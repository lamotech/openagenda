<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Tests\Integration;

use OCA\OpenAgenda\Db\AgendaSnapshotMapper;
use OCA\OpenAgenda\Db\MeetingMapper;
use OCA\OpenAgenda\Db\TreatmentMapper;
use OCA\OpenAgenda\Domain\ContentHasher;
use OCA\OpenAgenda\Domain\Exception\ForbiddenException;
use OCA\OpenAgenda\Domain\Exception\ImmutableRecordException;
use OCA\OpenAgenda\Domain\Exception\ValidationException;
use OCA\OpenAgenda\Service\AgendaItemService;
use OCA\OpenAgenda\Service\AgendaService;
use OCP\Server;

final class AgendaPublicationIntegrationTest extends IntegrationTestCase {
	public function testPublishCreatesImmutableSnapshotWithDocumentVersions(): void {
		$meeting = $this->createMeeting($this->createCommittee('Udsendelse'), 7);
		$item = $this->createSubmittedItem($meeting, 'case1', true, [self::docId('doc-public'), self::docId('doc-internal')]);
		$draft = Server::get(AgendaItemService::class)->create($this->actor('caseworker'), ['meetingId' => $meeting->getUuid(), 'title' => 'Ikke færdig']);

		$agenda = Server::get(AgendaService::class);
		try {
			$agenda->publish($this->actor('caseworker'), $meeting->getUuid(), true);
			$this->fail('Caseworker must not publish');
		} catch (ForbiddenException) {
		}
		try {
			$agenda->publish($this->actor('secretary'), $meeting->getUuid(), false);
			$this->fail('No approved items yet');
		} catch (ValidationException) {
		}

		$snapshot = $agenda->publish($this->actor('secretary'), $meeting->getUuid(), true);
		$this->assertSame(1, $snapshot->getVersion());
		$this->assertSame('agenda_published', Server::get(MeetingMapper::class)->findByUuid($meeting->getUuid())->getStatus());

		$content = $snapshot->getContentArray();
		$this->assertCount(1, $content['items'], 'Draft items are not on the sent-out agenda');
		$this->assertSame($item->getUuid(), $content['items'][0]['uuid']);
		$this->assertSame(1, $content['items'][0]['number']);
		$this->assertSame(self::caseId('case1'), $content['items'][0]['caseReference']['opencaseCaseId']);
		$this->assertSame(self::docId('doc-public') . '-v2', $content['items'][0]['attachments'][0]['versionId']);
		$this->assertSame(2, $content['items'][0]['attachments'][0]['versionNumber']);
		$this->assertSame('public', $content['items'][0]['attachments'][0]['accessLevel']);
		$this->assertSame('internal', $content['items'][0]['attachments'][1]['accessLevel']);
		$this->assertTrue(ContentHasher::verify($content, $snapshot->getContentHash()));

		// Treatment created for the published item.
		$this->assertNotNull(Server::get(TreatmentMapper::class)->findByItem($item->getId()));

		// Snapshot cannot be modified or deleted.
		$mapper = Server::get(AgendaSnapshotMapper::class);
		$stored = $mapper->findByUuid($snapshot->getUuid());
		$stored->setContent('{}');
		try {
			$mapper->update($stored);
			$this->fail('Snapshot update must be blocked');
		} catch (ImmutableRecordException) {
		}
		try {
			$mapper->delete($stored);
			$this->fail('Snapshot delete must be blocked');
		} catch (ImmutableRecordException) {
		}

		// A correction after sending out changes the item but not the stored snapshot ...
		Server::get(AgendaItemService::class)->update($this->actor('secretary'), $item->getUuid(), ['recommendation' => 'Rettet indstilling']);
		$v1 = $agenda->getSnapshot($this->actor('secretary'), $snapshot->getUuid());
		$this->assertTrue($v1['verified']);
		$this->assertSame('Forvaltningen indstiller, at forslaget godkendes.', $v1['content']['items'][0]['recommendation']);

		// ... and is only sent out as a new version.
		$v2 = $agenda->publish($this->actor('secretary'), $meeting->getUuid(), false);
		$this->assertSame(2, $v2->getVersion());
		$this->assertSame('Rettet indstilling', $v2->getContentArray()['items'][0]['recommendation']);
		$this->assertCount(2, $mapper->findByMeeting($meeting->getId()));
		$this->assertSame($snapshot->getContentHash(), $mapper->findByUuid($snapshot->getUuid())->getContentHash());
		$this->assertNotNull($draft);
	}

	public function testCaseworkerSeesClosedItemsOfOthersOnlyAsPlaceholder(): void {
		$meeting = $this->createMeeting($this->createCommittee('Snapshotadgang'), 7);
		$this->createSubmittedItem($meeting, 'case2', false);
		$snapshot = Server::get(AgendaService::class)->publish($this->actor('secretary'), $meeting->getUuid(), true);

		$asOther = Server::get(AgendaService::class)->getSnapshot($this->actor('caseworker2'), $snapshot->getUuid());
		$this->assertTrue($asOther['content']['items'][0]['restricted']);
		$this->assertArrayNotHasKey('presentation', $asOther['content']['items'][0]);

		$asPolitician = Server::get(AgendaService::class)->getSnapshot($this->actor('politician'), $snapshot->getUuid());
		$this->assertArrayHasKey('presentation', $asPolitician['content']['items'][0]);

		$this->expectException(ForbiddenException::class);
		Server::get(AgendaService::class)->getSnapshot($this->actor('nobody'), $snapshot->getUuid());
	}
}
