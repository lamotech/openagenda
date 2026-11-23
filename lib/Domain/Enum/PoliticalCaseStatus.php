<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain\Enum;

enum PoliticalCaseStatus: string {
	case Open = 'open';
	case Decided = 'decided';

	public function label(): string {
		return match ($this) {
			self::Open => 'Åben',
			self::Decided => 'Afgjort',
		};
	}
}
