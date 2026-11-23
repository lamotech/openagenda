<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Tests\Unit;

use OCA\OpenAgenda\Domain\Enum\AgendaItemStatus;
use OCA\OpenAgenda\Domain\Enum\MeetingStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusTransitionTest extends TestCase {
	public static function meetingTransitions(): array {
		return [
			['planned', 'agenda_preparation', true, true],
			['planned', 'agenda_published', true, false],
			['planned', 'in_progress', false, false],
			['agenda_preparation', 'planned', true, true],
			['agenda_preparation', 'agenda_published', true, false],
			['agenda_published', 'in_progress', true, true],
			['agenda_published', 'planned', false, false],
			['in_progress', 'completed', true, true],
			['in_progress', 'agenda_published', false, false],
			['completed', 'in_progress', false, false],
			['completed', 'planned', false, false],
		];
	}

	#[DataProvider('meetingTransitions')]
	public function testMeetingTransitions(string $from, string $to, bool $allowed, bool $manual): void {
		$this->assertSame($allowed, MeetingStatus::from($from)->canTransitionTo(MeetingStatus::from($to)));
		$this->assertSame($manual, MeetingStatus::from($from)->canTransitionManuallyTo(MeetingStatus::from($to)));
	}

	public function testAgendaPublishedIsNeverAManualTransition(): void {
		foreach (MeetingStatus::cases() as $status) {
			$this->assertFalse($status->canTransitionManuallyTo(MeetingStatus::AgendaPublished));
		}
	}

	public function testMeetingHelpers(): void {
		$this->assertTrue(MeetingStatus::Planned->isOpenForSubmissions());
		$this->assertTrue(MeetingStatus::AgendaPreparation->isOpenForSubmissions());
		$this->assertFalse(MeetingStatus::AgendaPublished->isOpenForSubmissions());
		$this->assertTrue(MeetingStatus::Completed->isAgendaPublished());
		$this->assertFalse(MeetingStatus::Completed->isEditable());
	}

	public static function itemTransitions(): array {
		return [
			['draft', 'submitted', true],
			['draft', 'approved', false],
			['draft', 'published', false],
			['submitted', 'approved', true],
			['submitted', 'draft', true],
			['submitted', 'published', false],
			['approved', 'published', true],
			['approved', 'draft', true],
			['published', 'draft', false],
			['published', 'approved', false],
		];
	}

	#[DataProvider('itemTransitions')]
	public function testItemTransitions(string $from, string $to, bool $allowed): void {
		$this->assertSame($allowed, AgendaItemStatus::from($from)->canTransitionTo(AgendaItemStatus::from($to)));
	}

	public function testEveryStatusHasDanishLabel(): void {
		foreach (MeetingStatus::cases() as $s) {
			$this->assertNotSame('', $s->label());
		}
		foreach (AgendaItemStatus::cases() as $s) {
			$this->assertNotSame('', $s->label());
		}
	}
}
