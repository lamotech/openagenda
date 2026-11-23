<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service;

/**
 * Small helpers for validating untrusted request data. Errors are collected
 * per field (Danish messages) and thrown together as a ValidationException.
 */
final class Input {
	/** @var array<string, string> */
	private array $errors = [];

	public function __construct(
		private array $data,
	) {
	}

	public function has(string $key): bool {
		return array_key_exists($key, $this->data);
	}

	public function requiredString(string $key, string $label, int $max = 255): string {
		$value = $this->data[$key] ?? null;
		if (!is_string($value) || trim($value) === '') {
			$this->errors[$key] = $label . ' skal udfyldes.';
			return '';
		}
		$value = trim($value);
		if (mb_strlen($value) > $max) {
			$this->errors[$key] = $label . ' må højst være ' . $max . ' tegn.';
		}
		return $value;
	}

	public function optionalString(string $key, string $label, int $max = 65535): ?string {
		$value = $this->data[$key] ?? null;
		if ($value === null) {
			return null;
		}
		if (!is_string($value)) {
			$this->errors[$key] = $label . ' har et ugyldigt format.';
			return null;
		}
		$value = trim($value);
		if (mb_strlen($value) > $max) {
			$this->errors[$key] = $label . ' må højst være ' . $max . ' tegn.';
		}
		return $value === '' ? null : $value;
	}

	public function bool(string $key, bool $default): bool {
		$value = $this->data[$key] ?? $default;
		if (is_bool($value)) {
			return $value;
		}
		if ($value === 1 || $value === '1' || $value === 'true') {
			return true;
		}
		if ($value === 0 || $value === '0' || $value === 'false') {
			return false;
		}
		$this->errors[$key] = 'Ugyldig værdi.';
		return $default;
	}

	/**
	 * @template T of \BackedEnum
	 * @param class-string<T> $enum
	 * @return T|null
	 */
	public function enum(string $key, string $enum, string $label, bool $required = true): ?\BackedEnum {
		$value = $this->data[$key] ?? null;
		if ($value === null || $value === '') {
			if ($required) {
				$this->errors[$key] = $label . ' skal vælges.';
			}
			return null;
		}
		$case = is_string($value) ? $enum::tryFrom($value) : null;
		if ($case === null) {
			$this->errors[$key] = $label . ' har en ugyldig værdi.';
		}
		return $case;
	}

	/** Accepts ISO 8601 date-time strings or UNIX timestamps; returns UNIX seconds. */
	public function timestamp(string $key, string $label, bool $required = true): ?int {
		$value = $this->data[$key] ?? null;
		if ($value === null || $value === '') {
			if ($required) {
				$this->errors[$key] = $label . ' skal udfyldes.';
			}
			return null;
		}
		if (is_int($value)) {
			return $value;
		}
		if (is_string($value)) {
			try {
				return (new \DateTimeImmutable($value))->getTimestamp();
			} catch (\Exception) {
			}
		}
		$this->errors[$key] = $label . ' er ikke en gyldig dato/tid.';
		return null;
	}

	public function addError(string $key, string $message): void {
		$this->errors[$key] = $message;
	}

	public function raw(string $key): mixed {
		return $this->data[$key] ?? null;
	}

	/** @throws \OCA\OpenAgenda\Domain\Exception\ValidationException */
	public function validate(): void {
		if ($this->errors !== []) {
			throw new \OCA\OpenAgenda\Domain\Exception\ValidationException($this->errors);
		}
	}
}
