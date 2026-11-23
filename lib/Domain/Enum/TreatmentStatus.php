<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain\Enum;

enum TreatmentStatus: string {
	case Pending = 'pending';
	case Decided = 'decided';
	case Forwarded = 'forwarded';

	public function label(): string {
		return match ($this) {
			self::Pending => 'Afventer behandling',
			self::Decided => 'Behandlet',
			self::Forwarded => 'Videreført',
		};
	}
}
