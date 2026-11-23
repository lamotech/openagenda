<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Tests\Integration;

use OCP\IConfig;
use OCP\Server;

/**
 * End-to-end test of the complete demo scenario through the real HTTP API of
 * the running Nextcloud instance (routing, authentication, CSRF handling,
 * controllers, services and database) – from choosing an OpenCase case to the
 * published city council decision.
 *
 * Requires that the server is reachable at 'overwrite.cli.url'. Requests use
 * basic auth with the temporary test users and the "OCS-APIRequest" header,
 * which Nextcloud accepts instead of a CSRF token for API clients.
 *
 * @group e2e
 */
final class EndToEndHttpTest extends IntegrationTestCase {
	private string $baseUrl;

	protected function setUp(): void {
		parent::setUp();
		$url = (string)Server::get(IConfig::class)->getSystemValue('overwrite.cli.url', '');
		if ($url === '' || !function_exists('curl_init')) {
			$this->markTestSkipped('overwrite.cli.url or ext-curl not available');
		}
		$this->baseUrl = rtrim($url, '/') . '/index.php/apps/openagenda';
		[$status] = $this->request(null, 'GET', '/api/public/v1/meetings');
		if ($status === 0) {
			$this->markTestSkipped('Nextcloud is not reachable at ' . $url);
		}
	}

	/**
	 * @return array{0: int, 1: mixed}
	 */
	private function request(?string $role, string $method, string $path, ?array $body = null): array {
		$ch = curl_init($this->baseUrl . $path);
		$headers = ['Accept: application/json', 'OCS-APIRequest: true'];
		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		}
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_TIMEOUT => 30,
		]);
		if ($role !== null) {
			$uid = self::$users[$role];
			curl_setopt($ch, CURLOPT_USERPWD, $uid . ':' . self::$passwords[$uid]);
		}
		$raw = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		return [$status, is_string($raw) ? json_decode($raw, true) : null];
	}

	private function ok(?string $role, string $method, string $path, ?array $body = null, int $expected = 200): array {
		[$status, $data] = $this->request($role, $method, $path, $body);
		$this->assertSame($expected, $status, "$method $path as $role: " . json_encode($data, JSON_UNESCAPED_UNICODE));
		return $data;
	}

	public function testCompleteDemoScenarioOverHttp(): void {
		// Setup by the secretariat: committees and meetings.
		$tmu = $this->ok('secretary', 'POST', '/api/v1/committees', ['name' => self::$prefix . ' Teknik- og Miljøudvalget', 'type' => 'standing_committee'], 201);
		$br = $this->ok('secretary', 'POST', '/api/v1/committees', ['name' => self::$prefix . ' Byrådet', 'type' => 'city_council'], 201);
		$tmuMeeting = $this->ok('secretary', 'POST', '/api/v1/meetings', ['committeeId' => $tmu['id'], 'title' => 'TMU-møde', 'startsAt' => gmdate('c', time() + 7 * 86400), 'location' => 'Mødelokale 2'], 201);
		$brMeeting = $this->ok('secretary', 'POST', '/api/v1/meetings', ['committeeId' => $br['id'], 'title' => 'Byrådsmøde', 'startsAt' => gmdate('c', time() + 21 * 86400), 'location' => 'Byrådssalen'], 201);
		$this->assertSame(403, $this->request('caseworker', 'POST', '/api/v1/meetings', ['committeeId' => $tmu['id'], 'title' => 'x', 'startsAt' => gmdate('c')])[0]);

		// 1. Caseworker finds the case in OpenCase (mock).
		$search = $this->ok('caseworker', 'GET', '/api/v1/opencase/cases?q=' . rawurlencode('Testsag lokalplan'));
		$found = array_values(array_filter($search['results'], fn ($c) => $c['id'] === self::caseId('case1')));
		$this->assertCount(1, $found);
		$documentIds = array_column($found[0]['documents'], 'id');
		$this->assertContains(self::docId('doc-public'), $documentIds);
		$this->assertNotContains(self::docId('doc-confidential'), $documentIds, 'Adapter hides confidential documents from caseworkers');
		$this->assertSame(403, $this->request('caseworker', 'GET', '/api/v1/opencase/documents/' . self::docId('doc-confidential'))[0]);

		// 2.+3. Caseworker creates the agenda item on the TMU meeting and submits it.
		$item = $this->ok('caseworker', 'POST', '/api/v1/items', [
			'meetingId' => $tmuMeeting['id'],
			'title' => 'Forslag til lokalplan for nyt boligområde',
			'opencaseCaseId' => self::caseId('case1'),
			'summary' => 'Lokalplan for ca. 80 boliger.',
			'presentation' => 'Forvaltningen har udarbejdet forslag til lokalplan.',
			'recommendation' => 'Forvaltningen indstiller, at lokalplanforslaget godkendes.',
			'legalBasis' => 'Planloven',
			'financialImpact' => 'Ingen',
			'isPublic' => true,
			'attachments' => [['documentId' => self::docId('doc-public')], ['documentId' => self::docId('doc-internal')]],
		], 201);
		$this->assertSame('draft', $item['status']);
		$this->assertSame(self::caseId('case1'), $item['case']['opencaseCaseId']);
		$this->assertSame('submitted', $this->ok('caseworker', 'POST', '/api/v1/items/' . $item['id'] . '/submit')['status']);

		// 4. Secretariat approves and sends out the agenda (immutable snapshot).
		$this->assertSame(403, $this->request('caseworker', 'POST', '/api/v1/meetings/' . $tmuMeeting['id'] . '/publish-agenda', ['approveSubmitted' => true])[0]);
		$this->assertSame('approved', $this->ok('secretary', 'POST', '/api/v1/items/' . $item['id'] . '/approve')['status']);
		$snapshot = $this->ok('secretary', 'POST', '/api/v1/meetings/' . $tmuMeeting['id'] . '/publish-agenda', ['approveSubmitted' => false], 201);
		$this->assertSame(1, $snapshot['version']);
		$snapshotView = $this->ok('politician', 'GET', '/api/v1/snapshots/' . $snapshot['id']);
		$this->assertTrue($snapshotView['verified']);
		$this->assertSame(self::docId('doc-public') . '-v2', $snapshotView['content']['items'][0]['attachments'][0]['versionId']);

		// 5. Committee treats the item and registers its recommendation.
		$this->ok('secretary', 'POST', '/api/v1/meetings/' . $tmuMeeting['id'] . '/status', ['status' => 'in_progress']);
		$this->assertSame(403, $this->request('politician', 'PUT', '/api/v1/items/' . $item['id'] . '/decision', ['decisionType' => 'approved'])[0]);
		$decided = $this->ok('secretary', 'PUT', '/api/v1/items/' . $item['id'] . '/decision', ['decisionType' => 'approved', 'decisionText' => 'Udvalget anbefaler lokalplanforslaget godkendt.']);
		$this->assertSame('approved', $decided['decision']['type']);
		$this->assertTrue($decided['permissions']['forward']);

		// 6. Forward to the city council meeting.
		$councilItem = $this->ok('secretary', 'POST', '/api/v1/items/' . $item['id'] . '/forward', ['meetingId' => $brMeeting['id']], 201);
		$this->assertSame($brMeeting['id'], $councilItem['meetingId']);
		$this->assertSame(self::caseId('case1'), $councilItem['case']['opencaseCaseId']);
		$this->assertSame($item['id'], $councilItem['forwardedFrom']['itemId']);
		$this->assertSame(409, $this->request('secretary', 'POST', '/api/v1/items/' . $item['id'] . '/forward', ['meetingId' => $brMeeting['id']])[0]);
		$this->ok('secretary', 'POST', '/api/v1/meetings/' . $tmuMeeting['id'] . '/status', ['status' => 'completed']);

		// A closed item on the council agenda (must never become public).
		$closed = $this->ok('secretary', 'POST', '/api/v1/items', [
			'meetingId' => $brMeeting['id'], 'title' => 'LUKKET: Køb af ejendom', 'opencaseCaseId' => self::caseId('case2'),
			'presentation' => 'FORTROLIG købesum', 'recommendation' => 'FORTROLIG indstilling', 'isPublic' => false,
		], 201);
		$this->ok('secretary', 'POST', '/api/v1/items/' . $closed['id'] . '/submit');

		// 7. City council: agenda out, earlier recommendation visible, independent decision.
		$this->ok('secretary', 'POST', '/api/v1/meetings/' . $brMeeting['id'] . '/publish-agenda', ['approveSubmitted' => true], 201);
		$councilView = $this->ok('politician', 'GET', '/api/v1/items/' . $councilItem['id']);
		$this->assertSame('Udvalget anbefaler lokalplanforslaget godkendt.', $councilView['history'][0]['decision']['text']);
		$this->assertTrue($councilView['history'][1]['isCurrent']);
		$this->assertSame(404, $this->request('caseworker2', 'GET', '/api/v1/items/' . $closed['id'])[0], 'Closed item hidden from other caseworkers');

		$this->ok('secretary', 'POST', '/api/v1/meetings/' . $brMeeting['id'] . '/status', ['status' => 'in_progress']);
		$this->ok('secretary', 'PUT', '/api/v1/items/' . $councilItem['id'] . '/decision', ['decisionType' => 'approved', 'decisionText' => 'Byrådet godkendte lokalplanen.']);
		$this->ok('secretary', 'PUT', '/api/v1/items/' . $closed['id'] . '/decision', ['decisionType' => 'approved', 'decisionText' => 'FORTROLIG beslutning']);
		$this->ok('secretary', 'POST', '/api/v1/meetings/' . $brMeeting['id'] . '/status', ['status' => 'completed']);

		// 8. Protocol generated and approved.
		$protocol = $this->ok('secretary', 'POST', '/api/v1/meetings/' . $brMeeting['id'] . '/protocols', null, 201);
		$this->assertStringContainsString('Byrådet godkendte lokalplanen.', $protocol['html']);
		$this->assertStringContainsString('Tidligere behandling', $protocol['html']);
		$approved = $this->ok('secretary', 'POST', '/api/v1/protocols/' . $protocol['id'] . '/approve');
		$this->assertSame('approved', $approved['status']);
		$this->assertTrue($approved['verified']);

		// 9. Journalized in OpenCase (mock) – idempotent.
		$journal = $this->ok('secretary', 'POST', '/api/v1/protocols/' . $protocol['id'] . '/journalize');
		$this->assertSame(2, $journal['created']);
		$again = $this->ok('secretary', 'POST', '/api/v1/protocols/' . $protocol['id'] . '/journalize');
		$this->assertSame(0, $again['created']);
		$this->assertSame(2, $again['skipped']);
		$this->assertSame(array_column($journal['journalizations'], 'externalRef'), array_column($again['journalizations'], 'externalRef'));

		// 10. Public agenda and decision published – readable without login.
		$this->assertSame(404, $this->request(null, 'GET', '/api/public/v1/meetings/' . $brMeeting['id'])[0]);
		$this->ok('secretary', 'POST', '/api/v1/meetings/' . $brMeeting['id'] . '/publications', null, 201);
		[$status, $public] = $this->request(null, 'GET', '/api/public/v1/meetings/' . $brMeeting['id']);
		$this->assertSame(200, $status);
		$publicJson = json_encode($public, JSON_UNESCAPED_UNICODE);
		$this->assertStringContainsString('Byrådet godkendte lokalplanen.', $publicJson);
		$this->assertStringContainsString('Udvalget anbefaler lokalplanforslaget godkendt.', $publicJson);
		$this->assertStringNotContainsString('FORTROLIG', $publicJson);
		$this->assertStringNotContainsString('Køb af ejendom', $publicJson);
		$this->assertStringNotContainsString('Internt testnotat', $publicJson);
		$this->assertTrue($public['items'][1]['closed']);

		// Internal API is not reachable anonymously; the committee meeting was never published publicly.
		$this->assertContains($this->request(null, 'GET', '/api/v1/meetings/' . $brMeeting['id'])[0], [401, 403]);
		$this->assertSame(404, $this->request(null, 'GET', '/api/public/v1/meetings/' . $tmuMeeting['id'])[0]);

		// Audit trail of the key steps exists and is readable by the secretariat only.
		$audit = $this->ok('secretary', 'GET', '/api/v1/audit?meetingId=' . $brMeeting['id'] . '&limit=200');
		$actions = array_column($audit, 'action');
		foreach (['agenda.published', 'decision.registered', 'protocol.approved', 'protocol.journalized', 'publication.published'] as $expected) {
			$this->assertContains($expected, $actions);
		}
		$this->assertSame(403, $this->request('politician', 'GET', '/api/v1/audit')[0]);
	}
}
