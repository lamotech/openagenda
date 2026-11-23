<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service;

use OCA\OpenAgenda\Db\MeetingMapper;
use OCA\OpenAgenda\Db\PublicationMapper;
use OCA\OpenAgenda\Domain\Exception\NotFoundException;
use OCA\OpenAgenda\Domain\Uuid;

/**
 * Read side of the public portal. Only ever returns the stored, already
 * filtered publication content – never live data from the internal model.
 * This boundary allows moving the portal to a separate system later
 * (e.g. by exporting publications).
 */
class PublicReadService {
	public function __construct(
		private PublicationMapper $publications,
		private MeetingMapper $meetings,
	) {
	}

	/** @return list<array> summaries, newest meeting first */
	public function listMeetings(): array {
		$result = [];
		foreach ($this->publications->findAllActive() as $publication) {
			$content = $publication->getContentArray();
			$result[] = [
				'meeting' => $content['meeting'] ?? null,
				'committee' => $content['committee'] ?? null,
				'municipality' => $content['municipality'] ?? null,
				'hasDecisions' => (bool)($content['hasDecisions'] ?? false),
				'itemCount' => count($content['items'] ?? []),
				'publishedAt' => $publication->getPublishedAt(),
			];
		}
		usort($result, static fn ($a, $b) => ($b['meeting']['startsAt'] ?? 0) <=> ($a['meeting']['startsAt'] ?? 0));
		return $result;
	}

	public function getMeeting(string $meetingUuid): array {
		if (!Uuid::isValid($meetingUuid)) {
			throw new NotFoundException('Mødet er ikke offentliggjort.');
		}
		try {
			$meeting = $this->meetings->findByUuid($meetingUuid);
		} catch (NotFoundException) {
			throw new NotFoundException('Mødet er ikke offentliggjort.');
		}
		$publication = $this->publications->findActiveByMeeting($meeting->getId());
		if ($publication === null) {
			throw new NotFoundException('Mødet er ikke offentliggjort.');
		}
		$content = $publication->getContentArray();
		$content['publishedAt'] = $publication->getPublishedAt();
		return $content;
	}
}
