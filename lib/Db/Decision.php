<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Beslutning belonging to exactly one treatment.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method int getTreatmentId()
 * @method void setTreatmentId(int $treatmentId)
 * @method string getDecisionType()
 * @method void setDecisionType(string $decisionType)
 * @method ?string getDecisionText()
 * @method void setDecisionText(?string $decisionText)
 * @method string getDecidedBy()
 * @method void setDecidedBy(string $decidedBy)
 * @method int getDecidedAt()
 * @method void setDecidedAt(int $decidedAt)
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
class Decision extends Entity {
	protected $uuid = null;
	protected $treatmentId = null;
	protected $decisionType = null;
	protected $decisionText = null;
	protected $decidedBy = null;
	protected $decidedAt = null;
	protected $createdAt = null;
	protected $updatedAt = null;

	public function __construct() {
		$this->addType('uuid', Types::STRING);
		$this->addType('treatmentId', Types::BIGINT);
		$this->addType('decisionType', Types::STRING);
		$this->addType('decisionText', Types::TEXT);
		$this->addType('decidedBy', Types::STRING);
		$this->addType('decidedAt', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}
}
