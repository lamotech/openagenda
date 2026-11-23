<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service;

use OCA\OpenAgenda\Db\AgendaItemMapper;
use OCA\OpenAgenda\Db\CommitteeMapper;
use OCA\OpenAgenda\Db\Meeting;
use OCA\OpenAgenda\Db\MeetingMapper;
use OCA\OpenAgenda\Db\TreatmentMapper;
use OCA\OpenAgenda\Db\DecisionMapper;
use OCA\OpenAgenda\Domain\Actor;
use OCA\OpenAgenda\Domain\Enum\AgendaItemStatus;
use OCA\OpenAgenda\Domain\Enum\MeetingStatus;
use OCA\OpenAgenda\Domain\Exception\ConflictException;
use OCA\OpenAgenda\Domain\Exception\InvalidTransitionException;
use OCA\OpenAgenda\Domain\Exception\ValidationException;
use OCA\OpenAgenda\Domain\Uuid;
use OCP\AppFramework\Utility\ITimeFactory;

class MeetingService {
	public function __construct(
		private MeetingMapper $meetings,
		private CommitteeMapper $committees,
		private AgendaItemMapper $items,
		private TreatmentMapper $treatments,
		private DecisionMapper $decisions,
		private AuthorizationService $auth,
		private AuditService $audit,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @param array{committeeId?: ?string, status?: ?string, from?: ?int, to?: ?int, order?: string, limit?: ?int} $filters
	 * @return list<Meeting>
	 */
	public function list(Actor $actor, array $filters = []): array {
		$this->auth->requireAppAccess($actor);
		$committeeId = null;
		if (!empty($filters['committeeId'])) {
			$committeeId = $this->committees->findByUuid((string)$filters['committeeId'])->getId();
		}
		$statuses = [];
		if (!empty($filters['status'])) {
			foreach (explode(',', (string)$filters['status']) as $s) {
				$status = MeetingStatus::tryFrom(trim($s));
				if ($status === null) {
					throw new ValidationException(['status' => 'Ukendt mødestatus.']);
				}
				$statuses[] = $status->value;
			}
		}
		return $this->meetings->findFiltered($committeeId, $statuses, $filters['from'] ?? null, $filters['to'] ?? null, $filters['order'] ?? 'ASC', $filters['limit'] ?? null);
	}

	public function get(Actor $actor, string $uuid): Meeting {
		$this->auth->requireAppAccess($actor);
		return $this->meetings->findByUuid($uuid);
	}

	public function create(Actor $actor, array $data): Meeting {
		$this->auth->require($this->auth->policy()->canManageMeetings($actor));
		$meeting = new Meeting();
		$meeting->setUuid(Uuid::v4());
		$meeting->setStatus(MeetingStatus::Planned->value);
		$meeting->setCreatedBy($actor->requireUserId());
		$this->apply($meeting, $data, true);
		$now = $this->timeFactory->getTime();
		$meeting->setCreatedAt($now);
		$meeting->setUpdatedAt($now);
		$meeting = $this->meetings->insert($meeting);
		$this->audit->record($actor, 'meeting.created', 'meeting', $meeting->getUuid(), $meeting->getUuid(), ['title' => $meeting->getTitle()]);
		return $meeting;
	}

	public function update(Actor $actor, string $uuid, array $data): Meeting {
		$this->auth->require($this->auth->policy()->canManageMeetings($actor));
		$meeting = $this->meetings->findByUuid($uuid);
		if (!MeetingStatus::from($meeting->getStatus())->isEditable()) {
			throw new ConflictException('Et afsluttet møde kan ikke redigeres.');
		}
		if (array_key_exists('committeeId', $data) && MeetingStatus::from($meeting->getStatus()) !== MeetingStatus::Planned) {
			$current = $this->committees->findById($meeting->getCommitteeId());
			if ($data['committeeId'] !== $current->getUuid()) {
				throw new ConflictException('Udvalget kan kun ændres, mens mødet er planlagt.');
			}
		}
		$this->apply($meeting, $data, false);
		$changed = array_keys($meeting->getUpdatedFields());
		$meeting->setUpdatedAt($this->timeFactory->getTime());
		$meeting = $this->meetings->update($meeting);
		$this->audit->record($actor, 'meeting.updated', 'meeting', $meeting->getUuid(), $meeting->getUuid(), ['fields' => $changed]);
		return $meeting;
	}

	public function delete(Actor $actor, string $uuid): void {
		$this->auth->require($this->auth->policy()->canManageMeetings($actor));
		$meeting = $this->meetings->findByUuid($uuid);
		if (MeetingStatus::from($meeting->getStatus()) !== MeetingStatus::Planned) {
			throw new ConflictException('Kun planlagte møder kan slettes.');
		}
		if ($this->items->findByMeeting($meeting->getId()) !== []) {
			throw new ConflictException('Mødet har dagsordenspunkter og kan ikke slettes.');
		}
		$this->meetings->delete($meeting);
		$this->audit->record($actor, 'meeting.deleted', 'meeting', $uuid, $uuid, ['title' => $meeting->getTitle()]);
	}

	/**
	 * Generic status change. Publishing the agenda is a separate use case
	 * (AgendaService::publish) because it must create a snapshot.
	 */
	public function changeStatus(Actor $actor, string $uuid, string $targetValue): Meeting {
		$this->auth->require($this->auth->policy()->canManageMeetings($actor));
		$meeting = $this->meetings->findByUuid($uuid);
		$current = MeetingStatus::from($meeting->getStatus());
		$target = MeetingStatus::tryFrom($targetValue);
		if ($target === null) {
			throw new ValidationException(['status' => 'Ukendt mødestatus.']);
		}
		if ($target === MeetingStatus::AgendaPublished) {
			throw new InvalidTransitionException('Brug "Godkend og udsend dagsorden" for at udsende dagsordenen.');
		}
		if (!$current->canTransitionManuallyTo($target)) {
			throw new InvalidTransitionException(sprintf('Mødet kan ikke skifte fra "%s" til "%s".', $current->label(), $target->label()));
		}
		if ($target === MeetingStatus::Completed) {
			$this->assertAllItemsTreated($meeting);
		}
		$meeting->setStatus($target->value);
		$meeting->setUpdatedAt($this->timeFactory->getTime());
		$meeting = $this->meetings->update($meeting);
		$this->audit->record($actor, 'meeting.status_changed', 'meeting', $meeting->getUuid(), $meeting->getUuid(), ['from' => $current->value, 'to' => $target->value]);
		return $meeting;
	}

	private function assertAllItemsTreated(Meeting $meeting): void {
		$published = array_values(array_filter(
			$this->items->findByMeeting($meeting->getId()),
			static fn ($i) => $i->getStatus() === AgendaItemStatus::Published->value,
		));
		$treatments = $this->treatments->findByItems(array_map(static fn ($i) => $i->getId(), $published));
		$decisions = $this->decisions->findByTreatments(array_map(static fn ($t) => $t->getId(), array_values($treatments)));
		$missing = [];
		foreach ($published as $index => $item) {
			$treatment = $treatments[$item->getId()] ?? null;
			if ($treatment === null || !isset($decisions[$treatment->getId()])) {
				$missing[] = $item->getTitle();
			}
		}
		if ($missing !== []) {
			throw new ConflictException('Mødet kan ikke afsluttes, før der er registreret beslutning på alle punkter. Mangler: ' . implode(', ', $missing));
		}
	}

	private function apply(Meeting $meeting, array $data, bool $isNew): void {
		$in = new Input($data);
		if ($isNew || $in->has('committeeId')) {
			$committeeUuid = $in->requiredString('committeeId', 'Udvalg', 36);
			if ($committeeUuid !== '') {
				try {
					$committee = $this->committees->findByUuid($committeeUuid);
					if (!$committee->getActive() && $committee->getId() !== $meeting->getCommitteeId()) {
						$in->addError('committeeId', 'Udvalget er ikke aktivt.');
					}
					$meeting->setCommitteeId($committee->getId());
				} catch (\OCA\OpenAgenda\Domain\Exception\NotFoundException) {
					$in->addError('committeeId', 'Udvalget findes ikke.');
				}
			}
		}
		if ($isNew || $in->has('title')) {
			$meeting->setTitle($in->requiredString('title', 'Titel'));
		}
		if ($isNew || $in->has('startsAt')) {
			$startsAt = $in->timestamp('startsAt', 'Mødetidspunkt');
			if ($startsAt !== null) {
				$meeting->setStartsAt($startsAt);
			}
		}
		if ($isNew || $in->has('endsAt')) {
			$meeting->setEndsAt($in->timestamp('endsAt', 'Sluttidspunkt', false));
		}
		if ($isNew || $in->has('location')) {
			$meeting->setLocation($in->optionalString('location', 'Sted', 255));
		}
		if ($meeting->getEndsAt() !== null && $meeting->getEndsAt() <= $meeting->getStartsAt()) {
			$in->addError('endsAt', 'Sluttidspunkt skal ligge efter starttidspunkt.');
		}
		$in->validate();
	}
}
