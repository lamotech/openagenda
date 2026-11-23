<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Journalisering of an approved protocol on one OpenCase case (unique per protocol and case).
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method int getProtocolId()
 * @method void setProtocolId(int $protocolId)
 * @method string getOpencaseCaseId()
 * @method void setOpencaseCaseId(string $opencaseCaseId)
 * @method string getIdempotencyKey()
 * @method void setIdempotencyKey(string $idempotencyKey)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method ?string getExternalRef()
 * @method void setExternalRef(?string $externalRef)
 * @method ?string getErrorMessage()
 * @method void setErrorMessage(?string $errorMessage)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method ?string getPayloadHash()
 * @method void setPayloadHash(?string $payloadHash)
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
class Journalization extends Entity {
	protected $uuid = null;
	protected $protocolId = null;
	protected $opencaseCaseId = null;
	protected $idempotencyKey = null;
	protected $status = null;
	protected $externalRef = null;
	protected $errorMessage = null;
	protected $attempts = null;
	protected $payloadHash = null;
	protected $createdBy = null;
	protected $createdAt = null;
	protected $updatedAt = null;

	public function __construct() {
		$this->addType('uuid', Types::STRING);
		$this->addType('protocolId', Types::BIGINT);
		$this->addType('opencaseCaseId', Types::STRING);
		$this->addType('idempotencyKey', Types::STRING);
		$this->addType('status', Types::STRING);
		$this->addType('externalRef', Types::STRING);
		$this->addType('errorMessage', Types::TEXT);
		$this->addType('attempts', Types::INTEGER);
		$this->addType('payloadHash', Types::STRING);
		$this->addType('createdBy', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}
}
