<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Udvalg (committee).
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string getName()
 * @method void setName(string $name)
 * @method ?string getShortName()
 * @method void setShortName(?string $shortName)
 * @method ?string getDescription()
 * @method void setDescription(?string $description)
 * @method string getType()
 * @method void setType(string $type)
 * @method bool getActive()
 * @method void setActive(bool $active)
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
class Committee extends Entity {
	protected $uuid = null;
	protected $name = null;
	protected $shortName = null;
	protected $description = null;
	protected $type = null;
	protected $active = null;
	protected $createdAt = null;
	protected $updatedAt = null;

	public function __construct() {
		$this->addType('uuid', Types::STRING);
		$this->addType('name', Types::STRING);
		$this->addType('shortName', Types::STRING);
		$this->addType('description', Types::TEXT);
		$this->addType('type', Types::STRING);
		$this->addType('active', Types::BOOLEAN);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}
}
