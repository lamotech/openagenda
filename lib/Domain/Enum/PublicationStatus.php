<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain\Enum;

enum PublicationStatus: string {
	case Published = 'published';
	case Withdrawn = 'withdrawn';

	public function label(): string {
		return match ($this) {
			self::Published => 'Offentliggjort',
			self::Withdrawn => 'Trukket tilbage',
		};
	}
}
