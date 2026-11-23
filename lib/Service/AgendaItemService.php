<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service;

use OCA\OpenAgenda\Db\AgendaItem;
use OCA\OpenAgenda\Db\AgendaItemMapper;
use OCA\OpenAgenda\Db\Meeting;
use OCA\OpenAgenda\Db\MeetingMapper;
use OCA\OpenAgenda\Db\PoliticalCase;
use OCA\OpenAgenda\Db\PoliticalCaseMapper;
use OCA\OpenAgenda\Db\TreatmentMapper;
use OCA\OpenAgenda\Domain\Actor;
use OCA\OpenAgenda\Domain\Enum\AgendaItemStatus;
use OCA\OpenAgenda\Domain\Enum\MeetingStatus;
use OCA\OpenAgenda\Domain\Enum\PoliticalCaseStatus;
use OCA\OpenAgenda\Domain\Exception\ConflictException;
use OCA\OpenAgenda\Domain\Exception\InvalidTransitionException;
use OCA\OpenAgenda\Domain\Exception\NotFoundException;
use OCA\OpenAgenda\Domain\Exception\ValidationException;
use OCA\OpenAgenda\Domain\Uuid;
use OCA\OpenAgenda\OpenCase\OpenCaseAdapterInterface;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Use cases for agenda items: create/edit/delete, submit/approve/reject, reorder.
 */
class AgendaItemService {
	use TTransactional;

	private const TEXT_MAX = 100000;

	public function __construct(
		private AgendaItemMapper $items,
		private MeetingMapper $meetings,
		private PoliticalCaseMapper $politicalCases,
		private TreatmentMapper $treatments,
		private OpenCaseAdapterInterface $openCase,
		private AuthorizationService $auth,
		private AuditService $audit,
		private ITimeFactory $timeFactory,
		private IDBConnection $db,
	) {
	}

	/**
	 * Items of a meeting the actor may read, in agenda order.
	 *
	 * @return list<AgendaItem>
	 */
	public function listForMeeting(Actor $actor, Meeting $meeting): array {
		$this->auth->requireAppAccess($actor);
		$status = MeetingStatus::from($meeting->getStatus());
		return array_values(array_filter(
			$this->items->findByMeeting($meeting->getId()),
			fn (AgendaItem $item) => $this->canRead($actor, $item, $status),
		));
	}

	/** @throws NotFoundException when the item does not exist or is not readable (no existence leak). */
	public function get(Actor $actor, string $uuid): AgendaItem {
		$this->auth->requireAppAccess($actor);
		$item = $this->items->findByUuid($uuid);
		$meeting = $this->meetings->findById($item->getMeetingId());
		if (!$this->canRead($actor, $item, MeetingStatus::from($meeting->getStatus()))) {
			throw new NotFoundException('Dagsordenspunktet blev ikke fundet.');
		}
		return $item;
	}

	public function canRead(Actor $actor, AgendaItem $item, MeetingStatus $meetingStatus): bool {
		return $this->auth->policy()->canReadItem(
			$actor,
			$item->getCreatedBy(),
			$item->getIsPublic(),
			AgendaItemStatus::from($item->getStatus()),
			$meetingStatus,
		);
	}

	public function create(Actor $actor, array $data): AgendaItem {
		$this->auth->requireAppAccess($actor);
		$in = new Input($data);
		$meetingUuid = $in->requiredString('meetingId', 'Møde', 36);
		$in->validate();
		try {
			$meeting = $this->meetings->findByUuid($meetingUuid);
		} catch (NotFoundException) {
			throw new ValidationException(['meetingId' => 'Mødet findes ikke.']);
		}
		$this->auth->require(
			$this->auth->policy()->canCreateItem($actor, MeetingStatus::from($meeting->getStatus())),
			'Du kan ikke oprette punkter på dette møde (mødets dagsorden er låst, eller du mangler rettighed).',
		);
		$userId = $actor->requireUserId();

		return $this->atomic(function () use ($actor, $data, $meeting, $userId): AgendaItem {
			$item = new AgendaItem();
			$item->setUuid(Uuid::v4());
			$item->setMeetingId($meeting->getId());
			$item->setStatus(AgendaItemStatus::Draft->value);
			$item->setCreatedBy($userId);
			$item->setSortOrder($this->items->maxSortOrder($meeting->getId()) + 1);
			$this->applyContent($actor, $item, $data, true);
			$now = $this->timeFactory->getTime();
			$item->setCreatedAt($now);
			$item->setUpdatedAt($now);
			$item = $this->items->insert($item);
			$this->audit->record($actor, 'agenda_item.created', 'agenda_item', $item->getUuid(), $meeting->getUuid(), [
				'politicalCaseId' => $item->getPoliticalCaseId(),
				'isPublic' => $item->getIsPublic(),
			]);
			return $item;
		}, $this->db);
	}

	public function update(Actor $actor, string $uuid, array $data): AgendaItem {
		$item = $this->get($actor, $uuid);
		$meeting = $this->meetings->findById($item->getMeetingId());
		$status = AgendaItemStatus::from($item->getStatus());
		$this->auth->require(
			$this->auth->policy()->canEditItem($actor, $item->getCreatedBy(), $status, MeetingStatus::from($meeting->getStatus())),
			'Du kan ikke redigere dette punkt i dets nuværende status.',
		);

		return $this->atomic(function () use ($actor, $item, $data, $meeting, $status): AgendaItem {
			if (array_key_exists('meetingId', $data) && $data['meetingId'] !== $meeting->getUuid()) {
				$this->moveToMeeting($actor, $item, (string)$data['meetingId'], $status);
			}
			$this->applyContent($actor, $item, $data, false);
			$changed = array_keys($item->getUpdatedFields());
			$item->setUpdatedBy($actor->userId);
			$item->setUpdatedAt($this->timeFactory->getTime());
			$item = $this->items->update($item);
			$this->audit->record($actor, 'agenda_item.updated', 'agenda_item', $item->getUuid(), $meeting->getUuid(), [
				'fields' => array_values(array_diff($changed, ['updatedBy', 'updatedAt'])),
				'correctionAfterPublish' => $status === AgendaItemStatus::Published,
			]);
			return $item;
		}, $this->db);
	}

	public function delete(Actor $actor, string $uuid): void {
		$item = $this->get($actor, $uuid);
		$meeting = $this->meetings->findById($item->getMeetingId());
		$this->auth->require($this->auth->policy()->canDeleteItem(
			$actor,
			$item->getCreatedBy(),
			AgendaItemStatus::from($item->getStatus()),
			MeetingStatus::from($meeting->getStatus()),
		), 'Du kan ikke slette dette punkt.');
		if ($this->treatments->findByItem($item->getId()) !== null) {
			throw new ConflictException('Punktet har en politisk behandling og kan ikke slettes.');
		}
		$this->items->delete($item);
		$this->audit->record($actor, 'agenda_item.deleted', 'agenda_item', $uuid, $meeting->getUuid(), ['title' => $item->getTitle()]);
	}

	public function submit(Actor $actor, string $uuid): AgendaItem {
		$item = $this->get($actor, $uuid);
		$status = AgendaItemStatus::from($item->getStatus());
		$this->auth->require($this->auth->policy()->canSubmitItem($actor, $item->getCreatedBy(), $status), 'Du kan ikke indsende dette punkt.');
		$this->assertCompleteForSubmission($item);
		$item->setRejectionReason(null);
		return $this->transition($actor, $item, AgendaItemStatus::Submitted, 'agenda_item.submitted');
	}

	public function approve(Actor $actor, string $uuid): AgendaItem {
		$item = $this->get($actor, $uuid);
		$this->auth->require($this->auth->policy()->canReviewItem($actor), 'Kun sekretariatet kan godkende punkter.');
		if (AgendaItemStatus::from($item->getStatus()) !== AgendaItemStatus::Submitted) {
			throw new InvalidTransitionException('Kun indsendte punkter kan godkendes.');
		}
		return $this->transition($actor, $item, AgendaItemStatus::Approved, 'agenda_item.approved');
	}

	public function reject(Actor $actor, string $uuid, ?string $reason): AgendaItem {
		$item = $this->get($actor, $uuid);
		$this->auth->require($this->auth->policy()->canReviewItem($actor), 'Kun sekretariatet kan afvise punkter.');
		$reason = $reason !== null ? trim($reason) : '';
		if ($reason === '') {
			throw new ValidationException(['reason' => 'Angiv en begrundelse for afvisningen.']);
		}
		if (mb_strlen($reason) > 4000) {
			throw new ValidationException(['reason' => 'Begrundelsen må højst være 4000 tegn.']);
		}
		$item->setRejectionReason($reason);
		return $this->transition($actor, $item, AgendaItemStatus::Draft, 'agenda_item.rejected');
	}

	/**
	 * Move an item one position up or down within its meeting.
	 *
	 * @return list<AgendaItem> the meeting's items in new order
	 */
	public function move(Actor $actor, string $uuid, string $direction): array {
		$item = $this->get($actor, $uuid);
		$this->auth->require($this->auth->policy()->canReorderItems($actor), 'Kun sekretariatet kan ændre rækkefølgen.');
		if (!in_array($direction, ['up', 'down'], true)) {
			throw new ValidationException(['direction' => 'Retning skal være "up" eller "down".']);
		}
		$meeting = $this->meetings->findById($item->getMeetingId());
		$meetingStatus = MeetingStatus::from($meeting->getStatus());
		if ($meetingStatus === MeetingStatus::InProgress || $meetingStatus === MeetingStatus::Completed) {
			throw new ConflictException('Rækkefølgen kan ikke ændres, når mødet er startet.');
		}

		return $this->atomic(function () use ($actor, $item, $direction, $meeting): array {
			$all = $this->items->findByMeeting($meeting->getId());
			// Normalise positions first so swapping is always well defined.
			foreach ($all as $position => $entry) {
				if ($entry->getSortOrder() !== $position + 1) {
					$entry->setSortOrder($position + 1);
					$this->items->update($entry);
				}
			}
			$index = null;
			foreach ($all as $position => $entry) {
				if ($entry->getId() === $item->getId()) {
					$index = $position;
				}
			}
			$swapWith = $direction === 'up' ? $index - 1 : $index + 1;
			if ($index === null || $swapWith < 0 || $swapWith >= count($all)) {
				return $all;
			}
			$a = $all[$index];
			$b = $all[$swapWith];
			$orderA = $a->getSortOrder();
			$a->setSortOrder($b->getSortOrder());
			$b->setSortOrder($orderA);
			$this->items->update($a);
			$this->items->update($b);
			$this->audit->record($actor, 'agenda_item.reordered', 'agenda_item', $a->getUuid(), $meeting->getUuid(), ['direction' => $direction, 'position' => $a->getSortOrder()]);
			return $this->items->findByMeeting($meeting->getId());
		}, $this->db);
	}

	/**
	 * Find the open political case for an OpenCase case or create one.
	 * OpenAgenda never copies the case itself – only a reference plus a cached display title.
	 */
	public function findOrCreatePoliticalCase(Actor $actor, string $opencaseCaseId): PoliticalCase {
		$case = $this->openCase->getCase($opencaseCaseId, $actor->userId);
		if ($case === null) {
			throw new ValidationException(['opencaseCaseId' => 'Sagen findes ikke i OpenCase, eller du har ikke adgang til den.']);
		}
		$existing = $this->politicalCases->findOpenByOpenCaseId($case->id);
		if ($existing !== null) {
			return $existing;
		}
		$pc = new PoliticalCase();
		$pc->setUuid(Uuid::v4());
		$pc->setOpencaseCaseId($case->id);
		$pc->setCaseNumber($case->caseNumber);
		$pc->setTitle(mb_substr($case->title, 0, 255));
		$pc->setStatus(PoliticalCaseStatus::Open->value);
		$pc->setCreatedBy($actor->requireUserId());
		$now = $this->timeFactory->getTime();
		$pc->setCreatedAt($now);
		$pc->setUpdatedAt($now);
		$pc = $this->politicalCases->insert($pc);
		$this->audit->record($actor, 'political_case.created', 'political_case', $pc->getUuid(), null, ['caseNumber' => $pc->getCaseNumber()]);
		return $pc;
	}

	private function transition(Actor $actor, AgendaItem $item, AgendaItemStatus $target, string $action): AgendaItem {
		$current = AgendaItemStatus::from($item->getStatus());
		if (!$current->canTransitionTo($target)) {
			throw new InvalidTransitionException(sprintf('Punktet kan ikke skifte fra "%s" til "%s".', $current->label(), $target->label()));
		}
		$meeting = $this->meetings->findById($item->getMeetingId());
		$item->setStatus($target->value);
		$item->setUpdatedBy($actor->userId);
		$item->setUpdatedAt($this->timeFactory->getTime());
		$item = $this->items->update($item);
		$metadata = ['from' => $current->value, 'to' => $target->value];
		if ($action === 'agenda_item.rejected') {
			$metadata['hasReason'] = true;
		}
		$this->audit->record($actor, $action, 'agenda_item', $item->getUuid(), $meeting->getUuid(), $metadata);
		return $item;
	}

	private function assertCompleteForSubmission(AgendaItem $item): void {
		$errors = [];
		if ($item->getPresentation() === null) {
			$errors['presentation'] = 'Sagsfremstilling skal udfyldes, før punktet indsendes.';
		}
		if ($item->getRecommendation() === null) {
			$errors['recommendation'] = 'Indstilling skal udfyldes, før punktet indsendes.';
		}
		if ($errors !== []) {
			throw new ValidationException($errors, 'Punktet er ikke klar til indsendelse.');
		}
	}

	private function moveToMeeting(Actor $actor, AgendaItem $item, string $meetingUuid, AgendaItemStatus $status): void {
		if ($status === AgendaItemStatus::Published) {
			throw new ConflictException('Et udsendt punkt kan ikke flyttes til et andet møde.');
		}
		try {
			$target = $this->meetings->findByUuid($meetingUuid);
		} catch (NotFoundException) {
			throw new ValidationException(['meetingId' => 'Mødet findes ikke.']);
		}
		if ($this->treatments->findByItem($item->getId()) !== null) {
			throw new ConflictException('Punktet har en behandling og kan ikke flyttes.');
		}
		$this->auth->require(
			$this->auth->policy()->canCreateItem($actor, MeetingStatus::from($target->getStatus())),
			'Punktet kan ikke flyttes til det valgte møde.',
		);
		$item->setMeetingId($target->getId());
		$item->setSortOrder($this->items->maxSortOrder($target->getId()) + 1);
	}

	private function applyContent(Actor $actor, AgendaItem $item, array $data, bool $isNew): void {
		$in = new Input($data);
		if ($isNew || $in->has('title')) {
			$item->setTitle($in->requiredString('title', 'Titel'));
		}
		$texts = [
			'summary' => ['Resumé', 'setSummary'],
			'presentation' => ['Sagsfremstilling', 'setPresentation'],
			'recommendation' => ['Indstilling', 'setRecommendation'],
			'legalBasis' => ['Lovgrundlag', 'setLegalBasis'],
			'financialImpact' => ['Økonomiske konsekvenser', 'setFinancialImpact'],
		];
		foreach ($texts as $key => [$label, $setter]) {
			if ($isNew || $in->has($key)) {
				$item->$setter($in->optionalString($key, $label, self::TEXT_MAX));
			}
		}
		if ($isNew || $in->has('isPublic')) {
			$item->setIsPublic($in->bool('isPublic', true));
		}

		$caseChanged = false;
		if ($in->has('opencaseCaseId')) {
			$caseId = $in->optionalString('opencaseCaseId', 'OpenCase-sag', 128);
			$currentCase = $item->getPoliticalCaseId() !== null ? $this->politicalCases->findById($item->getPoliticalCaseId()) : null;
			if ($caseId !== ($currentCase?->getOpencaseCaseId())) {
				if (!$isNew && AgendaItemStatus::from($item->getStatus()) !== AgendaItemStatus::Draft) {
					$in->addError('opencaseCaseId', 'Sagsreferencen kan kun ændres, mens punktet er en kladde.');
				} elseif (!$isNew && $item->getForwardedFromItemId() !== null) {
					$in->addError('opencaseCaseId', 'Sagsreferencen på et videreført punkt kan ikke ændres.');
				} elseif ($caseId === null) {
					$item->setPoliticalCaseId(null);
					$caseChanged = true;
				} else {
					try {
						$item->setPoliticalCaseId($this->findOrCreatePoliticalCase($actor, $caseId)->getId());
						$caseChanged = true;
					} catch (ValidationException $e) {
						foreach ($e->getErrors() as $field => $message) {
							$in->addError($field, $message);
						}
					}
				}
			}
		}

		if ($in->has('attachments') || ($caseChanged && !$isNew)) {
			$raw = $in->has('attachments') ? $in->raw('attachments') : [];
			$attachments = $this->validateAttachments($actor, $item, is_array($raw) ? $raw : null, $in);
			if ($attachments !== null) {
				$item->setAttachmentList($attachments);
			}
		} elseif ($isNew) {
			$item->setAttachmentList([]);
		}
		$in->validate();
	}

	/**
	 * Attachments are references to OpenCase documents on the item's case. The
	 * adapter decides whether the user may access each document.
	 *
	 * @return list<array{documentId: string, title: string}>|null
	 */
	private function validateAttachments(Actor $actor, AgendaItem $item, ?array $raw, Input $in): ?array {
		if ($raw === null) {
			$in->addError('attachments', 'Bilag skal angives som en liste.');
			return null;
		}
		if ($raw === []) {
			return [];
		}
		if ($item->getPoliticalCaseId() === null) {
			$in->addError('attachments', 'Bilag kræver en tilknyttet OpenCase-sag.');
			return null;
		}
		$caseId = $this->politicalCases->findById($item->getPoliticalCaseId())->getOpencaseCaseId();
		$result = [];
		$seen = [];
		foreach ($raw as $entry) {
			$documentId = is_array($entry) ? ($entry['documentId'] ?? null) : $entry;
			if (!is_string($documentId) || $documentId === '' || isset($seen[$documentId])) {
				continue;
			}
			$seen[$documentId] = true;
			if (!$this->openCase->canAccessDocument($actor->userId, $documentId)) {
				$in->addError('attachments', 'Du har ikke adgang til et eller flere af de valgte dokumenter i OpenCase.');
				return null;
			}
			$document = $this->openCase->getDocument($documentId, $actor->userId);
			if ($document === null || $document->caseId !== $caseId) {
				$in->addError('attachments', 'Et eller flere bilag hører ikke til den valgte sag.');
				return null;
			}
			$result[] = ['documentId' => $document->id, 'title' => $document->title];
		}
		return $result;
	}
}
