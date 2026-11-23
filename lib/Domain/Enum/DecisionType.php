<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain\Enum;

enum DecisionType: string {
	case Approved = 'approved';
	case Rejected = 'rejected';
	case ApprovedWithAmendments = 'approved_with_amendments';
	case Postponed = 'postponed';
	case Referred = 'referred';

	public function label(): string {
		return match ($this) {
			self::Approved => 'Godkendt',
			self::Rejected => 'Ikke godkendt',
			self::ApprovedWithAmendments => 'Godkendt med ændringer',
			self::Postponed => 'Udsat',
			self::Referred => 'Henvist',
		};
	}

	/** A decision text is mandatory because the type alone does not describe the outcome. */
	public function requiresText(): bool {
		return $this === self::ApprovedWithAmendments || $this === self::Referred;
	}

	/**
	 * Whether the case may be forwarded to another committee after this decision.
	 * A postponed item has not been treated and must be re-scheduled instead.
	 */
	public function allowsForwarding(): bool {
		return $this !== self::Postponed;
	}

	/** Concludes the political case when taken by the city council. */
	public function isConclusive(): bool {
		return in_array($this, [self::Approved, self::Rejected, self::ApprovedWithAmendments], true);
	}
}
