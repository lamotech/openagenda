<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends BaseMapper<Decision>
 */
class DecisionMapper extends BaseMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'oa_decisions', Decision::class);
	}

	protected function objectLabel(): string {
		return 'Beslutningen';
	}

	public function findByTreatment(int $treatmentId): ?Decision {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('treatment_id', $qb->createNamedParameter($treatmentId, IQueryBuilder::PARAM_INT)));
		return $this->findOneOrNull($qb);
	}

	/**
	 * @param list<int> $treatmentIds
	 * @return array<int, Decision> keyed by treatment id
	 */
	public function findByTreatments(array $treatmentIds): array {
		if ($treatmentIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('treatment_id', $qb->createNamedParameter($treatmentIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$result = [];
		foreach ($this->findEntities($qb) as $decision) {
			$result[$decision->getTreatmentId()] = $decision;
		}
		return $result;
	}

	/** @return list<Decision> */
	public function findRecent(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('decided_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}
}
