<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Beslutningsprotokol. Immutable once approved.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method int getMeetingId()
 * @method void setMeetingId(int $meetingId)
 * @method int getVersion()
 * @method void setVersion(int $version)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getContent()
 * @method void setContent(string $content)
 * @method string getHtml()
 * @method void setHtml(string $html)
 * @method string getContentHash()
 * @method void setContentHash(string $contentHash)
 * @method string getGeneratedBy()
 * @method void setGeneratedBy(string $generatedBy)
 * @method int getGeneratedAt()
 * @method void setGeneratedAt(int $generatedAt)
 * @method ?string getApprovedBy()
 * @method void setApprovedBy(?string $approvedBy)
 * @method ?int getApprovedAt()
 * @method void setApprovedAt(?int $approvedAt)
 */
/*
 * Note: all properties default to null on purpose. OCP\AppFramework\Db\Entity only marks
 * a field as updated when the new value differs from the current one, so a non-null
 * default (e.g. false) would silently be dropped on insert.
 */
class Protocol extends Entity {
	protected $uuid = null;
	protected $meetingId = null;
	protected $version = null;
	protected $status = null;
	protected $content = null;
	protected $html = null;
	protected $contentHash = null;
	protected $generatedBy = null;
	protected $generatedAt = null;
	protected $approvedBy = null;
	protected $approvedAt = null;

	public function __construct() {
		$this->addType('uuid', Types::STRING);
		$this->addType('meetingId', Types::BIGINT);
		$this->addType('version', Types::INTEGER);
		$this->addType('status', Types::STRING);
		$this->addType('content', Types::TEXT);
		$this->addType('html', Types::TEXT);
		$this->addType('contentHash', Types::STRING);
		$this->addType('generatedBy', Types::STRING);
		$this->addType('generatedAt', Types::BIGINT);
		$this->addType('approvedBy', Types::STRING);
		$this->addType('approvedAt', Types::BIGINT);
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
