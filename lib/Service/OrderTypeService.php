<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * 0.89.7 (OP-074, Schritt B): Auftragsarten für Aufträge ohne Sterbefall (Customizing → Auftragsarten).
 *
 * Je Art: Schlüssel, Bezeichnung, ob Angaben zur verstorbenen Person dazugehören (Überführung, Urnenbeisetzung), welche
 * Unterordner der Ordnerplan für diese Art anlegt (leer = Ordnerauswahl für Aufträge) und welche Warengruppen die
 * Leistungsauswahl vorschlägt. Gespeichert als App-Wert `order_types` (in der Sicherung enthalten), austauschbar über
 * das Einrichtungspaket (einstellungen/Auftragsarten.json).
 *
 * Ohne gespeicherte Liste gelten die vier Grundarten aus dem Konzept (nur Bezeichnungen, ohne Katalogbezüge) – so kann eine
 * neue Installation sofort Aufträge anlegen. Warengruppen und Ordner ergänzt das Einrichtungspaket bzw. die Administration.
 */
class OrderTypeService {
	public const CONFIG_KEY = 'order_types';
	public const EXCHANGE_FORMAT = 'bestatter-order-types';
	public const DEFAULTS = [
		['key' => 'VERKAUF', 'label' => 'Verkauf', 'requiresDeceased' => false, 'folders' => [], 'articleGroups' => [], 'active' => true],
		['key' => 'UEBERFUEHRUNG', 'label' => 'Überführung', 'requiresDeceased' => true, 'folders' => [], 'articleGroups' => [], 'active' => true],
		['key' => 'URNENBEISETZUNG', 'label' => 'Urnenbeisetzung/Seebestattung', 'requiresDeceased' => true, 'folders' => [], 'articleGroups' => [], 'active' => true],
		['key' => 'TRAUERFEIER', 'label' => 'Trauerfeier/Abschiednahme', 'requiresDeceased' => false, 'folders' => [], 'articleGroups' => [], 'active' => true],
	];
	private const MAX_TYPES = 50;
	/** @var array{types: list<array>, isDefault: bool}|null */
	private ?array $cache = null;

	public function __construct(
		private ?IConfig $config = null,
		private ?IDBConnection $db = null,
		private ?TeamService $team = null,
		private ?AuditService $audit = null,
		private ?InstallationConfigService $installation = null,
		private ?CatalogStructureService $structure = null,
	) {
	}

	/** @return array{types: list<array>, isDefault: bool} */
	public function stored(): array {
		if ($this->cache !== null) {
			return $this->cache;
		}
		$raw = json_decode((string)($this->config?->getAppValue(Application::APP_ID, self::CONFIG_KEY, '') ?? ''), true);
		$list = is_array($raw) && is_array($raw['types'] ?? null) ? $raw['types'] : null;
		$types = [];
		foreach ($list ?? self::DEFAULTS as $index => $item) {
			if (!is_array($item)) {
				continue;
			}
			try {
				$types[] = self::normalizeType($item, $index);
			} catch (\InvalidArgumentException) {
				continue;
			}
		}
		return $this->cache = ['types' => $types, 'isDefault' => $list === null];
	}

	/** @return list<array{key:string,label:string,requiresDeceased:bool,folders:list<string>,articleGroups:list<string>,active:bool,sortOrder:int}> */
	public function all(): array {
		return $this->stored()['types'];
	}

	public function byKey(string $key): ?array {
		foreach ($this->all() as $type) {
			if ($type['key'] === strtoupper(trim($key))) {
				return $type;
			}
		}
		return null;
	}

	public function label(string $key): string {
		return $this->byKey($key)['label'] ?? $key;
	}

	/** Prüft eine Auftragsart bei der Anlage: bekannt und aktiv. */
	public function requireActive(string $key): array {
		$type = $this->byKey($key);
		if ($type === null) {
			throw new \InvalidArgumentException('Die Auftragsart „' . $key . '“ ist nicht eingerichtet.');
		}
		if (!$type['active']) {
			throw new \InvalidArgumentException('Die Auftragsart „' . $type['label'] . '“ ist deaktiviert.');
		}
		return $type;
	}

	/** Customizing: Liste mit Auswahlwerten (Ordnerplan, Warengruppen), Verwendung und Berechtigung. */
	public function settings(): array {
		$stored = $this->stored();
		$usage = $this->usage();
		$plan = $this->installation?->folderPlan() ?? [];
		$groups = [];
		foreach ($this->structure?->groups() ?? [] as $group) {
			$groups[] = ['key' => (string)($group['key'] ?? ''), 'name' => (string)($group['name'] ?? $group['label'] ?? $group['key'] ?? '')];
		}
		return [
			'types' => array_map(static fn (array $type): array => $type + ['usage' => $usage[$type['key']] ?? 0], $stored['types']),
			'isDefault' => $stored['isDefault'],
			'folderPlan' => array_map(static fn (array $item): array => ['key' => $item['key'], 'name' => $item['name']], $plan),
			'articleGroups' => $groups,
			'canEdit' => $this->team?->isBestatterAdmin() ?? false,
		];
	}

	/** Speichert die Liste (Bestatter-Administration). Verwendete Arten lassen sich nur deaktivieren, nicht entfernen. */
	public function save(array $input, bool $fromPackage = false): array {
		$this->team?->requireBestatterAdmin();
		$types = [];
		$keys = [];
		foreach (array_values((array)($input['types'] ?? [])) as $index => $raw) {
			if (!is_array($raw)) {
				continue;
			}
			if (trim((string)($raw['key'] ?? '')) === '' && trim((string)($raw['label'] ?? '')) === '') {
				continue;
			}
			$type = self::normalizeType($raw, $index);
			if (isset($keys[$type['key']])) {
				throw new \InvalidArgumentException('Der Schlüssel „' . $type['key'] . '“ kommt doppelt vor.');
			}
			$keys[$type['key']] = true;
			$types[] = $type;
		}
		if (count($types) > self::MAX_TYPES) {
			throw new \InvalidArgumentException('Höchstens ' . self::MAX_TYPES . ' Auftragsarten.');
		}
		if (!$fromPackage && $types === []) {
			throw new \InvalidArgumentException('Mindestens eine Auftragsart ist erforderlich.');
		}
		$usage = $this->usage();
		$previous = $this->all();
		foreach ($previous as $old) {
			if (!isset($keys[$old['key']]) && ($usage[$old['key']] ?? 0) > 0) {
				if ($fromPackage) {
					$types[] = $old;
					$keys[$old['key']] = true;
					continue;
				}
				throw new \InvalidArgumentException('Die Auftragsart „' . $old['label'] . '“ wird in ' . $usage[$old['key']] . ' Auftrag/Aufträgen verwendet. Bitte deaktivieren statt entfernen.');
			}
		}
		$planKeys = array_column($this->installation?->folderPlan() ?? [], 'key');
		foreach ($types as $index => $type) {
			if ($planKeys !== [] && !$fromPackage) {
				foreach ($type['folders'] as $folder) {
					if (!in_array($folder, $planKeys, true)) {
						throw new \InvalidArgumentException('Auftragsart „' . $type['label'] . '“: Ordner-Kennzeichen „' . $folder . '“ gibt es im Ordnerplan nicht.');
					}
				}
			}
			if ($planKeys !== [] && $fromPackage) {
				$types[$index]['folders'] = array_values(array_intersect($type['folders'], $planKeys));
			}
		}
		usort($types, static fn (array $left, array $right): int => [$left['sortOrder'], $left['label']] <=> [$right['sortOrder'], $right['label']]);
		$this->config?->setAppValue(Application::APP_ID, self::CONFIG_KEY, json_encode(['types' => array_values($types)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		$this->cache = null;
		$this->audit?->logSystem('ORDER_TYPES', 'UPDATED', ['types' => array_column($types, 'key')]);
		return $this->settings();
	}

	/** @return array{key:string,label:string,requiresDeceased:bool,folders:list<string>,articleGroups:list<string>,active:bool,sortOrder:int} */
	public static function normalizeType(array $raw, int $index = 0): array {
		$label = trim((string)($raw['label'] ?? ''));
		$key = strtoupper(trim((string)($raw['key'] ?? '')));
		if ($key === '' && $label !== '') {
			$key = self::keyFromLabel($label);
		}
		if (!preg_match('/^[A-Z][A-Z0-9_]{1,59}$/', $key)) {
			throw new \InvalidArgumentException('Der Schlüssel der Auftragsart „' . ($label !== '' ? $label : $key) . '“ ist ungültig (Großbuchstaben, Ziffern, Unterstrich).');
		}
		if ($label === '' || mb_strlen($label) > 80) {
			throw new \InvalidArgumentException('Bitte eine Bezeichnung (höchstens 80 Zeichen) für die Auftragsart „' . $key . '“ angeben.');
		}
		$list = static function (mixed $value, string $pattern): array {
			$items = is_array($value) ? $value : explode(',', (string)$value);
			$result = [];
			foreach ($items as $item) {
				$item = strtoupper(trim((string)$item));
				if ($item !== '' && preg_match($pattern, $item) === 1 && !in_array($item, $result, true)) {
					$result[] = $item;
				}
			}
			return array_slice($result, 0, 40);
		};
		return [
			'key' => $key,
			'label' => $label,
			'requiresDeceased' => filter_var($raw['requiresDeceased'] ?? false, FILTER_VALIDATE_BOOLEAN),
			'folders' => $list($raw['folders'] ?? [], '/^[A-Z0-9_]{1,60}$/'),
			'articleGroups' => $list($raw['articleGroups'] ?? [], '/^[A-Z0-9_.\-]{1,60}$/'),
			'active' => !array_key_exists('active', $raw) || filter_var($raw['active'], FILTER_VALIDATE_BOOLEAN),
			'sortOrder' => (int)($raw['sortOrder'] ?? (($index + 1) * 10)),
		];
	}

	public static function keyFromLabel(string $label): string {
		$key = strtoupper(strtr(trim($label), ['Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE', 'ä' => 'AE', 'ö' => 'OE', 'ü' => 'UE', 'ß' => 'SS']));
		$key = trim((string)preg_replace('/[^A-Z0-9]+/', '_', $key), '_');
		if ($key === '' || !preg_match('/^[A-Z]/', $key)) {
			$key = 'ART_' . $key;
		}
		return substr($key, 0, 60);
	}

	/** @return array<string,int> Anzahl der Aufträge je Auftragsart */
	public function usage(): array {
		if ($this->db === null) {
			return [];
		}
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('order_type')->selectAlias($query->func()->count('*'), 'usage_count')->from('bestatter_cases')
			->where($query->expr()->isNotNull('order_type'))->groupBy('order_type')->executeQuery()->fetchAllAssociative();
		$usage = [];
		foreach ($rows as $row) {
			$usage[strtoupper((string)$row['order_type'])] = (int)$row['usage_count'];
		}
		return $usage;
	}

	/** Einrichtungspaket: Auftragsarten mit Ordner-Kennzeichen und Warengruppen. */
	public function exportExchange(): string {
		return json_encode(['format' => self::EXCHANGE_FORMAT, 'formatVersion' => 1, 'appVersion' => Application::VERSION, 'types' => $this->all()],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	/** @return list<array> */
	public static function parseExchange(string $content): array {
		$data = json_decode($content, true);
		if (!is_array($data) || ($data['format'] ?? '') !== self::EXCHANGE_FORMAT) {
			throw new \InvalidArgumentException('Keine Auftragsarten im erwarteten Format.');
		}
		$types = [];
		foreach (array_values(array_filter((array)($data['types'] ?? []), 'is_array')) as $index => $raw) {
			$types[] = self::normalizeType($raw, $index);
		}
		return $types;
	}

	/** Gleiche Schlüssel werden aktualisiert, eigene Arten bleiben. @return array{types:int} */
	public function importExchange(string $content): array {
		$incoming = self::parseExchange($content);
		$merged = [];
		foreach ($this->stored()['isDefault'] ? [] : $this->all() as $type) {
			$merged[$type['key']] = $type;
		}
		foreach ($incoming as $type) {
			$merged[$type['key']] = $type;
		}
		$this->save(['types' => array_values($merged)], true);
		return ['types' => count($this->all())];
	}
}
