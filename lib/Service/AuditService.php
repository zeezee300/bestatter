<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IDBConnection;
use OCP\IUserSession;

/** Append-only audit log for every case-related business operation. */
class AuditService {
	// Kundenwunsch 2026-09-21: Der Fall-Verlauf zeigte bisher pro Eintrag nur einen generischen
	// Titel wie "Fall geändert" – die vollständigen Vorher-/Nachher-Schnappschüsse werden zwar
	// bereits seit Projektbeginn in before_data/after_data gespeichert, aber nie ausgewertet.
	// diff() vergleicht beide Schnappschüsse feldweise und macht daraus eine lesbare Änderungsliste.
	// Bewusst nur für Objekttypen aktiviert, bei denen before/after nachweislich dieselbe Struktur
	// haben (echter Vorher-/Nachher-Vergleich desselben Datensatzes) – siehe DIFFABLE_TYPES.
	// Bei anderen Objekttypen (z. B. PROCESS_ACTION EXECUTED/FAILED, ORDER QUOTE_CONVERTED) haben
	// before/after unterschiedliche, absichtlich verschiedene Formen (Auslöser- vs. Ergebnisdaten);
	// ein Feld-für-Feld-Vergleich würde dort irreführenden statt hilfreichen Text erzeugen.
	private const DIFFABLE_TYPES = ['CASE', 'TASK', 'SCHEDULE', 'DOCUMENT', 'CONTACT'];
	private const CONTAINER_KEY = ['CASE' => 'masterData'];
	// Diese Fall-Stammdatenfelder werden sowohl auf oberster Ebene (camelCase) als auch identisch
	// unter masterData.* (snake_case) geführt (siehe CaseService::mapCase()); ohne diese Ausnahme
	// würde jede Änderung doppelt in der Änderungsliste erscheinen.
	private const CASE_DUPLICATE_TOP_LEVEL_KEYS = ['firstName', 'lastName', 'dateOfDeath', 'funeralType', 'burialVariantCode', 'withFuneralCeremony', 'pickupTime', 'bodyHeightCm', 'bodyWeightKg'];
	private const TOP_LEVEL_IGNORE = ['id', 'caseId', 'caseNumber', 'updatedAt', 'ownerUid', 'createdBy', 'updatedBy'];
	private const MAX_CHANGES = 20;
	public const LOCK_TABLE = 'bestatter_audit_lock';
	/** 0.84.1: So viele vorangehende Einträge werden betrachtet, um einen gleichzeitig geschriebenen Eintrag zu erkennen. */
	private const CONCURRENT_WINDOW = 50;
	private const FIELD_LABELS = [
		'status' => 'Status', 'branch' => 'Niederlassung', 'responsibleEmployee' => 'Zuständige Person',
		'retentionHold' => 'Aufbewahrungssperre', 'retentionDueAt' => 'Aufbewahrung fällig am',
		'retentionHoldReason' => 'Grund der Aufbewahrungssperre', 'retentionHoldResponsible' => 'Aufbewahrungssperre gesetzt von',
		'retentionHoldSetAt' => 'Aufbewahrungssperre gesetzt am', 'retentionHoldReviewAt' => 'Aufbewahrungssperre zu prüfen am',
		'anonymizedAt' => 'Anonymisiert am',
		'title' => 'Titel', 'date' => 'Termin/Datum', 'assigneeUid' => 'Zuständig', 'assigneeName' => 'Zuständig (Name)',
		'priority' => 'Priorität', 'description' => 'Beschreibung', 'dueOffsetDays' => 'Frist (Tage)',
		'channel' => 'Kontaktweg', 'contact' => 'Kontakt', 'recipient' => 'Empfänger', 'recipientCategory' => 'Empfängergruppe',
		'location' => 'Ort', 'appointmentCategory' => 'Terminart', 'durationMinutes' => 'Dauer (Minuten)',
		'scheduleKind' => 'Terminkategorie', 'sourceStatusAfter' => 'Status danach', 'caseStatusAfter' => 'Fallstatus danach',
		'checklist' => 'Checkliste', 'categories' => 'Kategorien', 'documentType' => 'Dokumentart',
	];

	private ?array $caseFieldLabelsCache = null;

	public function __construct(
		private IDBConnection $db,
		private IUserSession $userSession,
		private BestatterActivityService $activity,
		private CustomizingService $customizing,
	) {}

	public function log(int $caseId, string $objectType, ?int $objectId, string $action, mixed $before = null, mixed $after = null): void {
		if ($caseId <= 0) return;
		$this->insert($caseId, $objectType, $objectId, $action, $before, $after);
	}

	public function logSystem(string $objectType, string $action, mixed $after = null): void {
		$this->insert(0, $objectType, null, $action, null, $after);
	}

	private function insert(int $caseId, string $objectType, ?int $objectId, string $action, mixed $before, mixed $after): void {
		$objectType = strtoupper($objectType);
		$action = strtoupper($action);
		$createdAt = date('c');
		$userUid = $this->uid();
		$before = $this->minimize($before);
		$after = $this->minimize($after);
		// 0.84.1: Lesen des letzten Hashs und Einfügen müssen ununterbrochen nacheinander geschehen. Ohne Sperre lasen
		// zwei gleichzeitige Vorgänge (z. B. Klick und Hintergrundjob) denselben Vorgänger und die Kette verzweigte.
		// Die Sperrzeile in bestatter_audit_lock reiht alle Schreibvorgänge hintereinander ein; Nextcloud arbeitet mit
		// READ COMMITTED, der nach der Sperre gelesene letzte Hash ist daher immer der aktuelle.
		$ownTransaction = !$this->db->inTransaction();
		if ($ownTransaction) $this->db->beginTransaction();
		try {
			$this->lockChain();
			$previousHash = $this->lastHash();
			$eventHash = hash('sha256', implode('|', [$previousHash, $caseId, $objectType, $objectId ?? '', $action, $userUid, $createdAt]));
			$query = $this->db->getQueryBuilder();
			$query->insert('bestatter_audit_log')->values([
				'case_id' => $query->createNamedParameter($caseId),
				'object_type' => $query->createNamedParameter($objectType),
				'object_id' => $query->createNamedParameter($objectId),
				'action' => $query->createNamedParameter($action),
				'before_data' => $query->createNamedParameter($before === null ? null : json_encode($before, JSON_THROW_ON_ERROR)),
				'after_data' => $query->createNamedParameter($after === null ? null : json_encode($after, JSON_THROW_ON_ERROR)),
				'user_uid' => $query->createNamedParameter($userUid),
				'created_at' => $query->createNamedParameter($createdAt),
				'previous_hash' => $query->createNamedParameter($previousHash !== '' ? $previousHash : null),
				'event_hash' => $query->createNamedParameter($eventHash),
			])->executeStatement();
			if ($ownTransaction) $this->db->commit();
		} catch (\Throwable $error) {
			if ($ownTransaction) $this->db->rollBack();
			throw $error;
		}
		$this->activity->publishAuditEvent($caseId, $objectType, $objectId, $action, $before, $after);
	}

	/**
	 * 0.84.1: Sperrt die eine Zeile in bestatter_audit_lock bis zum Ende der Transaktion. Weitere Schreibvorgänge warten
	 * an dieser Stelle, bis der vorherige Eintrag gespeichert ist. Fehlt die Zeile (z. B. nach manuellem Leeren der
	 * Tabelle), wird sie ohne Konflikt neu angelegt.
	 */
	private function lockChain(): void {
		for ($attempt = 0; $attempt < 2; $attempt++) {
			$query = $this->db->getQueryBuilder();
			$locked = $query->update(self::LOCK_TABLE)
				->set('counter', $query->createFunction($query->getColumnName('counter') . ' + 1'))
				->where($query->expr()->eq('id', $query->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->executeStatement();
			if ($locked > 0) return;
			$this->db->insertIgnoreConflict(self::LOCK_TABLE, ['id' => 1, 'counter' => 0]);
		}
		throw new \RuntimeException('Audit-Protokoll: Schreibsperre konnte nicht gesetzt werden.');
	}

	/**
	 * 0.75.1: Entfernt die Inhalte (Vorher-/Nachher-Daten, darin Namen, Anschriften usw.) aller Protokolleinträge
	 * eines Falls. Die Zeilen selbst bleiben stehen: Der Hash umfasst nur Fall-ID, Objekt, Aktion, Benutzer und
	 * Zeitpunkt, die Kette bleibt dadurch vollständig prüfbar (verifyIntegrity). Löscht außerdem die
	 * Aktivitätseinträge des Falls im Nextcloud-Aktivitätenstrom, weil diese den Fallnamen enthalten.
	 */
	public function redactCase(int $caseId): int {
		$query = $this->db->getQueryBuilder();
		$count = $query->update('bestatter_audit_log')
			->set('before_data', $query->createNamedParameter(null))
			->set('after_data', $query->createNamedParameter(null))
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->executeStatement();
		if ($this->db->tableExists('activity')) {
			$activity = $this->db->getQueryBuilder();
			$activity->delete('activity')
				->where($activity->expr()->eq('app', $activity->createNamedParameter(Application::APP_ID)))
				->andWhere($activity->expr()->eq('object_type', $activity->createNamedParameter('bestatter_case')))
				->andWhere($activity->expr()->eq('object_id', $activity->createNamedParameter($caseId)))
				->executeStatement();
		}
		return $count;
	}

	/**
	 * Prüft die Hash-Kette. Ergebnis:
	 * - brokenIds: echte Auffälligkeiten (Inhalt passt nicht zum Hash, oder der Vorgänger fehlt bzw. wurde verändert) → ERROR
	 * - concurrentIds (0.84.1): Einträge, deren eigener Hash stimmt und deren Vorgänger einer der kurz zuvor geschriebenen
	 *   Einträge ist. Das entsteht, wenn zwei Vorgänge gleichzeitig geschrieben haben (vor 0.84.1 möglich); es ist kein
	 *   Hinweis auf eine Veränderung → WARN. Das Protokoll selbst wird nie umgeschrieben.
	 * @return array{status: string, checked: int, brokenIds: list<int>, concurrentIds: list<int>, message: string}
	 */
	public function verifyIntegrity(): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('id', 'case_id', 'object_type', 'object_id', 'action', 'user_uid', 'created_at', 'previous_hash', 'event_hash')
			->from('bestatter_audit_log')->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		return self::classifyChain($rows);
	}

	/**
	 * 0.84.1: Bewertet die nach ID sortierten Protokollzeilen (ohne Datenbank, auch für Tests).
	 * @param list<array<string, mixed>> $rows
	 * @return array{status: string, checked: int, brokenIds: list<int>, concurrentIds: list<int>, message: string}
	 */
	public static function classifyChain(array $rows): array {
		$previous = ''; $broken = []; $concurrent = []; $recent = [];
		foreach ($rows as $row) {
			$stored = (string)($row['event_hash'] ?? '');
			if ($stored === '') { $previous = ''; $recent = []; continue; } // Altbestand beginnt vor Einführung der Verkettung.
			$expectedPrevious = (string)($row['previous_hash'] ?? '');
			$expected = hash('sha256', implode('|', [$expectedPrevious, (int)$row['case_id'], (string)$row['object_type'], $row['object_id'] ?? '', (string)$row['action'], (string)$row['user_uid'], (string)$row['created_at']]));
			if (!hash_equals($expected, $stored)) {
				$broken[] = (int)$row['id'];
			} elseif ($expectedPrevious !== $previous) {
				// Gleichzeitig geschrieben: Der Vorgänger ist ein kurz zuvor gespeicherter Eintrag (oder dessen Vorgänger).
				if ($expectedPrevious !== '' && isset($recent[$expectedPrevious])) $concurrent[] = (int)$row['id'];
				else $broken[] = (int)$row['id'];
			}
			$recent[$stored] = true;
			if ($expectedPrevious !== '') $recent[$expectedPrevious] = true;
			if (count($recent) > 2 * self::CONCURRENT_WINDOW) $recent = array_slice($recent, -self::CONCURRENT_WINDOW, null, true);
			$previous = $stored;
		}
		$status = $broken !== [] ? 'ERROR' : ($concurrent !== [] ? 'WARN' : 'OK');
		return ['status' => $status, 'checked' => count($rows), 'brokenIds' => $broken, 'concurrentIds' => $concurrent, 'message' => self::integrityMessage(count($rows), $broken, $concurrent)];
	}

	/** 0.84.1: Klartext zum Prüfergebnis, z. B. „1283 Einträge geprüft – 1 Eintrag gleichzeitig geschrieben (Nr. 976), Inhalt unverändert“. */
	public static function integrityMessage(int $checked, array $broken, array $concurrent): string {
		$list = static fn(array $ids): string => 'Nr. ' . implode(', ', array_slice($ids, 0, 10)) . (count($ids) > 10 ? ' und ' . (count($ids) - 10) . ' weitere' : '');
		$parts = [];
		if ($broken !== []) $parts[] = (count($broken) === 1 ? '1 Eintrag beschädigt oder Vorgänger fehlt' : count($broken) . ' Einträge beschädigt oder Vorgänger fehlt') . ' (' . $list($broken) . ')';
		if ($concurrent !== []) $parts[] = (count($concurrent) === 1 ? '1 Eintrag gleichzeitig geschrieben' : count($concurrent) . ' Einträge gleichzeitig geschrieben') . ' (' . $list($concurrent) . '), Inhalt unverändert';
		return $checked . ' Einträge geprüft' . ($parts === [] ? ', Kette vollständig' : ' – ' . implode('; ', $parts));
	}

	private function lastHash(): string {
		$query = $this->db->getQueryBuilder();
		$value = $query->select('event_hash')->from('bestatter_audit_log')->where($query->expr()->isNotNull('event_hash'))->orderBy('id', 'DESC')->setMaxResults(1)->executeQuery()->fetchOne();
		return $value === false ? '' : (string)$value;
	}

	private function minimize(mixed $value, string $key = ''): mixed {
		if ($value === null) return null;
		if (preg_match('/password|secret|token|audio|content|iban|bic|creditor|account/i', $key)) return '[geschützt]';
		if (is_array($value)) {
			$result = [];
			foreach ($value as $name => $item) $result[$name] = $this->minimize($item, (string)$name);
			return $result;
		}
		if (is_string($value) && mb_strlen($value) > 2000) return mb_substr($value, 0, 2000) . '… [gekürzt]';
		return $value;
	}

	public function caseHistory(int $caseId): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_audit_log')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')
			->executeQuery()->fetchAllAssociative();
		$history = [];
		foreach ($rows as $row) {
			$objectType = (string)$row['object_type'];
			$action = strtoupper((string)$row['action']);
			$before = $this->decode($row['before_data'] ?? null);
			$after = $this->decode($row['after_data'] ?? null);
			// Bei CREATED/DELETED gibt es naturgemäß keinen Vorher-/Nachher-Vergleich (diff() liefert
			// dafür immer []), aber ohne jeden Hinweis bleibt z. B. "Aufgabe gelöscht" genauso
			// unbestimmt wie zuvor "Fall geändert" - welche Aufgabe, ist offen. contextSummary()
			// ergänzt in diesen beiden Fällen eine knappe Kennung (Titel bzw. Name) des betroffenen
			// Datensatzes, ebenfalls nur für die bereits als sicher diffbar geprüften Objekttypen.
			$changes = match (true) {
				// 0.76.0: Marker – Klartext („Kostenträger: Sozialamt“) statt Feldvergleich.
				strtoupper($objectType) === 'LABEL' => $this->labelSummary($before, $after),
				$action === 'DELETED' && is_array($before) && $after === null => $this->contextSummary($objectType, $before),
				$action === 'CREATED' && is_array($after) && $before === null => $this->contextSummary($objectType, $after),
				default => $this->diff($objectType, $before, $after),
			};
			if ($this->isNoOpUpdate($action, $objectType, $before, $after, $changes)) {
				continue;
			}
			$history[] = [
				'id' => (int)$row['id'],
				'caseId' => (int)$row['case_id'],
				'type' => 'activity',
				'objectType' => $objectType,
				'objectId' => $row['object_id'] !== null ? (int)$row['object_id'] : null,
				'action' => (string)$row['action'],
				'title' => $this->title($objectType, (string)$row['action'], $changes),
				'userUid' => (string)$row['user_uid'],
				'date' => (string)$row['created_at'],
				'status' => 'PROTOKOLLIERT',
				'before' => $before,
				'after' => $after,
				'changes' => $changes,
				'immutable' => true,
			];
		}
		$legacyQuery = $this->db->getQueryBuilder();
		$legacy = $legacyQuery->select('*')->from('bestatter_records')
			->where($legacyQuery->expr()->eq('case_id', $legacyQuery->createNamedParameter($caseId)))
			->andWhere($legacyQuery->expr()->eq('record_type', $legacyQuery->createNamedParameter('activity')))
			->executeQuery()->fetchAllAssociative();
		foreach ($legacy as $row) $history[] = [
			'id' => 'legacy-' . (int)$row['id'], 'caseId' => $caseId, 'type' => 'activity',
			'objectType' => 'LEGACY_ACTIVITY', 'objectId' => (int)$row['id'], 'action' => 'DOCUMENTED',
			'title' => (string)$row['title'], 'userUid' => (string)($row['created_by'] ?? $row['owner_uid'] ?? 'system'),
			'date' => (string)($row['record_date'] ?: $row['created_at']), 'status' => 'PROTOKOLLIERT', 'changes' => [], 'immutable' => true,
		];
		usort($history, static fn(array $left, array $right): int => strcmp((string)$right['date'], (string)$left['date']));
		return $history;
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? 'system';
	}

	private function decode(mixed $value): mixed {
		if ($value === null || $value === '') return null;
		try { return json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR); }
		catch (\Throwable) { return (string)$value; }
	}

	private function title(string $type, string $action, array $changes = []): string {
		// Objekttyp-Bezeichnungen: 'CONTACT' war hier bisher nicht gelistet (ergab das nicht ins
		// deutsche Sprachbild passende "Contact") - beim Ergänzen der Änderungsliste mitkorrigiert.
		$objects = ['CASE' => 'Fall', 'TASK' => 'Aufgabe', 'SCHEDULE' => 'Termin', 'DOCUMENT' => 'Dokument', 'CONTACT' => 'Kontakt', 'SERVICE' => 'Leistung', 'QUOTE' => 'Kostenvoranschlag', 'ORDER' => 'Auftrag', 'INVOICE' => 'Rechnung', 'INCOMING_INVOICE' => 'Eingangsrechnung', 'DEREGISTRATION' => 'Abmeldung', 'WORKFLOW' => 'Workflow', 'PROCESS_ACTION' => 'Ablaufaktion', 'ASSISTANT' => 'Assistent', 'RETENTION' => 'Aufbewahrung', 'ARTICLE' => 'Artikel', 'ARTICLE_GROUP' => 'Warengruppe', 'ARTICLE_AREA' => 'Katalogbereich', 'ARTICLE_CATALOG' => 'Leistungskatalog', 'PRICE_LIST' => 'Preisliste', 'PRICE_LIST_ITEM' => 'Preis', 'LABEL' => 'Marker', 'LABEL_DEFINITION' => 'Marker (Customizing)'];
		$actions = ['CREATED' => 'angelegt', 'UPDATED' => 'geändert', 'DELETED' => 'gelöscht', 'VERSION_CREATED' => 'festgeschrieben', 'QUOTE_CONVERTED' => 'in Auftrag überführt', 'SELECTION_UPDATED' => 'Auswahl geändert', 'LIFECYCLE_UPDATED' => 'Status geändert', 'EXECUTED' => 'ausgeführt', 'FAILED' => 'fehlgeschlagen', 'INTENT_PREVIEWED' => 'Aktionsvorschlag geprüft', 'INFORMATION_REQUESTED' => 'Auskunft abgerufen', 'LEGAL_HOLD_SET' => 'Legal Hold gesetzt', 'LEGAL_HOLD_RELEASED' => 'Legal Hold aufgehoben', 'SET' => 'gesetzt', 'REMOVED' => 'entfernt', 'CHANGED' => 'geändert', 'ACTIVATED' => 'aktiviert'];
		$base = ($objects[strtoupper($type)] ?? ucfirst(strtolower($type))) . ' ' . ($actions[strtoupper($action)] ?? strtolower(str_replace('_', ' ', $action)));
		// Kundenwunsch 2026-09-21: Statt nur "Fall geändert" auch WELCHE Felder betroffen waren –
		// bei wenigen geänderten Feldern deren Bezeichnung, sonst nur die Anzahl (bleibt lesbar).
		$labels = array_values(array_filter(array_map(
			static fn(array $change): ?string => $change['key'] === '__truncated__' ? null : (string)$change['label'],
			$changes
		)));
		if ($labels === []) return $base;
		return $base . ' (' . (count($labels) <= 3 ? implode(', ', $labels) : count($labels) . ' Felder') . ')';
	}

	/**
	 * Kundenwunsch 2026-09-21: "wenn wirklich nichts verändert wurde, braucht das auch nicht im
	 * Fall-Verlauf erscheinen" - z. B. löst das debounced Autosave in bindMasterForm() bei jeder
	 * Unterbrechung einen vollständigen Stammdaten-Speichervorgang aus, auch ohne inhaltliche
	 * Änderung. Ein solcher echter No-Op wird ab sofort nicht mehr im Fall-Verlauf angezeigt.
	 * Wichtig: Der zugrunde liegende Audit-Log-Datensatz selbst (bestatter_audit_log, Hash-Chain)
	 * bleibt davon unberührt - er wird weiterhin unverändert geschrieben (siehe log()); gefiltert
	 * wird ausschließlich die Anzeige in caseHistory(), damit die lückenlose Nachweiskette
	 * technisch unangetastet bleibt. Greift bewusst nur, wenn beide Schnappschüsse tatsächlich als
	 * Arrays vorlagen (also ein echter, vollständiger Vergleich stattgefunden hat) und dieser
	 * Vergleich buchstäblich keine Änderung ergab - nicht bei fehlenden/unvollständigen Daten.
	 */
	private function isNoOpUpdate(string $action, string $objectType, mixed $before, mixed $after, array $changes): bool {
		return $action === 'UPDATED'
			&& in_array(strtoupper($objectType), self::DIFFABLE_TYPES, true)
			&& is_array($before) && is_array($after)
			&& $changes === [];
	}

	/** 0.76.0: Kurzbeschreibung einer Markeränderung für den Fall-Verlauf. */
	private function labelSummary(mixed $before, mixed $after): array {
		$text = static fn(mixed $value): string => is_array($value) ? (string)($value['text'] ?? $value['name'] ?? '') : '';
		$note = static fn(mixed $value): string => is_array($value) ? trim((string)($value['note'] ?? '')) : '';
		if (is_array($before) && is_array($after)) {
			// 0.77.0: Wert und Notiz getrennt; eine reine Notizänderung nennt den Marker als Kontext.
			$rows = $text($before) !== $text($after) ? [['key' => 'label', 'label' => 'Wert', 'before' => $text($before), 'after' => $text($after)]] : [['key' => '__context__', 'label' => '', 'before' => '', 'after' => 'Marker: ' . $text($after)]];
			if ($note($before) !== $note($after)) $rows[] = ['key' => 'note', 'label' => 'Notiz', 'before' => $note($before), 'after' => $note($after)];
			return $rows;
		}
		if (is_array($after)) return array_merge([['key' => '__context__', 'label' => '', 'before' => '', 'after' => 'Marker: ' . $text($after)]], $note($after) !== '' ? [['key' => 'note', 'label' => 'Notiz', 'before' => '', 'after' => $note($after)]] : []);
		if (is_array($before)) return array_merge([['key' => '__context__', 'label' => '', 'before' => '', 'after' => 'Marker: ' . $text($before)]], $note($before) !== '' ? [['key' => 'note', 'label' => 'Notiz', 'before' => $note($before), 'after' => '']] : []);
		return [];
	}

	/** Liefert bei CREATED/DELETED eine knappe Kennung des betroffenen Datensatzes (Titel bzw. Name), siehe Aufrufstelle in caseHistory(). */
	private function contextSummary(string $objectType, array $snapshot): array {
		$objectType = strtoupper($objectType);
		if (!in_array($objectType, self::DIFFABLE_TYPES, true)) return [];
		if ($objectType === 'CASE') {
			$name = trim(((string)($snapshot['firstName'] ?? '')) . ' ' . ((string)($snapshot['lastName'] ?? '')));
			return $name === '' ? [] : [['key' => '__context__', 'label' => '', 'before' => '', 'after' => "Verstorbene(r): {$name}"]];
		}
		$title = trim((string)($snapshot['title'] ?? ''));
		return $title === '' ? [] : [['key' => '__context__', 'label' => '', 'before' => '', 'after' => "Titel: {$title}"]];
	}

	/**
	 * Vergleicht zwei vollständige Vorher-/Nachher-Schnappschüsse desselben Datensatzes feldweise
	 * und liefert eine lesbare Änderungsliste [{key, label, before, after}, ...]. Nur für Objekttypen
	 * aktiv, bei denen before/after nachweislich dieselbe Struktur haben (siehe DIFFABLE_TYPES-Kommentar
	 * oben) – für alle anderen liefert diese Methode bewusst eine leere Liste statt eines irreführenden
	 * Vergleichs strukturell unterschiedlicher Auslöser-/Ergebnisdaten.
	 */
	private function diff(string $objectType, mixed $before, mixed $after): array {
		$objectType = strtoupper($objectType);
		if (!in_array($objectType, self::DIFFABLE_TYPES, true) || !is_array($before) || !is_array($after)) return [];
		$containerKey = self::CONTAINER_KEY[$objectType] ?? 'data';
		$ignore = self::TOP_LEVEL_IGNORE;
		if ($objectType === 'CASE') $ignore = array_merge($ignore, self::CASE_DUPLICATE_TOP_LEVEL_KEYS);
		$flatBefore = $this->flatten($before, $containerKey, $ignore);
		$flatAfter = $this->flatten($after, $containerKey, $ignore);
		$changes = [];
		foreach (array_unique([...array_keys($flatBefore), ...array_keys($flatAfter)]) as $key) {
			$beforeValue = $flatBefore[$key] ?? null;
			$afterValue = $flatAfter[$key] ?? null;
			if ($this->normalize($beforeValue) === $this->normalize($afterValue)) continue;
			$changes[] = ['key' => $key, 'label' => $this->fieldLabel($objectType, $key), 'before' => $this->formatValue($beforeValue), 'after' => $this->formatValue($afterValue)];
		}
		if (count($changes) > self::MAX_CHANGES) {
			$remaining = count($changes) - self::MAX_CHANGES;
			$changes = array_slice($changes, 0, self::MAX_CHANGES);
			$changes[] = ['key' => '__truncated__', 'label' => '', 'before' => '', 'after' => "… und {$remaining} weitere Felder geändert"];
		}
		return $changes;
	}

	/** Reduziert einen verschachtelten Datensatz auf eine flache key => Wert-Liste (String/Zahl/Bool/null/einfache Liste). */
	private function flatten(array $data, string $containerKey, array $ignoreTopLevel): array {
		$flat = [];
		foreach ($data as $key => $value) {
			if ($key === $containerKey && is_array($value)) {
				foreach ($value as $innerKey => $innerValue) {
					if (is_array($innerValue) && !$this->isSimpleList($innerValue)) continue; // tiefer geschachtelte Strukturen (z. B. Nebenaufträge) hier nicht aufgelöst
					$flat[(string)$innerKey] = $innerValue;
				}
				continue;
			}
			if (in_array($key, $ignoreTopLevel, true)) continue;
			if (is_array($value) && !$this->isSimpleList($value)) continue;
			$flat[(string)$key] = $value;
		}
		return $flat;
	}

	/** Ein Array gilt als "einfache Liste", wenn alle Elemente selbst Skalare sind (z. B. ausgewählte Ressourcen-Schlüssel). */
	private function isSimpleList(array $value): bool {
		foreach ($value as $item) if (is_array($item)) return false;
		return true;
	}

	private function normalize(mixed $value): string {
		if ($value === null) return '';
		if (is_bool($value)) return $value ? '1' : '0';
		if (is_array($value)) return implode('|', array_map(static fn(mixed $item): string => (string)$item, $value));
		return trim((string)$value);
	}

	private function formatValue(mixed $value): string {
		if ($value === null || $value === '' || $value === []) return '–';
		if (is_bool($value)) return $value ? 'Ja' : 'Nein';
		if (is_array($value)) return implode(', ', array_map(static fn(mixed $item): string => (string)$item, $value));
		$text = (string)$value;
		return mb_strlen($text) > 160 ? mb_substr($text, 0, 160) . '…' : $text;
	}

	private function fieldLabel(string $objectType, string $key): string {
		if ($objectType === 'CASE' && isset($this->caseFieldLabels()[$key])) return $this->caseFieldLabels()[$key];
		if (isset(self::FIELD_LABELS[$key])) return self::FIELD_LABELS[$key];
		return trim((string)preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace('_', ' ', $key)));
	}

	/** @return array<string, string> key => Anzeigebezeichnung, aus resources/case-field-schema.json (dieselbe Quelle wie CustomizingService/DocumentService). */
	private function caseFieldLabels(): array {
		if ($this->caseFieldLabelsCache === null) {
			$this->caseFieldLabelsCache = [];
			foreach ($this->customizing->caseFieldSchema() as $entry) {
				if (isset($entry['key'], $entry['label'])) $this->caseFieldLabelsCache[(string)$entry['key']] = (string)$entry['label'];
			}
		}
		return $this->caseFieldLabelsCache;
	}
}
