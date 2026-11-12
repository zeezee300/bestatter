<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * 0.90.2: Aufbau der Fall-, Auftrags- und Vorsorgenummern, wahlweise mit Kennung der Niederlassung.
 *
 * Aufbau (wie die Rechnungsnummer unter Administration einstellbar):
 *  - YEAR_SEQ         „2026-0001“ (Standard, unverändert seit 0.65)
 *  - BRANCH_YEAR_SEQ  „HB-2026-0001“
 *  - YEAR_BRANCH_SEQ  „2026-HB-0001“
 * Aufträge und Vorsorgen behalten ihr Präfix vorn („A-HB-2026-0001“, „V-2026-HB-0001“).
 *
 * Zählweise: YEAR_GLOBAL – eine Folge je Vorgangsart und Jahr für alle Niederlassungen (Zähler „CASE:2026“ wie bisher);
 * YEAR_BRANCH – je Niederlassungskennung eine eigene Folge („CASE:2026:HB“). Ohne Niederlassung am Fall entsteht die
 * Nummer ohne Kennung aus dem gemeinsamen Zähler. Eine schon vergebene Nummer wird nie ein zweites Mal ausgegeben
 * (nach einem Wechsel von Aufbau oder Zählweise überspringt der Zähler belegte Nummern).
 *
 * Die Kennung ist je Niederlassung pflegbar („Kennung in Fallnummern“, 1–5 Großbuchstaben/Ziffern, z. B. „HB“ oder „01“);
 * ohne eigene Kennung gilt der Niederlassungsschlüssel, wenn er diese Form hat. Gespeichert als App-Konfiguration
 * „case_number_settings“ (JSON) und damit in der Datensicherung enthalten.
 */
class CaseNumberService {
	public const CONFIG_KEY = 'case_number_settings';

	public const LAYOUTS = [
		'YEAR_SEQ' => 'Ohne Niederlassung (2026-0001)',
		'BRANCH_YEAR_SEQ' => 'Kennung vorn (HB-2026-0001)',
		'YEAR_BRANCH_SEQ' => 'Kennung nach dem Jahr (2026-HB-0001)',
	];
	public const SCOPES = [
		'YEAR_GLOBAL' => 'Jährlich, gemeinsam für alle Niederlassungen',
		'YEAR_BRANCH' => 'Jährlich je Niederlassung',
	];
	public const DEFAULTS = ['layout' => 'YEAR_SEQ', 'scope' => 'YEAR_GLOBAL'];

	/** Kennung: 1–5 Großbuchstaben oder Ziffern; nicht „A“/„V“/„KVA“ (Präfixe von Aufträgen, Vorsorgen, Kostenvoranschlägen) und keine Jahreszahl. */
	public const CODE_PATTERN = '/^[A-Z0-9]{1,5}$/';
	public const RESERVED_CODES = ['A', 'V', 'KVA'];

	/** Sicherheitsgrenze für das Überspringen belegter Nummern nach einem Wechsel des Aufbaus. */
	private const MAX_SKIPS = 10000;

	public function __construct(
		private IConfig $config,
		private IDBConnection $db,
	) {
	}

	/** @return array{layout:string,scope:string} */
	public function settings(): array {
		$stored = json_decode($this->config->getAppValue(Application::APP_ID, self::CONFIG_KEY, ''), true);
		return self::normalize(is_array($stored) ? $stored : []);
	}

	/** @return array{layout:string,scope:string} */
	public static function normalize(array $data): array {
		$layout = strtoupper(trim((string)($data['layout'] ?? self::DEFAULTS['layout'])));
		$scope = strtoupper(trim((string)($data['scope'] ?? self::DEFAULTS['scope'])));
		return [
			'layout' => array_key_exists($layout, self::LAYOUTS) ? $layout : self::DEFAULTS['layout'],
			'scope' => array_key_exists($scope, self::SCOPES) ? $scope : self::DEFAULTS['scope'],
		];
	}

	public static function withBranch(array $settings): bool {
		return ($settings['layout'] ?? 'YEAR_SEQ') !== 'YEAR_SEQ';
	}

	/** Einstellungen, Auswahllisten, Kennungen der Niederlassungen und Beispiele für die Oberfläche. */
	public function view(): array {
		$settings = $this->settings();
		$branches = [];
		foreach ($this->branchRows() as $row) {
			$code = self::effectiveCode((string)($row['number_code'] ?? ''), (string)$row['branch_key']);
			$branches[] = ['key' => (string)$row['branch_key'], 'name' => (string)$row['name'], 'active' => (bool)$row['active'], 'numberCode' => (string)($row['number_code'] ?? ''), 'effectiveCode' => $code];
		}
		$example = '';
		foreach ($branches as $branch) {
			if ($branch['active'] && $branch['effectiveCode'] !== '') {
				$example = $branch['effectiveCode'];
				break;
			}
		}
		$example = $example !== '' ? $example : 'HB';
		$year = date('Y');
		return [
			'settings' => $settings,
			'layouts' => self::LAYOUTS,
			'scopes' => self::SCOPES,
			'branches' => $branches,
			'problems' => $this->problems($settings),
			'preview' => [
				CaseKinds::DEATH => self::compose($settings['layout'], CaseKinds::DEATH, $year, $example, 1),
				CaseKinds::ORDER => self::compose($settings['layout'], CaseKinds::ORDER, $year, $example, 1),
				CaseKinds::PRECARE => self::compose($settings['layout'], CaseKinds::PRECARE, $year, $example, 1),
			],
		];
	}

	/** Speichert Aufbau und Zählweise; mit Kennung nur, wenn jede aktive Niederlassung eine gültige Kennung hat. */
	public function save(array $data): array {
		$settings = self::normalize($data);
		$layout = strtoupper(trim((string)($data['layout'] ?? '')));
		$scope = strtoupper(trim((string)($data['scope'] ?? '')));
		if ($layout !== '' && !array_key_exists($layout, self::LAYOUTS)) {
			throw new \InvalidArgumentException('Unbekannter Aufbau der Fallnummer.');
		}
		if ($scope !== '' && !array_key_exists($scope, self::SCOPES)) {
			throw new \InvalidArgumentException('Unbekannte Zählweise der Fallnummer.');
		}
		$problems = $this->problems($settings);
		if ($problems !== []) {
			throw new \InvalidArgumentException(implode(' ', $problems));
		}
		$this->config->setAppValue(Application::APP_ID, self::CONFIG_KEY, json_encode($settings, JSON_THROW_ON_ERROR));
		return $this->view();
	}

	/** @return list<string> Gründe, warum der Aufbau mit Kennung (noch) nicht nutzbar ist. */
	public function problems(?array $settings = null): array {
		$settings ??= $this->settings();
		if (!self::withBranch($settings)) {
			return [];
		}
		$problems = [];
		$seen = [];
		foreach ($this->branchRows() as $row) {
			if (!(bool)$row['active']) {
				continue;
			}
			$code = self::effectiveCode((string)($row['number_code'] ?? ''), (string)$row['branch_key']);
			if ($code === '') {
				$problems[] = 'Für die Niederlassung „' . (string)$row['name'] . '“ fehlt die Kennung für Fallnummern (Stammdaten → Niederlassungen).';
				continue;
			}
			if (isset($seen[$code])) {
				$problems[] = 'Die Kennung „' . $code . '“ ist bei „' . $seen[$code] . '“ und „' . (string)$row['name'] . '“ gleich; jede Niederlassung braucht eine eigene Kennung.';
			}
			$seen[$code] = (string)$row['name'];
		}
		return $problems;
	}

	/**
	 * Prüft eine eingegebene Kennung und gibt sie normalisiert zurück (leer = keine eigene Kennung).
	 */
	public static function normalizeCode(string $value): string {
		$code = strtoupper(trim($value));
		if ($code === '') {
			return '';
		}
		if (!preg_match(self::CODE_PATTERN, $code)) {
			throw new \InvalidArgumentException('Die Kennung für Fallnummern darf 1 bis 5 Großbuchstaben oder Ziffern enthalten (z. B. „HB“ oder „01“).');
		}
		if (in_array($code, self::RESERVED_CODES, true)) {
			throw new \InvalidArgumentException('Die Kennung „' . $code . '“ ist vergeben (A-, V- und KVA- kennzeichnen Aufträge, Vorsorgen und Kostenvoranschläge).');
		}
		if (preg_match('/^[0-9]{4,5}$/', $code)) {
			throw new \InvalidArgumentException('Eine Kennung aus vier oder fünf Ziffern ist nicht möglich (sie wäre mit Jahr oder laufender Nummer zu verwechseln).');
		}
		return $code;
	}

	/** Eigene Kennung oder – wenn sie fehlt – der Niederlassungsschlüssel, sofern er als Kennung taugt; sonst leer. */
	public static function effectiveCode(string $numberCode, string $branchKey): string {
		foreach ([$numberCode, $branchKey] as $candidate) {
			try {
				$code = self::normalizeCode($candidate);
			} catch (\InvalidArgumentException) {
				continue;
			}
			if ($code !== '') {
				return $code;
			}
		}
		return '';
	}

	/** Nummer aus Aufbau, Vorgangsart, Jahr, Kennung (leer = ohne) und laufender Nummer. */
	public static function compose(string $layout, string $kind, string $year, string $code, int $sequence): string {
		$prefix = CaseKinds::PREFIXES[CaseKinds::normalize($kind)];
		$seq = sprintf('%04d', $sequence);
		if ($code === '' || $layout === 'YEAR_SEQ') {
			return $prefix . $year . '-' . $seq;
		}
		return $layout === 'YEAR_BRANCH_SEQ' ? $prefix . $year . '-' . $code . '-' . $seq : $prefix . $code . '-' . $year . '-' . $seq;
	}

	/** Zählerschlüssel: wie bisher „CASE:2026“ bzw. je Kennung „CASE:2026:HB“. */
	public static function sequenceKey(string $kind, string $year, string $scope, string $code): string {
		$key = CaseKinds::sequenceKey($kind, $year);
		return $scope === 'YEAR_BRANCH' && $code !== '' ? $key . ':' . $code : $key;
	}

	/** Kennung der Niederlassung des Falls nach den aktuellen Einstellungen (leer = Nummer ohne Kennung). */
	public function codeForBranch(string $branchKey): string {
		$branchKey = strtoupper(trim($branchKey));
		if ($branchKey === '' || !self::withBranch($this->settings())) {
			return '';
		}
		foreach ($this->branchRows() as $row) {
			if (strtoupper((string)$row['branch_key']) === $branchKey) {
				return self::effectiveCode((string)($row['number_code'] ?? ''), (string)$row['branch_key']);
			}
		}
		return '';
	}

	/**
	 * Niederlassung für einen neuen Fall ohne Angabe, wenn Nummern eine Kennung tragen: die aktive Niederlassung der
	 * zuständigen Person, sonst die einzige aktive Niederlassung; sonst leer.
	 */
	public function defaultBranch(string $uid): string {
		if (!self::withBranch($this->settings())) {
			return '';
		}
		$active = array_values(array_filter($this->branchRows(), static fn (array $row): bool => (bool)$row['active']));
		if ($uid !== '') {
			foreach ($active as $row) {
				$members = json_decode((string)($row['member_uids'] ?? '[]'), true);
				if (is_array($members) && in_array($uid, $members, true)) {
					return (string)$row['branch_key'];
				}
			}
		}
		return count($active) === 1 ? (string)$active[0]['branch_key'] : '';
	}

	/**
	 * Nächste freie Nummer. Aufruf innerhalb der Transaktion der Fallanlage (der Zähler bleibt bis zum Ende gesperrt).
	 */
	public function next(string $kind, string $year, string $branchKey): string {
		$settings = $this->settings();
		$code = $this->codeForBranch($branchKey);
		$key = self::sequenceKey($kind, $year, $settings['scope'], $code);
		for ($i = 0; $i < self::MAX_SKIPS; $i++) {
			$number = self::compose($settings['layout'], $kind, $year, $code, DatabaseLocks::nextSequence($this->db, $key));
			if (!$this->numberExists($number)) {
				return $number;
			}
		}
		throw new \RuntimeException('Für den Nummernkreis „' . $key . '“ wurde keine freie Nummer gefunden.');
	}

	private function numberExists(string $number): bool {
		$query = $this->db->getQueryBuilder();
		$id = $query->select('id')->from('bestatter_cases')
			->where($query->expr()->eq('case_number', $query->createNamedParameter($number)))
			->setMaxResults(1)->executeQuery()->fetchOne();
		return $id !== false && $id !== null;
	}

	/** @return list<array<string,mixed>> */
	private function branchRows(): array {
		$query = $this->db->getQueryBuilder();
		return $query->select('*')->from('bestatter_branches')->orderBy('sort_order', 'ASC')->addOrderBy('name', 'ASC')->executeQuery()->fetchAllAssociative();
	}
}
