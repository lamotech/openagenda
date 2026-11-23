<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Tests\Unit;

use OCA\OpenAgenda\Domain\Enum\DecisionType;
use PHPUnit\Framework\TestCase;

final class DecisionTypeTest extends TestCase {
	public function testSupportedDecisionTypes(): void {
		$this->assertSame(
			['approved', 'rejected', 'approved_with_amendments', 'postponed', 'referred'],
			array_map(static fn ($c) => $c->value, DecisionType::cases()),
		);
	}

	public function testTextRequirement(): void {
		$this->assertTrue(DecisionType::ApprovedWithAmendments->requiresText());
		$this->assertTrue(DecisionType::Referred->requiresText());
		$this->assertFalse(DecisionType::Approved->requiresText());
		$this->assertFalse(DecisionType::Rejected->requiresText());
		$this->assertFalse(DecisionType::Postponed->requiresText());
	}

	public function testForwardingAndConclusiveness(): void {
		$this->assertFalse(DecisionType::Postponed->allowsForwarding());
		foreach ([DecisionType::Approved, DecisionType::Rejected, DecisionType::ApprovedWithAmendments, DecisionType::Referred] as $type) {
			$this->assertTrue($type->allowsForwarding(), $type->value);
		}
		$this->assertTrue(DecisionType::Approved->isConclusive());
		$this->assertFalse(DecisionType::Referred->isConclusive());
		$this->assertFalse(DecisionType::Postponed->isConclusive());
	}

	public function testUnknownTypeIsRejected(): void {
		$this->assertNull(DecisionType::tryFrom('approve'));
	}
}
