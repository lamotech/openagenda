<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\OpenCase;

use OCA\OpenAgenda\OpenCase\Dto\JournalizationRequest;
use OCA\OpenAgenda\OpenCase\Dto\JournalizationResult;
use OCA\OpenAgenda\OpenCase\Dto\OpenCaseCase;
use OCA\OpenAgenda\OpenCase\Dto\OpenCaseDocument;
use OCA\OpenAgenda\OpenCase\Dto\OpenCaseDocumentVersion;

/**
 * Port to the authoritative case management system (OpenCase).
 *
 * OpenAgenda never stores or modifies cases, documents or journal entries
 * itself; it only keeps references (case id, document id, version id).
 * Implementations decide access (access control is delegated): every read
 * takes the id of the Nextcloud user it is performed for. null means an
 * anonymous/system context and must never grant more than public access.
 *
 * This contract is defined by OpenAgenda's needs and does NOT describe a
 * concrete OpenCase API. A production adapter must map it onto whatever
 * interface OpenCase actually provides.
 */
interface OpenCaseAdapterInterface {
	/** Short identifier shown in UI/diagnostics, e.g. "mock". */
	public function getName(): string;

	/**
	 * @return list<OpenCaseCase>
	 * @throws OpenCaseException
	 */
	public function searchCases(string $query, ?string $userId, int $limit = 20): array;

	/**
	 * Case metadata, or null if it does not exist or the user may not read it.
	 *
	 * @throws OpenCaseException
	 */
	public function getCase(string $caseId, ?string $userId): ?OpenCaseCase;

	/**
	 * Documents on a case that the given user may see. Pass null for anonymous/public context.
	 *
	 * @return list<OpenCaseDocument>
	 * @throws OpenCaseException
	 */
	public function listDocuments(string $caseId, ?string $userId): array;

	/**
	 * Document metadata, or null if it does not exist or the user may not read it.
	 *
	 * @throws OpenCaseException
	 */
	public function getDocument(string $documentId, ?string $userId): ?OpenCaseDocument;

	/**
	 * @return list<OpenCaseDocumentVersion> oldest first
	 * @throws OpenCaseException
	 */
	public function listDocumentVersions(string $documentId, ?string $userId): array;

	/**
	 * Access decision for a document. $userId null means anonymous (public portal).
	 */
	public function canAccessDocument(?string $userId, string $documentId): bool;

	/**
	 * Create a journal entry on a case. Must be idempotent on $request->idempotencyKey.
	 *
	 * @throws OpenCaseException
	 */
	public function journalize(JournalizationRequest $request): JournalizationResult;

	/**
	 * True when this adapter talks to real OpenCase data (UI warning for the mock).
	 */
	public function isSimulated(): bool;
}
