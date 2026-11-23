<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Tests\Integration;

use OCA\OpenAgenda\Domain\Exception\ForbiddenException;
use OCA\OpenAgenda\Domain\Exception\NotFoundException;
use OCA\OpenAgenda\OpenCase\OpenCaseAdapterInterface;
use OCA\OpenAgenda\Service\AgendaItemService;
use OCA\OpenAgenda\Service\AgendaService;
use OCA\OpenAgenda\Service\AuditService;
use OCA\OpenAgenda\Service\CommitteeService;
use OCA\OpenAgenda\Service\MeetingService;
use OCA\OpenAgenda\Service\ProtocolService;
use OCA\OpenAgenda\Service\PublicationService;
use OCA\OpenAgenda\Service\PublicReadService;
use OCA\OpenAgenda\Service\TreatmentService;
use OCP\Server;

final class SecurityIntegrationTest extends IntegrationTestCase {
	public function testClosedItemsAreNotReadableByUnauthorisedUsers(): void {
		$meeting = $this->createMeeting($this->createCommittee('Lukket'), 7);
		$closed = $this->createSubmittedItem($meeting, 'case2', false);
		$items = Server::get(AgendaItemService::class);

		foreach (['caseworker2', 'politician', 'nobody'] as $role) {
			try {
				$items->get($this->actor($role), $closed->getUuid());
				$this->fail($role . ' must not read the closed item before it is sent out');
			} catch (NotFoundException|ForbiddenException) {
				$this->addToAssertionCount(1);
			}
		}
		$this->assertSame($closed->getUuid(), $items->get($this->actor('caseworker'), $closed->getUuid())->getUuid());

		Server::get(AgendaService::class)->publish($this->actor('secretary'), $meeting->getUuid(), true);
		$meeting = Server::get(MeetingService::class)->get($this->actor('secretary'), $meeting->getUuid());
		// Authorised meeting material for politicians, still hidden from other caseworkers.
		$this->assertCount(1, $items->listForMeeting($this->actor('politician'), $meeting));
		$this->assertCount(0, $items->listForMeeting($this->actor('caseworker2'), $meeting));
		$this->expectException(NotFoundException::class);
		$items->get($this->actor('caseworker2'), $closed->getUuid());
	}

	public function testUnauthorisedWriteOperationsAreRejected(): void {
		$committee = $this->createCommittee('Skriv');
		$meeting = $this->createMeeting($committee, 7);
		$item = $this->createSubmittedItem($meeting);
		$calls = [
			'committee.create' => fn ($a) => Server::get(CommitteeService::class)->create($a, ['name' => self::$prefix . ' x', 'type' => 'standing_committee']),
			'committee.update' => fn ($a) => Server::get(CommitteeService::class)->update($a, $committee->getUuid(), ['name' => 'Hacked']),
			'meeting.update' => fn ($a) => Server::get(MeetingService::class)->update($a, $meeting->getUuid(), ['title' => 'Hacked']),
			'meeting.status' => fn ($a) => Server::get(MeetingService::class)->changeStatus($a, $meeting->getUuid(), 'agenda_preparation'),
			'item.approve' => fn ($a) => Server::get(AgendaItemService::class)->approve($a, $item->getUuid()),
			'item.reject' => fn ($a) => Server::get(AgendaItemService::class)->reject($a, $item->getUuid(), 'x'),
			'agenda.publish' => fn ($a) => Server::get(AgendaService::class)->publish($a, $meeting->getUuid(), true),
			'decision' => fn ($a) => Server::get(TreatmentService::class)->registerDecision($a, $item->getUuid(), ['decisionType' => 'approved']),
			'protocol.generate' => fn ($a) => Server::get(ProtocolService::class)->generate($a, $meeting->getUuid()),
			'publication' => fn ($a) => Server::get(PublicationService::class)->publish($a, $meeting->getUuid()),
		];
		foreach (['caseworker2', 'politician', 'nobody'] as $role) {
			foreach ($calls as $name => $call) {
				try {
					$call($this->actor($role));
					$this->fail("$role was allowed to perform $name");
				} catch (ForbiddenException|NotFoundException) {
					$this->addToAssertionCount(1);
				}
			}
		}
		$this->assertSame('submitted', Server::get(AgendaItemService::class)->get($this->actor('secretary'), $item->getUuid())->getStatus());
	}

	public function testDocumentAccessFollowsAdapterDecision(): void {
		$adapter = Server::get(OpenCaseAdapterInterface::class);
		$this->assertTrue($adapter->canAccessDocument(null, self::docId('doc-public')));
		$this->assertFalse($adapter->canAccessDocument(null, self::docId('doc-internal')));
		$this->assertTrue($adapter->canAccessDocument(self::$users['caseworker'], self::docId('doc-internal')));
		$this->assertFalse($adapter->canAccessDocument(self::$users['caseworker'], self::docId('doc-confidential')));
		$this->assertTrue($adapter->canAccessDocument(self::$users['secretary'], self::docId('doc-confidential')));
		$this->assertFalse($adapter->canAccessDocument(self::$users['secretary'], 'unknown-document'));

		$visible = array_map(static fn ($d) => $d->id, $adapter->listDocuments(self::caseId('case1'), self::$users['caseworker']));
		$this->assertContains(self::docId('doc-internal'), $visible);
		$this->assertNotContains(self::docId('doc-confidential'), $visible);
		$this->assertSame([self::docId('doc-public')], array_map(static fn ($d) => $d->id, $adapter->listDocuments(self::caseId('case1'), null)));
	}

	public function testPublicReadModelNeverContainsClosedData(): void {
		$meeting = $this->createMeeting($this->createCommittee('Offentlig', 'city_council'), 7);
		$public = $this->createSubmittedItem($meeting, 'case1', true, [self::docId('doc-public'), self::docId('doc-internal')]);
		$closed = Server::get(AgendaItemService::class)->create($this->actor('caseworker'), [
			'meetingId' => $meeting->getUuid(), 'title' => 'TOPHEMMELIG ejendomshandel', 'opencaseCaseId' => self::caseId('case2'),
			'presentation' => 'TOPHEMMELIG pris', 'recommendation' => 'TOPHEMMELIG indstilling', 'isPublic' => false,
		]);
		Server::get(AgendaItemService::class)->submit($this->actor('caseworker'), $closed->getUuid());
		$read = Server::get(PublicReadService::class);

		Server::get(AgendaService::class)->publish($this->actor('secretary'), $meeting->getUuid(), true);
		try {
			$read->getMeeting($meeting->getUuid());
			$this->fail('Not yet released publicly');
		} catch (NotFoundException) {
		}

		$this->startMeeting($meeting);
		Server::get(TreatmentService::class)->registerDecision($this->actor('secretary'), $public->getUuid(), ['decisionType' => 'approved', 'decisionText' => 'Offentlig beslutning']);
		Server::get(TreatmentService::class)->registerDecision($this->actor('secretary'), $closed->getUuid(), ['decisionType' => 'approved', 'decisionText' => 'TOPHEMMELIG beslutning']);
		$this->completeMeeting($meeting);
		$protocol = Server::get(ProtocolService::class)->generate($this->actor('secretary'), $meeting->getUuid());
		Server::get(ProtocolService::class)->approve($this->actor('secretary'), $protocol->getUuid());
		$publication = Server::get(PublicationService::class)->publish($this->actor('secretary'), $meeting->getUuid());

		$content = $read->getMeeting($meeting->getUuid());
		$json = json_encode($content, JSON_UNESCAPED_UNICODE);
		$this->assertStringNotContainsString('TOPHEMMELIG', $json);
		$this->assertStringNotContainsString('Internt testnotat', $json);
		$this->assertStringNotContainsString(self::caseId('case2'), $json);
		$this->assertStringContainsString('Offentlig beslutning', $json);
		$this->assertTrue($content['items'][1]['closed']);
		$this->assertSame(['Offentligt testbilag'], array_column($content['items'][0]['attachments'], 'title'));

		$listJson = json_encode($read->listMeetings(), JSON_UNESCAPED_UNICODE);
		$this->assertStringContainsString($meeting->getUuid(), $listJson);
		$this->assertStringNotContainsString('TOPHEMMELIG', $listJson);

		// Withdrawn publications disappear from the public API.
		Server::get(PublicationService::class)->withdraw($this->actor('secretary'), $publication->getUuid());
		$this->expectException(NotFoundException::class);
		$read->getMeeting($meeting->getUuid());
	}

	public function testAuditLogHasNoWriteApi(): void {
		$this->assertFalse(method_exists(AuditService::class, 'update'));
		$this->assertFalse(method_exists(AuditService::class, 'delete'));
		$controller = new \ReflectionClass(\OCA\OpenAgenda\Controller\AuditController::class);
		$public = array_filter($controller->getMethods(\ReflectionMethod::IS_PUBLIC), static fn ($m) => $m->getDeclaringClass()->getName() === $controller->getName() && $m->getName() !== '__construct');
		$this->assertSame(['index'], array_values(array_map(static fn ($m) => $m->getName(), $public)));
	}
}
