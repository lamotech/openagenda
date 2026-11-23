<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service;

use OCA\OpenAgenda\Db\AgendaItem;
use OCA\OpenAgenda\Db\AgendaItemMapper;
use OCA\OpenAgenda\Db\AgendaSnapshot;
use OCA\OpenAgenda\Db\AgendaSnapshotMapper;
use OCA\OpenAgenda\Db\CommitteeMapper;
use OCA\OpenAgenda\Db\Meeting;
use OCA\OpenAgenda\Db\MeetingMapper;
use OCA\OpenAgenda\Db\PoliticalCaseMapper;
use OCA\OpenAgenda\Db\Treatment;
use OCA\OpenAgenda\Db\TreatmentMapper;
use OCA\OpenAgenda\Domain\Actor;
use OCA\OpenAgenda\Domain\ContentHasher;
use OCA\OpenAgenda\Domain\Enum\AgendaItemStatus;
use OCA\OpenAgenda\Domain\Enum\MeetingStatus;
use OCA\OpenAgenda\Domain\Enum\TreatmentStatus;
use OCA\OpenAgenda\Domain\Exception\ConflictException;
use OCA\OpenAgenda\Domain\Exception\NotFoundException;
use OCA\OpenAgenda\Domain\Exception\ValidationException;
use OCA\OpenAgenda\Domain\Uuid;
use OCA\OpenAgenda\OpenCase\Dto\OpenCaseDocument;
use OCA\OpenAgenda\OpenCase\OpenCaseAdapterInterface;
use OCA\OpenAgenda\OpenCase\OpenCaseException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Approval and sending out ("udsendelse") of agendas with immutable, versioned snapshots.
 */
class AgendaService {
	use TTransactional;

	public const SNAPSHOT_SCHEMA = 1;

	public function __construct(
		private MeetingMapper $meetings,
		private CommitteeMapper $committees,
		private AgendaItemMapper $items,
		private PoliticalCaseMapper $politicalCases,
		private TreatmentMapper $treatments,
		private AgendaSnapshotMapper $snapshots,
		private CaseHistoryService $history,
		private OpenCaseAdapterInterface $openCase,
		private SettingsService $settings,
		private AuthorizationService $auth,
		private AuditService $audit,
		private ITimeFactory $timeFactory,
		private IDBConnection $db,
	) {
	}

	/**
	 * Approve (optionally) and send out the agenda: publishes all approved items,
	 * creates treatments and stores a new immutable snapshot version.
	 * Calling it again while the agenda is published creates a corrected version.
	 */
	public function publish(Actor $actor, string $meetingUuid, bool $approveSubmitted): AgendaSnapshot {
		$this->auth->require($this->auth->policy()->canPublishAgenda($actor), 'Kun sekretariatet kan udsende dagsordener.');
		$userId = $actor->requireUserId();
		$meeting = $this->meetings->findByUuid($meetingUuid);
		$status = MeetingStatus::from($meeting->getStatus());
		if ($status !== MeetingStatus::AgendaPublished && !$status->canTransitionTo(MeetingStatus::AgendaPublished)) {
			throw new ConflictException('Dagsordenen kan ikke udsendes, når mødet er "' . $status->label() . '".');
		}

		try {
			return $this->atomic(function () use ($actor, $userId, $meeting, $status, $approveSubmitted): AgendaSnapshot {
				$items = $this->items->findByMeeting($meeting->getId());
				$included = [];
				foreach ($items as $item) {
					$itemStatus = AgendaItemStatus::from($item->getStatus());
					if ($approveSubmitted && $itemStatus === AgendaItemStatus::Submitted) {
						$this->setItemStatus($actor, $item, $meeting, AgendaItemStatus::Approved, 'agenda_item.approved');
						$itemStatus = AgendaItemStatus::Approved;
					}
					if ($itemStatus->isReadyForAgenda()) {
						$included[] = $item;
					}
				}
				if ($included === []) {
					throw new ValidationException(['items' => 'Der er ingen godkendte punkter på dagsordenen.'], 'Dagsordenen kan ikke udsendes.');
				}

				foreach ($included as $item) {
					if (AgendaItemStatus::from($item->getStatus()) === AgendaItemStatus::Approved) {
						$this->setItemStatus($actor, $item, $meeting, AgendaItemStatus::Published, 'agenda_item.published');
					}
					$this->ensureTreatment($item);
				}

				$latest = $this->snapshots->findLatest($meeting->getId());
				$version = $latest === null ? 1 : $latest->getVersion() + 1;
				$now = $this->timeFactory->getTime();
				$content = $this->buildContent($meeting, $included, $version, $now, $userId);

				$snapshot = new AgendaSnapshot();
				$snapshot->setUuid(Uuid::v4());
				$snapshot->setMeetingId($meeting->getId());
				$snapshot->setVersion($version);
				$snapshot->setContent(ContentHasher::canonicalJson($content));
				$snapshot->setContentHash(ContentHasher::hash($content));
				$snapshot->setCreatedBy($userId);
				$snapshot->setCreatedAt($now);
				$snapshot = $this->snapshots->insert($snapshot);

				if ($status !== MeetingStatus::AgendaPublished) {
					$meeting->setStatus(MeetingStatus::AgendaPublished->value);
					$meeting->setUpdatedAt($now);
					$this->meetings->update($meeting);
				}
				$this->audit->record($actor, 'agenda.published', 'meeting', $meeting->getUuid(), $meeting->getUuid(), [
					'snapshotVersion' => $version,
					'itemCount' => count($included),
					'contentHash' => $snapshot->getContentHash(),
				]);
				return $snapshot;
			}, $this->db);
		} catch (OpenCaseException $e) {
			throw new ConflictException('Dokumentversioner kunne ikke hentes fra OpenCase: ' . $e->getMessage());
		}
	}

	/** @return list<AgendaSnapshot> newest first */
	public function listSnapshots(Actor $actor, Meeting $meeting): array {
		$this->auth->require($this->auth->policy()->canReadSnapshots($actor));
		return $this->snapshots->findByMeeting($meeting->getId());
	}

	/**
	 * Snapshot content as visible to the actor. Closed items the actor may not read
	 * are replaced by a placeholder.
	 *
	 * @return array{snapshot: AgendaSnapshot, content: array, verified: bool}
	 */
	public function getSnapshot(Actor $actor, string $uuid): array {
		$this->auth->require($this->auth->policy()->canReadSnapshots($actor));
		$snapshot = $this->snapshots->findByUuid($uuid);
		$content = $snapshot->getContentArray();
		$verified = ContentHasher::verify($content, $snapshot->getContentHash());

		if (!$actor->isSecretariat()) {
			$ownItemUuids = [];
			$sourceItems = $this->items->findByMeeting($snapshot->getMeetingId());
			foreach ($sourceItems as $item) {
				if ($item->getCreatedBy() === $actor->userId) {
					$ownItemUuids[$item->getUuid()] = true;
				}
			}
			$meeting = $this->meetings->findById($snapshot->getMeetingId());
			foreach ($content['items'] as $i => $entry) {
				$readable = $this->auth->policy()->canReadItem(
					$actor,
					isset($ownItemUuids[$entry['uuid']]) ? $actor->userId : null,
					(bool)$entry['isPublic'],
					AgendaItemStatus::Published,
					MeetingStatus::from($meeting->getStatus()),
				);
				if (!$readable) {
					$content['items'][$i] = ['uuid' => $entry['uuid'], 'number' => $entry['number'], 'isPublic' => false, 'restricted' => true, 'title' => 'Lukket punkt'];
				}
			}
		}
		return ['snapshot' => $snapshot, 'content' => $content, 'verified' => $verified];
	}

	private function setItemStatus(Actor $actor, AgendaItem $item, Meeting $meeting, AgendaItemStatus $target, string $action): void {
		$from = $item->getStatus();
		$item->setStatus($target->value);
		$item->setUpdatedBy($actor->userId);
		$item->setUpdatedAt($this->timeFactory->getTime());
		$this->items->update($item);
		$this->audit->record($actor, $action, 'agenda_item', $item->getUuid(), $meeting->getUuid(), ['from' => $from, 'to' => $target->value]);
	}

	private function ensureTreatment(AgendaItem $item): Treatment {
		$treatment = $this->treatments->findByItem($item->getId());
		$now = $this->timeFactory->getTime();
		if ($treatment === null) {
			$treatment = new Treatment();
			$treatment->setUuid(Uuid::v4());
			$treatment->setPoliticalCaseId($item->getPoliticalCaseId());
			$treatment->setMeetingId($item->getMeetingId());
			$treatment->setAgendaItemId($item->getId());
			$treatment->setRecommendation($item->getRecommendation());
			$treatment->setStatus(TreatmentStatus::Pending->value);
			$treatment->setCreatedAt($now);
			$treatment->setUpdatedAt($now);
			return $this->treatments->insert($treatment);
		}
		if ($treatment->getStatus() === TreatmentStatus::Pending->value && $treatment->getRecommendation() !== $item->getRecommendation()) {
			$treatment->setRecommendation($item->getRecommendation());
			$treatment->setUpdatedAt($now);
			$treatment = $this->treatments->update($treatment);
		}
		return $treatment;
	}

	/**
	 * @param list<AgendaItem> $items
	 */
	private function buildContent(Meeting $meeting, array $items, int $version, int $now, string $userId): array {
		$committee = $this->committees->findById($meeting->getCommitteeId());
		$entries = [];
		foreach (array_values($items) as $index => $item) {
			$case = $item->getPoliticalCaseId() !== null ? $this->politicalCases->findById($item->getPoliticalCaseId()) : null;
			$entries[] = [
				'uuid' => $item->getUuid(),
				'number' => $index + 1,
				'title' => $item->getTitle(),
				'isPublic' => $item->getIsPublic(),
				'caseReference' => $case === null ? null : [
					'politicalCaseUuid' => $case->getUuid(),
					'opencaseCaseId' => $case->getOpencaseCaseId(),
					'caseNumber' => $case->getCaseNumber(),
				],
				'summary' => $item->getSummary(),
				'presentation' => $item->getPresentation(),
				'recommendation' => $item->getRecommendation(),
				'legalBasis' => $item->getLegalBasis(),
				'financialImpact' => $item->getFinancialImpact(),
				'attachments' => $this->resolveAttachmentVersions($item, $userId),
				'previousTreatments' => $this->history->previousTreatmentsSummary($item),
			];
		}
		return [
			'schema' => self::SNAPSHOT_SCHEMA,
			'version' => $version,
			'createdAt' => $now,
			'municipality' => $this->settings->getMunicipalityName(),
			'committee' => [
				'uuid' => $committee->getUuid(),
				'name' => $committee->getName(),
				'type' => $committee->getType(),
			],
			'meeting' => [
				'uuid' => $meeting->getUuid(),
				'title' => $meeting->getTitle(),
				'startsAt' => $meeting->getStartsAt(),
				'endsAt' => $meeting->getEndsAt(),
				'location' => $meeting->getLocation(),
			],
			'items' => $entries,
		];
	}

	/**
	 * Pin the current OpenCase document version for each attachment, as seen by the
	 * publishing user. Documents the publisher cannot read are kept with an unknown
	 * version and treated as confidential.
	 *
	 * @return list<array{documentId: string, title: string, versionId: ?string, versionNumber: ?int, accessLevel: string}>
	 */
	private function resolveAttachmentVersions(AgendaItem $item, string $userId): array {
		$result = [];
		foreach ($item->getAttachmentList() as $attachment) {
			$document = $this->openCase->getDocument((string)$attachment['documentId'], $userId);
			$result[] = [
				'documentId' => (string)$attachment['documentId'],
				'title' => $document?->title ?? (string)($attachment['title'] ?? ''),
				'versionId' => $document?->latestVersion?->versionId,
				'versionNumber' => $document?->latestVersion?->versionNumber,
				// Unknown documents are never treated as public.
				'accessLevel' => $document?->accessLevel ?? OpenCaseDocument::ACCESS_CONFIDENTIAL,
			];
		}
		return $result;
	}

	public function findLatestSnapshot(Meeting $meeting): ?AgendaSnapshot {
		return $this->snapshots->findLatest($meeting->getId());
	}

	/** @throws NotFoundException */
	public function requireLatestSnapshot(Meeting $meeting): AgendaSnapshot {
		$snapshot = $this->snapshots->findLatest($meeting->getId());
		if ($snapshot === null) {
			throw new NotFoundException('Dagsordenen er ikke udsendt.');
		}
		return $snapshot;
	}
}
