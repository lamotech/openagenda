<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCA\OpenAgenda\Domain\Exception\ImmutableRecordException;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Snapshots are append-only: update and delete are blocked at repository level.
 *
 * @template-extends BaseMapper<AgendaSnapshot>
 */
class AgendaSnapshotMapper extends BaseMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'oa_snapshots', AgendaSnapshot::class);
	}

	protected function objectLabel(): string {
		return 'Dagsordensversionen';
	}

	public function update(Entity $entity): Entity {
		throw new ImmutableRecordException('En udsendt dagsorden kan ikke ændres. Udsend en ny version.');
	}

	public function insertOrUpdate(Entity $entity): Entity {
		if ($entity->getId() !== null) {
			throw new ImmutableRecordException('En udsendt dagsorden kan ikke ændres. Udsend en ny version.');
		}
		return $this->insert($entity);
	}

	public function delete(Entity $entity): Entity {
		throw new ImmutableRecordException('En udsendt dagsorden kan ikke slettes.');
	}

	/** @return list<AgendaSnapshot> newest first */
	public function findByMeeting(int $meetingId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('meeting_id', $qb->createNamedParameter($meetingId, IQueryBuilder::PARAM_INT)))
			->orderBy('version', 'DESC');
		return $this->findEntities($qb);
	}

	public function findLatest(int $meetingId): ?AgendaSnapshot {
		return $this->findByMeeting($meetingId)[0] ?? null;
	}
}
