<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain;

enum Role: string {
	case Admin = 'admin';
	case Secretary = 'secretary';
	case Caseworker = 'caseworker';
	case Politician = 'politician';

	public function label(): string {
		return match ($this) {
			self::Admin => 'Administrator',
			self::Secretary => 'Sekretariat',
			self::Caseworker => 'Sagsbehandler',
			self::Politician => 'Politiker',
		};
	}
}
