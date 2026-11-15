<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IDBConnection;

/**
 * 0.92.6 – Datei-Verweise reparieren (occ bestatter:repair-file-references). Für Installationen, die vor 0.92.6 mit
 * `bestatter:restore` aus einem Sicherungspaket aufgebaut wurden: Nextcloud hat den Dateien dabei neue IDs gegeben, die
 * Falldaten tragen noch die IDs der alten Installation. Ein Verweis wird über den daneben gespeicherten Pfad neu
 * zugeordnet, wenn dort eine Datei liegt und die gespeicherte ID nicht auf eine gleichnamige Datei zeigt (die Datei wäre
 * dann nur umbenannt oder verschoben worden – der Verweis stimmt). Verweise ohne Pfad, deren ID ins Leere zeigt, werden
 * gezählt, aber nicht geändert. Das Änderungsprotokoll bleibt unverändert.
 */
class FileReferenceRepairService {
	public const CONFIRMATION = 'BESTATTER-DATEIVERWEISE';
	private const EXAMPLES = 30;

	/** @var list<Folder>|null */
	private ?array $bases = null;
	/** @var array<string,File|false> */
	private array $byPath = [];
	/** @var array<int,File|false> */
	private array $byId = [];

	public function __construct(
		private IDBConnection $db,
		private CaseStorageService $storage,
		private AuditService $audit,
	) {
	}

	/**
	 * Prüflauf ($execute = false) oder Reparatur.
	 * @return array{executed:bool,tables:array<string,array{rows:int,references:int}>,references:int,rows:int,unresolved:int,examples:list<array<string,mixed>>}
	 */
	public function run(bool $execute): array {
		$result = ['executed' => $execute, 'tables' => [], 'references' => 0, 'rows' => 0, 'unresolved' => 0, 'examples' => []];
		$plan = [];
		foreach (BackupService::TABLES as $table) {
			if ($table === 'bestatter_audit_log') {
				continue;
			}
			foreach ($this->rows($table) as $row) {
				$changed = 0;
				$new = FileReferences::rewriteRow($row, $this->resolver($result, $table, (int)($row['id'] ?? 0)), $changed);
				if ($changed === 0) {
					continue;
				}
				$columns = array_keys(array_filter($new, static fn (mixed $value, string|int $column): bool => $value !== $row[$column], ARRAY_FILTER_USE_BOTH));
				$plan[] = [$table, (int)$row['id'], $columns, $new];
				$result['tables'][$table] ??= ['rows' => 0, 'references' => 0];
				$result['tables'][$table]['rows']++;
				$result['tables'][$table]['references'] += $changed;
				$result['rows']++;
				$result['references'] += $changed;
			}
		}
		if (!$execute || $plan === []) {
			return $result;
		}
		$this->db->beginTransaction();
		try {
			foreach ($plan as [$table, $id, $columns, $new]) {
				$query = $this->db->getQueryBuilder();
				$query->update($table);
				foreach ($columns as $column) {
					$query->set((string)$column, $query->createNamedParameter($new[$column]));
				}
				$query->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw new \RuntimeException('Die Datei-Verweise wurden nicht geändert (zurückgerollt): ' . $error->getMessage(), 0, $error);
		}
		$this->audit->logSystem('FILE_REFERENCES', 'REPAIRED', ['rows' => $result['rows'], 'references' => $result['references'], 'tables' => $result['tables'], 'unresolved' => $result['unresolved']]);
		return $result;
	}

	/** @return callable(int, ?string): ?int */
	private function resolver(array &$result, string $table, int $rowId): callable {
		return function (int $old, ?string $path) use (&$result, $table, $rowId): ?int {
			$node = $path !== null ? $this->fileAtPath($path) : null;
			if ($node === null) {
				if ($this->fileById($old) === null) {
					$result['unresolved']++;
				}
				return null;
			}
			$new = (int)$node->getId();
			if ($new === $old) {
				return null;
			}
			$current = $this->fileById($old);
			if ($current !== null && $current->getName() === basename(FileReferences::normalizePath((string)$path))) {
				return null;
			}
			if (count($result['examples']) < self::EXAMPLES) {
				$result['examples'][] = ['table' => $table, 'id' => $rowId, 'from' => $old, 'to' => $new, 'path' => FileReferences::normalizePath((string)$path)];
			}
			return $new;
		};
	}

	private function fileAtPath(string $path): ?File {
		$path = FileReferences::normalizePath($path);
		if ($path === '' || in_array('..', explode('/', $path), true)) {
			return null;
		}
		if (!array_key_exists($path, $this->byPath)) {
			$this->byPath[$path] = false;
			// Pfade sind relativ zum Basisordner der Ablage; ältere Einträge können „<konto>/files/…“ enthalten.
			$candidates = array_values(array_unique([$path, (string)preg_replace('#^[^/]+/files/#', '', $path)]));
			foreach ($this->bases() as $base) {
				foreach ($candidates as $candidate) {
					try {
						if ($candidate !== '' && $base->nodeExists($candidate)) {
							$node = $base->get($candidate);
							if ($node instanceof File) {
								$this->byPath[$path] = $node;
								break 2;
							}
						}
					} catch (\Throwable) {
					}
				}
			}
		}
		return $this->byPath[$path] ?: null;
	}

	private function fileById(int $id): ?File {
		if (!array_key_exists($id, $this->byId)) {
			$this->byId[$id] = false;
			foreach ($this->bases() as $base) {
				try {
					foreach ($base->getById($id) as $node) {
						if ($node instanceof File) {
							$this->byId[$id] = $node;
							break 2;
						}
					}
				} catch (\Throwable) {
				}
			}
		}
		return $this->byId[$id] ?: null;
	}

	/** @return list<Folder> */
	private function bases(): array {
		if ($this->bases === null) {
			$this->bases = [];
			$seen = [];
			foreach ($this->storage->allBases() as $base) {
				$folder = $base['folder'] ?? null;
				if ($folder instanceof Folder && !isset($seen[(int)$folder->getId()])) {
					$seen[(int)$folder->getId()] = true;
					$this->bases[] = $folder;
				}
			}
		}
		return $this->bases;
	}

	/** @return list<array<string,mixed>> */
	private function rows(string $table): array {
		try {
			$query = $this->db->getQueryBuilder();
			return $query->select('*')->from($table)->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		} catch (\Throwable) {
			return [];
		}
	}
}
