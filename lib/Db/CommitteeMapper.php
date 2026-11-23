<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCP\IDBConnection;

/**
 * @template-extends BaseMapper<Committee>
 */
class CommitteeMapper extends BaseMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'oa_committees', Committee::class);
	}

	protected function objectLabel(): string {
		return 'Udvalget';
	}

	/** @return list<Committee> */
	public function findAll(bool $includeInactive = true): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('name', 'ASC');
		if (!$includeInactive) {
			$qb->where($qb->expr()->eq('active', $qb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)));
		}
		return $this->findEntities($qb);
	}

	public function findByName(string $name): ?Committee {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('name', $qb->createNamedParameter($name)));
		return $this->findOneOrNull($qb);
	}
}
