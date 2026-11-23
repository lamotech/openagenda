<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain\Enum;

enum ProtocolStatus: string {
	case Draft = 'draft';
	case Approved = 'approved';

	public function label(): string {
		return match ($this) {
			self::Draft => 'Kladde',
			self::Approved => 'Godkendt',
		};
	}
}
