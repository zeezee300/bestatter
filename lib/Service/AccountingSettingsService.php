<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IConfig;

/**
 * 0.90.0 – Einstellungen des Buchhaltungsexports (DATEV) je Installation.
 *
 * Gespeichert als App-Konfiguration „accounting_settings“ (JSON); sie ist damit in der Datensicherung enthalten.
 * Berater-/Mandantennummer, Wirtschaftsjahr, Kontenrahmen, Kontenfindung und Debitoren kommen von der Steuerberatung –
 * die Vorschläge für SKR03/SKR04 sind nur Vorbelegung und vor dem ersten Export mit ihr abzustimmen.
 */
class AccountingSettingsService {
	public const CONFIG_KEY = 'accounting_settings';
	public const DEBTOR_MODES = ['INDIVIDUAL' => 'Eigener Debitor je Rechnungsempfänger', 'COLLECTIVE' => 'Ein Sammeldebitor für alle Rechnungen'];
	public const KOST1_SOURCES = ['NONE' => 'nicht ausgeben', 'BRANCH' => 'Niederlassung'];
	public const KOST2_SOURCES = ['NONE' => 'nicht ausgeben', 'CASE' => 'Fall- bzw. Auftragsnummer'];
	public const CHARTS = ['SKR03' => 'SKR03', 'SKR04' => 'SKR04'];
	public const ARTICLE_TYPES = ['DIENSTLEISTUNG', 'HANDELSWARE', 'FREMDLEISTUNG', 'AUSLAGE', 'ZUSCHLAG', 'PAKET'];

	/**
	 * Vorschläge je Kontenrahmen (Standardkontenrahmen, Automatikkonten → ohne BU-Schlüssel). Nur Vorbelegung:
	 * die Steuerberatung entscheidet über Konten, Steuerschlüssel und die Behandlung durchlaufender Posten.
	 */
	public const SUGGESTIONS = [
		'SKR03' => ['passThroughAccount' => '1590', 'rules' => [
			['label' => 'Erlöse 19 % USt', 'vatRate' => 19, 'account' => '8400', 'taxKey' => ''],
			['label' => 'Erlöse 7 % USt', 'vatRate' => 7, 'account' => '8300', 'taxKey' => ''],
		]],
		'SKR04' => ['passThroughAccount' => '1370', 'rules' => [
			['label' => 'Erlöse 19 % USt', 'vatRate' => 19, 'account' => '4400', 'taxKey' => ''],
			['label' => 'Erlöse 7 % USt', 'vatRate' => 7, 'account' => '4300', 'taxKey' => ''],
		]],
	];

	private const DEFAULTS = [
		'consultantNumber' => '', 'clientNumber' => '', 'fiscalYearStart' => '01-01', 'accountLength' => 4, 'chart' => '',
		'origin' => 'RE', 'lockBookings' => false, 'debtorMode' => 'INDIVIDUAL', 'debtorStart' => 10000, 'collectiveDebtor' => '',
		'passThroughAccount' => '', 'kost1' => 'NONE', 'kost2' => 'NONE', 'costCenters' => [], 'exportFolder' => 'DATEV-Export', 'rules' => [],
	];

	public function __construct(
		private IConfig $config,
	) {
	}

	public function settings(): array {
		$stored = json_decode($this->config->getAppValue(Application::APP_ID, self::CONFIG_KEY, ''), true);
		return self::normalize(is_array($stored) ? $stored : []);
	}

	/** Einstellungen mit Prüfergebnis und Auswahllisten für die Oberfläche. */
	public function view(): array {
		$settings = $this->settings();
		return [
			'settings' => $settings,
			'problems' => self::problems($settings),
			'debtorModes' => self::DEBTOR_MODES, 'kost1Sources' => self::KOST1_SOURCES, 'kost2Sources' => self::KOST2_SOURCES,
			'charts' => self::CHARTS, 'articleTypes' => self::ARTICLE_TYPES, 'suggestions' => self::SUGGESTIONS,
		];
	}

	public function save(array $input): array {
		$settings = self::normalize($input);
		$errors = self::validate($settings);
		if ($errors !== []) {
			throw new \InvalidArgumentException(implode(' ', $errors));
		}
		$this->config->setAppValue(Application::APP_ID, self::CONFIG_KEY, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		return $this->view();
	}

	/** Liest Eingaben tolerant ein (Strings aus Formularen, Leerwerte) und liefert immer die vollständige Struktur. */
	public static function normalize(array $input): array {
		$value = static fn (string $key): mixed => array_key_exists($key, $input) ? $input[$key] : self::DEFAULTS[$key];
		$digits = static fn (mixed $raw): string => preg_replace('/\s+/', '', trim((string)$raw)) ?? '';
		$rules = [];
		foreach ((array)$value('rules') as $rule) {
			if (!is_array($rule)) {
				continue;
			}
			$rate = trim((string)($rule['vatRate'] ?? ''));
			$normalized = [
				'label' => mb_substr(trim((string)($rule['label'] ?? '')), 0, 60),
				'area' => strtoupper(trim((string)($rule['area'] ?? ''))),
				'group' => strtoupper(trim((string)($rule['group'] ?? ''))),
				'articleType' => strtoupper(trim((string)($rule['articleType'] ?? ''))),
				'passThrough' => CsvTools::flag($rule['passThrough'] ?? false),
				'vatRate' => $rate === '' ? null : (int)$rate,
				'account' => $digits($rule['account'] ?? ''),
				'taxKey' => $digits($rule['taxKey'] ?? ''),
			];
			if ($normalized['account'] === '' && $normalized['label'] === '' && $normalized['group'] === '' && $normalized['area'] === '') {
				continue;
			}
			$rules[] = $normalized;
		}
		$costCenters = [];
		foreach ((array)$value('costCenters') as $branch => $center) {
			$branch = strtoupper(trim((string)$branch));
			$center = trim((string)$center);
			if ($branch !== '' && $center !== '') {
				$costCenters[$branch] = mb_substr($center, 0, 36);
			}
		}
		$fiscal = trim((string)$value('fiscalYearStart'));
		if (preg_match('/^(\d{1,2})\.(\d{1,2})\.?$/', $fiscal, $m)) {
			$fiscal = sprintf('%02d-%02d', (int)$m[2], (int)$m[1]);
		}
		$mode = strtoupper(trim((string)$value('debtorMode')));
		$chart = strtoupper(trim((string)$value('chart')));
		$kost1 = strtoupper(trim((string)$value('kost1')));
		$kost2 = strtoupper(trim((string)$value('kost2')));
		return [
			'consultantNumber' => $digits($value('consultantNumber')),
			'clientNumber' => $digits($value('clientNumber')),
			'fiscalYearStart' => $fiscal,
			'accountLength' => (int)$value('accountLength'),
			'chart' => isset(self::CHARTS[$chart]) ? $chart : '',
			'origin' => strtoupper(mb_substr(trim((string)$value('origin')), 0, 2)) ?: 'RE',
			'lockBookings' => CsvTools::flag($value('lockBookings')),
			'debtorMode' => isset(self::DEBTOR_MODES[$mode]) ? $mode : 'INDIVIDUAL',
			'debtorStart' => (int)$digits($value('debtorStart')),
			'collectiveDebtor' => $digits($value('collectiveDebtor')),
			'passThroughAccount' => $digits($value('passThroughAccount')),
			'kost1' => isset(self::KOST1_SOURCES[$kost1]) ? $kost1 : 'NONE',
			'kost2' => isset(self::KOST2_SOURCES[$kost2]) ? $kost2 : 'NONE',
			'costCenters' => $costCenters,
			'exportFolder' => trim(str_replace('\\', '/', (string)$value('exportFolder')), '/ '),
			'rules' => $rules,
		];
	}

	/** Formale Fehler, die das Speichern verhindern (falsche Längen, Zeichen, Bereiche). */
	public static function validate(array $settings): array {
		$errors = [];
		$length = (int)$settings['accountLength'];
		if ($length < 4 || $length > 8) {
			$errors[] = 'Die Sachkontenlänge muss zwischen 4 und 8 liegen.';
			return $errors;
		}
		if ($settings['consultantNumber'] !== '' && (!ctype_digit($settings['consultantNumber']) || (int)$settings['consultantNumber'] < 1001 || (int)$settings['consultantNumber'] > 9999999)) {
			$errors[] = 'Die Beraternummer muss eine Zahl von 1001 bis 9999999 sein.';
		}
		if ($settings['clientNumber'] !== '' && (!ctype_digit($settings['clientNumber']) || (int)$settings['clientNumber'] < 1 || (int)$settings['clientNumber'] > 99999)) {
			$errors[] = 'Die Mandantennummer muss eine Zahl von 1 bis 99999 sein.';
		}
		if (!preg_match('/^(\d{2})-(\d{2})$/', $settings['fiscalYearStart'], $m) || !checkdate((int)$m[1], (int)$m[2], 2001)) {
			$errors[] = 'Beginn des Wirtschaftsjahres bitte als TT.MM. angeben (z. B. 01.01.).';
		}
		if (!preg_match('/^[A-Z0-9]{2}$/', $settings['origin'])) {
			$errors[] = 'Das Herkunftskürzel besteht aus zwei Buchstaben oder Ziffern.';
		}
		foreach (['passThroughAccount' => 'Konto für durchlaufende Posten'] as $key => $label) {
			if ($settings[$key] !== '' && !self::isLedgerAccount($settings[$key], $length)) {
				$errors[] = $label . ': ' . $length . '-stellige Kontonummer erwartet.';
			}
		}
		[$min, $max] = self::debtorRange($length);
		if ($settings['debtorMode'] === 'INDIVIDUAL' && ($settings['debtorStart'] < $min || $settings['debtorStart'] > $max)) {
			$errors[] = 'Der Debitorennummernkreis muss bei Sachkontenlänge ' . $length . ' zwischen ' . $min . ' und ' . $max . ' beginnen.';
		}
		if ($settings['collectiveDebtor'] !== '' && ((int)$settings['collectiveDebtor'] < $min || (int)$settings['collectiveDebtor'] > $max)) {
			$errors[] = 'Der Sammeldebitor muss bei Sachkontenlänge ' . $length . ' zwischen ' . $min . ' und ' . $max . ' liegen.';
		}
		if ($settings['exportFolder'] !== '' && (str_contains($settings['exportFolder'], '..') || preg_match('/[\x00-\x1F\x7F:*?"<>|]/u', $settings['exportFolder']))) {
			$errors[] = 'Der Ablageordner enthält unzulässige Zeichen.';
		}
		foreach ($settings['rules'] as $index => $rule) {
			$label = 'Kontenzuordnung ' . ($index + 1) . ($rule['label'] !== '' ? ' („' . $rule['label'] . '“)' : '');
			if (!self::isLedgerAccount($rule['account'], $length)) {
				$errors[] = $label . ': ' . $length . '-stellige Kontonummer erwartet.';
			}
			if ($rule['taxKey'] !== '' && !preg_match('/^\d{1,4}$/', $rule['taxKey'])) {
				$errors[] = $label . ': Der BU-Schlüssel besteht aus höchstens vier Ziffern.';
			}
			if ($rule['articleType'] !== '' && !in_array($rule['articleType'], self::ARTICLE_TYPES, true)) {
				$errors[] = $label . ': unbekannte Artikelart.';
			}
			if ($rule['vatRate'] !== null && ($rule['vatRate'] < 0 || $rule['vatRate'] > 99)) {
				$errors[] = $label . ': Steuersatz zwischen 0 und 99 %.';
			}
		}
		return $errors;
	}

	/**
	 * V-01: Was für einen Export noch fehlt (Berater, Mandant, Kontenrahmen, Sammeldebitor). Leer = exportbereit.
	 * @return list<string>
	 */
	public static function problems(array $settings): array {
		$problems = self::validate($settings);
		if ($settings['consultantNumber'] === '') {
			$problems[] = 'Beraternummer fehlt.';
		}
		if ($settings['clientNumber'] === '') {
			$problems[] = 'Mandantennummer fehlt.';
		}
		if ($settings['chart'] === '') {
			$problems[] = 'Kontenrahmen (SKR03 oder SKR04) ist nicht gewählt.';
		}
		if ($settings['debtorMode'] === 'COLLECTIVE' && $settings['collectiveDebtor'] === '') {
			$problems[] = 'Für den Sammeldebitor ist keine Kontonummer eingetragen.';
		}
		if ($settings['rules'] === []) {
			$problems[] = 'Es ist noch keine Kontenzuordnung (Erlöskonten) hinterlegt.';
		}
		return array_values(array_unique($problems));
	}

	public static function isLedgerAccount(string $account, int $length): bool {
		return preg_match('/^\d+$/', $account) === 1 && strlen($account) === $length && (int)$account > 0;
	}

	/** Personenkonten haben eine Stelle mehr als Sachkonten; Debitoren 10000–69999 (bei Sachkontenlänge 4). @return array{int,int} */
	public static function debtorRange(int $length): array {
		$base = 10 ** $length;
		return [$base, 7 * $base - 1];
	}

	/** Wirtschaftsjahr (Beginn, Ende als Y-m-d) zu einem Datum. @return array{string,string} */
	public static function fiscalYear(string $date, string $start): array {
		[$month, $day] = array_map('intval', explode('-', $start) + [1 => 1]);
		$year = (int)substr($date, 0, 4);
		$begin = sprintf('%04d-%02d-%02d', $year, $month, $day);
		if ($date < $begin) {
			$begin = sprintf('%04d-%02d-%02d', $year - 1, $month, $day);
		}
		$end = (new \DateTimeImmutable($begin))->modify('+1 year -1 day')->format('Y-m-d');
		return [$begin, $end];
	}
}
