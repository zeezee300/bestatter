<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * 0.90.0 – DATEV-Format „Buchungsstapel“ (EXTF 700, Kategorie 21) schreiben und prüfen.
 *
 * Feldliste, Reihenfolge und Längen stehen in resources/datev/buchungsstapel-700-13.json, nicht im Code. Die Klasse ist ohne
 * Nextcloud lauffähig (tests/datev-export-0900-fixture.php). Ausgabe: Windows-1252, CRLF, Semikolon, Text in Anführungszeichen,
 * Zahlen ohne; Beträge positiv mit Dezimalkomma und zwei Nachkommastellen, die Richtung steckt im Soll/Haben-Kennzeichen.
 */
final class DatevFormat {
	public const DEFINITION = __DIR__ . '/../../resources/datev/buchungsstapel-700-13.json';
	/** Zulässige Zeichen in Belegfeld 1 (Schlüsselfeld der Offene-Posten-Verwaltung). */
	public const DOCUMENT_NUMBER_PATTERN = '/^[A-Za-z0-9$&%*+\-\/]{1,36}$/';

	private array $definition;
	/** @var array<string, int> Spaltenname → Index (0-basiert) */
	private array $columnIndex;
	/** @var list<string> Hinweise zu ersetzten Zeichen (V-09) */
	private array $replacements = [];

	public function __construct(?string $definitionFile = null) {
		$decoded = json_decode((string)file_get_contents($definitionFile ?? self::DEFINITION), true);
		if (!is_array($decoded) || count((array)($decoded['spalten'] ?? [])) < 20 || count((array)($decoded['kopf'] ?? [])) < 22) {
			throw new \RuntimeException('Die DATEV-Formatbeschreibung ist unvollständig.');
		}
		$this->definition = $decoded;
		$this->columnIndex = array_flip(array_values($decoded['spalten']));
	}

	public function formatVersion(): int {
		return (int)$this->definition['formatversion'];
	}

	public function maxLines(): int {
		return max(1, (int)($this->definition['maxZeilen'] ?? 99999));
	}

	public function limit(string $field): int {
		return (int)($this->definition['grenzen'][$field] ?? 0);
	}

	/** @return list<string> */
	public function columns(): array {
		return array_values($this->definition['spalten']);
	}

	/**
	 * Datei(en) erzeugen. Mehr als maxZeilen Buchungen werden auf mehrere Stapel verteilt.
	 *
	 * @param array{created:\DateTimeInterface, origin:string, exportedBy:string, consultant:string, client:string, fiscalYearStart:string,
	 *              accountLength:int, from:string, to:string, label:string, lock:bool, chart:string} $meta Datumswerte als Y-m-d
	 * @param list<array> $lines Buchungszeilen (siehe DatevPostingBuilder)
	 * @return array{files: list<array{name:string, bytes:string, lines:int}>, warnings: list<string>}
	 */
	public function render(array $meta, array $lines, string $baseName): array {
		$this->replacements = [];
		$chunks = $lines === [] ? [[]] : array_chunk($lines, $this->maxLines());
		$files = [];
		foreach ($chunks as $index => $chunk) {
			$rows = [$this->headerRow($meta, count($chunks) > 1 ? $index + 1 : 0), $this->columnRow()];
			foreach ($chunk as $line) {
				$rows[] = $this->lineRow($line);
			}
			$name = count($chunks) > 1 ? sprintf('%s_Teil%02d.csv', $baseName, $index + 1) : $baseName . '.csv';
			$files[] = ['name' => $name, 'bytes' => implode("\r\n", $rows) . "\r\n", 'lines' => count($chunk)];
		}
		return ['files' => $files, 'warnings' => array_values(array_unique($this->replacements))];
	}

	private function headerRow(array $meta, int $part): string {
		$label = $meta['label'] . ($part > 0 ? ' T' . $part : '');
		$values = [
			$this->text((string)$this->definition['kennzeichen']), (string)(int)$this->definition['versionsnummer'], (string)(int)$this->definition['datenkategorie'],
			$this->text((string)$this->definition['formatname']), (string)$this->formatVersion(), $meta['created']->format('YmdHis') . substr($meta['created']->format('u'), 0, 3),
			'', $this->text($meta['origin'], 'Herkunft'), $this->text($meta['exportedBy'], 'Exportiert von'), $this->text(''),
			$meta['consultant'], $meta['client'], str_replace('-', '', $meta['fiscalYearStart']), (string)$meta['accountLength'],
			str_replace('-', '', $meta['from']), str_replace('-', '', $meta['to']), $this->text($label, 'Bezeichnung'), $this->text(''),
			'1', '0', $meta['lock'] ? '1' : '0', $this->text('EUR'), '', '', '', '', $this->text(match ($meta['chart']) {
				'SKR03' => '03', 'SKR04' => '04', default => ''
			}), '', '', '', '',
		];
		return implode(';', array_slice(array_pad($values, count($this->definition['kopf']), ''), 0, count($this->definition['kopf'])));
	}

	private function columnRow(): string {
		return implode(';', array_map(fn (string $name): string => $this->encode($name), $this->columns()));
	}

	/** Eine Buchungszeile mit allen Spalten der Formatbeschreibung; nicht genutzte Spalten bleiben leer. */
	public function lineRow(array $line): string {
		$fields = array_fill(0, count($this->columnIndex), '');
		$set = function (string $column, string $value) use (&$fields): void {
			if (!isset($this->columnIndex[$column])) {
				throw new \RuntimeException('Spalte „' . $column . '“ fehlt in der DATEV-Formatbeschreibung.');
			}
			$fields[$this->columnIndex[$column]] = $value;
		};
		$set('Umsatz (ohne Soll/Haben-Kz)', self::amount((int)$line['amountCents']));
		$set('Soll/Haben-Kennzeichen', $this->text((string)$line['side']));
		$set('WKZ Umsatz', $this->text('EUR'));
		$set('Konto', (string)$line['account']);
		$set('Gegenkonto (ohne BU-Schlüssel)', (string)$line['contraAccount']);
		$set('BU-Schlüssel', $line['taxKey'] !== '' ? $this->text((string)$line['taxKey']) : '');
		$set('Belegdatum', date('dm', (int)strtotime((string)$line['date'])));
		$set('Belegfeld 1', $this->text((string)$line['documentNo'], 'Belegfeld 1'));
		$set('Belegfeld 2', ($line['documentNo2'] ?? '') !== '' ? $this->text((string)$line['documentNo2'], 'Belegfeld 2') : '');
		$set('Buchungstext', $this->text((string)$line['text'], 'Buchungstext'));
		$set('KOST1 - Kostenstelle', ($line['kost1'] ?? '') !== '' ? $this->text((string)$line['kost1'], 'KOST1 - Kostenstelle') : '');
		$set('KOST2 - Kostenstelle', ($line['kost2'] ?? '') !== '' ? $this->text((string)$line['kost2'], 'KOST2 - Kostenstelle') : '');
		$set('Leistungsdatum', ($line['serviceDate'] ?? '') !== '' ? date('dmY', (int)strtotime((string)$line['serviceDate'])) : '');
		$set('Fälligkeit', ($line['dueDate'] ?? '') !== '' ? date('dmY', (int)strtotime((string)$line['dueDate'])) : '');
		return implode(';', $fields);
	}

	public static function amount(int $cents): string {
		return number_format(abs($cents) / 100, 2, ',', '');
	}

	/** Textfeld: gekürzt auf die Feldlänge, Anführungszeichen ersetzt, in Windows-1252 umgewandelt und in "…" gesetzt. */
	private function text(string $value, string $field = ''): string {
		$value = str_replace(['"', "\r", "\n", "\t"], ["'", ' ', ' ', ' '], $value);
		$limit = $field !== '' ? $this->limit($field) : 0;
		if ($limit > 0 && mb_strlen($value) > $limit) {
			$value = mb_substr($value, 0, $limit);
		}
		return '"' . $this->encode($value) . '"';
	}

	/** UTF-8 → Windows-1252; nicht darstellbare Zeichen werden ersetzt und als Hinweis gemeldet (V-09). */
	private function encode(string $value): string {
		$converted = self::toWindows1252($value, $replaced);
		foreach ($replaced as $char) {
			$this->replacements[] = 'Zeichen „' . $char . '“ ist in DATEV (Windows-1252) nicht darstellbar und wurde ersetzt.';
		}
		return $converted;
	}

	/** @param list<string>|null $replaced */
	public static function toWindows1252(string $value, ?array &$replaced = null): string {
		$replaced = [];
		$result = '';
		foreach (mb_str_split($value) as $char) {
			$converted = @iconv('UTF-8', 'Windows-1252', $char);
			if ($converted === false || $converted === '') {
				$fallback = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $char);
				$converted = $fallback !== false && $fallback !== '' ? $fallback : '?';
				$replaced[] = $char;
			}
			$result .= $converted;
		}
		return $result;
	}

	/**
	 * NFA-12: Eingebauter Validator. Liest die erzeugte Datei zurück und prüft sie gegen die Formatregeln, bevor sie bereitgestellt wird.
	 * @return list<string> Fehler (leer = in Ordnung)
	 */
	public function validate(string $bytes): array {
		$errors = [];
		if (!str_ends_with($bytes, "\r\n")) {
			$errors[] = 'Datei endet nicht mit CRLF.';
		}
		$utf8 = (string)mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
		$rows = explode("\r\n", rtrim($utf8, "\r\n"));
		if (count($rows) < 2) {
			return ['Kopfzeile oder Spaltenüberschriften fehlen.'];
		}
		$parse = static fn (string $row): array => str_getcsv($row, ';', '"', '');
		$header = $parse($rows[0]);
		if (count($header) !== count($this->definition['kopf'])) {
			$errors[] = 'Kopfzeile hat ' . count($header) . ' statt ' . count($this->definition['kopf']) . ' Felder.';
		}
		if (($header[0] ?? '') !== $this->definition['kennzeichen'] || (int)($header[1] ?? 0) !== (int)$this->definition['versionsnummer']
			|| (int)($header[2] ?? 0) !== (int)$this->definition['datenkategorie'] || ($header[3] ?? '') !== $this->definition['formatname'] || (int)($header[4] ?? 0) !== $this->formatVersion()) {
			$errors[] = 'Kennzeichen, Versionsnummer, Kategorie, Formatname oder Formatversion im Kopf stimmen nicht.';
		}
		if (!preg_match('/^\d{17}$/', (string)($header[5] ?? ''))) {
			$errors[] = 'Erzeugungszeitpunkt im Kopf hat nicht das Format JJJJMMTTHHMMSSmmm.';
		}
		foreach ([10 => 'Beraternummer', 11 => 'Mandantennummer', 12 => 'WJ-Beginn', 13 => 'Sachkontenlänge', 14 => 'Datum vom', 15 => 'Datum bis'] as $index => $label) {
			if (!preg_match('/^\d+$/', (string)($header[$index] ?? ''))) {
				$errors[] = $label . ' im Kopf fehlt oder ist keine Zahl.';
			}
		}
		$from = (string)($header[14] ?? '');
		$to = (string)($header[15] ?? '');
		$fiscal = (string)($header[12] ?? '');
		if (preg_match('/^\d{8}$/', $from) && preg_match('/^\d{8}$/', $to) && preg_match('/^\d{8}$/', $fiscal)) {
			$fiscalEnd = (new \DateTimeImmutable($fiscal))->modify('+1 year -1 day')->format('Ymd');
			if ($from > $to || $from < $fiscal || $to > $fiscalEnd) {
				$errors[] = 'Zeitraum im Kopf liegt nicht innerhalb eines Wirtschaftsjahres.';
			}
		}
		if ($parse($rows[1]) !== $this->columns()) {
			$errors[] = 'Spaltenüberschriften entsprechen nicht der Formatbeschreibung.';
		}
		$accountLength = (int)($header[13] ?? 0);
		$column = $this->columnIndex;
		for ($i = 2; $i < count($rows); $i++) {
			$fields = $parse($rows[$i]);
			$n = $i + 1;
			if (count($fields) !== count($column)) {
				$errors[] = 'Zeile ' . $n . ': ' . count($fields) . ' statt ' . count($column) . ' Spalten.';
				continue;
			}
			$get = static fn (string $name): string => (string)$fields[$column[$name]];
			if (!preg_match('/^\d+,\d{2}$/', $get('Umsatz (ohne Soll/Haben-Kz)')) || $get('Umsatz (ohne Soll/Haben-Kz)') === '0,00') {
				$errors[] = 'Zeile ' . $n . ': Umsatz muss positiv mit zwei Nachkommastellen und Dezimalkomma sein.';
			}
			if (!in_array($get('Soll/Haben-Kennzeichen'), ['S', 'H'], true)) {
				$errors[] = 'Zeile ' . $n . ': Soll/Haben-Kennzeichen muss S oder H sein.';
			}
			foreach (['Konto' => $accountLength + 1, 'Gegenkonto (ohne BU-Schlüssel)' => $accountLength] as $name => $length) {
				if (!preg_match('/^\d{1,' . max(1, $length) . '}$/', $get($name))) {
					$errors[] = 'Zeile ' . $n . ': ' . $name . ' ist keine gültige Kontonummer.';
				}
			}
			if ($get('BU-Schlüssel') !== '' && !preg_match('/^\d{1,4}$/', $get('BU-Schlüssel'))) {
				$errors[] = 'Zeile ' . $n . ': BU-Schlüssel ungültig.';
			}
			$date = $get('Belegdatum');
			if (!preg_match('/^(\d{2})(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[1], 2000)) {
				$errors[] = 'Zeile ' . $n . ': Belegdatum muss TTMM sein.';
			} elseif ($from !== '' && $to !== '') {
				$full = (substr($from, 4, 4) <= $m[2] . $m[1] ? substr($from, 0, 4) : substr($to, 0, 4)) . $m[2] . $m[1];
				if ($full < $from || $full > $to) {
					$errors[] = 'Zeile ' . $n . ': Belegdatum liegt außerhalb des Zeitraums im Kopf.';
				}
			}
			if (!preg_match(self::DOCUMENT_NUMBER_PATTERN, $get('Belegfeld 1'))) {
				$errors[] = 'Zeile ' . $n . ': Belegfeld 1 fehlt oder enthält unzulässige Zeichen.';
			}
			foreach (['Belegfeld 2', 'Buchungstext', 'KOST1 - Kostenstelle', 'KOST2 - Kostenstelle'] as $name) {
				if ($this->limit($name) > 0 && mb_strlen($get($name)) > $this->limit($name)) {
					$errors[] = 'Zeile ' . $n . ': ' . $name . ' ist länger als ' . $this->limit($name) . ' Zeichen.';
				}
			}
			foreach (['Leistungsdatum', 'Fälligkeit'] as $name) {
				if ($get($name) !== '' && !preg_match('/^\d{8}$/', $get($name))) {
					$errors[] = 'Zeile ' . $n . ': ' . $name . ' muss TTMMJJJJ sein.';
				}
			}
		}
		return $errors;
	}
}
