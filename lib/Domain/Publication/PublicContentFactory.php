<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain\Publication;

/**
 * Builds the public representation of a meeting from an immutable agenda
 * snapshot and (optionally) an approved protocol.
 *
 * This is an explicit allow-list: only the fields copied here can ever reach
 * the public API. Closed items are reduced to a placeholder with their number,
 * and only attachments whose access level is "public" are listed (metadata only).
 */
final class PublicContentFactory {
	public const SCHEMA_VERSION = 1;
	public const CLOSED_ITEM_TITLE = 'Lukket punkt';

	/**
	 * @param array $snapshot snapshot content (see AgendaSnapshotService)
	 * @param array|null $protocol approved protocol content (see ProtocolService)
	 */
	public function build(array $snapshot, ?array $protocol, int $snapshotVersion, ?int $protocolVersion): array {
		$decisionsByItem = [];
		foreach (($protocol['items'] ?? []) as $protocolItem) {
			if (($protocolItem['isPublic'] ?? false) === true && isset($protocolItem['uuid'])) {
				$decisionsByItem[$protocolItem['uuid']] = $protocolItem['decision'] ?? null;
			}
		}

		$items = [];
		foreach (($snapshot['items'] ?? []) as $item) {
			$number = (int)($item['number'] ?? 0);
			if (($item['isPublic'] ?? false) !== true) {
				$items[] = [
					'number' => $number,
					'closed' => true,
					'title' => self::CLOSED_ITEM_TITLE,
				];
				continue;
			}
			$decision = $decisionsByItem[$item['uuid'] ?? ''] ?? null;
			$items[] = [
				'number' => $number,
				'closed' => false,
				'title' => (string)($item['title'] ?? ''),
				'caseNumber' => $item['caseReference']['caseNumber'] ?? null,
				'summary' => $item['summary'] ?? null,
				'presentation' => $item['presentation'] ?? null,
				'recommendation' => $item['recommendation'] ?? null,
				'legalBasis' => $item['legalBasis'] ?? null,
				'financialImpact' => $item['financialImpact'] ?? null,
				'attachments' => $this->publicAttachments($item['attachments'] ?? []),
				'previousTreatments' => $this->publicTreatments($item['previousTreatments'] ?? []),
				'decision' => $decision === null ? null : [
					'type' => (string)($decision['type'] ?? ''),
					'label' => (string)($decision['label'] ?? ''),
					'text' => $decision['text'] ?? null,
				],
			];
		}

		return [
			'schema' => self::SCHEMA_VERSION,
			'municipality' => (string)($snapshot['municipality'] ?? ''),
			'committee' => [
				'name' => (string)($snapshot['committee']['name'] ?? ''),
				'type' => (string)($snapshot['committee']['type'] ?? ''),
			],
			'meeting' => [
				'uuid' => (string)($snapshot['meeting']['uuid'] ?? ''),
				'title' => (string)($snapshot['meeting']['title'] ?? ''),
				'startsAt' => $snapshot['meeting']['startsAt'] ?? null,
				'location' => $snapshot['meeting']['location'] ?? null,
			],
			'agendaVersion' => $snapshotVersion,
			'protocolVersion' => $protocolVersion,
			'hasDecisions' => $protocol !== null,
			'items' => $items,
		];
	}

	private function publicAttachments(array $attachments): array {
		$result = [];
		foreach ($attachments as $attachment) {
			if (($attachment['accessLevel'] ?? null) !== 'public') {
				continue;
			}
			$result[] = [
				'title' => (string)($attachment['title'] ?? ''),
				'versionNumber' => $attachment['versionNumber'] ?? null,
			];
		}
		return $result;
	}

	private function publicTreatments(array $treatments): array {
		$result = [];
		foreach ($treatments as $treatment) {
			if (($treatment['isPublic'] ?? false) !== true) {
				continue;
			}
			$result[] = [
				'committeeName' => (string)($treatment['committeeName'] ?? ''),
				'meetingStartsAt' => $treatment['meetingStartsAt'] ?? null,
				'decisionLabel' => $treatment['decisionLabel'] ?? null,
				'decisionText' => $treatment['decisionText'] ?? null,
			];
		}
		return $result;
	}
}
