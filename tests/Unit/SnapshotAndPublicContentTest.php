<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Tests\Unit;

use OCA\OpenAgenda\Domain\ContentHasher;
use OCA\OpenAgenda\Domain\Publication\PublicContentFactory;
use OCA\OpenAgenda\Domain\Uuid;
use PHPUnit\Framework\TestCase;

final class SnapshotAndPublicContentTest extends TestCase {
	private function snapshot(): array {
		return [
			'schema' => 1,
			'version' => 1,
			'municipality' => 'Demo Kommune (fiktiv)',
			'committee' => ['uuid' => 'c', 'name' => 'Byrådet', 'type' => 'city_council'],
			'meeting' => ['uuid' => 'm-uuid', 'title' => 'Byrådsmøde', 'startsAt' => 1765900800, 'endsAt' => null, 'location' => 'Byrådssalen'],
			'items' => [
				[
					'uuid' => 'i1', 'number' => 1, 'title' => 'Lokalplan', 'isPublic' => true,
					'caseReference' => ['politicalCaseUuid' => 'p', 'opencaseCaseId' => 'mock-case-0001', 'caseNumber' => 'DEMO-2026-001'],
					'summary' => 'Resumé', 'presentation' => 'Sagsfremstilling', 'recommendation' => 'Indstilling',
					'legalBasis' => 'Planloven', 'financialImpact' => 'Ingen',
					'attachments' => [
						['documentId' => 'd1', 'title' => 'Offentligt bilag', 'versionId' => 'd1-v3', 'versionNumber' => 3, 'accessLevel' => 'public'],
						['documentId' => 'd2', 'title' => 'Internt notat', 'versionId' => 'd2-v1', 'versionNumber' => 1, 'accessLevel' => 'internal'],
						['documentId' => 'd3', 'title' => 'Fortroligt', 'versionId' => 'd3-v1', 'versionNumber' => 1, 'accessLevel' => 'confidential'],
					],
					'previousTreatments' => [
						['committeeName' => 'Teknik- og Miljøudvalget', 'meetingStartsAt' => 1765000000, 'recommendation' => 'x', 'decisionType' => 'approved', 'decisionLabel' => 'Godkendt', 'decisionText' => 'Anbefales', 'isPublic' => true],
					],
				],
				[
					'uuid' => 'i2', 'number' => 2, 'title' => 'HEMMELIG: Køb af ejendom', 'isPublic' => false,
					'caseReference' => ['politicalCaseUuid' => 'p2', 'opencaseCaseId' => 'mock-case-0004', 'caseNumber' => 'DEMO-2026-004'],
					'summary' => 'HEMMELIG', 'presentation' => 'HEMMELIG', 'recommendation' => 'HEMMELIG', 'legalBasis' => null, 'financialImpact' => 'HEMMELIG',
					'attachments' => [['documentId' => 'd4', 'title' => 'HEMMELIG', 'versionId' => 'x', 'versionNumber' => 1, 'accessLevel' => 'public']],
					'previousTreatments' => [],
				],
			],
		];
	}

	public function testHashIsDeterministicAndKeyOrderIndependent(): void {
		$a = ['b' => 1, 'a' => ['y' => 2, 'x' => [3, 2, 1]]];
		$b = ['a' => ['x' => [3, 2, 1], 'y' => 2], 'b' => 1];
		$this->assertSame(ContentHasher::hash($a), ContentHasher::hash($b));
		$this->assertTrue(ContentHasher::verify($b, ContentHasher::hash($a)));
	}

	public function testHashDetectsTampering(): void {
		$snapshot = $this->snapshot();
		$hash = ContentHasher::hash($snapshot);
		$snapshot['items'][0]['recommendation'] = 'Ændret efter udsendelse';
		$this->assertFalse(ContentHasher::verify($snapshot, $hash));
	}

	public function testRoundTripThroughStorageKeepsHash(): void {
		$snapshot = $this->snapshot();
		$stored = ContentHasher::canonicalJson($snapshot);
		$restored = json_decode($stored, true);
		$this->assertTrue(ContentHasher::verify($restored, ContentHasher::hash($snapshot)));
	}

	public function testPublicContentContainsNoClosedData(): void {
		$protocol = ['items' => [
			['uuid' => 'i1', 'isPublic' => true, 'decision' => ['type' => 'approved', 'label' => 'Godkendt', 'text' => 'Byrådet godkendte']],
			['uuid' => 'i2', 'isPublic' => false, 'decision' => ['type' => 'approved', 'label' => 'Godkendt', 'text' => 'HEMMELIG']],
		]];
		$public = (new PublicContentFactory())->build($this->snapshot(), $protocol, 1, 1);
		$json = json_encode($public, JSON_UNESCAPED_UNICODE);

		$this->assertStringNotContainsString('HEMMELIG', $json);
		$this->assertStringNotContainsString('Internt notat', $json);
		$this->assertStringNotContainsString('Fortroligt', $json);
		$this->assertStringNotContainsString('mock-case-0004', $json);
		$this->assertStringNotContainsString('DEMO-2026-004', $json);

		$this->assertSame(['number' => 2, 'closed' => true, 'title' => 'Lukket punkt'], $public['items'][1]);
		$this->assertSame('Byrådet godkendte', $public['items'][0]['decision']['text']);
		$this->assertSame([['title' => 'Offentligt bilag', 'versionNumber' => 3]], $public['items'][0]['attachments']);
		$this->assertTrue($public['hasDecisions']);
	}

	public function testPublicItemExposesOnlyAllowListedFields(): void {
		$public = (new PublicContentFactory())->build($this->snapshot(), null, 1, null);
		$this->assertSame(
			['number', 'closed', 'title', 'caseNumber', 'summary', 'presentation', 'recommendation', 'legalBasis', 'financialImpact', 'attachments', 'previousTreatments', 'decision'],
			array_keys($public['items'][0]),
		);
		$this->assertNull($public['items'][0]['decision']);
		$this->assertFalse($public['hasDecisions']);
		$this->assertArrayNotHasKey('uuid', $public['items'][0]);
	}

	public function testUuidV4Format(): void {
		$uuid = Uuid::v4();
		$this->assertTrue(Uuid::isValid($uuid));
		$this->assertSame('4', $uuid[14]);
		$this->assertNotSame($uuid, Uuid::v4());
		$this->assertFalse(Uuid::isValid("x' OR 1=1"));
	}
}
