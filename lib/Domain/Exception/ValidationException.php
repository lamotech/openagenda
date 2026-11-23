<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain\Exception;

/**
 * Input validation failed. Carries per-field error messages.
 */
class ValidationException extends OpenAgendaException {
	/**
	 * @param array<string, string> $errors field => message
	 */
	public function __construct(
		private array $errors,
		string $message = 'Ugyldige data.',
	) {
		parent::__construct($message);
	}

	/** @return array<string, string> */
	public function getErrors(): array {
		return $this->errors;
	}
}
