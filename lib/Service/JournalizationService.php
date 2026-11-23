<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service;

use OCA\OpenAgenda\Db\CommitteeMapper;
use OCA\OpenAgenda\Db\Journalization;
use OCA\OpenAgenda\Db\JournalizationMapper;
use OCA\OpenAgenda\Db\MeetingMapper;
use OCA\OpenAgenda\Db\Protocol;
use OCA\OpenAgenda\Db\ProtocolMapper;
use OCA\OpenAgenda\Domain\Actor;
use OCA\OpenAgenda\Domain\Enum\JournalizationStatus;
use OCA\OpenAgenda\Domain\Enum\ProtocolStatus;
use OCA\OpenAgenda\Domain\Exception\ConflictException;
use OCA\OpenAgenda\Domain\Exception\InvalidTransitionException;
use OCA\OpenAgenda\Domain\Uuid;
use OCA\OpenAgenda\OpenCase\Dto\JournalizationRequest;
use OCA\OpenAgenda\OpenCase\OpenCaseAdapterInterface;
use OCA\OpenAgenda\OpenCase\OpenCaseException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use Psr\Log\LoggerInterface;

/**
 * Journalizes an approved protocol on every OpenCase case referenced by its items.
 *
 * Idempotency is guaranteed twice:
 *  1. one oa_journalizations row per (protocol, case) – unique DB constraint; a
 *     succeeded row is never sent again;
 *  2. a deterministic idempotency key is passed to the adapter, so a retry after
 *     an unknown outcome (timeout) cannot create a second journal entry.
 */
class JournalizationService {
	public const DOCUMENT_TYPE = 'beslutningsprotokol';

	public function __construct(
		private JournalizationMapper $journalizations,
		private ProtocolMapper $protocols,
		private MeetingMapper $meetings,
		private CommitteeMapper $committees,
		private OpenCaseAdapterInterface $openCase,
		private AuthorizationService $auth,
		private AuditService $audit,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{journalizations: list<Journalization>, created: int, skipped: int, failed: int}
	 */
	public function journalize(Actor $actor, string $protocolUuid): array {
		$this->auth->require($this->auth->policy()->canJournalize($actor), 'Kun sekretariatet kan journalisere.');
		$userId = $actor->requireUserId();
		$protocol = $this->protocols->findByUuid($protocolUuid);
		if ($protocol->getStatus() !== ProtocolStatus::Approved->value) {
			throw new InvalidTransitionException('Kun en godkendt protokol kan journaliseres.');
		}
		$meeting = $this->meetings->findById($protocol->getMeetingId());
		$committee = $this->committees->findById($meeting->getCommitteeId());

		$caseIds = $this->caseIdsOf($protocol);
		if ($caseIds === []) {
			throw new ConflictException('Protokollen indeholder ingen punkter med OpenCase-sagsreference.');
		}

		$created = 0;
		$skipped = 0;
		$failed = 0;
		foreach ($caseIds as $caseId) {
			$key = sprintf('openagenda:protocol:%s:case:%s', $protocol->getUuid(), $caseId);
			$row = $this->findOrCreateRow($protocol, $caseId, $key, $userId);
			if ($row->getStatus() === JournalizationStatus::Succeeded->value) {
				$skipped++;
				continue;
			}

			$request = new JournalizationRequest(
				$caseId,
				$key,
				sprintf('Beslutningsprotokol – %s – %s (version %d)', $committee->getName(), ProtocolRenderer::formatDate($meeting->getStartsAt()), $protocol->getVersion()),
				self::DOCUMENT_TYPE,
				$protocol->getHtml(),
				$userId,
				[
					'protocolUuid' => $protocol->getUuid(),
					'protocolVersion' => $protocol->getVersion(),
					'meetingUuid' => $meeting->getUuid(),
					'contentHash' => $protocol->getContentHash(),
				],
			);
			$row->setAttempts($row->getAttempts() + 1);
			$row->setPayloadHash($request->payloadHash());
			try {
				$result = $this->openCase->journalize($request);
				$row->setStatus(JournalizationStatus::Succeeded->value);
				$row->setExternalRef($result->journalReference);
				$row->setErrorMessage(null);
				$created++;
				$action = 'protocol.journalized';
				$metadata = ['caseId' => $caseId, 'journalRef' => $result->journalReference, 'adapterReportedExisting' => $result->alreadyExisted];
			} catch (OpenCaseException $e) {
				$this->logger->warning('OpenAgenda journalization failed', ['exception' => $e, 'protocol' => $protocol->getUuid(), 'case' => $caseId]);
				$row->setStatus(JournalizationStatus::Failed->value);
				$row->setErrorMessage(mb_substr($e->getMessage(), 0, 2000));
				$failed++;
				$action = 'protocol.journalization_failed';
				$metadata = ['caseId' => $caseId, 'error' => mb_substr($e->getMessage(), 0, 500)];
			}
			$row->setUpdatedAt($this->timeFactory->getTime());
			$this->journalizations->update($row);
			$this->audit->record($actor, $action, 'protocol', $protocol->getUuid(), $meeting->getUuid(), $metadata);
		}

		return [
			'journalizations' => $this->journalizations->findByProtocol($protocol->getId()),
			'created' => $created,
			'skipped' => $skipped,
			'failed' => $failed,
		];
	}

	/** @return list<Journalization> */
	public function listForProtocol(Actor $actor, string $protocolUuid): array {
		$this->auth->require($this->auth->policy()->canReadProtocol($actor));
		$protocol = $this->protocols->findByUuid($protocolUuid);
		return $this->journalizations->findByProtocol($protocol->getId());
	}

	/** @return list<string> */
	private function caseIdsOf(Protocol $protocol): array {
		$ids = [];
		foreach ($protocol->getContentArray()['items'] ?? [] as $item) {
			if (!empty($item['opencaseCaseId'])) {
				$ids[(string)$item['opencaseCaseId']] = true;
			}
		}
		return array_keys($ids);
	}

	private function findOrCreateRow(Protocol $protocol, string $caseId, string $key, string $userId): Journalization {
		$existing = $this->journalizations->findByIdempotencyKey($key);
		if ($existing !== null) {
			return $existing;
		}
		$now = $this->timeFactory->getTime();
		$row = new Journalization();
		$row->setUuid(Uuid::v4());
		$row->setProtocolId($protocol->getId());
		$row->setOpencaseCaseId($caseId);
		$row->setIdempotencyKey($key);
		$row->setStatus(JournalizationStatus::Pending->value);
		$row->setAttempts(0);
		$row->setCreatedBy($userId);
		$row->setCreatedAt($now);
		$row->setUpdatedAt($now);
		try {
			return $this->journalizations->insert($row);
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				$existing = $this->journalizations->findByIdempotencyKey($key);
				if ($existing !== null) {
					return $existing;
				}
			}
			throw $e;
		}
	}
}
