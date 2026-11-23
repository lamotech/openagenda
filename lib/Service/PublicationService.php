<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service;

use OCA\OpenAgenda\Db\AgendaSnapshotMapper;
use OCA\OpenAgenda\Db\Meeting;
use OCA\OpenAgenda\Db\MeetingMapper;
use OCA\OpenAgenda\Db\ProtocolMapper;
use OCA\OpenAgenda\Db\Publication;
use OCA\OpenAgenda\Db\PublicationMapper;
use OCA\OpenAgenda\Domain\Actor;
use OCA\OpenAgenda\Domain\ContentHasher;
use OCA\OpenAgenda\Domain\Enum\PublicationStatus;
use OCA\OpenAgenda\Domain\Exception\ConflictException;
use OCA\OpenAgenda\Domain\Publication\PublicContentFactory;
use OCA\OpenAgenda\Domain\Uuid;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Public release ("offentliggørelse") of a meeting. Content is derived only
 * from the latest immutable agenda snapshot and the latest approved protocol,
 * filtered through the PublicContentFactory allow-list.
 */
class PublicationService {
	use TTransactional;

	public function __construct(
		private PublicationMapper $publications,
		private MeetingMapper $meetings,
		private AgendaSnapshotMapper $snapshots,
		private ProtocolMapper $protocols,
		private PublicContentFactory $factory,
		private AuthorizationService $auth,
		private AuditService $audit,
		private ITimeFactory $timeFactory,
		private IDBConnection $db,
	) {
	}

	public function publish(Actor $actor, string $meetingUuid): Publication {
		$this->auth->require($this->auth->policy()->canPublishPublicly($actor), 'Kun sekretariatet kan offentliggøre.');
		$userId = $actor->requireUserId();
		$meeting = $this->meetings->findByUuid($meetingUuid);
		$snapshot = $this->snapshots->findLatest($meeting->getId());
		if ($snapshot === null) {
			throw new ConflictException('Dagsordenen skal være udsendt, før den kan offentliggøres.');
		}
		$snapshotContent = $snapshot->getContentArray();
		if (!ContentHasher::verify($snapshotContent, $snapshot->getContentHash())) {
			throw new ConflictException('Dagsordensversionens integritet kunne ikke verificeres. Offentliggørelse afbrudt.');
		}
		$protocol = $this->protocols->findLatestApproved($meeting->getId());
		$protocolContent = $protocol?->getContentArray();
		if ($protocol !== null && !ContentHasher::verify($protocolContent, $protocol->getContentHash())) {
			throw new ConflictException('Protokollens integritet kunne ikke verificeres. Offentliggørelse afbrudt.');
		}
		$content = $this->factory->build($snapshotContent, $protocolContent, $snapshot->getVersion(), $protocol?->getVersion());

		return $this->atomic(function () use ($actor, $userId, $meeting, $snapshot, $protocol, $content): Publication {
			$now = $this->timeFactory->getTime();
			$this->publications->withdrawActive($meeting->getId(), $now);
			$publication = new Publication();
			$publication->setUuid(Uuid::v4());
			$publication->setMeetingId($meeting->getId());
			$publication->setSnapshotId($snapshot->getId());
			$publication->setProtocolId($protocol?->getId());
			$publication->setStatus(PublicationStatus::Published->value);
			$publication->setContent(json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
			$publication->setPublishedBy($userId);
			$publication->setPublishedAt($now);
			$publication = $this->publications->insert($publication);
			$this->audit->record($actor, 'publication.published', 'publication', $publication->getUuid(), $meeting->getUuid(), [
				'snapshotVersion' => $snapshot->getVersion(),
				'protocolVersion' => $protocol?->getVersion(),
			]);
			return $publication;
		}, $this->db);
	}

	public function withdraw(Actor $actor, string $publicationUuid): Publication {
		$this->auth->require($this->auth->policy()->canPublishPublicly($actor), 'Kun sekretariatet kan trække offentliggørelser tilbage.');
		$publication = $this->publications->findByUuid($publicationUuid);
		if ($publication->getStatus() !== PublicationStatus::Published->value) {
			throw new ConflictException('Offentliggørelsen er allerede trukket tilbage.');
		}
		$meeting = $this->meetings->findById($publication->getMeetingId());
		$publication->setStatus(PublicationStatus::Withdrawn->value);
		$publication->setWithdrawnAt($this->timeFactory->getTime());
		$publication = $this->publications->update($publication);
		$this->audit->record($actor, 'publication.withdrawn', 'publication', $publication->getUuid(), $meeting->getUuid());
		return $publication;
	}

	/** @return list<Publication> */
	public function listForMeeting(Actor $actor, Meeting $meeting): array {
		$this->auth->requireAppAccess($actor);
		return $this->publications->findByMeeting($meeting->getId());
	}
}
