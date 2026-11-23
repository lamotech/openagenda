<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Immutable, versioned snapshot of a sent-out agenda.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method int getMeetingId()
 * @method void setMeetingId(int $meetingId)
 * @method int getVersion()
 * @method void setVersion(int $version)
 * @method string getContent()
 * @method void setContent(string $content)
 * @method string getContentHash()
 * @method void setContentHash(string $contentHash)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
/*
 * Note: all properties default to null on purpose. OCP\AppFramework\Db\Entity only marks
 * a field as updated when the new value differs from the current one, so a non-null
 * default (e.g. false) would silently be dropped on insert.
 */
class AgendaSnapshot extends Entity {
	protected $uuid = null;
	protected $meetingId = null;
	protected $version = null;
	protected $content = null;
	protected $contentHash = null;
	protected $createdBy = null;
	protected $createdAt = null;

	public function __construct() {
		$this->addType('uuid', Types::STRING);
		$this->addType('meetingId', Types::BIGINT);
		$this->addType('version', Types::INTEGER);
		$this->addType('content', Types::TEXT);
		$this->addType('contentHash', Types::STRING);
		$this->addType('createdBy', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
	}

	/** @return array<array-key, mixed> */
	public function getContentArray(): array {
		$raw = $this->getContent();
		if ($raw === null || $raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}
}
