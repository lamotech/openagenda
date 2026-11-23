<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Tests\Integration;

use OCA\OpenAgenda\Db\AgendaItemMapper;
use OCA\OpenAgenda\Db\AuditEventMapper;
use OCA\OpenAgenda\Db\PoliticalCaseMapper;
use OCA\OpenAgenda\Domain\Exception\ForbiddenException;
use OCA\OpenAgenda\Domain\Exception\InvalidTransitionException;
use OCA\OpenAgenda\Domain\Exception\NotFoundException;
use OCA\OpenAgenda\Domain\Exception\ValidationException;
use OCA\OpenAgenda\Service\AgendaItemService;
use OCP\Server;

final class AgendaItemIntegrationTest extends IntegrationTestCase {
	private function service(): AgendaItemService {
		return Server::get(AgendaItemService::class);
	}

	public function testCaseworkerCreatesItemWithOpenCaseReference(): void {
		$meeting = $this->createMeeting($this->createCommittee('Punkter'), 7);
		$item = $this->service()->create($this->actor('caseworker'), [
			'meetingId' => $meeting->getUuid(),
			'title' => 'Lokalplan',
			'opencaseCaseId' => self::caseId('case1'),
			'presentation' => 'Sagsfremstilling',
			'recommendation' => 'Indstilling',
			'attachments' => [['documentId' => self::docId('doc-public')], ['documentId' => self::docId('doc-internal')]],
		]);

		$reloaded = Server::get(AgendaItemMapper::class)->findByUuid($item->getUuid());
		$this->assertSame('draft', $reloaded->getStatus());
		$this->assertSame(self::$users['caseworker'], $reloaded->getCreatedBy());
		$this->assertCount(2, $reloaded->getAttachmentList());
		$this->assertTrue($reloaded->getIsPublic());

		$case = Server::get(PoliticalCaseMapper::class)->findById($reloaded->getPoliticalCaseId());
		$this->assertSame(self::caseId('case1'), $case->getOpencaseCaseId());
		$this->assertSame('open', $case->getStatus());

		// A second item on the same OpenCase case reuses the open political case (no duplicate).
		$second = $this->service()->create($this->actor('caseworker'), ['meetingId' => $meeting->getUuid(), 'title' => 'Opfølgning', 'opencaseCaseId' => self::caseId('case1')]);
		$this->assertSame($reloaded->getPoliticalCaseId(), $second->getPoliticalCaseId());

		$events = Server::get(AuditEventMapper::class)->findFiltered('agenda_item', $item->getUuid(), null);
		$this->assertSame('agenda_item.created', $events[0]->getAction());
		$this->assertSame(self::$users['caseworker'], $events[0]->getUserId());
	}

	public function testUnknownCaseAndForeignDocumentsAreRejected(): void {
		$meeting = $this->createMeeting($this->createCommittee('Validering'), 7);
		try {
			$this->service()->create($this->actor('caseworker'), ['meetingId' => $meeting->getUuid(), 'title' => 'X', 'opencaseCaseId' => 'findes-ikke']);
			$this->fail('Unknown case must be rejected');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('opencaseCaseId', $e->getErrors());
		}
		try {
			$this->service()->create($this->actor('caseworker'), ['meetingId' => $meeting->getUuid(), 'title' => 'X', 'opencaseCaseId' => self::caseId('case1'), 'attachments' => [self::docId('doc-secret')]]);
			$this->fail('Document from another case must be rejected');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('attachments', $e->getErrors());
		}
	}

	public function testAdapterDecidesDocumentAccessWhenAttaching(): void {
		$meeting = $this->createMeeting($this->createCommittee('Dokumentadgang'), 7);
		try {
			$this->service()->create($this->actor('caseworker'), ['meetingId' => $meeting->getUuid(), 'title' => 'X', 'opencaseCaseId' => self::caseId('case1'), 'attachments' => [self::docId('doc-confidential')]]);
			$this->fail('Caseworker has no access to the confidential document in the mock policy');
		} catch (ValidationException $e) {
			$this->assertStringContainsString('adgang', $e->getErrors()['attachments']);
		}
		$item = $this->service()->create($this->actor('secretary'), ['meetingId' => $meeting->getUuid(), 'title' => 'X', 'opencaseCaseId' => self::caseId('case1'), 'attachments' => [self::docId('doc-confidential')]]);
		$this->assertCount(1, $item->getAttachmentList());
	}

	public function testApprovalWorkflow(): void {
		$meeting = $this->createMeeting($this->createCommittee('Godkendelse'), 7);
		$item = $this->service()->create($this->actor('caseworker'), ['meetingId' => $meeting->getUuid(), 'title' => 'Ufærdigt punkt']);

		try {
			$this->service()->submit($this->actor('caseworker'), $item->getUuid());
			$this->fail('Incomplete item must not be submitted');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('presentation', $e->getErrors());
		}
		$this->service()->update($this->actor('caseworker'), $item->getUuid(), ['presentation' => 'Tekst', 'recommendation' => 'Indstilling']);

		try {
			$this->service()->submit($this->actor('caseworker2'), $item->getUuid());
			$this->fail('Another caseworker must not submit the item');
		} catch (ForbiddenException) {
		}
		$submitted = $this->service()->submit($this->actor('caseworker'), $item->getUuid());
		$this->assertSame('submitted', $submitted->getStatus());

		try {
			$this->service()->update($this->actor('caseworker'), $item->getUuid(), ['title' => 'Ændret efter indsendelse']);
			$this->fail('Caseworker must not edit a submitted item');
		} catch (ForbiddenException) {
		}
		try {
			$this->service()->approve($this->actor('caseworker'), $item->getUuid());
			$this->fail('Caseworker must not approve');
		} catch (ForbiddenException) {
		}

		$rejected = $this->service()->reject($this->actor('secretary'), $item->getUuid(), 'Mangler økonomiafsnit');
		$this->assertSame('draft', $rejected->getStatus());
		$this->assertSame('Mangler økonomiafsnit', $rejected->getRejectionReason());

		$this->service()->submit($this->actor('caseworker'), $item->getUuid());
		$approved = $this->service()->approve($this->actor('secretary'), $item->getUuid());
		$this->assertSame('approved', Server::get(AgendaItemMapper::class)->findByUuid($approved->getUuid())->getStatus());

		$this->expectException(InvalidTransitionException::class);
		$this->service()->approve($this->actor('secretary'), $item->getUuid());
	}

	public function testItemsCanBeMovedUpAndDown(): void {
		$meeting = $this->createMeeting($this->createCommittee('Rækkefølge'), 7);
		$a = $this->service()->create($this->actor('caseworker'), ['meetingId' => $meeting->getUuid(), 'title' => 'A']);
		$b = $this->service()->create($this->actor('caseworker'), ['meetingId' => $meeting->getUuid(), 'title' => 'B']);
		$c = $this->service()->create($this->actor('caseworker'), ['meetingId' => $meeting->getUuid(), 'title' => 'C']);

		$order = array_map(static fn ($i) => $i->getTitle(), $this->service()->move($this->actor('secretary'), $c->getUuid(), 'up'));
		$this->assertSame(['A', 'C', 'B'], $order);
		$order = array_map(static fn ($i) => $i->getTitle(), $this->service()->move($this->actor('secretary'), $a->getUuid(), 'down'));
		$this->assertSame(['C', 'A', 'B'], $order);
		// Moving the first item up is a no-op.
		$order = array_map(static fn ($i) => $i->getTitle(), $this->service()->move($this->actor('secretary'), $c->getUuid(), 'up'));
		$this->assertSame(['C', 'A', 'B'], $order);

		$this->expectException(ForbiddenException::class);
		$this->service()->move($this->actor('caseworker'), $b->getUuid(), 'up');
	}

	public function testDraftsAreInvisibleToPoliticians(): void {
		$meeting = $this->createMeeting($this->createCommittee('Synlighed'), 7);
		$item = $this->service()->create($this->actor('caseworker'), ['meetingId' => $meeting->getUuid(), 'title' => 'Kladde']);
		$this->assertSame([], $this->service()->listForMeeting($this->actor('politician'), $meeting));
		$this->expectException(NotFoundException::class);
		$this->service()->get($this->actor('politician'), $item->getUuid());
	}
}
