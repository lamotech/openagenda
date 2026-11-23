<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain;

use OCA\OpenAgenda\Domain\Enum\DecisionType;
use OCA\OpenAgenda\Domain\Enum\MeetingStatus;
use OCA\OpenAgenda\Domain\Enum\TreatmentStatus;
use OCA\OpenAgenda\Domain\Exception\ConflictException;
use OCA\OpenAgenda\Domain\Exception\InvalidTransitionException;
use OCA\OpenAgenda\Domain\Exception\ValidationException;

/**
 * Rules for forwarding ("videreførelse") a treated agenda item to another committee.
 */
final class ForwardingRules {
	public function assertCanForward(
		TreatmentStatus $sourceTreatmentStatus,
		?DecisionType $sourceDecision,
		int $sourceCommitteeId,
		int $sourceMeetingStartsAt,
		int $targetCommitteeId,
		bool $targetCommitteeActive,
		MeetingStatus $targetMeetingStatus,
		int $targetMeetingStartsAt,
		bool $hasPoliticalCase,
	): void {
		if ($sourceTreatmentStatus === TreatmentStatus::Forwarded) {
			throw new ConflictException('Punktet er allerede videreført.');
		}
		if ($sourceTreatmentStatus !== TreatmentStatus::Decided || $sourceDecision === null) {
			throw new InvalidTransitionException('Punktet skal være behandlet, før det kan videreføres.');
		}
		if (!$sourceDecision->allowsForwarding()) {
			throw new InvalidTransitionException('Et udsat punkt kan ikke videreføres.');
		}
		if (!$hasPoliticalCase) {
			throw new ValidationException(['meetingId' => 'Kun punkter med en sagsreference kan videreføres.']);
		}
		if ($sourceCommitteeId === $targetCommitteeId) {
			throw new ValidationException(['meetingId' => 'Punktet skal videreføres til et andet udvalg.']);
		}
		if (!$targetCommitteeActive) {
			throw new ValidationException(['meetingId' => 'Det valgte udvalg er ikke aktivt.']);
		}
		if (!$targetMeetingStatus->isOpenForSubmissions()) {
			throw new ValidationException(['meetingId' => 'Det valgte møde modtager ikke flere punkter (dagsordenen er udsendt).']);
		}
		if ($targetMeetingStartsAt <= $sourceMeetingStartsAt) {
			throw new ValidationException(['meetingId' => 'Det valgte møde skal ligge efter det nuværende møde.']);
		}
	}
}
