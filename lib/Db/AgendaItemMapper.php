<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends BaseMapper<AgendaItem>
 */
class AgendaItemMapper extends BaseMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'oa_agenda_items', AgendaItem::class);
	}

	protected function objectLabel(): string {
		return 'Dagsordenspunktet';
	}

	/** @return list<AgendaItem> ordered by agenda position */
	public function findByMeeting(int $meetingId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('meeting_id', $qb->createNamedParameter($meetingId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<AgendaItem> */
	public function findByPoliticalCase(int $politicalCaseId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('political_case_id', $qb->createNamedParameter($politicalCaseId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function maxSortOrder(int $meetingId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('sort_order'))->from($this->getTableName())
			->where($qb->expr()->eq('meeting_id', $qb->createNamedParameter($meetingId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();
		return $max === null || $max === false ? 0 : (int)$max;
	}

	/** @return list<AgendaItem> most recently updated first */
	public function findByStatus(string $status, int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
			->orderBy('updated_at', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** @return list<AgendaItem> most recently updated first */
	public function findByCreator(string $userId, int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('created_by', $qb->createNamedParameter($userId)))
			->orderBy('updated_at', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}
}
