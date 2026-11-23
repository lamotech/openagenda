<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Dagsordenspunkt.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method int getMeetingId()
 * @method void setMeetingId(int $meetingId)
 * @method ?int getPoliticalCaseId()
 * @method void setPoliticalCaseId(?int $politicalCaseId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method ?string getSummary()
 * @method void setSummary(?string $summary)
 * @method ?string getPresentation()
 * @method void setPresentation(?string $presentation)
 * @method ?string getRecommendation()
 * @method void setRecommendation(?string $recommendation)
 * @method ?string getLegalBasis()
 * @method void setLegalBasis(?string $legalBasis)
 * @method ?string getFinancialImpact()
 * @method void setFinancialImpact(?string $financialImpact)
 * @method ?string getAttachments()
 * @method void setAttachments(?string $attachments)
 * @method bool getIsPublic()
 * @method void setIsPublic(bool $isPublic)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method ?string getRejectionReason()
 * @method void setRejectionReason(?string $rejectionReason)
 * @method ?int getForwardedFromItemId()
 * @method void setForwardedFromItemId(?int $forwardedFromItemId)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method ?string getUpdatedBy()
 * @method void setUpdatedBy(?string $updatedBy)
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
class AgendaItem extends Entity {
	protected $uuid = null;
	protected $meetingId = null;
	protected $politicalCaseId = null;
	protected $title = null;
	protected $summary = null;
	protected $presentation = null;
	protected $recommendation = null;
	protected $legalBasis = null;
	protected $financialImpact = null;
	protected $attachments = null;
	protected $isPublic = null;
	protected $sortOrder = null;
	protected $status = null;
	protected $rejectionReason = null;
	protected $forwardedFromItemId = null;
	protected $createdBy = null;
	protected $updatedBy = null;
	protected $createdAt = null;
	protected $updatedAt = null;

	public function __construct() {
		$this->addType('uuid', Types::STRING);
		$this->addType('meetingId', Types::BIGINT);
		$this->addType('politicalCaseId', Types::BIGINT);
		$this->addType('title', Types::STRING);
		$this->addType('summary', Types::TEXT);
		$this->addType('presentation', Types::TEXT);
		$this->addType('recommendation', Types::TEXT);
		$this->addType('legalBasis', Types::TEXT);
		$this->addType('financialImpact', Types::TEXT);
		$this->addType('attachments', Types::TEXT);
		$this->addType('isPublic', Types::BOOLEAN);
		$this->addType('sortOrder', Types::INTEGER);
		$this->addType('status', Types::STRING);
		$this->addType('rejectionReason', Types::TEXT);
		$this->addType('forwardedFromItemId', Types::BIGINT);
		$this->addType('createdBy', Types::STRING);
		$this->addType('updatedBy', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}

	/** @return array<array-key, mixed> */
	public function getAttachmentList(): array {
		$raw = $this->getAttachments();
		if ($raw === null || $raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @param list<array{documentId: string, title: string}> $attachments
	 */
	public function setAttachmentList(array $attachments): void {
		$this->setAttachments(json_encode(array_values($attachments), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
	}
}
