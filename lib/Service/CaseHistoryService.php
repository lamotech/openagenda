<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service;

use OCA\OpenAgenda\Db\AgendaItem;
use OCA\OpenAgenda\Db\AgendaItemMapper;
use OCA\OpenAgenda\Db\CommitteeMapper;
use OCA\OpenAgenda\Db\DecisionMapper;
use OCA\OpenAgenda\Db\MeetingMapper;
use OCA\OpenAgenda\Db\TreatmentMapper;
use OCA\OpenAgenda\Domain\Enum\DecisionType;

/**
 * Read model of the political treatment history of a case (across committees).
 */
class CaseHistoryService {
	public function __construct(
		private TreatmentMapper $treatments,
		private DecisionMapper $decisions,
		private AgendaItemMapper $items,
		private MeetingMapper $meetings,
		private CommitteeMapper $committees,
	) {
	}

	/**
	 * All treatments of a political case, oldest meeting first.
	 *
	 * @return list<array{treatment: \OCA\OpenAgenda\Db\Treatment, item: AgendaItem, meeting: \OCA\OpenAgenda\Db\Meeting, committee: \OCA\OpenAgenda\Db\Committee, decision: ?\OCA\OpenAgenda\Db\Decision}>
	 */
	public function entriesForCase(int $politicalCaseId): array {
		$treatments = $this->treatments->findByPoliticalCase($politicalCaseId);
		if ($treatments === []) {
			return [];
		}
		$items = $this->items->findByIds(array_map(static fn ($t) => $t->getAgendaItemId(), $treatments));
		$meetings = $this->meetings->findByIds(array_map(static fn ($t) => $t->getMeetingId(), $treatments));
		$committees = $this->committees->findByIds(array_map(static fn ($m) => $m->getCommitteeId(), array_values($meetings)));
		$decisions = $this->decisions->findByTreatments(array_map(static fn ($t) => $t->getId(), $treatments));

		$entries = [];
		foreach ($treatments as $treatment) {
			$meeting = $meetings[$treatment->getMeetingId()];
			$entries[] = [
				'treatment' => $treatment,
				'item' => $items[$treatment->getAgendaItemId()],
				'meeting' => $meeting,
				'committee' => $committees[$meeting->getCommitteeId()],
				'decision' => $decisions[$treatment->getId()] ?? null,
			];
		}
		usort($entries, static fn ($a, $b) => [$a['meeting']->getStartsAt(), $a['treatment']->getId()] <=> [$b['meeting']->getStartsAt(), $b['treatment']->getId()]);
		return $entries;
	}

	/**
	 * Decided treatments of the same political case at earlier meetings than the
	 * item's meeting. Used for snapshots and protocols ("tidligere behandlinger").
	 *
	 * @return list<array{committeeName: string, meetingStartsAt: int, recommendation: ?string, decisionType: string, decisionLabel: string, decisionText: ?string, isPublic: bool}>
	 */
	public function previousTreatmentsSummary(AgendaItem $item): array {
		if ($item->getPoliticalCaseId() === null) {
			return [];
		}
		$currentMeeting = $this->meetings->findById($item->getMeetingId());
		$result = [];
		foreach ($this->entriesForCase($item->getPoliticalCaseId()) as $entry) {
			if ($entry['item']->getId() === $item->getId() || $entry['decision'] === null) {
				continue;
			}
			if ($entry['meeting']->getStartsAt() >= $currentMeeting->getStartsAt()) {
				continue;
			}
			$type = DecisionType::from($entry['decision']->getDecisionType());
			$result[] = [
				'committeeName' => $entry['committee']->getName(),
				'meetingStartsAt' => $entry['meeting']->getStartsAt(),
				'recommendation' => $entry['treatment']->getRecommendation(),
				'decisionType' => $type->value,
				'decisionLabel' => $type->label(),
				'decisionText' => $entry['decision']->getDecisionText(),
				'isPublic' => $entry['item']->getIsPublic(),
			];
		}
		return $result;
	}
}
