<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service;

use OCA\OpenAgenda\Db\AgendaItemMapper;
use OCA\OpenAgenda\Db\CommitteeMapper;
use OCA\OpenAgenda\Db\DecisionMapper;
use OCA\OpenAgenda\Db\Meeting;
use OCA\OpenAgenda\Db\MeetingMapper;
use OCA\OpenAgenda\Db\PoliticalCaseMapper;
use OCA\OpenAgenda\Db\Protocol;
use OCA\OpenAgenda\Db\ProtocolMapper;
use OCA\OpenAgenda\Db\TreatmentMapper;
use OCA\OpenAgenda\Domain\Actor;
use OCA\OpenAgenda\Domain\ContentHasher;
use OCA\OpenAgenda\Domain\Enum\AgendaItemStatus;
use OCA\OpenAgenda\Domain\Enum\DecisionType;
use OCA\OpenAgenda\Domain\Enum\MeetingStatus;
use OCA\OpenAgenda\Domain\Enum\ProtocolStatus;
use OCA\OpenAgenda\Domain\Exception\ConflictException;
use OCA\OpenAgenda\Domain\Exception\InvalidTransitionException;
use OCA\OpenAgenda\Domain\Uuid;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Decision protocol ("beslutningsprotokol"): generation, approval and versioning.
 */
class ProtocolService {
	use TTransactional;

	public const SCHEMA = 1;

	public function __construct(
		private ProtocolMapper $protocols,
		private MeetingMapper $meetings,
		private CommitteeMapper $committees,
		private AgendaItemMapper $items,
		private TreatmentMapper $treatments,
		private DecisionMapper $decisions,
		private PoliticalCaseMapper $politicalCases,
		private CaseHistoryService $history,
		private ProtocolRenderer $renderer,
		private SettingsService $settings,
		private AuthorizationService $auth,
		private AuditService $audit,
		private ITimeFactory $timeFactory,
		private IDBConnection $db,
	) {
	}

	/**
	 * Generate (or regenerate) the draft protocol. If the latest version is approved,
	 * a new version is created; an approved version is never touched.
	 */
	public function generate(Actor $actor, string $meetingUuid): Protocol {
		$this->auth->require($this->auth->policy()->canManageProtocol($actor), 'Kun sekretariatet kan generere protokoller.');
		$userId = $actor->requireUserId();
		$meeting = $this->meetings->findByUuid($meetingUuid);
		$status = MeetingStatus::from($meeting->getStatus());
		if ($status !== MeetingStatus::InProgress && $status !== MeetingStatus::Completed) {
			throw new InvalidTransitionException('Protokollen kan først genereres, når mødet er startet.');
		}

		return $this->atomic(function () use ($actor, $userId, $meeting): Protocol {
			$latest = $this->protocols->findLatest($meeting->getId());
			$now = $this->timeFactory->getTime();
			if ($latest !== null && $latest->getStatus() === ProtocolStatus::Draft->value) {
				$protocol = $latest;
			} else {
				$protocol = new Protocol();
				$protocol->setUuid(Uuid::v4());
				$protocol->setMeetingId($meeting->getId());
				$protocol->setVersion($latest === null ? 1 : $latest->getVersion() + 1);
				$protocol->setStatus(ProtocolStatus::Draft->value);
			}
			$content = $this->buildContent($meeting, $protocol->getVersion(), ProtocolStatus::Draft);
			$protocol->setContent(ContentHasher::canonicalJson($content));
			$protocol->setHtml($this->renderer->render($content));
			$protocol->setContentHash(ContentHasher::hash($content));
			$protocol->setGeneratedBy($userId);
			$protocol->setGeneratedAt($now);
			$protocol = $protocol->getId() === null ? $this->protocols->insert($protocol) : $this->protocols->update($protocol);
			$this->audit->record($actor, 'protocol.generated', 'protocol', $protocol->getUuid(), $meeting->getUuid(), ['version' => $protocol->getVersion()]);
			return $protocol;
		}, $this->db);
	}

	/**
	 * Approve a draft protocol. The stored content must match the current decisions;
	 * otherwise the draft is stale and must be regenerated first.
	 */
	public function approve(Actor $actor, string $protocolUuid): Protocol {
		$this->auth->require($this->auth->policy()->canManageProtocol($actor), 'Kun sekretariatet kan godkende protokoller.');
		$userId = $actor->requireUserId();
		$protocol = $this->protocols->findByUuid($protocolUuid);
		if ($protocol->getStatus() !== ProtocolStatus::Draft->value) {
			throw new ConflictException('Protokollen er allerede godkendt.');
		}
		$meeting = $this->meetings->findById($protocol->getMeetingId());
		if (MeetingStatus::from($meeting->getStatus()) !== MeetingStatus::Completed) {
			throw new InvalidTransitionException('Mødet skal være afsluttet, før protokollen kan godkendes.');
		}
		$current = $this->buildContent($meeting, $protocol->getVersion(), ProtocolStatus::Draft);
		if (!ContentHasher::verify($current, $protocol->getContentHash())) {
			throw new ConflictException('Protokoludkastet er forældet. Generér protokollen igen, før den godkendes.');
		}

		return $this->atomic(function () use ($actor, $userId, $protocol, $meeting): Protocol {
			$now = $this->timeFactory->getTime();
			$content = $this->buildContent($meeting, $protocol->getVersion(), ProtocolStatus::Approved);
			$content['approvedAt'] = $now;
			$content['approvedBy'] = $userId;
			$protocol->setContent(ContentHasher::canonicalJson($content));
			$protocol->setHtml($this->renderer->render($content));
			$protocol->setContentHash(ContentHasher::hash($content));
			$protocol->setStatus(ProtocolStatus::Approved->value);
			$protocol->setApprovedBy($userId);
			$protocol->setApprovedAt($now);
			$protocol = $this->protocols->update($protocol);
			$this->audit->record($actor, 'protocol.approved', 'protocol', $protocol->getUuid(), $meeting->getUuid(), [
				'version' => $protocol->getVersion(),
				'contentHash' => $protocol->getContentHash(),
			]);
			return $protocol;
		}, $this->db);
	}

	/** @return list<Protocol> */
	public function listForMeeting(Actor $actor, Meeting $meeting): array {
		$this->auth->require($this->auth->policy()->canReadProtocol($actor), 'Du har ikke adgang til protokoller.');
		return $this->protocols->findByMeeting($meeting->getId());
	}

	public function get(Actor $actor, string $uuid): Protocol {
		$this->auth->require($this->auth->policy()->canReadProtocol($actor), 'Du har ikke adgang til protokoller.');
		return $this->protocols->findByUuid($uuid);
	}

	public function verify(Protocol $protocol): bool {
		return ContentHasher::verify($protocol->getContentArray(), $protocol->getContentHash());
	}

	private function buildContent(Meeting $meeting, int $version, ProtocolStatus $status): array {
		$committee = $this->committees->findById($meeting->getCommitteeId());
		$items = array_values(array_filter(
			$this->items->findByMeeting($meeting->getId()),
			static fn ($i) => $i->getStatus() === AgendaItemStatus::Published->value,
		));
		$treatments = $this->treatments->findByItems(array_map(static fn ($i) => $i->getId(), $items));
		$decisions = $this->decisions->findByTreatments(array_map(static fn ($t) => $t->getId(), array_values($treatments)));

		$entries = [];
		foreach ($items as $index => $item) {
			$treatment = $treatments[$item->getId()] ?? null;
			$decision = $treatment !== null ? ($decisions[$treatment->getId()] ?? null) : null;
			$case = $item->getPoliticalCaseId() !== null ? $this->politicalCases->findById($item->getPoliticalCaseId()) : null;
			$entries[] = [
				'uuid' => $item->getUuid(),
				'number' => $index + 1,
				'title' => $item->getTitle(),
				'isPublic' => $item->getIsPublic(),
				'caseNumber' => $case?->getCaseNumber(),
				'opencaseCaseId' => $case?->getOpencaseCaseId(),
				'recommendation' => $treatment?->getRecommendation() ?? $item->getRecommendation(),
				'decision' => $decision === null ? null : [
					'type' => $decision->getDecisionType(),
					'label' => DecisionType::from($decision->getDecisionType())->label(),
					'text' => $decision->getDecisionText(),
					'decidedAt' => $decision->getDecidedAt(),
				],
				'previousTreatments' => $this->history->previousTreatmentsSummary($item),
			];
		}
		return [
			'schema' => self::SCHEMA,
			'version' => $version,
			'status' => $status->value,
			'municipality' => $this->settings->getMunicipalityName(),
			'committee' => ['uuid' => $committee->getUuid(), 'name' => $committee->getName(), 'type' => $committee->getType()],
			'meeting' => [
				'uuid' => $meeting->getUuid(),
				'title' => $meeting->getTitle(),
				'startsAt' => $meeting->getStartsAt(),
				'location' => $meeting->getLocation(),
			],
			'items' => $entries,
		];
	}
}
