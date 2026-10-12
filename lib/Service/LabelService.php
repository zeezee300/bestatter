<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Marker am Fall (0.76.0, Stufe 1).
 *
 * - Definitionen pflegen Bestatter-Administratoren unter Customizing → Marker (Ja/Nein oder Liste,
 *   Farbe aus fester Palette, Symbol aus fester Auswahl, stabiler technischer Schlüssel).
 * - Mitgelieferte Marker (resources/labels.json) entstehen inaktiv und werden bei der Einrichtung
 *   ausgewählt; sie lassen sich nur deaktivieren, nicht löschen.
 * - Gesetzt werden Marker von jedem Mitglied der Bestatter-Gruppe; jede Änderung steht im Fall-Verlauf.
 * - Automatische Hinweise (LabelRules::HINTS) werden nur angezeigt und nie gespeichert.
 * - 0.77.0: Je gesetztem Marker eine interne Notiz (höchstens 500 Zeichen). Sie steht im Fall-Verlauf, im
 *   Fallexport und in der Beschreibung der Ablaufaufgabe, die der Marker ausgelöst hat – nie in Dokumentvorlagen.
 *   Beim Entfernen des Markers und beim Anonymisieren wird sie gelöscht.
 *
 * Bewusst ohne Abhängigkeit zu CaseService/ProcessService, damit diese die Marker nutzen können.
 */
class LabelService {
	private bool $seeded = false;
	/** @var array<string,array>|null */
	private ?array $definitionCache = null;

	public function __construct(
		private IDBConnection $db,
		private AuditService $audit,
		private TeamService $team,
		private IUserSession $userSession,
		private IUserManager $users,
	) {}

	// ------------------------------------------------------------------ Definitionen

	public function ensureSeedData(): void {
		if ($this->seeded) return;
		$this->seeded = true;
		$bundle = json_decode((string)file_get_contents(LabelRules::BUNDLE_FILE), true, 512, JSON_THROW_ON_ERROR);
		$existing = $this->rowsByKey();
		$sort = $existing === [] ? 0 : max(array_map(static fn(array $row): int => (int)$row['sort_order'], $existing));
		foreach ((array)($bundle['labels'] ?? []) as $label) {
			if (isset($existing[strtoupper((string)$label['key'])])) continue;
			$definition = LabelRules::normalizeDefinition($label + ['active' => false]);
			$sort += 10;
			$this->insertDefinition($definition, 'BUNDLED', $sort);
		}
		$this->definitionCache = null;
	}

	/** Alle Definitionen (für Customizing, Abläufe und das Auswahlfenster) einschließlich Nutzung. */
	public function definitions(bool $activeOnly = false): array {
		$this->ensureSeedData();
		$all = array_values($this->definitionMap());
		$usage = $this->usageCounts();
		$all = array_map(static fn(array $definition): array => $definition + ['usage' => $usage[$definition['id']] ?? 0], $all);
		return $activeOnly ? array_values(array_filter($all, static fn(array $definition): bool => $definition['active'])) : $all;
	}

	/** Klartextnamen je Schlüssel (für Abläufe; ohne Nutzungszählung). @return array<string,string> */
	public function names(): array {
		$this->ensureSeedData();
		return array_map(static fn(array $definition): string => $definition['name'], $this->definitionMap());
	}

	/** Antwort für GET /api/labels: Definitionen, Palette, Symbolauswahl und automatische Hinweise. */
	public function catalog(): array {
		return [
			'labels' => $this->definitions(),
			'colors' => LabelRules::COLORS,
			'kinds' => LabelRules::KINDS,
			'noteModes' => LabelRules::NOTE_MODES,
			'noteMax' => LabelRules::NOTE_MAX,
			'hints' => array_map(static fn(string $key, array $hint): array => ['key' => $key] + $hint, array_keys(LabelRules::HINTS), LabelRules::HINTS),
			'maxActive' => LabelRules::MAX_ACTIVE,
			'canEdit' => $this->team->isBestatterAdmin(),
			// Symbolauswahl (resources/label-icons.json): einzige Quelle für Oberfläche und Prüfung im Server.
			'icons' => LabelRules::iconCatalog(),
		];
	}

	public function create(array $input): array {
		$this->team->requireBestatterAdmin();
		$this->ensureSeedData();
		$definition = LabelRules::normalizeDefinition($input);
		if (isset($this->definitionMap()[$definition['key']])) throw new \InvalidArgumentException('Einen Marker mit dem Schlüssel „' . $definition['key'] . '“ gibt es bereits.');
		$this->assertUniqueName($definition['name'], '');
		$rows = $this->rowsByKey();
		$sort = ($rows === [] ? 0 : max(array_map(static fn(array $row): int => (int)$row['sort_order'], $rows))) + 10;
		$this->insertDefinition($definition, 'CUSTOM', $sort);
		$this->definitionCache = null;
		$created = $this->definition($definition['key']);
		$this->audit->logSystem('LABEL_DEFINITION', 'CREATED', $this->auditDefinition($created));
		return $created;
	}

	public function update(string $key, array $input): array {
		$this->team->requireBestatterAdmin();
		$existing = $this->definition($key);
		$definition = LabelRules::normalizeDefinition($input, $existing);
		$this->assertUniqueName($definition['name'], $existing['key']);
		$this->db->beginTransaction();
		try {
			$query = $this->db->getQueryBuilder();
			$query->update('bestatter_labels')
				->set('name', $query->createNamedParameter($definition['name']))
				->set('description', $query->createNamedParameter($definition['description'] !== '' ? $definition['description'] : null))
				->set('color', $query->createNamedParameter($definition['color']))
				->set('icon', $query->createNamedParameter($definition['icon']))
				->set('active', $query->createNamedParameter($definition['active'] ? 1 : 0, IQueryBuilder::PARAM_INT))
				->set('keep_on_anonymize', $query->createNamedParameter($definition['keepOnAnonymize'] ? 1 : 0, IQueryBuilder::PARAM_INT))
				->set('note_mode', $query->createNamedParameter($definition['noteMode']))
				->set('note_hint', $query->createNamedParameter($definition['noteHint'] !== '' ? $definition['noteHint'] : null))
				->set('updated_at', $query->createNamedParameter(date('c')))
				->where($query->expr()->eq('id', $query->createNamedParameter($existing['id'], IQueryBuilder::PARAM_INT)))
				->executeStatement();
			if ($existing['kind'] === 'LIST') $this->replaceValues($existing['id'], $definition['values'], $existing['values']);
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		$this->definitionCache = null;
		$updated = $this->definition($key);
		$this->audit->logSystem('LABEL_DEFINITION', 'UPDATED', ['before' => $this->auditDefinition($existing), 'after' => $this->auditDefinition($updated)]);
		return $updated;
	}

	/** Eigene, nie verwendete Marker lassen sich löschen; alles andere wird deaktiviert. */
	public function delete(string $key): void {
		$this->team->requireBestatterAdmin();
		$existing = $this->definition($key);
		if ($existing['origin'] === 'BUNDLED') throw new \InvalidArgumentException('Mitgelieferte Marker können nur deaktiviert werden.');
		if (($this->usageCounts(true)[$existing['id']] ?? 0) > 0) throw new \InvalidArgumentException('Der Marker wurde schon in Fällen verwendet und kann nur deaktiviert werden.');
		$this->db->beginTransaction();
		try {
			foreach (['bestatter_label_values' => 'label_id', 'bestatter_case_labels' => 'label_id', 'bestatter_labels' => 'id'] as $table => $column) {
				$query = $this->db->getQueryBuilder();
				$query->delete($table)->where($query->expr()->eq($column, $query->createNamedParameter($existing['id'], IQueryBuilder::PARAM_INT)))->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		$this->definitionCache = null;
		$this->audit->logSystem('LABEL_DEFINITION', 'DELETED', $this->auditDefinition($existing));
	}

	/**
	 * Aktiviert Marker (Ersteinrichtung, Customizing, occ bestatter:labels). ['*'] = alle mitgelieferten.
	 * @param list<string> $keys
	 * @return list<string> tatsächlich aktivierte Schlüssel
	 */
	public function activate(array $keys, bool $requireAdmin = true): array {
		if ($requireAdmin) $this->team->requireBestatterAdmin();
		$this->ensureSeedData();
		$map = $this->definitionMap();
		if (in_array('*', $keys, true)) $keys = array_keys(array_filter($map, static fn(array $definition): bool => $definition['origin'] === 'BUNDLED'));
		$activated = [];
		foreach (array_unique(array_map(static fn(mixed $key): string => strtoupper(trim((string)$key)), $keys)) as $key) {
			if (!isset($map[$key])) throw new \InvalidArgumentException('Unbekannter Marker „' . $key . '“.');
			if ($map[$key]['active']) continue;
			$query = $this->db->getQueryBuilder();
			$query->update('bestatter_labels')->set('active', $query->createNamedParameter(1, IQueryBuilder::PARAM_INT))->set('updated_at', $query->createNamedParameter(date('c')))
				->where($query->expr()->eq('id', $query->createNamedParameter($map[$key]['id'], IQueryBuilder::PARAM_INT)))->executeStatement();
			$activated[] = $key;
		}
		$this->definitionCache = null;
		if ($activated !== []) $this->audit->logSystem('LABEL_DEFINITION', 'ACTIVATED', ['keys' => $activated]);
		return $activated;
	}

	/** @param list<string> $keys gewünschte Reihenfolge */
	public function reorder(array $keys): array {
		$this->team->requireBestatterAdmin();
		$map = $this->definitionMap();
		$position = 0;
		foreach (array_values(array_unique(array_map(static fn(mixed $key): string => strtoupper(trim((string)$key)), $keys))) as $key) {
			if (!isset($map[$key])) continue;
			$position += 10;
			$query = $this->db->getQueryBuilder();
			$query->update('bestatter_labels')->set('sort_order', $query->createNamedParameter($position, IQueryBuilder::PARAM_INT))
				->where($query->expr()->eq('id', $query->createNamedParameter($map[$key]['id'], IQueryBuilder::PARAM_INT)))->executeStatement();
		}
		$this->definitionCache = null;
		return $this->definitions();
	}

	public function definition(string $key): array {
		$this->ensureSeedData();
		$key = strtoupper(trim($key));
		return $this->definitionMap()[$key] ?? throw new \InvalidArgumentException('Den Marker „' . $key . '“ gibt es nicht.');
	}

	// ------------------------------------------------------------------ Marker am Fall

	/** Gesetzte Marker und automatische Hinweise eines Falls. */
	public function forCase(int $caseId): array {
		$this->ensureSeedData();
		return ['labels' => $this->forCases([$caseId])[$caseId] ?? [], 'hints' => $this->hintsForCases([$caseId])[$caseId] ?? []];
	}

	/**
	 * Gesetzte (nicht entfernte) Marker je Fall, sortiert wie im Customizing.
	 * Marker, die inzwischen deaktiviert wurden, bleiben sichtbar (mit inactive = true).
	 * @param list<int> $caseIds
	 * @return array<int,list<array>>
	 */
	public function forCases(array $caseIds): array {
		$caseIds = array_values(array_unique(array_filter(array_map('intval', $caseIds))));
		if ($caseIds === []) return [];
		$byId = [];
		foreach ($this->definitionMap() as $definition) $byId[$definition['id']] = $definition;
		$result = [];
		foreach (array_chunk($caseIds, 500) as $chunk) {
			$query = $this->db->getQueryBuilder();
			$rows = $query->select('*')->from('bestatter_case_labels')
				->where($query->expr()->in('case_id', $query->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($query->expr()->isNull('removed_at'))
				->executeQuery()->fetchAllAssociative();
			foreach ($rows as $row) {
				$definition = $byId[(int)$row['label_id']] ?? null;
				if ($definition === null) continue;
				$result[(int)$row['case_id']][] = $this->assignmentView($definition, $row);
			}
		}
		foreach ($result as &$labels) usort($labels, static fn(array $left, array $right): int => [$left['sortOrder'], $left['name']] <=> [$right['sortOrder'], $right['name']]);
		unset($labels);
		return $result;
	}

	/**
	 * Automatische Hinweise je Fall (aus Stammdaten und offenen Rechnungen).
	 * @param list<int> $caseIds
	 * @return array<int,list<array>>
	 */
	public function hintsForCases(array $caseIds): array {
		$caseIds = array_values(array_unique(array_filter(array_map('intval', $caseIds))));
		if ($caseIds === []) return [];
		$today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
		$masters = []; $overdue = [];
		foreach (array_chunk($caseIds, 500) as $chunk) {
			$query = $this->db->getQueryBuilder();
			foreach ($query->select('id', 'master_data', 'anonymized_at')->from('bestatter_cases')->where($query->expr()->in('id', $query->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))->executeQuery()->fetchAllAssociative() as $row) {
				if (trim((string)($row['anonymized_at'] ?? '')) !== '') continue;
				$masters[(int)$row['id']] = json_decode((string)($row['master_data'] ?? '{}'), true) ?: [];
			}
			$invoices = $this->db->getQueryBuilder();
			$rows = $invoices->select('case_id', 'created_at', 'payment_snapshot')->from('bestatter_invoices')
				->where($invoices->expr()->in('case_id', $invoices->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($invoices->expr()->in('status', $invoices->createNamedParameter(['FREIGEGEBEN', 'VERSENDET', 'TEILBEZAHLT'], IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($invoices->expr()->eq('document_kind', $invoices->createNamedParameter('INVOICE')))
				->executeQuery()->fetchAllAssociative();
			foreach ($rows as $row) {
				$snapshot = json_decode((string)($row['payment_snapshot'] ?? ''), true);
				$term = is_array($snapshot) && isset($snapshot['paymentTermDays']) ? (int)$snapshot['paymentTermDays'] : 14;
				if (LabelRules::invoiceOverdue($this->localDate((string)$row['created_at']), $term, $today)) $overdue[(int)$row['case_id']] = true;
			}
		}
		$result = [];
		foreach ($masters as $caseId => $master) {
			$hints = LabelRules::hints($master, isset($overdue[$caseId]));
			if ($hints !== []) $result[$caseId] = $hints;
		}
		return $result;
	}

	/**
	 * Setzt einen Marker (bzw. ändert den Wert eines Listen-Markers). Ergebnis: Marker und Hinweise des Falls.
	 * @param string|null $note null = Notiz unverändert lassen (bzw. keine beim ersten Setzen), '' = Notiz löschen
	 */
	public function set(int $caseId, string $key, string $valueKey = '', ?string $note = null): array {
		$case = $this->writableCase($caseId);
		$definition = $this->definition($key);
		if (!$definition['active']) throw new \InvalidArgumentException('Der Marker „' . $definition['name'] . '“ ist deaktiviert.');
		$valueKey = strtoupper(trim($valueKey));
		$valueName = '';
		if ($definition['kind'] === 'LIST') {
			foreach ($definition['values'] as $value) if ($value['key'] === $valueKey && $value['active']) $valueName = $value['name'];
			if ($valueName === '') throw new \InvalidArgumentException('Bitte einen gültigen Wert für „' . $definition['name'] . '“ wählen.');
		} else {
			$valueKey = '';
		}
		$existing = $this->assignmentRow((int)$case['id'], $definition['id']);
		$before = $existing !== null && $existing['removed_at'] === null ? $this->assignmentView($definition, $existing) : null;
		$newNote = $note === null ? ($before['note'] ?? '') : LabelRules::normalizeNote($note);
		$noteChanged = $newNote !== ($before['note'] ?? '');
		$valueChanged = $before === null || (string)($existing['value_key'] ?? '') !== $valueKey;
		if (!$valueChanged && !$noteChanged) return $this->forCase($caseId);
		$this->assertNoteAllowed($definition, $newNote);
		$now = date('c');
		$uid = $this->uid();
		$noteBy = $noteChanged ? ($newNote !== '' ? $uid : null) : ($existing['note_by'] ?? null);
		$noteAt = $noteChanged ? ($newNote !== '' ? $now : null) : ($existing['note_at'] ?? null);
		if ($existing === null) {
			$query = $this->db->getQueryBuilder();
			$query->insert('bestatter_case_labels')->values([
				'case_id' => $query->createNamedParameter((int)$case['id'], IQueryBuilder::PARAM_INT),
				'label_id' => $query->createNamedParameter($definition['id'], IQueryBuilder::PARAM_INT),
				'value_key' => $query->createNamedParameter($valueKey !== '' ? $valueKey : null),
				'set_at' => $query->createNamedParameter($now),
				'set_by' => $query->createNamedParameter($uid),
				'note' => $query->createNamedParameter($newNote !== '' ? $newNote : null),
				'note_by' => $query->createNamedParameter($noteBy),
				'note_at' => $query->createNamedParameter($noteAt),
			])->executeStatement();
		} else {
			$query = $this->db->getQueryBuilder();
			$query->update('bestatter_case_labels')
				->set('value_key', $query->createNamedParameter($valueKey !== '' ? $valueKey : null))
				->set('note', $query->createNamedParameter($newNote !== '' ? $newNote : null))
				->set('note_by', $query->createNamedParameter($noteBy))
				->set('note_at', $query->createNamedParameter($noteAt));
			// Eine reine Notizänderung lässt „gesetzt von/am“ stehen.
			if ($valueChanged) $query->set('set_at', $query->createNamedParameter($now))->set('set_by', $query->createNamedParameter($uid));
			$query->set('removed_at', $query->createNamedParameter(null))
				->set('removed_by', $query->createNamedParameter(null))
				->where($query->expr()->eq('id', $query->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		$after = ['key' => $definition['key'], 'name' => $definition['name'], 'value' => $valueKey, 'valueName' => $valueName, 'text' => LabelRules::displayText($definition['name'], $definition['kind'], $valueName), 'note' => $newNote];
		$this->audit->log((int)$case['id'], 'LABEL', $definition['id'], $before === null ? 'SET' : 'CHANGED', $before === null ? null : $this->auditAssignment($before), $after);
		return $this->forCase($caseId);
	}

	/** Ändert nur die Notiz eines gesetzten Markers ('' löscht sie). */
	public function setNote(int $caseId, string $key, string $note): array {
		$this->writableCase($caseId);
		$definition = $this->definition($key);
		$existing = $this->assignmentRow($caseId, $definition['id']);
		if ($existing === null || $existing['removed_at'] !== null) throw new \InvalidArgumentException('Der Marker „' . $definition['name'] . '“ ist an diesem Fall nicht gesetzt.');
		if (!$definition['active']) throw new \InvalidArgumentException('Der Marker „' . $definition['name'] . '“ ist deaktiviert.');
		return $this->set($caseId, $definition['key'], (string)($existing['value_key'] ?? ''), $note);
	}

	/**
	 * Marker aus dem Anlagedialog eines neuen Falls übernehmen. Ungültige Angaben verhindern die Fallanlage
	 * nicht, sondern werden als Hinweis zurückgegeben.
	 * @return list<string> Hinweise
	 */
	public function setInitial(int $caseId, array $labels): array {
		$warnings = [];
		foreach ($labels as $label) {
			if (!is_array($label)) continue;
			try { $this->set($caseId, (string)($label['key'] ?? ''), (string)($label['value'] ?? ''), isset($label['note']) ? (string)$label['note'] : null); }
			catch (\InvalidArgumentException $error) { $warnings[] = $error->getMessage(); }
		}
		return $warnings;
	}

	public function remove(int $caseId, string $key): array {
		$case = $this->writableCase($caseId);
		$definition = $this->definition($key);
		$existing = $this->assignmentRow((int)$case['id'], $definition['id']);
		if ($existing === null || $existing['removed_at'] !== null) return $this->forCase($caseId);
		$before = $this->assignmentView($definition, $existing);
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_case_labels')
			->set('removed_at', $query->createNamedParameter(date('c')))
			->set('removed_by', $query->createNamedParameter($this->uid()))
			// Die Notiz gehört zum gesetzten Marker; nachvollziehbar bleibt sie nur im Fall-Verlauf.
			->set('note', $query->createNamedParameter(null))
			->set('note_by', $query->createNamedParameter(null))
			->set('note_at', $query->createNamedParameter(null))
			->where($query->expr()->eq('id', $query->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$this->audit->log((int)$case['id'], 'LABEL', $definition['id'], 'REMOVED', $this->auditAssignment($before), null);
		return $this->forCase($caseId);
	}

	/**
	 * Fall-IDs, die ALLE Filter erfüllen (UND). Leere Filterliste = null (kein Filter).
	 * @param list<array{key:string,value:string}> $filters
	 * @return list<int>|null
	 */
	public function caseIdsForFilter(array $filters): ?array {
		if ($filters === []) return null;
		$this->ensureSeedData();
		$map = $this->definitionMap();
		$ids = [];
		foreach (array_values($filters) as $index => $filter) {
			$definition = $map[$filter['key']] ?? null;
			if ($definition === null) return [];
			$query = $this->db->getQueryBuilder();
			$query->select('case_id')->from('bestatter_case_labels')
				->where($query->expr()->eq('label_id', $query->createNamedParameter($definition['id'], IQueryBuilder::PARAM_INT)))
				->andWhere($query->expr()->isNull('removed_at'));
			if ($filter['value'] !== '') $query->andWhere($query->expr()->eq('value_key', $query->createNamedParameter($filter['value'])));
			$found = array_map('intval', $query->executeQuery()->fetchFirstColumn());
			$ids = $index === 0 ? $found : array_values(array_intersect($ids, $found));
			if ($ids === []) return [];
		}
		return array_values(array_unique($ids));
	}

	/**
	 * Fallsuche: Fall-IDs je Suchbegriff, deren gesetzte Marker den Begriff in der Notiz enthalten.
	 * @param list<string> $terms
	 * @return array<string,list<int>>
	 */
	public function caseIdsForNoteTerms(array $terms): array {
		$result = [];
		foreach ($terms as $term) {
			$term = trim($term);
			if ($term === '' || isset($result[$term])) continue;
			$query = $this->db->getQueryBuilder();
			$query->selectDistinct('case_id')->from('bestatter_case_labels')
				->where($query->expr()->isNull('removed_at'))
				->andWhere($query->expr()->isNotNull('note'))
				->andWhere($query->expr()->iLike('note', $query->createNamedParameter('%' . $this->db->escapeLikeParameter($term) . '%')));
			$result[$term] = array_map('intval', $query->executeQuery()->fetchFirstColumn());
		}
		return $result;
	}

	/**
	 * Fakten für Abläufe: gesetzte und entfernte Marker.
	 * @return array{labels:array<string,array{value:string,setAt:string,note:string,name:string}>,removedLabels:array<string,string>}
	 */
	public function processFacts(int $caseId): array {
		$this->ensureSeedData();
		$byId = [];
		foreach ($this->definitionMap() as $definition) $byId[$definition['id']] = $definition;
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_case_labels')->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchAllAssociative();
		$labels = []; $removed = [];
		foreach ($rows as $row) {
			$definition = $byId[(int)$row['label_id']] ?? null;
			if ($definition === null) continue;
			if ($row['removed_at'] === null) $labels[$definition['key']] = ['value' => (string)($row['value_key'] ?? ''), 'setAt' => $this->localDate((string)$row['set_at']), 'note' => (string)($row['note'] ?? ''), 'name' => $definition['name']];
			else $removed[$definition['key']] = $this->localDate((string)$row['removed_at']);
		}
		return ['labels' => $labels, 'removedLabels' => $removed];
	}

	/** Platzhalter für Dokumentvorlagen: {{marker}} und {{marker.SCHLUESSEL}} (ja / Wert / leer). */
	public function placeholderValues(int $caseId): array {
		if ($caseId <= 0) return [];
		$this->ensureSeedData();
		$set = [];
		foreach ($this->forCases([$caseId])[$caseId] ?? [] as $label) $set[$label['key']] = $label;
		$values = ['{{marker}}' => implode(', ', array_map(static fn(array $label): string => $label['text'], array_values($set)))];
		foreach ($this->definitionMap() as $definition) {
			$label = $set[$definition['key']] ?? null;
			$values['{{marker.' . $definition['key'] . '}}'] = $label === null ? '' : ($definition['kind'] === 'LIST' ? $label['valueName'] : 'ja');
		}
		// 0.77.1: Umbenennung „Etiketten“ → „Marker“ – die bisherigen Platzhalter funktionieren weiter.
		foreach ($values as $placeholder => $value) $values[self::legacyPlaceholder($placeholder)] = $value;
		return $values;
	}

	/**
	 * Bis 0.77.0 hießen die Platzhalter {{etiketten}} und {{etikett.SCHLUESSEL}}. Sie werden weiter gefüllt und bei der
	 * Vorlagenprüfung akzeptiert, stehen aber nicht mehr im Feldkatalog.
	 * @return list<string>
	 */
	public function legacyPlaceholders(): array {
		$this->ensureSeedData();
		return array_merge(['{{etiketten}}'], array_map(static fn(string $key): string => '{{etikett.' . $key . '}}', array_keys($this->definitionMap())));
	}

	private static function legacyPlaceholder(string $placeholder): string {
		return $placeholder === '{{marker}}' ? '{{etiketten}}' : str_replace('{{marker.', '{{etikett.', $placeholder);
	}

	/** Platzhalterkatalog (Customizing → Dokumentvorlagen). */
	public function placeholderCatalog(): array {
		$this->ensureSeedData();
		$result = [['placeholder' => '{{marker}}', 'name' => 'Gesetzte Marker (kommagetrennt)', 'source' => 'Marker']];
		foreach ($this->definitionMap() as $definition) $result[] = ['placeholder' => '{{marker.' . $definition['key'] . '}}', 'name' => 'Marker „' . $definition['name'] . '“ (' . ($definition['kind'] === 'LIST' ? 'gewählter Wert' : '„ja“') . ' oder leer)', 'source' => 'Marker'];
		return $result;
	}

	/** Zeilen für den Fallexport (Marker mit Klartext). */
	public function exportRows(int $caseId): array {
		$this->ensureSeedData();
		$byId = [];
		foreach ($this->definitionMap() as $definition) $byId[$definition['id']] = $definition;
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_case_labels')->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		return array_map(static function (array $row) use ($byId): array {
			$definition = $byId[(int)$row['label_id']] ?? null;
			return $row + ['label_key' => $definition['key'] ?? '', 'label_name' => $definition['name'] ?? ''];
		}, $rows);
	}

	/**
	 * Anonymisierung: Zuordnungen ohne „bei Anonymisierung behalten“ werden gelöscht, behaltene verlieren
	 * die Benutzerkennungen und immer ihre Notiz (Freitext). Entfernte (historische) Zuordnungen werden immer gelöscht.
	 */
	public function anonymizeCase(int $caseId): int {
		$keep = array_map(static fn(array $definition): int => $definition['id'], array_filter($this->definitionMap(), static fn(array $definition): bool => $definition['keepOnAnonymize']));
		$query = $this->db->getQueryBuilder();
		$query->delete('bestatter_case_labels')->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)));
		$query->andWhere($query->expr()->orX(
			$query->expr()->isNotNull('removed_at'),
			...($keep === [] ? [$query->expr()->eq('case_id', $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT))] : [$query->expr()->notIn('label_id', $query->createNamedParameter(array_values($keep), IQueryBuilder::PARAM_INT_ARRAY))]),
		));
		$deleted = $query->executeStatement();
		$clear = $this->db->getQueryBuilder();
		$clear->update('bestatter_case_labels')->set('set_by', $clear->createNamedParameter(null))->set('removed_by', $clear->createNamedParameter(null))
			->set('note', $clear->createNamedParameter(null))->set('note_by', $clear->createNamedParameter(null))->set('note_at', $clear->createNamedParameter(null))
			->where($clear->expr()->eq('case_id', $clear->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))->executeStatement();
		return $deleted;
	}

	// ------------------------------------------------------------------ Austausch (Einrichtungspaket)

	public const EXCHANGE_FORMAT = 'bestatter-labels';

	public function exportExchange(): string {
		$labels = array_map(static fn(array $definition): array => [
			'key' => $definition['key'], 'name' => $definition['name'], 'description' => $definition['description'], 'kind' => $definition['kind'],
			'color' => $definition['color'], 'icon' => $definition['icon'], 'active' => $definition['active'], 'keepOnAnonymize' => $definition['keepOnAnonymize'],
			'noteMode' => $definition['noteMode'], 'noteHint' => $definition['noteHint'],
			'values' => array_map(static fn(array $value): array => ['key' => $value['key'], 'name' => $value['name'], 'active' => $value['active']], $definition['values']),
		], $this->definitions());
		return json_encode(['format' => self::EXCHANGE_FORMAT, 'formatVersion' => 1, 'labels' => $labels], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	/** @return array{labels:list<array>,new:int,existing:int} */
	public function parseExchange(string $content): array {
		$this->ensureSeedData();
		$data = json_decode($content, true);
		if (!is_array($data) || ($data['format'] ?? '') !== self::EXCHANGE_FORMAT) throw new \InvalidArgumentException('Keine Marker im erwarteten Format.');
		$labels = []; $new = 0; $existing = 0;
		$map = $this->definitionMap();
		foreach ((array)($data['labels'] ?? []) as $raw) {
			if (!is_array($raw)) continue;
			$current = $map[strtoupper((string)($raw['key'] ?? ''))] ?? null;
			if ($current !== null && $current['kind'] !== strtoupper((string)($raw['kind'] ?? ''))) throw new \InvalidArgumentException('Der Marker „' . $current['key'] . '“ hat hier eine andere Art (Ja/Nein bzw. Liste).');
			$labels[] = LabelRules::normalizeDefinition($raw, $current);
			$current === null ? $new++ : $existing++;
		}
		return ['labels' => $labels, 'new' => $new, 'existing' => $existing];
	}

	/** Übernimmt Definitionen aus einem Einrichtungspaket (neu anlegen bzw. aktualisieren, Aktiv-Schalter wie im Paket). */
	public function importExchange(string $content): array {
		$this->team->requireBestatterAdmin();
		$this->ensureSeedData();
		$parsed = $this->parseExchange($content);
		$created = 0; $updated = 0;
		foreach ($parsed['labels'] as $definition) {
			if (isset($this->definitionMap()[$definition['key']])) { $this->update($definition['key'], $definition); $updated++; }
			else { $this->create($definition); $created++; }
		}
		return ['created' => $created, 'updated' => $updated];
	}

	// ------------------------------------------------------------------ intern

	/** @return array<string,array> nach Schlüssel */
	private function definitionMap(): array {
		if ($this->definitionCache !== null) return $this->definitionCache;
		$rows = $this->rowsByKey();
		$values = [];
		if ($rows !== []) {
			$query = $this->db->getQueryBuilder();
			foreach ($query->select('*')->from('bestatter_label_values')->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC')->executeQuery()->fetchAllAssociative() as $row) {
				$values[(int)$row['label_id']][] = ['key' => (string)$row['value_key'], 'name' => (string)$row['name'], 'sortOrder' => (int)$row['sort_order'], 'active' => (int)$row['active'] === 1];
			}
		}
		$icons = LabelRules::iconKeys();
		$map = [];
		uasort($rows, static fn(array $left, array $right): int => [(int)$left['sort_order'], (string)$left['name']] <=> [(int)$right['sort_order'], (string)$right['name']]);
		foreach ($rows as $row) {
			$icon = (string)$row['icon'];
			$map[(string)$row['label_key']] = [
				'id' => (int)$row['id'], 'key' => (string)$row['label_key'], 'name' => (string)$row['name'], 'description' => (string)($row['description'] ?? ''),
				'kind' => (string)$row['kind'], 'color' => isset(LabelRules::COLORS[(string)$row['color']]) ? (string)$row['color'] : 'SLATE',
				'icon' => in_array($icon, $icons, true) ? $icon : LabelRules::FALLBACK_ICON, 'sortOrder' => (int)$row['sort_order'],
				'active' => (int)$row['active'] === 1, 'keepOnAnonymize' => (int)$row['keep_on_anonymize'] === 1, 'origin' => (string)$row['origin'],
				'noteMode' => isset(LabelRules::NOTE_MODES[(string)($row['note_mode'] ?? '')]) ? (string)$row['note_mode'] : 'OPTIONAL', 'noteHint' => (string)($row['note_hint'] ?? ''),
				'values' => $values[(int)$row['id']] ?? [],
			];
		}
		return $this->definitionCache = $map;
	}

	/** @return array<string,array> */
	private function rowsByKey(): array {
		$query = $this->db->getQueryBuilder();
		$rows = [];
		foreach ($query->select('*')->from('bestatter_labels')->executeQuery()->fetchAllAssociative() as $row) $rows[(string)$row['label_key']] = $row;
		return $rows;
	}

	private function insertDefinition(array $definition, string $origin, int $sortOrder): void {
		$now = date('c');
		$query = $this->db->getQueryBuilder();
		$query->insert('bestatter_labels')->values([
			'label_key' => $query->createNamedParameter($definition['key']),
			'name' => $query->createNamedParameter($definition['name']),
			'description' => $query->createNamedParameter($definition['description'] !== '' ? $definition['description'] : null),
			'kind' => $query->createNamedParameter($definition['kind']),
			'color' => $query->createNamedParameter($definition['color']),
			'icon' => $query->createNamedParameter($definition['icon']),
			'sort_order' => $query->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT),
			'active' => $query->createNamedParameter($definition['active'] ? 1 : 0, IQueryBuilder::PARAM_INT),
			'keep_on_anonymize' => $query->createNamedParameter($definition['keepOnAnonymize'] ? 1 : 0, IQueryBuilder::PARAM_INT),
			'note_mode' => $query->createNamedParameter($definition['noteMode'] ?? 'OPTIONAL'),
			'note_hint' => $query->createNamedParameter(($definition['noteHint'] ?? '') !== '' ? $definition['noteHint'] : null),
			'origin' => $query->createNamedParameter($origin),
			'created_at' => $query->createNamedParameter($now),
			'updated_at' => $query->createNamedParameter($now),
		])->executeStatement();
		$labelId = $query->getLastInsertId();
		$this->replaceValues($labelId, $definition['values'], []);
	}

	/**
	 * Auswahlwerte abgleichen: neue anlegen, vorhandene umbenennen/umsortieren. Werte, die im Fall schon
	 * verwendet wurden, werden nicht gelöscht, sondern deaktiviert (gesetzte Marker behalten ihren Klartext).
	 */
	private function replaceValues(int $labelId, array $values, array $previous): void {
		$known = [];
		foreach ($previous as $value) $known[$value['key']] = $value;
		$wanted = [];
		foreach ($values as $index => $value) {
			$wanted[$value['key']] = true;
			$sort = ($index + 1) * 10;
			if (isset($known[$value['key']])) {
				$query = $this->db->getQueryBuilder();
				$query->update('bestatter_label_values')->set('name', $query->createNamedParameter($value['name']))->set('sort_order', $query->createNamedParameter($sort, IQueryBuilder::PARAM_INT))
					->set('active', $query->createNamedParameter(($value['active'] ?? true) ? 1 : 0, IQueryBuilder::PARAM_INT))
					->where($query->expr()->eq('label_id', $query->createNamedParameter($labelId, IQueryBuilder::PARAM_INT)))
					->andWhere($query->expr()->eq('value_key', $query->createNamedParameter($value['key'])))->executeStatement();
				continue;
			}
			$query = $this->db->getQueryBuilder();
			$query->insert('bestatter_label_values')->values([
				'label_id' => $query->createNamedParameter($labelId, IQueryBuilder::PARAM_INT), 'value_key' => $query->createNamedParameter($value['key']),
				'name' => $query->createNamedParameter($value['name']), 'sort_order' => $query->createNamedParameter($sort, IQueryBuilder::PARAM_INT),
				'active' => $query->createNamedParameter(($value['active'] ?? true) ? 1 : 0, IQueryBuilder::PARAM_INT),
			])->executeStatement();
		}
		foreach ($known as $key => $value) {
			if (isset($wanted[$key])) continue;
			$used = $this->db->getQueryBuilder();
			$count = (int)$used->select($used->func()->count('*', 'n'))->from('bestatter_case_labels')
				->where($used->expr()->eq('label_id', $used->createNamedParameter($labelId, IQueryBuilder::PARAM_INT)))
				->andWhere($used->expr()->eq('value_key', $used->createNamedParameter($key)))->executeQuery()->fetchOne();
			$query = $this->db->getQueryBuilder();
			// Verwendete Werte bleiben (deaktiviert, ans Ende sortiert) stehen, damit gesetzte Marker ihren Klartext behalten.
			if ($count > 0) $query->update('bestatter_label_values')->set('active', $query->createNamedParameter(0, IQueryBuilder::PARAM_INT))->set('sort_order', $query->createNamedParameter(9000, IQueryBuilder::PARAM_INT));
			else $query->delete('bestatter_label_values');
			$query->where($query->expr()->eq('label_id', $query->createNamedParameter($labelId, IQueryBuilder::PARAM_INT)))
				->andWhere($query->expr()->eq('value_key', $query->createNamedParameter($key)))->executeStatement();
		}
	}

	private function assertUniqueName(string $name, string $ownKey): void {
		foreach ($this->definitionMap() as $definition) {
			if ($definition['key'] !== $ownKey && mb_strtolower($definition['name']) === mb_strtolower($name)) throw new \InvalidArgumentException('Einen Marker mit dem Namen „' . $name . '“ gibt es bereits.');
		}
	}

	/** @return array<int,int> Anzahl Fälle je Marker (nur aktuell gesetzte bzw. mit $includeRemoved auch entfernte) */
	private function usageCounts(bool $includeRemoved = false): array {
		$query = $this->db->getQueryBuilder();
		$query->select('label_id', $query->func()->count('*', 'n'))->from('bestatter_case_labels');
		if (!$includeRemoved) $query->where($query->expr()->isNull('removed_at'));
		$result = [];
		foreach ($query->groupBy('label_id')->executeQuery()->fetchAllAssociative() as $row) $result[(int)$row['label_id']] = (int)$row['n'];
		return $result;
	}

	private function assignmentRow(int $caseId, int $labelId): ?array {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('*')->from('bestatter_case_labels')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('label_id', $query->createNamedParameter($labelId, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchAssociative();
		return $row === false ? null : $row;
	}

	private function assignmentView(array $definition, array $row): array {
		$valueKey = (string)($row['value_key'] ?? '');
		$valueName = '';
		foreach ($definition['values'] as $value) if ($value['key'] === $valueKey) $valueName = $value['name'];
		if ($valueName === '' && $valueKey !== '') $valueName = $valueKey;
		$setBy = (string)($row['set_by'] ?? '');
		$noteBy = (string)($row['note_by'] ?? '');
		return [
			'key' => $definition['key'], 'name' => $definition['name'], 'kind' => $definition['kind'], 'color' => $definition['color'], 'icon' => $definition['icon'],
			'description' => $definition['description'], 'sortOrder' => $definition['sortOrder'], 'inactive' => !$definition['active'],
			'value' => $valueKey, 'valueName' => $valueName, 'text' => LabelRules::displayText($definition['name'], $definition['kind'], $valueName),
			'setAt' => (string)$row['set_at'], 'setBy' => $setBy, 'setByName' => $setBy !== '' ? ($this->users->getDisplayName($setBy) ?? $setBy) : '',
			'noteMode' => $definition['noteMode'], 'noteHint' => $definition['noteHint'], 'note' => (string)($row['note'] ?? ''), 'noteAt' => (string)($row['note_at'] ?? ''),
			'noteBy' => $noteBy, 'noteByName' => $noteBy !== '' ? ($this->users->getDisplayName($noteBy) ?? $noteBy) : '',
			'noteMissing' => $definition['noteMode'] === 'REQUIRED' && trim((string)($row['note'] ?? '')) === '',
		];
	}

	/** Pflichtnotiz bzw. „keine Notiz“ der Definition durchsetzen. */
	private function assertNoteAllowed(array $definition, string $note): void {
		if ($definition['noteMode'] === 'NONE' && $note !== '') throw new \InvalidArgumentException('Zum Marker „' . $definition['name'] . '“ sind keine Notizen vorgesehen.');
		if ($definition['noteMode'] === 'REQUIRED' && $note === '') throw new \InvalidArgumentException('Zum Marker „' . $definition['name'] . '“ ist eine Notiz erforderlich' . ($definition['noteHint'] !== '' ? ' (' . rtrim($definition['noteHint'], '?.') . ')' : '') . '.');
	}

	/** Protokollstand einer Zuordnung (Fall-Verlauf). */
	private function auditAssignment(array $view): array {
		return ['key' => $view['key'], 'name' => $view['name'], 'value' => $view['value'], 'valueName' => $view['valueName'], 'text' => $view['text'], 'note' => $view['note'] ?? ''];
	}

	private function writableCase(int $caseId): array {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('id', 'status', 'anonymized_at')->from('bestatter_cases')->where($query->expr()->eq('id', $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchAssociative();
		if ($row === false) throw new \InvalidArgumentException('Fall wurde nicht gefunden.');
		if (trim((string)($row['anonymized_at'] ?? '')) !== '' || strtoupper((string)$row['status']) === 'ANONYMISIERT') throw new \InvalidArgumentException('Anonymisierte Fälle können keine Marker erhalten.');
		return $row;
	}

	private function auditDefinition(array $definition): array {
		return ['key' => $definition['key'], 'name' => $definition['name'], 'kind' => $definition['kind'], 'color' => $definition['color'], 'icon' => $definition['icon'], 'active' => $definition['active'], 'keepOnAnonymize' => $definition['keepOnAnonymize'], 'noteMode' => $definition['noteMode'], 'noteHint' => $definition['noteHint'], 'values' => array_map(static fn(array $value): string => $value['key'] . '=' . $value['name'] . ($value['active'] ? '' : ' (inaktiv)'), $definition['values'])];
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? 'system';
	}

	private function localDate(string $value): string {
		$value = trim($value);
		if ($value === '') return '';
		try {
			return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');
		} catch (\Throwable) {
			return substr($value, 0, 10);
		}
	}
}
