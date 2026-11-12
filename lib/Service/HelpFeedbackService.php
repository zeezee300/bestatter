<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IConfig;

/**
 * 0.89.6: Rückmeldung „Hat das geholfen? – Nein“ der Bedienhilfe (0.90.1: zusätzlich unbeantwortete Fragen). Gespeichert werden nur Frage, Seite, gefundene
 * Abschnitte und Datum – ohne Benutzerkennung – als App-Wert `help_feedback` (höchstens 200 Einträge, älter als
 * 180 Tage entfallen). Die Bestatter-Administration sieht die Liste unter Administration → Assistenz („Offene
 * Hilfefragen“) und entfernt erledigte Einträge. Bewusst getrennt von der lesenden Bedienhilfe.
 */
class HelpFeedbackService {
	public const CONFIG_KEY = 'help_feedback';
	public const MAX_ENTRIES = 200;
	public const MAX_AGE_DAYS = 180;

	public function __construct(
		private IConfig $config,
		private ?TeamService $team = null,
	) {
	}

	public const REASONS = ['NOT_HELPFUL', 'NO_SOURCE', 'FAILED'];

	/**
	 * 0.90.1: Neben „Hat das geholfen? – Nein“ (NOT_HELPFUL) werden auch Fragen ohne passenden Hilfetext (NO_SOURCE) und
	 * Fragen, zu denen der Chat-Anbieter keine Antwort erzeugen konnte (FAILED), automatisch erfasst. Dieselbe Frage
	 * (Groß-/Kleinschreibung und Satzzeichen am Ende egal) erzeugt keinen zweiten Eintrag; sie rückt nach oben und zählt mit.
	 * @param list<string> $sources Überschriften der gefundenen Hilfetexte
	 */
	public function add(string $question, string $page = '', string $pageTitle = '', array $sources = [], string $reason = 'NOT_HELPFUL'): array {
		$question = trim((string)preg_replace('/\s+/u', ' ', $question));
		if ($question === '' || mb_strlen($question) > 500) {
			throw new \InvalidArgumentException('Die Frage fehlt oder ist länger als 500 Zeichen.');
		}
		$page = preg_match('/^[a-z0-9-]{1,120}$/', $page) ? $page : '';
		$reason = in_array($reason, self::REASONS, true) ? $reason : 'NOT_HELPFUL';
		$sources = array_values(array_slice(array_map(static fn ($source): string => mb_substr(trim((string)$source), 0, 200), array_filter($sources, 'is_scalar')), 0, 5));
		$entries = $this->stored();
		$key = self::questionKey($question);
		foreach ($entries as $index => $existing) {
			if (self::questionKey((string)$existing['question']) !== $key) {
				continue;
			}
			unset($entries[$index]);
			$entry = array_merge($existing, [
				'count' => (int)($existing['count'] ?? 1) + 1, 'createdAt' => date('c'), 'reason' => $reason,
				'page' => $page !== '' ? $page : (string)($existing['page'] ?? ''), 'pageTitle' => trim($pageTitle) !== '' ? mb_substr(trim($pageTitle), 0, 160) : (string)($existing['pageTitle'] ?? ''),
				'sources' => $sources,
			]);
			array_unshift($entries, $entry);
			$this->store(array_values($entries));
			return ['id' => $entry['id'], 'count' => $entry['count']];
		}
		$entry = [
			'id' => bin2hex(random_bytes(8)), 'question' => $question, 'page' => $page, 'pageTitle' => mb_substr(trim($pageTitle), 0, 160),
			'sources' => $sources, 'reason' => $reason, 'count' => 1, 'createdAt' => date('c'),
		];
		array_unshift($entries, $entry);
		$this->store($entries);
		return ['id' => $entry['id'], 'count' => 1];
	}

	private static function questionKey(string $question): string {
		return trim((string)preg_replace('/[\s?!.]+$/u', '', mb_strtolower(trim($question))));
	}

	/** Offene Hilfefragen (neueste zuerst), nur Bestatter-Administration. */
	public function entries(): array {
		$this->team?->requireBestatterAdmin();
		return ['entries' => $this->stored(), 'maxEntries' => self::MAX_ENTRIES, 'maxAgeDays' => self::MAX_AGE_DAYS];
	}

	public function resolve(string $id): array {
		$this->team?->requireBestatterAdmin();
		$entries = $this->stored();
		$remaining = array_values(array_filter($entries, static fn (array $entry): bool => $entry['id'] !== $id));
		if (count($remaining) === count($entries)) {
			throw new \InvalidArgumentException('Diese Hilfefrage wurde nicht gefunden.');
		}
		$this->store($remaining);
		return $this->entries();
	}

	/** @return list<array> */
	private function stored(): array {
		$data = json_decode($this->config->getAppValue(Application::APP_ID, self::CONFIG_KEY, '[]'), true);
		$limit = time() - self::MAX_AGE_DAYS * 86400;
		return array_values(array_filter(is_array($data) ? $data : [], static fn ($entry): bool => is_array($entry) && isset($entry['id'], $entry['question']) && strtotime((string)($entry['createdAt'] ?? '')) >= $limit));
	}

	private function store(array $entries): void {
		$this->config->setAppValue(Application::APP_ID, self::CONFIG_KEY, json_encode(array_slice($entries, 0, self::MAX_ENTRIES), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
	}
}
