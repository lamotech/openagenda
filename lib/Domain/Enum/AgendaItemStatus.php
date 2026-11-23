<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain\Enum;

/**
 * Approval workflow for agenda items: draft -> submitted -> approved -> published.
 * A secretary may reject a submitted item (back to draft) or reopen an approved,
 * not yet published item (back to draft).
 */
enum AgendaItemStatus: string {
	case Draft = 'draft';
	case Submitted = 'submitted';
	case Approved = 'approved';
	case Published = 'published';

	public function label(): string {
		return match ($this) {
			self::Draft => 'Kladde',
			self::Submitted => 'Indsendt',
			self::Approved => 'Godkendt',
			self::Published => 'Udsendt',
		};
	}

	/** @return list<self> */
	public function allowedTransitions(): array {
		return match ($this) {
			self::Draft => [self::Submitted],
			self::Submitted => [self::Approved, self::Draft],
			self::Approved => [self::Published, self::Draft],
			self::Published => [],
		};
	}

	public function canTransitionTo(self $target): bool {
		return in_array($target, $this->allowedTransitions(), true);
	}

	/** Can be included on a published agenda. */
	public function isReadyForAgenda(): bool {
		return $this === self::Approved || $this === self::Published;
	}
}
