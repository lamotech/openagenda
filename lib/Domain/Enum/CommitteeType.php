<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain\Enum;

enum CommitteeType: string {
	case StandingCommittee = 'standing_committee';
	case FinanceCommittee = 'finance_committee';
	case CityCouncil = 'city_council';
	case SpecialCommittee = 'special_committee';

	public function label(): string {
		return match ($this) {
			self::StandingCommittee => 'Stående udvalg',
			self::FinanceCommittee => 'Økonomiudvalg',
			self::CityCouncil => 'Byråd',
			self::SpecialCommittee => 'Særligt udvalg',
		};
	}
}
