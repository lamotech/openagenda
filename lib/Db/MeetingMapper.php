<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends BaseMapper<Meeting>
 */
class MeetingMapper extends BaseMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'oa_meetings', Meeting::class);
	}

	protected function objectLabel(): string {
		return 'Mødet';
	}

	/**
	 * @param list<string> $statuses
	 * @return list<Meeting>
	 */
	public function findFiltered(?int $committeeId = null, array $statuses = [], ?int $from = null, ?int $to = null, string $order = 'ASC', ?int $limit = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		if ($committeeId !== null) {
			$qb->andWhere($qb->expr()->eq('committee_id', $qb->createNamedParameter($committeeId, IQueryBuilder::PARAM_INT)));
		}
		if ($statuses !== []) {
			$qb->andWhere($qb->expr()->in('status', $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		if ($from !== null) {
			$qb->andWhere($qb->expr()->gte('starts_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)));
		}
		if ($to !== null) {
			$qb->andWhere($qb->expr()->lte('starts_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('starts_at', $order === 'DESC' ? 'DESC' : 'ASC');
		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		return $this->findEntities($qb);
	}

	public function countByCommittee(int $committeeId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from($this->getTableName())
			->where($qb->expr()->eq('committee_id', $qb->createNamedParameter($committeeId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	public function findByCommitteeAndTitle(int $committeeId, string $title): ?Meeting {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('committee_id', $qb->createNamedParameter($committeeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('title', $qb->createNamedParameter($title)));
		$qb->setMaxResults(1);
		$entities = $this->findEntities($qb);
		return $entities[0] ?? null;
	}
}
