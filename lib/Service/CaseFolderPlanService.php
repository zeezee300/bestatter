<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\Files\Folder;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * 0.72.0 – Ordnerplan der Fallakte ändern: Vorschau und sichere Übernahme.
 *
 * Umbenennen eines Unterordners (gleiches Kennzeichen, neuer Name) benennt den Ordner in allen vorhandenen
 * Fallakten um (zentrale Ablage und persönliche Altbestände; Datei-IDs bleiben erhalten) und stellt die
 * gespeicherten Pfade in Dokumentregister, Rechnungsnachweisen, Ablaufaktionen, Abmelde-Postausgang,
 * Eingangsrechnungen und Dokumentvorlagen um. Entfernen ist nur möglich, wenn der Ordner weder Funktionsordner
 * noch Ziel einer Vorlage ist und in keinem Fall Dateien enthält.
 */
class CaseFolderPlanService {
	public const CONFIRMATION = 'BESTATTER-ORDNER-UMBENENNEN';
	private const LOCK_KEY = 'folder_plan_lock';
	private const LOCK_SECONDS = 900;
	/** @var list<array{table: string, id: string, column: string, kind: string}> */
	private const REFERENCES = [
		['table' => 'bestatter_records', 'id' => 'id', 'column' => 'payload', 'kind' => 'json'],
		['table' => 'bestatter_invoices', 'id' => 'id', 'column' => 'closure_manifest', 'kind' => 'json'],
		['table' => 'bestatter_action_runs', 'id' => 'id', 'column' => 'result_json', 'kind' => 'json'],
		['table' => 'bestatter_dereg_mail_outbox', 'id' => 'id', 'column' => 'attachments_json', 'kind' => 'json'],
		['table' => 'bestatter_incoming_invoices', 'id' => 'id', 'column' => 'file_path', 'kind' => 'path'],
		['table' => 'bestatter_document_templates', 'id' => 'id', 'column' => 'output_subfolder', 'kind' => 'name'],
	];

	public function __construct(
		private InstallationConfigService $installation,
		private CaseStorageService $storage,
		private IDBConnection $db,
		private IConfig $config,
		private AuditService $audit,
		private LoggerInterface $logger,
	) {}

	/** Aktueller Plan mit Verwendung je Ordner (für die Administration). */
	public function current(): array {
		$plan = $this->installation->folderPlan();
		$functions = $this->installation->functionFolders();
		$templates = $this->templateUsage();
		return [
			'plan' => array_map(fn(array $item): array => $item + [
				'functions' => array_values(array_keys(array_filter($functions, static fn(string $key): bool => $key === $item['key']))),
				'templates' => $templates[$item['name']] ?? [],
				'standard' => array_key_exists($item['key'], InstallationConfigService::STANDARD_FOLDERS),
			], $plan),
			'functions' => $functions,
			'functionLabels' => array_map(static fn(array $definition): string => $definition[1], InstallationConfigService::FUNCTIONS),
			'locked' => $this->lockedSince() !== null,
			'command' => 'occ bestatter:case-folder-rename',
		];
	}

	/**
	 * Vorschau einer Planänderung – verändert nichts.
	 * @param list<array{key?: string, name: string}> $input
	 * @param array<string, string> $functionInput Funktion => Kennzeichen
	 */
	public function preview(array $input, array $functionInput = [], bool $withCaseScan = true): array {
		[$plan, $functions] = $this->normalize($input, $functionInput);
		$old = $this->installation->folderPlan();
		$oldByKey = array_column($old, 'name', 'key');
		$newByKey = array_column($plan, 'name', 'key');
		$renames = [];
		foreach ($newByKey as $key => $name) if (isset($oldByKey[$key]) && $oldByKey[$key] !== $name) $renames[$key] = ['key' => $key, 'from' => $oldByKey[$key], 'to' => $name];
		$added = array_values(array_map(static fn(string $key): array => ['key' => $key, 'name' => $newByKey[$key]], array_keys(array_diff_key($newByKey, $oldByKey))));
		$removed = array_values(array_map(static fn(string $key): array => ['key' => $key, 'name' => $oldByKey[$key]], array_keys(array_diff_key($oldByKey, $newByKey))));
		$orderChanged = array_values(array_intersect(array_keys($newByKey), array_keys($oldByKey))) !== array_values(array_intersect(array_keys($oldByKey), array_keys($newByKey)));
		$functionChanges = [];
		foreach ($functions as $function => $key) if (($this->installation->functionFolders()[$function] ?? '') !== $key) $functionChanges[] = ['function' => $function, 'label' => InstallationConfigService::FUNCTIONS[$function][1], 'from' => $oldByKey[$this->installation->functionFolders()[$function] ?? ''] ?? '', 'to' => $newByKey[$key]];

		$blocked = [];
		$templates = $this->templateUsage();
		$scan = $withCaseScan && ($renames !== [] || $removed !== []) ? $this->scanCases(array_column($renames, 'to', 'from'), array_column($removed, 'name')) : ['folders' => [], 'conflicts' => [], 'nonEmpty' => [], 'cases' => 0];
		foreach ($removed as $item) {
			$reasons = [];
			foreach ($functions as $function => $key) if ($key === $item['key']) $reasons[] = 'ist ' . InstallationConfigService::FUNCTIONS[$function][1];
			if (!empty($templates[$item['name']])) $reasons[] = 'Ziel der Vorlage(n) ' . implode(', ', $templates[$item['name']]);
			if (!empty($scan['nonEmpty'][$item['name']])) $reasons[] = 'enthält Dateien in ' . count($scan['nonEmpty'][$item['name']]) . ' Fall/Fällen (z. B. ' . implode(', ', array_slice($scan['nonEmpty'][$item['name']], 0, 3)) . ')';
			if ($reasons !== []) $blocked[] = ['key' => $item['key'], 'name' => $item['name'], 'reasons' => $reasons];
		}
		$references = $renames !== [] ? $this->countReferences(array_column($renames, 'to', 'from')) : [];
		foreach ($renames as $key => $rename) {
			$renames[$key]['caseFolders'] = count($scan['folders'][$rename['from']] ?? []);
			$renames[$key]['templates'] = $templates[$rename['from']] ?? [];
		}
		$conflicts = [];
		foreach ($scan['conflicts'] as $name => $cases) $conflicts[] = ['name' => $name, 'cases' => array_slice($cases, 0, 20), 'count' => count($cases)];
		return [
			'plan' => $plan, 'functions' => $functions,
			'renames' => array_values($renames), 'added' => $added, 'removed' => $removed, 'orderChanged' => $orderChanged, 'functionChanges' => $functionChanges,
			'blocked' => $blocked, 'conflicts' => $conflicts, 'references' => $references, 'casesScanned' => $scan['cases'],
			'changed' => $renames !== [] || $added !== [] || $removed !== [] || $orderChanged || $functionChanges !== [],
			'canApply' => $blocked === [] && $conflicts === [],
		];
	}

	/** Planänderung übernehmen (Ordner umbenennen, Verweise umstellen, Plan speichern). */
	public function apply(array $input, array $functionInput = []): array {
		$preview = $this->preview($input, $functionInput);
		if (!$preview['changed']) return $preview + ['applied' => false];
		if ($preview['blocked'] !== []) throw new \InvalidArgumentException('Nicht übernommen: ' . implode('; ', array_map(static fn(array $item): string => '„' . $item['name'] . '“ ' . implode(', ', $item['reasons']), $preview['blocked'])) . '.');
		if ($preview['conflicts'] !== []) throw new \InvalidArgumentException('Nicht übernommen: In ' . array_sum(array_column($preview['conflicts'], 'count')) . ' Fall/Fällen gibt es den neuen Ordnernamen schon (z. B. ' . implode(', ', array_slice($preview['conflicts'][0]['cases'], 0, 3)) . '). Bitte dort zuerst zusammenführen.');
		$this->lock();
		$moved = [];
		try {
			$map = array_column($preview['renames'], 'to', 'from');
			if ($map !== []) $moved = $this->renameCaseFolders($map);
			$this->db->beginTransaction();
			try {
				$updated = $map !== [] ? $this->rewriteReferences($map) : [];
				$this->installation->storePlan($preview['plan'], $preview['functions']);
				$this->db->commit();
			} catch (\Throwable $error) {
				$this->db->rollBack();
				throw $error;
			}
		} catch (\Throwable $error) {
			$this->rollbackMoves($moved);
			$this->unlock();
			$this->logger->error('Ordnerplan konnte nicht übernommen werden.', ['reason' => $error->getMessage()]);
			throw $error;
		}
		$this->unlock();
		$result = ['applied' => true, 'renamedFolders' => count($moved), 'updatedReferences' => $updated];
		$this->audit->logSystem('FOLDER_PLAN', 'CHANGED', ['renames' => $preview['renames'], 'added' => $preview['added'], 'removed' => $preview['removed'], 'functionChanges' => $preview['functionChanges'], 'renamedFolders' => count($moved), 'updatedReferences' => $updated]);
		return $preview + $result;
	}

	/** Solange eine Umstellung läuft, legt die App keine Fall-Unterordner an (sonst entstünden Ordner mit altem Namen). */
	public function lockedSince(): ?int {
		$value = (int)$this->config->getAppValue(Application::APP_ID, self::LOCK_KEY, '0');
		return $value > 0 && $value > time() - self::LOCK_SECONDS ? $value : null;
	}

	/** @return array{0: list<array{key: string, name: string}>, 1: array<string, string>} */
	private function normalize(array $input, array $functionInput): array {
		$old = $this->installation->folderPlan();
		$oldKeys = array_column($old, 'key');
		$plan = []; $names = []; $keys = [];
		foreach ($input as $item) {
			if (!is_array($item)) continue;
			$name = $this->installation->validFolderName((string)($item['name'] ?? ''), 'Fall-Unterordner');
			$lower = mb_strtolower($name);
			if (isset($names[$lower])) throw new \InvalidArgumentException('Der Ordnername „' . $name . '“ kommt doppelt vor.');
			$names[$lower] = true;
			$key = strtoupper(trim((string)($item['key'] ?? '')));
			if ($key !== '' && !in_array($key, $oldKeys, true)) throw new \InvalidArgumentException('Unbekanntes Ordner-Kennzeichen „' . $key . '“. Neue Ordner ohne Kennzeichen anlegen.');
			if ($key === '') {
				$base = InstallationConfigService::keyFromName($name); $key = $base; $n = 2;
				while (isset($keys[$key]) || in_array($key, $oldKeys, true)) $key = $base . '_' . $n++;
			}
			if (isset($keys[$key])) throw new \InvalidArgumentException('Das Kennzeichen „' . $key . '“ kommt doppelt vor.');
			$keys[$key] = true;
			$plan[] = ['key' => $key, 'name' => $name];
		}
		if ($plan === []) throw new \InvalidArgumentException('Mindestens ein Fall-Unterordner ist erforderlich.');
		$functions = $this->installation->functionFolders();
		foreach (InstallationConfigService::FUNCTIONS as $function => [, $label]) {
			if (isset($functionInput[$function]) && trim((string)$functionInput[$function]) !== '') $functions[$function] = strtoupper(trim((string)$functionInput[$function]));
			// Zeigt die Funktion auf einen entfernten Ordner, meldet die Vorschau das als Sperrgrund.
			if (!isset($keys[$functions[$function]]) && !in_array($functions[$function], $oldKeys, true)) throw new \InvalidArgumentException('„' . $label . '“ muss auf einen Ordner des Plans zeigen.');
		}
		return [$plan, $functions];
	}

	/**
	 * @param array<string, string> $renames alt => neu
	 * @param list<string> $removedNames
	 * @return array{folders: array<string, list<string>>, conflicts: array<string, list<string>>, nonEmpty: array<string, list<string>>, cases: int}
	 */
	private function scanCases(array $renames, array $removedNames): array {
		$folders = []; $conflicts = []; $nonEmpty = []; $cases = 0;
		$vacated = array_keys($renames);
		foreach ($this->caseFolders() as [$label, $case]) {
			$cases++;
			foreach ($renames as $from => $to) {
				if (!$case->nodeExists($from)) continue;
				$folders[$from][] = $label;
				// Ziel existiert schon und wird nicht selbst weg-umbenannt → Konflikt
				if ($case->nodeExists($to) && !in_array($to, $vacated, true)) $conflicts[$to][] = $label;
			}
			foreach ($removedNames as $name) {
				if (!$case->nodeExists($name)) continue;
				$node = $case->get($name);
				if ($node instanceof Folder && $node->getDirectoryListing() !== []) $nonEmpty[$name][] = $label;
			}
		}
		return ['folders' => $folders, 'conflicts' => $conflicts, 'nonEmpty' => $nonEmpty, 'cases' => $cases];
	}

	/** @return \Generator<array{0: string, 1: Folder}> alle Fallordner (zentral und Altbestände) */
	private function caseFolders(): \Generator {
		foreach ($this->storage->existingFolders($this->installation->casesPath()) as $base) {
			foreach ($base['folder']->getDirectoryListing() as $year) {
				if (!$year instanceof Folder || !preg_match('/^\d{4}$/', $year->getName())) continue;
				foreach ($year->getDirectoryListing() as $case) if ($case instanceof Folder) yield [$base['label'] . '/' . $year->getName() . '/' . $case->getName(), $case];
			}
		}
	}

	/**
	 * Zweistufig (erst auf eindeutige Zwischennamen, dann auf die neuen Namen), damit auch Tausch und
	 * Verschieben der Nummerierung („04 …“ ↔ „05 …“) funktionieren.
	 * @param array<string, string> $map alt => neu
	 * @return list<array{0: \OCP\Files\Node, 1: string}> umbenannte Ordner mit ursprünglichem Namen (für Rücknahme)
	 */
	private function renameCaseFolders(array $map): array {
		$moved = []; $staged = [];
		foreach ($this->caseFolders() as [, $case]) {
			foreach ($map as $from => $to) {
				if (!$case->nodeExists($from)) continue;
				$node = $case->get($from);
				if (!$node instanceof Folder) continue;
				$temporary = '.umbenennen-' . bin2hex(random_bytes(4)) . '-' . InstallationConfigService::keyFromName($to);
				$node = $node->move(rtrim($case->getPath(), '/') . '/' . $temporary);
				$moved[] = [$node, $from];
				$staged[] = [$case, $node, $to];
			}
		}
		foreach ($staged as $index => [$case, $node, $to]) {
			if ($case->nodeExists($to)) throw new \RuntimeException('Im Fallordner ' . $case->getName() . ' gibt es „' . $to . '“ bereits.');
			$final = $node->move(rtrim($case->getPath(), '/') . '/' . $to);
			$moved[$index] = [$final, $moved[$index][1]];
		}
		return $moved;
	}

	/** @param list<array{0: \OCP\Files\Node, 1: string}> $moved */
	private function rollbackMoves(array $moved): void {
		foreach (array_reverse($moved) as [$node, $original]) {
			try { $node->move(rtrim($node->getParent()->getPath(), '/') . '/' . $original); } catch (\Throwable $error) { $this->logger->error('Ordner konnte nicht zurückbenannt werden.', ['folder' => $node->getPath(), 'original' => $original, 'reason' => $error->getMessage()]); }
		}
	}

	/** @param array<string, string> $map @return array<string, int> */
	private function countReferences(array $map): array {
		$counts = [];
		foreach (self::REFERENCES as $reference) {
			$count = 0;
			foreach ($this->rows($reference) as $row) if ($this->rewriteValue($reference['kind'], $row[$reference['column']], $map) !== $row[$reference['column']]) $count++;
			if ($count > 0) $counts[$reference['table']] = $count;
		}
		return $counts;
	}

	/** @param array<string, string> $map @return array<string, int> */
	private function rewriteReferences(array $map): array {
		$updated = [];
		foreach (self::REFERENCES as $reference) {
			$count = 0;
			foreach ($this->rows($reference) as $row) {
				$value = $row[$reference['column']];
				$new = $this->rewriteValue($reference['kind'], $value, $map);
				if ($new === $value) continue;
				$query = $this->db->getQueryBuilder();
				$query->update($reference['table'])->set($reference['column'], $query->createNamedParameter($new))->where($query->expr()->eq($reference['id'], $query->createNamedParameter((int)$row[$reference['id']])))->executeStatement();
				$count++;
			}
			if ($count > 0) $updated[$reference['table']] = $count;
		}
		return $updated;
	}

	private function rewriteValue(string $kind, mixed $value, array $map): mixed {
		if ($value === null || $value === '') return $value;
		if ($kind === 'name') return $map[(string)$value] ?? $value;
		if ($kind === 'path') return $this->rewritePath((string)$value, $map);
		$decoded = json_decode((string)$value, true);
		if (!is_array($decoded)) return $value;
		$changed = false;
		$rewritten = $this->rewriteJson($decoded, $map, $changed);
		return $changed ? json_encode($rewritten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $value;
	}

	private function rewriteJson(array $data, array $map, bool &$changed): array {
		foreach ($data as $key => $value) {
			if (is_array($value)) { $data[$key] = $this->rewriteJson($value, $map, $changed); continue; }
			if (!is_string($value) || $value === '') continue;
			$name = (string)$key;
			$new = $value;
			if (in_array($name, ['subfolder', 'outputSubfolder', 'folder'], true)) $new = $map[$value] ?? $value;
			elseif ($name === 'relativePath') { $parts = explode('/', $value, 2); if (isset($map[$parts[0]]) && count($parts) === 2) $new = $map[$parts[0]] . '/' . $parts[1]; }
			elseif (in_array($name, ['path', 'filePath', 'file_path'], true)) $new = $this->rewritePath($value, $map);
			if ($new !== $value) { $data[$key] = $new; $changed = true; }
		}
		return $data;
	}

	/** Ersetzt den Unterordner direkt hinter „<Fallordner>/<Jahr>/<Fallnummer>/“. */
	private function rewritePath(string $path, array $map): string {
		$cases = preg_quote(trim($this->installation->casesFolder(), '/'), '#');
		return (string)preg_replace_callback('#(^|/)(' . $cases . '/\d{4}/[^/]+/)([^/]+)(?=/|$)#u', static fn(array $m): string => $m[1] . $m[2] . ($map[$m[3]] ?? $m[3]), $path);
	}

	/** @return list<array<string, mixed>> */
	private function rows(array $reference): array {
		try {
			$query = $this->db->getQueryBuilder();
			return $query->select($reference['id'], $reference['column'])->from($reference['table'])->where($query->expr()->isNotNull($reference['column']))->executeQuery()->fetchAllAssociative();
		} catch (\Throwable) { return []; }
	}

	/** @return array<string, list<string>> Ordnername => Vorlagen */
	private function templateUsage(): array {
		$usage = [];
		try {
			$query = $this->db->getQueryBuilder();
			foreach ($query->select('name', 'output_subfolder')->from('bestatter_document_templates')->where($query->expr()->isNotNull('output_subfolder'))->executeQuery()->fetchAllAssociative() as $row) {
				$folder = $this->installation->resolveSubfolder((string)$row['output_subfolder']) ?? (string)$row['output_subfolder'];
				if ($folder !== '') $usage[$folder][] = (string)$row['name'];
			}
		} catch (\Throwable) {}
		return $usage;
	}

	private function lock(): void {
		if ($this->lockedSince() !== null) throw new \RuntimeException('Der Ordnerplan wird gerade umgestellt. Bitte in einigen Minuten erneut versuchen.');
		$this->config->setAppValue(Application::APP_ID, self::LOCK_KEY, (string)time());
	}

	private function unlock(): void { $this->config->deleteAppValue(Application::APP_ID, self::LOCK_KEY); }
}
