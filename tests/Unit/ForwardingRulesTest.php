<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Tests\Unit;

use OCA\OpenAgenda\Domain\Enum\DecisionType;
use OCA\OpenAgenda\Domain\Enum\MeetingStatus;
use OCA\OpenAgenda\Domain\Enum\TreatmentStatus;
use OCA\OpenAgenda\Domain\Exception\ConflictException;
use OCA\OpenAgenda\Domain\Exception\InvalidTransitionException;
use OCA\OpenAgenda\Domain\Exception\ValidationException;
use OCA\OpenAgenda\Domain\ForwardingRules;
use PHPUnit\Framework\TestCase;

final class ForwardingRulesTest extends TestCase {
	private ForwardingRules $rules;

	protected function setUp(): void {
		$this->rules = new ForwardingRules();
	}

	private function check(array $overrides = []): void {
		$args = array_merge([
			'sourceTreatmentStatus' => TreatmentStatus::Decided,
			'sourceDecision' => DecisionType::Approved,
			'sourceCommitteeId' => 1,
			'sourceMeetingStartsAt' => 1000,
			'targetCommitteeId' => 2,
			'targetCommitteeActive' => true,
			'targetMeetingStatus' => MeetingStatus::Planned,
			'targetMeetingStartsAt' => 2000,
			'hasPoliticalCase' => true,
		], $overrides);
		$this->rules->assertCanForward(...$args);
	}

	public function testValidForwarding(): void {
		$this->check();
		$this->check(['targetMeetingStatus' => MeetingStatus::AgendaPreparation, 'sourceDecision' => DecisionType::Referred]);
		$this->addToAssertionCount(2);
	}

	public function testUntreatedItemCannotBeForwarded(): void {
		$this->expectException(InvalidTransitionException::class);
		$this->check(['sourceTreatmentStatus' => TreatmentStatus::Pending, 'sourceDecision' => null]);
	}

	public function testAlreadyForwardedIsConflict(): void {
		$this->expectException(ConflictException::class);
		$this->check(['sourceTreatmentStatus' => TreatmentStatus::Forwarded]);
	}

	public function testPostponedCannotBeForwarded(): void {
		$this->expectException(InvalidTransitionException::class);
		$this->check(['sourceDecision' => DecisionType::Postponed]);
	}

	public function testSameCommitteeIsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->check(['targetCommitteeId' => 1]);
	}

	public function testTargetMeetingMustBeLater(): void {
		$this->expectException(ValidationException::class);
		$this->check(['targetMeetingStartsAt' => 1000]);
	}

	public function testTargetAgendaMustBeOpen(): void {
		$this->expectException(ValidationException::class);
		$this->check(['targetMeetingStatus' => MeetingStatus::AgendaPublished]);
	}

	public function testRequiresCaseReference(): void {
		$this->expectException(ValidationException::class);
		$this->check(['hasPoliticalCase' => false]);
	}

	public function testInactiveCommitteeIsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->check(['targetCommitteeActive' => false]);
	}
}
