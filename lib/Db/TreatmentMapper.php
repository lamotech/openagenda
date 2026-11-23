<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends BaseMapper<Treatment>
 */
class TreatmentMapper extends BaseMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'oa_treatments', Treatment::class);
	}

	protected function objectLabel(): string {
		return 'Behandlingen';
	}

	public function findByItem(int $agendaItemId): ?Treatment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('agenda_item_id', $qb->createNamedParameter($agendaItemId, IQueryBuilder::PARAM_INT)));
		return $this->findOneOrNull($qb);
	}

	/**
	 * @param list<int> $itemIds
	 * @return array<int, Treatment> keyed by agenda item id
	 */
	public function findByItems(array $itemIds): array {
		if ($itemIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('agenda_item_id', $qb->createNamedParameter($itemIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$result = [];
		foreach ($this->findEntities($qb) as $treatment) {
			$result[$treatment->getAgendaItemId()] = $treatment;
		}
		return $result;
	}

	/** @return list<Treatment> oldest first */
	public function findByPoliticalCase(int $politicalCaseId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('political_case_id', $qb->createNamedParameter($politicalCaseId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}
}
