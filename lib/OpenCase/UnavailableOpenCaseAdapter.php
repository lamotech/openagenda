<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\OpenCase;

use OCA\OpenAgenda\OpenCase\Dto\JournalizationRequest;
use OCA\OpenAgenda\OpenCase\Dto\JournalizationResult;
use OCA\OpenAgenda\OpenCase\Dto\OpenCaseCase;
use OCA\OpenAgenda\OpenCase\Dto\OpenCaseDocument;

/**
 * Used when the configured OpenCase backend is not available. Every call fails
 * visibly; document access is always denied (fail closed).
 */
class UnavailableOpenCaseAdapter implements OpenCaseAdapterInterface {
	public function __construct(
		private string $reason,
	) {
	}

	public function getName(): string {
		return 'unavailable';
	}

	public function isSimulated(): bool {
		return false;
	}

	public function searchCases(string $query, ?string $userId, int $limit = 20): array {
		throw new OpenCaseException($this->reason);
	}

	public function getCase(string $caseId, ?string $userId): ?OpenCaseCase {
		throw new OpenCaseException($this->reason);
	}

	public function listDocuments(string $caseId, ?string $userId): array {
		throw new OpenCaseException($this->reason);
	}

	public function getDocument(string $documentId, ?string $userId): ?OpenCaseDocument {
		throw new OpenCaseException($this->reason);
	}

	public function listDocumentVersions(string $documentId, ?string $userId): array {
		throw new OpenCaseException($this->reason);
	}

	public function canAccessDocument(?string $userId, string $documentId): bool {
		return false;
	}

	public function journalize(JournalizationRequest $request): JournalizationResult {
		throw new OpenCaseException($this->reason);
	}
}
