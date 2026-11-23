<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Tests\Integration;

use OCA\OpenAgenda\Db\AgendaItemMapper;
use OCA\OpenAgenda\Db\DecisionMapper;
use OCA\OpenAgenda\Db\PoliticalCaseMapper;
use OCA\OpenAgenda\Db\TreatmentMapper;
use OCA\OpenAgenda\Domain\Exception\ConflictException;
use OCA\OpenAgenda\Domain\Exception\ForbiddenException;
use OCA\OpenAgenda\Domain\Exception\InvalidTransitionException;
use OCA\OpenAgenda\Domain\Exception\ValidationException;
use OCA\OpenAgenda\Service\AgendaService;
use OCA\OpenAgenda\Service\TreatmentService;
use OCP\Server;

final class TreatmentIntegrationTest extends IntegrationTestCase {
	public function testDecisionAndForwardingToCityCouncil(): void {
		$tmu = $this->createCommittee('TMU');
		$br = $this->createCommittee('Byråd', 'city_council');
		$tmuMeeting = $this->createMeeting($tmu, 5);
		$brMeeting = $this->createMeeting($br, 20, 'Byrådsmøde');
		$item = $this->createSubmittedItem($tmuMeeting);
		Server::get(AgendaService::class)->publish($this->actor('secretary'), $tmuMeeting->getUuid(), true);

		$treatments = Server::get(TreatmentService::class);
		try {
			$treatments->registerDecision($this->actor('secretary'), $item->getUuid(), ['decisionType' => 'approved']);
			$this->fail('Meeting not started');
		} catch (InvalidTransitionException) {
		}
		$this->startMeeting($tmuMeeting);

		try {
			$treatments->registerDecision($this->actor('caseworker'), $item->getUuid(), ['decisionType' => 'approved']);
			$this->fail('Caseworker must not register decisions');
		} catch (ForbiddenException) {
		}
		try {
			$treatments->registerDecision($this->actor('secretary'), $item->getUuid(), ['decisionType' => 'approved_with_amendments']);
			$this->fail('Text required');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('decisionText', $e->getErrors());
		}
		try {
			$treatments->registerDecision($this->actor('secretary'), $item->getUuid(), ['decisionType' => 'godkendt']);
			$this->fail('Unknown decision type');
		} catch (ValidationException) {
		}

		$treatments->registerDecision($this->actor('secretary'), $item->getUuid(), ['decisionType' => 'postponed']);
		try {
			$treatments->forward($this->actor('secretary'), $item->getUuid(), $brMeeting->getUuid());
			$this->fail('Postponed item cannot be forwarded');
		} catch (InvalidTransitionException) {
		}
		$decision = $treatments->registerDecision($this->actor('secretary'), $item->getUuid(), ['decisionType' => 'approved', 'decisionText' => 'Udvalget anbefaler forslaget godkendt.']);
		$this->assertSame('approved', $decision->getDecisionType());

		try {
			$treatments->forward($this->actor('secretary'), $item->getUuid(), $tmuMeeting->getUuid());
			$this->fail('Must forward to another committee');
		} catch (ValidationException) {
		}

		$forwarded = $treatments->forward($this->actor('secretary'), $item->getUuid(), $brMeeting->getUuid());
		$this->assertSame($brMeeting->getId(), $forwarded->getMeetingId());
		$this->assertSame($item->getPoliticalCaseId(), $forwarded->getPoliticalCaseId(), 'Same political case / OpenCase reference');
		$this->assertSame($item->getId(), $forwarded->getForwardedFromItemId());
		$this->assertSame('approved', $forwarded->getStatus());

		// The earlier treatment is preserved and linked.
		$tm = Server::get(TreatmentMapper::class);
		$old = $tm->findByItem($item->getId());
		$new = $tm->findByItem($forwarded->getId());
		$this->assertSame('forwarded', $old->getStatus());
		$this->assertSame($forwarded->getId(), $old->getForwardedToItemId());
		$this->assertSame($old->getId(), $new->getPreviousTreatmentId());
		$this->assertSame('Udvalget anbefaler forslaget godkendt.', Server::get(DecisionMapper::class)->findByTreatment($old->getId())->getDecisionText());
		$this->assertCount(2, $tm->findByPoliticalCase($item->getPoliticalCaseId()));
		$this->assertCount(1, Server::get(PoliticalCaseMapper::class)->findByIds([$item->getPoliticalCaseId()]));

		try {
			$treatments->forward($this->actor('secretary'), $item->getUuid(), $brMeeting->getUuid());
			$this->fail('Double forwarding');
		} catch (ConflictException) {
		}
		try {
			$treatments->registerDecision($this->actor('secretary'), $item->getUuid(), ['decisionType' => 'rejected']);
			$this->fail('Forwarded decision is locked');
		} catch (ConflictException) {
		}

		// The secretariat sees the history while preparing the council agenda.
		$history = $treatments->historyForItem($this->actor('secretary'), $forwarded->getUuid());
		$this->assertCount(2, $history);
		$this->assertFalse($history[0]['isCurrent']);
		$this->assertSame('Udvalget anbefaler forslaget godkendt.', $history[0]['decision']['text']);
		$this->assertTrue($history[1]['isCurrent']);
		$this->assertNull($history[1]['decision']);

		// Politicians get the council material (incl. the committee's recommendation) once it is sent out.
		$snapshot = Server::get(AgendaService::class)->publish($this->actor('secretary'), $brMeeting->getUuid(), false);
		$history = $treatments->historyForItem($this->actor('politician'), $forwarded->getUuid());
		$this->assertSame('Godkendt', $history[0]['decision']['label']);
		$this->assertFalse($history[0]['restricted']);

		// City council takes an independent final decision.
		$this->assertSame('Godkendt', $snapshot->getContentArray()['items'][0]['previousTreatments'][0]['decisionLabel']);
		$this->startMeeting($brMeeting);
		$treatments->registerDecision($this->actor('secretary'), $forwarded->getUuid(), ['decisionType' => 'approved', 'decisionText' => 'Byrådet godkendte lokalplanen.']);
		$this->assertSame('decided', Server::get(PoliticalCaseMapper::class)->findById($item->getPoliticalCaseId())->getStatus());
		$this->assertSame('Udvalget anbefaler forslaget godkendt.', Server::get(DecisionMapper::class)->findByTreatment($old->getId())->getDecisionText(), 'Historic decision not overwritten');
		$this->assertSame('published', Server::get(AgendaItemMapper::class)->findById($forwarded->getId())->getStatus());
	}
}
