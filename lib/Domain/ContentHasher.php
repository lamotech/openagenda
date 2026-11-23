<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain;

/**
 * Canonical JSON encoding + SHA-256, used to seal immutable snapshots and protocols.
 */
final class ContentHasher {
	public static function canonicalJson(array $content): string {
		return json_encode(self::sortKeys($content), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	public static function hash(array $content): string {
		return hash('sha256', self::canonicalJson($content));
	}

	public static function verify(array $content, string $expectedHash): bool {
		return hash_equals($expectedHash, self::hash($content));
	}

	private static function sortKeys(mixed $value): mixed {
		if (!is_array($value)) {
			return $value;
		}
		if (!array_is_list($value)) {
			ksort($value);
		}
		return array_map([self::class, 'sortKeys'], $value);
	}
}
