<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Ablaufvorlagen in 7 Phasen (0.66.0, Kundenentscheidung vom 2026-09-24).
 *
 * Eine Ablaufvorlage besteht aus Schritten. Jeder Schritt beschreibt in Klartext, WANN er im Fall
 * entsteht (Auslöser), FÜR WELCHE Fälle er gilt (Bedingungen), WER zuständig ist (Rolle =
 * Nextcloud-Gruppe), BIS WANN er fällig ist (Frist relativ zu einem Bezugsdatum) und WELCHE
 * Ergebnis-Knöpfe er anbietet. Checklisten und Workflows werden dadurch zu einem einzigen Begriff
 * zusammengeführt; die bewährte Ausführungs-Engine (Dokumente, Termine, Protokoll, Wiederanlauf)
 * übernimmt der ProcessActionService.
 *
 * Kern ist reconcile(): Aus dem aktuellen Fallzustand wird abgeleitet, welche Schritte fällig sind.
 * Der Abgleich ist idempotent (eindeutiger Index in bestatter_process_runs) und wird nach jeder
 * fachlich relevanten Änderung sowie beim Öffnen eines Falls aufgerufen. Dadurch greifen auch
 * Änderungen, die über die Nextcloud-Kalendersynchronisation hereinkommen.
 */
class ProcessService {
	private const RESOURCE = __DIR__ . '/../../resources/process-templates.json';
	private const ISSUED_INVOICE_STATUSES = ['FREIGEGEBEN', 'VERSENDET', 'TEILBEZAHLT', 'BEZAHLT'];
	private const SENT_DEREGISTRATION_STATUSES = ['VERSENDET', 'BESTAETIGT', 'ERLEDIGT'];
	private const CEREMONY_PRESETS = ['TRAUERFEIER_1', 'TRAUERFEIER_2'];
	private const BURIAL_PRESETS = ['BEISETZUNG'];
	private const MAX_RECONCILE_PASSES = 4;
	/** Kontext für Schritte ohne Termin-/Rechnungsbezug (nicht leer: Oracle speichert '' als NULL). */
	private const CASE_CONTEXT = 'case';

	private bool $seeded = false;
	/** @var array<int, bool> Schutz gegen verschachtelte Abgleiche desselben Falls innerhalb einer Anfrage. */
	private array $reconciling = [];

	public function __construct(
		private IDBConnection $db,
		private IUserSession $userSession,
		private IGroupManager $groups,
		private IUserManager $users,
		private CaseService $cases,
		private RecordService $records,
		private GroupwareService $groupware,
		private CommercialStateService $commercialState,
		private CustomizingService $customizing,
		private ConfigurationService $configuration,
		private InstallationConfigService $installation,
		private TeamService $team,
		private ProcessActionService $actions,
		private SchedulingService $scheduling,
		private SurchargeRuleService $surcharges,
		private ArticleService $articles,
		private AuditService $audit,
		private LoggerInterface $logger,
		private LabelService $labels,
		private DocumentKindService $documentKinds,
		private GroupLookupService $groupLookup,
	) {}

	// ------------------------------------------------------------------ Vorlagen (Customizing)

	public function ensureSeedData(): void {
		if ($this->seeded) return;
		$this->seeded = true;
		$data = json_decode((string)file_get_contents(self::RESOURCE), true, 512, JSON_THROW_ON_ERROR);
		foreach ($data['templates'] ?? [] as $index => $template) {
			if ($this->templateRow((string)$template['key']) !== null) continue;
			$this->insertTemplate($template, 'BUNDLED', (int)($template['sortOrder'] ?? (($index + 1) * 10)));
		}
	}

	public function templates(): array {
		$this->ensureSeedData();
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_process_templates')->orderBy('sort_order', 'ASC')->addOrderBy('name', 'ASC')->executeQuery()->fetchAllAssociative();
		return array_map(fn(array $row): array => $this->mapTemplate($row, true), $rows);
	}

	/**
	 * 0.88.1: Rollen der aktiven Ablaufschritte, für die keine Nextcloud-Gruppe mit Mitgliedern eingerichtet ist.
	 * Aufgaben dieser Rollen fallen an die Fallverantwortung; die Oberfläche meldet das einmal zentral statt in jedem Schritt.
	 * Nur Anzahl der Mitglieder, keine Namen.
	 */
	public function roleStatus(): array {
		$this->ensureSeedData();
		$used = [];
		foreach ($this->activeSteps() as $step) {
			$role = (string)($step['role'] ?? '');
			if ($role !== '' && $role !== 'FALLVERANTWORTLICH') $used[$role] = true;
		}
		$roles = [];
		foreach ($this->installation->roleGroups() as $role => $group) {
			if (!isset($used[$role])) continue;
			$exists = $group !== '' && $this->groupLookup->lookup($group)['exists'];
			$members = $exists ? $this->groupMembers($group) : [];
			$reason = $group === '' ? 'keine Gruppe zugeordnet' : (!$exists ? 'Gruppe „' . $group . '“ nicht gefunden' : ($members === [] ? 'Gruppe „' . $group . '“ ohne Mitglieder' : ''));
			$roles[] = ['key' => $role, 'label' => ProcessRules::ROLES[$role] ?? $role, 'group' => $group, 'groupExists' => $exists, 'memberCount' => count($members), 'unresolved' => $members === [], 'reason' => $reason];
		}
		return ['roles' => $roles, 'unresolved' => array_values(array_filter($roles, static fn(array $role): bool => $role['unresolved']))];
	}

	public function meta(): array {
		$roles = [];
		foreach ($this->installation->roleGroups() as $role => $group) {
			$members = $group === '' ? [] : $this->groupMembers($group);
			$lookup = $this->groupLookup->lookup($group);
			$roles[] = ['key' => $role, 'label' => ProcessRules::ROLES[$role], 'group' => $group, 'groupExists' => $lookup['exists'], 'suggestedGroup' => $lookup['suggestion'], 'groupHint' => $this->groupLookup->missingText($group), 'members' => array_map(static fn(array $member): string => $member['displayName'], $members)];
		}
		$presets = [];
		foreach ($this->scheduling->catalog(true)['types'] as $type) $presets[$type['key']] = ['key' => $type['key'], 'label' => $type['name'], 'scheduleKind' => $type['scheduleKind']];
		foreach ($this->actions->schedulePresets() as $preset) $presets[$preset['key']] ??= ['key' => $preset['key'], 'label' => $preset['label'], 'scheduleKind' => $preset['scheduleKind']];
		return [
			'phases' => ProcessRules::PHASES,
			'triggers' => ProcessRules::TRIGGERS,
			'references' => ProcessRules::REFERENCES,
			'roles' => $roles,
			'roleLabels' => ProcessRules::ROLES,
			'priorities' => ProcessRules::PRIORITY_LABELS,
			'scopes' => ProcessRules::SCOPES,
			'completions' => ProcessRules::COMPLETIONS,
			'caseStatuses' => ProcessRules::CASE_STATUSES,
			'dateReferences' => ProcessRules::DATE_REFERENCES,
			'caseFields' => $this->caseFields(),
			// 0.76.0: Marker für Bedingung, Auslöser, Erledigt-Bedingung und Knopf „Marker setzen/entfernen“.
			'labels' => array_map(static fn(array $label): array => ['key' => $label['key'], 'name' => $label['name'], 'kind' => $label['kind'], 'active' => $label['active'], 'noteMode' => $label['noteMode'], 'noteHint' => $label['noteHint'], 'values' => array_map(static fn(array $value): array => ['key' => $value['key'], 'name' => $value['name']], $label['values'])], $this->labels->definitions()),
			// 0.80.0: Dokumentarten für Auslöser und Erledigt-Bedingung „Dokument eingegangen“.
			'documentKinds' => array_map(static fn(array $kind): array => ['key' => $kind['key'], 'name' => $kind['name'], 'active' => $kind['active']], $this->documentKinds->definitions()),
			'schedulePresets' => array_values($presets),
			'documentTemplates' => array_values(array_map(static fn(array $item): array => ['key' => $item['key'], 'name' => $item['name']], array_filter($this->configuration->documents(), static fn(array $item): bool => (bool)$item['active']))),
			'deregistrationTemplates' => array_values(array_map(static fn(array $item): array => ['key' => $item['key'], 'name' => $item['name']], array_filter($this->configuration->deregistrations(), static fn(array $item): bool => (bool)$item['active']))),
			'views' => ['overview' => 'Übersicht', 'master' => 'Stammdaten', 'order' => 'Auftrag / KVA', 'services' => 'Leistungen', 'finances' => 'Finanzen', 'deregistration' => 'Abmeldungen', 'document' => 'Dokumente', 'schedule' => 'Termine', 'contact' => 'Kontakte', 'task' => 'Aufgaben'],
			'actionTypes' => [
				'DOCUMENT' => 'Dokument erzeugen', 'DOCUMENT_BUNDLE' => 'Dokumentpaket erzeugen', 'SCHEDULE' => 'Termin anlegen', 'DEREGISTRATIONS' => 'Abmeldungen anlegen',
				'EMAIL_DRAFT' => 'E-Mail-Entwurf', 'TASK' => 'Folgeaufgabe', 'REMINDER' => 'Wiedervorlage', 'CONTACT_ACTIVITY' => 'Kontakt dokumentieren',
				'OPEN_VIEW' => 'Bereich öffnen', 'SURCHARGE_CHECK' => 'Leistungs- und Zuschlagsprüfung', 'CASE_STATUS' => 'Fallstatus setzen', 'CASE_CLOSE' => 'Fall abschließen', 'LABEL' => 'Marker setzen/entfernen',
			],
			// Prüfregel „Terminart ohne Folgeablauf“: auf diese Terminarten reagiert kein aktiver Schritt.
			'unusedPresets' => array_values(array_map(static fn(array $preset): string => $preset['label'], array_filter($presets, function (array $preset): bool {
				foreach ($this->activeSteps() as $step) {
					if (in_array($preset['key'], $step['trigger']['presetKeys'], true) || $step['complete']['presetKey'] === $preset['key']) return false;
					foreach ($step['actions'] as $action) if (($action['schedulePresetKey'] ?? '') === $preset['key']) return false;
				}
				return true;
			}))),
		];
	}

	/** Stammdatenfelder für „Stammdatenfeld ausgefüllt“ (dieselbe Quelle wie der Platzhalterkatalog). */
	private function caseFields(): array {
		$schema = json_decode((string)@file_get_contents(__DIR__ . '/../../resources/case-field-schema.json'), true);
		$fields = [];
		foreach (is_array($schema) ? $schema : [] as $field) {
			$key = (string)($field['key'] ?? '');
			if ($key === '' || in_array($key, ['case_number', 'first_name', 'last_name'], true)) continue;
			$fields[$key] = ['key' => $key, 'label' => (string)($field['label'] ?? $key)];
		}
		uasort($fields, static fn(array $left, array $right): int => strcasecmp($left['label'], $right['label']));
		return array_values($fields);
	}

	public function createTemplate(string $name, string $copyFrom = ''): array {
		$this->ensureSeedData();
		$name = trim($name);
		if ($name === '') throw new \InvalidArgumentException('Bitte einen Namen für die Ablaufvorlage angeben.');
		$key = ProcessRules::keyFromTitle($name);
		$suffix = 1; $base = $key;
		while ($this->templateRow($key) !== null) $key = substr($base, 0, 70) . '_' . (++$suffix);
		$steps = [];
		if ($copyFrom !== '') {
			$source = $this->template($copyFrom);
			$steps = array_values(array_filter($source['steps'], static fn(array $step): bool => !$step['archived']));
		}
		$template = $this->insertTemplate(['key' => $key, 'name' => $name, 'description' => $copyFrom !== '' ? 'Kopie von „' . ($source['name'] ?? $copyFrom) . '“' : '', 'active' => false, 'steps' => $steps], 'CUSTOM', 900);
		$this->audit->logSystem('PROCESS_TEMPLATE', 'CREATED', ['key' => $key, 'copyFrom' => $copyFrom]);
		return $template;
	}

	/**
	 * Speichert Kopfdaten und die vollständige Schrittliste einer Vorlage in einer Transaktion.
	 * Schritte werden über ihren Schlüssel wiedererkannt (IDs bleiben stabil, damit laufende Fälle
	 * ihre Aufgaben behalten); entfernte Schritte mit bestehenden Aufgaben werden archiviert.
	 */
	public function saveTemplate(string $key, array $input): array {
		$row = $this->templateRow($key);
		if ($row === null) throw new \InvalidArgumentException('Ablaufvorlage wurde nicht gefunden.');
		$name = trim((string)($input['name'] ?? $row['name']));
		if ($name === '') throw new \InvalidArgumentException('Der Name der Ablaufvorlage ist erforderlich.');
		$steps = $this->normalizeSteps((array)($input['steps'] ?? []));
		$this->db->beginTransaction();
		try {
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_process_templates')
				->set('name', $update->createNamedParameter($name))
				->set('description', $update->createNamedParameter(trim((string)($input['description'] ?? $row['description'] ?? ''))))
				->set('active', $update->createNamedParameter(filter_var($input['active'] ?? $row['active'], FILTER_VALIDATE_BOOLEAN), IQueryBuilder::PARAM_BOOL))
				->set('updated_at', $update->createNamedParameter(date('c')))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'])))
				->executeStatement();
			$active = filter_var($input['active'] ?? $row['active'], FILTER_VALIDATE_BOOLEAN);
			$this->replaceSteps((int)$row['id'], $steps, $active, $active && !(bool)$row['active']);
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		$after = $this->template($key);
		$this->audit->logSystem('PROCESS_TEMPLATE', 'UPDATED', ['key' => $key, 'steps' => count($steps), 'active' => $after['active']]);
		return $after;
	}

	public function deleteTemplate(string $key): void {
		$row = $this->templateRow($key);
		if ($row === null) throw new \InvalidArgumentException('Ablaufvorlage wurde nicht gefunden.');
		if ((string)$row['origin'] === 'BUNDLED') throw new \InvalidArgumentException('Mitgelieferte Ablaufvorlagen können nur deaktiviert, nicht gelöscht werden.');
		$runs = $this->db->getQueryBuilder();
		$runs->select($runs->func()->count('*', 'count'))->from('bestatter_process_runs', 'r')
			->innerJoin('r', 'bestatter_process_steps', 's', $runs->expr()->eq('s.id', 'r.step_id'))
			->where($runs->expr()->eq('s.template_id', $runs->createNamedParameter((int)$row['id'])));
		if ((int)$runs->executeQuery()->fetchOne() > 0) throw new \InvalidArgumentException('Zu dieser Ablaufvorlage gibt es bereits Aufgaben in Fällen. Bitte deaktivieren statt löschen.');
		$this->db->beginTransaction();
		try {
			$steps = $this->db->getQueryBuilder();
			$steps->delete('bestatter_process_steps')->where($steps->expr()->eq('template_id', $steps->createNamedParameter((int)$row['id'])))->executeStatement();
			$template = $this->db->getQueryBuilder();
			$template->delete('bestatter_process_templates')->where($template->expr()->eq('id', $template->createNamedParameter((int)$row['id'])))->executeStatement();
			$this->db->commit();
		} catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
		$this->audit->logSystem('PROCESS_TEMPLATE', 'DELETED', ['key' => $key]);
	}

	/** Stellt den Auslieferungsstand einer mitgelieferten Vorlage wieder her (IDs gleicher Schlüssel bleiben erhalten). */
	public function restoreBundled(string $key): array {
		$data = json_decode((string)file_get_contents(self::RESOURCE), true, 512, JSON_THROW_ON_ERROR);
		foreach ($data['templates'] ?? [] as $template) {
			if (strtoupper((string)$template['key']) !== strtoupper($key)) continue;
			return $this->saveTemplate($key, ['name' => $template['name'], 'description' => $template['description'] ?? '', 'active' => $this->template($key)['active'], 'steps' => $template['steps']]);
		}
		throw new \InvalidArgumentException('Für diese Vorlage gibt es keinen Auslieferungsstand.');
	}

	public function exportCsv(string $key): array {
		$template = $this->template($key);
		$steps = array_values(array_filter($template['steps'], static fn(array $step): bool => !$step['archived']));
		return ['fileName' => 'Ablauf-' . $template['key'] . '.csv', 'content' => ProcessRules::toCsv($steps)];
	}

	public const EXCHANGE_FORMAT = 'bestatter-process-template';
	public const EXCHANGE_VERSION = 1;
	private const STEP_FIELDS = ['key', 'phase', 'title', 'description', 'sortOrder', 'active', 'trigger', 'applies', 'complete', 'actions', 'dueOffsetDays', 'dueReference', 'role', 'priority', 'required'];

	/**
	 * 0.75.3: Vollständige Vorlage als JSON (verlustfrei inkl. Knöpfen und Auslöser-Details) – für Einrichtungspakete
	 * und die Übertragung zwischen Installationen. Die CSV bleibt für die Abstimmung in Excel.
	 * @return array{fileName:string,content:string,data:array}
	 */
	public function exportJson(string $key): array {
		$template = $this->template($key);
		$steps = array_values(array_map(static fn(array $step): array => array_intersect_key($step, array_flip(self::STEP_FIELDS)), array_filter($template['steps'], static fn(array $step): bool => !$step['archived'])));
		$data = ['format' => self::EXCHANGE_FORMAT, 'formatVersion' => self::EXCHANGE_VERSION, 'appVersion' => \OCA\Bestatter\AppInfo\Application::VERSION, 'exportedAt' => date('c'),
			'key' => $template['key'], 'name' => $template['name'], 'description' => $template['description'], 'active' => $template['active'], 'steps' => $steps];
		$this->audit->logSystem('PROCESS_TEMPLATE', 'EXPORTED', ['key' => $template['key'], 'steps' => count($steps)]);
		return ['fileName' => 'Ablauf-' . $template['key'] . '.json', 'content' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'data' => $data];
	}

	/** Liest und prüft eine JSON-Austauschdatei. @return array{key:string,name:string,description:string,active:bool,steps:list<array>} */
	public function parseExchange(string $content): array {
		if ($content === '' || strlen($content) > 2 * 1024 * 1024) throw new \InvalidArgumentException('Die Datei ist leer oder größer als 2 MB.');
		try { $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new \InvalidArgumentException('Die Datei ist keine gültige JSON-Datei.'); }
		if (!is_array($data) || ($data['format'] ?? '') !== self::EXCHANGE_FORMAT) throw new \InvalidArgumentException('Die Datei ist keine exportierte Bestatter-Ablaufvorlage.');
		if ((int)($data['formatVersion'] ?? 0) < 1 || (int)$data['formatVersion'] > self::EXCHANGE_VERSION) throw new \InvalidArgumentException('Die Ablaufvorlage hat ein Format, das diese App-Version nicht kennt (Formatversion ' . (int)($data['formatVersion'] ?? 0) . ').');
		$name = trim((string)($data['name'] ?? ''));
		if ($name === '') throw new \InvalidArgumentException('Die Ablaufvorlage hat keinen Namen.');
		$steps = array_values(array_filter((array)($data['steps'] ?? []), 'is_array'));
		$this->normalizeSteps($steps); // Prüfung vorab (wirft mit verständlicher Meldung)
		return ['key' => strtoupper(trim((string)($data['key'] ?? ''))) ?: ProcessRules::keyFromTitle($name), 'name' => $name, 'description' => (string)($data['description'] ?? ''), 'active' => (bool)($data['active'] ?? false), 'steps' => $steps];
	}

	/**
	 * 0.75.3: JSON einspielen. REPLACE ersetzt die Schrittliste der Vorlage $targetKey (Aktiv-Status bleibt);
	 * NEW legt eine neue, zunächst inaktive Vorlage an (Schlüssel bei Gleichheit mit Zusatz).
	 * @return array{template:array,warnings:list<string>,mode:string}
	 */
	public function importJson(string $content, string $mode = 'NEW', string $targetKey = ''): array {
		$this->ensureSeedData();
		$parsed = $this->parseExchange($content);
		$warnings = $this->referenceWarnings($parsed['steps']);
		if (strtoupper($mode) === 'REPLACE') {
			$key = $targetKey !== '' ? $targetKey : $parsed['key'];
			$row = $this->templateRow($key) ?? throw new \InvalidArgumentException('Die zu ersetzende Ablaufvorlage wurde nicht gefunden.');
			$template = $this->saveTemplate($key, ['name' => $parsed['name'], 'description' => $parsed['description'], 'active' => (bool)$row['active'], 'steps' => $parsed['steps']]);
			$this->audit->logSystem('PROCESS_TEMPLATE', 'IMPORTED', ['key' => $key, 'mode' => 'REPLACE', 'steps' => count($parsed['steps']), 'warnings' => count($warnings)]);
			return ['template' => $template, 'warnings' => $warnings, 'mode' => 'REPLACE'];
		}
		$key = $parsed['key']; $suffix = 1; $base = $key;
		while ($this->templateRow($key) !== null) $key = substr($base, 0, 70) . '_' . (++$suffix);
		$template = $this->insertTemplate(['key' => $key, 'name' => $parsed['name'] . ($key !== $base ? ' (' . $suffix . ')' : ''), 'description' => $parsed['description'], 'active' => false, 'steps' => $parsed['steps']], 'CUSTOM', 900);
		$this->audit->logSystem('PROCESS_TEMPLATE', 'IMPORTED', ['key' => $key, 'mode' => 'NEW', 'steps' => count($parsed['steps']), 'warnings' => count($warnings)]);
		return ['template' => $template, 'warnings' => $warnings, 'mode' => 'NEW'];
	}

	public function templateExists(string $key): bool { $this->ensureSeedData(); return $this->templateRow($key) !== null; }

	/** Verweise auf Dokument-, Abmelde- und Terminvorlagen, die in dieser Installation fehlen. @return list<string> */
	public function referenceWarnings(array $steps): array {
		$meta = $this->meta();
		$templates = array_fill_keys(array_merge(array_column($meta['documentTemplates'], 'key'), array_column($meta['deregistrationTemplates'], 'key')), true);
		$presets = array_fill_keys(array_column($meta['schedulePresets'], 'key'), true);
		$labels = array_column($meta['labels'], null, 'key');
		$kinds = array_column($meta['documentKinds'], null, 'key');
		$warnings = [];
		foreach ($steps as $step) {
			$title = (string)($step['title'] ?? $step['key'] ?? 'Schritt');
			$templateKeys = [(string)($step['trigger']['templateKey'] ?? ''), (string)($step['complete']['templateKey'] ?? '')];
			$presetKeys = array_merge((array)($step['trigger']['presetKeys'] ?? []), [(string)($step['complete']['presetKey'] ?? '')]);
			foreach ((array)($step['actions'] ?? []) as $action) {
				$templateKeys[] = (string)($action['templateKey'] ?? '');
				foreach ((array)($action['templateKeys'] ?? []) as $item) $templateKeys[] = (string)$item;
				$presetKeys[] = (string)($action['schedulePresetKey'] ?? '');
			}
			foreach (array_unique(array_filter($templateKeys)) as $item) if (!isset($templates[strtoupper($item)]) && !isset($templates[$item])) $warnings[] = '„' . $title . '“: Vorlage ' . $item . ' fehlt oder ist inaktiv.';
			foreach (array_unique(array_filter(array_map('strval', $presetKeys))) as $item) if (!isset($presets[$item])) $warnings[] = '„' . $title . '“: Terminart ' . $item . ' fehlt.';
			foreach ($this->labelReferences($step) as $item) if (!isset($labels[$item])) $warnings[] = '„' . $title . '“: Marker ' . $item . ' fehlt.';
			foreach ($this->labelReferences($step) as $item) if (isset($labels[$item]) && !$labels[$item]['active']) $warnings[] = '„' . $title . '“: Marker „' . $labels[$item]['name'] . '“ ist deaktiviert.';
			foreach (array_unique(array_filter([(string)($step['trigger']['documentKind'] ?? ''), (string)($step['complete']['documentKind'] ?? '')])) as $item) {
				$kind = $kinds[strtoupper($item)] ?? null;
				if ($kind === null) $warnings[] = '„' . $title . '“: Dokumentart ' . $item . ' fehlt.';
				elseif (!$kind['active']) $warnings[] = '„' . $title . '“: Dokumentart „' . $kind['name'] . '“ ist deaktiviert.';
			}
		}
		return array_values(array_unique($warnings));
	}

	/** Alle Marker, auf die ein Schritt Bezug nimmt (Bedingung, Auslöser, Erledigt-Bedingung, Knöpfe). @return list<string> */
	private function labelReferences(array $step): array {
		$keys = [(string)($step['applies']['label'] ?? ''), in_array((string)($step['trigger']['type'] ?? ''), ProcessRules::LABEL_TRIGGERS, true) ? (string)($step['trigger']['label'] ?? '') : '', in_array((string)($step['complete']['type'] ?? ''), ProcessRules::LABEL_TRIGGERS, true) ? (string)($step['complete']['label'] ?? '') : ''];
		foreach ((array)($step['actions'] ?? []) as $action) if (($action['type'] ?? '') === 'LABEL') $keys[] = (string)($action['labelKey'] ?? '');
		return array_values(array_unique(array_filter(array_map('strtoupper', $keys))));
	}

	/** CSV-Import (z. B. aus dem Reiter „Ablauf“ der Installations-Checkliste). Ergebnis-Knöpfe gleicher Schlüssel bleiben erhalten. */
	public function importCsv(string $key, string $content): array {
		$template = $this->template($key);
		$existing = [];
		foreach ($template['steps'] as $step) { $existing[$step['key']] = $step; $existing['TITLE:' . mb_strtolower($step['title'])] = $step; }
		$steps = [];
		foreach (ProcessRules::fromCsv($content) as $row) {
			$match = $existing[strtoupper((string)$row['key'])] ?? $existing['TITLE:' . mb_strtolower((string)$row['title'])] ?? null;
			if ($match !== null) {
				$row['key'] = $match['key'];
				$row['actions'] = $match['actions'];
				if ($row['trigger']['once'] === null) $row['trigger']['once'] = $match['trigger']['once'] ?? false;
				$detail = ($row['complete']['templateKey'] ?? '') . ($row['complete']['presetKey'] ?? '');
				if ($detail === '' && ($row['complete']['type'] ?? 'NONE') === ($match['complete']['type'] ?? 'NONE')) $row['complete'] = $match['complete'];
			}
			$row['trigger']['once'] = (bool)($row['trigger']['once'] ?? false);
			unset($row['line']);
			$steps[] = $row;
		}
		return $this->saveTemplate($key, ['steps' => $steps]);
	}

	// ------------------------------------------------------------------ Fall: Übersicht und Abgleich

	/** Übersicht für den Fall (Phasenleiste, Schritte, nächste Schritte, Abschlussprüfung). */
	public function overview(int $caseId): array {
		$this->ensureSeedData();
		$case = $this->cases->getCase($caseId);
		$reconcile = $this->reconcileQuietly($caseId);
		$case = $this->cases->getCase($caseId);
		$facts = $this->facts($case);
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$phases = [];
		foreach (ProcessRules::PHASES as $number => $label) $phases[$number] = ['phase' => $number, 'label' => $label, 'total' => 0, 'done' => 0, 'open' => 0, 'overdue' => 0, 'steps' => []];
		$manual = [];
		$today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
		$activeIds = array_fill_keys(array_map(static fn(array $step): int => (int)$step['id'], $this->activeSteps()), true);
		$recordIds = [];
		foreach ($facts['runsByStep'] as $stepRuns) foreach ($stepRuns as $stepRun) $recordIds[] = (int)$stepRun['record_id'];
		$executed = $this->executedActions($recordIds);
		foreach ($this->allSteps() as $step) {
			[$applies, $reason] = ProcessRules::evaluateApplies($step['applies'], $facts);
			$runs = $facts['runsByStep'][(int)$step['id']] ?? [];
			// Deaktivierte/archivierte Schritte erscheinen nur noch, wenn es im Fall bereits Aufgaben dazu gibt.
			if (!isset($activeIds[(int)$step['id']]) && $runs === []) continue;
			if ($applies === 'NO' && $runs === []) continue;
			if ($runs === [] && !$this->stepAppliesToCaseVersion($step, $facts)) {
				// Bestandsschutz: erst nach Start dieses Falls in die Vorlage gekommen – nur gezielt übernehmbar.
				if ($applies !== 'NO') $manual[] = ['stepId' => $step['id'], 'title' => $step['title'], 'phase' => $step['phase'], 'templateName' => $step['templateName'], 'isNew' => true];
				continue;
			}
			if ($runs === []) {
				if ($step['trigger']['type'] === 'MANUAL' || $applies === 'YES') {
					$manual[] = ['stepId' => $step['id'], 'title' => $step['title'], 'phase' => $step['phase'], 'templateName' => $step['templateName']];
				}
				if ($step['trigger']['type'] === 'MANUAL') continue;
				$phases[$step['phase']]['steps'][] = $this->stepView($step, null, $applies === 'PENDING' ? 'pending' : 'waiting', $applies === 'PENDING' ? $reason : $this->waitingReason($step, $facts), $uid, $today);
				$phases[$step['phase']]['total']++;
				continue;
			}
			foreach ($runs as $run) {
				$state = $this->runState($run, $today);
				$view = $this->stepView($step, $run, $state, '', $uid, $today);
				$view['actions'] = array_map(static fn(array $action): array => $action + ['executed' => !$action['repeatable'] && isset($executed[(int)$run['record_id'] . ':' . $action['key']])], $view['actions']);
				$phases[$step['phase']]['steps'][] = $view;
				if (in_array($state, ['autoskipped', 'deleted'], true)) continue;
				$phases[$step['phase']]['total']++;
				if (in_array($state, ['done', 'skipped'], true)) $phases[$step['phase']]['done']++;
				else { $phases[$step['phase']]['open']++; if ($state === 'overdue') $phases[$step['phase']]['overdue']++; }
			}
		}
		$open = [];
		foreach ($phases as $phase) foreach ($phase['steps'] as $view) if (in_array($view['state'], ['open', 'overdue'], true)) $open[] = $view;
		$priorityRank = ['KRITISCH' => 0, 'HOCH' => 1, 'NORMAL' => 2, 'NIEDRIG' => 3];
		usort($open, static fn(array $left, array $right): int => [$left['state'] === 'overdue' ? 0 : 1, substr((string)$left['due'], 0, 10), $priorityRank[$left['priority']] ?? 2] <=> [$right['state'] === 'overdue' ? 0 : 1, substr((string)$right['due'], 0, 10), $priorityRank[$right['priority']] ?? 2]);
		return [
			'caseId' => $caseId,
			'reconcile' => $reconcile,
			'phases' => array_values($phases),
			'nextSteps' => array_slice($open, 0, 3),
			'manualSteps' => $manual,
			'attributes' => $this->attributesView($case, $facts),
			'closure' => $this->closureCheck($caseId, $facts),
			'caseStatus' => (string)$case['status'],
		];
	}

	/** Abgleich ohne Fehlerweitergabe – für Aufrufe nach fachlichen Änderungen (die Hauptaktion darf nie scheitern). */
	public function reconcileQuietly(int $caseId): array {
		try {
			return $this->reconcile($caseId);
		} catch (\Throwable $error) {
			$this->logger->warning('Der Abgleich des Ablaufs ist fehlgeschlagen.', ['caseId' => $caseId, 'exceptionClass' => $error::class, 'reason' => $error->getMessage()]);
			return ['created' => 0, 'completed' => 0, 'rescheduled' => 0, 'errors' => [$error->getMessage()]];
		}
	}

	public function reconcile(int $caseId): array {
		$summary = ['created' => 0, 'completed' => 0, 'rescheduled' => 0, 'errors' => []];
		if ($caseId <= 0 || isset($this->reconciling[$caseId]) || $this->userSession->getUser() === null) return $summary;
		$this->ensureSeedData();
		$this->reconciling[$caseId] = true;
		try {
			for ($pass = 0; $pass < self::MAX_RECONCILE_PASSES; $pass++) {
				$case = $this->cases->getCase($caseId);
				$facts = $this->facts($case);
				$changes = 0;
				$changes += $this->autoComplete($case, $facts, $summary);
				if (!in_array(strtoupper((string)$case['status']), ['STORNIERT', 'ABGESCHLOSSEN'], true) && !$facts['cancelled']) {
					$changes += $this->createDueSteps($case, $facts, $summary);
					$this->reschedule($facts, $summary);
					$this->syncLabelNotes($facts, $summary);
				} elseif (strtoupper((string)$case['status']) === 'ABGESCHLOSSEN' && !$facts['cancelled']) {
					// Nachsorge nach dem Fallabschluss: nur „Datum erreicht“ und „Fallstatus: abgeschlossen“.
					$changes += $this->createDueSteps($case, $facts, $summary, true);
				}
				if ($changes === 0) break;
			}
		} finally {
			unset($this->reconciling[$caseId]);
		}
		return $summary;
	}

	/**
	 * Zeitgesteuerte Auslöser („Datum erreicht“, „Rechnung überfällig“) ohne Benutzerinteraktion
	 * abgleichen (ProcessScheduleJob). Jeder Fall läuft im Namen der Fallverantwortung bzw. ersatzweise
	 * eines Bestatter-Administrators, damit Zuständigkeit und Aufgabenbesitz wie im Tagesgeschäft entstehen.
	 * @return array{cases:int,created:int,errors:int}
	 */
	public function reconcileTimeTriggers(): array {
		$this->ensureSeedData();
		$result = ['cases' => 0, 'created' => 0, 'errors' => 0];
		$types = array_unique(array_map(static fn(array $step): string => $step['trigger']['type'], array_filter($this->activeSteps(), static fn(array $step): bool => in_array($step['trigger']['type'], ProcessRules::TIME_TRIGGERS, true))));
		if ($types === []) return $result;
		$query = $this->db->getQueryBuilder();
		$query->select('id', 'status', 'responsible_employee')->from('bestatter_cases')
			->where($query->expr()->neq('status', $query->createNamedParameter('STORNIERT')))
			->andWhere($query->expr()->isNull('anonymized_at'));
		// Abgeschlossene Fälle nur für „Datum erreicht“ (Nachsorge); überfällige Rechnungen hat ein abgeschlossener Fall nicht.
		if (!in_array('DATE_REACHED', $types, true)) $query->andWhere($query->expr()->neq('status', $query->createNamedParameter('ABGESCHLOSSEN')));
		$fallback = $this->fallbackUser();
		$previous = $this->userSession->getUser();
		try {
			foreach ($query->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative() as $row) {
				$responsible = trim((string)($row['responsible_employee'] ?? ''));
				$user = $responsible !== '' && $this->team->hasBestatterRole($responsible) ? $this->users->get($responsible) : null;
				$user ??= $fallback;
				if ($user === null) { $result['errors']++; continue; }
				$this->userSession->setVolatileActiveUser($user);
				$summary = $this->reconcileQuietly((int)$row['id']);
				$result['cases']++;
				$result['created'] += (int)$summary['created'];
				$result['errors'] += count($summary['errors']);
			}
		} finally {
			$this->userSession->setVolatileActiveUser($previous);
		}
		return $result;
	}

	private function fallbackUser(): ?\OCP\IUser {
		foreach ($this->installation->adminGroups() as $groupName) {
			$group = $this->groups->get($groupName);
			if ($group === null) continue;
			foreach ($group->getUsers() as $user) if ($user->isEnabled() && $this->team->hasBestatterRole($user->getUID())) return $user;
		}
		return null;
	}

	public function completeRun(int $runId): array {
		[$run, $record] = $this->runWithRecord($runId);
		if ($record === null) throw new \InvalidArgumentException('Zu diesem Schritt gibt es keine Aufgabe.');
		$data = $record['data'];
		unset($data['skipped'], $data['skipReason']);
		$this->updateTask($record, 'ERLEDIGT', $data);
		$this->reconcileQuietly((int)$run['case_id']);
		return $this->overview((int)$run['case_id']);
	}

	/** Schritt überspringen („trifft nicht zu“); bei Pflichtschritten mit Begründung (Audit-Log). */
	public function skipRun(int $runId, string $reason): array {
		[$run, $record] = $this->runWithRecord($runId);
		if ($record === null) throw new \InvalidArgumentException('Zu diesem Schritt gibt es keine Aufgabe.');
		$reason = trim($reason);
		if (!empty($record['data']['processRequired']) && mb_strlen($reason) < 5) throw new \InvalidArgumentException('Pflichtschritte können nur mit einer kurzen Begründung (mindestens 5 Zeichen) übersprungen werden.');
		$data = $record['data'];
		$data['skipped'] = true;
		$data['skipReason'] = $reason;
		$data['skippedBy'] = $this->userSession->getUser()?->getUID() ?? '';
		$data['skippedAt'] = date('c');
		$this->updateTask($record, 'ERLEDIGT', $data);
		$this->audit->log((int)$run['case_id'], 'PROCESS', $runId, 'STEP_SKIPPED', null, ['step' => $run['step_key'], 'reason' => $reason, 'required' => !empty($record['data']['processRequired'])]);
		$this->reconcileQuietly((int)$run['case_id']);
		return $this->overview((int)$run['case_id']);
	}

	public function reopenRun(int $runId): array {
		[$run, $record] = $this->runWithRecord($runId);
		if ($record === null) throw new \InvalidArgumentException('Zu diesem Schritt gibt es keine Aufgabe.');
		$data = $record['data'];
		unset($data['skipped'], $data['skipReason'], $data['skippedBy'], $data['skippedAt'], $data['autoCompleted'], $data['autoCompletedReason']);
		$data['manuallyReopened'] = true;
		$this->updateTask($record, 'OFFEN', $data);
		return $this->overview((int)$run['case_id']);
	}

	/** Schritt von Hand hinzufügen (Auslöser „nur von Hand“ oder vorzeitig). */
	public function addStep(int $caseId, int $stepId): array {
		$case = $this->cases->getCase($caseId);
		$step = null;
		foreach ($this->activeSteps() as $candidate) if ((int)$candidate['id'] === $stepId) { $step = $candidate; break; }
		if ($step === null) throw new \InvalidArgumentException('Der Schritt ist nicht (mehr) aktiv.');
		$facts = $this->facts($case);
		// Nicht-manuelle Schritte ohne Termin-/Rechnungsbezug unter ihrem regulären Kontext anlegen, damit
		// der spätere Auslöser keine Dublette erzeugt.
		$type = $step['trigger']['type'];
		$perObject = ['MANUAL', 'SCHEDULE_CONFIRMED', 'SCHEDULE_DONE', 'SCHEDULE_CHANGED', 'SCHEDULE_CANCELLED', 'INCOMING_INVOICE', 'FINAL_INVOICE_ISSUED', 'PAYMENT_RECEIVED', 'INVOICE_OVERDUE', 'SIDE_ORDER_COMMISSIONED', 'SIDE_ORDER_CLOSED', 'DEREGISTRATION_SENT', 'DEREGISTRATION_CONFIRMED'];
		$contextKey = in_array($type, ProcessRules::DOCUMENT_TRIGGERS, true) ? 'doc:' . $step['trigger']['templateKey'] : ($type === 'DOCUMENT_RECEIVED' ? 'docin:' . strtoupper((string)$step['trigger']['documentKind']) : (!in_array($type, $perObject, true) ? self::CASE_CONTEXT : 'manual:' . date('YmdHis')));
		foreach ($facts['runsByStep'][$stepId] ?? [] as $run) if (in_array((string)$run['record_status'], ['OFFEN', 'IN_BEARBEITUNG'], true)) throw new \InvalidArgumentException('Dieser Schritt ist im Fall bereits offen.');
		$summary = ['created' => 0, 'completed' => 0, 'rescheduled' => 0, 'errors' => []];
		$this->createStepTask($case, $facts, $step, $contextKey, $facts['today'], '', $summary, true);
		if ($summary['errors'] !== []) throw new \InvalidArgumentException(implode(' ', $summary['errors']));
		return $this->overview($caseId);
	}

	/** Fallmerkmale für den Ablauf (Trauerfeier, Rente, Anzahlung) – direkt im Fallcockpit pflegbar. */
	public function saveAttributes(int $caseId, array $input, string $expectedUpdatedAt = ''): array {
		$case = $this->cases->getCase($caseId);
		$master = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		foreach (['with_funeral_ceremony' => 'ceremony', 'receives_pension' => 'pension'] as $field => $inputKey) {
			if (!array_key_exists($inputKey, $input)) continue;
			$value = (string)$input[$inputKey];
			if (!in_array($value, ['', '0', '1'], true)) throw new \InvalidArgumentException('Bitte Ja, Nein oder offen angeben.');
			$master[$field] = $value;
		}
		if (array_key_exists('depositAmount', $input)) {
			$raw = trim(str_replace(['€', ' ', "\u{00A0}"], '', (string)$input['depositAmount']));
			// „1.500,50“ / „1500,50“: Komma = Dezimaltrenner; „1500.50“: Punkt = Dezimaltrenner; „1.500“: Tausenderpunkt
			if (str_contains($raw, ',')) $raw = str_replace(',', '.', str_replace('.', '', $raw));
			elseif (!preg_match('/^\d+\.\d{1,2}$/', $raw)) $raw = str_replace('.', '', $raw);
			if ($raw !== '' && !is_numeric($raw)) throw new \InvalidArgumentException('Bitte den Anzahlungsbetrag als Zahl angeben, z. B. 1.500,00.');
			$cents = $raw === '' ? 0 : (int)round((float)$raw * 100);
			if ($cents < 0 || $cents > 10000000) throw new \InvalidArgumentException('Der Anzahlungsbetrag ist unplausibel.');
			$master['deposit_amount_cents'] = $cents;
		}
		if (array_key_exists('depositReceivedAt', $input)) {
			$date = trim((string)$input['depositReceivedAt']);
			if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new \InvalidArgumentException('Bitte das Eingangsdatum der Anzahlung als Datum angeben.');
			if ($date !== '' && (int)($master['deposit_amount_cents'] ?? 0) <= 0) throw new \InvalidArgumentException('Bitte zuerst den vereinbarten Anzahlungsbetrag erfassen.');
			$master['deposit_received_at'] = $date;
		}
		$this->cases->updateMasterData($caseId, $master, $expectedUpdatedAt);
		return $this->overview($caseId);
	}

	/**
	 * Abschlussprüfung (Paket E): Offene Pflichtschritte, fehlende oder unbezahlte Schlussrechnung
	 * und offene Nebenaufträge verhindern den Abschluss; alles andere sind Hinweise.
	 */
	public function closureCheck(int $caseId, ?array $facts = null): array {
		$case = $this->cases->getCase($caseId);
		$facts ??= $this->facts($case);
		$blockers = []; $warnings = [];
		foreach ($facts['runsByStep'] as $runs) foreach ($runs as $run) {
			if (empty($run['required']) || in_array((string)$run['run_status'], ['SKIPPED_AUTO'], true)) continue;
			if ((string)$run['record_status'] === 'GELOESCHT') { $warnings[] = 'Die Aufgabe zum Pflichtschritt „' . $run['record_title'] . '“ wurde gelöscht.'; continue; }
			if ((string)$run['record_status'] !== 'ERLEDIGT' && (int)$run['record_id'] > 0) $blockers[] = 'Pflichtschritt offen: ' . $run['record_title'];
		}
		if ($facts['orderCommissioned'] && !$facts['closedWithoutInvoice']) {
			if ($facts['finalInvoice'] === null) $blockers[] = 'Es gibt noch keine freigegebene Schlussrechnung.';
			elseif (!$facts['paid']) $blockers[] = 'Die Schlussrechnung ' . $facts['finalInvoice']['invoice_number'] . ' ist noch nicht als bezahlt markiert.';
		}
		if ($facts['depositCents'] > 0 && !$facts['depositReceived']) $warnings[] = 'Die vereinbarte Anzahlung ist nicht als eingegangen erfasst.';
		try { $this->cases->assertClosable($caseId); } catch (\InvalidArgumentException $error) { $blockers[] = $error->getMessage(); }
		$openTasks = 0;
		foreach ($facts['openCaseTasks'] as $task) if ((int)($task['processStepId'] ?? 0) === 0) $openTasks++;
		if ($openTasks > 0) $warnings[] = $openTasks . ' weitere offene Aufgabe(n) ohne Ablaufbezug werden beim Abschluss automatisch mitgeschlossen.';
		return ['closable' => $blockers === [] && strtoupper((string)$case['status']) !== 'ABGESCHLOSSEN', 'alreadyClosed' => strtoupper((string)$case['status']) === 'ABGESCHLOSSEN', 'blockers' => array_values(array_unique($blockers)), 'warnings' => $warnings];
	}

	public function closeCase(int $caseId, string $note = ''): array {
		$check = $this->closureCheck($caseId);
		if ($check['alreadyClosed']) throw new \InvalidArgumentException('Der Fall ist bereits abgeschlossen.');
		if (!$check['closable']) throw new \InvalidArgumentException('Der Fall kann noch nicht abgeschlossen werden: ' . implode(' ', $check['blockers']));
		$case = $this->cases->getCase($caseId);
		$master = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$master['status'] = 'ABGESCHLOSSEN';
		$master['closed_at'] = date('Y-m-d');
		$master['close_reason'] = trim($note) !== '' ? trim($note) : 'Ablauf vollständig abgeschlossen';
		$this->cases->updateMasterData($caseId, $master, (string)($case['updatedAt'] ?? ''));
		$this->records->closeFollowUpsForCase($caseId, 'ABGESCHLOSSEN');
		$this->audit->log($caseId, 'PROCESS', $caseId, 'CASE_CLOSED', null, ['note' => $master['close_reason']]);
		return $this->overview($caseId);
	}

	/**
	 * Leistungs- und Zuschlagsprüfung (Paket F): Vorschläge für Zuschläge nach den gepflegten
	 * Staffeln und für typischerweise vergessene Leistungen. Übernommen wird bewusst über den
	 * regulären Leistungsdialog (inkl. Nachtragslogik nach Beauftragung).
	 */
	public function suggestions(int $caseId): array {
		$case = $this->cases->getCase($caseId);
		$facts = $this->facts($case);
		$services = $this->articles->caseServices($caseId)['items'] ?? [];
		$presentArticleIds = []; $presentGroups = [];
		foreach ($services as $service) {
			if (strtoupper((string)($service['serviceStatus'] ?? '')) === 'STORNIERT') continue;
			$presentArticleIds[(int)($service['articleId'] ?? 0)] = true;
			if (($service['articleGroupKey'] ?? null) !== null) $presentGroups[(string)$service['articleGroupKey']] = true;
		}
		$catalog = [];
		foreach ($this->articles->catalog()['articles'] ?? [] as $article) $catalog[(int)$article['id']] = $article;
		$byNumber = [];
		foreach ($catalog as $article) $byNumber[strtoupper((string)$article['articleNumber'])] = $article;
		$items = [];
		$values = ['pickupTime' => (string)($case['pickupTime'] ?? ''), 'heightCm' => $case['bodyHeightCm'] ?? null, 'weightKg' => $case['bodyWeightKg'] ?? null];
		$allRules = $this->surcharges->all();
		$matched = ProcessRules::matchingSurcharges($allRules, $values);
		// Im Fall bereits ausdrücklich gewählte Staffeln (Stammdaten „Zuschläge“) zählen ebenfalls.
		$master = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		foreach (['surcharge_pickup_rule_key', 'surcharge_height_rule_key', 'surcharge_weight_rule_key', 'surcharge_other_rule_key'] as $field) {
			$chosen = trim((string)($master[$field] ?? ''));
			if ($chosen === '') continue;
			foreach ($allRules as $rule) if ($rule['ruleKey'] === $chosen && !in_array($rule['ruleKey'], array_column($matched, 'ruleKey'), true)) $matched[] = $rule;
		}
		foreach ($matched as $rule) {
			$article = $catalog[(int)($rule['articleId'] ?? 0)] ?? null;
			$items[] = [
				'kind' => 'SURCHARGE', 'label' => (string)$rule['label'], 'reason' => $this->surchargeReason($rule, $values),
				'articleId' => $article['id'] ?? null, 'articleNumber' => $article['articleNumber'] ?? '', 'articleName' => $article['shortName'] ?? '',
				'present' => $article !== null && isset($presentArticleIds[(int)$article['id']]),
				'missingArticle' => $article === null,
			];
		}
		$suggest = function (string $articleNumber, string $label, string $reason, string $group = '') use (&$items, $byNumber, $presentArticleIds, $presentGroups): void {
			$article = $byNumber[strtoupper($articleNumber)] ?? null;
			if ($article === null || !($article['active'] ?? true)) return;
			$present = isset($presentArticleIds[(int)$article['id']]) || ($group !== '' && isset($presentGroups[$group]));
			$items[] = ['kind' => 'SERVICE', 'label' => $label, 'reason' => $reason, 'articleId' => (int)$article['id'], 'articleNumber' => $article['articleNumber'], 'articleName' => $article['shortName'], 'present' => $present, 'missingArticle' => false];
		};
		$ceremonyHeld = false; $transferHeld = false;
		foreach ($facts['schedules'] as $schedule) {
			if (!$schedule['confirmed']) continue;
			if (in_array($schedule['presetKey'], self::CEREMONY_PRESETS, true)) $ceremonyHeld = true;
			if ($schedule['presetKey'] === 'UEBERFUEHRUNG') $transferHeld = true;
		}
		if ($ceremonyHeld) $suggest('14010', 'Trauerfeier', 'Im Fall ist eine bestätigte Trauerfeier geplant.', '140');
		if ($transferHeld || $case['pickupTime'] !== '') $suggest('11010', 'Überführung', 'Im Fall ist eine Überführung/Abholung erfasst.', '110');
		if ($facts['scope'] === 'CREMATION') $suggest('63010', 'Einäscherung im Krematorium', 'Die Bestattungsart ist eine Feuerbestattung.', '630');
		foreach ($facts['incoming'] as $incoming) if (!$incoming['transferred']) $items[] = ['kind' => 'INCOMING', 'label' => 'Eingangsrechnung ' . $incoming['supplier'], 'reason' => 'Die Positionen sind noch nicht in die Abrechnung übernommen.', 'articleId' => null, 'articleNumber' => '', 'articleName' => '', 'present' => false, 'missingArticle' => false];
		$open = count(array_filter($items, static fn(array $item): bool => !$item['present']));
		return ['items' => $items, 'openCount' => $open, 'orderFrozen' => $facts['orderCommissioned']];
	}

	// ------------------------------------------------------------------ interner Abgleich

	private function createDueSteps(array $case, array $facts, array &$summary, bool $afterClosure = false): int {
		$changes = 0;
		foreach ($this->activeSteps() as $step) {
			if ($step['trigger']['type'] === 'MANUAL') continue;
			if ($afterClosure && !self::runsAfterClosure($step)) continue;
			if (!$this->stepAppliesToCaseVersion($step, $facts)) continue; // Bestandsschutz
			[$applies] = ProcessRules::evaluateApplies($step['applies'], $facts);
			if ($applies !== 'YES') continue;
			$manuallyAdded = false;
			foreach ($facts['runsByStep'][(int)$step['id']] ?? [] as $existingRun) if (str_starts_with((string)$existingRun['context_key'], 'manual:')) $manuallyAdded = true;
			if ($manuallyAdded && (!empty($step['trigger']['once']) || in_array($step['trigger']['type'], ['FINAL_INVOICE_ISSUED', 'PAYMENT_RECEIVED'], true))) continue;
			foreach ($this->triggerContexts($step, $facts) as [$contextKey, $anchor]) {
				if (isset($facts['runIndex'][(int)$step['id'] . '|' . $contextKey])) continue;
				if ($this->createStepTask($case, $facts, $step, $contextKey, $anchor, $this->contextLabel($contextKey, $facts), $summary)) $changes++;
			}
		}
		return $changes;
	}

	/** Schritte, die auch in abgeschlossenen Fällen noch entstehen (Nachsorge). */
	public static function runsAfterClosure(array $step): bool {
		$trigger = $step['trigger'];
		return $trigger['type'] === 'DATE_REACHED' || ($trigger['type'] === 'CASE_STATUS' && ($trigger['status'] ?? '') === 'ABGESCHLOSSEN');
	}

	private function createStepTask(array $case, array $facts, array $step, string $contextKey, string $anchor, string $contextLabel, array &$summary, bool $manual = false): bool {
		$caseId = (int)$case['id'];
		$now = date('c');
		$alreadyComplete = !$manual && $this->completionSatisfied($step, $facts, $contextKey);
		$reference = $this->referenceDate($step, $facts, $anchor);
		[$due, $shortNotice] = ProcessRules::dueDate($reference, (int)$step['dueOffsetDays'], $facts['today']);
		$claim = $this->db->getQueryBuilder();
		try {
			$claim->insert('bestatter_process_runs')->values([
				'case_id' => $claim->createNamedParameter($caseId), 'step_id' => $claim->createNamedParameter((int)$step['id']),
				'step_key' => $claim->createNamedParameter((string)$step['key']), 'context_key' => $claim->createNamedParameter($contextKey),
				'record_id' => $claim->createNamedParameter(null), 'run_status' => $claim->createNamedParameter($alreadyComplete ? 'SKIPPED_AUTO' : 'CREATING'),
				'anchor_date' => $claim->createNamedParameter($reference), 'due_date' => $claim->createNamedParameter($due),
				'created_by' => $claim->createNamedParameter($this->userSession->getUser()?->getUID() ?? ''),
				'created_at' => $claim->createNamedParameter($now), 'updated_at' => $claim->createNamedParameter($now),
			])->executeStatement();
		} catch (\Throwable $error) {
			if ($this->isUniqueViolation($error)) return false; // parallel bereits angelegt
			throw $error;
		}
		$runId = (int)$this->db->lastInsertId('bestatter_process_runs');
		if ($alreadyComplete) return true;
		try {
			$title = $step['title'] . ($contextLabel !== '' ? ' – ' . $contextLabel : '');
			$assignment = $this->resolveAssignment((string)$step['role'], $case);
			// 0.77.0: Notizen der auslösenden bzw. bedingenden Marker stehen in der Aufgabenbeschreibung.
			$labelKeys = self::stepLabelKeys($step);
			$noteBlock = $this->labelNoteBlock($labelKeys, $facts);
			$data = array_filter([
				'description' => LabelRules::withTaskNoteBlock((string)$step['description'], '', $noteBlock),
				'labelNoteKeys' => $labelKeys,
				'labelNoteBlock' => $noteBlock,
				'priority' => (string)$step['priority'],
				'assigneeUid' => $assignment['uid'],
				'assigneeUids' => $assignment['uids'],
				'assigneeRole' => (string)$step['role'],
				'roleUnresolved' => $assignment['unresolved'],
				'processStepId' => (int)$step['id'],
				'processStepKey' => (string)$step['key'],
				'processTemplateKey' => (string)$step['templateKey'],
				'processPhase' => (int)$step['phase'],
				'processRequired' => (bool)$step['required'],
				'processRunId' => $runId,
				'processContext' => $contextKey,
				'shortNotice' => $shortNotice,
			], static fn(mixed $value): bool => $value !== '' && $value !== [] && $value !== false && $value !== null);
			if (str_starts_with($contextKey, 'schedule:')) {
				$scheduleId = (int)substr($contextKey, 9);
				$data['sourceScheduleId'] = $scheduleId;
				$data['link'] = ['source' => 'PROCESS', 'sourceScheduleId' => $scheduleId];
			}
			$record = $this->groupware->createTask($title, $due, 'OFFEN', json_encode($data, JSON_THROW_ON_ERROR), $caseId);
		} catch (\Throwable $error) {
			// Anspruch wieder freigeben, damit der nächste Abgleich den Schritt erneut versucht.
			$delete = $this->db->getQueryBuilder();
			$delete->delete('bestatter_process_runs')->where($delete->expr()->eq('id', $delete->createNamedParameter($runId)))->executeStatement();
			$summary['errors'][] = '„' . $step['title'] . '“: ' . $error->getMessage();
			$this->logger->warning('Ablaufschritt konnte nicht angelegt werden.', ['caseId' => $caseId, 'step' => $step['key'], 'reason' => $error->getMessage()]);
			return false;
		}
		try {
			// Die Aufgabe existiert bereits: Ein Fehler hier darf den Anspruch NICHT freigeben (sonst Dublette).
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_process_runs')->set('record_id', $update->createNamedParameter((int)$record['id']))
				->set('run_status', $update->createNamedParameter('CREATED'))->set('due_date', $update->createNamedParameter((string)($record['date'] ?? $due)))
				->set('updated_at', $update->createNamedParameter(date('c')))
				->where($update->expr()->eq('id', $update->createNamedParameter($runId)))->executeStatement();
		} catch (\Throwable $error) {
			$this->logger->error('Ablaufschritt angelegt, Verknüpfung aber nicht gespeichert.', ['caseId' => $caseId, 'step' => $step['key'], 'recordId' => (int)$record['id'], 'reason' => $error->getMessage()]);
		}
		$summary['created']++;
		return true;
	}

	/** Marker, deren Notiz in die Aufgabe eines Schritts gehört: Auslöser „Marker gesetzt“ und Bedingung „Marker“. */
	public static function stepLabelKeys(array $step): array {
		$keys = [];
		if (($step['trigger']['type'] ?? '') === 'LABEL_SET' && (string)($step['trigger']['label'] ?? '') !== '') $keys[] = strtoupper((string)$step['trigger']['label']);
		if ((string)($step['applies']['label'] ?? '') !== '') $keys[] = strtoupper((string)$step['applies']['label']);
		return array_values(array_unique($keys));
	}

	/** @param list<string> $keys */
	private function labelNoteBlock(array $keys, array $facts): string {
		$notes = [];
		foreach ($keys as $key) {
			$label = $facts['labels'][$key] ?? null;
			if ($label !== null && trim((string)($label['note'] ?? '')) !== '') $notes[(string)($label['name'] ?? $key)] = (string)$label['note'];
		}
		return LabelRules::taskNoteBlock($notes);
	}

	/**
	 * 0.77.0: Offene Aufgaben aus Schritten mit Marker übernehmen geänderte Notizen (Block am Ende der Beschreibung).
	 * Von Hand ergänzter Text bleibt erhalten; erledigte Aufgaben werden nicht mehr verändert.
	 */
	private function syncLabelNotes(array $facts, array &$summary): void {
		$steps = [];
		foreach ($this->allSteps() as $step) $steps[(int)$step['id']] = $step;
		foreach ($facts['runsByStep'] as $stepId => $runs) {
			$step = $steps[(int)$stepId] ?? null;
			if ($step === null) continue;
			$keys = self::stepLabelKeys($step);
			if ($keys === []) continue;
			$block = $this->labelNoteBlock($keys, $facts);
			foreach ($runs as $run) {
				if ((int)$run['record_id'] <= 0 || !in_array((string)$run['record_status'], ['OFFEN', 'IN_BEARBEITUNG'], true)) continue;
				try {
					$record = $this->records->get((int)$run['record_id'], (string)$run['record_owner']);
					$data = is_array($record['data'] ?? null) ? $record['data'] : [];
					$previous = (string)($data['labelNoteBlock'] ?? '');
					if ($previous === $block) continue;
					$data['description'] = LabelRules::withTaskNoteBlock((string)($data['description'] ?? ''), $previous, $block);
					$data['labelNoteKeys'] = $keys;
					$data['labelNoteBlock'] = $block;
					$this->groupware->update(array_replace($record, ['data' => $data]), (string)$run['record_owner']);
					$summary['notes'] = (int)($summary['notes'] ?? 0) + 1;
				} catch (\Throwable $error) {
					$summary['errors'][] = $error->getMessage();
				}
			}
		}
	}

	/** Verknüpft verwaiste Ansprüche (CREATING, älter als 10 Minuten) mit einer bereits erzeugten Aufgabe. */
	private function recoverStaleClaims(int $caseId): void {
		$query = $this->db->getQueryBuilder();
		$claims = $query->select('id', 'step_id', 'context_key')->from('bestatter_process_runs')->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->andWhere($query->expr()->eq('run_status', $query->createNamedParameter('CREATING')))
			->andWhere($query->expr()->lt('updated_at', $query->createNamedParameter(date('c', time() - 600))))
			->executeQuery()->fetchAllAssociative();
		foreach ($claims as $claim) {
			$runId = (int)$claim['id'];
			try {
				$lookup = $this->db->getQueryBuilder();
				$candidates = $lookup->select('id', 'record_date', 'payload')->from('bestatter_records')
					->where($lookup->expr()->eq('case_id', $lookup->createNamedParameter($caseId)))
					->andWhere($lookup->expr()->eq('record_type', $lookup->createNamedParameter('task')))
					->andWhere($lookup->expr()->like('payload', $lookup->createNamedParameter('%"processRunId":' . $runId . '%')))
					->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
				$record = null;
				foreach ($candidates as $candidate) {
					$payload = json_decode((string)$candidate['payload'], true);
					if (is_array($payload) && (int)($payload['processRunId'] ?? 0) === $runId) { $record = $candidate; break; }
				}
				if ($record === null) continue;
				$update = $this->db->getQueryBuilder();
				$update->update('bestatter_process_runs')->set('record_id', $update->createNamedParameter((int)$record['id']))
					->set('run_status', $update->createNamedParameter('CREATED'))->set('updated_at', $update->createNamedParameter(date('c')));
				if ((string)($record['record_date'] ?? '') !== '') $update->set('due_date', $update->createNamedParameter((string)$record['record_date']));
				$update->where($update->expr()->eq('id', $update->createNamedParameter($runId)))
					->andWhere($update->expr()->eq('run_status', $update->createNamedParameter('CREATING')))->executeStatement();
				$this->logger->info('Verwaisten Ablaufschritt mit bereits angelegter Aufgabe verknüpft.', ['caseId' => $caseId, 'runId' => $runId, 'recordId' => (int)$record['id']]);
			} catch (\Throwable $error) {
				$this->logger->warning('Verwaister Ablaufschritt konnte nicht geprüft werden.', ['caseId' => $caseId, 'runId' => $runId, 'reason' => $error->getMessage()]);
			}
		}
	}

	private function isUniqueViolation(\Throwable $error): bool {
		if ($error instanceof \Doctrine\DBAL\Exception\UniqueConstraintViolationException) return true;
		if ($error instanceof \OCP\DB\Exception && $error->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) return true;
		$previous = $error->getPrevious();
		return $previous !== null && $this->isUniqueViolation($previous);
	}

	private function autoComplete(array $case, array $facts, array &$summary): int {
		$changes = 0;
		$steps = [];
		foreach ($this->allSteps() as $step) $steps[(int)$step['id']] = $step;
		foreach ($facts['runsByStep'] as $stepId => $runs) {
			$step = $steps[(int)$stepId] ?? null;
			if ($step === null || $step['complete']['type'] === 'NONE') continue;
			foreach ($runs as $run) {
				if ((int)$run['record_id'] <= 0 || !in_array((string)$run['record_status'], ['OFFEN', 'IN_BEARBEITUNG'], true)) continue;
				if (!empty($run['manually_reopened'])) continue;
				if (!$this->completionSatisfied($step, $facts, (string)$run['context_key'])) continue;
				try {
					$record = $this->records->get((int)$run['record_id'], (string)$run['record_owner']);
					$data = $record['data'];
					$data['autoCompleted'] = true;
					$data['autoCompletedReason'] = ProcessRules::COMPLETIONS[$step['complete']['type']] ?? '';
					$this->updateTask($record, 'ERLEDIGT', $data);
					$summary['completed']++;
					$changes++;
				} catch (\Throwable $error) {
					$summary['errors'][] = $error->getMessage();
				}
			}
		}
		return $changes;
	}

	/** Fristen folgen dem Termin: offene, nicht von Hand umdatierte Aufgaben wandern mit. */
	private function reschedule(array $facts, array &$summary): void {
		$steps = [];
		foreach ($this->allSteps() as $step) $steps[(int)$step['id']] = $step;
		foreach ($facts['runsByStep'] as $stepId => $runs) {
			$step = $steps[(int)$stepId] ?? null;
			if ($step === null) continue;
			foreach ($runs as $run) {
				if ((int)$run['record_id'] <= 0 || !in_array((string)$run['record_status'], ['OFFEN', 'IN_BEARBEITUNG'], true)) continue;
				$anchor = $this->contextAnchor((string)$run['context_key'], $facts);
				$reference = $this->referenceDate($step, $facts, $anchor ?? (string)$run['anchor_date']);
				if ($reference === null || substr($reference, 0, 10) === substr((string)$run['anchor_date'], 0, 10)) continue;
				if (substr((string)$run['record_date'], 0, 10) !== substr((string)$run['due_date'], 0, 10)) continue; // von Hand geändert
				[$due] = ProcessRules::dueDate($reference, (int)$step['dueOffsetDays'], $facts['today']);
				try {
					$record = $this->records->get((int)$run['record_id'], (string)$run['record_owner']);
					$this->groupware->update(array_replace($record, ['date' => $due]), (string)$run['record_owner']);
					$update = $this->db->getQueryBuilder();
					$update->update('bestatter_process_runs')->set('anchor_date', $update->createNamedParameter($reference))->set('due_date', $update->createNamedParameter($due))
						->set('updated_at', $update->createNamedParameter(date('c')))->where($update->expr()->eq('id', $update->createNamedParameter((int)$run['id'])))->executeStatement();
					$summary['rescheduled']++;
				} catch (\Throwable $error) {
					$summary['errors'][] = $error->getMessage();
				}
			}
		}
	}

	private function triggerContexts(array $step, array $facts): array {
		$trigger = $step['trigger'];
		$today = $facts['today'];
		switch ($trigger['type']) {
			case 'CASE_CREATED': return [[self::CASE_CONTEXT, $facts['caseCreated']]];
			case 'FUNERAL_TYPE_SET': return $facts['scope'] !== '' ? [[self::CASE_CONTEXT, $today]] : [];
			case 'ORDER_COMMISSIONED': return $facts['orderCommissioned'] ? [[self::CASE_CONTEXT, $facts['orderDate'] ?: $today]] : [];
			case 'DEPOSIT_AGREED': return $facts['depositCents'] > 0 ? [[self::CASE_CONTEXT, $today]] : [];
			case 'FINAL_INVOICE_ISSUED': return $facts['finalInvoice'] !== null ? [['invoice:' . (int)$facts['finalInvoice']['id'], $today]] : [];
			case 'PAYMENT_RECEIVED': return $facts['paid'] ? [['invoice:' . (int)$facts['finalInvoice']['id'], $today]] : [];
			case 'INCOMING_INVOICE':
				return array_map(static fn(array $incoming): array => ['incoming:' . $incoming['id'], $incoming['date'] ?: $today], $facts['incoming']);
			case 'STEP_DONE':
				$doneKey = (int)$step['templateId'] . '|' . $trigger['stepKey'];
				return isset($facts['doneStepKeys'][$doneKey]) ? [[self::CASE_CONTEXT, $today]] : [];
			case 'SCHEDULE_CONFIRMED':
			case 'SCHEDULE_DONE':
				$contexts = [];
				foreach ($facts['schedules'] as $schedule) {
					if (!in_array($schedule['presetKey'], $trigger['presetKeys'], true)) continue;
					if ($trigger['type'] === 'SCHEDULE_CONFIRMED' ? !$schedule['confirmed'] : !$schedule['done']) continue;
					$contexts[] = [$trigger['once'] ? '' : 'schedule:' . $schedule['id'], $schedule['date']];
					if ($trigger['once']) break;
				}
				return $contexts;
			case 'SCHEDULE_CHANGED':
				$contexts = [];
				foreach ($facts['scheduleChanges'] as $change) {
					if (!in_array($change['presetKey'], $trigger['presetKeys'], true)) continue;
					$contexts[] = [$trigger['once'] ? self::CASE_CONTEXT : 'change:' . $change['id'], $change['changedAt'] ?: $today];
					if ($trigger['once']) break;
				}
				return $contexts;
			case 'SCHEDULE_CANCELLED':
				$contexts = [];
				foreach ($facts['cancelledSchedules'] as $cancelled) {
					if (!in_array($cancelled['presetKey'], $trigger['presetKeys'], true)) continue;
					$contexts[] = [$trigger['once'] ? self::CASE_CONTEXT : 'cancel:' . $cancelled['id'], $cancelled['cancelledAt'] ?: $today];
					if ($trigger['once']) break;
				}
				return $contexts;
			case 'FIELD_SET':
				return ProcessRules::fieldMatches($facts['master'][$trigger['field']] ?? null, (string)$trigger['value']) ? [[self::CASE_CONTEXT, $today]] : [];
			case 'CASE_STATUS':
				return $facts['caseStatus'] === $trigger['status'] ? [[self::CASE_CONTEXT, $trigger['status'] === 'ABGESCHLOSSEN' && $facts['closedAt'] !== '' ? $facts['closedAt'] : $today]] : [];
			case 'QUOTE_CREATED':
				return $facts['quoteCreated'] !== '' ? [[self::CASE_CONTEXT, $facts['quoteCreated']]] : [];
			case 'ORDER_SIGNED':
				return $facts['orderSigned'] !== null ? [[self::CASE_CONTEXT, $facts['orderSigned']]] : [];
			case 'SIDE_ORDER_COMMISSIONED':
			case 'SIDE_ORDER_CLOSED':
				$wanted = $trigger['type'] === 'SIDE_ORDER_CLOSED' ? ['ABGESCHLOSSEN'] : ['BEAUFTRAGT', 'ABGESCHLOSSEN'];
				return array_values(array_map(static fn(array $side): array => ['side:' . $side['id'], $side['updated'] ?: $today], array_filter($facts['sideOrders'], static fn(array $side): bool => in_array($side['status'], $wanted, true))));
			case 'DOCUMENT_CREATED':
			case 'DOCUMENT_FINAL':
				foreach ($facts['documents'] as $document) {
					if ($document['key'] !== $trigger['templateKey']) continue;
					if ($trigger['type'] === 'DOCUMENT_FINAL' && !in_array($document['status'], ['FINAL', 'UNTERSCHRIEBEN'], true)) continue;
					return [['doc:' . $trigger['templateKey'], $trigger['type'] === 'DOCUMENT_FINAL' ? ($document['updated'] ?: $today) : ($document['created'] ?: $today)]];
				}
				return [];
			case 'DOCUMENT_SENT':
				return isset($facts['sentDocuments'][$trigger['templateKey']]) ? [['doc:' . $trigger['templateKey'], $facts['sentDocuments'][$trigger['templateKey']] ?: $today]] : [];
			case 'DEREGISTRATION_SENT':
			case 'DEREGISTRATION_CONFIRMED':
				$field = $trigger['type'] === 'DEREGISTRATION_CONFIRMED' ? 'confirmed' : 'sent';
				$contexts = [];
				foreach ($facts['deregistrations'] as $deregistration) {
					if (!$deregistration[$field] || ($trigger['templateKey'] !== '' && $deregistration['key'] !== $trigger['templateKey'])) continue;
					$contexts[] = ['dereg:' . $deregistration['id'], $deregistration[$field . 'At'] ?: $today];
				}
				return $contexts;
			case 'INVOICE_OVERDUE':
				$contexts = [];
				foreach ($facts['openInvoices'] as $invoice) {
					[$target, $overdue] = ProcessRules::invoiceOverdue($invoice['date'], $invoice['paymentTermDays'], (int)$trigger['days'], $today);
					if ($overdue) $contexts[] = ['invoice:' . $invoice['id'], $target];
				}
				return $contexts;
			case 'LABEL_SET':
				return ProcessRules::labelMatches($facts['labels'], (string)$trigger['label'], (string)($trigger['value'] ?? '')) ? [[self::CASE_CONTEXT, $facts['labels'][(string)$trigger['label']]['setAt'] ?: $today]] : [];
			case 'DOCUMENT_RECEIVED':
				// 0.80.0: einmal je Fall und Dokumentart, sobald ein Schriftstück dieser Art abgelegt ist (Upload oder Paperless).
				$receivedAt = $facts['receivedKinds'][strtoupper((string)($trigger['documentKind'] ?? ''))] ?? null;
				return $receivedAt !== null ? [['docin:' . strtoupper((string)$trigger['documentKind']), $receivedAt ?: $today]] : [];
			case 'LABEL_REMOVED':
				$removedAt = $facts['removedLabels'][(string)$trigger['label']] ?? null;
				return $removedAt !== null && !isset($facts['labels'][(string)$trigger['label']]) ? [[self::CASE_CONTEXT, $removedAt ?: $today]] : [];
			case 'DATE_REACHED':
				[$target, $reached] = ProcessRules::dateReached($this->dateReference((string)$trigger['dateRef'], $facts), (int)$trigger['days'], (int)$trigger['leadDays'], $today);
				return $target !== null && $reached ? [[self::CASE_CONTEXT, $target]] : [];
		}
		return [];
	}

	/** Bezugsdatum für „Datum erreicht“; null = (noch) nicht bekannt. */
	private function dateReference(string $key, array $facts): ?string {
		$value = match ($key) {
			'DEATH_DATE' => $facts['deathDate'],
			'CEREMONY' => $facts['ceremonyDate'] ?? '',
			'BURIAL' => $facts['burialDate'] ?? '',
			'CASE_CREATED' => $facts['caseCreated'],
			'CASE_CLOSED' => $facts['caseStatus'] === 'ABGESCHLOSSEN' ? $facts['closedAt'] : '',
			default => '',
		};
		return trim((string)$value) !== '' ? substr(trim((string)$value), 0, 10) : null;
	}

	private function completionSatisfied(array $step, array $facts, string $contextKey): bool {
		$complete = $step['complete'];
		switch ($complete['type']) {
			case 'FUNERAL_TYPE_SET': return $facts['scope'] !== '';
			case 'ORDER_COMMISSIONED': return $facts['orderCommissioned'];
			case 'DOCUMENT_CREATED': return isset($facts['documentKeys'][strtoupper((string)$complete['templateKey']) ?: (string)($step['trigger']['templateKey'] ?? '')]);
			case 'DEREGISTRATIONS_CREATED': return $facts['deregistrationCount'] > 0;
			case 'DEREGISTRATIONS_SENT': return $facts['deregistrationCount'] > 0 && $facts['deregistrationsOpen'] === 0;
			case 'DEPOSIT_RECEIVED': return $facts['depositReceived'];
			case 'FINAL_INVOICE_ISSUED': return $facts['finalInvoice'] !== null;
			case 'PAYMENT_RECEIVED': return $facts['paid'];
			case 'FIELD_SET': return ProcessRules::fieldMatches($facts['master'][$complete['field'] ?? ''] ?? null, (string)($complete['value'] ?? ''));
			case 'CASE_STATUS': return $facts['caseStatus'] === (string)($complete['status'] ?? '');
			case 'LABEL_SET': return ProcessRules::labelMatches($facts['labels'], (string)($complete['label'] ?? ''), (string)($complete['value'] ?? ''));
			case 'DOCUMENT_RECEIVED': return isset($facts['receivedKinds'][strtoupper((string)(($complete['documentKind'] ?? '') ?: ($step['trigger']['documentKind'] ?? '')))]);
			case 'LABEL_REMOVED': return isset($facts['removedLabels'][(string)($complete['label'] ?? '')]) && !isset($facts['labels'][(string)($complete['label'] ?? '')]);
			case 'QUOTE_CREATED': return $facts['quoteCreated'] !== '';
			case 'ORDER_SIGNED': return $facts['orderSigned'] !== null;
			case 'SIDE_ORDER_CLOSED':
				foreach ($facts['sideOrders'] as $side) {
					if (str_starts_with($contextKey, 'side:') && 'side:' . $side['id'] !== $contextKey) continue;
					if ($side['status'] === 'ABGESCHLOSSEN') return true;
				}
				return false;
			case 'DOCUMENT_FINAL':
			case 'DOCUMENT_SENT':
				$key = strtoupper((string)($complete['templateKey'] ?? '')) ?: (string)($step['trigger']['templateKey'] ?? '');
				if ($key === '') return false;
				if ($complete['type'] === 'DOCUMENT_SENT') return isset($facts['sentDocuments'][$key]);
				foreach ($facts['documents'] as $document) if ($document['key'] === $key && in_array($document['status'], ['FINAL', 'UNTERSCHRIEBEN'], true)) return true;
				return false;
			case 'DEREGISTRATION_SENT':
			case 'DEREGISTRATION_CONFIRMED':
				$field = $complete['type'] === 'DEREGISTRATION_CONFIRMED' ? 'confirmed' : 'sent';
				$key = strtoupper((string)($complete['templateKey'] ?? ''));
				foreach ($facts['deregistrations'] as $deregistration) {
					if (str_starts_with($contextKey, 'dereg:')) { if ('dereg:' . $deregistration['id'] === $contextKey) return $deregistration[$field]; continue; }
					if (($key === '' || $deregistration['key'] === $key) && $deregistration[$field]) return true;
				}
				return false;
			case 'INCOMING_TRANSFERRED':
				if (!str_starts_with($contextKey, 'incoming:')) return false;
				foreach ($facts['incoming'] as $incoming) if ('incoming:' . $incoming['id'] === $contextKey) return $incoming['transferred'];
				return false;
			case 'SCHEDULE_CONFIRMED':
			case 'SCHEDULE_DONE':
				$field = $complete['type'] === 'SCHEDULE_DONE' ? 'done' : 'confirmed';
				foreach ($facts['schedules'] as $schedule) {
					if (str_starts_with($contextKey, 'schedule:')) { if ('schedule:' . $schedule['id'] === $contextKey) return $schedule[$field]; continue; }
					$presets = $complete['presetKey'] !== '' ? [$complete['presetKey']] : $step['trigger']['presetKeys'];
					if (in_array($schedule['presetKey'], $presets, true) && $schedule[$field]) return true;
				}
				return false;
		}
		return false;
	}

	private function referenceDate(array $step, array $facts, string $anchor): ?string {
		return match ($step['dueReference']) {
			'CASE_CREATED' => $facts['caseCreated'],
			'DEATH_DATE' => $facts['deathDate'] !== '' ? $facts['deathDate'] : $anchor,
			'CEREMONY' => $facts['ceremonyDate'] ?? $anchor,
			'BURIAL' => $facts['burialDate'] ?? $anchor,
			default => $anchor !== '' ? $anchor : $facts['today'],
		};
	}

	private function contextAnchor(string $contextKey, array $facts): ?string {
		if (!str_starts_with($contextKey, 'schedule:')) return null;
		foreach ($facts['schedules'] as $schedule) if ('schedule:' . $schedule['id'] === $contextKey) return $schedule['date'];
		return null;
	}

	private function contextLabel(string $contextKey, array $facts): string {
		if (str_starts_with($contextKey, 'schedule:')) {
			foreach ($facts['schedules'] as $schedule) if ('schedule:' . $schedule['id'] === $contextKey) {
				return trim($schedule['title'] . ' ' . (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string)$schedule['date'], $m) ? $m[3] . '.' . $m[2] . '.' : ''));
			}
		}
		if (str_starts_with($contextKey, 'incoming:')) {
			foreach ($facts['incoming'] as $incoming) if ('incoming:' . $incoming['id'] === $contextKey) return trim($incoming['supplier'] . ' ' . $incoming['number']);
		}
		$short = static fn(string $date): string => preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m) ? $m[3] . '.' . $m[2] . '.' : '';
		if (str_starts_with($contextKey, 'change:')) {
			foreach ($facts['scheduleChanges'] as $change) if ('change:' . $change['id'] === $contextKey) return trim($change['title'] . ' → ' . $short($change['date']));
		}
		if (str_starts_with($contextKey, 'cancel:')) {
			foreach ($facts['cancelledSchedules'] as $cancelled) if ('cancel:' . $cancelled['id'] === $contextKey) return trim($cancelled['title'] . ' ' . $short($cancelled['date']));
		}
		if (str_starts_with($contextKey, 'dereg:')) return (string)($facts['deregistrations'][(int)substr($contextKey, 6)]['title'] ?? '');
		if (str_starts_with($contextKey, 'docin:')) return (string)($this->documentKinds->definition(substr($contextKey, 6))['name'] ?? substr($contextKey, 6));
		if (str_starts_with($contextKey, 'side:')) {
			foreach ($facts['sideOrders'] as $side) if ('side:' . $side['id'] === $contextKey) return 'Nebenauftrag ' . $side['number'];
		}
		if (str_starts_with($contextKey, 'invoice:')) {
			foreach ($facts['openInvoices'] as $invoice) if ('invoice:' . $invoice['id'] === $contextKey) return 'Rechnung ' . $invoice['number'];
		}
		return '';
	}

	/**
	 * Zuständigkeit nach Rolle: Die Fallverantwortung bekommt die Aufgabe, wenn sie der Rolle
	 * angehört; sonst die aktuelle Person (falls in der Rolle), sonst das erste Rollenmitglied.
	 * Alle Rollenmitglieder werden als weitere Zuständige eingetragen und sehen die Aufgabe.
	 */
	private function resolveAssignment(string $role, array $case): array {
		$current = $this->userSession->getUser()?->getUID() ?? '';
		$responsible = trim((string)($case['responsibleEmployee'] ?? ''));
		if ($role === '' ) return ['uid' => $current, 'uids' => [], 'unresolved' => false];
		if ($role === 'FALLVERANTWORTLICH') return ['uid' => $responsible !== '' && $this->team->member($responsible) !== null ? $responsible : $current, 'uids' => [], 'unresolved' => $responsible === ''];
		$group = $this->installation->roleGroups()[$role] ?? '';
		$members = $group === '' ? [] : array_map(static fn(array $member): string => $member['uid'], $this->groupMembers($group));
		if ($members === []) return ['uid' => $responsible !== '' && $this->team->member($responsible) !== null ? $responsible : $current, 'uids' => [], 'unresolved' => true];
		$uid = in_array($responsible, $members, true) ? $responsible : (in_array($current, $members, true) ? $current : $members[0]);
		return ['uid' => $uid, 'uids' => array_values(array_diff($members, [$uid])), 'unresolved' => false];
	}

	/** Mitglieder einer Rollengruppe, die zugleich der Bestatter-Mitgliedergruppe angehören. */
	private function groupMembers(string $groupName): array {
		$group = $this->groups->get($groupName);
		if ($group === null) return [];
		$result = [];
		foreach ($group->getUsers() as $user) {
			$member = $this->team->member($user->getUID());
			if ($member !== null) $result[] = $member;
		}
		usort($result, static fn(array $left, array $right): int => strcasecmp($left['displayName'], $right['displayName']));
		return $result;
	}

	private function updateTask(array $record, string $status, array $data): array {
		// Ablaufschritte sind Arbeit am Fall: Status wird im Namen des Aufgabeninhabers gesetzt, damit
		// Autoerledigung und Kolleg*innen der Rolle nicht an der datensatzbezogenen Sichtprüfung scheitern.
		return $this->groupware->update(array_replace($record, ['status' => $status, 'data' => $data]), (string)($record['ownerUid'] ?? ''));
	}

	private function runWithRecord(int $runId): array {
		$query = $this->db->getQueryBuilder();
		$run = $query->select('*')->from('bestatter_process_runs')->where($query->expr()->eq('id', $query->createNamedParameter($runId)))->executeQuery()->fetchAssociative();
		if ($run === false) throw new \InvalidArgumentException('Ablaufschritt wurde nicht gefunden.');
		$record = null;
		if ((int)$run['record_id'] > 0) {
			$owner = $this->db->getQueryBuilder();
			$ownerUid = $owner->select('owner_uid')->from('bestatter_records')->where($owner->expr()->eq('id', $owner->createNamedParameter((int)$run['record_id'])))->executeQuery()->fetchOne();
			if ($ownerUid !== false) $record = $this->records->get((int)$run['record_id'], (string)$ownerUid);
		}
		return [$run, $record];
	}

	// ------------------------------------------------------------------ Fallzustand

	/** Bestandsschutz: Schritt gehörte zur Vorlage, als der Ablauf des Falls begann. */
	private function stepAppliesToCaseVersion(array $step, array $facts): bool {
		if (($step['introducedAt'] ?? '') === '' || ($facts['processStart'] ?? '') === '') return true;
		return strtotime($step['introducedAt'] . ' UTC') <= strtotime($facts['processStart'] . ' UTC');
	}

	/** bestatter_cases.created_at ist UTC; Fristen rechnen in Ortszeit (wie "heute"). */
	private function localDate(string $utc): string {
		if ($utc === '') return '';
		try {
			return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');
		} catch (\Throwable) {
			return substr($utc, 0, 10);
		}
	}

		private function caseCreatedAt(int $caseId): string {
		$query = $this->db->getQueryBuilder();
		return (string)$query->select('created_at')->from('bestatter_cases')->where($query->expr()->eq('id', $query->createNamedParameter($caseId)))->executeQuery()->fetchOne();
	}

	private function facts(array $case): array {
		$caseId = (int)$case['id'];
		$labelFacts = $this->labels->processFacts($caseId);
		$labelNames = $this->labels->names();
		$master = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$zone = new \DateTimeZone('Europe/Berlin');
		$now = new \DateTimeImmutable('now', $zone);
		$funeral = ProcessRules::funeralFacts((string)($case['burialVariantCode'] ?: ($master['burial_variant_code'] ?? '')), (string)($case['funeralType'] ?: ($master['funeral_type'] ?? '')), $this->customizing->valuesForKey('BURIAL_VARIANT'));
		$pension = (string)($master['receives_pension'] ?? '');
		if ($pension === '' && trim((string)($master['pension_insurance_number'] ?? '')) !== '') $pension = '1';
		$commercial = $this->commercialState->state($caseId);

		$records = $this->db->getQueryBuilder();
		$rows = $records->select('id', 'record_type', 'title', 'record_date', 'status', 'payload', 'owner_uid', 'assignee_uid', 'assignee_name', 'created_at', 'updated_at')->from('bestatter_records')
			->where($records->expr()->eq('case_id', $records->createNamedParameter($caseId)))
			->andWhere($records->expr()->in('record_type', $records->createNamedParameter(['schedule', 'task', 'document', 'deregistration'], IQueryBuilder::PARAM_STR_ARRAY)))
			->executeQuery()->fetchAllAssociative();
		$schedules = []; $documentKeys = []; $receivedKinds = []; $deregistrationCount = 0; $deregistrationsOpen = 0; $tasksById = []; $openCaseTasks = [];
		$cancelledSchedules = []; $scheduleMeta = []; $documents = []; $deregistrations = []; $orderSigned = null;
		foreach ($rows as $row) {
			$payload = json_decode((string)$row['payload'], true) ?: [];
			$type = (string)$row['record_type'];
			$status = strtoupper((string)$row['status']);
			if ($type === 'schedule') {
				$scheduleMeta[(int)$row['id']] = ['title' => (string)$row['title'], 'date' => (string)$row['record_date'], 'presetKey' => strtoupper((string)($payload['schedulePresetKey'] ?? $payload['scheduleTypeKey'] ?? ''))];
				if ($status === 'ABGESAGT') {
					$cancelledSchedules[(int)$row['id']] = ['id' => (int)$row['id'], 'title' => (string)$row['title'], 'date' => (string)$row['record_date'], 'presetKey' => $scheduleMeta[(int)$row['id']]['presetKey'], 'cancelledAt' => $this->localDate((string)$row['updated_at'])];
					continue;
				}
				$date = (string)$row['record_date'];
				$confirmed = in_array($status, ['BESTAETIGT', 'ERLEDIGT'], true);
				$end = null;
				try { $end = (new \DateTimeImmutable($date !== '' ? $date : 'now', $zone))->modify('+' . max(15, (int)($payload['durationMinutes'] ?? 60)) . ' minutes'); } catch (\Throwable) {}
				$schedules[] = [
					'id' => (int)$row['id'], 'title' => (string)$row['title'], 'date' => $date, 'status' => $status,
					'presetKey' => strtoupper((string)($payload['schedulePresetKey'] ?? $payload['scheduleTypeKey'] ?? '')),
					'confirmed' => $confirmed, 'done' => $status === 'ERLEDIGT' || ($confirmed && $end !== null && $end < $now),
				];
			} elseif ($type === 'document') {
				$key = strtoupper((string)($payload['templateKey'] ?? ''));
				if ($key !== '') $documentKeys[$key] = true;
				// 0.80.0: eingegangene Schriftstücke je Dokumentart (frühestes Datum).
				$kind = strtoupper((string)($payload['documentKind'] ?? ''));
				if ($kind !== '') {
					$received = $this->localDate((string)$row['created_at']);
					if (!isset($receivedKinds[$kind]) || $received < $receivedKinds[$kind]) $receivedKinds[$kind] = $received;
				}
				$documents[(int)$row['id']] = ['id' => (int)$row['id'], 'key' => $key, 'title' => (string)$row['title'], 'status' => $status,
					'created' => $this->localDate((string)$row['created_at']), 'updated' => $this->localDate((string)$row['updated_at'])];
				$review = is_array($payload['paperSignature']['review'] ?? null) ? $payload['paperSignature']['review'] : null;
				if ($status === 'UNTERSCHRIEBEN' && $review !== null) {
					$signedAt = $this->localDate((string)($review['reviewedAt'] ?? '')) ?: $this->localDate((string)$row['updated_at']);
					if ($orderSigned === null || $signedAt < $orderSigned) $orderSigned = $signedAt;
				}
			} elseif ($type === 'deregistration') {
				$deregistrationCount++;
				if (!in_array($status, self::SENT_DEREGISTRATION_STATUSES, true)) $deregistrationsOpen++;
				$deregistrations[(int)$row['id']] = ['id' => (int)$row['id'], 'key' => strtoupper((string)($payload['templateKey'] ?? '')), 'title' => (string)$row['title'], 'status' => $status,
					'sent' => in_array($status, self::SENT_DEREGISTRATION_STATUSES, true), 'confirmed' => in_array($status, ['BESTAETIGT', 'ERLEDIGT'], true),
					'sentAt' => $this->localDate((string)($payload['sentAt'] ?? '')) ?: $this->localDate((string)$row['updated_at']),
					'confirmedAt' => $this->localDate((string)($payload['confirmedAt'] ?? '')) ?: $this->localDate((string)$row['updated_at'])];
			} elseif ($type === 'task') {
				$tasksById[(int)$row['id']] = $row + ['data' => $payload];
				if ($status !== 'ERLEDIGT') $openCaseTasks[] = ['id' => (int)$row['id'], 'processStepId' => (int)($payload['processStepId'] ?? 0)];
			}
		}
		usort($schedules, static fn(array $left, array $right): int => strcmp($left['date'], $right['date']));
		$ceremonyDate = null; $burialDate = null;
		foreach ($schedules as $schedule) {
			if (!$schedule['confirmed']) continue;
			if ($ceremonyDate === null && in_array($schedule['presetKey'], self::CEREMONY_PRESETS, true)) $ceremonyDate = $schedule['date'];
			if ($burialDate === null && in_array($schedule['presetKey'], self::BURIAL_PRESETS, true)) $burialDate = $schedule['date'];
		}

		$invoice = $this->db->getQueryBuilder();
		$finalInvoice = $invoice->select('id', 'invoice_number', 'status', 'gross_cents', 'updated_at')->from('bestatter_invoices')
			->where($invoice->expr()->eq('case_id', $invoice->createNamedParameter($caseId)))
			->andWhere($invoice->expr()->isNull('side_order_id'))
			->andWhere($invoice->expr()->eq('invoice_type', $invoice->createNamedParameter('FINAL')))
			->andWhere($invoice->expr()->in('status', $invoice->createNamedParameter(self::ISSUED_INVOICE_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'DESC')->setMaxResults(1)->executeQuery()->fetchAssociative() ?: null;

		$incomingQuery = $this->db->getQueryBuilder();
		$incoming = array_map(static fn(array $row): array => [
			'id' => (int)$row['id'], 'supplier' => (string)$row['supplier_name'], 'number' => (string)$row['supplier_invoice_no'],
			'date' => (string)($row['received_date'] ?: $row['invoice_date']), 'transferred' => in_array((string)$row['status'], ['ZUGEORDNET', 'FREIGEGEBEN'], true),
		], $incomingQuery->select('id', 'supplier_name', 'supplier_invoice_no', 'invoice_date', 'received_date', 'status')->from('bestatter_incoming_invoices')
			->where($incomingQuery->expr()->eq('case_id', $incomingQuery->createNamedParameter($caseId)))
			->andWhere($incomingQuery->expr()->neq('status', $incomingQuery->createNamedParameter('ABGELEHNT')))
			->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative());

		[$scheduleChanges, $confirmedEver] = $this->scheduleHistory($caseId, $scheduleMeta);
		foreach ($cancelledSchedules as $id => $cancelled) if (!isset($confirmedEver[$id])) unset($cancelledSchedules[$id]);

		// Digitale Bestätigung im Auftrag (Unterschriftsname) zählt wie ein geprüfter Papiervertrag.
		if (trim((string)($master['order_signature_name'] ?? '')) !== '') {
			$digital = trim((string)($master['order_signature_date'] ?? '')) ?: $now->format('Y-m-d');
			if ($orderSigned === null || $digital < $orderSigned) $orderSigned = substr($digital, 0, 10);
		}

		$quoteQuery = $this->db->getQueryBuilder();
		$quoteCreated = (string)$quoteQuery->select($quoteQuery->func()->min('created_at'))->from('bestatter_commercial_docs')
			->where($quoteQuery->expr()->eq('case_id', $quoteQuery->createNamedParameter($caseId)))
			->andWhere($quoteQuery->expr()->eq('document_type', $quoteQuery->createNamedParameter('QUOTE')))
			->andWhere($quoteQuery->expr()->isNull('side_order_id'))->executeQuery()->fetchOne();

		$mailQuery = $this->db->getQueryBuilder();
		$sentDocuments = [];
		foreach ($mailQuery->select('record_id', 'created_at')->from('bestatter_mail_outbox')
			->where($mailQuery->expr()->eq('case_id', $mailQuery->createNamedParameter($caseId)))
			->andWhere($mailQuery->expr()->eq('status', $mailQuery->createNamedParameter('ACCEPTED')))
			->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative() as $mail) {
			$document = $documents[(int)$mail['record_id']] ?? null;
			if ($document === null || $document['key'] === '') continue;
			$sentDocuments[$document['key']] ??= $this->localDate((string)$mail['created_at']);
		}

		$sideQuery = $this->db->getQueryBuilder();
		$sideOrders = array_map(fn(array $row): array => ['id' => (int)$row['id'], 'number' => (string)$row['side_order_number'], 'status' => strtoupper((string)$row['status']), 'updated' => $this->localDate((string)$row['updated_at'])],
			$sideQuery->select('id', 'side_order_number', 'status', 'updated_at')->from('bestatter_side_orders')
				->where($sideQuery->expr()->eq('case_id', $sideQuery->createNamedParameter($caseId)))->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative());

		$openInvoiceQuery = $this->db->getQueryBuilder();
		$openInvoices = array_map(function (array $row): array {
			$snapshot = json_decode((string)($row['payment_snapshot'] ?? ''), true);
			return ['id' => (int)$row['id'], 'number' => (string)$row['invoice_number'], 'date' => $this->localDate((string)$row['created_at']),
				'paymentTermDays' => is_array($snapshot) && isset($snapshot['paymentTermDays']) ? (int)$snapshot['paymentTermDays'] : 14];
		}, $openInvoiceQuery->select('id', 'invoice_number', 'created_at', 'payment_snapshot')->from('bestatter_invoices')
			->where($openInvoiceQuery->expr()->eq('case_id', $openInvoiceQuery->createNamedParameter($caseId)))
			->andWhere($openInvoiceQuery->expr()->in('status', $openInvoiceQuery->createNamedParameter(['FREIGEGEBEN', 'VERSENDET', 'TEILBEZAHLT'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($openInvoiceQuery->expr()->eq('document_kind', $openInvoiceQuery->createNamedParameter('INVOICE')))
			->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative());

		$orderDate = '';
		if (in_array($commercial['effectiveOrderStatus'], ['beauftragt', 'abgeschlossen'], true) && ($commercial['documentType'] ?? '') === 'ORDER' && (int)($commercial['documentId'] ?? 0) > 0) {
			$doc = $this->db->getQueryBuilder();
			$orderDate = (string)$doc->select('created_at')->from('bestatter_commercial_docs')->where($doc->expr()->eq('id', $doc->createNamedParameter((int)$commercial['documentId'])))->executeQuery()->fetchOne();
		}

		// Hängengebliebene Ansprüche (Anfrage während der Aufgabenanlage abgebrochen) nach 10 Minuten
		// bereinigen; jüngere zählen als "in Arbeit" und werden nicht erneut angelegt. Wurde die Aufgabe
		// schon angelegt und nur die Verknüpfung nicht gespeichert, wird sie über die Prozesslauf-Kennung
		// wiedergefunden und verknüpft statt ein zweites Mal angelegt.
		$this->recoverStaleClaims($caseId);
		$stale = $this->db->getQueryBuilder();
		$stale->delete('bestatter_process_runs')->where($stale->expr()->eq('case_id', $stale->createNamedParameter($caseId)))
			->andWhere($stale->expr()->eq('run_status', $stale->createNamedParameter('CREATING')))
			->andWhere($stale->expr()->lt('updated_at', $stale->createNamedParameter(date('c', time() - 600))))
			->executeStatement();
		$runsQuery = $this->db->getQueryBuilder();
		$allRunRows = $runsQuery->select('*')->from('bestatter_process_runs')->where($runsQuery->expr()->eq('case_id', $runsQuery->createNamedParameter($caseId)))
			->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		$runRows = [];
		$creatingIndex = [];
		foreach ($allRunRows as $row) {
			if ((string)$row['run_status'] === 'CREATING') $creatingIndex[(int)$row['step_id'] . '|' . (string)$row['context_key']] = true;
			else $runRows[] = $row;
		}
		$stepTemplates = [];
		foreach ($this->allSteps() as $step) $stepTemplates[(int)$step['id']] = $step;
		$runsByStep = []; $runIndex = []; $doneStepKeys = [];
		foreach ($runRows as $run) {
			$task = $tasksById[(int)($run['record_id'] ?? 0)] ?? null;
			$step = $stepTemplates[(int)$run['step_id']] ?? null;
			$recordStatus = $task === null ? ((string)$run['run_status'] === 'SKIPPED_AUTO' ? 'ERLEDIGT' : 'GELOESCHT') : strtoupper((string)$task['status']);
			$run['record_status'] = $recordStatus;
			$run['record_title'] = $task['title'] ?? ($step['title'] ?? $run['step_key']);
			$run['record_date'] = (string)($task['record_date'] ?? '');
			$run['record_owner'] = (string)($task['owner_uid'] ?? '');
			$run['record_assignee'] = (string)($task['assignee_uid'] ?? '');
			$run['record_assignee_name'] = (string)($task['assignee_name'] ?? '');
			$run['record_data'] = $task['data'] ?? [];
			$run['required'] = (bool)($task['data']['processRequired'] ?? ($step['required'] ?? false));
			$run['manually_reopened'] = !empty($task['data']['manuallyReopened']);
			$runsByStep[(int)$run['step_id']][] = $run;
			$runIndex[(int)$run['step_id'] . '|' . (string)$run['context_key']] = true;
			if ($step !== null && in_array($recordStatus, ['ERLEDIGT', 'GELOESCHT'], true)) $doneStepKeys[(int)$step['templateId'] . '|' . $step['key']] = true;
		}
		return [
			'today' => $now->format('Y-m-d'),
			'caseCreated' => $this->localDate($this->caseCreatedAt($caseId)),
			'processStart' => $this->caseCreatedAt($caseId),
			'deathDate' => (string)($case['dateOfDeath'] ?: ($master['date_of_death'] ?? '')),
			'scope' => $funeral['scope'], 'nature' => $funeral['nature'], 'grave' => $funeral['grave'],
			'ceremony' => (string)($master['with_funeral_ceremony'] ?? ''),
			'pension' => $pension,
			'depositCents' => (int)($master['deposit_amount_cents'] ?? 0),
			'depositReceived' => trim((string)($master['deposit_received_at'] ?? '')) !== '',
			'depositReceivedAt' => (string)($master['deposit_received_at'] ?? ''),
			'orderCommissioned' => in_array($commercial['effectiveOrderStatus'], ['beauftragt', 'abgeschlossen'], true),
			'orderDate' => substr($orderDate, 0, 10),
			'closedWithoutInvoice' => (bool)($commercial['closedWithoutInvoice'] ?? false),
			'cancelled' => mb_strtolower((string)($commercial['effectiveOrderStatus'] ?? '')) === 'storniert' || strtoupper((string)$case['status']) === 'STORNIERT',
			'finalInvoice' => $finalInvoice,
			'paid' => $finalInvoice !== null && (string)$finalInvoice['status'] === 'BEZAHLT',
			'schedules' => $schedules, 'ceremonyDate' => $ceremonyDate, 'burialDate' => $burialDate,
			'incoming' => $incoming,
			'documentKeys' => $documentKeys,
			'deregistrationCount' => $deregistrationCount, 'deregistrationsOpen' => $deregistrationsOpen,
			'runsByStep' => $runsByStep, 'runIndex' => $runIndex + $creatingIndex, 'doneStepKeys' => $doneStepKeys,
			'openCaseTasks' => $openCaseTasks,
			// 0.68.0 – weitere Auslöser
			'master' => $master, 'caseStatus' => strtoupper((string)$case['status']),
			'closedAt' => substr(trim((string)($master['closed_at'] ?? '')), 0, 10),
			'quoteCreated' => $this->localDate($quoteCreated),
			'orderSigned' => $orderSigned,
			'documents' => $documents, 'sentDocuments' => $sentDocuments,
			'deregistrations' => $deregistrations,
			'cancelledSchedules' => array_values($cancelledSchedules), 'scheduleChanges' => $scheduleChanges,
			'sideOrders' => $sideOrders, 'openInvoices' => $openInvoices,
			// 0.76.0 – Marker
			'labels' => $labelFacts['labels'], 'removedLabels' => $labelFacts['removedLabels'], 'labelNames' => $labelNames,
			'receivedKinds' => $receivedKinds,
		];
	}

	/**
	 * Terminänderungen und -bestätigungen aus dem Terminprotokoll (bestatter_schedule_history).
	 * Als „geändert“ zählt eine Änderung von Zeitpunkt oder Ort an einem bereits bestätigten Termin.
	 * @return array{0:list<array>,1:array<int,bool>} [Änderungen, Termine, die jemals bestätigt waren]
	 */
	private function scheduleHistory(int $caseId, array $scheduleMeta): array {
		if ($scheduleMeta === []) return [[], []];
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('id', 'record_id', 'action', 'snapshot_json', 'changes_json', 'created_at')->from('bestatter_schedule_history')
			->where($query->expr()->in('record_id', $query->createNamedParameter(array_keys($scheduleMeta), IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		$confirmedStatuses = ['BESTAETIGT', 'GEAENDERT', 'ERLEDIGT'];
		$changes = []; $confirmedEver = [];
		foreach ($rows as $row) {
			$recordId = (int)$row['record_id'];
			$snapshot = json_decode((string)$row['snapshot_json'], true) ?: [];
			$diff = json_decode((string)$row['changes_json'], true) ?: [];
			$after = strtoupper((string)($snapshot['status'] ?? ''));
			$before = $after; $fields = [];
			foreach ($diff as $change) {
				$field = (string)($change['field'] ?? '');
				$fields[$field] = true;
				if ($field === 'status') $before = strtoupper((string)($change['before'] ?? ''));
			}
			if (in_array($before, $confirmedStatuses, true) || in_array($after, $confirmedStatuses, true)) $confirmedEver[$recordId] = true;
			if ((string)$row['action'] !== 'UPDATED' || !in_array($before, ['BESTAETIGT', 'GEAENDERT'], true)) continue;
			if (!isset($fields['date']) && !isset($fields['location'])) continue;
			$meta = $scheduleMeta[$recordId];
			$changes[] = ['id' => (int)$row['id'], 'scheduleId' => $recordId, 'title' => $meta['title'], 'presetKey' => $meta['presetKey'],
				'date' => (string)($snapshot['date'] ?? $meta['date']), 'changedAt' => $this->localDate((string)$row['created_at'])];
		}
		return [$changes, $confirmedEver];
	}

	/** Bereits ausgeführte Knöpfe je Aufgabe (bestatter_action_runs). */
	private function executedActions(array $recordIds): array {
		$recordIds = array_values(array_unique(array_filter($recordIds, static fn(int $id): bool => $id > 0)));
		if ($recordIds === []) return [];
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('source_record_id', 'action_key', 'run_status')->from('bestatter_action_runs')
			->where($query->expr()->in('source_record_id', $query->createNamedParameter($recordIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($query->expr()->in('run_status', $query->createNamedParameter(['RUNNING', 'COMPLETED'], IQueryBuilder::PARAM_STR_ARRAY)))
			->executeQuery()->fetchAllAssociative();
		$result = [];
		foreach ($rows as $row) $result[(int)$row['source_record_id'] . ':' . (string)$row['action_key']] = true;
		return $result;
	}

	private function runState(array $run, string $today): string {
		if ((string)$run['run_status'] === 'SKIPPED_AUTO') return 'autoskipped';
		$status = (string)$run['record_status'];
		if ($status === 'GELOESCHT') return 'deleted';
		if ($status === 'ERLEDIGT') return !empty($run['record_data']['skipped']) ? 'skipped' : 'done';
		return substr((string)$run['record_date'], 0, 10) !== '' && substr((string)$run['record_date'], 0, 10) < $today ? 'overdue' : 'open';
	}

	private function stepView(array $step, ?array $run, string $state, string $reason, string $uid, string $today): array {
		$data = $run['record_data'] ?? [];
		$assigneeUids = array_map('strval', (array)($data['assigneeUids'] ?? []));
		$accessible = $run !== null && $uid !== '' && ($uid === ($run['record_owner'] ?? '') || $uid === ($run['record_assignee'] ?? '') || in_array($uid, $assigneeUids, true));
		$actions = array_map(static fn(array $action): array => [
			'key' => $action['key'], 'type' => $action['type'], 'label' => $action['label'], 'view' => $action['view'] ?? '',
			'schedulePresetKey' => $action['schedulePresetKey'] ?? '', 'openForm' => (bool)($action['openForm'] ?? false),
			'templateKeys' => $action['templateKeys'] ?? [], 'repeatable' => (bool)($action['repeatable'] ?? false),
		], $step['actions']);
		return [
			'stepId' => (int)$step['id'], 'stepKey' => $step['key'], 'templateKey' => $step['templateKey'], 'templateName' => $step['templateName'],
			'phase' => (int)$step['phase'], 'title' => $run['record_title'] ?? $step['title'], 'description' => $step['description'],
			'state' => $state, 'reason' => $reason,
			'runId' => $run !== null ? (int)$run['id'] : null, 'recordId' => $run !== null && (int)$run['record_id'] > 0 ? (int)$run['record_id'] : null,
			'due' => $run['record_date'] ?? '', 'assigneeName' => $run['record_assignee_name'] ?? '', 'role' => $step['role'], 'roleLabel' => ProcessRules::ROLES[$step['role']] ?? '',
			'roleUnresolved' => !empty($data['roleUnresolved']),
			'priority' => $step['priority'], 'required' => (bool)$step['required'], 'shortNotice' => !empty($data['shortNotice']),
			'autoCompleted' => !empty($data['autoCompleted']), 'autoCompletedReason' => (string)($data['autoCompletedReason'] ?? ''),
			'skipReason' => (string)($data['skipReason'] ?? ''), 'accessible' => $accessible,
			'actions' => $actions, 'completion' => ProcessRules::COMPLETIONS[$step['complete']['type']] ?? '',
			// Für den Reiter „Termine“: Schritt vereinbart einen Termin, folgt aus einem Termin oder hakt sich über einen Termin ab.
			'scheduleRelated' => in_array((string)($step['trigger']['type'] ?? ''), ProcessRules::SCHEDULE_TRIGGERS, true)
				|| in_array($step['complete']['type'], ['SCHEDULE_CONFIRMED', 'SCHEDULE_DONE'], true)
				|| in_array('SCHEDULE', array_column($actions, 'type'), true),
		];
	}

	private function caseFieldLabel(string $key): string {
		foreach ($this->caseFields() as $field) if ($field['key'] === $key) return $field['label'];
		return $key;
	}

	private function templateLabel(string $key, string $triggerType): string {
		$items = in_array($triggerType, ProcessRules::DEREGISTRATION_TRIGGERS, true) ? $this->configuration->deregistrations() : $this->configuration->documents();
		foreach ($items as $item) if (strtoupper((string)$item['key']) === $key) return (string)$item['name'];
		return $key;
	}

	private function waitingReason(array $step, ?array $facts = null): string {
		$trigger = $step['trigger'];
		$label = ProcessRules::TRIGGERS[$trigger['type']] ?? '';
		if ($trigger['type'] === 'STEP_DONE') {
			foreach ($this->allSteps() as $candidate) if ((int)$candidate['templateId'] === (int)$step['templateId'] && $candidate['key'] === $trigger['stepKey']) return 'startet, sobald „' . $candidate['title'] . '“ erledigt ist';
		}
		if (in_array($trigger['type'], ProcessRules::SCHEDULE_TRIGGERS, true)) {
			$labels = array_map(fn(string $key): string => $this->presetLabel($key), $trigger['presetKeys']);
			$verb = ['SCHEDULE_DONE' => 'stattgefunden hat', 'SCHEDULE_CONFIRMED' => 'bestätigt ist', 'SCHEDULE_CHANGED' => 'geändert wird', 'SCHEDULE_CANCELLED' => 'abgesagt wird'][$trigger['type']];
			return 'startet, sobald ein Termin „' . implode('“ oder „', $labels) . '“ ' . $verb;
		}
		if ($trigger['type'] === 'DATE_REACHED' && $facts !== null) {
			[$target] = ProcessRules::dateReached($this->dateReference((string)$trigger['dateRef'], $facts), (int)$trigger['days'], (int)$trigger['leadDays'], $facts['today']);
			$reference = ProcessRules::DATE_REFERENCES[$trigger['dateRef']] ?? '';
			if ($target === null) return 'startet, sobald das Bezugsdatum „' . $reference . '“ feststeht';
			$create = (new \DateTimeImmutable($target))->modify('-' . (int)$trigger['leadDays'] . ' days')->format('d.m.Y');
			return 'entsteht am ' . $create . ' (Stichtag ' . (new \DateTimeImmutable($target))->format('d.m.Y') . ')';
		}
		if ($trigger['type'] === 'FIELD_SET') return 'startet, sobald das Stammdatenfeld „' . $this->caseFieldLabel((string)$trigger['field']) . '“' . ((string)$trigger['value'] !== '' ? ' den Wert „' . $trigger['value'] . '“ hat' : ' ausgefüllt ist');
		if (in_array($trigger['type'], ProcessRules::DOCUMENT_TRIGGERS, true) || in_array($trigger['type'], ProcessRules::DEREGISTRATION_TRIGGERS, true)) {
			return 'startet: ' . $label . ((string)$trigger['templateKey'] !== '' ? ' („' . $this->templateLabel((string)$trigger['templateKey'], $trigger['type']) . '“)' : '');
		}
		if (in_array($trigger['type'], ProcessRules::LABEL_TRIGGERS, true)) {
			$name = (string)($facts['labelNames'][(string)$trigger['label']] ?? $trigger['label']);
			return $trigger['type'] === 'LABEL_SET' ? 'startet, sobald der Marker „' . $name . '“' . ((string)($trigger['value'] ?? '') !== '' ? ' (' . $trigger['value'] . ')' : '') . ' gesetzt ist' : 'startet, sobald der Marker „' . $name . '“ entfernt wird';
		}
		if ($trigger['type'] === 'CASE_STATUS') return 'startet, sobald der Fall den Status „' . (ProcessRules::CASE_STATUSES[$trigger['status']] ?? $trigger['status']) . '“ hat';
		if ($trigger['type'] === 'DOCUMENT_RECEIVED') return 'startet, sobald ein Dokument „' . (string)($this->documentKinds->definition((string)$trigger['documentKind'])['name'] ?? $trigger['documentKind']) . '“ eingeht';
		if ($trigger['type'] === 'INVOICE_OVERDUE') return 'startet ' . ((int)$trigger['days'] > 0 ? (int)$trigger['days'] . ' Tag(e) nach dem Zahlungsziel einer offenen Rechnung' : 'am Zahlungsziel einer offenen Rechnung');
		return 'startet: ' . $label;
	}

	private ?array $presetLabels = null;

	private function presetLabel(string $key): string {
		if ($this->presetLabels === null) {
			$this->presetLabels = [];
			try {
				foreach ($this->scheduling->catalog(true)['types'] as $type) $this->presetLabels[$type['key']] = $type['name'];
				foreach ($this->actions->schedulePresets() as $preset) $this->presetLabels[$preset['key']] ??= $preset['label'];
			} catch (\Throwable) {}
		}
		return $this->presetLabels[$key] ?? $key;
	}

	private function attributesView(array $case, array $facts): array {
		return [
			'funeralScope' => $facts['scope'], 'funeralScopeLabel' => $facts['scope'] === '' ? 'noch offen' : (ProcessRules::SCOPES[$facts['nature'] ? 'NATURE' : $facts['scope']] ?? $facts['scope']),
			'funeralType' => (string)($case['funeralType'] ?? ''),
			'ceremony' => $facts['ceremony'], 'pension' => $facts['pension'],
			'depositCents' => $facts['depositCents'], 'depositReceivedAt' => $facts['depositReceivedAt'],
			'orderCommissioned' => $facts['orderCommissioned'],
			'finalInvoiceNumber' => (string)($facts['finalInvoice']['invoice_number'] ?? ''), 'paid' => $facts['paid'],
			'updatedAt' => (string)($case['updatedAt'] ?? ''),
		];
	}

	private function surchargeReason(array $rule, array $values): string {
		$dimension = strtoupper((string)$rule['dimension']);
		if ($dimension === 'PICKUP' && trim((string)$values['pickupTime']) !== '') { $time = strtotime((string)$values['pickupTime']); return 'Abholzeit ' . ($time !== false ? date('d.m.Y H:i', $time) . ' Uhr' : (string)$values['pickupTime']); }
		if ($dimension === 'HEIGHT_CM' && $values['heightCm'] !== null) return 'Körpergröße ' . (int)$values['heightCm'] . ' cm';
		if ($dimension === 'WEIGHT_KG' && $values['weightKg'] !== null) return 'Körpergewicht ' . (int)$values['weightKg'] . ' kg';
		return 'Im Fall als Zuschlag ausgewählt';
	}

	// ------------------------------------------------------------------ Persistenz

	/** Aktive Schritte aktiver Vorlagen (für neue Fälle). */
	private function activeSteps(): array {
		return array_values(array_filter($this->allSteps(), static fn(array $step): bool => $step['active'] && !$step['archived'] && $step['templateActive']));
	}

	private ?array $stepCache = null;

	private function allSteps(): array {
		if ($this->stepCache !== null) return $this->stepCache;
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('s.*')->selectAlias('t.template_key', 'template_key')->selectAlias('t.name', 'template_name')->selectAlias('t.active', 'template_active')
			->from('bestatter_process_steps', 's')->innerJoin('s', 'bestatter_process_templates', 't', $query->expr()->eq('t.id', 's.template_id'))
			->orderBy('t.sort_order', 'ASC')->addOrderBy('s.phase', 'ASC')->addOrderBy('s.sort_order', 'ASC')->executeQuery()->fetchAllAssociative();
		return $this->stepCache = array_map(fn(array $row): array => $this->mapStep($row), $rows);
	}

	private function template(string $key): array {
		$row = $this->templateRow($key);
		if ($row === null) throw new \InvalidArgumentException('Ablaufvorlage wurde nicht gefunden.');
		return $this->mapTemplate($row, true);
	}

	private function templateRow(string $key): ?array {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('*')->from('bestatter_process_templates')->where($query->expr()->eq('template_key', $query->createNamedParameter(strtoupper(trim($key)))))->executeQuery()->fetchAssociative();
		return $row === false ? null : $row;
	}

	private function mapTemplate(array $row, bool $withSteps): array {
		$template = ['id' => (int)$row['id'], 'key' => (string)$row['template_key'], 'name' => (string)$row['name'], 'description' => (string)($row['description'] ?? ''), 'active' => (bool)$row['active'], 'origin' => (string)$row['origin'], 'sortOrder' => (int)$row['sort_order'], 'updatedAt' => (string)$row['updated_at']];
		if ($withSteps) {
			$query = $this->db->getQueryBuilder();
			$steps = $query->select('s.*')->selectAlias('t.template_key', 'template_key')->selectAlias('t.name', 'template_name')->selectAlias('t.active', 'template_active')
				->from('bestatter_process_steps', 's')->innerJoin('s', 'bestatter_process_templates', 't', $query->expr()->eq('t.id', 's.template_id'))
				->where($query->expr()->eq('s.template_id', $query->createNamedParameter((int)$row['id'])))
				->orderBy('s.phase', 'ASC')->addOrderBy('s.sort_order', 'ASC')->executeQuery()->fetchAllAssociative();
			$template['steps'] = array_map(fn(array $step): array => $this->mapStep($step), $steps);
			$template['warnings'] = $this->warnings($template['steps']);
		}
		return $template;
	}

	private function mapStep(array $row): array {
		$trigger = json_decode((string)$row['trigger_json'], true) ?: [];
		$applies = json_decode((string)$row['applies_json'], true) ?: [];
		$complete = json_decode((string)$row['complete_json'], true) ?: [];
		return [
			'id' => (int)$row['id'], 'templateId' => (int)$row['template_id'], 'templateKey' => (string)($row['template_key'] ?? ''), 'templateName' => (string)($row['template_name'] ?? ''),
			'templateActive' => (bool)($row['template_active'] ?? true),
			'key' => (string)$row['step_key'], 'phase' => (int)$row['phase'], 'title' => (string)$row['title'], 'description' => (string)($row['description'] ?? ''),
			'sortOrder' => (int)$row['sort_order'], 'active' => (bool)$row['active'], 'archived' => (bool)($row['archived'] ?? false),
			'trigger' => ['type' => (string)($trigger['type'] ?? 'CASE_CREATED'), 'presetKeys' => array_values((array)($trigger['presetKeys'] ?? [])), 'stepKey' => (string)($trigger['stepKey'] ?? ''), 'once' => (bool)($trigger['once'] ?? false),
				'templateKey' => (string)($trigger['templateKey'] ?? ''), 'field' => (string)($trigger['field'] ?? ''), 'value' => (string)($trigger['value'] ?? ''), 'status' => (string)($trigger['status'] ?? ''),
				'dateRef' => (string)($trigger['dateRef'] ?? ''), 'days' => (int)($trigger['days'] ?? 0), 'leadDays' => (int)($trigger['leadDays'] ?? 0), 'label' => (string)($trigger['label'] ?? ''), 'documentKind' => (string)($trigger['documentKind'] ?? '')],
			'applies' => ['scope' => (string)($applies['scope'] ?? 'ALL'), 'ceremony' => (string)($applies['ceremony'] ?? 'ANY'), 'pension' => (string)($applies['pension'] ?? 'ANY'), 'deposit' => (string)($applies['deposit'] ?? 'ANY'), 'label' => (string)($applies['label'] ?? ''), 'labelValue' => (string)($applies['labelValue'] ?? '')],
			'complete' => ['type' => (string)($complete['type'] ?? 'NONE'), 'templateKey' => (string)($complete['templateKey'] ?? ''), 'presetKey' => (string)($complete['presetKey'] ?? ''),
				'field' => (string)($complete['field'] ?? ''), 'value' => (string)($complete['value'] ?? ''), 'status' => (string)($complete['status'] ?? ''), 'label' => (string)($complete['label'] ?? ''), 'documentKind' => (string)($complete['documentKind'] ?? '')],
			'actions' => json_decode((string)$row['actions_json'], true) ?: [],
			'dueOffsetDays' => (int)$row['due_offset_days'], 'dueReference' => (string)$row['due_reference'], 'role' => (string)$row['role'],
			'priority' => (string)$row['priority'], 'required' => (bool)$row['required'], 'introducedAt' => (string)($row['introduced_at'] ?? ''),
		];
	}

	private function normalizeSteps(array $input): array {
		$steps = []; $keys = [];
		foreach (array_values($input) as $position => $raw) {
			if (!is_array($raw)) throw new \InvalidArgumentException('Ein Schritt ist ungültig.');
			$step = ProcessRules::normalizeStep($raw, fn(array $actions): array => $this->actions->normalizeActions($actions));
			$base = $step['key']; $suffix = 1;
			while (isset($keys[$step['key']])) $step['key'] = substr($base, 0, 74) . '_' . (++$suffix);
			$keys[$step['key']] = true;
			$step['sortOrder'] = ($position + 1) * 10;
			$steps[] = $step;
		}
		return $steps;
	}

	/**
	 * @param bool $templateActive Vorlage ist nach dem Speichern aktiv
	 * @param bool $activated Vorlage wurde mit diesem Speichern aktiviert
	 * Bestandsschutz (0.66.1): Neue, neu aktivierte oder mit ihrer Vorlage aktivierte Schritte erhalten
	 * introduced_at = jetzt und entstehen dadurch nur in Fällen, deren Ablauf danach beginnt.
	 */
	private function replaceSteps(int $templateId, array $steps, bool $templateActive = false, bool $activated = false, bool $seeding = false): void {
		$existing = []; $wasActive = [];
		$query = $this->db->getQueryBuilder();
		foreach ($query->select('id', 'step_key', 'active', 'archived')->from('bestatter_process_steps')->where($query->expr()->eq('template_id', $query->createNamedParameter($templateId)))->executeQuery()->fetchAllAssociative() as $row) {
			$existing[(string)$row['step_key']] = (int)$row['id'];
			$wasActive[(string)$row['step_key']] = (bool)$row['active'] && !(bool)$row['archived'];
		}
		$now = date('c');
		$introducedNow = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
		foreach ($steps as $step) {
			$values = [
				'phase' => $step['phase'], 'title' => $step['title'], 'description' => $step['description'], 'sort_order' => $step['sortOrder'],
				'trigger_json' => json_encode($step['trigger'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'applies_json' => json_encode($step['applies'], JSON_THROW_ON_ERROR),
				'complete_json' => json_encode($step['complete'], JSON_THROW_ON_ERROR), 'actions_json' => json_encode($step['actions'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
				'due_offset_days' => $step['dueOffsetDays'], 'due_reference' => $step['dueReference'], 'role' => $step['role'], 'priority' => $step['priority'], 'updated_at' => $now,
			];
			if (isset($existing[$step['key']])) {
				$update = $this->db->getQueryBuilder();
				$update->update('bestatter_process_steps');
				foreach ($values as $column => $value) $update->set($column, $update->createNamedParameter($value));
				if (!$seeding && $templateActive && $step['active'] && ($activated || !$wasActive[$step['key']])) $update->set('introduced_at', $update->createNamedParameter($introducedNow));
				$update->set('active', $update->createNamedParameter($step['active'], IQueryBuilder::PARAM_BOOL))->set('required', $update->createNamedParameter($step['required'], IQueryBuilder::PARAM_BOOL))->set('archived', $update->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
					->where($update->expr()->eq('id', $update->createNamedParameter($existing[$step['key']])))->executeStatement();
				unset($existing[$step['key']]);
			} else {
				$insert = $this->db->getQueryBuilder();
				$params = ['template_id' => $insert->createNamedParameter($templateId), 'step_key' => $insert->createNamedParameter($step['key']),
					'active' => $insert->createNamedParameter($step['active'], IQueryBuilder::PARAM_BOOL), 'required' => $insert->createNamedParameter($step['required'], IQueryBuilder::PARAM_BOOL), 'archived' => $insert->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)];
				foreach ($values as $column => $value) $params[$column] = $insert->createNamedParameter($value);
				if (!$seeding && $templateActive) $params['introduced_at'] = $insert->createNamedParameter($introducedNow);
				$insert->insert('bestatter_process_steps')->values($params)->executeStatement();
			}
		}
		foreach ($existing as $key => $id) {
			$runs = $this->db->getQueryBuilder();
			$hasRuns = (int)$runs->select($runs->func()->count('*', 'count'))->from('bestatter_process_runs')->where($runs->expr()->eq('step_id', $runs->createNamedParameter($id)))->executeQuery()->fetchOne() > 0;
			if ($hasRuns) {
				$archive = $this->db->getQueryBuilder();
				$archive->update('bestatter_process_steps')->set('archived', $archive->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))->set('active', $archive->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))->set('sort_order', $archive->createNamedParameter(99999))
					->where($archive->expr()->eq('id', $archive->createNamedParameter($id)))->executeStatement();
			} else {
				$delete = $this->db->getQueryBuilder();
				$delete->delete('bestatter_process_steps')->where($delete->expr()->eq('id', $delete->createNamedParameter($id)))->executeStatement();
			}
		}
		$this->stepCache = null;
	}

	private function insertTemplate(array $template, string $origin, int $sortOrder): array {
		$key = strtoupper(trim((string)$template['key']));
		$steps = $this->normalizeSteps((array)($template['steps'] ?? []));
		$now = date('c');
		$this->db->beginTransaction();
		try {
			$insert = $this->db->getQueryBuilder();
			$insert->insert('bestatter_process_templates')->values([
				'template_key' => $insert->createNamedParameter($key), 'name' => $insert->createNamedParameter((string)$template['name']),
				'description' => $insert->createNamedParameter((string)($template['description'] ?? '')),
				'active' => $insert->createNamedParameter(filter_var($template['active'] ?? true, FILTER_VALIDATE_BOOLEAN), IQueryBuilder::PARAM_BOOL),
				'origin' => $insert->createNamedParameter($origin), 'sort_order' => $insert->createNamedParameter($sortOrder),
				'created_at' => $insert->createNamedParameter($now), 'updated_at' => $insert->createNamedParameter($now),
			])->executeStatement();
			$templateId = (int)$this->db->lastInsertId('bestatter_process_templates');
			$this->replaceSteps($templateId, $steps, filter_var($template['active'] ?? true, FILTER_VALIDATE_BOOLEAN), false, $origin === 'BUNDLED');
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		return $this->template($key);
	}

	/** Prüfregeln im Editor (Paket D). Reine Hinweise – Speichern bleibt möglich. */
	private function warnings(array $steps): array {
		$warnings = ProcessRules::chainWarnings(array_values(array_filter($steps, static fn(array $step): bool => !$step['archived'])));
		$titles = [];
		$roles = $this->installation->roleGroups();
		$roleSteps = [];
		foreach ($steps as $step) {
			if ($step['archived'] || !$step['active']) continue;
			$title = mb_strtolower($step['title']);
			if (isset($titles[$title])) $warnings[] = 'Der Schritt „' . $step['title'] . '“ kommt doppelt vor.';
			$titles[$title] = true;
			if ($step['role'] !== '' && $step['role'] !== 'FALLVERANTWORTLICH') {
				$roleSteps[$step['role']][] = '„' . $step['title'] . '“';
			}
			if ($step['role'] === '') $warnings[] = 'Der Schritt „' . $step['title'] . '“ hat keine feste Zuständigkeit – die Aufgabe geht an die Person, die den Auslöser ausführt.';
			foreach ($this->activeSteps() as $other) {
				if ($other['templateId'] === $step['templateId'] || mb_strtolower($other['title']) !== $title) continue;
				$warnings[] = 'Der Schritt „' . $step['title'] . '“ steht auch in der aktiven Vorlage „' . $other['templateName'] . '“ – im Fall entstünden doppelte Aufgaben.';
			}
			foreach ($step['actions'] as $action) {
				if (($action['type'] ?? '') === 'DOCUMENT_BUNDLE' && ((array)($action['templateKeys'] ?? [])) === []) $warnings[] = 'Knopf „' . ($action['label'] ?? '') . '“ bei „' . $step['title'] . '“: keine Dokumentvorlagen im Paket.';
			}
			if ($step['trigger']['type'] === 'MANUAL' && $step['required']) $warnings[] = 'Der Pflichtschritt „' . $step['title'] . '“ entsteht nur von Hand und kann dadurch übersehen werden.';
			if ($step['dueOffsetDays'] < 0 && !in_array($step['dueReference'], ['CEREMONY', 'BURIAL'], true) && !in_array($step['trigger']['type'], ['SCHEDULE_CONFIRMED'], true)) $warnings[] = 'Der Schritt „' . $step['title'] . '“ hat eine negative Frist ohne Terminbezug – er ist dann immer sofort fällig.';
		}
		// 0.83.2: je Rolle ein Hinweis mit dem erwarteten Gruppennamen (Gruppen-ID) und, bei umbenannten Gruppen, der passenden ID.
		foreach ($roleSteps as $role => $titles) {
			$group = $roles[$role] ?? '';
			$problem = $group === '' ? 'keine Nextcloud-Gruppe eingetragen' : $this->groupLookup->missingText($group);
			if ($problem === '') continue;
			$titles = array_values(array_unique($titles));
			$named = implode(', ', array_slice($titles, 0, 3)) . (count($titles) > 3 ? ' und ' . (count($titles) - 3) . ' weitere' : '');
			$warnings[] = 'Rolle „' . (ProcessRules::ROLES[$role] ?? $role) . '“ (' . (count($titles) === 1 ? 'Schritt ' : 'Schritte ') . $named . '): ' . $problem
				. '. Bis dahin gehen diese Aufgaben an die Fallverantwortung (Administration → Installationsparameter → Rollen für Abläufe).';
		}
		return array_values(array_unique($warnings));
	}
}
