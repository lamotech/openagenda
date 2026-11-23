<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service;

/**
 * Renders protocol content (structured array) as a self-contained HTML document.
 * All values are escaped; no user supplied markup is ever rendered.
 */
class ProtocolRenderer {
	public function render(array $content): string {
		$e = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$para = static fn (?string $v): string => $v === null || $v === '' ? '<p class="empty">–</p>' : '<p>' . nl2br($e($v)) . '</p>';
		$date = static fn (?int $ts): string => $ts === null ? '' : self::formatDate($ts);

		$status = $content['status'] === 'approved' ? 'Godkendt' : 'Udkast – ikke godkendt';
		$html = [];
		$html[] = '<!DOCTYPE html><html lang="da"><head><meta charset="utf-8">';
		$html[] = '<title>Beslutningsprotokol – ' . $e($content['committee']['name']) . ' – ' . $e($date($content['meeting']['startsAt'])) . '</title>';
		$html[] = '<style>body{font-family:Arial,Helvetica,sans-serif;max-width:860px;margin:2em auto;color:#222;line-height:1.45}'
			. 'h1{font-size:1.6em;margin-bottom:.2em}h2{font-size:1.2em;border-top:1px solid #ccc;padding-top:1em;margin-top:1.5em}'
			. 'h3{font-size:1em;margin-bottom:.2em}.meta{color:#555}.empty{color:#888}.closed{background:#f6f0e0;padding:.3em .6em}'
			. '.decision{background:#eef5ee;border-left:4px solid #2d7d32;padding:.5em 1em}.history{background:#f3f3f8;padding:.5em 1em;font-size:.95em}'
			. '.fictitious{background:#fff3cd;padding:.5em 1em;border:1px solid #e0c36b}</style></head><body>';
		$html[] = '<p class="fictitious">Demo – alle data er fiktive.</p>';
		$html[] = '<p class="meta">' . $e($content['municipality']) . '</p>';
		$html[] = '<h1>Beslutningsprotokol</h1>';
		$html[] = '<p><strong>' . $e($content['committee']['name']) . '</strong><br>'
			. 'Møde: ' . $e($content['meeting']['title']) . '<br>'
			. 'Dato: ' . $e($date($content['meeting']['startsAt'])) . '<br>'
			. 'Sted: ' . $e($content['meeting']['location'] ?? '–') . '</p>';
		$html[] = '<p class="meta">Protokolversion ' . $e($content['version']) . ' · Status: ' . $e($status) . '</p>';

		foreach ($content['items'] as $item) {
			$html[] = '<h2>' . $e($item['number']) . '. ' . $e($item['title']) . '</h2>';
			if (!$item['isPublic']) {
				$html[] = '<p class="closed">Lukket punkt</p>';
			}
			if ($item['caseNumber'] !== null) {
				$html[] = '<p class="meta">Sagsnr.: ' . $e($item['caseNumber']) . '</p>';
			}
			foreach ($item['previousTreatments'] as $previous) {
				$html[] = '<div class="history"><h3>Tidligere behandling: ' . $e($previous['committeeName']) . ', ' . $e($date($previous['meetingStartsAt'])) . '</h3>'
					. '<p><strong>' . $e($previous['decisionLabel']) . '</strong></p>' . $para($previous['decisionText']) . '</div>';
			}
			$html[] = '<h3>Indstilling</h3>' . $para($item['recommendation']);
			$html[] = '<h3>Beslutning</h3>';
			if ($item['decision'] === null) {
				$html[] = '<p class="empty">Ingen beslutning registreret.</p>';
			} else {
				$html[] = '<div class="decision"><p><strong>' . $e($item['decision']['label']) . '</strong></p>' . $para($item['decision']['text']) . '</div>';
			}
		}
		$html[] = '</body></html>';
		return implode("\n", $html);
	}

	public static function formatDate(int $timestamp): string {
		$dt = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('Europe/Copenhagen'));
		$months = ['januar', 'februar', 'marts', 'april', 'maj', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'december'];
		return sprintf('%d. %s %s kl. %s', (int)$dt->format('j'), $months[(int)$dt->format('n') - 1], $dt->format('Y'), $dt->format('H:i'));
	}
}
