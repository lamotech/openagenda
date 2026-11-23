<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCA\OpenAgenda\Domain\Enum\ProtocolStatus;
use OCA\OpenAgenda\Domain\Exception\ImmutableRecordException;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Approved protocols are immutable. The guard reads the persisted status, so an
 * approved row cannot be changed even if an entity object is manipulated.
 *
 * @template-extends BaseMapper<Protocol>
 */
class ProtocolMapper extends BaseMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'oa_protocols', Protocol::class);
	}

	protected function objectLabel(): string {
		return 'Protokollen';
	}

	public function update(Entity $entity): Entity {
		$this->assertNotApproved($entity);
		return parent::update($entity);
	}

	public function delete(Entity $entity): Entity {
		$this->assertNotApproved($entity);
		return parent::delete($entity);
	}

	private function assertNotApproved(Entity $entity): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('status')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($entity->getId(), IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$status = $result->fetchOne();
		$result->closeCursor();
		if ($status === ProtocolStatus::Approved->value) {
			throw new ImmutableRecordException('En godkendt protokol kan ikke ændres. Generér en ny version.');
		}
	}

	/** @return list<Protocol> newest first */
	public function findByMeeting(int $meetingId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('meeting_id', $qb->createNamedParameter($meetingId, IQueryBuilder::PARAM_INT)))
			->orderBy('version', 'DESC');
		return $this->findEntities($qb);
	}

	public function findLatest(int $meetingId): ?Protocol {
		return $this->findByMeeting($meetingId)[0] ?? null;
	}

	public function findLatestApproved(int $meetingId): ?Protocol {
		foreach ($this->findByMeeting($meetingId) as $protocol) {
			if ($protocol->getStatus() === ProtocolStatus::Approved->value) {
				return $protocol;
			}
		}
		return null;
	}
}
