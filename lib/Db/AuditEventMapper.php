<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCA\OpenAgenda\Domain\Exception\ImmutableRecordException;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Append-only audit log.
 *
 * @template-extends BaseMapper<AuditEvent>
 */
class AuditEventMapper extends BaseMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'oa_audit_events', AuditEvent::class);
	}

	protected function objectLabel(): string {
		return 'Audit-hændelsen';
	}

	public function update(Entity $entity): Entity {
		throw new ImmutableRecordException('Auditloggen kan ikke ændres.');
	}

	public function insertOrUpdate(Entity $entity): Entity {
		if ($entity->getId() !== null) {
			throw new ImmutableRecordException('Auditloggen kan ikke ændres.');
		}
		return $this->insert($entity);
	}

	public function delete(Entity $entity): Entity {
		throw new ImmutableRecordException('Auditloggen kan ikke ændres.');
	}

	/** @return list<AuditEvent> newest first */
	public function findFiltered(?string $objectType, ?string $objectId, ?string $meetingUuid, int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		if ($objectType !== null) {
			$qb->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter($objectType)));
		}
		if ($objectId !== null) {
			$qb->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($objectId)));
		}
		if ($meetingUuid !== null) {
			$qb->andWhere($qb->expr()->eq('meeting_uuid', $qb->createNamedParameter($meetingUuid)));
		}
		$qb->orderBy('occurred_at', 'DESC')->addOrderBy('id', 'DESC')->setMaxResults(max(1, min($limit, 500)));
		return $this->findEntities($qb);
	}

	public function countByActionAndObject(string $action, string $objectId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from($this->getTableName())
			->where($qb->expr()->eq('action', $qb->createNamedParameter($action)))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($objectId)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}
}
