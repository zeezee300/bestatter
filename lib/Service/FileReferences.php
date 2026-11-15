<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * 0.92.6 – Datei-Verweise in Datenbankzeilen umschreiben. Die App speichert die Nextcloud-Datei-ID an vielen Stellen
 * (Dokumentdatensätze, Abschlussnachweis, Eingangsrechnungen, Paperless, Anhänge). Nach einer Wiederherstellung vergibt
 * Nextcloud den Dateien neue IDs. Gemeinsam genutzt von Einzelfall-Restore, vollständigem Restore und der Reparatur
 * bereits wiederhergestellter Installationen (FileReferenceRepairService).
 *
 * Verweise: Spalten `file_id` und `…_file_id` (Pfad daneben in `file_path`) sowie in JSON-Spalten die Schlüssel `fileId`
 * und `…FileId`. Den Pfad zu einem Verweis liefern `path`, `relativePath`, `filePath` neben `fileId` bzw. `<x>Path` und
 * `<x>FilePath` neben `<x>FileId`. Was eine Datei-ID ersetzt, entscheidet der Aufrufer ($resolve).
 */
final class FileReferences {
	private const PATH_KEYS = ['path', 'relativePath', 'filePath'];

	/**
	 * @param callable(int, ?string): ?int $resolve alte ID und Pfad (falls bekannt) → neue ID oder null (unverändert)
	 * @param int $changed Anzahl umgeschriebener Verweise (wird erhöht)
	 * @return array<string,mixed> die Zeile, ggf. mit neuen IDs (JSON neu kodiert)
	 */
	public static function rewriteRow(array $row, callable $resolve, int &$changed): array {
		foreach ($row as $column => $value) {
			$column = (string)$column;
			if ($column === 'file_id' || str_ends_with($column, '_file_id')) {
				if (!is_numeric($value) || (int)$value <= 0) {
					continue;
				}
				$path = $column === 'file_id' && is_string($row['file_path'] ?? null) && trim((string)$row['file_path']) !== '' ? (string)$row['file_path'] : null;
				$new = $resolve((int)$value, $path);
				if ($new !== null && $new !== (int)$value) {
					$row[$column] = $new;
					$changed++;
				}
				continue;
			}
			if (!is_string($value) || $value === '' || ($value[0] !== '{' && $value[0] !== '[')) {
				continue;
			}
			$decoded = json_decode($value, true);
			if (!is_array($decoded)) {
				continue;
			}
			$before = $changed;
			$decoded = self::rewriteJson($decoded, $resolve, $changed);
			if ($changed > $before) {
				$row[$column] = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			}
		}
		return $row;
	}

	/** @param callable(int, ?string): ?int $resolve */
	public static function rewriteJson(array $data, callable $resolve, int &$changed): array {
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$data[$key] = self::rewriteJson($value, $resolve, $changed);
				continue;
			}
			$name = (string)$key;
			if (($name !== 'fileId' && !str_ends_with($name, 'FileId')) || !is_numeric($value) || (int)$value <= 0) {
				continue;
			}
			$new = $resolve((int)$value, self::siblingPath($data, $name));
			if ($new !== null && $new !== (int)$value) {
				$data[$key] = $new;
				$changed++;
			}
		}
		return $data;
	}

	/** Pfad neben einem Verweis: path/relativePath/filePath neben fileId, <x>Path/<x>FilePath neben <x>FileId. */
	private static function siblingPath(array $data, string $key): ?string {
		$prefix = $key === 'fileId' ? '' : substr($key, 0, -strlen('FileId'));
		$candidates = $prefix === '' ? self::PATH_KEYS : [$prefix . 'Path', $prefix . 'FilePath'];
		foreach ($candidates as $candidate) {
			if (is_string($data[$candidate] ?? null) && trim((string)$data[$candidate]) !== '') {
				return (string)$data[$candidate];
			}
		}
		return null;
	}

	/** Pfad vereinheitlicht (ohne führenden und abschließenden Schrägstrich). */
	public static function normalizePath(string $path): string {
		return trim(str_replace('\\', '/', $path), '/');
	}
}
