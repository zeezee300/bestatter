<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * 0.90.3: Reine Regeln für die Zuordnung „Ressourcen je Terminart“ (Matrix im Customizing → Terminplanung).
 *
 * Eine Terminart bietet nur die Ressourcen an, die ihr als „zulässig“ zugeordnet sind; „vorbelegt“ ist immer eine
 * Teilmenge davon. Ohne Zuordnung gibt es für die Terminart keine Ressourcen, ohne Terminart („Freie Eingabe“)
 * ebenfalls keine. Mitarbeitende werden nie als Ressource disponiert, sondern über die Terminzuständigkeit.
 * Ohne Nextcloud-/Datenbankabhängigkeit, damit sie in tests/scheduling-resources-0903-fixture.php geprüft werden kann.
 */
final class SchedulingRules {
	/** Ressourcentypen, die einer Terminart zugeordnet werden können (in dieser Reihenfolge angezeigt). */
	public const ASSIGNABLE_TYPES = ['CHAPEL', 'ROOM', 'VEHICLE', 'EQUIPMENT'];
	public const TYPE_LABELS = ['CHAPEL' => 'Kapelle', 'ROOM' => 'Raum', 'VEHICLE' => 'Fahrzeug', 'EQUIPMENT' => 'Ausstattung', 'STAFF' => 'Mitarbeiter'];
	public const TYPE_LABELS_PLURAL = ['CHAPEL' => 'Kapellen', 'ROOM' => 'Räume', 'VEHICLE' => 'Fahrzeuge', 'EQUIPMENT' => 'Ausstattung', 'STAFF' => 'Mitarbeitende'];
	/** Pflichtbedarf je Ressourcentyp (Feld der Terminart). */
	public const REQUIREMENT_FIELDS = ['CHAPEL' => 'requiredChapels', 'ROOM' => 'requiredRooms', 'VEHICLE' => 'requiredVehicles', 'EQUIPMENT' => 'requiredEquipment'];
	/**
	 * 0.92.0: Werteliste „Ressourcentypen“ (Stammdaten → Wertelisten). Die vier Standardtypen sind fest (nicht löschbar,
	 * technischer Wert unveränderlich); eigene Typen können ergänzt werden. Ohne gepflegte Liste gelten diese Standardwerte.
	 */
	public const RESOURCE_TYPE_LIST = 'RESOURCE_TYPE';
	public const SYSTEM_RESOURCE_TYPES = ['CHAPEL', 'ROOM', 'VEHICLE', 'EQUIPMENT'];
	public const DEFAULT_RESOURCE_TYPES = [
		['key' => 'CHAPEL', 'label' => 'Kapelle', 'plural' => 'Kapellen', 'icon' => 'building-church'],
		['key' => 'ROOM', 'label' => 'Raum', 'plural' => 'Räume', 'icon' => 'door'],
		['key' => 'VEHICLE', 'label' => 'Fahrzeug', 'plural' => 'Fahrzeuge', 'icon' => 'car'],
		['key' => 'EQUIPMENT', 'label' => 'Ausstattung', 'plural' => 'Ausstattung', 'icon' => 'armchair'],
	];
	/**
	 * 0.92.0: Feste Auswahl an Symbolen für Ressourcentypen (Tabler Icons, Outline). Gleichlautend in
	 * src/modules/resource-icons.js; tests/resources-0920-static.mjs prüft, dass beide Listen übereinstimmen.
	 */
	public const RESOURCE_ICONS = ['building-church', 'building', 'home', 'door', 'bed', 'armchair', 'car', 'car-suv', 'truck', 'bus', 'snowflake', 'fridge', 'candle', 'flower', 'music', 'microphone', 'box', 'tool'];
	public const DEFAULT_RESOURCE_ICON = 'box';
	/** 0.92.0: Längen der neuen Ressourcenfelder. */
	public const IDENTIFIER_MAX = 40;
	public const DESCRIPTION_MAX = 2000;
	/**
	 * Vorschlag für die mitgelieferten Terminarten („Zuordnung vorschlagen“). Der Vorschlag geht nach Ressourcentyp und
	 * füllt nur Terminarten ohne Zuordnung; einzelne Ressourcen (z. B. ein Aufbewahrungsraum) nimmt man danach heraus.
	 */
	public const SUGGESTED_TYPES = [
		'TRAUERFEIER_1' => ['CHAPEL', 'VEHICLE', 'EQUIPMENT'],
		'TRAUERFEIER_2' => ['CHAPEL', 'VEHICLE', 'EQUIPMENT'],
		'BEISETZUNG' => ['VEHICLE', 'EQUIPMENT'],
		'AUFBAHRUNG' => ['ROOM', 'EQUIPMENT'],
		'ABSCHIEDNAHME' => ['ROOM', 'EQUIPMENT'],
		'UEBERFUEHRUNG' => ['VEHICLE', 'EQUIPMENT'],
		'BERATUNG' => ['ROOM'],
		'BEHOERDENTERMIN' => ['VEHICLE'],
		'INTERNE_TAETIGKEIT' => ['CHAPEL', 'ROOM', 'VEHICLE', 'EQUIPMENT'],
	];

	/**
	 * 0.92.0: Ressourcentypen aus den Einträgen der Werteliste „Ressourcentypen“ (Reihenfolge der Liste). Fehlt die Liste
	 * noch (z. B. vor dem ersten Laden der Wertelisten), gelten die vier Standardtypen. Standardtypen fehlen nie.
	 *
	 * @param list<array<string,mixed>> $items Einträge mit value, label, metadata (plural, icon)
	 * @return list<array{key:string,label:string,plural:string,icon:string,system:bool}>
	 */
	public static function resourceTypesFromItems(array $items): array {
		$types = [];
		foreach ($items as $item) {
			$key = strtoupper(trim((string)($item['value'] ?? '')));
			if ($key === '' || $key === 'STAFF' || isset($types[$key])) {
				continue;
			}
			$metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
			$label = trim((string)($item['label'] ?? '')) ?: $key;
			$icon = (string)($metadata['icon'] ?? '');
			$types[$key] = [
				'key' => $key,
				'label' => $label,
				'plural' => trim((string)($metadata['plural'] ?? '')) ?: $label,
				'icon' => in_array($icon, self::RESOURCE_ICONS, true) ? $icon : self::defaultIcon($key),
				'system' => in_array($key, self::SYSTEM_RESOURCE_TYPES, true),
			];
		}
		foreach (self::DEFAULT_RESOURCE_TYPES as $default) {
			if (!isset($types[$default['key']])) {
				$types[$default['key']] = [...$default, 'system' => true];
			}
		}
		return array_values($types);
	}

	/** @return list<array{key:string,label:string,plural:string,icon:string,system:bool}> */
	public static function defaultResourceTypes(): array {
		return self::resourceTypesFromItems([]);
	}

	public static function defaultIcon(string $typeKey): string {
		foreach (self::DEFAULT_RESOURCE_TYPES as $default) {
			if ($default['key'] === $typeKey) {
				return $default['icon'];
			}
		}
		return self::DEFAULT_RESOURCE_ICON;
	}

	/** @return array<string,string> Typschlüssel → Bezeichnung (Einzahl) */
	public static function typeLabels(?array $resourceTypes = null): array {
		$labels = [];
		foreach ($resourceTypes ?? self::defaultResourceTypes() as $type) {
			$labels[(string)$type['key']] = (string)$type['label'];
		}
		return $labels;
	}

	/** Anzeigename einer Ressource mit Nummer/Kennung, z. B. „Bestattungswagen (HB-RL 333)“. */
	public static function resourceLabel(array $resource): string {
		$name = trim((string)($resource['name'] ?? $resource['key'] ?? ''));
		$identifier = trim((string)($resource['identifier'] ?? ''));
		return $identifier !== '' && $identifier !== $name ? $name . ' (' . $identifier . ')' : $name;
	}

	/**
	 * 0.92.0: Pflichtbedarf einer Terminart je Ressourcentyp. Neu als Liste requiredResources; ältere Datensätze und
	 * Aufrufer liefern noch die Einzelfelder requiredChapels, requiredRooms, requiredVehicles, requiredEquipment.
	 *
	 * @return array<string,int>
	 */
	public static function requiredResources(?array $type): array {
		if ($type === null) {
			return [];
		}
		$result = [];
		$map = $type['requiredResources'] ?? null;
		if (is_array($map) && $map !== []) {
			foreach ($map as $key => $count) {
				$key = strtoupper(trim((string)$key));
				if ($key !== '' && $key !== 'STAFF' && (int)$count > 0) {
					$result[$key] = (int)$count;
				}
			}
			return $result;
		}
		foreach (self::REQUIREMENT_FIELDS as $resourceType => $field) {
			if ((int)($type[$field] ?? 0) > 0) {
				$result[$resourceType] = (int)$type[$field];
			}
		}
		return $result;
	}

	/**
	 * 0.92.0: Pflichtbedarf aus dem Customizing bereinigen – nur bekannte Ressourcentypen, nur Werte > 0.
	 *
	 * @param list<array<string,mixed>> $resourceTypes
	 * @return array<string,int>
	 */
	public static function normalizeRequirements(mixed $input, array $resourceTypes): array {
		$known = array_map(static fn (array $type): string => (string)$type['key'], $resourceTypes);
		$result = [];
		foreach ((array)$input as $key => $count) {
			$key = strtoupper(trim((string)$key));
			if ((int)$count <= 0) {
				continue;
			}
			if (!in_array($key, $known, true)) {
				throw new \InvalidArgumentException('Pflichtbedarf: Der Ressourcentyp „' . $key . '“ ist nicht bekannt.');
			}
			$result[$key] = min(99, (int)$count);
		}
		return $result;
	}

	/**
	 * 0.92.0: Niederlassungen einer Ressource bereinigen (leer = alle Niederlassungen).
	 *
	 * @param list<string> $knownBranchKeys
	 * @return list<string>
	 */
	public static function normalizeBranchKeys(mixed $input, array $knownBranchKeys): array {
		$known = array_map(static fn (string $key): string => strtoupper(trim($key)), $knownBranchKeys);
		$result = [];
		foreach ((array)$input as $key) {
			$key = trim((string)$key);
			if ($key === '' || strtoupper($key) === 'ALL') {
				continue;
			}
			$index = array_search(strtoupper($key), $known, true);
			if ($index === false) {
				throw new \InvalidArgumentException('Die Niederlassung „' . $key . '“ ist nicht bekannt.');
			}
			$value = $knownBranchKeys[$index];
			if (!in_array($value, $result, true)) {
				$result[] = $value;
			}
		}
		return $result;
	}

	/** 0.92.0: Ist die Ressource in der Niederlassung verfügbar? Ohne Niederlassung (Termin ohne Fall) gilt jede. */
	public static function availableInBranch(array $resource, string $branchKey): bool {
		$branchKey = strtoupper(trim($branchKey));
		$keys = array_map(static fn (mixed $key): string => strtoupper(trim((string)$key)), (array)($resource['branchKeys'] ?? []));
		return $branchKey === '' || $keys === [] || in_array($branchKey, $keys, true);
	}

	/**
	 * Technischer Schlüssel aus der Bezeichnung, wenn beim Anlegen keiner angegeben ist (Platzhalter „automatisch aus
	 * Bezeichnung“), z. B. „Kapelle Süd“ → KAPELLE_SUED. Belegte Schlüssel erhalten eine Zählung (_2, _3, …).
	 *
	 * @param list<string> $taken
	 */
	public static function keyFromName(string $name, array $taken = []): string {
		$key = strtoupper(strtr(trim($name), ['Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE', 'ä' => 'AE', 'ö' => 'OE', 'ü' => 'UE', 'ß' => 'SS']));
		$key = trim((string)preg_replace('/[^A-Z0-9]+/', '_', $key), '_');
		$key = substr($key === '' ? 'EINTRAG' : $key, 0, 90);
		$candidate = $key;
		for ($index = 2; in_array($candidate, $taken, true); $index++) {
			$candidate = $key . '_' . $index;
		}
		return $candidate;
	}

	/** @return list<string> Großgeschriebene, eindeutige Schlüssel ohne Leereinträge. */
	public static function normalizeKeys(mixed $keys): array {
		$result = [];
		foreach ((array)$keys as $key) {
			$key = strtoupper(trim((string)$key));
			if ($key !== '' && !in_array($key, $result, true)) {
				$result[] = $key;
			}
		}
		return $result;
	}

	/** @param list<array<string,mixed>> $resources */
	public static function resourcesByKey(array $resources): array {
		$result = [];
		foreach ($resources as $resource) {
			$result[(string)($resource['key'] ?? '')] = $resource;
		}
		return $result;
	}

	/** 0.92.0: Jede Ressource außer dem früheren Typ „Mitarbeiter“ ist zuordenbar – auch eigene Ressourcentypen. */
	public static function isAssignable(array $resource): bool {
		$type = strtoupper(trim((string)($resource['resourceType'] ?? '')));
		return $type !== '' && $type !== 'STAFF';
	}

	/**
	 * Bereinigt eine Zuordnung vor dem Speichern: nur bekannte, zuordenbare Ressourcen; Vorbelegte sind immer zulässig.
	 *
	 * @param list<array<string,mixed>> $resources alle Ressourcen (auch inaktive)
	 * @return array{allowed:list<string>,defaults:list<string>}
	 */
	public static function normalizeAssignment(mixed $allowed, mixed $defaults, array $resources): array {
		$byKey = self::resourcesByKey($resources);
		$allowedKeys = self::normalizeKeys($allowed);
		$defaultKeys = self::normalizeKeys($defaults);
		foreach ([...$allowedKeys, ...$defaultKeys] as $key) {
			if (!isset($byKey[$key])) {
				throw new \InvalidArgumentException('Die Ressource „' . $key . '“ ist nicht bekannt.');
			}
			if (!self::isAssignable($byKey[$key])) {
				throw new \InvalidArgumentException('„' . (string)$byKey[$key]['name'] . '“ ist als Mitarbeiter geführt. Mitarbeitende werden im Termin über „Mitarbeitende“ gewählt, nicht über die Ressourcen.');
			}
		}
		foreach ($defaultKeys as $key) {
			if (!in_array($key, $allowedKeys, true)) {
				$allowedKeys[] = $key;
			}
		}
		return ['allowed' => self::sortKeys($allowedKeys, $resources), 'defaults' => self::sortKeys($defaultKeys, $resources)];
	}

	/** Zulässige Ressourcen einer Terminart (ohne Terminart: keine). */
	public static function allowedKeys(?array $type): array {
		if ($type === null) {
			return [];
		}
		return self::normalizeKeys([...(array)($type['allowedResourceKeys'] ?? []), ...(array)($type['defaultResourceKeys'] ?? [])]);
	}

	/**
	 * Prüft die im Termin gewählten Ressourcen. Ressourcen, die schon am Termin hingen, dürfen inaktiv sein.
	 * 0.92.0: Mit Niederlassung (aus dem Fall) nur Ressourcen dieser Niederlassung oder „Alle Niederlassungen“; was schon
	 * am Termin hing, bleibt erlaubt.
	 *
	 * @param list<array<string,mixed>> $resources alle Ressourcen (auch inaktive)
	 * @param list<string> $previousKeys Ressourcen des bisherigen Termins (beim Ändern)
	 * @return list<string> Fehlermeldungen (leer = in Ordnung)
	 */
	public static function selectionErrors(?array $type, array $selectedKeys, array $resources, array $previousKeys = [], string $branchKey = '', string $branchName = ''): array {
		$selectedKeys = self::normalizeKeys($selectedKeys);
		if ($selectedKeys === []) {
			return [];
		}
		$previousKeys = self::normalizeKeys($previousKeys);
		$byKey = self::resourcesByKey($resources);
		$errors = [];
		$unknown = array_values(array_filter($selectedKeys, static fn (string $key): bool => !isset($byKey[$key])));
		if ($unknown !== []) {
			$errors[] = 'Unbekannte Ressource: ' . implode(', ', $unknown) . '.';
		}
		$known = array_values(array_filter($selectedKeys, static fn (string $key): bool => isset($byKey[$key])));
		$name = static fn (string $key): string => self::resourceLabel($byKey[$key] ?? ['name' => $key]);
		if ($type === null) {
			if ($known !== []) {
				$errors[] = 'Ressourcen können nur zusammen mit einer Terminart disponiert werden. Bitte eine Terminart wählen (gewählt: ' . implode(', ', array_map($name, $known)) . ').';
			}
			return $errors;
		}
		$allowed = self::allowedKeys($type);
		$notAllowed = array_values(array_filter($known, static fn (string $key): bool => !in_array($key, $allowed, true) || !self::isAssignable($byKey[$key])));
		if ($notAllowed !== []) {
			$errors[] = 'Für die Terminart „' . (string)($type['name'] ?? $type['key'] ?? '') . '“ nicht vorgesehen: ' . implode(', ', array_map($name, $notAllowed)) . '. Die zulässigen Ressourcen je Terminart legen Sie unter Customizing → Terminplanung fest.';
		}
		$inactive = array_values(array_filter($known, static fn (string $key): bool => !(bool)($byKey[$key]['active'] ?? false) && !in_array($key, $previousKeys, true)));
		if ($inactive !== []) {
			$errors[] = 'Nicht mehr aktiv: ' . implode(', ', array_map($name, $inactive)) . '.';
		}
		$otherBranch = array_values(array_filter($known, static fn (string $key): bool => !self::availableInBranch($byKey[$key], $branchKey) && !in_array($key, $previousKeys, true)));
		if ($otherBranch !== []) {
			$errors[] = 'Nicht für die Niederlassung „' . ($branchName !== '' ? $branchName : $branchKey) . '“ vorgesehen: ' . implode(', ', array_map($name, $otherBranch)) . '. Die Niederlassungen einer Ressource legen Sie unter Stammdaten → Ressourcen fest.';
		}
		return $errors;
	}

	/**
	 * Hinweise zur Zuordnung: Eine aktive Terminart verlangt mehr Ressourcen eines Typs, als ihr aktiv zugeordnet sind.
	 * Ein solcher Termin ließe sich nie speichern. 0.92.0: Bei mehreren Niederlassungen zusätzlich je Niederlassung
	 * (Ressourcen mit „Alle Niederlassungen“ zählen überall).
	 *
	 * @param list<array<string,mixed>>|null $resourceTypes Werteliste „Ressourcentypen“ (für die Bezeichnungen)
	 * @param list<array{key:string,name:string}> $branches aktive Niederlassungen
	 * @return list<string>
	 */
	public static function assignmentIssues(array $types, array $resources, ?array $resourceTypes = null, array $branches = []): array {
		$labels = self::typeLabels($resourceTypes);
		$byKey = self::resourcesByKey($resources);
		$issues = [];
		foreach ($types as $type) {
			if (!(bool)($type['active'] ?? true)) {
				continue;
			}
			$required = self::requiredResources($type);
			if ($required === []) {
				continue;
			}
			$usable = [];
			foreach (self::allowedKeys($type) as $key) {
				$resource = $byKey[$key] ?? null;
				if ($resource !== null && (bool)($resource['active'] ?? false) && self::isAssignable($resource)) {
					$usable[] = $resource;
				}
			}
			$typeName = (string)($type['name'] ?? $type['key'] ?? '');
			$missingEverywhere = [];
			foreach ($required as $resourceType => $count) {
				$available = count(array_filter($usable, static fn (array $resource): bool => (string)$resource['resourceType'] === $resourceType));
				if ($count > $available) {
					$missingEverywhere[$resourceType] = true;
					$issues[] = '„' . $typeName . '“ verlangt ' . $count . ' × ' . ($labels[$resourceType] ?? $resourceType)
						. ', zulässig ' . ($available === 0 ? 'ist keine aktive Ressource dieser Art' : 'sind nur ' . $available) . '.';
				}
			}
			if (count($branches) < 2) {
				continue;
			}
			foreach ($branches as $branch) {
				$branchKey = (string)($branch['key'] ?? '');
				foreach ($required as $resourceType => $count) {
					if (isset($missingEverywhere[$resourceType])) {
						continue;
					}
					$available = count(array_filter($usable, static fn (array $resource): bool => (string)$resource['resourceType'] === $resourceType && self::availableInBranch($resource, $branchKey)));
					if ($count > $available) {
						$issues[] = '„' . $typeName . '“ verlangt ' . $count . ' × ' . ($labels[$resourceType] ?? $resourceType) . ', in der Niederlassung „'
							. (string)($branch['name'] ?? $branchKey) . '“ ' . ($available === 0 ? 'ist keine aktive Ressource dieser Art zulässig' : 'sind nur ' . $available . ' zulässig') . '.';
					}
				}
			}
		}
		return $issues;
	}

	/**
	 * Vorschlag „zulässig“ für eine Terminart: mitgelieferte Terminarten nach SUGGESTED_TYPES, eigene nach Pflichtbedarf.
	 *
	 * @return list<string>
	 */
	public static function suggestAllowed(array $type, array $resources): array {
		$key = strtoupper((string)($type['key'] ?? ''));
		$categories = self::SUGGESTED_TYPES[$key] ?? array_keys(self::requiredResources($type));
		$keys = [];
		foreach ($resources as $resource) {
			if ((bool)($resource['active'] ?? false) && in_array((string)($resource['resourceType'] ?? ''), $categories, true)) {
				$keys[] = (string)$resource['key'];
			}
		}
		return self::normalizeKeys([...$keys, ...(array)($type['defaultResourceKeys'] ?? [])]);
	}

	/** 0.90.5: Arbeitszeit für die Terminplanung, solange im Customizing nichts anderes eingetragen ist. */
	public const DEFAULT_WORKING_HOURS = ['start' => '07:00', 'end' => '18:00'];

	/**
	 * 0.90.5: Arbeitszeit für die Terminplanung prüfen und vereinheitlichen (Customizing → Terminplanung). Sie bestimmt die
	 * vorbelegte Uhrzeit neuer Termine (Arbeitsbeginn) und Aufgaben (Arbeitsende), das Fenster der Ersatzzeiten bei Konflikten
	 * und den Ausschnitt der Tagesleiste im Terminformular.
	 *
	 * @return array{start:string,end:string}
	 */
	public static function normalizeWorkingHours(mixed $input): array {
		$input = is_array($input) ? $input : [];
		$time = static function (mixed $value): string {
			$value = trim((string)$value);
			if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $match) || (int)$match[1] > 23 || (int)$match[2] > 59) {
				return '';
			}
			return sprintf('%02d:%02d', (int)$match[1], (int)$match[2]);
		};
		$start = $time($input['start'] ?? self::DEFAULT_WORKING_HOURS['start']);
		$end = $time($input['end'] ?? self::DEFAULT_WORKING_HOURS['end']);
		if ($start === '' || $end === '') {
			throw new \InvalidArgumentException('Bitte Beginn und Ende der Arbeitszeit als Uhrzeit angeben, z. B. 07:00 und 18:00.');
		}
		if (self::minutesOfDay($end) - self::minutesOfDay($start) < 60) {
			throw new \InvalidArgumentException('Das Ende der Arbeitszeit muss mindestens eine Stunde nach dem Beginn liegen.');
		}
		return ['start' => $start, 'end' => $end];
	}

	/** 0.90.5: „07:30“ → 450. */
	public static function minutesOfDay(string $time): int {
		[$hours, $minutes] = array_map('intval', explode(':', $time . ':0'));
		return $hours * 60 + $minutes;
	}

	/**
	 * 0.90.5: Braucht eine Terminänderung einen Änderungsgrund? Begründet werden nur Änderungen an einem Termin, der schon
	 * abgestimmt bzw. mitgeteilt ist. Frei bleiben:
	 * - jede Änderung an einem Entwurf (auch „Entwurf“ → „Bestätigt“),
	 * - der reine Statuswechsel auf „Erledigt“ (seit 0.89.10),
	 * - der reine Statuswechsel „Geändert – Folgeaufgaben prüfen“ → „Bestätigt“ (erneute Bestätigung).
	 * Gespiegelt im Terminformular (src/modules/schedule-form.js), damit das Feld nur bei Bedarf erscheint.
	 *
	 * @param list<string> $changedFields Felder aus SchedulingService::changes() (title, date, status, location, …)
	 */
	public static function changeReasonRequired(string $previousStatus, string $newStatus, array $changedFields): bool {
		if ($changedFields === []) {
			return false;
		}
		$previous = strtoupper(trim($previousStatus));
		$next = strtoupper(trim($newStatus));
		if ($previous === 'ENTWURF') {
			return false;
		}
		if (array_values($changedFields) === ['status'] && ($next === 'ERLEDIGT' || ($previous === 'GEAENDERT' && $next === 'BESTAETIGT'))) {
			return false;
		}
		return true;
	}

	/** @param list<string> $keys */
	private static function sortKeys(array $keys, array $resources): array {
		$order = array_flip(array_map(static fn (array $resource): string => (string)($resource['key'] ?? ''), $resources));
		usort($keys, static fn (string $left, string $right): int => ($order[$left] ?? PHP_INT_MAX) <=> ($order[$right] ?? PHP_INT_MAX) ?: strcmp($left, $right));
		return $keys;
	}
}
