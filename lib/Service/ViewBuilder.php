<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service;

use OCA\OpenAgenda\Db\AgendaItem;
use OCA\OpenAgenda\Db\AgendaItemMapper;
use OCA\OpenAgenda\Db\AgendaSnapshot;
use OCA\OpenAgenda\Db\AuditEvent;
use OCA\OpenAgenda\Db\Committee;
use OCA\OpenAgenda\Db\CommitteeMapper;
use OCA\OpenAgenda\Db\DecisionMapper;
use OCA\OpenAgenda\Db\Journalization;
use OCA\OpenAgenda\Db\Meeting;
use OCA\OpenAgenda\Db\MeetingMapper;
use OCA\OpenAgenda\Db\PoliticalCaseMapper;
use OCA\OpenAgenda\Db\Protocol;
use OCA\OpenAgenda\Db\Publication;
use OCA\OpenAgenda\Db\TreatmentMapper;
use OCA\OpenAgenda\Domain\Actor;
use OCA\OpenAgenda\Domain\Enum\AgendaItemStatus;
use OCA\OpenAgenda\Domain\Enum\CommitteeType;
use OCA\OpenAgenda\Domain\Enum\DecisionType;
use OCA\OpenAgenda\Domain\Enum\JournalizationStatus;
use OCA\OpenAgenda\Domain\Enum\MeetingStatus;
use OCA\OpenAgenda\Domain\Enum\ProtocolStatus;
use OCA\OpenAgenda\Domain\Enum\PublicationStatus;
use OCA\OpenAgenda\Domain\Enum\TreatmentStatus;
use OCP\IUserManager;

/**
 * Presentation helper: turns entities into API response arrays (UUIDs as ids,
 * Danish labels, per-object permission flags for the UI).
 */
class ViewBuilder {
	/** @var array<string, string> */
	private array $displayNames = [];

	public function __construct(
		private CommitteeMapper $committees,
		private MeetingMapper $meetings,
		private AgendaItemMapper $items,
		private TreatmentMapper $treatments,
		private DecisionMapper $decisions,
		private PoliticalCaseMapper $politicalCases,
		private AuthorizationService $auth,
		private IUserManager $userManager,
	) {
	}

	public function committee(Committee $c): array {
		return [
			'id' => $c->getUuid(),
			'name' => $c->getName(),
			'shortName' => $c->getShortName(),
			'description' => $c->getDescription(),
			'type' => $c->getType(),
			'typeLabel' => CommitteeType::tryFrom($c->getType())?->label() ?? $c->getType(),
			'active' => $c->getActive(),
			'createdAt' => $c->getCreatedAt(),
			'updatedAt' => $c->getUpdatedAt(),
		];
	}

	/**
	 * @param list<Meeting> $meetings
	 * @return list<array>
	 */
	public function meetings(array $meetings): array {
		$committees = $this->committees->findByIds(array_map(static fn ($m) => $m->getCommitteeId(), $meetings));
		return array_map(fn (Meeting $m) => $this->meeting($m, $committees[$m->getCommitteeId()] ?? null), $meetings);
	}

	public function meeting(Meeting $m, ?Committee $committee = null): array {
		$committee ??= $this->committees->findById($m->getCommitteeId());
		$status = MeetingStatus::from($m->getStatus());
		return [
			'id' => $m->getUuid(),
			'committee' => [
				'id' => $committee->getUuid(),
				'name' => $committee->getName(),
				'type' => $committee->getType(),
			],
			'title' => $m->getTitle(),
			'startsAt' => $m->getStartsAt(),
			'endsAt' => $m->getEndsAt(),
			'location' => $m->getLocation(),
			'status' => $status->value,
			'statusLabel' => $status->label(),
			'manualTransitions' => array_values(array_map(
				static fn (MeetingStatus $s) => ['status' => $s->value, 'label' => $s->label()],
				array_filter($status->allowedTransitions(), static fn ($s) => $status->canTransitionManuallyTo($s)),
			)),
			'createdAt' => $m->getCreatedAt(),
			'updatedAt' => $m->getUpdatedAt(),
		];
	}

	public function item(Actor $actor, AgendaItem $item): array {
		$meeting = $this->meetings->findById($item->getMeetingId());
		return $this->items($actor, $meeting, [$item])[0];
	}

	/**
	 * @param list<AgendaItem> $items items of one meeting, in agenda order
	 * @return list<array>
	 */
	public function items(Actor $actor, Meeting $meeting, array $items): array {
		$policy = $this->auth->policy();
		$meetingStatus = MeetingStatus::from($meeting->getStatus());
		$treatments = $this->treatments->findByItems(array_map(static fn ($i) => $i->getId(), $items));
		$decisions = $this->decisions->findByTreatments(array_map(static fn ($t) => $t->getId(), array_values($treatments)));
		$cases = $this->politicalCases->findByIds(array_map(static fn ($i) => $i->getPoliticalCaseId(), $items));
		$linkedItemIds = [];
		foreach ($items as $item) {
			if ($item->getForwardedFromItemId() !== null) {
				$linkedItemIds[] = $item->getForwardedFromItemId();
			}
		}
		foreach ($treatments as $t) {
			if ($t->getForwardedToItemId() !== null) {
				$linkedItemIds[] = $t->getForwardedToItemId();
			}
		}
		$linkedItems = $this->items->findByIds($linkedItemIds);
		$linkedMeetings = $this->meetings->findByIds(array_map(static fn ($i) => $i->getMeetingId(), array_values($linkedItems)));
		$linkedCommittees = $this->committees->findByIds(array_map(static fn ($m) => $m->getCommitteeId(), array_values($linkedMeetings)));

		$link = function (?int $itemId) use ($linkedItems, $linkedMeetings, $linkedCommittees): ?array {
			if ($itemId === null || !isset($linkedItems[$itemId])) {
				return null;
			}
			$linked = $linkedItems[$itemId];
			$m = $linkedMeetings[$linked->getMeetingId()];
			return [
				'itemId' => $linked->getUuid(),
				'meetingId' => $m->getUuid(),
				'meetingTitle' => $m->getTitle(),
				'meetingStartsAt' => $m->getStartsAt(),
				'committeeName' => $linkedCommittees[$m->getCommitteeId()]->getName(),
			];
		};

		$result = [];
		$position = 0;
		foreach ($items as $item) {
			$status = AgendaItemStatus::from($item->getStatus());
			$treatment = $treatments[$item->getId()] ?? null;
			$decision = $treatment !== null ? ($decisions[$treatment->getId()] ?? null) : null;
			$case = $item->getPoliticalCaseId() !== null ? ($cases[$item->getPoliticalCaseId()] ?? null) : null;
			$decisionType = $decision !== null ? DecisionType::from($decision->getDecisionType()) : null;
			$treatmentStatus = $treatment !== null ? TreatmentStatus::from($treatment->getStatus()) : null;
			$number = $status === AgendaItemStatus::Published ? ++$position : null;

			$canDecide = $policy->canRegisterDecision($actor)
				&& $status === AgendaItemStatus::Published
				&& $meetingStatus === MeetingStatus::InProgress
				&& $treatmentStatus !== TreatmentStatus::Forwarded;
			$canForward = $policy->canForward($actor)
				&& $treatmentStatus === TreatmentStatus::Decided
				&& $decisionType !== null && $decisionType->allowsForwarding()
				&& $case !== null;

			$result[] = [
				'id' => $item->getUuid(),
				'meetingId' => $meeting->getUuid(),
				'number' => $number,
				'title' => $item->getTitle(),
				'summary' => $item->getSummary(),
				'presentation' => $item->getPresentation(),
				'recommendation' => $item->getRecommendation(),
				'legalBasis' => $item->getLegalBasis(),
				'financialImpact' => $item->getFinancialImpact(),
				'attachments' => $item->getAttachmentList(),
				'isPublic' => $item->getIsPublic(),
				'sortOrder' => $item->getSortOrder(),
				'status' => $status->value,
				'statusLabel' => $status->label(),
				'rejectionReason' => $item->getRejectionReason(),
				'createdBy' => $item->getCreatedBy(),
				'createdByName' => $this->displayName($item->getCreatedBy()),
				'createdAt' => $item->getCreatedAt(),
				'updatedAt' => $item->getUpdatedAt(),
				'case' => $case === null ? null : [
					'politicalCaseId' => $case->getUuid(),
					'opencaseCaseId' => $case->getOpencaseCaseId(),
					'caseNumber' => $case->getCaseNumber(),
					'title' => $case->getTitle(),
					'status' => $case->getStatus(),
				],
				'treatment' => $treatment === null ? null : [
					'id' => $treatment->getUuid(),
					'status' => $treatmentStatus->value,
					'statusLabel' => $treatmentStatus->label(),
					'treatedAt' => $treatment->getTreatedAt(),
					'recommendation' => $treatment->getRecommendation(),
				],
				'decision' => $decision === null ? null : [
					'id' => $decision->getUuid(),
					'type' => $decisionType->value,
					'label' => $decisionType->label(),
					'text' => $decision->getDecisionText(),
					'decidedAt' => $decision->getDecidedAt(),
					'decidedBy' => $decision->getDecidedBy(),
				],
				'forwardedFrom' => $link($item->getForwardedFromItemId()),
				'forwardedTo' => $link($treatment?->getForwardedToItemId()),
				'permissions' => [
					'edit' => $policy->canEditItem($actor, $item->getCreatedBy(), $status, $meetingStatus),
					'delete' => $policy->canDeleteItem($actor, $item->getCreatedBy(), $status, $meetingStatus) && $treatment === null,
					'submit' => $policy->canSubmitItem($actor, $item->getCreatedBy(), $status),
					'approve' => $policy->canReviewItem($actor) && $status === AgendaItemStatus::Submitted,
					'reject' => $policy->canReviewItem($actor) && ($status === AgendaItemStatus::Submitted || $status === AgendaItemStatus::Approved),
					'reorder' => $policy->canReorderItems($actor) && $meetingStatus !== MeetingStatus::InProgress && $meetingStatus !== MeetingStatus::Completed,
					'decide' => $canDecide,
					'forward' => $canForward,
				],
			];
		}
		return $result;
	}

	public function snapshot(AgendaSnapshot $s, ?array $content = null, ?bool $verified = null): array {
		$data = [
			'id' => $s->getUuid(),
			'version' => $s->getVersion(),
			'contentHash' => $s->getContentHash(),
			'createdBy' => $s->getCreatedBy(),
			'createdByName' => $this->displayName($s->getCreatedBy()),
			'createdAt' => $s->getCreatedAt(),
		];
		if ($content !== null) {
			$data['content'] = $content;
			$data['verified'] = $verified;
		}
		return $data;
	}

	public function protocol(Protocol $p, bool $includeHtml = false, ?bool $verified = null): array {
		$status = ProtocolStatus::from($p->getStatus());
		$data = [
			'id' => $p->getUuid(),
			'version' => $p->getVersion(),
			'status' => $status->value,
			'statusLabel' => $status->label(),
			'contentHash' => $p->getContentHash(),
			'generatedBy' => $p->getGeneratedBy(),
			'generatedAt' => $p->getGeneratedAt(),
			'approvedBy' => $p->getApprovedBy(),
			'approvedByName' => $p->getApprovedBy() !== null ? $this->displayName($p->getApprovedBy()) : null,
			'approvedAt' => $p->getApprovedAt(),
		];
		if ($includeHtml) {
			$data['html'] = $p->getHtml();
			$data['verified'] = $verified;
		}
		return $data;
	}

	public function journalization(Journalization $j): array {
		$status = JournalizationStatus::from($j->getStatus());
		return [
			'id' => $j->getUuid(),
			'opencaseCaseId' => $j->getOpencaseCaseId(),
			'status' => $status->value,
			'statusLabel' => $status->label(),
			'externalRef' => $j->getExternalRef(),
			'errorMessage' => $j->getErrorMessage(),
			'attempts' => $j->getAttempts(),
			'createdAt' => $j->getCreatedAt(),
			'updatedAt' => $j->getUpdatedAt(),
		];
	}

	public function publication(Publication $p): array {
		$status = PublicationStatus::from($p->getStatus());
		$content = $p->getContentArray();
		return [
			'id' => $p->getUuid(),
			'status' => $status->value,
			'statusLabel' => $status->label(),
			'agendaVersion' => $content['agendaVersion'] ?? null,
			'protocolVersion' => $content['protocolVersion'] ?? null,
			'hasDecisions' => (bool)($content['hasDecisions'] ?? false),
			'publishedBy' => $p->getPublishedBy(),
			'publishedAt' => $p->getPublishedAt(),
			'withdrawnAt' => $p->getWithdrawnAt(),
		];
	}

	public function auditEvent(AuditEvent $e): array {
		return [
			'id' => $e->getUuid(),
			'occurredAt' => $e->getOccurredAt(),
			'userId' => $e->getUserId(),
			'userName' => $e->getUserId() !== null ? $this->displayName($e->getUserId()) : null,
			'action' => $e->getAction(),
			'objectType' => $e->getObjectType(),
			'objectId' => $e->getObjectId(),
			'meetingId' => $e->getMeetingUuid(),
			'metadata' => $e->getMetadataArray(),
		];
	}

	public function displayName(string $userId): string {
		if (!isset($this->displayNames[$userId])) {
			$this->displayNames[$userId] = $this->userManager->getDisplayName($userId) ?? $userId;
		}
		return $this->displayNames[$userId];
	}
}
