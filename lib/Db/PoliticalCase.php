<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Politisk sag: OpenAgenda's reference to an authoritative OpenCase case. Title/case number are cached display copies only.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string getOpencaseCaseId()
 * @method void setOpencaseCaseId(string $opencaseCaseId)
 * @method string getCaseNumber()
 * @method void setCaseNumber(string $caseNumber)
 * @method string getTitle()
 * @method void setTitle(string $title)
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
class PoliticalCase extends Entity {
	protected $uuid = null;
	protected $opencaseCaseId = null;
	protected $caseNumber = null;
	protected $title = null;
	protected $status = null;
	protected $createdBy = null;
	protected $createdAt = null;
	protected $updatedAt = null;

	public function __construct() {
		$this->addType('uuid', Types::STRING);
		$this->addType('opencaseCaseId', Types::STRING);
		$this->addType('caseNumber', Types::STRING);
		$this->addType('title', Types::STRING);
		$this->addType('status', Types::STRING);
		$this->addType('createdBy', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}
}
