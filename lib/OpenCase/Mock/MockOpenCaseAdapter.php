<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\OpenCase\Mock;

use OCA\OpenAgenda\AppInfo\Application;
use OCA\OpenAgenda\OpenCase\Dto\JournalizationRequest;
use OCA\OpenAgenda\OpenCase\Dto\JournalizationResult;
use OCA\OpenAgenda\OpenCase\Dto\OpenCaseCase;
use OCA\OpenAgenda\OpenCase\Dto\OpenCaseDocument;
use OCA\OpenAgenda\OpenCase\Dto\OpenCaseDocumentVersion;
use OCA\OpenAgenda\OpenCase\OpenCaseAdapterInterface;
use OCA\OpenAgenda\OpenCase\OpenCaseException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;

/**
 * Simulated OpenCase. Case metadata is visible to every user with an OpenAgenda
 * role (no case-level access control); document access follows the policy below.
 * Data lives in the oa_mock_* tables and is created by the
 * demo seeder. All data is fictitious and labelled as such.
 *
 * Explicit document access policy of the mock:
 *  - "public":       everybody, including anonymous users
 *  - "internal":     every logged-in user
 *  - "confidential": Nextcloud admins and members of the group configured in
 *                    app config "mock_confidential_group" (default: openagenda_secretaries)
 *
 * Failure simulation: set app config "mock_journalize_fail" to "1" to make
 * journalize() throw, to demonstrate error registration.
 */
class MockOpenCaseAdapter implements OpenCaseAdapterInterface {
	public const NAME = 'mock';
	public const CONFIG_CONFIDENTIAL_GROUP = 'mock_confidential_group';
	public const CONFIG_JOURNALIZE_FAIL = 'mock_journalize_fail';
	public const DEFAULT_CONFIDENTIAL_GROUP = 'openagenda_secretaries';

	public function __construct(
		private IDBConnection $db,
		private IGroupManager $groupManager,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
	) {
	}

	public function getName(): string {
		return self::NAME;
	}

	public function isSimulated(): bool {
		return true;
	}

	public function searchCases(string $query, ?string $userId, int $limit = 20): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('oa_mock_cases');
		$query = trim($query);
		if ($query !== '') {
			$like = '%' . $this->db->escapeLikeParameter($query) . '%';
			$qb->where($qb->expr()->orX(
				$qb->expr()->iLike('case_number', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('title', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('description', $qb->createNamedParameter($like)),
			));
		}
		$qb->orderBy('case_number', 'ASC')->setMaxResults(max(1, min($limit, 50)));
		$result = $qb->executeQuery();
		$cases = [];
		while ($row = $result->fetch()) {
			$cases[] = $this->mapCase($row);
		}
		$result->closeCursor();
		return $cases;
	}

	public function getCase(string $caseId, ?string $userId = null): ?OpenCaseCase {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('oa_mock_cases')
			->where($qb->expr()->eq('external_id', $qb->createNamedParameter($caseId)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $this->mapCase($row);
	}

	public function listDocuments(string $caseId, ?string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('oa_mock_documents')
			->where($qb->expr()->eq('case_external_id', $qb->createNamedParameter($caseId)))
			->orderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$documents = [];
		while ($row = $result->fetch()) {
			$document = $this->mapDocument($row);
			if ($this->isAllowed($userId, $document->accessLevel)) {
				$documents[] = $document;
			}
		}
		$result->closeCursor();
		return $documents;
	}

	public function getDocument(string $documentId, ?string $userId = null): ?OpenCaseDocument {
		$row = $this->fetchDocumentRow($documentId);
		return $row === null ? null : $this->mapDocument($row);
	}

	public function listDocumentVersions(string $documentId, ?string $userId = null): array {
		$row = $this->fetchDocumentRow($documentId);
		return $row === null ? [] : $this->mapVersions((string)$row['versions']);
	}

	public function canAccessDocument(?string $userId, string $documentId): bool {
		$row = $this->fetchDocumentRow($documentId);
		if ($row === null) {
			return false;
		}
		return $this->isAllowed($userId, (string)$row['access_level']);
	}

	public function journalize(JournalizationRequest $request): JournalizationResult {
		if ($this->appConfig->getValueString(Application::APP_ID, self::CONFIG_JOURNALIZE_FAIL, '0') === '1') {
			throw new OpenCaseException('Simuleret fejl: OpenCase (mock) er ikke tilgængelig.');
		}
		if ($this->getCase($request->caseId) === null) {
			throw new OpenCaseException('Sagen ' . $request->caseId . ' findes ikke i OpenCase (mock).');
		}

		$existing = $this->findJournalRef($request->idempotencyKey);
		if ($existing !== null) {
			return new JournalizationResult($existing, true);
		}

		$now = $this->timeFactory->getTime();
		$reference = sprintf('MOCK-JNL-%s-%s', date('Y', $now), strtoupper(bin2hex(random_bytes(4))));
		$qb = $this->db->getQueryBuilder();
		$qb->insert('oa_mock_journal')->values([
			'journal_ref' => $qb->createNamedParameter($reference),
			'idempotency_key' => $qb->createNamedParameter($request->idempotencyKey),
			'case_external_id' => $qb->createNamedParameter($request->caseId),
			'title' => $qb->createNamedParameter(mb_substr($request->title, 0, 255)),
			'document_type' => $qb->createNamedParameter($request->documentType),
			'payload_hash' => $qb->createNamedParameter($request->payloadHash()),
			'content' => $qb->createNamedParameter($request->contentHtml),
			'created_by' => $qb->createNamedParameter($request->requestedBy),
			'created_at' => $qb->createNamedParameter($now),
		]);
		try {
			$qb->executeStatement();
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				// Concurrent request with the same key won the race: return its entry.
				$existing = $this->findJournalRef($request->idempotencyKey);
				if ($existing !== null) {
					return new JournalizationResult($existing, true);
				}
			}
			throw new OpenCaseException('Journalisering i OpenCase (mock) mislykkedes.', 0, $e);
		}
		return new JournalizationResult($reference, false);
	}

	/** Number of journal entries for a case (diagnostics/tests). */
	public function countJournalEntries(string $caseId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from('oa_mock_journal')
			->where($qb->expr()->eq('case_external_id', $qb->createNamedParameter($caseId)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function findJournalRef(string $idempotencyKey): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('journal_ref')->from('oa_mock_journal')
			->where($qb->expr()->eq('idempotency_key', $qb->createNamedParameter($idempotencyKey)));
		$result = $qb->executeQuery();
		$ref = $result->fetchOne();
		$result->closeCursor();
		return $ref === false ? null : (string)$ref;
	}

	private function isAllowed(?string $userId, string $accessLevel): bool {
		if ($accessLevel === OpenCaseDocument::ACCESS_PUBLIC) {
			return true;
		}
		if ($userId === null || $userId === '') {
			return false;
		}
		if ($accessLevel === OpenCaseDocument::ACCESS_INTERNAL) {
			return true;
		}
		if ($accessLevel === OpenCaseDocument::ACCESS_CONFIDENTIAL) {
			if ($this->groupManager->isAdmin($userId)) {
				return true;
			}
			$group = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_CONFIDENTIAL_GROUP, self::DEFAULT_CONFIDENTIAL_GROUP);
			return $group !== '' && $this->groupManager->isInGroup($userId, $group);
		}
		// Unknown access level: deny.
		return false;
	}

	private function fetchDocumentRow(string $documentId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('oa_mock_documents')
			->where($qb->expr()->eq('external_id', $qb->createNamedParameter($documentId)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	private function mapCase(array $row): OpenCaseCase {
		return new OpenCaseCase(
			(string)$row['external_id'],
			(string)$row['case_number'],
			(string)$row['title'],
			$row['description'] !== null ? (string)$row['description'] : null,
			(string)$row['status'],
			$row['responsible_unit'] !== null ? (string)$row['responsible_unit'] : null,
		);
	}

	private function mapDocument(array $row): OpenCaseDocument {
		$versions = $this->mapVersions((string)$row['versions']);
		return new OpenCaseDocument(
			(string)$row['external_id'],
			(string)$row['case_external_id'],
			(string)$row['title'],
			(string)$row['document_type'],
			(string)$row['access_level'],
			$versions === [] ? null : $versions[count($versions) - 1],
		);
	}

	/** @return list<OpenCaseDocumentVersion> */
	private function mapVersions(string $json): array {
		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			return [];
		}
		$versions = [];
		foreach ($decoded as $v) {
			$versions[] = new OpenCaseDocumentVersion(
				(string)$v['versionId'],
				(int)$v['versionNumber'],
				(int)$v['createdAt'],
				isset($v['checksum']) ? (string)$v['checksum'] : null,
			);
		}
		usort($versions, static fn ($a, $b) => $a->versionNumber <=> $b->versionNumber);
		return $versions;
	}
}
