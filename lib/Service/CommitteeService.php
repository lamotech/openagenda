<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service;

use OCA\OpenAgenda\Db\Committee;
use OCA\OpenAgenda\Db\CommitteeMapper;
use OCA\OpenAgenda\Db\MeetingMapper;
use OCA\OpenAgenda\Domain\Actor;
use OCA\OpenAgenda\Domain\Enum\CommitteeType;
use OCA\OpenAgenda\Domain\Exception\ConflictException;
use OCA\OpenAgenda\Domain\Exception\ValidationException;
use OCA\OpenAgenda\Domain\Uuid;
use OCP\AppFramework\Utility\ITimeFactory;

class CommitteeService {
	public function __construct(
		private CommitteeMapper $committees,
		private MeetingMapper $meetings,
		private AuthorizationService $auth,
		private AuditService $audit,
		private ITimeFactory $timeFactory,
	) {
	}

	/** @return list<Committee> */
	public function list(Actor $actor, bool $includeInactive = true): array {
		$this->auth->requireAppAccess($actor);
		return $this->committees->findAll($includeInactive);
	}

	public function get(Actor $actor, string $uuid): Committee {
		$this->auth->requireAppAccess($actor);
		return $this->committees->findByUuid($uuid);
	}

	public function create(Actor $actor, array $data): Committee {
		$this->auth->require($this->auth->policy()->canManageCommittees($actor));
		$committee = new Committee();
		$committee->setUuid(Uuid::v4());
		$this->apply($committee, $data, true);
		$now = $this->timeFactory->getTime();
		$committee->setCreatedAt($now);
		$committee->setUpdatedAt($now);
		$committee = $this->committees->insert($committee);
		$this->audit->record($actor, 'committee.created', 'committee', $committee->getUuid(), null, ['name' => $committee->getName()]);
		return $committee;
	}

	public function update(Actor $actor, string $uuid, array $data): Committee {
		$this->auth->require($this->auth->policy()->canManageCommittees($actor));
		$committee = $this->committees->findByUuid($uuid);
		$this->apply($committee, $data, false);
		$changed = array_keys($committee->getUpdatedFields());
		$committee->setUpdatedAt($this->timeFactory->getTime());
		$committee = $this->committees->update($committee);
		$this->audit->record($actor, 'committee.updated', 'committee', $committee->getUuid(), null, ['fields' => $changed]);
		return $committee;
	}

	public function delete(Actor $actor, string $uuid): void {
		$this->auth->require($this->auth->policy()->canManageCommittees($actor));
		$committee = $this->committees->findByUuid($uuid);
		if ($this->meetings->countByCommittee($committee->getId()) > 0) {
			throw new ConflictException('Udvalget har møder og kan ikke slettes. Markér det som inaktivt i stedet.');
		}
		$this->committees->delete($committee);
		$this->audit->record($actor, 'committee.deleted', 'committee', $uuid, null, ['name' => $committee->getName()]);
	}

	private function apply(Committee $committee, array $data, bool $isNew): void {
		$in = new Input($data);
		if ($isNew || $in->has('name')) {
			$name = $in->requiredString('name', 'Navn');
			$existing = $name !== '' ? $this->committees->findByName($name) : null;
			if ($existing !== null && $existing->getId() !== $committee->getId()) {
				$in->addError('name', 'Der findes allerede et udvalg med dette navn.');
			}
			$committee->setName($name);
		}
		if ($isNew || $in->has('shortName')) {
			$committee->setShortName($in->optionalString('shortName', 'Forkortelse', 64));
		}
		if ($isNew || $in->has('description')) {
			$committee->setDescription($in->optionalString('description', 'Beskrivelse', 4000));
		}
		if ($isNew || $in->has('type')) {
			$type = $in->enum('type', CommitteeType::class, 'Udvalgstype');
			if ($type !== null) {
				$committee->setType($type->value);
			}
		}
		if ($isNew || $in->has('active')) {
			$committee->setActive($in->bool('active', true));
		}
		$in->validate();
	}
}
