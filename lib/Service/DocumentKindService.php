<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * 0.80.0 – Dokumentarten (OP-058), gepflegt unter Customizing → Dokumentarten.
 *
 * - Mitgelieferte Arten (resources/document-kinds.json, Herkunft BUNDLED) entstehen beim ersten Aufruf mit dem
 *   Aktiv-Schalter aus der Datei; sie lassen sich bearbeiten und deaktivieren, aber nicht löschen.
 * - Eigene Arten (CUSTOM) dürfen gelöscht werden; bereits abgelegte Dokumente behalten ihren Schlüssel.
 * - Je Paperless-Dokumenttyp höchstens eine aktive Art; der Ablageordner ist ein Schlüssel des Ordnerplans,
 *   damit eine Umbenennung der Fall-Unterordner nichts verschiebt.
 * - Pflege nur durch Bestatter-Administratoren; Lesen für alle Mitglieder (Belegeingang, Upload, Abläufe).
 */
class DocumentKindService {
	private bool $seeded = false;
	/** @var array<string,array>|null */
	private ?array $cache = null;

	public function __construct(
		private IDBConnection $db,
		private AuditService $audit,
		private TeamService $team,
		private InstallationConfigService $installationConfig,
	) {}

	public function ensureSeedData(): void {
		if ($this->seeded) return;
		$this->seeded = true;
		$bundle = json_decode((string)file_get_contents(DocumentKindRules::BUNDLE_FILE), true, 512, JSON_THROW_ON_ERROR);
		$existing = $this->rowsByKey();
		$sort = $existing === [] ? 0 : max(array_map(static fn(array $row): int => (int)$row['sort_order'], $existing));
		$folderKeys = $this->folderKeys();
		foreach ((array)($bundle['kinds'] ?? []) as $kind) {
			if (isset($existing[strtoupper((string)$kind['key'])])) continue;
			// Ein umbenannter oder entfernter Standardordner darf das Anlegen nicht verhindern: dann Standardablage.
			if (!in_array(strtoupper((string)($kind['folderKey'] ?? '')), $folderKeys, true)) $kind['folderKey'] = $this->uploadFolderKey();
			$sort += 10;
			$this->insert(DocumentKindRules::normalizeDefinition($kind, $folderKeys), 'BUNDLED', $sort);
		}
		$this->cache = null;
	}

	/** @return list<array> */
	public function definitions(bool $activeOnly = false): array {
		$this->ensureSeedData();
		$all = array_values($this->map());
		return $activeOnly ? array_values(array_filter($all, static fn(array $item): bool => $item['active'])) : $all;
	}

	public function definition(string $key): ?array {
		$this->ensureSeedData();
		return $this->map()[strtoupper(trim($key))] ?? null;
	}

	/** Aktive Art oder Fehler (Belegeingang, Upload). */
	public function activeDefinition(string $key): array {
		$definition = $this->definition($key);
		if ($definition === null || !$definition['active']) throw new \InvalidArgumentException('Bitte eine aktive Dokumentart wählen.');
		return $definition;
	}

	/** Für Customizing: Arten, Auswahllisten und Ordnerplan. */
	public function catalog(): array {
		return [
			'kinds' => $this->definitions(),
			'effects' => DocumentKindRules::EFFECTS,
			'categories' => CaseFileService::DOCUMENT_TYPES,
			'classifications' => IncomingInvoiceService::CLASSIFICATIONS,
			'folders' => $this->installationConfig->folderPlan(),
			'canEdit' => $this->team->isBestatterAdmin(),
		];
	}

	public function create(array $input): array {
		$this->team->requireBestatterAdmin();
		$this->ensureSeedData();
		$definition = DocumentKindRules::normalizeDefinition($input, $this->folderKeys());
		if (isset($this->map()[$definition['key']])) throw new \InvalidArgumentException('Eine Dokumentart mit dem Schlüssel „' . $definition['key'] . '“ gibt es bereits.');
		foreach ($this->map() as $existing) if (mb_strtolower($existing['name']) === mb_strtolower($definition['name'])) throw new \InvalidArgumentException('Eine Dokumentart mit diesem Namen gibt es bereits.');
		DocumentKindRules::assertUniquePaperlessTypes([...array_values($this->map()), $definition]);
		$sort = $this->map() === [] ? 10 : max(array_column($this->map(), 'sortOrder')) + 10;
		$this->insert($definition, 'CUSTOM', $sort);
		$this->cache = null;
		$created = $this->definition($definition['key']);
		$this->audit->logSystem('DOCUMENT_KIND', 'CREATED', $created);
		return $created ?? $definition;
	}

	public function update(string $key, array $input): array {
		$this->team->requireBestatterAdmin();
		$existing = $this->definition($key);
		if ($existing === null) throw new \InvalidArgumentException('Dokumentart nicht gefunden.');
		$definition = DocumentKindRules::normalizeDefinition($input + ['key' => $existing['key']], $this->folderKeys(), $existing);
		foreach ($this->map() as $other) if ($other['key'] !== $existing['key'] && mb_strtolower($other['name']) === mb_strtolower($definition['name'])) throw new \InvalidArgumentException('Eine Dokumentart mit diesem Namen gibt es bereits.');
		$others = array_values(array_filter($this->map(), static fn(array $item): bool => $item['key'] !== $existing['key']));
		DocumentKindRules::assertUniquePaperlessTypes([...$others, $definition]);
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_document_kinds')
			->set('name', $query->createNamedParameter($definition['name']))
			->set('description', $query->createNamedParameter($definition['description'] !== '' ? $definition['description'] : null))
			->set('hint', $query->createNamedParameter($definition['hint'] !== '' ? $definition['hint'] : null))
			->set('paperless_type_id', $query->createNamedParameter($definition['paperlessTypeId'], $definition['paperlessTypeId'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
			->set('folder_key', $query->createNamedParameter($definition['folderKey']))
			->set('category', $query->createNamedParameter($definition['category']))
			->set('effect', $query->createNamedParameter($definition['effect']))
			->set('classification', $query->createNamedParameter($definition['classification'] !== '' ? $definition['classification'] : null))
			->set('count_label', $query->createNamedParameter($definition['countLabel'] !== '' ? $definition['countLabel'] : null))
			->set('active', $query->createNamedParameter($definition['active'] ? 1 : 0, IQueryBuilder::PARAM_INT))
			->set('updated_at', $query->createNamedParameter(date('c')))
			->where($query->expr()->eq('kind_key', $query->createNamedParameter($existing['key'])))
			->executeStatement();
		$this->cache = null;
		$updated = $this->definition($existing['key']) ?? $definition;
		$this->audit->logSystem('DOCUMENT_KIND', 'UPDATED', ['before' => $existing, 'after' => $updated]);
		return $updated;
	}

	public function delete(string $key): void {
		$this->team->requireBestatterAdmin();
		$existing = $this->definition($key);
		if ($existing === null) throw new \InvalidArgumentException('Dokumentart nicht gefunden.');
		if ($existing['origin'] === 'BUNDLED') throw new \InvalidArgumentException('Mitgelieferte Dokumentarten lassen sich nicht löschen, nur deaktivieren.');
		$query = $this->db->getQueryBuilder();
		$query->delete('bestatter_document_kinds')->where($query->expr()->eq('kind_key', $query->createNamedParameter($existing['key'])))->executeStatement();
		$this->cache = null;
		$this->audit->logSystem('DOCUMENT_KIND', 'DELETED', $existing);
	}

	/** Zielordner (aktueller Name) einer Art; fällt auf die Standardablage zurück, falls der Ordner fehlt. */
	public function folderName(array $definition): string {
		return $this->installationConfig->folderNameForKey((string)$definition['folderKey']) ?? $this->installationConfig->defaultUploadFolder();
	}

	// ------------------------------------------------------------------ Austausch (Einrichtungspaket)

	public function exportExchange(): string {
		$kinds = array_map(static fn(array $item): array => array_intersect_key($item, array_flip(['key', 'name', 'description', 'hint', 'paperlessTypeId', 'folderKey', 'category', 'effect', 'classification', 'countLabel', 'active'])), $this->definitions());
		return json_encode(['format' => DocumentKindRules::EXCHANGE_FORMAT, 'formatVersion' => DocumentKindRules::EXCHANGE_VERSION, 'kinds' => $kinds], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	/** @return array{kinds:list<array>,new:int,existing:int} */
	public function parseExchange(string $content): array {
		$this->ensureSeedData();
		$data = json_decode($content, true);
		if (!is_array($data) || ($data['format'] ?? '') !== DocumentKindRules::EXCHANGE_FORMAT) throw new \InvalidArgumentException('Keine Dokumentarten im erwarteten Format.');
		$kinds = []; $new = 0; $existing = 0;
		$folderKeys = $this->folderKeys();
		foreach ((array)($data['kinds'] ?? []) as $raw) {
			if (!is_array($raw)) continue;
			$current = $this->map()[strtoupper((string)($raw['key'] ?? ''))] ?? null;
			if (!in_array(strtoupper((string)($raw['folderKey'] ?? '')), $folderKeys, true)) $raw['folderKey'] = $current['folderKey'] ?? $this->uploadFolderKey();
			$kinds[] = DocumentKindRules::normalizeDefinition($raw, $folderKeys, $current);
			$current === null ? $new++ : $existing++;
		}
		return ['kinds' => $kinds, 'new' => $new, 'existing' => $existing];
	}

	public function importExchange(string $content): array {
		$this->team->requireBestatterAdmin();
		$parsed = $this->parseExchange($content);
		$created = 0; $updated = 0;
		// Erst alle betroffenen Paperless-Zuordnungen lösen, damit ein Tausch zweier Typen nicht an der Eindeutigkeit scheitert.
		$incomingTypes = array_filter(array_map(static fn(array $kind): int => (int)($kind['paperlessTypeId'] ?? 0), $parsed['kinds']));
		if ($incomingTypes !== []) {
			$query = $this->db->getQueryBuilder();
			$query->update('bestatter_document_kinds')->set('paperless_type_id', $query->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->where($query->expr()->in('paperless_type_id', $query->createNamedParameter(array_values($incomingTypes), IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
			$this->cache = null;
		}
		foreach ($parsed['kinds'] as $definition) {
			if (isset($this->map()[$definition['key']])) { $this->update($definition['key'], $definition); $updated++; }
			else { $this->create($definition); $created++; }
		}
		return ['created' => $created, 'updated' => $updated];
	}

	// ------------------------------------------------------------------ intern

	/** @return list<string> */
	private function folderKeys(): array {
		return array_values(array_map(static fn(array $item): string => (string)$item['key'], $this->installationConfig->folderPlan()));
	}

	private function uploadFolderKey(): string {
		return (string)($this->installationConfig->functionFolders()['UPLOAD'] ?? ($this->folderKeys()[0] ?? 'STAMMDATEN'));
	}

	/** @return array<string,array> */
	private function map(): array {
		if ($this->cache !== null) return $this->cache;
		$rows = array_values($this->rowsByKey());
		usort($rows, static fn(array $a, array $b): int => [(int)$a['sort_order'], (int)$a['id']] <=> [(int)$b['sort_order'], (int)$b['id']]);
		$map = [];
		foreach ($rows as $row) {
			$map[(string)$row['kind_key']] = [
				'key' => (string)$row['kind_key'], 'name' => (string)$row['name'], 'description' => (string)($row['description'] ?? ''), 'hint' => (string)($row['hint'] ?? ''),
				'paperlessTypeId' => $row['paperless_type_id'] !== null ? (int)$row['paperless_type_id'] : null, 'folderKey' => (string)$row['folder_key'],
				'folderName' => $this->installationConfig->folderNameForKey((string)$row['folder_key']) ?? '', 'category' => (string)$row['category'],
				'effect' => (string)$row['effect'], 'effectLabel' => DocumentKindRules::EFFECTS[(string)$row['effect']] ?? (string)$row['effect'],
				'classification' => (string)($row['classification'] ?? ''), 'countLabel' => (string)($row['count_label'] ?? ''),
				'sortOrder' => (int)$row['sort_order'], 'active' => (int)$row['active'] === 1, 'origin' => (string)$row['origin'],
			];
		}
		return $this->cache = $map;
	}

	/** @return array<string,array> */
	private function rowsByKey(): array {
		$query = $this->db->getQueryBuilder();
		$rows = [];
		foreach ($query->select('*')->from('bestatter_document_kinds')->executeQuery()->fetchAllAssociative() as $row) $rows[(string)$row['kind_key']] = $row;
		return $rows;
	}

	private function insert(array $definition, string $origin, int $sortOrder): void {
		$now = date('c');
		$query = $this->db->getQueryBuilder();
		$query->insert('bestatter_document_kinds')->values([
			'kind_key' => $query->createNamedParameter($definition['key']),
			'name' => $query->createNamedParameter($definition['name']),
			'description' => $query->createNamedParameter($definition['description'] !== '' ? $definition['description'] : null),
			'hint' => $query->createNamedParameter($definition['hint'] !== '' ? $definition['hint'] : null),
			'paperless_type_id' => $query->createNamedParameter($definition['paperlessTypeId'], $definition['paperlessTypeId'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
			'folder_key' => $query->createNamedParameter($definition['folderKey']),
			'category' => $query->createNamedParameter($definition['category']),
			'effect' => $query->createNamedParameter($definition['effect']),
			'classification' => $query->createNamedParameter($definition['classification'] !== '' ? $definition['classification'] : null),
			'count_label' => $query->createNamedParameter($definition['countLabel'] !== '' ? $definition['countLabel'] : null),
			'sort_order' => $query->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT),
			'active' => $query->createNamedParameter($definition['active'] ? 1 : 0, IQueryBuilder::PARAM_INT),
			'origin' => $query->createNamedParameter($origin),
			'created_at' => $query->createNamedParameter($now),
			'updated_at' => $query->createNamedParameter($now),
		])->executeStatement();
	}
}
