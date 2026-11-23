<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Tests\Unit;

use OCA\OpenAgenda\OpenCase\Dto\JournalizationRequest;
use OCA\OpenAgenda\OpenCase\Dto\OpenCaseDocument;
use OCA\OpenAgenda\OpenCase\InProcess\InProcessOpenCaseAdapter;
use OCA\OpenAgenda\OpenCase\OpenCaseException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests the in-process adapter's mapping and idempotency logic against
 * in-memory stand-ins for the OpenCase services (no OpenCase data touched).
 * Requires the OpenCase classes to be autoloadable (the adapter checks class_exists).
 */
final class InProcessOpenCaseAdapterTest extends TestCase {
	private FakeOpenCase $oc;
	private array $config = [];

	protected function setUp(): void {
		if (!class_exists('OCA\\OpenCase\\Service\\CaseService')) {
			$this->markTestSkipped('OpenCase app is not installed');
		}
		$this->oc = new FakeOpenCase();
		$this->config = [];
	}

	private function adapter(): InProcessOpenCaseAdapter {
		$container = new class($this->oc) implements ContainerInterface {
			public function __construct(private FakeOpenCase $oc) {
			}
			public function get(string $id): mixed {
				return $this->oc;
			}
			public function has(string $id): bool {
				return true;
			}
		};
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn ($app, $key, $default = '') => $this->config[$key] ?? $default);
		return new InProcessOpenCaseAdapter($container, $config, new NullLogger());
	}

	public function testReadsAreDoneAsTheUserAndRespectOpenCaseAccess(): void {
		$adapter = $this->adapter();
		$this->assertNotNull($adapter->getCase('case-uuid', 'alice'));
		$this->assertNull($adapter->getCase('case-uuid', 'mallory'), 'OpenCase denies mallory');
		$this->assertNull($adapter->getCase('case-uuid', null), 'no anonymous case access');
		$this->assertSame(['alice', 'mallory'], array_values(array_unique($this->oc->caseReadsBy)));

		$docs = $adapter->listDocuments('case-uuid', 'alice');
		$this->assertSame(['doc-open', 'doc-closed'], array_map(static fn ($d) => $d->id, $docs));
		$this->assertTrue($adapter->canAccessDocument('alice', 'doc-closed'));
		$this->assertFalse($adapter->canAccessDocument('bob', 'doc-closed'), 'per-document deny in OpenCase');
		$this->assertFalse($adapter->canAccessDocument('alice', 'unknown'));
		$this->assertNull($adapter->getDocument('doc-closed', 'bob'));
	}

	public function testNothingIsPublicUnlessConfigured(): void {
		$adapter = $this->adapter();
		$this->assertSame(OpenCaseDocument::ACCESS_INTERNAL, $adapter->getDocument('doc-open', 'alice')->accessLevel);
		$this->assertSame(OpenCaseDocument::ACCESS_CONFIDENTIAL, $adapter->getDocument('doc-closed', 'alice')->accessLevel);
		$this->assertFalse($adapter->canAccessDocument(null, 'doc-open'));

		$this->config['opencase_public_insight_levels'] = '1';
		$adapter = $this->adapter();
		$this->assertSame(OpenCaseDocument::ACCESS_PUBLIC, $adapter->getDocument('doc-open', 'alice')->accessLevel);
		$this->assertTrue($adapter->canAccessDocument(null, 'doc-open'));
		$this->assertFalse($adapter->canAccessDocument(null, 'doc-closed'));
	}

	public function testDocumentVersionIsCompositeOfFileVersions(): void {
		$adapter = $this->adapter();
		$version = $adapter->getDocument('doc-open', 'alice')->latestVersion;
		$this->assertSame('file-a:v3,file-b:v1', $version->versionId);
		$this->assertSame(3, $version->versionNumber);
		$this->assertSame(hash('sha256', 'ca|cb'), $version->checksum);
	}

	public function testJournalizeIsIdempotentAndCompletesInterruptedAttempts(): void {
		$adapter = $this->adapter();
		$request = new JournalizationRequest('case-uuid', 'openagenda:protocol:p1:case:case-uuid', 'Beslutningsprotokol – Byrådet', 'beslutningsprotokol', '<html>p</html>', 'alice', ['protocolVersion' => 1]);

		$first = $adapter->journalize($request);
		$this->assertFalse($first->alreadyExisted);
		$this->assertSame('2026-00001-3', $first->journalReference);
		$this->assertCount(1, $this->oc->created);
		$this->assertStringContainsString('[OA-', $this->oc->created[0]['title']);
		$this->assertSame(3, $this->oc->created[0]['category']);
		$this->assertSame('Referat', $this->oc->created[0]['type']);
		$this->assertCount(1, $this->oc->uploads);
		$this->assertSame('alice', $this->oc->created[0]['user'], 'created as the requesting user');

		$second = $adapter->journalize($request);
		$this->assertTrue($second->alreadyExisted);
		$this->assertSame($first->journalReference, $second->journalReference);
		$this->assertCount(1, $this->oc->created, 'no duplicate document');
		$this->assertCount(1, $this->oc->uploads, 'no duplicate file');

		// A document created by an interrupted attempt (no file) is completed.
		$this->oc->uploads = [];
		$this->oc->filesByDocument[3] = [];
		$adapter->journalize($request);
		$this->assertCount(1, $this->oc->created);
		$this->assertCount(1, $this->oc->uploads);
	}

	public function testJournalizeWithoutWriteAccessFails(): void {
		$this->expectException(OpenCaseException::class);
		$this->adapter()->journalize(new JournalizationRequest('case-uuid', 'k', 'T', 'beslutningsprotokol', 'x', 'bob', []));
	}

	public function testSearchSkipsInboxCasesAndRequiresUser(): void {
		$adapter = $this->adapter();
		$this->assertSame(['2026-00001'], array_map(static fn ($c) => $c->caseNumber, $adapter->searchCases('lokalplan', 'alice')));
		$this->assertSame('Under behandling', $adapter->searchCases('', 'alice')[0]->status);
		$this->assertSame('Forslag til lokalplan', $adapter->searchCases('', 'alice')[0]->description);
		$this->assertSame([], $adapter->searchCases('lokalplan', null));
	}
}

/**
 * Minimal in-memory stand-in for the OpenCase services/mappers the adapter uses.
 * Users: alice (read+write), bob (case read, denied doc-closed, no write), mallory (nothing).
 */
final class FakeOpenCase {
	public array $caseReadsBy = [];
	public array $created = [];
	public array $uploads = [];
	public array $filesByDocument;
	private array $documents;
	private object $case;
	private object $inbox;

	public function __construct() {
		$this->case = new FakeEntity(['id' => 1, 'uuid' => 'case-uuid', 'caseNumber' => '2026-00001', 'title' => 'Lokalplan', 'insightLevelId' => 2, 'isInbox' => false]);
		$this->inbox = new FakeEntity(['id' => 2, 'uuid' => 'inbox', 'caseNumber' => '2026-00002', 'title' => 'Indbakke', 'insightLevelId' => null, 'isInbox' => true]);
		$this->documents = [
			1 => new FakeEntity(['id' => 1, 'caseId' => 1, 'uuid' => 'doc-open', 'title' => 'Lokalplanforslag', 'documentType' => 'Bilag', 'insightLevelId' => 1, 'documentNumber' => '2026-00001-1']),
			2 => new FakeEntity(['id' => 2, 'caseId' => 1, 'uuid' => 'doc-closed', 'title' => 'Høringssvar', 'documentType' => null, 'insightLevelId' => 3, 'documentNumber' => '2026-00001-2']),
		];
		$this->filesByDocument = [
			1 => [
				new FakeEntity(['id' => 10, 'uuid' => 'file-a', 'version' => 3, 'checksum' => 'ca', 'updatedAt' => new \DateTime('@1700000000')]),
				new FakeEntity(['id' => 11, 'uuid' => 'file-b', 'version' => 1, 'checksum' => 'cb', 'updatedAt' => new \DateTime('@1700000100')]),
			],
			2 => [],
		];
	}

	private function notFound(): \Throwable {
		$class = 'OCA\\OpenCase\\Service\\NotFoundException';
		return new $class('Access denied');
	}

	// CaseService
	public function getByUuid(string $uuid, string $userId): object {
		$this->caseReadsBy[] = $userId;
		if ($uuid !== 'case-uuid' || !in_array($userId, ['alice', 'bob'], true)) {
			throw $this->notFound();
		}
		return $this->case;
	}

	public function list(string $userId, $o, $y, $s, ?string $search, int $limit): array {
		$cases = $userId === 'mallory' ? [] : [$this->case, $this->inbox];
		if ($search !== null) {
			$cases = array_filter($cases, static fn ($c) => stripos($c->getTitle(), $search) !== false);
		}
		return ['cases' => array_values($cases), 'total' => count($cases)];
	}

	// CaseExportService
	public function caseFields(object $case, $exportedAt): array {
		return ['Summary' => '<p>Forslag til lokalplan</p>', 'StatusName' => 'Under behandling', 'OrganisationName' => 'Plan'];
	}

	// DocumentService::listByCase / CaseMapper::findById share names with other services; dispatch by arity
	public function listByCase(int $caseId, string $userId): array {
		if (!in_array($userId, ['alice', 'bob'], true)) {
			throw $this->notFound();
		}
		return array_values(array_filter($this->documents, fn ($d) => $d->getCaseId() === $caseId && $this->userHasReadAccessToDocument($userId, $d->getId())));
	}

	public function findById(int $id): ?object {
		return $id === 1 ? $this->case : null;
	}

	public function findByUuid(string $uuid): ?object {
		foreach ($this->documents as $d) {
			if ($d->getUuid() === $uuid) {
				return $d;
			}
		}
		return null;
	}

	public function create(int $caseId, string $title, ?string $type, string $userId, $i, $dd, $rd, $reg, ?int $category, ?string $summary): object {
		if ($userId !== 'alice') {
			throw $this->notFound();
		}
		$id = count($this->documents) + 1;
		$doc = new FakeEntity(['id' => $id, 'caseId' => $caseId, 'uuid' => 'doc-' . $id, 'title' => $title, 'documentType' => $type, 'insightLevelId' => null, 'documentNumber' => '2026-00001-' . $id]);
		$this->documents[$id] = $doc;
		$this->filesByDocument[$id] = [];
		$this->created[] = ['title' => $title, 'type' => $type, 'user' => $userId, 'category' => $category];
		return $doc;
	}

	// PermissionService
	public function userHasReadAccessToDocument(string $userId, int $documentId): bool {
		return $userId === 'alice' || ($userId === 'bob' && $documentId !== 2);
	}

	// FileService
	public function listByDocument(int $documentId, string $userId): array {
		return $this->filesByDocument[$documentId] ?? [];
	}

	public function listVersions(int $fileId, string $userId): array {
		return [];
	}

	public function upload(int $documentId, string $name, string $mime, $content, string $userId): object {
		$file = new FakeEntity(['id' => 100 + $documentId, 'uuid' => 'f' . $documentId, 'version' => 1, 'checksum' => 'x', 'updatedAt' => new \DateTime()]);
		$this->filesByDocument[$documentId][] = $file;
		$this->uploads[] = [$documentId, $name, $mime];
		return $file;
	}
}

final class FakeEntity {
	public function __construct(private array $data) {
	}

	public function __call(string $name, array $args): mixed {
		if ($name === 'isSoftDeleted') {
			return false;
		}
		$key = lcfirst(substr($name, 3));
		return $this->data[$key] ?? null;
	}
}
