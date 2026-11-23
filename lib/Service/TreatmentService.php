<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service;

use OCA\OpenAgenda\Db\AgendaItem;
use OCA\OpenAgenda\Db\AgendaItemMapper;
use OCA\OpenAgenda\Db\CommitteeMapper;
use OCA\OpenAgenda\Db\Decision;
use OCA\OpenAgenda\Db\DecisionMapper;
use OCA\OpenAgenda\Db\MeetingMapper;
use OCA\OpenAgenda\Db\PoliticalCaseMapper;
use OCA\OpenAgenda\Db\ProtocolMapper;
use OCA\OpenAgenda\Db\Treatment;
use OCA\OpenAgenda\Db\TreatmentMapper;
use OCA\OpenAgenda\Domain\Actor;
use OCA\OpenAgenda\Domain\Enum\AgendaItemStatus;
use OCA\OpenAgenda\Domain\Enum\CommitteeType;
use OCA\OpenAgenda\Domain\Enum\DecisionType;
use OCA\OpenAgenda\Domain\Enum\MeetingStatus;
use OCA\OpenAgenda\Domain\Enum\PoliticalCaseStatus;
use OCA\OpenAgenda\Domain\Enum\TreatmentStatus;
use OCA\OpenAgenda\Domain\Exception\ConflictException;
use OCA\OpenAgenda\Domain\Exception\InvalidTransitionException;
use OCA\OpenAgenda\Domain\Exception\NotFoundException;
use OCA\OpenAgenda\Domain\Exception\ValidationException;
use OCA\OpenAgenda\Domain\ForwardingRules;
use OCA\OpenAgenda\Domain\Uuid;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Political treatment: registering decisions and forwarding cases between committees.
 */
class TreatmentService {
	use TTransactional;

	public function __construct(
		private TreatmentMapper $treatments,
		private DecisionMapper $decisions,
		private AgendaItemMapper $items,
		private MeetingMapper $meetings,
		private CommitteeMapper $committees,
		private PoliticalCaseMapper $politicalCases,
		private ProtocolMapper $protocols,
		private CaseHistoryService $history,
		private AgendaItemService $itemService,
		private ForwardingRules $forwardingRules,
		private AuthorizationService $auth,
		private AuditService $audit,
		private ITimeFactory $timeFactory,
		private IDBConnection $db,
	) {
	}

	/**
	 * Register (or correct) the decision for an item during the meeting.
	 */
	public function registerDecision(Actor $actor, string $itemUuid, array $data): Decision {
		$this->auth->require($this->auth->policy()->canRegisterDecision($actor), 'Kun sekretariatet kan registrere beslutninger.');
		$userId = $actor->requireUserId();
		$item = $this->itemService->get($actor, $itemUuid);
		$meeting = $this->meetings->findById($item->getMeetingId());

		if (AgendaItemStatus::from($item->getStatus()) !== AgendaItemStatus::Published) {
			throw new InvalidTransitionException('Der kan kun registreres beslutning på punkter fra en udsendt dagsorden.');
		}
		if (MeetingStatus::from($meeting->getStatus()) !== MeetingStatus::InProgress) {
			throw new InvalidTransitionException('Mødet skal være startet ("Møde i gang"), før beslutninger kan registreres.');
		}
		if ($this->protocols->findLatestApproved($meeting->getId()) !== null) {
			throw new ConflictException('Protokollen er godkendt; beslutninger kan ikke længere ændres.');
		}
		$treatment = $this->treatments->findByItem($item->getId());
		if ($treatment === null) {
			throw new NotFoundException('Punktet har ingen behandling. Udsend dagsordenen igen.');
		}
		if ($treatment->getStatus() === TreatmentStatus::Forwarded->value) {
			throw new ConflictException('Punktet er videreført, og beslutningen kan ikke ændres.');
		}

		$in = new Input($data);
		/** @var DecisionType|null $type */
		$type = $in->enum('decisionType', DecisionType::class, 'Beslutningstype');
		$text = $in->optionalString('decisionText', 'Beslutningstekst', 20000);
		if ($type !== null && $type->requiresText() && $text === null) {
			$in->addError('decisionText', 'Beslutningstypen "' . $type->label() . '" kræver en beslutningstekst.');
		}
		$in->validate();
		/** @var DecisionType $type */

		return $this->atomic(function () use ($actor, $userId, $item, $meeting, $treatment, $type, $text): Decision {
			$now = $this->timeFactory->getTime();
			$decision = $this->decisions->findByTreatment($treatment->getId());
			$isNew = $decision === null;
			$previousType = $decision?->getDecisionType();
			if ($isNew) {
				$decision = new Decision();
				$decision->setUuid(Uuid::v4());
				$decision->setTreatmentId($treatment->getId());
				$decision->setCreatedAt($now);
			}
			$decision->setDecisionType($type->value);
			$decision->setDecisionText($text);
			$decision->setDecidedBy($userId);
			$decision->setDecidedAt($now);
			$decision->setUpdatedAt($now);
			$decision = $isNew ? $this->decisions->insert($decision) : $this->decisions->update($decision);

			$treatment->setStatus(TreatmentStatus::Decided->value);
			$treatment->setTreatedAt($now);
			$treatment->setRecommendation($item->getRecommendation());
			$treatment->setUpdatedAt($now);
			$this->treatments->update($treatment);

			$this->updatePoliticalCaseStatus($item, $meeting->getCommitteeId(), $type);

			$this->audit->record($actor, $isNew ? 'decision.registered' : 'decision.changed', 'agenda_item', $item->getUuid(), $meeting->getUuid(), [
				'decisionType' => $type->value,
				'previousDecisionType' => $previousType,
				'treatmentUuid' => $treatment->getUuid(),
			]);
			return $decision;
		}, $this->db);
	}

	/**
	 * Forward a treated item to a meeting of another committee ("Viderefør til andet udvalg").
	 * The OpenCase case reference and earlier treatments are kept; a new agenda item
	 * and a new treatment are created on the target meeting.
	 */
	public function forward(Actor $actor, string $itemUuid, string $targetMeetingUuid): AgendaItem {
		$this->auth->require($this->auth->policy()->canForward($actor), 'Kun sekretariatet kan videreføre punkter.');
		$userId = $actor->requireUserId();
		$source = $this->itemService->get($actor, $itemUuid);
		$sourceMeeting = $this->meetings->findById($source->getMeetingId());
		$treatment = $this->treatments->findByItem($source->getId());
		if ($treatment === null) {
			throw new InvalidTransitionException('Punktet skal være behandlet, før det kan videreføres.');
		}
		$decision = $this->decisions->findByTreatment($treatment->getId());
		try {
			$targetMeeting = $this->meetings->findByUuid($targetMeetingUuid);
		} catch (NotFoundException) {
			throw new ValidationException(['meetingId' => 'Det valgte møde findes ikke.']);
		}
		$targetCommittee = $this->committees->findById($targetMeeting->getCommitteeId());

		$this->forwardingRules->assertCanForward(
			TreatmentStatus::from($treatment->getStatus()),
			$decision !== null ? DecisionType::from($decision->getDecisionType()) : null,
			$sourceMeeting->getCommitteeId(),
			$sourceMeeting->getStartsAt(),
			$targetCommittee->getId(),
			$targetCommittee->getActive(),
			MeetingStatus::from($targetMeeting->getStatus()),
			$targetMeeting->getStartsAt(),
			$source->getPoliticalCaseId() !== null,
		);

		return $this->atomic(function () use ($actor, $userId, $source, $sourceMeeting, $treatment, $targetMeeting, $targetCommittee): AgendaItem {
			$now = $this->timeFactory->getTime();
			$item = new AgendaItem();
			$item->setUuid(Uuid::v4());
			$item->setMeetingId($targetMeeting->getId());
			$item->setPoliticalCaseId($source->getPoliticalCaseId());
			$item->setTitle($source->getTitle());
			$item->setSummary($source->getSummary());
			$item->setPresentation($source->getPresentation());
			$item->setRecommendation($source->getRecommendation());
			$item->setLegalBasis($source->getLegalBasis());
			$item->setFinancialImpact($source->getFinancialImpact());
			$item->setAttachments($source->getAttachments());
			$item->setIsPublic($source->getIsPublic());
			// Forwarded by the secretariat: placed directly as approved on the target agenda.
			$item->setStatus(AgendaItemStatus::Approved->value);
			$item->setSortOrder($this->items->maxSortOrder($targetMeeting->getId()) + 1);
			$item->setForwardedFromItemId($source->getId());
			$item->setCreatedBy($userId);
			$item->setUpdatedBy($userId);
			$item->setCreatedAt($now);
			$item->setUpdatedAt($now);
			$item = $this->items->insert($item);

			$newTreatment = new Treatment();
			$newTreatment->setUuid(Uuid::v4());
			$newTreatment->setPoliticalCaseId($source->getPoliticalCaseId());
			$newTreatment->setMeetingId($targetMeeting->getId());
			$newTreatment->setAgendaItemId($item->getId());
			$newTreatment->setRecommendation($item->getRecommendation());
			$newTreatment->setStatus(TreatmentStatus::Pending->value);
			$newTreatment->setPreviousTreatmentId($treatment->getId());
			$newTreatment->setCreatedAt($now);
			$newTreatment->setUpdatedAt($now);
			$this->treatments->insert($newTreatment);

			$treatment->setStatus(TreatmentStatus::Forwarded->value);
			$treatment->setForwardedToItemId($item->getId());
			$treatment->setUpdatedAt($now);
			$this->treatments->update($treatment);

			$this->audit->record($actor, 'agenda_item.forwarded', 'agenda_item', $source->getUuid(), $sourceMeeting->getUuid(), [
				'targetMeetingUuid' => $targetMeeting->getUuid(),
				'targetCommittee' => $targetCommittee->getName(),
				'newItemUuid' => $item->getUuid(),
			]);
			$this->audit->record($actor, 'agenda_item.created', 'agenda_item', $item->getUuid(), $targetMeeting->getUuid(), [
				'forwardedFromItemUuid' => $source->getUuid(),
				'politicalCaseId' => $item->getPoliticalCaseId(),
			]);
			return $item;
		}, $this->db);
	}

	/**
	 * Treatment history for the political case of an item. Entries for items the
	 * actor may not read are reduced to committee and date.
	 */
	public function historyForItem(Actor $actor, string $itemUuid): array {
		$item = $this->itemService->get($actor, $itemUuid);
		if ($item->getPoliticalCaseId() === null) {
			return [];
		}
		$result = [];
		foreach ($this->history->entriesForCase($item->getPoliticalCaseId()) as $entry) {
			$readable = $this->itemService->canRead($actor, $entry['item'], MeetingStatus::from($entry['meeting']->getStatus()));
			$base = [
				'treatmentUuid' => $entry['treatment']->getUuid(),
				'isCurrent' => $entry['item']->getId() === $item->getId(),
				'committee' => ['uuid' => $entry['committee']->getUuid(), 'name' => $entry['committee']->getName()],
				'meeting' => ['uuid' => $entry['meeting']->getUuid(), 'title' => $entry['meeting']->getTitle(), 'startsAt' => $entry['meeting']->getStartsAt()],
				'status' => $entry['treatment']->getStatus(),
				'statusLabel' => TreatmentStatus::from($entry['treatment']->getStatus())->label(),
				'restricted' => !$readable,
			];
			if ($readable) {
				$decision = $entry['decision'];
				$base['itemUuid'] = $entry['item']->getUuid();
				$base['itemTitle'] = $entry['item']->getTitle();
				$base['recommendation'] = $entry['treatment']->getRecommendation() ?? $entry['item']->getRecommendation();
				$base['decision'] = $decision === null ? null : [
					'type' => $decision->getDecisionType(),
					'label' => DecisionType::from($decision->getDecisionType())->label(),
					'text' => $decision->getDecisionText(),
					'decidedAt' => $decision->getDecidedAt(),
				];
				$base['treatedAt'] = $entry['treatment']->getTreatedAt();
			}
			$result[] = $base;
		}
		return $result;
	}

	public function findTreatment(AgendaItem $item): ?Treatment {
		return $this->treatments->findByItem($item->getId());
	}

	private function updatePoliticalCaseStatus(AgendaItem $item, int $committeeId, DecisionType $type): void {
		if ($item->getPoliticalCaseId() === null) {
			return;
		}
		$committee = $this->committees->findById($committeeId);
		if ($committee->getType() !== CommitteeType::CityCouncil->value) {
			return;
		}
		$case = $this->politicalCases->findById($item->getPoliticalCaseId());
		$target = $type->isConclusive() ? PoliticalCaseStatus::Decided : PoliticalCaseStatus::Open;
		if ($case->getStatus() !== $target->value) {
			$case->setStatus($target->value);
			$case->setUpdatedAt($this->timeFactory->getTime());
			$this->politicalCases->update($case);
		}
	}
}
