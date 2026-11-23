<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Tests\Integration;

use OCA\OpenAgenda\Db\CommitteeMapper;
use OCA\OpenAgenda\Db\MeetingMapper;
use OCA\OpenAgenda\Domain\Exception\ConflictException;
use OCA\OpenAgenda\Domain\Exception\ForbiddenException;
use OCA\OpenAgenda\Domain\Exception\InvalidTransitionException;
use OCA\OpenAgenda\Domain\Exception\ValidationException;
use OCA\OpenAgenda\Service\CommitteeService;
use OCA\OpenAgenda\Service\MeetingService;
use OCP\IDBConnection;
use OCP\Server;

final class MeetingIntegrationTest extends IntegrationTestCase {
	public function testCreateCommitteeAndMeetingIsPersisted(): void {
		$committee = $this->createCommittee('Teknik');
		$meeting = $this->createMeeting($committee, 10, 'Persistensmøde');

		// Read back through a fresh query (no entity reuse).
		$reloaded = Server::get(MeetingMapper::class)->findByUuid($meeting->getUuid());
		$this->assertSame('Persistensmøde', $reloaded->getTitle());
		$this->assertSame('planned', $reloaded->getStatus());
		$this->assertSame($committee->getId(), $reloaded->getCommitteeId());
		$this->assertSame('Testlokale', $reloaded->getLocation());
		$this->assertSame(36, strlen($reloaded->getUuid()));

		$this->assertTrue(Server::get(IDBConnection::class)->tableExists('oa_meetings'));
		$this->assertSame($committee->getName(), Server::get(CommitteeMapper::class)->findByUuid($committee->getUuid())->getName());
	}

	public function testMeetingValidation(): void {
		$committee = $this->createCommittee('Validering');
		try {
			Server::get(MeetingService::class)->create($this->actor('secretary'), ['committeeId' => $committee->getUuid(), 'title' => '', 'startsAt' => 'ikke-en-dato']);
			$this->fail('Expected validation error');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('title', $e->getErrors());
			$this->assertArrayHasKey('startsAt', $e->getErrors());
		}
	}

	public function testServerValidatesStatusTransitions(): void {
		$meeting = $this->createMeeting($this->createCommittee('Status'), 5);
		$service = Server::get(MeetingService::class);
		$secretary = $this->actor('secretary');

		try {
			$service->changeStatus($secretary, $meeting->getUuid(), 'in_progress');
			$this->fail('planned -> in_progress must be rejected');
		} catch (InvalidTransitionException) {
		}
		try {
			$service->changeStatus($secretary, $meeting->getUuid(), 'agenda_published');
			$this->fail('agenda_published only via publish use case');
		} catch (InvalidTransitionException) {
		}
		$this->assertSame('agenda_preparation', $service->changeStatus($secretary, $meeting->getUuid(), 'agenda_preparation')->getStatus());
		$this->assertSame('planned', $service->changeStatus($secretary, $meeting->getUuid(), 'planned')->getStatus());
	}

	public function testOnlySecretariatManagesMeetings(): void {
		$committee = $this->createCommittee('Rettigheder');
		foreach (['caseworker', 'politician', 'nobody'] as $role) {
			try {
				Server::get(MeetingService::class)->create($this->actor($role), ['committeeId' => $committee->getUuid(), 'title' => 'X', 'startsAt' => '2030-01-01T10:00:00Z']);
				$this->fail($role . ' must not create meetings');
			} catch (ForbiddenException) {
				$this->addToAssertionCount(1);
			}
		}
		$this->expectException(ForbiddenException::class);
		Server::get(CommitteeService::class)->create($this->actor('caseworker'), ['name' => self::$prefix . ' hack', 'type' => 'standing_committee']);
	}

	public function testCommitteeWithMeetingsCannotBeDeleted(): void {
		$committee = $this->createCommittee('Slet');
		$this->createMeeting($committee, 3);
		$this->expectException(ConflictException::class);
		Server::get(CommitteeService::class)->delete($this->actor('secretary'), $committee->getUuid());
	}
}
