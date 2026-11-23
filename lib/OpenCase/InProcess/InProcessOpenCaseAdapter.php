<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\OpenCase\InProcess;

use OCA\OpenAgenda\AppInfo\Application;
use OCA\OpenAgenda\OpenCase\Dto\JournalizationRequest;
use OCA\OpenAgenda\OpenCase\Dto\JournalizationResult;
use OCA\OpenAgenda\OpenCase\Dto\OpenCaseCase;
use OCA\OpenAgenda\OpenCase\Dto\OpenCaseDocument;
use OCA\OpenAgenda\OpenCase\Dto\OpenCaseDocumentVersion;
use OCA\OpenAgenda\OpenCase\OpenCaseAdapterInterface;
use OCA\OpenAgenda\OpenCase\OpenCaseException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * OpenCase adapter that calls the OpenCase app's PHP services in the same
 * Nextcloud instance, on behalf of the logged-in user.
 *
 * - Every read is performed with the OpenAgenda user's id, so OpenCase's own
 *   access model (access profiles, per-case grants, per-document deny
 *   overrides) decides what the user sees – access control is truly delegated.
 * - The OpenCase services are internal classes of OpenCase 1.3.x, not a
 *   published API. They are resolved lazily from the container so OpenAgenda
 *   still loads when OpenCase is not installed, and every call is wrapped so
 *   that signature changes surface as OpenCaseException instead of fatals.
 *   Verified against OpenCase 1.3.0.
 *
 * Identifiers: opencaseCaseId = case UUID, documentId = document UUID.
 *
 * App config (openagenda):
 * - opencase_public_insight_levels: comma separated OpenCase insight level ids
 *   whose documents may be listed in the public portal (default: none).
 * - opencase_journal_category_id: document category for journalized protocols (default 3 = "Intern").
 * - opencase_journal_document_type: document type for journalized protocols (default "Referat").
 */
class InProcessOpenCaseAdapter implements OpenCaseAdapterInterface {
	public const NAME = 'opencase';
	public const SUPPORTED_VERSION_PREFIX = '1.3.';

	private const CASE_SERVICE = 'OCA\\OpenCase\\Service\\CaseService';
	private const DOCUMENT_SERVICE = 'OCA\\OpenCase\\Service\\DocumentService';
	private const FILE_SERVICE = 'OCA\\OpenCase\\Service\\FileService';
	private const PERMISSION_SERVICE = 'OCA\\OpenCase\\Service\\PermissionService';
	private const EXPORT_SERVICE = 'OCA\\OpenCase\\Service\\CaseExportService';
	private const DOCUMENT_MAPPER = 'OCA\\OpenCase\\Db\\DocumentMapper';
	private const CASE_MAPPER = 'OCA\\OpenCase\\Db\\CaseMapper';
	private const NOT_FOUND = 'OCA\\OpenCase\\Service\\NotFoundException';

	/** Internal OpenCase insight levels: 1 Åben, 2 Intern, 3 Begrænset, 4 Lukket. */
	private const CONFIDENTIAL_INSIGHT_LEVELS = [3, 4];

	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function getName(): string {
		return self::NAME;
	}

	public function isSimulated(): bool {
		return false;
	}

	public function searchCases(string $query, ?string $userId, int $limit = 20): array {
		if ($userId === null) {
			return [];
		}
		$query = trim($query);
		$result = $this->call(fn () => $this->service(self::CASE_SERVICE)->list($userId, null, null, null, $query === '' ? null : $query, max(1, min($limit, 50))));
		$cases = [];
		foreach ($result['cases'] ?? [] as $entity) {
			if ($entity->getIsInbox()) {
				continue;
			}
			$cases[] = $this->mapCase($entity);
		}
		return $cases;
	}

	public function getCase(string $caseId, ?string $userId): ?OpenCaseCase {
		$entity = $this->findCase($caseId, $userId);
		return $entity === null ? null : $this->mapCase($entity);
	}

	public function listDocuments(string $caseId, ?string $userId): array {
		$case = $this->findCase($caseId, $userId);
		if ($case === null || $userId === null) {
			return [];
		}
		$documents = $this->call(fn () => $this->service(self::DOCUMENT_SERVICE)->listByCase($case->getId(), $userId));
		return array_map(fn ($document) => $this->mapDocument($document, $case, $userId), $documents);
	}

	public function getDocument(string $documentId, ?string $userId): ?OpenCaseDocument {
		if ($userId === null) {
			return null;
		}
		$document = $this->findReadableDocument($documentId, $userId);
		if ($document === null) {
			return null;
		}
		$case = $this->call(fn () => $this->service(self::CASE_MAPPER)->findById($document->getCaseId()));
		return $case === null ? null : $this->mapDocument($document, $case, $userId);
	}

	/**
	 * OpenCase versions files, not documents. A document version is the set of
	 * (file uuid, file version, checksum) of its files. Only the current
	 * composite version can be addressed; historical file versions are listed
	 * for single-file documents (numbered backwards from the current version,
	 * because OpenCase stores no version number on historical rows).
	 */
	public function listDocumentVersions(string $documentId, ?string $userId): array {
		if ($userId === null) {
			return [];
		}
		$document = $this->findReadableDocument($documentId, $userId);
		if ($document === null) {
			return [];
		}
		$files = $this->files($document->getId(), $userId);
		$current = $this->compositeVersion($files);
		if ($current === null) {
			return [];
		}
		$versions = [];
		if (count($files) === 1) {
			$file = $files[0];
			$history = $this->call(fn () => $this->service(self::FILE_SERVICE)->listVersions($file->getId(), $userId));
			$number = $file->getVersion() - 1;
			foreach ($history as $old) { // newest first
				$versions[] = new OpenCaseDocumentVersion(
					$file->getUuid() . ':h:' . $old->getUuid(),
					max(1, $number--),
					(int)$old->getTimestamp(),
					method_exists($old, 'getChecksum') ? $old->getChecksum() : null,
				);
			}
			$versions = array_reverse($versions);
		}
		$versions[] = $current;
		return $versions;
	}

	public function canAccessDocument(?string $userId, string $documentId): bool {
		try {
			$document = $this->service(self::DOCUMENT_MAPPER)->findByUuid($documentId);
			if ($document === null || $document->isSoftDeleted()) {
				return false;
			}
			if ($userId === null) {
				$case = $this->service(self::CASE_MAPPER)->findById($document->getCaseId());
				return $case !== null && $this->accessLevel($document, $case) === OpenCaseDocument::ACCESS_PUBLIC;
			}
			return (bool)$this->service(self::PERMISSION_SERVICE)->userHasReadAccessToDocument($userId, $document->getId());
		} catch (\Throwable $e) {
			$this->logger->warning('OpenAgenda: OpenCase access check failed', ['exception' => $e]);
			return false; // fail closed
		}
	}

	/**
	 * Creates a document with the protocol as file on the case, as the requesting
	 * user (who needs write access to the case in OpenCase).
	 *
	 * OpenCase has no idempotency key, so the adapter embeds a short marker derived
	 * from the key in the document title and looks for it before creating. A
	 * document left without file by an interrupted earlier attempt is completed.
	 */
	public function journalize(JournalizationRequest $request): JournalizationResult {
		$userId = $request->requestedBy;
		$case = $this->findCase($request->caseId, $userId);
		if ($case === null) {
			throw new OpenCaseException('Sagen findes ikke i OpenCase, eller ' . $userId . ' har ikke adgang til den.');
		}
		$marker = '[OA-' . substr(hash('sha256', $request->idempotencyKey), 0, 12) . ']';

		$existing = null;
		foreach ($this->call(fn () => $this->service(self::DOCUMENT_SERVICE)->listByCase($case->getId(), $userId)) as $document) {
			if (str_contains((string)$document->getTitle(), $marker)) {
				$existing = $document;
				break;
			}
		}

		$document = $existing ?? $this->call(fn () => $this->service(self::DOCUMENT_SERVICE)->create(
			$case->getId(),
			mb_substr($request->title, 0, 230) . ' ' . $marker,
			$this->appConfig->getValueString(Application::APP_ID, 'opencase_journal_document_type', 'Referat'),
			$userId,
			null,
			null,
			null,
			null,
			(int)$this->appConfig->getValueString(Application::APP_ID, 'opencase_journal_category_id', '3'),
			'Journaliseret automatisk fra OpenAgenda. Protokol ' . ($request->metadata['protocolUuid'] ?? '') . ', indholds-hash ' . ($request->metadata['contentHash'] ?? ''),
		), 'Dokumentet kunne ikke oprettes i OpenCase (kræver skriveadgang til sagen)');

		if ($this->files($document->getId(), $userId) === []) {
			$version = (string)($request->metadata['protocolVersion'] ?? '1');
			$this->call(fn () => $this->service(self::FILE_SERVICE)->upload(
				$document->getId(),
				'beslutningsprotokol-v' . $version . '.html',
				'text/html',
				$request->contentHtml,
				$userId,
			), 'Protokolfilen kunne ikke uploades til OpenCase');
		}

		return new JournalizationResult((string)($document->getDocumentNumber() ?? $document->getUuid()), $existing !== null);
	}

	private function findCase(string $caseUuid, ?string $userId): ?object {
		if ($userId === null || $caseUuid === '') {
			return null;
		}
		try {
			return $this->service(self::CASE_SERVICE)->getByUuid($caseUuid, $userId);
		} catch (\Throwable $e) {
			if (is_a($e, self::NOT_FOUND)) {
				return null; // not found or no read access – OpenCase does not distinguish
			}
			throw $this->wrap($e, 'Sagen kunne ikke hentes fra OpenCase');
		}
	}

	private function findReadableDocument(string $documentUuid, string $userId): ?object {
		try {
			$document = $this->service(self::DOCUMENT_MAPPER)->findByUuid($documentUuid);
			if ($document === null || $document->isSoftDeleted()) {
				return null;
			}
			if (!$this->service(self::PERMISSION_SERVICE)->userHasReadAccessToDocument($userId, $document->getId())) {
				return null;
			}
			return $document;
		} catch (\Throwable $e) {
			throw $this->wrap($e, 'Dokumentet kunne ikke hentes fra OpenCase');
		}
	}

	/** @return list<object> */
	private function files(int $documentId, string $userId): array {
		return array_values($this->call(fn () => $this->service(self::FILE_SERVICE)->listByDocument($documentId, $userId)));
	}

	private function mapCase(object $entity): OpenCaseCase {
		$fields = $this->call(fn () => $this->service(self::EXPORT_SERVICE)->caseFields($entity, null));
		$summary = isset($fields['Summary']) && $fields['Summary'] !== null
			? trim(html_entity_decode(strip_tags((string)$fields['Summary']), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
			: null;
		return new OpenCaseCase(
			(string)$entity->getUuid(),
			(string)$entity->getCaseNumber(),
			(string)$entity->getTitle(),
			$summary === '' ? null : $summary,
			(string)($fields['StatusName'] ?? ''),
			isset($fields['OrganisationName']) ? (string)$fields['OrganisationName'] : null,
		);
	}

	private function mapDocument(object $document, object $case, string $userId): OpenCaseDocument {
		return new OpenCaseDocument(
			(string)$document->getUuid(),
			(string)$case->getUuid(),
			(string)$document->getTitle(),
			(string)($document->getDocumentType() ?? 'Dokument'),
			$this->accessLevel($document, $case),
			$this->compositeVersion($this->files($document->getId(), $userId)),
		);
	}

	/** @param list<object> $files */
	private function compositeVersion(array $files): ?OpenCaseDocumentVersion {
		if ($files === []) {
			return null;
		}
		usort($files, static fn ($a, $b) => $a->getId() <=> $b->getId());
		$parts = [];
		$checksums = [];
		$maxVersion = 1;
		$updated = 0;
		foreach ($files as $file) {
			$parts[] = $file->getUuid() . ':v' . $file->getVersion();
			$checksums[] = (string)$file->getChecksum();
			$maxVersion = max($maxVersion, (int)$file->getVersion());
			$updated = max($updated, $file->getUpdatedAt() instanceof \DateTimeInterface ? $file->getUpdatedAt()->getTimestamp() : 0);
		}
		return new OpenCaseDocumentVersion(
			implode(',', $parts),
			$maxVersion,
			$updated,
			count($files) === 1 ? ($checksums[0] ?: null) : hash('sha256', implode('|', $checksums)),
		);
	}

	/**
	 * Maps OpenCase's insight level (document, falling back to the case) to the
	 * OpenAgenda levels. Nothing is public unless explicitly configured, because
	 * "Åben (Offentlig)" in OpenCase means "open within the organisation", not
	 * "may be published on the internet".
	 */
	private function accessLevel(object $document, object $case): string {
		$level = $document->getInsightLevelId() ?? $case->getInsightLevelId();
		if ($level !== null && in_array((int)$level, $this->publicInsightLevels(), true)) {
			return OpenCaseDocument::ACCESS_PUBLIC;
		}
		if ($level !== null && in_array((int)$level, self::CONFIDENTIAL_INSIGHT_LEVELS, true)) {
			return OpenCaseDocument::ACCESS_CONFIDENTIAL;
		}
		return OpenCaseDocument::ACCESS_INTERNAL;
	}

	/** @return list<int> */
	private function publicInsightLevels(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, 'opencase_public_insight_levels', '');
		return array_values(array_map('intval', array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== '')));
	}

	private function service(string $class): object {
		if (!class_exists($class)) {
			throw new OpenCaseException('OpenCase-appen er ikke installeret eller aktiveret.');
		}
		return $this->container->get($class);
	}

	/**
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	private function call(callable $fn, string $context = 'Kald til OpenCase mislykkedes') {
		try {
			return $fn();
		} catch (OpenCaseException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw $this->wrap($e, $context);
		}
	}

	private function wrap(\Throwable $e, string $context): OpenCaseException {
		if (is_a($e, self::NOT_FOUND)) {
			return new OpenCaseException($context . ': ikke fundet eller ingen adgang.', 0, $e);
		}
		if ($e instanceof \InvalidArgumentException) {
			return new OpenCaseException($context . ': ' . $e->getMessage(), 0, $e);
		}
		$this->logger->error('OpenAgenda: unexpected OpenCase error', ['exception' => $e]);
		return new OpenCaseException($context . '. Se Nextcloud-loggen for detaljer.', 0, $e);
	}
}
