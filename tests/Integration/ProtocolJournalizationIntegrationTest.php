<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Tests\Integration;

use OCA\OpenAgenda\AppInfo\Application;
use OCA\OpenAgenda\Db\Meeting;
use OCA\OpenAgenda\Db\ProtocolMapper;
use OCA\OpenAgenda\Domain\Exception\ConflictException;
use OCA\OpenAgenda\Domain\Exception\ForbiddenException;
use OCA\OpenAgenda\Domain\Exception\ImmutableRecordException;
use OCA\OpenAgenda\Domain\Exception\InvalidTransitionException;
use OCA\OpenAgenda\OpenCase\Mock\MockOpenCaseAdapter;
use OCA\OpenAgenda\Service\AgendaService;
use OCA\OpenAgenda\Service\JournalizationService;
use OCA\OpenAgenda\Service\ProtocolService;
use OCA\OpenAgenda\Service\TreatmentService;
use OCP\IAppConfig;
use OCP\Server;

final class ProtocolJournalizationIntegrationTest extends IntegrationTestCase {
	protected function tearDown(): void {
		Server::get(IAppConfig::class)->deleteKey(Application::APP_ID, MockOpenCaseAdapter::CONFIG_JOURNALIZE_FAIL);
		parent::tearDown();
	}

	private function decidedMeeting(): array {
		$meeting = $this->createMeeting($this->createCommittee('Protokol ' . bin2hex(random_bytes(2)), 'city_council'), 5);
		$public = $this->createSubmittedItem($meeting, 'case1', true);
		$closed = $this->createSubmittedItem($meeting, 'case2', false);
		Server::get(AgendaService::class)->publish($this->actor('secretary'), $meeting->getUuid(), true);
		$this->startMeeting($meeting);
		Server::get(TreatmentService::class)->registerDecision($this->actor('secretary'), $public->getUuid(), ['decisionType' => 'approved', 'decisionText' => 'Godkendt.']);
		return [$meeting, $public, $closed];
	}

	public function testProtocolGenerationApprovalAndImmutability(): void {
		[$meeting, , $closed] = $this->decidedMeeting();
		$protocols = Server::get(ProtocolService::class);

		$draft = $protocols->generate($this->actor('secretary'), $meeting->getUuid());
		$this->assertSame(1, $draft->getVersion());
		$this->assertSame('draft', $draft->getStatus());
		$this->assertStringContainsString('Beslutningsprotokol', $draft->getHtml());
		$this->assertStringContainsString('Ingen beslutning registreret', $draft->getHtml());

		try {
			$protocols->approve($this->actor('secretary'), $draft->getUuid());
			$this->fail('Meeting must be completed first');
		} catch (InvalidTransitionException) {
		}
		try {
			$this->completeMeeting($meeting);
			$this->fail('Cannot complete with undecided items');
		} catch (ConflictException) {
		}
		Server::get(TreatmentService::class)->registerDecision($this->actor('secretary'), $closed->getUuid(), ['decisionType' => 'approved', 'decisionText' => 'Købet godkendt.']);
		$this->completeMeeting($meeting);

		try {
			$protocols->approve($this->actor('secretary'), $draft->getUuid());
			$this->fail('Stale draft (decision added after generation) must be regenerated');
		} catch (ConflictException) {
		}
		$draft = $protocols->generate($this->actor('secretary'), $meeting->getUuid());
		$this->assertSame(1, $draft->getVersion(), 'Draft is regenerated in place');
		$this->assertStringContainsString('Lukket punkt', $draft->getHtml());

		try {
			$protocols->approve($this->actor('politician'), $draft->getUuid());
			$this->fail('Politician must not approve');
		} catch (ForbiddenException) {
		}
		$approved = $protocols->approve($this->actor('secretary'), $draft->getUuid());
		$this->assertSame('approved', $approved->getStatus());
		$this->assertSame(self::$users['secretary'], $approved->getApprovedBy());
		$this->assertTrue($protocols->verify($approved));

		$mapper = Server::get(ProtocolMapper::class);
		$stored = $mapper->findByUuid($approved->getUuid());
		$stored->setHtml('<p>manipuleret</p>');
		try {
			$mapper->update($stored);
			$this->fail('Approved protocol must be immutable');
		} catch (ImmutableRecordException) {
		}

		// Regenerating after approval creates a new version, the approved one is untouched.
		$v2 = $protocols->generate($this->actor('secretary'), $meeting->getUuid());
		$this->assertSame(2, $v2->getVersion());
		$this->assertSame($approved->getContentHash(), $mapper->findByUuid($approved->getUuid())->getContentHash());
	}

	public function testJournalizationIsIdempotentAndRecordsFailures(): void {
		[$meeting, , $closed] = $this->decidedMeeting();
		Server::get(TreatmentService::class)->registerDecision($this->actor('secretary'), $closed->getUuid(), ['decisionType' => 'rejected']);
		$this->completeMeeting($meeting);
		$protocols = Server::get(ProtocolService::class);
		$journalization = Server::get(JournalizationService::class);
		$adapter = Server::get(MockOpenCaseAdapter::class);

		$draft = $protocols->generate($this->actor('secretary'), $meeting->getUuid());
		try {
			$journalization->journalize($this->actor('secretary'), $draft->getUuid());
			$this->fail('Only approved protocols');
		} catch (InvalidTransitionException) {
		}
		$protocol = $protocols->approve($this->actor('secretary'), $draft->getUuid());

		try {
			$journalization->journalize($this->actor('caseworker'), $protocol->getUuid());
			$this->fail('Caseworker must not journalize');
		} catch (ForbiddenException) {
		}

		// Simulated OpenCase failure is recorded, nothing is journalized.
		Server::get(IAppConfig::class)->setValueString(Application::APP_ID, MockOpenCaseAdapter::CONFIG_JOURNALIZE_FAIL, '1');
		$failed = $journalization->journalize($this->actor('secretary'), $protocol->getUuid());
		$this->assertSame(2, $failed['failed']);
		$this->assertSame('failed', $failed['journalizations'][0]->getStatus());
		$this->assertStringContainsString('Simuleret fejl', $failed['journalizations'][0]->getErrorMessage());
		$this->assertSame(0, $adapter->countJournalEntries(self::caseId('case1')));

		// Retry succeeds; one journal entry per case.
		Server::get(IAppConfig::class)->deleteKey(Application::APP_ID, MockOpenCaseAdapter::CONFIG_JOURNALIZE_FAIL);
		$ok = $journalization->journalize($this->actor('secretary'), $protocol->getUuid());
		$this->assertSame(2, $ok['created']);
		$rows = $ok['journalizations'];
		$this->assertCount(2, $rows);
		foreach ($rows as $row) {
			$this->assertSame('succeeded', $row->getStatus());
			$this->assertStringStartsWith('MOCK-JNL-', $row->getExternalRef());
			$this->assertNull($row->getErrorMessage());
			$this->assertSame(2, $row->getAttempts());
		}

		// Repeated calls do not create further journal entries.
		$again = $journalization->journalize($this->actor('secretary'), $protocol->getUuid());
		$this->assertSame(0, $again['created']);
		$this->assertSame(2, $again['skipped']);
		$this->assertSame($rows[0]->getExternalRef(), $again['journalizations'][0]->getExternalRef());
		$this->assertSame(1, $adapter->countJournalEntries(self::caseId('case1')));
		$this->assertSame(1, $adapter->countJournalEntries(self::caseId('case2')));
	}
}
