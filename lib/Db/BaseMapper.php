<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCA\OpenAgenda\Domain\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * @template T of Entity
 * @template-extends QBMapper<T>
 */
abstract class BaseMapper extends QBMapper {
	/** Human readable (Danish) name used in "not found" messages. */
	abstract protected function objectLabel(): string;

	/**
	 * @return T
	 * @throws NotFoundException
	 */
	public function findById(int $id): Entity {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findOneOrFail($qb);
	}

	/**
	 * @return T
	 * @throws NotFoundException
	 */
	public function findByUuid(string $uuid): Entity {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
		return $this->findOneOrFail($qb);
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, T> keyed by id
	 */
	public function findByIds(array $ids): array {
		$ids = array_values(array_unique(array_filter($ids, static fn ($id) => $id !== null)));
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		$result = [];
		foreach ($this->findEntities($qb) as $entity) {
			$result[$entity->getId()] = $entity;
		}
		return $result;
	}

	/**
	 * @return T
	 * @throws NotFoundException
	 */
	protected function findOneOrFail(IQueryBuilder $qb): Entity {
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			throw new NotFoundException($this->objectLabel() . ' blev ikke fundet.');
		}
	}

	/**
	 * @return T|null
	 */
	protected function findOneOrNull(IQueryBuilder $qb): ?Entity {
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}
}
