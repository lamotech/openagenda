<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain;

use OCA\OpenAgenda\Domain\Enum\AgendaItemStatus;
use OCA\OpenAgenda\Domain\Enum\MeetingStatus;

/**
 * Central, framework independent access rules.
 *
 * All methods are pure functions of the actor's server-resolved roles and the
 * state of the objects involved, which makes them unit testable. The
 * AuthorizationService resolves roles and turns denials into exceptions.
 */
final class AccessPolicy {
	public function canUseApp(Actor $actor): bool {
		return !$actor->isAnonymous() && $actor->hasAnyRole();
	}

	public function canConfigure(Actor $actor): bool {
		return $actor->has(Role::Admin);
	}

	public function canManageCommittees(Actor $actor): bool {
		return $actor->isSecretariat();
	}

	public function canManageMeetings(Actor $actor): bool {
		return $actor->isSecretariat();
	}

	public function canSearchOpenCase(Actor $actor): bool {
		return $actor->has(Role::Admin, Role::Secretary, Role::Caseworker);
	}

	public function canCreateItem(Actor $actor, MeetingStatus $meetingStatus): bool {
		if ($actor->isSecretariat()) {
			// Secretariat may add supplementary items until the meeting starts.
			return $meetingStatus->isOpenForSubmissions() || $meetingStatus === MeetingStatus::AgendaPublished;
		}
		return $actor->has(Role::Caseworker) && $meetingStatus->isOpenForSubmissions();
	}

	public function canReadItem(Actor $actor, ?string $ownerId, bool $isPublic, AgendaItemStatus $status, MeetingStatus $meetingStatus): bool {
		if ($actor->isSecretariat()) {
			return true;
		}
		if ($actor->has(Role::Caseworker)) {
			if ($ownerId !== null && $ownerId === $actor->userId) {
				return true;
			}
			// Caseworkers see other caseworkers' public items, never closed ones.
			if ($isPublic) {
				return true;
			}
		}
		if ($actor->has(Role::Politician)) {
			// Politicians read authorised meeting material: items on a sent-out agenda.
			return $status === AgendaItemStatus::Published && $meetingStatus->isAgendaPublished();
		}
		return false;
	}

	public function canEditItem(Actor $actor, ?string $ownerId, AgendaItemStatus $status, MeetingStatus $meetingStatus): bool {
		if ($meetingStatus === MeetingStatus::InProgress || $meetingStatus === MeetingStatus::Completed) {
			return false;
		}
		if ($actor->isSecretariat()) {
			// Published items may be corrected while the agenda is published;
			// the correction only becomes visible through a new snapshot version.
			return true;
		}
		return $actor->has(Role::Caseworker)
			&& $ownerId !== null
			&& $ownerId === $actor->userId
			&& $status === AgendaItemStatus::Draft;
	}

	public function canDeleteItem(Actor $actor, ?string $ownerId, AgendaItemStatus $status, MeetingStatus $meetingStatus): bool {
		if ($status === AgendaItemStatus::Published) {
			return false;
		}
		return $this->canEditItem($actor, $ownerId, $status, $meetingStatus);
	}

	public function canSubmitItem(Actor $actor, ?string $ownerId, AgendaItemStatus $status): bool {
		if ($status !== AgendaItemStatus::Draft) {
			return false;
		}
		if ($actor->isSecretariat()) {
			return true;
		}
		return $actor->has(Role::Caseworker) && $ownerId !== null && $ownerId === $actor->userId;
	}

	public function canReviewItem(Actor $actor): bool {
		return $actor->isSecretariat();
	}

	public function canReorderItems(Actor $actor): bool {
		return $actor->isSecretariat();
	}

	public function canPublishAgenda(Actor $actor): bool {
		return $actor->isSecretariat();
	}

	public function canRegisterDecision(Actor $actor): bool {
		return $actor->isSecretariat();
	}

	public function canForward(Actor $actor): bool {
		return $actor->isSecretariat();
	}

	public function canReadProtocol(Actor $actor): bool {
		return $actor->has(Role::Admin, Role::Secretary, Role::Politician);
	}

	public function canManageProtocol(Actor $actor): bool {
		return $actor->isSecretariat();
	}

	public function canJournalize(Actor $actor): bool {
		return $actor->isSecretariat();
	}

	public function canPublishPublicly(Actor $actor): bool {
		return $actor->isSecretariat();
	}

	public function canReadAudit(Actor $actor): bool {
		return $actor->isSecretariat();
	}

	public function canReadSnapshots(Actor $actor): bool {
		return $this->canUseApp($actor);
	}

	/**
	 * Permission flags exposed to the UI so it can hide actions. Informational only:
	 * every operation is checked again on the server.
	 *
	 * @return array<string, bool>
	 */
	public function globalCapabilities(Actor $actor): array {
		return [
			'useApp' => $this->canUseApp($actor),
			'configure' => $this->canConfigure($actor),
			'manageCommittees' => $this->canManageCommittees($actor),
			'manageMeetings' => $this->canManageMeetings($actor),
			'searchOpenCase' => $this->canSearchOpenCase($actor),
			'reviewItems' => $this->canReviewItem($actor),
			'publishAgenda' => $this->canPublishAgenda($actor),
			'registerDecisions' => $this->canRegisterDecision($actor),
			'forward' => $this->canForward($actor),
			'readProtocol' => $this->canReadProtocol($actor),
			'manageProtocol' => $this->canManageProtocol($actor),
			'journalize' => $this->canJournalize($actor),
			'publishPublicly' => $this->canPublishPublicly($actor),
			'readAudit' => $this->canReadAudit($actor),
		];
	}
}
