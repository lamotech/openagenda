<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Tests\Unit;

use OCA\OpenAgenda\Domain\AccessPolicy;
use OCA\OpenAgenda\Domain\Actor;
use OCA\OpenAgenda\Domain\Enum\AgendaItemStatus as S;
use OCA\OpenAgenda\Domain\Enum\MeetingStatus as M;
use OCA\OpenAgenda\Domain\Role;
use PHPUnit\Framework\TestCase;

final class AccessPolicyTest extends TestCase {
	private AccessPolicy $policy;
	private Actor $admin;
	private Actor $secretary;
	private Actor $caseworker;
	private Actor $otherCaseworker;
	private Actor $politician;
	private Actor $noRole;
	private Actor $anonymous;

	protected function setUp(): void {
		$this->policy = new AccessPolicy();
		$this->admin = new Actor('admin', [Role::Admin]);
		$this->secretary = new Actor('sec', [Role::Secretary]);
		$this->caseworker = new Actor('cw', [Role::Caseworker]);
		$this->otherCaseworker = new Actor('cw2', [Role::Caseworker]);
		$this->politician = new Actor('pol', [Role::Politician]);
		$this->noRole = new Actor('nobody', []);
		$this->anonymous = Actor::anonymous();
	}

	public function testAppAccessRequiresARole(): void {
		$this->assertTrue($this->policy->canUseApp($this->politician));
		$this->assertFalse($this->policy->canUseApp($this->noRole));
		$this->assertFalse($this->policy->canUseApp($this->anonymous));
	}

	public function testOnlySecretariatManagesMeetingsAndCommittees(): void {
		foreach ([$this->admin, $this->secretary] as $a) {
			$this->assertTrue($this->policy->canManageMeetings($a));
			$this->assertTrue($this->policy->canManageCommittees($a));
			$this->assertTrue($this->policy->canPublishAgenda($a));
			$this->assertTrue($this->policy->canRegisterDecision($a));
			$this->assertTrue($this->policy->canJournalize($a));
		}
		foreach ([$this->caseworker, $this->politician, $this->noRole, $this->anonymous] as $a) {
			$this->assertFalse($this->policy->canManageMeetings($a));
			$this->assertFalse($this->policy->canManageCommittees($a));
			$this->assertFalse($this->policy->canPublishAgenda($a));
			$this->assertFalse($this->policy->canRegisterDecision($a));
			$this->assertFalse($this->policy->canForward($a));
			$this->assertFalse($this->policy->canJournalize($a));
			$this->assertFalse($this->policy->canPublishPublicly($a));
			$this->assertFalse($this->policy->canReviewItem($a));
		}
	}

	public function testOnlyAdminConfigures(): void {
		$this->assertTrue($this->policy->canConfigure($this->admin));
		$this->assertFalse($this->policy->canConfigure($this->secretary));
	}

	public function testCaseworkerCreatesItemsOnlyOnOpenMeetings(): void {
		$this->assertTrue($this->policy->canCreateItem($this->caseworker, M::Planned));
		$this->assertTrue($this->policy->canCreateItem($this->caseworker, M::AgendaPreparation));
		$this->assertFalse($this->policy->canCreateItem($this->caseworker, M::AgendaPublished));
		$this->assertTrue($this->policy->canCreateItem($this->secretary, M::AgendaPublished));
		$this->assertFalse($this->policy->canCreateItem($this->secretary, M::InProgress));
		$this->assertFalse($this->policy->canCreateItem($this->politician, M::Planned));
	}

	public function testCaseworkerEditsOnlyOwnDrafts(): void {
		$this->assertTrue($this->policy->canEditItem($this->caseworker, 'cw', S::Draft, M::Planned));
		$this->assertFalse($this->policy->canEditItem($this->caseworker, 'cw', S::Submitted, M::Planned));
		$this->assertFalse($this->policy->canEditItem($this->otherCaseworker, 'cw', S::Draft, M::Planned));
		$this->assertFalse($this->policy->canEditItem($this->politician, 'pol', S::Draft, M::Planned));
		$this->assertTrue($this->policy->canEditItem($this->secretary, 'cw', S::Published, M::AgendaPublished));
		$this->assertFalse($this->policy->canEditItem($this->secretary, 'cw', S::Published, M::InProgress));
	}

	public function testSubmitRules(): void {
		$this->assertTrue($this->policy->canSubmitItem($this->caseworker, 'cw', S::Draft));
		$this->assertFalse($this->policy->canSubmitItem($this->otherCaseworker, 'cw', S::Draft));
		$this->assertFalse($this->policy->canSubmitItem($this->caseworker, 'cw', S::Submitted));
		$this->assertTrue($this->policy->canSubmitItem($this->secretary, 'cw', S::Draft));
	}

	public function testClosedItemsAreHiddenFromOtherCaseworkers(): void {
		$this->assertFalse($this->policy->canReadItem($this->otherCaseworker, 'cw', false, S::Published, M::AgendaPublished));
		$this->assertTrue($this->policy->canReadItem($this->otherCaseworker, 'cw', true, S::Published, M::AgendaPublished));
		$this->assertTrue($this->policy->canReadItem($this->caseworker, 'cw', false, S::Draft, M::Planned));
	}

	public function testPoliticiansReadOnlySentOutMaterial(): void {
		$this->assertTrue($this->policy->canReadItem($this->politician, 'cw', false, S::Published, M::AgendaPublished));
		$this->assertTrue($this->policy->canReadItem($this->politician, 'cw', true, S::Published, M::Completed));
		$this->assertFalse($this->policy->canReadItem($this->politician, 'cw', true, S::Approved, M::AgendaPreparation));
		$this->assertFalse($this->policy->canReadItem($this->politician, 'cw', true, S::Draft, M::Planned));
	}

	public function testAnonymousAndRolelessReadNothing(): void {
		foreach ([$this->anonymous, $this->noRole] as $a) {
			$this->assertFalse($this->policy->canReadItem($a, 'cw', true, S::Published, M::Completed));
			$this->assertFalse($this->policy->canReadProtocol($a));
			$this->assertFalse($this->policy->canReadAudit($a));
		}
	}

	public function testProtocolAccess(): void {
		$this->assertTrue($this->policy->canReadProtocol($this->politician));
		$this->assertFalse($this->policy->canReadProtocol($this->caseworker));
		$this->assertFalse($this->policy->canManageProtocol($this->politician));
	}

	public function testCapabilitiesAreInformationalCopyOfPolicy(): void {
		$caps = $this->policy->globalCapabilities($this->caseworker);
		$this->assertTrue($caps['searchOpenCase']);
		$this->assertFalse($caps['publishAgenda']);
	}
}
