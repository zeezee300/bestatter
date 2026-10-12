<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * Gemeinsame CSV-Hilfen für Leistungskatalog, Warengruppen und Preislisten.
 *
 * Ziel ist ein verlustfreier Weg über Excel: Semikolon, UTF-8 mit BOM, deutsche Spaltenköpfe
 * und Klartextwerte. Beim Einlesen werden die Umformungen toleriert, die Excel beim Speichern
 * vornimmt (Windows-1252 statt UTF-8, Datum als TT.MM.JJJJ, „ja/nein“, Dezimalkomma).
 */
final class CsvTools {
	/** @param list<string> $headers @param list<list<mixed>> $rows */
	public static function write(array $headers, array $rows): string {
		$quote = static fn(mixed $value): string => '"' . str_replace('"', '""', (string)($value ?? '')) . '"';
		$lines = [implode(';', array_map($quote, $headers))];
		foreach ($rows as $row) $lines[] = implode(';', array_map($quote, $row));
		return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
	}

	/**
	 * @param callable(string): string $normalizeHeader
	 * @return list<array{line: int, values: array<string, string>}>
	 */
	public static function read(string $content, callable $normalizeHeader): array {
		$content = self::decode($content);
		$firstLine = strtok($content, "\r\n") ?: '';
		$delimiter = substr_count($firstLine, ';') >= max(substr_count($firstLine, ','), substr_count($firstLine, "\t")) ? ';' : (substr_count($firstLine, "\t") > substr_count($firstLine, ',') ? "\t" : ',');
		$stream = fopen('php://temp', 'w+');
		if ($stream === false) throw new \RuntimeException('CSV-Datei konnte nicht verarbeitet werden.');
		fwrite($stream, $content);
		rewind($stream);
		$headers = fgetcsv($stream, 0, $delimiter, '"', '');
		if (!is_array($headers) || count(array_filter($headers, static fn($header): bool => trim((string)$header) !== '')) === 0) {
			fclose($stream);
			throw new \InvalidArgumentException('Die CSV-Datei enthält keine Kopfzeile.');
		}
		$headers = array_map(static fn($header): string => $normalizeHeader(trim((string)$header)), $headers);
		$rows = [];
		$line = 1;
		while (($values = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
			$line++;
			if (count(array_filter($values, static fn($value): bool => trim((string)$value) !== '')) === 0) continue;
			$values = array_map(static fn($value): string => (string)$value, array_pad($values, count($headers), ''));
			$rows[] = ['line' => $line, 'values' => array_combine($headers, array_slice($values, 0, count($headers)))];
		}
		fclose($stream);
		return $rows;
	}

	/** Entfernt die BOM und wandelt von Excel als „CSV (Trennzeichen-getrennt)“ gespeicherte Dateien (Windows-1252) nach UTF-8. */
	public static function decode(string $content): string {
		$content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
		if (!mb_check_encoding($content, 'UTF-8')) $content = (string)mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
		return $content;
	}

	/** Deutsche und technische Schreibweisen: ja/nein, j/n, x, wahr/falsch, 1/0, true/false. */
	public static function flag(mixed $value, bool $default = false): bool {
		if (is_bool($value)) return $value;
		if ($value === null) return $default;
		$normalized = mb_strtolower(trim((string)$value));
		if ($normalized === '') return $default;
		if (in_array($normalized, ['1', 'ja', 'j', 'yes', 'y', 'x', 'true', 'wahr', 'on', 'aktiv'], true)) return true;
		if (in_array($normalized, ['0', 'nein', 'n', 'no', 'false', 'falsch', 'off', 'inaktiv', '-'], true)) return false;
		throw new \InvalidArgumentException('„' . trim((string)$value) . '“ ist kein Ja/Nein-Wert.');
	}

	/** Liefert JJJJ-MM-TT; akzeptiert zusätzlich TT.MM.JJJJ und TT.MM.JJ, wie Excel Datumswerte speichert. */
	public static function date(mixed $value, string $label): string {
		$raw = trim((string)($value ?? ''));
		if ($raw === '') return '';
		$formats = preg_match('/^\d{1,2}\.\d{1,2}\.\d{2}$/', $raw) ? ['!d.m.y', '!j.n.y'] : ['!Y-m-d', '!d.m.Y', '!j.n.Y', '!Y-m-d H:i:s', '!d.m.Y H:i', '!d.m.Y H:i:s'];
		foreach ($formats as $format) {
			$date = \DateTimeImmutable::createFromFormat($format, $raw);
			$errors = \DateTimeImmutable::getLastErrors();
			if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) return $date->format('Y-m-d');
		}
		throw new \InvalidArgumentException('„' . $label . '“ ist kein gültiges Datum (TT.MM.JJJJ oder JJJJ-MM-TT): ' . $raw);
	}

	/** Betrag in Cent; akzeptiert 1.234,56 · 1234,56 · 1234.56 · „€“. */
	public static function cents(mixed $value): int {
		$raw = trim(str_replace(['€', ' ', "\u{00A0}"], '', (string)($value ?? '')));
		if ($raw === '') return 0;
		if (str_contains($raw, ',')) $normalized = str_replace(['.', ','], ['', '.'], $raw);
		elseif (substr_count($raw, '.') > 1) $normalized = str_replace('.', '', $raw);
		else $normalized = $raw;
		if (!is_numeric($normalized)) throw new \InvalidArgumentException('„' . trim((string)$value) . '“ ist kein gültiger Betrag.');
		return (int)round((float)$normalized * 100);
	}

	public static function money(?int $cents): string {
		return $cents === null ? '' : number_format($cents / 100, 2, ',', '');
	}

	/** Vereinheitlicht Spaltenköpfe (Kleinschreibung, Umlaute, Leer- und Sonderzeichen). */
	public static function headerKey(string $header): string {
		$key = mb_strtolower(trim($header));
		return trim(strtr($key, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', ' ' => '_', '-' => '_', '.' => '', '/' => '_', '€' => '', '(' => '', ')' => '']), '_');
	}
}
