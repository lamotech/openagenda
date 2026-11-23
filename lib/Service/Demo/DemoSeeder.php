<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service\Demo;

use OCA\OpenAgenda\Db\Committee;
use OCA\OpenAgenda\Db\CommitteeMapper;
use OCA\OpenAgenda\Db\Meeting;
use OCA\OpenAgenda\Db\MeetingMapper;
use OCA\OpenAgenda\Domain\Actor;
use OCA\OpenAgenda\Domain\Enum\MeetingStatus;
use OCA\OpenAgenda\Domain\Role;
use OCA\OpenAgenda\Domain\Uuid;
use OCA\OpenAgenda\Service\AuditService;
use OCA\OpenAgenda\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Idempotent demo seeder. Running it repeatedly never creates duplicates:
 * committees are matched by name, meetings by committee + title, mock cases
 * and documents by their external id. No users are created.
 */
class DemoSeeder {
	public const SYSTEM_USER = 'openagenda-demo-seeder';

	public function __construct(
		private IDBConnection $db,
		private CommitteeMapper $committees,
		private MeetingMapper $meetings,
		private SettingsService $settings,
		private AuditService $audit,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @param array<string, string> $userRoles uid => role value (existing users only)
	 * @param callable(string): void $log
	 */
	public function seed(array $userRoles, callable $log): void {
		$actor = new Actor(self::SYSTEM_USER, [Role::Admin]);
		$this->settings->setMunicipalityName(DemoFixtures::MUNICIPALITY);
		$log('Kommune: ' . DemoFixtures::MUNICIPALITY);

		foreach (Role::cases() as $role) {
			$gid = $this->settings->getGroupForRole($role);
			if (!$this->groupManager->groupExists($gid)) {
				$this->groupManager->createGroup($gid);
				$log("Gruppe oprettet: $gid ({$role->label()})");
			} else {
				$log("Gruppe findes: $gid ({$role->label()})");
			}
		}

		foreach ($userRoles as $uid => $roleValue) {
			$role = Role::tryFrom($roleValue);
			$user = $this->userManager->get($uid);
			if ($role === null || $user === null) {
				$log("Springer over: bruger '$uid' eller rolle '$roleValue' findes ikke.");
				continue;
			}
			$group = $this->groupManager->get($this->settings->getGroupForRole($role));
			if ($group !== null && !$group->inGroup($user)) {
				$group->addUser($user);
				$log("Bruger $uid tilføjet rollen {$role->label()}");
			}
		}

		$now = $this->timeFactory->getTime();
		$committeesByName = [];
		foreach (DemoFixtures::committees() as $data) {
			$committee = $this->committees->findByName($data['name']);
			if ($committee === null) {
				$committee = new Committee();
				$committee->setUuid(Uuid::v4());
				$committee->setName($data['name']);
				$committee->setShortName($data['shortName']);
				$committee->setDescription($data['description']);
				$committee->setType($data['type']);
				$committee->setActive(true);
				$committee->setCreatedAt($now);
				$committee->setUpdatedAt($now);
				$committee = $this->committees->insert($committee);
				$this->audit->record($actor, 'committee.created', 'committee', $committee->getUuid(), null, ['name' => $committee->getName(), 'source' => 'demo-seeder']);
				$log('Udvalg oprettet: ' . $data['name']);
			} else {
				$log('Udvalg findes: ' . $data['name']);
			}
			$committeesByName[$data['name']] = $committee;
		}

		$tz = new \DateTimeZone('Europe/Copenhagen');
		$today = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
		foreach (DemoFixtures::meetings() as $data) {
			$committee = $committeesByName[$data['committee']];
			if ($this->meetings->findByCommitteeAndTitle($committee->getId(), $data['title']) !== null) {
				$log("Møde findes: {$data['committee']} – {$data['title']}");
				continue;
			}
			$start = $today->modify('+' . $data['days'] . ' days')->setTime($data['hour'], $data['minute']);
			$meeting = new Meeting();
			$meeting->setUuid(Uuid::v4());
			$meeting->setCommitteeId($committee->getId());
			$meeting->setTitle($data['title']);
			$meeting->setStartsAt($start->getTimestamp());
			$meeting->setEndsAt($start->modify('+' . $data['durationHours'] . ' hours')->getTimestamp());
			$meeting->setLocation($data['location']);
			$meeting->setStatus(MeetingStatus::Planned->value);
			$meeting->setCreatedBy(self::SYSTEM_USER);
			$meeting->setCreatedAt($now);
			$meeting->setUpdatedAt($now);
			$meeting = $this->meetings->insert($meeting);
			$this->audit->record($actor, 'meeting.created', 'meeting', $meeting->getUuid(), $meeting->getUuid(), ['title' => $meeting->getTitle(), 'source' => 'demo-seeder']);
			$log("Møde oprettet: {$data['committee']} – {$data['title']} ({$start->format('d.m.Y H:i')})");
		}

		foreach (DemoFixtures::cases() as $case) {
			$inserted = $this->insertIfMissing('oa_mock_cases', [
				'external_id' => $case['externalId'],
				'case_number' => $case['caseNumber'],
				'title' => $case['title'],
				'description' => $case['description'],
				'status' => $case['status'],
				'responsible_unit' => $case['unit'],
				'created_at' => $now,
			]);
			$log(($inserted ? 'OpenCase-mock sag oprettet: ' : 'OpenCase-mock sag findes: ') . $case['caseNumber']);
			foreach ($case['documents'] as $doc) {
				$versions = [];
				for ($v = 1; $v <= $doc['versions']; $v++) {
					$versions[] = [
						'versionId' => $doc['externalId'] . '-v' . $v,
						'versionNumber' => $v,
						'createdAt' => $now - (($doc['versions'] - $v + 1) * 86400 * 7),
						'checksum' => hash('sha256', $doc['externalId'] . '-v' . $v),
					];
				}
				$this->insertIfMissing('oa_mock_documents', [
					'external_id' => $doc['externalId'],
					'case_external_id' => $case['externalId'],
					'title' => $doc['title'],
					'document_type' => $doc['type'],
					'access_level' => $doc['access'],
					'versions' => json_encode($versions, JSON_THROW_ON_ERROR),
					'created_at' => $now,
				]);
			}
		}
		$log('Demo-data er indlæst. Alle data er fiktive.');
	}

	/**
	 * Insert a row unless a row with the same unique key exists (unique index on external_id).
	 *
	 * @param array<string, scalar> $values
	 */
	private function insertIfMissing(string $table, array $values): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($table);
		foreach ($values as $column => $value) {
			$qb->setValue($column, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
		}
		try {
			$qb->executeStatement();
			return true;
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $e;
		}
	}

	/**
	 * Delete ALL OpenAgenda data (including the audit log and mock OpenCase data).
	 * Demo/test environments only – exposed via an explicit occ command with --force.
	 */
	public function purgeAll(callable $log): void {
		$tables = [
			'oa_publications', 'oa_journalizations', 'oa_protocols', 'oa_snapshots', 'oa_decisions',
			'oa_treatments', 'oa_agenda_items', 'oa_pol_cases', 'oa_meetings', 'oa_committees',
			'oa_audit_events', 'oa_mock_journal', 'oa_mock_documents', 'oa_mock_cases',
		];
		$this->db->beginTransaction();
		try {
			foreach ($tables as $table) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)->executeStatement();
				$log('Tømt: ' . $table);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
