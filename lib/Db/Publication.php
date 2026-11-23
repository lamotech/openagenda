<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Public release of a meeting, built from an immutable snapshot and (optionally) an approved protocol.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method int getMeetingId()
 * @method void setMeetingId(int $meetingId)
 * @method int getSnapshotId()
 * @method void setSnapshotId(int $snapshotId)
 * @method ?int getProtocolId()
 * @method void setProtocolId(?int $protocolId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getContent()
 * @method void setContent(string $content)
 * @method string getPublishedBy()
 * @method void setPublishedBy(string $publishedBy)
 * @method int getPublishedAt()
 * @method void setPublishedAt(int $publishedAt)
 * @method ?int getWithdrawnAt()
 * @method void setWithdrawnAt(?int $withdrawnAt)
 */
/*
 * Note: all properties default to null on purpose. OCP\AppFramework\Db\Entity only marks
 * a field as updated when the new value differs from the current one, so a non-null
 * default (e.g. false) would silently be dropped on insert.
 */
class Publication extends Entity {
	protected $uuid = null;
	protected $meetingId = null;
	protected $snapshotId = null;
	protected $protocolId = null;
	protected $status = null;
	protected $content = null;
	protected $publishedBy = null;
	protected $publishedAt = null;
	protected $withdrawnAt = null;

	public function __construct() {
		$this->addType('uuid', Types::STRING);
		$this->addType('meetingId', Types::BIGINT);
		$this->addType('snapshotId', Types::BIGINT);
		$this->addType('protocolId', Types::BIGINT);
		$this->addType('status', Types::STRING);
		$this->addType('content', Types::TEXT);
		$this->addType('publishedBy', Types::STRING);
		$this->addType('publishedAt', Types::BIGINT);
		$this->addType('withdrawnAt', Types::BIGINT);
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
