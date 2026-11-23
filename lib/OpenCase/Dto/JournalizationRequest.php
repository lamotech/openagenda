<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\OpenCase\Dto;

/**
 * Payload sent to OpenCase when journalizing a document (e.g. an approved protocol).
 * The idempotency key must make repeated calls return the same journal entry.
 */
final class JournalizationRequest {
	/**
	 * @param array<string, scalar|null> $metadata
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $idempotencyKey,
		public readonly string $title,
		public readonly string $documentType,
		public readonly string $contentHtml,
		public readonly string $requestedBy,
		public readonly array $metadata = [],
	) {
	}

	public function payloadHash(): string {
		return hash('sha256', $this->caseId . "\n" . $this->title . "\n" . $this->documentType . "\n" . $this->contentHtml);
	}
}
