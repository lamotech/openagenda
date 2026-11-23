<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Tests\Integration;

use OCA\OpenAgenda\AppInfo\Application;
use OCA\OpenAgenda\OpenCase\Dto\JournalizationRequest;
use OCA\OpenAgenda\OpenCase\InProcess\InProcessOpenCaseAdapter;
use OCA\OpenAgenda\OpenCase\OpenCaseAdapterInterface;
use OCA\OpenAgenda\Service\AgendaItemService;
use OCA\OpenAgenda\Service\AgendaService;
use OCA\OpenAgenda\Service\AuthorizationService;
use OCA\OpenAgenda\Service\CommitteeService;
use OCA\OpenAgenda\Service\JournalizationService;
use OCA\OpenAgenda\Service\MeetingService;
use OCA\OpenAgenda\Service\ProtocolService;
use OCA\OpenAgenda\Service\TreatmentService;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Full OpenAgenda flow against the REAL OpenCase app: agenda item on a real
 * OpenCase case, sent-out agenda, decision, approved protocol, journalization
 * into OpenCase (twice, to verify idempotency).
 *
 * WRITES TO OPENCASE: creates one document with the protocol file on the given
 * case. Opt-in and must run on its own (it replaces the adapter in the app container):
 *
 *   sudo -u www-data env OA_OPENCASE_LIVE_USER=admin OA_OPENCASE_TEST_CASE=<case-uuid> \
 *     php vendor-bin/phpunit/vendor/bin/phpunit -c tests/phpunit.xml --filter OpenCaseInProcessJournalizationTest
 *
 * The user needs an OpenAgenda secretariat/admin role and write access to the case in OpenCase.
 * OpenAgenda rows created by the test are removed again; the OpenCase document is kept as evidence.
 */
final class OpenCaseInProcessJournalizationTest extends TestCase {
	private string $userId;
	private string $caseUuid;
	private string $prefix;

	protected function setUp(): void {
		$user = getenv('OA_OPENCASE_LIVE_USER');
		$case = getenv('OA_OPENCASE_TEST_CASE');
		if (!$user || !$case) {
			$this->markTestSkipped('Set OA_OPENCASE_LIVE_USER and OA_OPENCASE_TEST_CASE to journalize into the real OpenCase app');
		}
		if (!Server::get(IAppManager::class)->isEnabledForAnyone('opencase')) {
			$this->markTestSkipped('OpenCase app is not enabled');
		}
		$this->userId = $user;
		$this->caseUuid = $case;
		$this->prefix = 'oalive' . bin2hex(random_bytes(3));
		\OC::$server->getRegisteredAppContainer(Application::APP_ID)->registerService(
			OpenCaseAdapterInterface::class,
			static fn () => Server::get(InProcessOpenCaseAdapter::class),
		);
	}

	protected function tearDown(): void {
		if (isset($this->prefix)) {
			$this->cleanup();
		}
	}

	public function testJournalizesApprovedProtocolIntoRealOpenCaseIdempotently(): void {
		$this->assertInstanceOf(InProcessOpenCaseAdapter::class, Server::get(OpenCaseAdapterInterface::class));
		$actor = Server::get(AuthorizationService::class)->actorFor($this->userId);
		$this->assertTrue($actor->isSecretariat(), 'test user needs the OpenAgenda secretariat/admin role');

		$committee = Server::get(CommitteeService::class)->create($actor, ['name' => $this->prefix . ' Byråd (test)', 'type' => 'city_council']);
		$meeting = Server::get(MeetingService::class)->create($actor, [
			'committeeId' => $committee->getUuid(), 'title' => 'Live-test', 'startsAt' => gmdate('c', time() + 86400),
		]);

		// OpenCase case and its documents are read through the adapter with the user's rights.
		$items = Server::get(AgendaItemService::class);
		$item = $items->create($actor, [
			'meetingId' => $meeting->getUuid(),
			'title' => 'Live-test af journalisering',
			'opencaseCaseId' => $this->caseUuid,
			'presentation' => 'Testpunkt til journalisering i OpenCase.',
			'recommendation' => 'Det indstilles, at testen godkendes.',
		]);
		$items->submit($actor, $item->getUuid());
		Server::get(AgendaService::class)->publish($actor, $meeting->getUuid(), true);
		Server::get(MeetingService::class)->changeStatus($actor, $meeting->getUuid(), 'in_progress');
		Server::get(TreatmentService::class)->registerDecision($actor, $item->getUuid(), ['decisionType' => 'approved', 'decisionText' => 'Godkendt (test).']);
		Server::get(MeetingService::class)->changeStatus($actor, $meeting->getUuid(), 'completed');
		$protocol = Server::get(ProtocolService::class)->generate($actor, $meeting->getUuid());
		$protocol = Server::get(ProtocolService::class)->approve($actor, $protocol->getUuid());

		$service = Server::get(JournalizationService::class);
		$first = $service->journalize($actor, $protocol->getUuid());
		$this->assertSame(1, $first['created'], json_encode(array_map(static fn ($j) => $j->getErrorMessage(), $first['journalizations'])));
		$row = $first['journalizations'][0];
		$this->assertSame('succeeded', $row->getStatus());
		$this->assertSame($this->caseUuid, $row->getOpencaseCaseId());
		$reference = (string)$row->getExternalRef();

		// The document exists in OpenCase with exactly one file containing the protocol.
		$db = Server::get(IDBConnection::class);
		$docs = $this->openCaseDocuments($db, $reference);
		$this->assertCount(1, $docs, 'exactly one OpenCase document with reference ' . $reference);
		$this->assertStringContainsString('[OA-', $docs[0]['title']);
		$this->assertSame($this->userId, $docs[0]['created_by']);
		$files = $db->executeQuery('SELECT original_filename, mime_type FROM `*PREFIX*opencase_files` WHERE document_id = ?', [$docs[0]['id']])->fetchAll();
		$this->assertCount(1, $files);
		$this->assertSame('text/html', $files[0]['mime_type']);

		// Idempotent: OpenAgenda skips, and the adapter itself recognises the key.
		$second = $service->journalize($actor, $protocol->getUuid());
		$this->assertSame(0, $second['created']);
		$this->assertSame(1, $second['skipped']);
		$again = Server::get(InProcessOpenCaseAdapter::class)->journalize(new JournalizationRequest(
			$this->caseUuid, $row->getIdempotencyKey(), 'ignored', 'beslutningsprotokol', '<p>x</p>', $this->userId, ['protocolVersion' => 1],
		));
		$this->assertTrue($again->alreadyExisted);
		$this->assertSame($reference, $again->journalReference);
		$this->assertCount(1, $this->openCaseDocuments($db, $reference));
		$this->assertCount(1, $db->executeQuery('SELECT id FROM `*PREFIX*opencase_files` WHERE document_id = ?', [$docs[0]['id']])->fetchAll());

		fwrite(STDERR, "\nOpenCase document created: {$reference} ({$docs[0]['title']})\n");
	}

	private function openCaseDocuments(IDBConnection $db, string $documentNumber): array {
		return $db->executeQuery('SELECT id, title, created_by FROM `*PREFIX*opencase_documents` WHERE document_number = ?', [$documentNumber])->fetchAll();
	}

	/** Remove the OpenAgenda rows created by this test (raw deletes; OpenCase data is kept). */
	private function cleanup(): void {
		$db = Server::get(IDBConnection::class);
		$ids = static function (string $table, string $column, array $values) use ($db): array {
			if ($values === []) {
				return [];
			}
			$qb = $db->getQueryBuilder();
			$type = is_int($values[0]) ? IQueryBuilder::PARAM_INT_ARRAY : IQueryBuilder::PARAM_STR_ARRAY;
			$qb->select('id')->from($table)->where($qb->expr()->in($column, $qb->createNamedParameter($values, $type)));
			return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
		};
		$delete = static function (string $table, string $column, array $values) use ($db): void {
			if ($values === []) {
				return;
			}
			$qb = $db->getQueryBuilder();
			$type = is_int($values[0]) ? IQueryBuilder::PARAM_INT_ARRAY : IQueryBuilder::PARAM_STR_ARRAY;
			$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($values, $type)))->executeStatement();
		};
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from('oa_committees')->where($qb->expr()->like('name', $qb->createNamedParameter($this->prefix . '%')));
		$committees = array_map('intval', $qb->executeQuery()->fetchFirstColumn());
		$meetings = $ids('oa_meetings', 'committee_id', $committees);
		$meetingUuids = [];
		if ($meetings !== []) {
			$qb = $db->getQueryBuilder();
			$qb->select('uuid')->from('oa_meetings')->where($qb->expr()->in('id', $qb->createNamedParameter($meetings, IQueryBuilder::PARAM_INT_ARRAY)));
			$meetingUuids = $qb->executeQuery()->fetchFirstColumn();
		}
		$items = $ids('oa_agenda_items', 'meeting_id', $meetings);
		$qb = $db->getQueryBuilder();
		$polCases = [];
		if ($items !== []) {
			$qb->selectDistinct('political_case_id')->from('oa_agenda_items')->where($qb->expr()->in('id', $qb->createNamedParameter($items, IQueryBuilder::PARAM_INT_ARRAY)));
			$polCases = array_values(array_filter(array_map('intval', $qb->executeQuery()->fetchFirstColumn())));
		}
		$objectUuids = [];
		foreach (['oa_pol_cases' => $polCases, 'oa_agenda_items' => $items] as $table => $rowIds) {
			if ($rowIds !== []) {
				$qb = $db->getQueryBuilder();
				$qb->select('uuid')->from($table)->where($qb->expr()->in('id', $qb->createNamedParameter($rowIds, IQueryBuilder::PARAM_INT_ARRAY)));
				$objectUuids = array_merge($objectUuids, $qb->executeQuery()->fetchFirstColumn());
			}
		}
		$protocols = $ids('oa_protocols', 'meeting_id', $meetings);
		$treatments = $ids('oa_treatments', 'meeting_id', $meetings);
		$delete('oa_publications', 'meeting_id', $meetings);
		$delete('oa_journalizations', 'protocol_id', $protocols);
		$delete('oa_protocols', 'id', $protocols);
		$delete('oa_snapshots', 'meeting_id', $meetings);
		$delete('oa_decisions', 'treatment_id', $treatments);
		$delete('oa_treatments', 'id', $treatments);
		$delete('oa_agenda_items', 'id', $items);
		$delete('oa_pol_cases', 'id', $polCases);
		$delete('oa_meetings', 'id', $meetings);
		$delete('oa_committees', 'id', $committees);
		$delete('oa_audit_events', 'meeting_uuid', $meetingUuids);
		$delete('oa_audit_events', 'object_id', $objectUuids);
		$qb = $db->getQueryBuilder();
		$qb->delete('oa_audit_events')->where($qb->expr()->like('metadata', $qb->createNamedParameter('%' . $this->prefix . '%')))->executeStatement();
		unset($this->prefix);
	}
}
