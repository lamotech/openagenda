<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain\Enum;

/**
 * Meeting lifecycle. Transitions are validated server side.
 *
 * planned -> agenda_preparation -> agenda_published -> in_progress -> completed
 * agenda_published is only reachable through the publish-agenda use case,
 * which creates an immutable snapshot.
 */
enum MeetingStatus: string {
	case Planned = 'planned';
	case AgendaPreparation = 'agenda_preparation';
	case AgendaPublished = 'agenda_published';
	case InProgress = 'in_progress';
	case Completed = 'completed';

	public function label(): string {
		return match ($this) {
			self::Planned => 'Planlagt',
			self::AgendaPreparation => 'Dagsorden under forberedelse',
			self::AgendaPublished => 'Dagsorden udsendt',
			self::InProgress => 'Møde i gang',
			self::Completed => 'Afsluttet',
		};
	}

	/** @return list<self> */
	public function allowedTransitions(): array {
		return match ($this) {
			self::Planned => [self::AgendaPreparation, self::AgendaPublished],
			self::AgendaPreparation => [self::Planned, self::AgendaPublished],
			self::AgendaPublished => [self::InProgress],
			self::InProgress => [self::Completed],
			self::Completed => [],
		};
	}

	public function canTransitionTo(self $target): bool {
		return in_array($target, $this->allowedTransitions(), true);
	}

	/**
	 * Transitions that may be requested directly through the generic status endpoint.
	 * Publishing must go through the publish use case (snapshot creation).
	 */
	public function canTransitionManuallyTo(self $target): bool {
		return $target !== self::AgendaPublished && $this->canTransitionTo($target);
	}

	/** Agenda items can still be added by caseworkers. */
	public function isOpenForSubmissions(): bool {
		return $this === self::Planned || $this === self::AgendaPreparation;
	}

	/** The agenda has been sent out (snapshot exists). */
	public function isAgendaPublished(): bool {
		return in_array($this, [self::AgendaPublished, self::InProgress, self::Completed], true);
	}

	/** Meeting metadata (time, place) may be edited. */
	public function isEditable(): bool {
		return $this !== self::Completed;
	}
}
