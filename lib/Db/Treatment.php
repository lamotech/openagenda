<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Politisk behandling: one treatment of a political case on one agenda item at one meeting.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method ?int getPoliticalCaseId()
 * @method void setPoliticalCaseId(?int $politicalCaseId)
 * @method int getMeetingId()
 * @method void setMeetingId(int $meetingId)
 * @method int getAgendaItemId()
 * @method void setAgendaItemId(int $agendaItemId)
 * @method ?string getRecommendation()
 * @method void setRecommendation(?string $recommendation)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method ?int getTreatedAt()
 * @method void setTreatedAt(?int $treatedAt)
 * @method ?int getPreviousTreatmentId()
 * @method void setPreviousTreatmentId(?int $previousTreatmentId)
 * @method ?int getForwardedToItemId()
 * @method void setForwardedToItemId(?int $forwardedToItemId)
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
class Treatment extends Entity {
	protected $uuid = null;
	protected $politicalCaseId = null;
	protected $meetingId = null;
	protected $agendaItemId = null;
	protected $recommendation = null;
	protected $status = null;
	protected $treatedAt = null;
	protected $previousTreatmentId = null;
	protected $forwardedToItemId = null;
	protected $createdAt = null;
	protected $updatedAt = null;

	public function __construct() {
		$this->addType('uuid', Types::STRING);
		$this->addType('politicalCaseId', Types::BIGINT);
		$this->addType('meetingId', Types::BIGINT);
		$this->addType('agendaItemId', Types::BIGINT);
		$this->addType('recommendation', Types::TEXT);
		$this->addType('status', Types::STRING);
		$this->addType('treatedAt', Types::BIGINT);
		$this->addType('previousTreatmentId', Types::BIGINT);
		$this->addType('forwardedToItemId', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}
}
