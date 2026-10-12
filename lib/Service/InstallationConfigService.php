<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\Files\Folder;
use OCP\IConfig;

/**
 * Installation-specific role and storage configuration.
 *
 * Values live in Nextcloud's persistent app config and can therefore be
 * maintained through the UI or provisioned with occ config:app:set.
 */
class InstallationConfigService {
	private const DEFAULTS = [
		'member_group' => 'Bestatter',
		'admin_groups' => 'Bestatter-Administratoren,Bestatter Administratoren',
		'storage_root' => 'Bestatter',
		'cases_folder' => 'Fälle',
		'templates_folder' => 'Vorlagen',
		'assistant_folder' => '.Assistenz',
		'article_images_folder' => 'Artikelbilder',
		'default_upload_folder' => '01 Stammdaten',
		'order_folder' => '02 Auftrag',
		'authorities_folder' => '03 Behörden',
		'billing_folder' => '06 Abrechnung',
		// Befund (Abmeldungen-Test 2026-09-22): resources/document-templates.json weist den
		// beiden Korrespondenz-Vorlagen NACHLASSMITTEILUNG_BANK und ARBEITGEBER_STERBEFALLMITTEILUNG
		// den Ablageordner "04 Korrespondenz" zu, der hier bislang NICHT enthalten war - jede
		// Erzeugung dieser beiden Dokumente scheiterte deshalb serverseitig mit "Der gewählte
		// Ablageordner ist nicht zulässig." (FolderService::caseSubfolder()). "04 Korrespondenz"
		// gehört daher fest in die Standardliste.
		'case_subfolders' => '["01 Stammdaten","02 Auftrag","03 Behörden","04 Friedhof","04 Korrespondenz","05 Trauerdruck","06 Abrechnung"]',
		// 0.66.0: Rollen der Ablaufvorlagen = reguläre Nextcloud-Gruppen (keine eigene Rollenverwaltung).
		// Kundenentscheidung 2026-09-24: Bestattung, Büro, Fahrer, Buchhaltung.
		// 0.70.0 (OP-040): USER = bisherige benutzerbezogene Ablage, CENTRAL = gemeinsame Fallablage im
		// Nextcloud-Konto storage_account (eigener Ordner mit Gruppenfreigabe oder Teamordner/Group folder).
		'storage_mode' => 'USER',
		'storage_account' => '',
		'role_groups' => '{"BESTATTER":"Bestatter-Bestattung","BUERO":"Bestatter-Büro","FAHRER":"Bestatter-Fahrer","BUCHHALTUNG":"Bestatter-Buchhaltung"}',
	];

	public const ROLE_KEYS = ['BESTATTER', 'BUERO', 'FAHRER', 'BUCHHALTUNG'];

	/**
	 * 0.72.0 – Ordnerplan: Jeder Fall-Unterordner hat ein festes Kennzeichen und einen änderbaren Namen.
	 * Funktionsordner (Upload, Auftrag, Behörden, Rechnungen) und Aufbewahrungsregeln hängen am Kennzeichen,
	 * sodass Umbenennen/Umnummerieren sie nicht mehr ungültig macht. Gespeichert in „case_folder_plan“ und
	 * „function_folders“; ohne diese Werte wird der Plan aus den bisherigen Einstellungen abgeleitet.
	 */
	public const STANDARD_FOLDERS = ['STAMMDATEN' => '01 Stammdaten', 'AUFTRAG' => '02 Auftrag', 'BEHOERDEN' => '03 Behörden', 'FRIEDHOF' => '04 Friedhof', 'KORRESPONDENZ' => '04 Korrespondenz', 'TRAUERDRUCK' => '05 Trauerdruck', 'ABRECHNUNG' => '06 Abrechnung'];
	public const FUNCTIONS = ['UPLOAD' => ['default_upload_folder', 'Standardablage Upload'], 'ORDER' => ['order_folder', 'Ablage Auftrag/KVA'], 'AUTHORITIES' => ['authorities_folder', 'Ablage Behörden'], 'BILLING' => ['billing_folder', 'Ablage Rechnungen']];

	public function __construct(private IConfig $config) {}

	public function settings(): array {
		return [
			'memberGroup' => $this->memberGroup(),
			'adminGroups' => $this->adminGroups(),
			'storageRoot' => $this->storageRoot(),
			'casesFolder' => $this->casesFolder(),
			'templatesFolder' => $this->templatesFolder(),
			'assistantFolder' => $this->assistantFolder(),
			'articleImagesFolder' => $this->articleImagesFolder(),
			'defaultUploadFolder' => $this->defaultUploadFolder(),
			'orderFolder' => $this->orderFolder(),
			'authoritiesFolder' => $this->authoritiesFolder(),
			'billingFolder' => $this->billingFolder(),
			'caseSubfolders' => $this->caseSubfolders(),
			'folderPlan' => $this->folderPlan(),
			'functionFolders' => $this->functionFolders(),
			'roleGroups' => $this->roleGroups(),
			'storageMode' => $this->storageMode(),
			'storageAccount' => $this->storageAccount(),
			'paths' => [
				'cases' => $this->casesPath(),
				'templates' => $this->templatesPath(),
				'assistant' => $this->join($this->storageRoot(), $this->assistantFolder()),
				'articleImages' => $this->join($this->storageRoot(), $this->articleImagesFolder()),
			],
		];
	}

	public function save(array $input): array {
		$memberGroup = $this->groupName((string)($input['memberGroup'] ?? ''), 'Mitgliedergruppe');
		$adminGroups = array_values(array_unique(array_filter(array_map(
			fn(mixed $value): string => $this->groupName((string)$value, 'Administrationsgruppe'),
			(array)($input['adminGroups'] ?? []),
		))));
		if ($adminGroups === []) throw new \InvalidArgumentException('Mindestens eine Administrationsgruppe ist erforderlich.');

		$values = [
			'member_group' => $memberGroup,
			'admin_groups' => implode(',', $adminGroups),
			'storage_root' => $this->relativePath((string)($input['storageRoot'] ?? ''), 'Arbeitsordner'),
			'cases_folder' => $this->folderName((string)($input['casesFolder'] ?? ''), 'Fallordner'),
			'templates_folder' => $this->folderName((string)($input['templatesFolder'] ?? ''), 'Vorlagenordner'),
			'assistant_folder' => $this->folderName((string)($input['assistantFolder'] ?? ''), 'Assistenzordner'),
			'article_images_folder' => $this->folderName((string)($input['articleImagesFolder'] ?? ''), 'Artikelbilderordner'),
		];
		// 0.72.0: Der Ordnerplan wird ausschließlich über CaseFolderPlanService geändert (mit Vorschau und
		// Umbenennung der vorhandenen Fallordner). Die frühere Namensliste wird nur noch angenommen, wenn sie
		// dem aktuellen Plan entspricht (z. B. aus der Ersteinrichtung ohne Änderung).
		if (array_key_exists('caseSubfolders', $input)) {
			$names = array_values(array_map(fn(mixed $value): string => $this->folderName((string)$value, 'Fall-Unterordner'), (array)$input['caseSubfolders']));
			if ($names !== $this->caseSubfolders()) {
				if ($this->planStored()) throw new \InvalidArgumentException('Die Fall-Unterordner werden über den Ordnerplan geändert (Vorschau, dann „Änderung übernehmen“). Vorhandene Fallordner werden dabei mit umbenannt.');
				// Erstkonfiguration (noch kein gespeicherter Plan): Namensliste in einen Plan übernehmen.
				$this->storePlan($this->planFromNames($names), $this->functionFolders());
			}
		}
		// Funktionsordner (Auswahl nach Namen im aktuellen Plan) – gespeichert wird das Kennzeichen.
		$plan = $this->folderPlan();
		$functions = $this->functionFolders(); $functionsChanged = false;
		foreach (['defaultUploadFolder' => 'UPLOAD', 'orderFolder' => 'ORDER', 'authoritiesFolder' => 'AUTHORITIES', 'billingFolder' => 'BILLING'] as $inputKey => $function) {
			if (!isset($input[$inputKey]) || trim((string)$input[$inputKey]) === '') continue;
			$match = array_values(array_filter($plan, static fn(array $item): bool => $item['name'] === trim((string)$input[$inputKey]) || $item['key'] === trim((string)$input[$inputKey])));
			if ($match === []) throw new \InvalidArgumentException('Der Funktionsordner "' . trim((string)$input[$inputKey]) . '" ist nicht in den Standard-Unterordnern enthalten.');
			if ($functions[$function] !== $match[0]['key']) { $functions[$function] = $match[0]['key']; $functionsChanged = true; }
		}
		if ($functionsChanged) $this->storePlan($plan, $functions);

		if (is_array($input['roleGroups'] ?? null)) {
			$roles = $this->roleGroups();
			foreach (self::ROLE_KEYS as $role) {
				if (!array_key_exists($role, $input['roleGroups'])) continue;
				$name = trim((string)$input['roleGroups'][$role]);
				$roles[$role] = $name === '' ? '' : $this->groupName($name, 'Rollengruppe');
			}
			$values['role_groups'] = json_encode($roles, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		}

		foreach ($values as $key => $value) $this->config->setAppValue(Application::APP_ID, $key, $value);
		return $this->settings();
	}

	public function memberGroup(): string { return $this->value('member_group'); }
	public function storageMode(): string { return $this->value('storage_mode') === 'CENTRAL' ? 'CENTRAL' : 'USER'; }
	public function storageAccount(): string { return $this->value('storage_account'); }
	/** Nur über CaseStorageService (mit Prüfung von Konto und Bestand) aufrufen. */
	public function setStorage(string $mode, string $account): void {
		$this->config->setAppValue(Application::APP_ID, 'storage_mode', $mode === 'CENTRAL' ? 'CENTRAL' : 'USER');
		$this->config->setAppValue(Application::APP_ID, 'storage_account', trim($account));
	}
	/** Bis 0.70: Erfassungseingang im Arbeitsordner (bei zentraler Ablage bzw. Teamordner für alle sichtbar). */
	public function captureInboxPath(): string { return $this->join($this->storageRoot(), '.Erfassungseingang'); }
	/** 0.71.0: Persönlicher, nicht geteilter Arbeitsbereich im eigenen Nextcloud-Ordner (Sprachaufnahmen, Erfassungsbögen vor der Zuordnung). */
	public const PRIVATE_WORK_ROOT = '.Bestatter-privat';
	public function privateAudioPath(): string { return self::PRIVATE_WORK_ROOT . '/Aufnahmen'; }
	public function privateCaptureInboxPath(): string { return self::PRIVATE_WORK_ROOT . '/Erfassungseingang'; }
	public function articleImagesPath(): string { return $this->join($this->storageRoot(), $this->articleImagesFolder()); }
	public function assistantPath(): string { return $this->join($this->storageRoot(), $this->assistantFolder()); }
	public function adminGroups(): array { return array_values(array_filter(array_map('trim', explode(',', $this->value('admin_groups'))))); }
	public function storageRoot(): string { return $this->value('storage_root'); }
	public function casesFolder(): string { return $this->value('cases_folder'); }
	public function templatesFolder(): string { return $this->value('templates_folder'); }
	public function assistantFolder(): string { return $this->value('assistant_folder'); }
	public function articleImagesFolder(): string { return $this->value('article_images_folder'); }
	public function defaultUploadFolder(): string { return $this->functionFolderName('UPLOAD'); }
	public function orderFolder(): string { return $this->functionFolderName('ORDER'); }
	public function authoritiesFolder(): string { return $this->functionFolderName('AUTHORITIES'); }
	public function billingFolder(): string { return $this->functionFolderName('BILLING'); }

	/** Namen der Fall-Unterordner in Planreihenfolge. */
	public function caseSubfolders(): array {
		return array_values(array_map(static fn(array $item): string => $item['name'], $this->folderPlan()));
	}

	/** @return list<array{key: string, name: string}> */
	public function folderPlan(): array {
		$stored = json_decode(trim($this->config->getAppValue(Application::APP_ID, 'case_folder_plan', '')), true);
		if (is_array($stored) && $stored !== []) {
			$plan = [];
			foreach ($stored as $item) if (is_array($item) && isset($item['key'], $item['name'])) $plan[] = ['key' => (string)$item['key'], 'name' => (string)$item['name']];
			if ($plan !== []) return $plan;
		}
		$decoded = json_decode($this->value('case_subfolders'), true);
		return $this->planFromNames(is_array($decoded) && $decoded !== [] ? array_values(array_map('strval', $decoded)) : array_values(self::STANDARD_FOLDERS));
	}

	/** @return array<string, string> Funktion (UPLOAD, ORDER, AUTHORITIES, BILLING) => Ordner-Kennzeichen */
	public function functionFolders(): array {
		$plan = $this->folderPlan();
		$keys = array_column($plan, 'key');
		$stored = json_decode(trim($this->config->getAppValue(Application::APP_ID, 'function_folders', '')), true);
		$result = [];
		foreach (self::FUNCTIONS as $function => [$legacyKey]) {
			$key = is_array($stored) ? (string)($stored[$function] ?? '') : '';
			if ($key === '' || !in_array($key, $keys, true)) {
				$legacyName = $this->value($legacyKey);
				$byName = array_values(array_filter($plan, static fn(array $item): bool => $item['name'] === $legacyName));
				$key = $byName[0]['key'] ?? ($this->resolveKey($legacyName) ?? ($keys[0] ?? ''));
			}
			$result[$function] = $key;
		}
		return $result;
	}

	public function folderNameForKey(string $key): ?string {
		foreach ($this->folderPlan() as $item) if ($item['key'] === $key) return $item['name'];
		return null;
	}

	private function functionFolderName(string $function): string {
		return $this->folderNameForKey($this->functionFolders()[$function] ?? '') ?? $this->value(self::FUNCTIONS[$function][0]);
	}

	/**
	 * Aktueller Ordnername zu einem Verweis: aktueller Name, Kennzeichen, Standardname („01 Stammdaten“) oder
	 * Name ohne Nummerierung. So funktionieren Vorlagen und gespeicherte Angaben auch nach einer Umbenennung.
	 */
	public function resolveSubfolder(string $reference): ?string {
		$reference = trim($reference);
		if ($reference === '') return null;
		foreach ($this->folderPlan() as $item) if ($item['name'] === $reference) return $item['name'];
		$key = $this->resolveKey($reference);
		return $key !== null ? $this->folderNameForKey($key) : null;
	}

	private function resolveKey(string $reference): ?string {
		$keys = array_column($this->folderPlan(), 'key');
		$reference = trim($reference);
		if (in_array(strtoupper($reference), $keys, true)) return strtoupper($reference);
		$standard = array_search($reference, self::STANDARD_FOLDERS, true);
		if (is_string($standard) && in_array($standard, $keys, true)) return $standard;
		$derived = self::keyFromName($reference);
		return in_array($derived, $keys, true) ? $derived : null;
	}

	/** Kennzeichen aus einem Ordnernamen: Nummerierung entfernen, Umlaute umschreiben, Großbuchstaben. */
	public static function keyFromName(string $name): string {
		$name = preg_replace('/^[\s\d._\-]+/u', '', trim($name)) ?? $name;
		$name = strtr(mb_strtoupper($name), ['Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE', 'ß' => 'SS', 'ẞ' => 'SS']);
		$key = trim(preg_replace('/[^A-Z0-9]+/', '_', $name) ?? '', '_');
		return $key !== '' ? mb_substr($key, 0, 40) : 'ORDNER';
	}

	/** @param list<string> $names @return list<array{key: string, name: string}> */
	public function planFromNames(array $names): array {
		$plan = []; $used = [];
		foreach ($names as $name) {
			$name = $this->folderName((string)$name, 'Fall-Unterordner');
			if (in_array(mb_strtolower($name), array_map('mb_strtolower', array_column($plan, 'name')), true)) continue;
			$standard = array_search($name, self::STANDARD_FOLDERS, true);
			$base = is_string($standard) ? $standard : self::keyFromName($name);
			$key = $base; $n = 2;
			while (isset($used[$key])) $key = $base . '_' . $n++;
			$used[$key] = true;
			$plan[] = ['key' => $key, 'name' => $name];
		}
		return $plan;
	}

	public function planStored(): bool {
		return trim($this->config->getAppValue(Application::APP_ID, 'case_folder_plan', '')) !== '';
	}

	/**
	 * Nur über CaseFolderPlanService (Prüfung, Umbenennung der Fallordner) bzw. die Ersteinrichtung aufrufen.
	 * Schreibt zusätzlich die bisherigen Einzelwerte (Namen), damit ältere Auswertungen konsistent bleiben.
	 * @param list<array{key: string, name: string}> $plan @param array<string, string> $functions
	 */
	public function storePlan(array $plan, array $functions): void {
		$names = array_column($plan, 'name');
		$this->config->setAppValue(Application::APP_ID, 'case_folder_plan', json_encode(array_values($plan), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		$this->config->setAppValue(Application::APP_ID, 'function_folders', json_encode($functions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		$this->config->setAppValue(Application::APP_ID, 'case_subfolders', json_encode(array_values($names), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		foreach (self::FUNCTIONS as $function => [$legacyKey]) {
			foreach ($plan as $item) if ($item['key'] === ($functions[$function] ?? '')) $this->config->setAppValue(Application::APP_ID, $legacyKey, $item['name']);
		}
	}

	/** Läuft gerade eine Umstellung des Ordnerplans? (siehe CaseFolderPlanService, höchstens 15 Minuten) */
	public function folderPlanLocked(): bool {
		$value = (int)$this->config->getAppValue(Application::APP_ID, 'folder_plan_lock', '0');
		return $value > 0 && $value > time() - 900;
	}

	/** Öffentliche Namensprüfung für den Ordnerplan. */
	public function validFolderName(string $value, string $label): string { return $this->folderName($value, $label); }

	/** Rolle => Nextcloud-Gruppenname; leerer Name = Rolle nicht belegt (Aufgabe geht an die Fallverantwortung). */
	public function roleGroups(): array {
		$decoded = json_decode($this->value('role_groups'), true);
		$defaults = json_decode(self::DEFAULTS['role_groups'], true);
		$result = [];
		foreach (self::ROLE_KEYS as $role) $result[$role] = trim((string)(is_array($decoded) && array_key_exists($role, $decoded) ? $decoded[$role] : ($defaults[$role] ?? '')));
		return $result;
	}

	public function casesPath(): string { return $this->join($this->storageRoot(), $this->casesFolder()); }
	public function templatesPath(): string { return $this->join($this->storageRoot(), $this->templatesFolder()); }

	public function ensurePath(Folder $base, string $relativePath): Folder {
		$folder = $base;
		foreach (explode('/', trim($relativePath, '/')) as $name) {
			if ($name === '') continue;
			$node = $folder->nodeExists($name) ? $folder->get($name) : $folder->newFolder($name);
			if (!$node instanceof Folder) throw new \RuntimeException('Der konfigurierte Pfad "' . $relativePath . '" ist keine Ordnerstruktur.');
			$folder = $node;
		}
		return $folder;
	}

	private function value(string $key): string {
		return trim($this->config->getAppValue(Application::APP_ID, $key, self::DEFAULTS[$key]));
	}

	private function join(string ...$parts): string {
		return implode('/', array_values(array_filter(array_map(static fn(string $part): string => trim($part, '/'), $parts), static fn(string $part): bool => $part !== '')));
	}

	private function groupName(string $value, string $label): string {
		$value = trim($value);
		if ($value === '' || preg_match('/[\x00-\x1F\x7F]/u', $value)) throw new \InvalidArgumentException($label . ' ist ungültig.');
		return $value;
	}

	private function folderName(string $value, string $label): string {
		$value = trim($value);
		if ($value === '' || $value === '.' || $value === '..' || str_contains($value, '/') || str_contains($value, '\\') || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
			throw new \InvalidArgumentException($label . ' muss ein einzelner gültiger Ordnername sein.');
		}
		return $value;
	}

	private function relativePath(string $value, string $label): string {
		$value = trim(str_replace('\\', '/', $value), '/');
		if ($value === '') throw new \InvalidArgumentException($label . ' darf nicht leer sein.');
		foreach (explode('/', $value) as $segment) $this->folderName($segment, $label);
		return $value;
	}
}
