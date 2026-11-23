<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain\Enum;

enum JournalizationStatus: string {
	case Pending = 'pending';
	case Succeeded = 'succeeded';
	case Failed = 'failed';

	public function label(): string {
		return match ($this) {
			self::Pending => 'Afventer',
			self::Succeeded => 'Journaliseret',
			self::Failed => 'Fejlet',
		};
	}
}
