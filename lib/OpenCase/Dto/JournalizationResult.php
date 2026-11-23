<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\OpenCase\Dto;

final class JournalizationResult {
	public function __construct(
		public readonly string $journalReference,
		/** True when the adapter recognised the idempotency key and returned an existing entry. */
		public readonly bool $alreadyExisted,
	) {
	}
}
