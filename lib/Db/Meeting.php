<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Møde.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method int getCommitteeId()
 * @method void setCommitteeId(int $committeeId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method int getStartsAt()
 * @method void setStartsAt(int $startsAt)
 * @method ?int getEndsAt()
 * @method void setEndsAt(?int $endsAt)
 * @method ?string getLocation()
 * @method void setLocation(?string $location)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
/*
 * Note: all properties default to null on purpose. OCP\AppFramework\Db\Entity only marks
 * a field as updated when the new value differs from the current one, so a non-null
 * default (e.g. false) would silently be dropped on insert.
 */
class Meeting extends Entity {
	protected $uuid = null;
	protected $committeeId = null;
	protected $title = null;
	protected $startsAt = null;
	protected $endsAt = null;
	protected $location = null;
	protected $status = null;
	protected $createdBy = null;
	protected $createdAt = null;
	protected $updatedAt = null;

	public function __construct() {
		$this->addType('uuid', Types::STRING);
		$this->addType('committeeId', Types::BIGINT);
		$this->addType('title', Types::STRING);
		$this->addType('startsAt', Types::BIGINT);
		$this->addType('endsAt', Types::BIGINT);
		$this->addType('location', Types::STRING);
		$this->addType('status', Types::STRING);
		$this->addType('createdBy', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}
}
