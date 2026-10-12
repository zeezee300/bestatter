<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * 0.81.0: Prüft die Paperless-Einrichtung für den Belegeingang (nur lesend): Betriebsart, Webhook-Geheimnis,
 * benutzerdefiniertes Feld „Fallnummer“, Zuordnung der Dokumentarten zu vorhandenen Paperless-Dokumenttypen und den
 * letzten gemeldeten Eingang. Genutzt von der Systemprüfung, `occ bestatter:paperless-acceptance-check` und dem
 * Bestatter-Assistenten. Die Bewertung selbst ist rein (evaluate) und ohne Nextcloud testbar.
 */
class PaperlessSetupCheckService {
	public function __construct(
		private PaperlessService $paperless,
		private DocumentKindService $kinds,
	) {}

	/** @return array{status:string,applicable:bool,message:string,items:list<array{key:string,label:string,status:string,detail:string}>,problems:list<string>} */
	public function check(bool $remote = true): array {
		$settings = $this->paperless->settings();
		$facts = [
			'mode' => $settings['mode'],
			'enabled' => (bool)$settings['enabled'],
			'webhookSecretConfigured' => (bool)$settings['webhookSecretConfigured'],
			'caseNumberField' => trim((string)$settings['caseNumberField']),
			'kinds' => $this->kinds->definitions(),
			'lastReceivedAt' => $this->paperless->lastInboxReceivedAt(),
			'remote' => null,
		];
		if ($remote && $facts['enabled'] && $facts['mode'] === 'FULL') {
			$connection = $this->paperless->connectionTest();
			$facts['remote'] = ['reachable' => ($connection['status'] ?? '') === 'OK', 'message' => (string)($connection['message'] ?? ''), 'fields' => null, 'types' => null, 'error' => ''];
			if ($facts['remote']['reachable']) {
				try { $facts['remote']['fields'] = array_values($this->paperless->customFieldNamesStrict()); }
				catch (\Throwable $error) { $facts['remote']['error'] = 'Benutzerdefinierte Felder nicht lesbar: ' . mb_substr($error->getMessage(), 0, 160); }
				try { $facts['remote']['types'] = $this->paperless->documentTypes(); }
				catch (\Throwable $error) { $facts['remote']['error'] = trim($facts['remote']['error'] . ' Dokumenttypen nicht lesbar: ' . mb_substr($error->getMessage(), 0, 160)); }
			}
		}
		return self::evaluate($facts);
	}

	/** Reine Bewertung der gesammelten Angaben (Status OK | WARN | ERROR; OFF, wenn der Belegeingang nicht genutzt wird). */
	public static function evaluate(array $facts): array {
		$items = [];
		$add = static function (string $key, string $label, string $status, string $detail) use (&$items): void { $items[] = ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail]; };
		if (!$facts['enabled'] || $facts['mode'] !== 'FULL') {
			return ['status' => 'OFF', 'applicable' => false, 'message' => $facts['enabled'] ? 'Der Belegeingang ist nicht aktiv (Betriebsart „Nur Kopie an Paperless“). Die Prüfung der Einrichtung entfällt.' : 'Paperless ist nicht angebunden; der Belegeingang wird nicht genutzt.', 'items' => [], 'problems' => []];
		}
		$add('webhook', 'Webhook-Geheimnis', $facts['webhookSecretConfigured'] ? 'OK' : 'ERROR', $facts['webhookSecretConfigured'] ? 'gesetzt' : 'fehlt – ohne Geheimnis nimmt die Anwendung keine Meldungen von Paperless an');

		$remote = $facts['remote'];
		if (is_array($remote) && !$remote['reachable']) $add('connection', 'Verbindung', 'ERROR', 'Paperless nicht erreichbar' . ($remote['message'] !== '' ? ': ' . $remote['message'] : ''));
		elseif (is_array($remote) && $remote['error'] !== '') $add('connection', 'Verbindung', 'WARN', $remote['error']);

		$field = (string)$facts['caseNumberField'];
		if ($field === '') $add('caseNumberField', 'Feld „Fallnummer“', 'WARN', 'nicht eingestellt – der Fall wird nur über Text oder Name und Datum vorgeschlagen, automatische Ablage ist aus');
		elseif (is_array($remote) && is_array($remote['fields'])) {
			$found = in_array(mb_strtolower($field), array_map(static fn(string $name): string => mb_strtolower(trim($name)), $remote['fields']), true);
			$add('caseNumberField', 'Feld „' . $field . '“', $found ? 'OK' : 'WARN', $found ? 'in Paperless vorhanden' : 'in Paperless nicht gefunden – benutzerdefiniertes Feld (Text) mit genau diesem Namen anlegen');
		} else $add('caseNumberField', 'Feld „' . $field . '“', 'OK', 'eingestellt (in Paperless nicht geprüft)');

		$active = array_values(array_filter((array)$facts['kinds'], static fn(array $kind): bool => (bool)$kind['active']));
		$withoutType = array_values(array_map(static fn(array $kind): string => (string)$kind['name'], array_filter($active, static fn(array $kind): bool => $kind['paperlessTypeId'] === null && $kind['key'] !== DocumentKindRules::FALLBACK_KIND)));
		$add('kindsMapped', 'Dokumentarten mit Paperless-Dokumenttyp', $withoutType === [] ? 'OK' : 'WARN', $withoutType === [] ? 'alle aktiven Dokumentarten zugeordnet' : 'ohne Dokumenttyp: ' . implode(', ', $withoutType) . ' – diese Arten werden nicht vorgeschlagen, sondern im Belegeingang von Hand gewählt');
		if (is_array($remote) && is_array($remote['types'])) {
			$typeIds = array_map(static fn(array $type): int => (int)$type['id'], $remote['types']);
			$missing = array_values(array_map(static fn(array $kind): string => $kind['name'] . ' (Typ ' . $kind['paperlessTypeId'] . ')', array_filter($active, static fn(array $kind): bool => $kind['paperlessTypeId'] !== null && !in_array((int)$kind['paperlessTypeId'], $typeIds, true))));
			if ($missing !== []) $add('kindsExist', 'Dokumenttypen in Paperless', 'WARN', 'nicht (mehr) vorhanden: ' . implode(', ', $missing));
			else $add('kindsExist', 'Dokumenttypen in Paperless', 'OK', count($typeIds) . ' Dokumenttyp(en) gelesen, alle zugeordneten vorhanden');
		}

		$last = $facts['lastReceivedAt'];
		$add('lastReceived', 'Letzte Meldung von Paperless', $last === null ? 'WARN' : 'OK', $last === null ? 'noch kein Dokument gemeldet – Workflow mit Webhook in Paperless prüfen' : date('d.m.Y H:i', strtotime((string)$last) ?: time()));

		$status = 'OK';
		foreach ($items as $item) { if ($item['status'] === 'ERROR') { $status = 'ERROR'; break; } if ($item['status'] === 'WARN') $status = 'WARN'; }
		$problems = array_values(array_map(static fn(array $item): string => $item['label'] . ': ' . $item['detail'], array_filter($items, static fn(array $item): bool => $item['status'] !== 'OK')));
		$message = $status === 'OK' ? 'Die Paperless-Einrichtung für den Belegeingang ist vollständig.' : count($problems) . ' Punkt(e) der Paperless-Einrichtung prüfen.';
		return ['status' => $status, 'applicable' => true, 'message' => $message, 'items' => $items, 'problems' => $problems];
	}
}
