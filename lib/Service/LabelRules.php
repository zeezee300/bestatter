<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * Reine Regeln der Marker (0.76.0): Prüfung der Definitionen, feste Farbpalette und Symbolauswahl,
 * Filterausdrücke der Fallliste und die automatischen Hinweise aus vorhandenen Falldaten.
 * Ohne Nextcloud-/Datenbankabhängigkeit, damit sie in tests/labels-0760-fixture.php geprüft werden kann.
 */
final class LabelRules {
	/** Feste, gedeckte Palette (Variante C). Die Farbwerte stehen in css/style.css (hell und dunkel). */
	public const COLORS = [
		'SLATE' => 'Schiefer', 'SAGE' => 'Salbei', 'PETROL' => 'Petrol', 'DOVE' => 'Taubenblau',
		'OCHRE' => 'Ocker', 'RUST' => 'Rost', 'PLUM' => 'Pflaume', 'MAUVE' => 'Malve',
	];
	public const KINDS = ['BOOL' => 'Ja/Nein', 'LIST' => 'Liste'];
	/** Empfohlene Obergrenze aktiver Marker, damit die Symbole in der Fallliste unterscheidbar bleiben. */
	public const MAX_ACTIVE = 12;
	public const MAX_VALUES = 30;
	public const ICON_FILE = __DIR__ . '/../../resources/label-icons.json';
	public const BUNDLE_FILE = __DIR__ . '/../../resources/labels.json';
	/** 0.77.0: Notiz je gesetztem Marker (intern, nie in Dokumentvorlagen). */
	public const NOTE_MODES = ['NONE' => 'keine Notiz', 'OPTIONAL' => 'Notiz möglich', 'REQUIRED' => 'Notiz erforderlich'];
	public const NOTE_MAX = 500;
	public const NOTE_HINT_MAX = 150;
	/** Kopfzeile des Notizblocks in Aufgaben, die ein Marker ausgelöst hat (zum Wiederfinden beim Aktualisieren). */
	public const TASK_NOTE_HEADING = 'Notizen aus Markern:';
	/** Ersatzsymbol, falls ein gespeichertes Symbol in einer späteren Version nicht mehr angeboten wird. */
	public const FALLBACK_ICON = 'tag';

	/** Automatische Hinweise: werden aus Falldaten berechnet, nie gespeichert und nie von Hand gesetzt. */
	public const HINTS = [
		'FREIGABE' => ['name' => 'Freigabe abwarten', 'color' => 'RUST', 'icon' => 'lock', 'description' => 'Todesart nicht natürlich oder ungeklärt und noch keine ärztliche/behördliche Freigabe – bzw. Freigabe gesperrt.'],
		'BETREUUNG' => ['name' => 'Betreuung', 'color' => 'SLATE', 'icon' => 'users', 'description' => 'Für die verstorbene Person bestand eine rechtliche Betreuung (Stammdaten → Betreuung).'],
		'ZAHLUNG_UEBERFAELLIG' => ['name' => 'Zahlung überfällig', 'color' => 'OCHRE', 'icon' => 'receipt', 'description' => 'Mindestens eine freigegebene Rechnung ist über dem Zahlungsziel und noch nicht bezahlt.'],
	];

	private static ?array $iconCache = null;

	private function __construct() {}

	/** @return array{groups:list<array{key:string,name:string}>,icons:list<array{key:string,name:string,group:string,terms:string,svg:string}>} */
	public static function iconCatalog(): array {
		if (self::$iconCache !== null) return self::$iconCache;
		$data = is_file(self::ICON_FILE) ? json_decode((string)file_get_contents(self::ICON_FILE), true) : null;
		return self::$iconCache = ['groups' => (array)($data['groups'] ?? []), 'icons' => (array)($data['icons'] ?? [])];
	}

	/** @return list<string> */
	public static function iconKeys(): array {
		return array_values(array_map(static fn(array $icon): string => (string)$icon['key'], self::iconCatalog()['icons']));
	}

	public static function keyFromName(string $name): string {
		$key = ProcessRules::keyFromTitle($name);
		// Schlüssel beginnen mit einem Buchstaben und haben mindestens zwei Zeichen (z. B. Name „X“ → „E_X“).
		if (!preg_match('/^[A-Z][A-Z0-9_]/', $key)) $key = 'E_' . $key;
		return substr($key, 0, 60);
	}

	/**
	 * Prüft und normalisiert eine Marker-Definition.
	 * @param array|null $existing gespeicherte Definition: Schlüssel und Art bleiben dann unverändert.
	 */
	public static function normalizeDefinition(array $input, ?array $existing = null): array {
		$name = trim(preg_replace('/\s+/u', ' ', (string)($input['name'] ?? '')) ?? '');
		if ($name === '') throw new \InvalidArgumentException('Der Marker braucht einen Namen.');
		if (mb_strlen($name) > 100) throw new \InvalidArgumentException('Der Name eines Markers darf höchstens 100 Zeichen lang sein.');
		$key = $existing !== null ? (string)$existing['key'] : strtoupper(trim((string)($input['key'] ?? '')));
		if ($key === '') $key = self::keyFromName($name);
		if (!preg_match('/^[A-Z][A-Z0-9_]{1,59}$/', $key)) throw new \InvalidArgumentException('Der technische Schlüssel des Markers ist ungültig (Großbuchstaben, Ziffern und _; beginnt mit einem Buchstaben).');
		$kind = $existing !== null ? (string)$existing['kind'] : strtoupper(trim((string)($input['kind'] ?? 'BOOL')));
		if (!isset(self::KINDS[$kind])) throw new \InvalidArgumentException('Unbekannte Art des Markers.');
		$description = trim((string)($input['description'] ?? ''));
		if (mb_strlen($description) > 500) throw new \InvalidArgumentException('Die Beschreibung eines Markers darf höchstens 500 Zeichen lang sein.');
		$color = strtoupper(trim((string)($input['color'] ?? 'SLATE')));
		if (!isset(self::COLORS[$color])) throw new \InvalidArgumentException('Bitte eine Farbe aus der vorgegebenen Palette wählen.');
		$icon = trim((string)($input['icon'] ?? self::FALLBACK_ICON));
		if (!in_array($icon, self::iconKeys(), true)) throw new \InvalidArgumentException('Bitte ein Symbol aus der vorgegebenen Auswahl wählen.');
		$values = [];
		if ($kind === 'LIST') {
			$seen = [];
			foreach (array_values((array)($input['values'] ?? [])) as $index => $raw) {
				if (!is_array($raw)) continue;
				$valueName = trim(preg_replace('/\s+/u', ' ', (string)($raw['name'] ?? '')) ?? '');
				if ($valueName === '') continue;
				if (mb_strlen($valueName) > 100) throw new \InvalidArgumentException('Ein Auswahlwert darf höchstens 100 Zeichen lang sein.');
				$valueKey = strtoupper(trim((string)($raw['key'] ?? '')));
				if ($valueKey === '') $valueKey = self::keyFromName($valueName);
				if (!preg_match('/^[A-Z][A-Z0-9_]{0,59}$/', $valueKey)) throw new \InvalidArgumentException('Der Schlüssel des Auswahlwerts „' . $valueName . '“ ist ungültig.');
				if (isset($seen[$valueKey])) throw new \InvalidArgumentException('Der Auswahlwert „' . $valueName . '“ ist doppelt vorhanden.');
				$seen[$valueKey] = true;
				$values[] = ['key' => $valueKey, 'name' => $valueName, 'sortOrder' => ($index + 1) * 10, 'active' => !array_key_exists('active', $raw) || filter_var($raw['active'], FILTER_VALIDATE_BOOLEAN)];
			}
			if ($values === []) throw new \InvalidArgumentException('Ein Listen-Marker braucht mindestens einen Auswahlwert.');
			if (count($values) > self::MAX_VALUES) throw new \InvalidArgumentException('Ein Listen-Marker darf höchstens ' . self::MAX_VALUES . ' Auswahlwerte haben.');
		}
		$noteMode = strtoupper(trim((string)($input['noteMode'] ?? ($existing['noteMode'] ?? 'OPTIONAL'))));
		if (!isset(self::NOTE_MODES[$noteMode])) throw new \InvalidArgumentException('Unbekannte Einstellung für Notizen.');
		$noteHint = trim(preg_replace('/\s+/u', ' ', (string)($input['noteHint'] ?? ($existing['noteHint'] ?? ''))) ?? '');
		if (mb_strlen($noteHint) > self::NOTE_HINT_MAX) throw new \InvalidArgumentException('Der Platzhaltertext der Notiz darf höchstens ' . self::NOTE_HINT_MAX . ' Zeichen lang sein.');
		if ($noteMode === 'NONE') $noteHint = '';
		return [
			'key' => $key, 'name' => $name, 'description' => $description, 'kind' => $kind, 'color' => $color, 'icon' => $icon,
			'noteMode' => $noteMode, 'noteHint' => $noteHint,
			'active' => filter_var($input['active'] ?? ($existing['active'] ?? false), FILTER_VALIDATE_BOOLEAN),
			'keepOnAnonymize' => filter_var($input['keepOnAnonymize'] ?? ($existing['keepOnAnonymize'] ?? false), FILTER_VALIDATE_BOOLEAN),
			'values' => $values,
		];
	}

	/**
	 * Filterausdruck der Fallliste: „KEY“ oder „KEY=WERT“, mehrere durch Komma (UND-verknüpft).
	 * @return list<array{key:string,value:string}>
	 */
	public static function parseFilter(string $filter): array {
		$result = [];
		foreach (array_slice(array_filter(array_map('trim', explode(',', $filter))), 0, 12) as $part) {
			[$key, $value] = array_pad(explode('=', $part, 2), 2, '');
			$key = strtoupper(trim($key)); $value = strtoupper(trim($value));
			if (!preg_match('/^[A-Z][A-Z0-9_]{1,59}$/', $key)) throw new \InvalidArgumentException('Ungültiger Markerfilter.');
			if ($value !== '' && !preg_match('/^[A-Z][A-Z0-9_]{0,59}$/', $value)) throw new \InvalidArgumentException('Ungültiger Wert im Markerfilter.');
			$result[$key . '=' . $value] = ['key' => $key, 'value' => $value];
		}
		return array_values($result);
	}

	/**
	 * Automatische Hinweise eines Falls.
	 * @param array $master Stammdaten (masterData)
	 * @return list<array{key:string,name:string,color:string,icon:string,description:string,automatic:bool,title:string}>
	 */
	public static function hints(array $master, bool $paymentOverdue): array {
		$hints = [];
		$manner = mb_strtolower(trim((string)($master['manner_of_death'] ?? '')));
		$release = strtoupper(trim((string)($master['release_status'] ?? '')));
		if ($release === 'GESPERRT') $hints[] = self::hint('FREIGABE', 'Freigabe gesperrt');
		elseif ($manner !== '' && $manner !== 'natürlicher tod' && $release !== 'FREIGEGEBEN') $hints[] = self::hint('FREIGABE', 'Todesart „' . trim((string)$master['manner_of_death']) . '“ – Freigabe abwarten');
		if (strtoupper(trim((string)($master['guardianship_status'] ?? ''))) === 'JA') $hints[] = self::hint('BETREUUNG', 'Betreuung' . (trim((string)($master['guardian_name'] ?? '')) !== '' ? ': ' . trim((string)$master['guardian_name']) : ''));
		if ($paymentOverdue) $hints[] = self::hint('ZAHLUNG_UEBERFAELLIG', 'Zahlung überfällig');
		return $hints;
	}

	private static function hint(string $key, string $title): array {
		$hint = self::HINTS[$key];
		return ['key' => $key, 'name' => $hint['name'], 'color' => $hint['color'], 'icon' => $hint['icon'], 'description' => $hint['description'], 'automatic' => true, 'title' => $title];
	}

	/** Zahlungsziel überschritten? (gleiche Regel wie der Ablaufauslöser „Rechnung überfällig“ mit 0 Tagen) */
	public static function invoiceOverdue(string $invoiceDate, int $paymentTermDays, string $today): bool {
		if (trim($invoiceDate) === '') return false;
		[$dueDate] = ProcessRules::invoiceOverdue($invoiceDate, $paymentTermDays, 0, $today);
		return $dueDate < substr($today, 0, 10);
	}

	/**
	 * Notiz prüfen und bereinigen: Zeilenumbrüche vereinheitlicht, Steuerzeichen entfernt, höchstens drei
	 * Leerzeilen hintereinander, höchstens NOTE_MAX Zeichen. Leere Notiz = ''.
	 */
	public static function normalizeNote(string $note): string {
		$note = str_replace(["\r\n", "\r"], "\n", $note);
		$note = preg_replace('/[^\P{C}\n\t]/u', '', $note) ?? '';
		$note = preg_replace('/[ \t]+\n/u', "\n", $note) ?? '';
		$note = preg_replace('/\n{3,}/u', "\n\n", $note) ?? '';
		$note = trim($note);
		if (mb_strlen($note) > self::NOTE_MAX) throw new \InvalidArgumentException('Die Notiz darf höchstens ' . self::NOTE_MAX . ' Zeichen lang sein.');
		return $note;
	}

	/**
	 * Notizblock für die Beschreibung einer Ablaufaufgabe.
	 * @param array<string,string> $notes Markername => Notiz (leere Notizen werden übergangen)
	 */
	public static function taskNoteBlock(array $notes): string {
		$lines = [];
		foreach ($notes as $name => $note) {
			$note = trim((string)$note);
			if ($note !== '') $lines[] = '„' . $name . '“: ' . str_replace("\n", "\n  ", $note);
		}
		return $lines === [] ? '' : self::TASK_NOTE_HEADING . "\n" . implode("\n", $lines);
	}

	/**
	 * Beschreibung einer Aufgabe mit aktuellem Notizblock: Der zuvor erzeugte Block wird ersetzt, sonst wird
	 * angehängt. Hat jemand den Block von Hand verändert, bleibt dessen Text stehen und der neue Block folgt.
	 */
	public static function withTaskNoteBlock(string $description, string $previousBlock, string $block): string {
		$description = rtrim($description);
		$position = $previousBlock !== '' ? strrpos($description, $previousBlock) : false;
		// Nur ein vollständiger, unveränderter Block zählt (danach Textende oder Zeilenumbruch).
		if ($position !== false) {
			$rest = substr($description, $position + strlen($previousBlock));
			if ($rest === '' || str_starts_with($rest, "\n")) $description = rtrim(rtrim(substr($description, 0, $position)) . $rest);
		}
		if ($block === '') return $description;
		return $description === '' ? $block : $description . "\n\n" . $block;
	}

	/** Klartext eines gesetzten Markers, z. B. „Kostenträger: Sozialamt“. */
	public static function displayText(string $name, string $kind, string $valueName): string {
		return $kind === 'LIST' && $valueName !== '' ? $name . ': ' . $valueName : $name;
	}
}
