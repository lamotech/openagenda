<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Tests\Integration;

use OCA\OpenAgenda\OpenCase\InProcess\InProcessOpenCaseAdapter;
use OCP\App\IAppManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Read-only smoke test of InProcessOpenCaseAdapter against the real OpenCase app.
 * Opt-in: OA_OPENCASE_LIVE_USER=<uid of a user with OpenCase access>.
 * Note: OpenCase writes "case viewed" entries to its own audit log for that user.
 */
final class OpenCaseInProcessLiveTest extends TestCase {
	private string $userId;

	protected function setUp(): void {
		$user = getenv('OA_OPENCASE_LIVE_USER');
		if ($user === false || $user === '') {
			$this->markTestSkipped('Set OA_OPENCASE_LIVE_USER to run against the real OpenCase app');
		}
		if (!Server::get(IAppManager::class)->isEnabledForAnyone('opencase')) {
			$this->markTestSkipped('OpenCase app is not enabled');
		}
		$this->userId = $user;
	}

	public function testReadsRealCasesDocumentsAndVersionsAsUser(): void {
		$adapter = Server::get(InProcessOpenCaseAdapter::class);
		$cases = $adapter->searchCases('', $this->userId, 20);
		$this->assertNotEmpty($cases, 'user sees at least one OpenCase case');

		$checkedDocument = false;
		foreach ($cases as $case) {
			$this->assertNotSame('', $case->caseNumber);
			$this->assertSame($case->caseNumber, $adapter->getCase($case->id, $this->userId)?->caseNumber);
			foreach ($adapter->listDocuments($case->id, $this->userId) as $document) {
				$this->assertTrue($adapter->canAccessDocument($this->userId, $document->id));
				$this->assertSame($case->id, $adapter->getDocument($document->id, $this->userId)?->caseId);
				if ($document->latestVersion !== null) {
					$versions = $adapter->listDocumentVersions($document->id, $this->userId);
					$this->assertSame($document->latestVersion->versionId, end($versions)->versionId);
				}
				$this->assertFalse($adapter->canAccessDocument(null, $document->id), 'nothing public without configuration');
				$checkedDocument = true;
				break 2;
			}
		}
		$this->assertTrue($checkedDocument, 'found at least one readable document');

		// A user without OpenCase access sees nothing.
		$this->assertSame([], $adapter->searchCases('', 'openagenda-no-such-user', 5));
		$this->assertNull($adapter->getCase($cases[0]->id, 'openagenda-no-such-user'));
	}
}
