<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Append-only audit log entry.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method int getOccurredAt()
 * @method void setOccurredAt(int $occurredAt)
 * @method ?string getUserId()
 * @method void setUserId(?string $userId)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method string getObjectType()
 * @method void setObjectType(string $objectType)
 * @method string getObjectId()
 * @method void setObjectId(string $objectId)
 * @method ?string getMeetingUuid()
 * @method void setMeetingUuid(?string $meetingUuid)
 * @method ?string getMetadata()
 * @method void setMetadata(?string $metadata)
 */
/*
 * Note: all properties default to null on purpose. OCP\AppFramework\Db\Entity only marks
 * a field as updated when the new value differs from the current one, so a non-null
 * default (e.g. false) would silently be dropped on insert.
 */
class AuditEvent extends Entity {
	protected $uuid = null;
	protected $occurredAt = null;
	protected $userId = null;
	protected $action = null;
	protected $objectType = null;
	protected $objectId = null;
	protected $meetingUuid = null;
	protected $metadata = null;

	public function __construct() {
		$this->addType('uuid', Types::STRING);
		$this->addType('occurredAt', Types::BIGINT);
		$this->addType('userId', Types::STRING);
		$this->addType('action', Types::STRING);
		$this->addType('objectType', Types::STRING);
		$this->addType('objectId', Types::STRING);
		$this->addType('meetingUuid', Types::STRING);
		$this->addType('metadata', Types::TEXT);
	}

	/** @return array<array-key, mixed> */
	public function getMetadataArray(): array {
		$raw = $this->getMetadata();
		if ($raw === null || $raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}
}
