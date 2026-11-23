<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Tests\Integration;

use OCA\OpenAgenda\Db\Committee;
use OCA\OpenAgenda\Db\Meeting;
use OCA\OpenAgenda\Domain\Actor;
use OCA\OpenAgenda\Domain\Role;
use OCA\OpenAgenda\Service\AuthorizationService;
use OCA\OpenAgenda\Service\CommitteeService;
use OCA\OpenAgenda\Service\MeetingService;
use OCA\OpenAgenda\Service\SettingsService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real Nextcloud database. Every test class creates its own
 * temporary users (random passwords), committees, meetings and mock OpenCase
 * cases with a unique prefix and removes them again afterwards.
 */
abstract class IntegrationTestCase extends TestCase {
	protected static string $prefix;
	/** @var array<string, string> role => uid */
	protected static array $users = [];
	/** @var array<string, string> uid => password */
	protected static array $passwords = [];
	/** @var list<string> */
	protected static array $mockCaseIds = [];

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$prefix = 'oatest' . bin2hex(random_bytes(3));
		self::$users = [];
		self::$passwords = [];
		self::$mockCaseIds = [];

		$userManager = Server::get(IUserManager::class);
		$groupManager = Server::get(IGroupManager::class);
		$settings = Server::get(SettingsService::class);
		$roles = [
			'secretary' => Role::Secretary,
			'caseworker' => Role::Caseworker,
			'caseworker2' => Role::Caseworker,
			'politician' => Role::Politician,
			'nobody' => null,
		];
		foreach ($roles as $key => $role) {
			$uid = self::$prefix . '_' . $key;
			$password = bin2hex(random_bytes(24)) . 'Aa1!';
			$user = $userManager->createUser($uid, $password);
			self::assertNotFalse($user, 'Could not create test user ' . $uid);
			if ($role !== null) {
				$gid = $settings->getGroupForRole($role);
				$group = $groupManager->get($gid) ?? $groupManager->createGroup($gid);
				$group->addUser($user);
			}
			self::$users[$key] = $uid;
			self::$passwords[$uid] = $password;
		}
		self::createMockCase('case1', 'Testsag lokalplan', [
			['doc-public', 'Offentligt testbilag', 'public'],
			['doc-internal', 'Internt testnotat', 'internal'],
			['doc-confidential', 'Fortroligt testdokument', 'confidential'],
		]);
		self::createMockCase('case2', 'Testsag ejendomskøb (lukket)', [
			['doc-secret', 'Fortrolig vurdering', 'confidential'],
		]);
	}

	public static function tearDownAfterClass(): void {
		self::cleanup();
		$userManager = Server::get(IUserManager::class);
		foreach (self::$users as $uid) {
			$userManager->get($uid)?->delete();
		}
		parent::tearDownAfterClass();
	}

	protected static function caseId(string $key): string {
		return self::$prefix . '-' . $key;
	}

	protected static function docId(string $key): string {
		return self::$prefix . '-' . $key;
	}

	/**
	 * @param list<array{0: string, 1: string, 2: string}> $documents key, title, access level
	 */
	private static function createMockCase(string $key, string $title, array $documents): void {
		$db = Server::get(IDBConnection::class);
		$caseId = self::caseId($key);
		$qb = $db->getQueryBuilder();
		$qb->insert('oa_mock_cases')->values([
			'external_id' => $qb->createNamedParameter($caseId),
			'case_number' => $qb->createNamedParameter(strtoupper(self::$prefix) . '-' . $key),
			'title' => $qb->createNamedParameter($title . ' (fiktiv test)'),
			'description' => $qb->createNamedParameter('Automatisk testdata – ' . self::$prefix),
			'status' => $qb->createNamedParameter('Under behandling'),
			'responsible_unit' => $qb->createNamedParameter('Test'),
			'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
		self::$mockCaseIds[] = $caseId;
		foreach ($documents as [$docKey, $docTitle, $access]) {
			$qb = $db->getQueryBuilder();
			$qb->insert('oa_mock_documents')->values([
				'external_id' => $qb->createNamedParameter(self::docId($docKey)),
				'case_external_id' => $qb->createNamedParameter($caseId),
				'title' => $qb->createNamedParameter($docTitle),
				'document_type' => $qb->createNamedParameter('Bilag'),
				'access_level' => $qb->createNamedParameter($access),
				'versions' => $qb->createNamedParameter(json_encode([
					['versionId' => self::docId($docKey) . '-v1', 'versionNumber' => 1, 'createdAt' => 1700000000, 'checksum' => 'a'],
					['versionId' => self::docId($docKey) . '-v2', 'versionNumber' => 2, 'createdAt' => 1700100000, 'checksum' => 'b'],
				])),
				'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
			])->executeStatement();
		}
	}

	protected function actor(string $key): Actor {
		return Server::get(AuthorizationService::class)->actorFor(self::$users[$key]);
	}

	protected function createCommittee(string $name, string $type = 'standing_committee'): Committee {
		return Server::get(CommitteeService::class)->create($this->actor('secretary'), [
			'name' => self::$prefix . ' ' . $name,
			'type' => $type,
			'description' => 'Testudvalg',
		]);
	}

	protected function createMeeting(Committee $committee, int $daysAhead, string $title = 'Testmøde'): Meeting {
		return Server::get(MeetingService::class)->create($this->actor('secretary'), [
			'committeeId' => $committee->getUuid(),
			'title' => $title,
			'startsAt' => (new \DateTimeImmutable('+' . $daysAhead . ' days'))->format(DATE_ATOM),
			'location' => 'Testlokale',
		]);
	}

	/** Caseworker creates and submits a complete item on the meeting. */
	protected function createSubmittedItem(Meeting $meeting, string $caseKey = 'case1', bool $isPublic = true, array $attachments = []): \OCA\OpenAgenda\Db\AgendaItem {
		$items = Server::get(\OCA\OpenAgenda\Service\AgendaItemService::class);
		$item = $items->create($this->actor('caseworker'), [
			'meetingId' => $meeting->getUuid(),
			'title' => 'Punkt ' . $caseKey,
			'opencaseCaseId' => self::caseId($caseKey),
			'summary' => 'Resumé',
			'presentation' => 'Sagsfremstilling for ' . $caseKey,
			'recommendation' => 'Forvaltningen indstiller, at forslaget godkendes.',
			'legalBasis' => 'Planloven',
			'financialImpact' => 'Ingen',
			'isPublic' => $isPublic,
			'attachments' => $attachments,
		]);
		return $items->submit($this->actor('caseworker'), $item->getUuid());
	}

	protected function startMeeting(Meeting $meeting): Meeting {
		return Server::get(MeetingService::class)->changeStatus($this->actor('secretary'), $meeting->getUuid(), 'in_progress');
	}

	protected function completeMeeting(Meeting $meeting): Meeting {
		return Server::get(MeetingService::class)->changeStatus($this->actor('secretary'), $meeting->getUuid(), 'completed');
	}

	/** Remove everything created by this test class (raw deletes, bypassing immutability guards). */
	private static function cleanup(): void {
		$db = Server::get(IDBConnection::class);
		$committeeIds = self::ids($db, 'oa_committees', 'name', self::$prefix . '%');
		$meetingIds = $committeeIds === [] ? [] : self::idsIn($db, 'oa_meetings', 'committee_id', $committeeIds);
		$itemIds = $meetingIds === [] ? [] : self::idsIn($db, 'oa_agenda_items', 'meeting_id', $meetingIds);
		$protocolIds = $meetingIds === [] ? [] : self::idsIn($db, 'oa_protocols', 'meeting_id', $meetingIds);
		$treatmentIds = $meetingIds === [] ? [] : self::idsIn($db, 'oa_treatments', 'meeting_id', $meetingIds);
		$politicalCaseIds = self::ids($db, 'oa_pol_cases', 'opencase_case_id', self::$prefix . '%');

		self::deleteIn($db, 'oa_publications', 'meeting_id', $meetingIds);
		self::deleteIn($db, 'oa_journalizations', 'protocol_id', $protocolIds);
		self::deleteIn($db, 'oa_protocols', 'id', $protocolIds);
		self::deleteIn($db, 'oa_snapshots', 'meeting_id', $meetingIds);
		self::deleteIn($db, 'oa_decisions', 'treatment_id', $treatmentIds);
		self::deleteIn($db, 'oa_treatments', 'id', $treatmentIds);
		self::deleteIn($db, 'oa_agenda_items', 'id', $itemIds);
		self::deleteIn($db, 'oa_pol_cases', 'id', $politicalCaseIds);
		self::deleteIn($db, 'oa_meetings', 'id', $meetingIds);
		self::deleteIn($db, 'oa_committees', 'id', $committeeIds);
		self::deleteLike($db, 'oa_audit_events', 'user_id', self::$prefix . '%');
		self::deleteLike($db, 'oa_mock_journal', 'case_external_id', self::$prefix . '%');
		self::deleteLike($db, 'oa_mock_documents', 'case_external_id', self::$prefix . '%');
		self::deleteLike($db, 'oa_mock_cases', 'external_id', self::$prefix . '%');
	}

	/** @return list<int> */
	private static function ids(IDBConnection $db, string $table, string $column, string $like): array {
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from($table)->where($qb->expr()->like($column, $qb->createNamedParameter($like)));
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	/** @return list<int> */
	private static function idsIn(IDBConnection $db, string $table, string $column, array $values): array {
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from($table)->where($qb->expr()->in($column, $qb->createNamedParameter($values, IQueryBuilder::PARAM_INT_ARRAY)));
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	private static function deleteIn(IDBConnection $db, string $table, string $column, array $values): void {
		if ($values === []) {
			return;
		}
		$qb = $db->getQueryBuilder();
		$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($values, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
	}

	private static function deleteLike(IDBConnection $db, string $table, string $column, string $like): void {
		$qb = $db->getQueryBuilder();
		$qb->delete($table)->where($qb->expr()->like($column, $qb->createNamedParameter($like)))->executeStatement();
	}
}
