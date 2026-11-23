<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service;

use OCA\OpenAgenda\Db\AgendaItemMapper;
use OCA\OpenAgenda\Db\DecisionMapper;
use OCA\OpenAgenda\Db\MeetingMapper;
use OCA\OpenAgenda\Db\TreatmentMapper;
use OCA\OpenAgenda\Domain\Actor;
use OCA\OpenAgenda\Domain\Enum\AgendaItemStatus;
use OCA\OpenAgenda\Domain\Enum\DecisionType;
use OCA\OpenAgenda\Domain\Enum\MeetingStatus;
use OCP\AppFramework\Utility\ITimeFactory;

class DashboardService {
	public function __construct(
		private MeetingMapper $meetings,
		private AgendaItemMapper $items,
		private TreatmentMapper $treatments,
		private DecisionMapper $decisions,
		private AgendaItemService $itemService,
		private AuthorizationService $auth,
		private ViewBuilder $view,
		private ITimeFactory $timeFactory,
	) {
	}

	public function build(Actor $actor): array {
		$this->auth->requireAppAccess($actor);
		$now = $this->timeFactory->getTime();

		$upcoming = $this->meetings->findFiltered(null, [
			MeetingStatus::Planned->value,
			MeetingStatus::AgendaPreparation->value,
			MeetingStatus::AgendaPublished->value,
			MeetingStatus::InProgress->value,
		], $now - 86400, null, 'ASC', 8);
		$preparation = $this->meetings->findFiltered(null, [MeetingStatus::Planned->value, MeetingStatus::AgendaPreparation->value], null, null, 'ASC', 8);

		$recentDecisions = [];
		foreach ($this->decisions->findRecent(25) as $decision) {
			if (count($recentDecisions) >= 8) {
				break;
			}
			$treatment = $this->treatments->findById($decision->getTreatmentId());
			$item = $this->items->findById($treatment->getAgendaItemId());
			$meeting = $this->meetings->findById($item->getMeetingId());
			if (!$this->itemService->canRead($actor, $item, MeetingStatus::from($meeting->getStatus()))) {
				continue;
			}
			$type = DecisionType::from($decision->getDecisionType());
			$recentDecisions[] = [
				'itemId' => $item->getUuid(),
				'itemTitle' => $item->getTitle(),
				'meeting' => $this->view->meeting($meeting),
				'decision' => ['type' => $type->value, 'label' => $type->label(), 'text' => $decision->getDecisionText(), 'decidedAt' => $decision->getDecidedAt()],
			];
		}

		$awaitingApproval = [];
		if ($this->auth->policy()->canReviewItem($actor)) {
			foreach ($this->items->findByStatus(AgendaItemStatus::Submitted->value, 20) as $item) {
				$awaitingApproval[] = $this->summaryOf($item);
			}
		}
		$myItems = [];
		if ($actor->userId !== null) {
			foreach ($this->items->findByCreator($actor->userId, 10) as $item) {
				$myItems[] = $this->summaryOf($item);
			}
		}

		return [
			'upcomingMeetings' => $this->view->meetings($upcoming),
			'meetingsInPreparation' => $this->view->meetings($preparation),
			'recentDecisions' => $recentDecisions,
			'awaitingApproval' => $awaitingApproval,
			'myItems' => $myItems,
		];
	}

	private function summaryOf(\OCA\OpenAgenda\Db\AgendaItem $item): array {
		$meeting = $this->meetings->findById($item->getMeetingId());
		$status = AgendaItemStatus::from($item->getStatus());
		return [
			'id' => $item->getUuid(),
			'title' => $item->getTitle(),
			'status' => $status->value,
			'statusLabel' => $status->label(),
			'meeting' => ['id' => $meeting->getUuid(), 'title' => $meeting->getTitle(), 'startsAt' => $meeting->getStartsAt()],
			'updatedAt' => $item->getUpdatedAt(),
		];
	}
}
