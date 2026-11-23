<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends BaseMapper<Journalization>
 */
class JournalizationMapper extends BaseMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'oa_journalizations', Journalization::class);
	}

	protected function objectLabel(): string {
		return 'Journaliseringen';
	}

	/** @return list<Journalization> */
	public function findByProtocol(int $protocolId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('protocol_id', $qb->createNamedParameter($protocolId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByIdempotencyKey(string $key): ?Journalization {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('idempotency_key', $qb->createNamedParameter($key)));
		return $this->findOneOrNull($qb);
	}
}
