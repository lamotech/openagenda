<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCA\OpenAgenda\Domain\Enum\PoliticalCaseStatus;
use OCP\IDBConnection;

/**
 * @template-extends BaseMapper<PoliticalCase>
 */
class PoliticalCaseMapper extends BaseMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'oa_pol_cases', PoliticalCase::class);
	}

	protected function objectLabel(): string {
		return 'Den politiske sag';
	}

	public function findOpenByOpenCaseId(string $opencaseCaseId): ?PoliticalCase {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('opencase_case_id', $qb->createNamedParameter($opencaseCaseId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(PoliticalCaseStatus::Open->value)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$entities = $this->findEntities($qb);
		return $entities[0] ?? null;
	}
}
