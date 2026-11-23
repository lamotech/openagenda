<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service;

use OCA\OpenAgenda\Db\AuditEvent;
use OCA\OpenAgenda\Db\AuditEventMapper;
use OCA\OpenAgenda\Domain\Actor;
use OCA\OpenAgenda\Domain\Uuid;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Writes append-only audit events. Metadata must not contain document content
 * or personal data beyond user ids; callers pass field names and status changes.
 */
class AuditService {
	public function __construct(
		private AuditEventMapper $mapper,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @param array<string, scalar|null|array> $metadata
	 */
	public function record(Actor $actor, string $action, string $objectType, string $objectId, ?string $meetingUuid = null, array $metadata = []): AuditEvent {
		$event = new AuditEvent();
		$event->setUuid(Uuid::v4());
		$event->setOccurredAt($this->timeFactory->getTime());
		$event->setUserId($actor->userId);
		$event->setAction($action);
		$event->setObjectType($objectType);
		$event->setObjectId($objectId);
		$event->setMeetingUuid($meetingUuid);
		$event->setMetadata($metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		return $this->mapper->insert($event);
	}

	/** @return list<AuditEvent> */
	public function find(?string $objectType, ?string $objectId, ?string $meetingUuid, int $limit = 100): array {
		return $this->mapper->findFiltered($objectType, $objectId, $meetingUuid, $limit);
	}
}
