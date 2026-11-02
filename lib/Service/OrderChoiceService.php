<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IConfig;

/**
 * 0.89.3: Ankreuzfelder im Bestattungsauftrag, unter Customizing pflegbar. Je Feld: Bezeichnung, fester Schlüssel
 * (Platzhalter {{order.choice.SCHLÜSSEL}}), Abschnitt, wer ankreuzt (Bestattungsunternehmen mit Vorbelegung oder Kunde bei der
 * Unterschrift am Tablet), Vorbelegung aus Warengruppen/Trauerfeier/Feuerbestattung und Abgleich mit den Positionen.
 * Die sieben bisher fest eingebauten Felder (0.88.3) sind mitgelieferte Einträge mit ihren bisherigen Platzhaltern.
 * Die Erklärungen zum Leistungsbeginn bei Widerrufsrecht bleiben fest (gesetzlicher Wortlaut).
 */
class OrderChoiceService {
	public const CONFIG_KEY = 'order_choice_fields';
	public const SECTIONS = ['SCOPE' => 'Leistungsumfang', 'POA' => 'Vollmacht für', 'CONSENT' => 'Einwilligungen und Erklärungen', 'OTHER' => 'Weitere Vereinbarungen'];
	public const ACTORS = ['COMPANY' => 'Bestattungsunternehmen', 'CUSTOMER' => 'Kunde bei der Unterschrift'];
	public const PREFILLS = ['RULE' => 'nach Leistungen und Bestattung', 'ALWAYS' => 'immer angekreuzt', 'NEVER' => 'nie vorbelegt'];
	public const CHECKS = ['BOTH' => 'in beide Richtungen', 'UNCHECKED' => 'nur wenn abgewählt, obwohl beauftragt', 'NONE' => 'kein Abgleich'];
	private const SECTION_GROUP = ['SCOPE' => 'scope', 'POA' => 'poa', 'CONSENT' => 'consent', 'OTHER' => 'other'];
	/** Mitgelieferte Felder: Schlüssel => [Stammdatum, bisheriger Platzhalter, Abschnitt, Bezeichnung, Vorbelegung, Warengruppen, Trauerfeier, Feuerbestattung, Abgleich] */
	private const STANDARD = [
		'SCOPE_TRANSFER' => ['order_scope_transfer', '{{order.scope_transfer}}', 'SCOPE', 'Überführung und Versorgung der verstorbenen Person', 'RULE', ['110', '120'], false, false, 'BOTH'],
		'SCOPE_CEREMONY' => ['order_scope_ceremony', '{{order.scope_ceremony}}', 'SCOPE', 'Organisation der Trauerfeier und Bestattung', 'RULE', ['140'], true, false, 'BOTH'],
		'SCOPE_FORMALITIES' => ['order_scope_formalities', '{{order.scope_formalities}}', 'SCOPE', 'Abmeldungen und Behördengänge', 'RULE', ['150'], false, false, 'BOTH'],
		'POA_REGISTRY' => ['order_poa_registry', '{{order.poa_registry}}', 'POA', 'Standesamt, Friedhofs- und Bestattungsverwaltung', 'ALWAYS', [], false, false, 'NONE'],
		'POA_CREMATORIUM' => ['order_poa_crematorium', '{{order.poa_crematorium}}', 'POA', 'Krematorium sowie Überführungs- und Versorgungsunternehmen', 'RULE', ['630'], false, true, 'UNCHECKED'],
		'POA_CEREMONY' => ['order_poa_ceremony', '{{order.poa_ceremony}}', 'POA', 'Kirche, Trauerredner/in, Trauerhalle', 'RULE', ['140'], true, false, 'UNCHECKED'],
		'POA_DEREGISTRATION' => ['order_poa_deregistration', '{{order.poa_deregistration}}', 'POA', 'Rentenservice, Kranken- und Pflegekasse, Versicherungen und Vertragspartner', 'RULE', ['150'], false, false, 'UNCHECKED'],
	];

	public function __construct(
		private ?IConfig $config = null,
		private ?CatalogStructureService $structure = null,
		private ?CustomizingService $customizing = null,
		private ?AuditService $audit = null,
		private ?TeamService $team = null,
	) {
	}

	/** Mitgelieferte Felder mit den Regeln von 0.88.3. */
	public static function defaults(): array {
		$fields = [];
		$sort = 10;
		foreach (self::STANDARD as $key => [$field, $placeholder, $section, $label, $prefill, $groups, $ceremony, $cremation, $check]) {
			$fields[] = ['key' => $key, 'field' => $field, 'placeholders' => [$placeholder, '{{order.choice.' . $key . '}}'], 'label' => $label, 'section' => $section,
				'actor' => 'COMPANY', 'prefill' => $prefill, 'groups' => $groups, 'ceremony' => $ceremony, 'cremation' => $cremation, 'check' => $check,
				'active' => true, 'system' => true, 'sortOrder' => $sort];
			$sort += 10;
		}
		return $fields;
	}

	/** Eingerichtete Felder (gespeicherte Liste, mitgelieferte Felder immer enthalten), sortiert. */
	public function definitions(bool $activeOnly = false): array {
		$stored = json_decode((string)($this->config?->getAppValue(Application::APP_ID, self::CONFIG_KEY, '') ?? ''), true);
		$fields = [];
		$defaults = array_column(self::defaults(), null, 'key');
		foreach (is_array($stored) ? $stored : [] as $entry) {
			if (!is_array($entry) || !isset($entry['key'])) {
				continue;
			}
			$key = (string)$entry['key'];
			if (isset($fields[$key])) {
				continue;
			}
			$fields[$key] = isset($defaults[$key]) ? self::systemField($defaults[$key], $entry) : self::customField($entry);
		}
		foreach ($defaults as $key => $default) {
			if (!isset($fields[$key])) {
				$fields[$key] = $default;
			}
		}
		$list = array_values($fields);
		usort($list, static fn (array $left, array $right): int => [$left['sortOrder'], $left['key']] <=> [$right['sortOrder'], $right['key']]);
		return $activeOnly ? array_values(array_filter($list, static fn (array $field): bool => $field['active'])) : $list;
	}

	/** Liste für Customizing (mit Auswahllisten und Warengruppen). */
	public function catalog(): array {
		$groups = [];
		foreach ($this->structure?->groups() ?? [] as $group) {
			$groups[] = ['key' => (string)$group['key'], 'name' => (string)$group['name']];
		}
		return ['fields' => $this->definitions(), 'sections' => self::SECTIONS, 'actors' => self::ACTORS, 'prefills' => self::PREFILLS, 'checks' => self::CHECKS, 'groups' => $groups,
			'canEdit' => $this->team?->isBestatterAdmin() ?? false];
	}

	/** Speichert die ganze Liste (Bestatter-Administration). Mitgelieferte Felder lassen sich ändern und deaktivieren, nicht löschen. */
	public function save(array $input, bool $allowMissingGroups = false): array {
		$this->team?->requireBestatterAdmin();
		$before = $this->definitions();
		$defaults = array_column(self::defaults(), null, 'key');
		$existing = array_column($before, null, 'key');
		$groupKeys = array_map(static fn (array $group): string => (string)$group['key'], $this->structure?->groups() ?? []);
		$result = [];
		$sort = 10;
		foreach ($input as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$key = strtoupper(trim((string)($entry['key'] ?? '')));
			if (!preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $key)) {
				throw new \InvalidArgumentException('Ungültiger Schlüssel „' . $key . '“: Großbuchstaben, Ziffern und _, 2 bis 40 Zeichen, mit einem Buchstaben am Anfang.');
			}
			if (isset($result[$key])) {
				throw new \InvalidArgumentException('Der Schlüssel „' . $key . '“ ist doppelt vergeben.');
			}
			$label = trim((string)preg_replace('/\s+/u', ' ', (string)($entry['label'] ?? '')));
			if ($label === '' || mb_strlen($label) > 300) {
				throw new \InvalidArgumentException('Bitte für „' . $key . '“ eine Bezeichnung mit höchstens 300 Zeichen angeben.');
			}
			$section = strtoupper((string)($entry['section'] ?? ''));
			if (!isset(self::SECTIONS[$section])) {
				throw new \InvalidArgumentException('Unbekannter Abschnitt bei „' . $label . '“.');
			}
			$known = $existing[$key] ?? null;
			$actor = $known !== null ? $known['actor'] : strtoupper((string)($entry['actor'] ?? 'COMPANY'));
			if (!isset(self::ACTORS[$actor])) {
				throw new \InvalidArgumentException('Unbekannte Angabe „Wer kreuzt an“ bei „' . $label . '“.');
			}
			$prefill = strtoupper((string)($entry['prefill'] ?? 'NEVER'));
			$check = strtoupper((string)($entry['check'] ?? 'NONE'));
			$groups = array_values(array_unique(array_filter(array_map(static fn ($group): string => strtoupper(trim((string)$group)), (array)($entry['groups'] ?? [])))));
			$ceremony = !empty($entry['ceremony']);
			$cremation = !empty($entry['cremation']);
			if ($actor === 'CUSTOMER') {
				$prefill = 'NEVER';
				$check = 'NONE';
				$groups = [];
				$ceremony = false;
				$cremation = false;
			}
			if (!isset(self::PREFILLS[$prefill])) {
				throw new \InvalidArgumentException('Unbekannte Vorbelegung bei „' . $label . '“.');
			}
			if (!isset(self::CHECKS[$check])) {
				throw new \InvalidArgumentException('Unbekannter Abgleich bei „' . $label . '“.');
			}
			if ($prefill !== 'RULE') {
				$groups = [];
				$ceremony = false;
				$cremation = false;
				$check = 'NONE';
			}
			foreach ($groups as $group) {
				if (!$allowMissingGroups && $groupKeys !== [] && !in_array($group, $groupKeys, true)) {
					throw new \InvalidArgumentException('Warengruppe „' . $group . '“ bei „' . $label . '“ gibt es im Leistungskatalog nicht.');
				}
			}
			if ($prefill === 'RULE' && $groups === [] && !$ceremony && !$cremation) {
				throw new \InvalidArgumentException('Für „' . $label . '“ ist „nach Leistungen und Bestattung“ gewählt: bitte mindestens eine Warengruppe, Trauerfeier oder Feuerbestattung angeben.');
			}
			$values = ['key' => $key, 'label' => $label, 'section' => $section, 'actor' => $actor, 'prefill' => $prefill, 'groups' => $groups, 'ceremony' => $ceremony, 'cremation' => $cremation, 'check' => $check, 'active' => !array_key_exists('active', $entry) || !empty($entry['active']), 'sortOrder' => $sort];
			$result[$key] = isset($defaults[$key]) ? self::systemField($defaults[$key], $values) : self::customField($values);
			$sort += 10;
		}
		foreach ($defaults as $key => $default) {
			if (!isset($result[$key])) {
				throw new \InvalidArgumentException('Mitgelieferte Ankreuzfelder lassen sich nicht löschen, nur deaktivieren („' . $default['label'] . '“).');
			}
		}
		$stored = array_map(static fn (array $field): array => array_intersect_key($field, array_flip(['key', 'label', 'section', 'actor', 'prefill', 'groups', 'ceremony', 'cremation', 'check', 'active', 'sortOrder'])), array_values($result));
		$this->config?->setAppValue(Application::APP_ID, self::CONFIG_KEY, json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		$this->audit?->logSystem('ORDER_CHOICE_FIELDS', 'UPDATED', ['before' => array_column($before, 'label', 'key'), 'after' => array_column($stored, 'label', 'key')]);
		return $this->catalog();
	}

	public const EXCHANGE_FORMAT = 'bestatter-order-choices';

	/** 0.89.5 (OP-071): Ankreuzfelder für das Einrichtungspaket (ohne Stammdatenfelder und Platzhalter, die ergeben sich aus dem Schlüssel). */
	public function exportExchange(): string {
		$fields = array_map(static fn (array $field): array => array_intersect_key($field, array_flip(['key', 'label', 'section', 'actor', 'prefill', 'groups', 'ceremony', 'cremation', 'check', 'active', 'sortOrder'])), $this->definitions());
		return json_encode(['format' => self::EXCHANGE_FORMAT, 'formatVersion' => 1, 'appVersion' => Application::VERSION, 'fields' => $fields], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	/** @return list<array> Felder aus der Austauschdatei (geprüft wird beim Speichern). */
	public static function parseExchange(string $content): array {
		$data = json_decode($content, true);
		if (!is_array($data) || ($data['format'] ?? '') !== self::EXCHANGE_FORMAT) {
			throw new \InvalidArgumentException('Keine Ankreuzfelder im erwarteten Format.');
		}
		$fields = array_values(array_filter((array)($data['fields'] ?? []), 'is_array'));
		if ($fields === []) {
			throw new \InvalidArgumentException('Die Datei enthält keine Ankreuzfelder.');
		}
		return $fields;
	}

	/**
	 * Übernimmt die Felder des Pakets: gleiche Schlüssel werden ersetzt, eigene Felder dieser Installation, die das Paket nicht
	 * kennt, bleiben (hinten angehängt) erhalten. Fehlende Warengruppen verhindern die Übernahme nicht – die Verweisprüfung meldet sie.
	 * @return array{created:int,updated:int}
	 */
	public function importExchange(string $content): array {
		$incoming = self::parseExchange($content);
		$current = array_column($this->definitions(), null, 'key');
		$created = 0;
		$updated = 0;
		$list = [];
		$seen = [];
		foreach ($incoming as $field) {
			$key = strtoupper(trim((string)($field['key'] ?? '')));
			if ($key === '' || isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			isset($current[$key]) ? $updated++ : $created++;
			$list[] = $field; // „Wer kreuzt an“ eines vorhandenen Feldes bleibt (save())
		}
		foreach ($current as $key => $field) {
			if (!isset($seen[$key])) {
				$list[] = $field;
			}
		}
		$this->save($list, true);
		return ['created' => $created, 'updated' => $updated];
	}

	/** Alle Platzhalter der Ankreuzfelder (Feldkatalog, Vorlagenprüfung). @return list<array{placeholder:string,name:string,source:string}> */
	public function placeholderCatalog(): array {
		$entries = [];
		foreach ($this->definitions() as $field) {
			foreach ($field['placeholders'] as $placeholder) {
				$entries[] = ['placeholder' => $placeholder, 'name' => 'Kästchen: ' . $field['label'] . ' (☒/☐' . ($field['actor'] === 'CUSTOMER' ? ', kreuzt der Kunde an' : '') . ')', 'source' => 'Ankreuzfeld im Auftrag (Customizing)'];
			}
		}
		return $entries;
	}

	/**
	 * Felder für einen Fall: Vorbelegung mit Grund, gewählter Wert (JA/NEIN) mit Vorrang, Widerspruch zu den Positionen.
	 * $templateText: Text der wirksamen Auftragsvorlage (null = unbekannt; dann gelten alle Felder als enthalten).
	 */
	public function selections(array $case, array $m, array $costing, ?string $templateText = null, bool $includeInactive = false): array {
		$articleGroups = [];
		foreach (($costing['items'] ?? []) as $item) {
			if (($item['serviceStatus'] ?? '') === 'STORNIERT') {
				continue;
			}
			// Warengruppe der Position: Schlüssel (articleGroupKey); ältere Positionen tragen den Schlüssel ggf. in articleGroup.
			foreach ([$item['articleGroupKey'] ?? '', $item['articleGroup'] ?? ''] as $value) {
				$group = strtoupper(trim((string)$value));
				if ($group !== '') {
					$articleGroups[$group] = true;
				}
			}
		}
		$variants = $this->customizing?->valuesForKey('BURIAL_VARIANT') ?? [];
		$facts = ProcessRules::funeralFacts((string)($case['burialVariantCode'] ?? $m['burial_variant_code'] ?? ''), (string)($case['funeralType'] ?? $m['funeral_type'] ?? ''), $variants);
		$ceremonyPlanned = ($case['withFuneralCeremony'] ?? null) === true;
		$cremation = $facts['scope'] === 'CREMATION';
		$groupNames = [];
		foreach ($this->structure?->groups() ?? [] as $group) {
			$groupNames[(string)$group['key']] = (string)$group['name'];
		}
		$result = [];
		foreach ($this->definitions(!$includeInactive) as $definition) {
			$choice = strtoupper(trim((string)($m[$definition['field']] ?? '')));
			if (!in_array($choice, ['JA', 'NEIN'], true)) {
				$choice = '';
			}
			$auto = false;
			$reason = 'nicht vorbelegt';
			if ($definition['actor'] === 'CUSTOMER') {
				$reason = 'kreuzt der Kunde bei der Unterschrift an';
			} elseif ($definition['prefill'] === 'ALWAYS') {
				$auto = true;
				$reason = 'immer vorbelegt';
			} elseif ($definition['prefill'] === 'RULE') {
				$hits = [];
				foreach ($definition['groups'] as $group) {
					if (isset($articleGroups[$group])) {
						$hits[] = ($groupNames[$group] ?? 'Warengruppe ' . $group) . ' gebucht';
					}
				}
				if ($definition['ceremony'] && $ceremonyPlanned) {
					$hits[] = 'Trauerfeier vorgesehen';
				}
				if ($definition['cremation'] && $cremation) {
					$hits[] = 'Feuerbestattung';
				}
				$auto = $hits !== [];
				$reason = $auto ? implode(', ', $hits) : 'keine passende Leistung gebucht';
			}
			$checked = $definition['actor'] === 'CUSTOMER' ? $choice === 'JA' : ($choice === '' ? $auto : $choice === 'JA');
			$mismatch = '';
			if ($definition['actor'] === 'COMPANY' && $definition['prefill'] === 'RULE' && $definition['check'] !== 'NONE' && $choice !== '') {
				if ($checked && !$auto && $definition['check'] === 'BOTH') {
					$mismatch = 'CHECKED_WITHOUT_SERVICE';
				}
				if (!$checked && $auto) {
					$mismatch = 'UNCHECKED_WITH_SERVICE';
				}
			}
			$expected = implode(' oder ', array_filter([
				$definition['groups'] !== [] ? implode(', ', array_map(static fn (string $group): string => $groupNames[$group] ?? 'Warengruppe ' . $group, $definition['groups'])) : '',
				$definition['ceremony'] ? 'Trauerfeier' : '', $definition['cremation'] ? 'Feuerbestattung' : '',
			]));
			$inTemplate = $templateText === null;
			if (!$inTemplate) {
				foreach ($definition['placeholders'] as $placeholder) {
					if (str_contains($templateText, $placeholder)) {
						$inTemplate = true;
						break;
					}
				}
			}
			$result[] = [
				'key' => $definition['key'], 'field' => $definition['field'], 'group' => self::SECTION_GROUP[$definition['section']] ?? 'other',
				'section' => $definition['section'], 'sectionLabel' => self::SECTIONS[$definition['section']] ?? '', 'label' => $definition['label'],
				'placeholder' => $definition['placeholders'][0], 'placeholders' => $definition['placeholders'], 'actor' => $definition['actor'], 'active' => $definition['active'],
				'auto' => $auto, 'autoReason' => $reason, 'choice' => $choice, 'checked' => $checked, 'check' => $definition['check'],
				'articleGroups' => $definition['groups'], 'expected' => $expected,
				'mismatch' => $mismatch, 'mismatchText' => match ($mismatch) {
					'CHECKED_WITHOUT_SERVICE' => '„' . $definition['label'] . '“ ist angekreuzt, aber keine passende Leistung (' . $expected . ') ist beauftragt.',
					'UNCHECKED_WITH_SERVICE' => '„' . $definition['label'] . '“ ist nicht angekreuzt, obwohl ' . $reason . '.',
					default => '',
				},
				'inTemplate' => $inTemplate,
			];
		}
		return $result;
	}

	/** Werte für die Dokumentausgabe (alle Platzhalter aller Felder, auch inaktiver: ☒/☐). @return array<string,string> */
	public function placeholderValues(array $selections): array {
		$values = [];
		foreach ($selections as $selection) {
			foreach ($selection['placeholders'] as $placeholder) {
				$values[$placeholder] = $selection['active'] && $selection['checked'] ? '☒' : '☐';
			}
		}
		return $values;
	}

	private static function systemField(array $default, array $stored): array {
		$field = $default;
		foreach (['label', 'section', 'prefill', 'groups', 'ceremony', 'cremation', 'check', 'active', 'sortOrder'] as $name) {
			if (array_key_exists($name, $stored)) {
				$field[$name] = $stored[$name];
			}
		}
		return self::normalize($field);
	}

	private static function customField(array $entry): array {
		$key = strtoupper((string)$entry['key']);
		$actor = strtoupper((string)($entry['actor'] ?? 'COMPANY')) === 'CUSTOMER' ? 'CUSTOMER' : 'COMPANY';
		return self::normalize(['key' => $key, 'field' => ($actor === 'CUSTOMER' ? 'order_customer_' : 'order_choice_') . strtolower($key), 'placeholders' => ['{{order.choice.' . $key . '}}'],
			'label' => (string)($entry['label'] ?? $key), 'section' => (string)($entry['section'] ?? 'OTHER'), 'actor' => $actor, 'prefill' => (string)($entry['prefill'] ?? 'NEVER'),
			'groups' => (array)($entry['groups'] ?? []), 'ceremony' => !empty($entry['ceremony']), 'cremation' => !empty($entry['cremation']), 'check' => (string)($entry['check'] ?? 'NONE'),
			'active' => !array_key_exists('active', $entry) || !empty($entry['active']), 'system' => false, 'sortOrder' => (int)($entry['sortOrder'] ?? 1000)]);
	}

	private static function normalize(array $field): array {
		$field['section'] = isset(self::SECTIONS[strtoupper((string)$field['section'])]) ? strtoupper((string)$field['section']) : 'OTHER';
		$field['prefill'] = isset(self::PREFILLS[strtoupper((string)$field['prefill'])]) ? strtoupper((string)$field['prefill']) : 'NEVER';
		$field['check'] = isset(self::CHECKS[strtoupper((string)$field['check'])]) ? strtoupper((string)$field['check']) : 'NONE';
		$field['groups'] = array_values(array_map('strval', (array)$field['groups']));
		$field['ceremony'] = (bool)$field['ceremony'];
		$field['cremation'] = (bool)$field['cremation'];
		$field['active'] = (bool)$field['active'];
		$field['sortOrder'] = (int)$field['sortOrder'];
		if ($field['actor'] === 'CUSTOMER') {
			$field['prefill'] = 'NEVER';
			$field['check'] = 'NONE';
			$field['groups'] = [];
			$field['ceremony'] = false;
			$field['cremation'] = false;
		}
		return $field;
	}
}
