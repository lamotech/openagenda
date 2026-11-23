<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCA\OpenAgenda\Domain\Enum\PublicationStatus;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends BaseMapper<Publication>
 */
class PublicationMapper extends BaseMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'oa_publications', Publication::class);
	}

	protected function objectLabel(): string {
		return 'Offentliggørelsen';
	}

	/** @return list<Publication> newest first */
	public function findByMeeting(int $meetingId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('meeting_id', $qb->createNamedParameter($meetingId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/** The current public release of a meeting, if any. */
	public function findActiveByMeeting(int $meetingId): ?Publication {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('meeting_id', $qb->createNamedParameter($meetingId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(PublicationStatus::Published->value)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$entities = $this->findEntities($qb);
		return $entities[0] ?? null;
	}

	/**
	 * Latest active publication per meeting.
	 *
	 * @return list<Publication>
	 */
	public function findAllActive(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(PublicationStatus::Published->value)))
			->orderBy('id', 'DESC');
		$latest = [];
		foreach ($this->findEntities($qb) as $publication) {
			$latest[$publication->getMeetingId()] ??= $publication;
		}
		return array_values($latest);
	}

	/** Withdraw all currently active publications for a meeting (used when re-publishing). */
	public function withdrawActive(int $meetingId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(PublicationStatus::Withdrawn->value))
			->set('withdrawn_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('meeting_id', $qb->createNamedParameter($meetingId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(PublicationStatus::Published->value)));
		$qb->executeStatement();
	}
}
