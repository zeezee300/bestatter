<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * 0.70.0 (OP-040) – Umzug der benutzerbezogenen Ablagen in die zentrale Fallablage.
 *
 * Trockenlauf (plan) zeigt Quelle, Ziel, Dateizahl, Konflikte und zu aktualisierende Verweise.
 * Der bestätigte Lauf (execute) kopiert die Dateien, schreibt die Datei-IDs in der Datenbank um
 * (Dokumentregister, Rechnungsnachweise, Eingangsrechnungen, Postausgang, Erfassungen, Paperless,
 * Ablaufknöpfe) und schaltet danach auf die zentrale Ablage um. Die persönlichen Ordner bleiben als
 * Quelle unverändert erhalten, bis sie nach separater Freigabe von Hand gelöscht werden. Das
 * Audit-Log wird nicht verändert. Ein erneuter Lauf ist gefahrlos (bereits vorhandene gleiche
 * Dateien werden wiederverwendet).
 */
class StorageMigrationService {
	/** @var array<string, array{table:string, id:string, column:string, kind:string}> */
	private const REFERENCES = [
		'records' => ['table' => 'bestatter_records', 'id' => 'id', 'column' => 'payload', 'kind' => 'json'],
		'invoiceManifest' => ['table' => 'bestatter_invoices', 'id' => 'id', 'column' => 'closure_manifest', 'kind' => 'json'],
		'actionRuns' => ['table' => 'bestatter_action_runs', 'id' => 'id', 'column' => 'result_json', 'kind' => 'json'],
		'deregMail' => ['table' => 'bestatter_dereg_mail_outbox', 'id' => 'id', 'column' => 'attachments_json', 'kind' => 'json'],
		'incomingFile' => ['table' => 'bestatter_incoming_invoices', 'id' => 'id', 'column' => 'file_id', 'kind' => 'int'],
		'incomingPath' => ['table' => 'bestatter_incoming_invoices', 'id' => 'id', 'column' => 'file_path', 'kind' => 'path'],
		'mailOutbox' => ['table' => 'bestatter_mail_outbox', 'id' => 'id', 'column' => 'file_id', 'kind' => 'int'],
		'captureImports' => ['table' => 'bestatter_capture_imports', 'id' => 'id', 'column' => 'nextcloud_file_id', 'kind' => 'int'],
		'externalDocuments' => ['table' => 'bestatter_external_documents', 'id' => 'id', 'column' => 'nextcloud_file_id', 'kind' => 'int'],
	];

	public function __construct(
		private IDBConnection $db,
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private InstallationConfigService $installation,
		private CaseStorageService $storage,
		private AuditService $audit,
		private LoggerInterface $logger,
	) {}

	/**
	 * Trockenlauf: verändert nichts.
	 * $sourceFolder (0.70.2): statt der persönlichen Ordner einen Ordner im Ablagekonto als Quelle verwenden –
	 * z. B. die Kopie „Bestatter (1)“, nachdem das Ablagekonto einem Teamordner „Bestatter“ zugeordnet wurde.
	 */
	public function plan(string $account = '', string $sourceFolder = ''): array {
		$account = $this->account($account);
		$target = $this->targetBase($account);
		$targetRoot = $target !== null ? $this->existingPath($target, $this->installation->storageRoot()) : null;
		$items = []; $planned = []; $sources = []; $seenFolders = []; $warnings = [];
		$storageRoot = trim($this->installation->storageRoot(), '/');
		$sourceFolder = trim($sourceFolder, '/');
		if ($sourceFolder !== '') {
			if ($target === null) throw new \InvalidArgumentException('Das Ablagekonto ist nicht erreichbar.');
			$sourceRoot = $this->existingPath($target, $sourceFolder);
			$shadow = $sourceRoot === null ? $this->shadowedCopy($target, $targetRoot, $storageRoot, $account) : null;
			if ($sourceRoot === null && $shadow !== null) return $this->shadowPlan($account, $target, $shadow, $storageRoot, $sourceFolder);
			if ($sourceRoot === null) throw new \InvalidArgumentException('Der Quellordner „' . $sourceFolder . '“ existiert im Ablagekonto nicht. Ordner im Ablagekonto: ' . $this->rootListing($target) . '. Arbeitsordner „' . $storageRoot . '“: ' . ($targetRoot !== null ? $this->storage->locationOf($targetRoot, $account) : 'nicht vorhanden') . '.');
			if ($targetRoot === null || $this->storage->locationOf($targetRoot, $account) !== 'TEAMFOLDER') throw new \InvalidArgumentException('Der Arbeitsordner „' . $storageRoot . '“ des Ablagekontos ist (noch) kein Teamordner. Bitte zuerst das Ablagekonto dem Teamordner zuordnen (Gruppe mit Lese-, Schreib- und Löschrecht).');
			if ((int)$sourceRoot->getId() === (int)$targetRoot->getId() || $targetRoot->getById((int)$sourceRoot->getId()) !== []) throw new \InvalidArgumentException('Der Quellordner liegt im Arbeitsordner selbst.');
			$sourceSpecs = [['uid' => $account, 'base' => $target, 'label' => $account . '/' . $sourceFolder, 'map' => [$sourceFolder, $storageRoot]]];
		} else {
			$sourceSpecs = [];
			foreach ($this->sourceUids($account) as $uid) {
				try { $sourceSpecs[] = ['uid' => $uid, 'base' => $this->rootFolder->getUserFolder($uid), 'label' => $uid, 'map' => null]; } catch (\Throwable) {}
			}
		}
		foreach ($sourceSpecs as $spec) {
			$uid = $spec['uid']; $base = $spec['base'];
			$summary = ['uid' => $spec['label'], 'files' => 0, 'bytes' => 0, 'paths' => [], 'teamfolder' => false];
			foreach ($this->managedPaths() as $path) {
				$sourcePath = $spec['map'] !== null ? $this->remap($path, $storageRoot, $spec['map'][0]) : $path;
				if ($sourcePath === null) continue;
				$folder = $this->existingPath($base, $sourcePath);
				if ($folder === null) continue;
				$folderId = (int)$folder->getId();
				// Ordner, die bereits im Arbeitsordner liegen (Freigabe/Teamordner) oder schon bei einer anderen Person gezählt wurden.
				if (isset($seenFolders[$folderId]) || ($targetRoot !== null && ((int)$targetRoot->getId() === $folderId || $targetRoot->getById($folderId) !== []))) continue;
				$seenFolders[$folderId] = true;
				if ($spec['map'] === null && $this->storage->locationOf($folder, $uid) === 'TEAMFOLDER') $summary['teamfolder'] = true;
				$summary['paths'][] = $sourcePath;
				foreach ($this->files($folder) as $file) {
					$relative = substr($file->getPath(), strlen(rtrim($base->getPath(), '/')) + 1);
					$targetRelative = $spec['map'] !== null ? (string)$this->remap($relative, $spec['map'][0], $storageRoot) : $relative;
					$hash = $this->hash($file);
					$item = ['uid' => $uid, 'sourceId' => (int)$file->getId(), 'source' => $relative, 'target' => $targetRelative, 'size' => (int)$file->getSize(), 'mtime' => (int)$file->getMTime(), 'hash' => $hash, 'action' => 'COPY'];
					$existingHash = $planned[$targetRelative] ?? ($target !== null ? $this->existingHash($target, $targetRelative) : null);
					if ($existingHash !== null) {
						if ($existingHash === $hash) $item['action'] = 'SAME';
						else {
							$item['action'] = 'CONFLICT';
							$item['target'] = $this->conflictName($targetRelative, $uid, $planned, $target);
						}
					}
					if ($item['action'] !== 'SAME') $planned[$item['target']] = $hash;
					$items[] = $item;
					$summary['files']++; $summary['bytes'] += $item['size'];
				}
			}
			if ($summary['teamfolder']) $warnings[] = 'Die Ablage von „' . $spec['label'] . '“ liegt bereits in einem Teamordner, den alle Mitglieder sehen. Empfehlung: nicht kopieren, sondern das Ablagekonto diesem Teamordner zuordnen (siehe docs/FALLABLAGE.md, „Vorhandener Teamordner“). Kopieren nur mit --copy-teamfolder.';
			if ($summary['files'] > 0 || $summary['paths'] !== []) $sources[] = $summary;
		}
		if ($sourceFolder === '' && $target !== null && ($shadow = $this->shadowedCopy($target, $targetRoot, $storageRoot, $account)) !== null) {
			$warnings[] = 'Im Ablagekonto liegt die bisherige Ablage „' . $storageRoot . '“ verdeckt unter dem gleichnamigen Teamordner. Zusammenführen mit: occ bestatter:storage-migrate --source-folder="' . $shadow['revealAs'] . '" (benennt die Kopie beim Ausführen zuerst in „' . $shadow['revealAs'] . '“ um).';
		}
		if ($sourceFolder === '' && $target !== null) {
			foreach ($this->leftoverCopies($target, $targetRoot, $storageRoot, $account) as $candidate) $warnings[] = 'Im Ablagekonto liegt der Ordner „' . $candidate . '“ (vermutlich die bisherige Ablage, vom Teamordner verdrängt). Zusammenführen mit: occ bestatter:storage-migrate --source-folder="' . $candidate . '"';
		}
		$sourceIds = array_fill_keys(array_column($items, 'sourceId'), true);
		return [
			'account' => $account, 'targetRoot' => $account . '/' . $this->installation->storageRoot(), 'currentMode' => $this->installation->storageMode(),
			'targetLocation' => $targetRoot !== null ? $this->storage->locationOf($targetRoot, $account) : 'NOT_CREATED', 'sourceFolder' => $sourceFolder,
			'warnings' => $warnings, 'teamfolderSources' => count(array_filter($sources, static fn(array $source): bool => $source['teamfolder'])),
			'sources' => $sources, 'files' => count($items), 'bytes' => array_sum(array_column($items, 'size')),
			'copy' => count(array_filter($items, static fn(array $item): bool => $item['action'] === 'COPY')),
			'same' => count(array_filter($items, static fn(array $item): bool => $item['action'] === 'SAME')),
			'conflicts' => array_values(array_map(static fn(array $item): array => ['uid' => $item['uid'], 'source' => $item['source'], 'target' => $item['target']], array_filter($items, static fn(array $item): bool => $item['action'] === 'CONFLICT'))),
			'references' => $this->countReferences($sourceIds),
			'items' => $items,
		];
	}

	/** Bestätigter Lauf. */
	public function execute(string $account = '', string $sourceFolder = '', bool $copyTeamfolder = false): array {
		$plan = $this->plan($account, $sourceFolder);
		$account = $plan['account'];
		$revealed = null;
		if (($plan['shadowed'] ?? false) === true) {
			// 0.70.2: vom Teamordner verdeckte Kopie zuerst sichtbar machen (Umbenennen im Speicher des Kontos;
			// Datei-IDs und Freigaben bleiben), danach mit dem sichtbaren Ordner wie gewohnt zusammenführen.
			$revealed = $this->revealShadowedCopy($account, (string)$plan['sourceFolder']);
			$plan = $this->plan($account, $revealed);
		}
		if ($plan['teamfolderSources'] > 0 && !$copyTeamfolder) throw new \InvalidArgumentException('Die bisherige Ablage liegt bereits in einem Teamordner. Bitte das Ablagekonto dem Teamordner zuordnen statt zu kopieren (docs/FALLABLAGE.md). Wer trotzdem kopieren will: --copy-teamfolder.');
		$prepared = $this->storage->prepareAccount($account, false);
		$target = $this->rootFolder->getUserFolder($account);
		$map = []; $pathMap = []; $created = 0; $reused = 0; $errors = [];
		foreach ($plan['items'] as $item) {
			try {
				$source = $this->nodeById($this->rootFolder->getUserFolder($item['uid']), (int)$item['sourceId']);
				if ($source === null) throw new \RuntimeException('Quelldatei nicht mehr vorhanden.');
				$existing = $this->fileAt($target, $item['target']);
				if ($existing !== null && $this->hash($existing) === $item['hash']) { $map[$item['sourceId']] = (int)$existing->getId(); $reused++; }
				elseif ($existing !== null) throw new \RuntimeException('Zieldatei ist inzwischen abweichend vorhanden: ' . $item['target']);
				else {
					$folder = $this->installation->ensurePath($target, dirname($item['target']) === '.' ? '' : dirname($item['target']));
					$copy = $folder->newFile(basename($item['target']));
					$stream = $source->fopen('r');
					$copy->putContent($stream !== false ? $stream : (string)$source->getContent());
					if (is_resource($stream)) @fclose($stream);
					if ($item['mtime'] > 0) try { $copy->touch($item['mtime']); } catch (\Throwable) {}
					$map[$item['sourceId']] = (int)$copy->getId(); $created++;
				}
				if ($item['target'] !== $item['source']) $pathMap[$item['source']] = $item['target'];
			} catch (\Throwable $error) {
				$errors[] = ['uid' => $item['uid'], 'source' => $item['source'], 'message' => $error->getMessage()];
			}
		}
		if ($errors !== []) {
			$this->logger->error('Ablage-Migration abgebrochen: Dateien konnten nicht kopiert werden.', ['errors' => $errors]);
			throw new \RuntimeException('Migration abgebrochen: ' . count($errors) . ' Datei(en) konnten nicht kopiert werden (z. B. ' . $errors[0]['source'] . ': ' . $errors[0]['message'] . '). Die Datenbank und die Betriebsart blieben unverändert; ein erneuter Lauf setzt fort.');
		}
		$this->db->beginTransaction();
		try {
			$updated = $this->rewriteReferences($map, $pathMap);
			$activated = $this->storage->activate('CENTRAL', $account, true);
			$prepared['createdShares'] = $activated['createdShares'] ?? [];
			$prepared['location'] = $activated['location'] ?? $prepared['location'];
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		// 0.70.2: Beim Zusammenführen in den Teamordner die Gruppenfreigaben der bisherigen Kopie entfernen,
		// damit die Mitglieder nur noch den Teamordner sehen. Die Kopie selbst bleibt zur Kontrolle erhalten.
		$removedShares = [];
		if ($plan['sourceFolder'] !== '') {
			$sourceRoot = $this->existingPath($target, $plan['sourceFolder']);
			if ($sourceRoot !== null) $removedShares = $this->storage->removeGroupShares($sourceRoot, $account);
		}
		$report = ['schema' => 'bestatter-storage-migration/1', 'executedAt' => date('c'), 'account' => $account, 'location' => $prepared['location'], 'createdShares' => $prepared['createdShares'],
			'created' => $created, 'reused' => $reused, 'conflicts' => $plan['conflicts'], 'updatedReferences' => $updated, 'sources' => $plan['sources'],
			'sourceFolder' => $plan['sourceFolder'], 'revealedShadowedCopy' => $revealed !== null, 'removedShares' => $removedShares,
			'note' => $plan['sourceFolder'] !== '' ? 'Der Quellordner „' . $plan['sourceFolder'] . '“ im Ablagekonto ist unverändert erhalten (ohne Gruppenfreigabe) und kann nach Prüfung gelöscht werden.' : 'Die persönlichen Ordner der Quellen sind unverändert erhalten und können nach Prüfung von Hand gelöscht werden.',
			'files' => array_map(static fn(array $item): array => ['uid' => $item['uid'], 'source' => $item['source'], 'target' => $item['target'], 'sourceId' => $item['sourceId'], 'targetId' => $map[$item['sourceId']] ?? null, 'sha256' => $item['hash']], $plan['items'])];
		$reportFile = null;
		try {
			$folder = $this->installation->ensurePath($target, $this->installation->storageRoot() . '/.Migration');
			$reportFile = $folder->newFile('Ablage-Migration ' . date('Y-m-d His') . '.json');
			$reportFile->putContent(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		} catch (\Throwable $error) { $this->logger->warning('Migrationsbericht konnte nicht geschrieben werden.', ['reason' => $error->getMessage()]); }
		$this->audit->logSystem('STORAGE', $plan['sourceFolder'] !== '' ? 'MERGED_INTO_TEAMFOLDER' : 'MIGRATED_TO_CENTRAL', ['account' => $account, 'sourceFolder' => $plan['sourceFolder'], 'removedShares' => $removedShares, 'created' => $created, 'reused' => $reused, 'conflicts' => count($plan['conflicts']), 'updatedReferences' => $updated, 'reportFileId' => $reportFile?->getId()]);
		unset($report['files']);
		return $report + ['reportFileId' => $reportFile?->getId(), 'mode' => 'CENTRAL'];
	}

	/** @param array<int, true> $sourceIds @return array<string, int> */
	private function countReferences(array $sourceIds): array {
		$counts = [];
		foreach (self::REFERENCES as $name => $reference) {
			if (in_array($reference['kind'], ['path'], true)) continue;
			$count = 0;
			foreach ($this->rows($reference) as $row) {
				$value = $row[$reference['column']];
				if ($reference['kind'] === 'int') { if (isset($sourceIds[(int)$value])) $count++; continue; }
				$decoded = json_decode((string)$value, true);
				if (is_array($decoded) && $this->containsId($decoded, $sourceIds)) $count++;
			}
			if ($count > 0) $counts[$name] = $count;
		}
		return $counts;
	}

	/** @param array<int, int> $map @param array<string, string> $pathMap @return array<string, int> */
	private function rewriteReferences(array $map, array $pathMap): array {
		$updated = [];
		if ($map === [] && $pathMap === []) return $updated;
		foreach (self::REFERENCES as $name => $reference) {
			$count = 0;
			foreach ($this->rows($reference) as $row) {
				$value = $row[$reference['column']];
				$new = $value;
				if ($reference['kind'] === 'int') { if ($value !== null && isset($map[(int)$value])) $new = $map[(int)$value]; }
				elseif ($reference['kind'] === 'path') { if ($value !== null) foreach ($this->pathVariants((string)$value) as $variant) if (isset($pathMap[$variant])) { $new = $pathMap[$variant]; break; } }
				else {
					$decoded = json_decode((string)$value, true);
					if (!is_array($decoded)) continue;
					$changed = false;
					$rewritten = $this->rewriteJson($decoded, $map, $pathMap, $changed);
					if ($changed) $new = json_encode($rewritten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
				}
				if ($new === $value) continue;
				$update = $this->db->getQueryBuilder();
				$update->update($reference['table'])->set($reference['column'], $update->createNamedParameter($new))
					->where($update->expr()->eq($reference['id'], $update->createNamedParameter((int)$row[$reference['id']])))->executeStatement();
				$count++;
			}
			if ($count > 0) $updated[$name] = $count;
		}
		return $updated;
	}

	private function rewriteJson(array $data, array $map, array $pathMap, bool &$changed): array {
		foreach ($data as $key => $value) {
			if (is_array($value)) { $data[$key] = $this->rewriteJson($value, $map, $pathMap, $changed); continue; }
			$name = (string)$key;
			if (($name === 'fileId' || str_ends_with($name, 'FileId')) && is_numeric($value) && isset($map[(int)$value])) { $data[$key] = $map[(int)$value]; $changed = true; continue; }
			if (in_array($name, ['path', 'relativePath'], true) && is_string($value) && $pathMap !== []) {
				foreach ($this->pathVariants($value) as $variant) if (isset($pathMap[$variant])) { $data[$key] = $pathMap[$variant]; $changed = true; break; }
			}
		}
		return $data;
	}

	private function containsId(array $data, array $ids): bool {
		foreach ($data as $key => $value) {
			if (is_array($value)) { if ($this->containsId($value, $ids)) return true; continue; }
			$name = (string)$key;
			if (($name === 'fileId' || str_ends_with($name, 'FileId')) && is_numeric($value) && isset($ids[(int)$value])) return true;
		}
		return false;
	}

	/** @return list<string> */
	private function pathVariants(string $path): array { return array_values(array_unique([$path, ltrim($path, '/')])); }

	/** @return list<array<string, mixed>> */
	private function rows(array $reference): array {
		try {
			$query = $this->db->getQueryBuilder();
			return $query->select($reference['id'], $reference['column'])->from($reference['table'])->where($query->expr()->isNotNull($reference['column']))->executeQuery()->fetchAllAssociative();
		} catch (\Throwable) { return []; }
	}

	private function account(string $account): string {
		$account = trim($account) !== '' ? trim($account) : $this->installation->storageAccount();
		if ($account === '') throw new \InvalidArgumentException('Kein Ablagekonto angegeben. Bitte in der Administration unter „Ablage“ eintragen oder --account angeben.');
		$user = $this->userManager->get($account);
		if ($user === null || !$user->isEnabled()) throw new \InvalidArgumentException('Das Ablagekonto „' . $account . '“ existiert nicht oder ist deaktiviert.');
		return $account;
	}

	private function targetBase(string $account): ?Folder {
		try { return $this->rootFolder->getUserFolder($account); } catch (\Throwable) { return null; }
	}

	/** @return list<string> */
	private function sourceUids(string $account): array {
		$uids = array_fill_keys($this->storage->bestatterUids(), true);
		try {
			$query = $this->db->getQueryBuilder();
			foreach ($query->selectDistinct('owner_uid')->from('bestatter_records')->where($query->expr()->isNotNull('owner_uid'))->executeQuery()->fetchFirstColumn() as $uid) if ((string)$uid !== '' && $this->userManager->userExists((string)$uid)) $uids[(string)$uid] = true;
		} catch (\Throwable) {}
		unset($uids[$account]);
		$list = array_keys($uids);
		sort($list, SORT_NATURAL | SORT_FLAG_CASE);
		return array_map('strval', $list);
	}

	/** @return list<string> */
	private function managedPaths(): array {
		return array_values(array_unique([$this->installation->casesPath(), $this->installation->templatesPath(), $this->installation->articleImagesPath(), $this->installation->captureInboxPath()]));
	}

	/** @return \Generator<File> */
	private function files(Folder $folder): \Generator {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) yield from $this->files($node);
			elseif ($node instanceof File) yield $node;
		}
	}

	private function hash(File $file): string {
		$stream = $file->fopen('r');
		if ($stream === false) return hash('sha256', (string)$file->getContent());
		$context = hash_init('sha256');
		hash_update_stream($context, $stream);
		fclose($stream);
		return hash_final($context);
	}

	private function existingHash(Folder $base, string $relative): ?string {
		$file = $this->fileAt($base, $relative);
		return $file !== null ? $this->hash($file) : null;
	}

	private function fileAt(Folder $base, string $relative): ?File {
		try { if (!$base->nodeExists($relative)) return null; $node = $base->get($relative); return $node instanceof File ? $node : null; } catch (\Throwable) { return null; }
	}

	private function nodeById(Folder $base, int $id): ?File {
		foreach ($base->getById($id) as $node) if ($node instanceof File) return $node;
		return null;
	}

	private function conflictName(string $relative, string $uid, array $planned, ?Folder $target): string {
		$dir = dirname($relative); $info = pathinfo($relative);
		$stem = $info['filename']; $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
		$safeUid = preg_replace('/[^A-Za-z0-9._-]/', '_', $uid) ?: 'person';
		for ($n = 1; $n < 100; $n++) {
			$name = $stem . ' (von ' . $safeUid . ($n > 1 ? ' ' . $n : '') . ')' . $ext;
			$candidate = ($dir === '.' ? '' : $dir . '/') . $name;
			if (!isset($planned[$candidate]) && ($target === null || $this->fileAt($target, $candidate) === null)) return $candidate;
		}
		throw new \RuntimeException('Kein freier Dateiname für ' . $relative);
	}

	/**
	 * 0.70.2: Ältere Teamordner-Versionen benennen einen gleichnamigen eigenen Ordner beim Einbinden nicht um,
	 * sondern verdecken ihn. Liegt unter dem Teamordner-Pfad noch ein Ordner im eigenen Speicher des Kontos,
	 * ist das die bisherige Kopie.
	 * @return array{storage: \OCP\Files\Storage\IStorage, path: string, revealAs: string}|null
	 */
	private function shadowedCopy(Folder $target, ?Folder $targetRoot, string $storageRoot, string $account): ?array {
		if ($targetRoot === null || $this->storage->locationOf($targetRoot, $account) !== 'TEAMFOLDER') return null;
		try {
			$home = $target->getStorage();
			$cache = $home->getCache();
			$internal = 'files/' . $storageRoot;
			if (!$cache->inCache($internal) || !$home->is_dir($internal)) return null;
			$n = 1;
			do { $name = $storageRoot . ' (' . $n++ . ')'; } while ($cache->inCache('files/' . $name) || $home->file_exists('files/' . $name));
			return ['storage' => $home, 'path' => $internal, 'revealAs' => $name];
		} catch (\Throwable) { return null; }
	}

	/** Trockenlauf für eine verdeckte Kopie: Dateien direkt aus dem Speicher des Kontos lesen, nichts verändern. */
	private function shadowPlan(string $account, Folder $target, array $shadow, string $storageRoot, string $requested): array {
		$home = $shadow['storage']; $cache = $home->getCache();
		$revealAs = $requested !== '' && !$cache->inCache('files/' . $requested) && !str_contains($requested, '/') ? $requested : $shadow['revealAs'];
		$items = []; $planned = []; $paths = [];
		foreach ($this->managedPaths() as $managed) {
			$internal = 'files/' . trim($managed, '/');
			if (!$cache->inCache($internal)) continue;
			$paths[] = $revealAs . substr(trim($managed, '/'), strlen($storageRoot));
			foreach ($this->cacheFiles($cache, $internal) as $entry) {
				$relative = substr($entry->getPath(), 6);
				$stream = $home->fopen($entry->getPath(), 'r');
				if ($stream === false) throw new \RuntimeException('Datei der verdeckten Kopie nicht lesbar: ' . $relative);
				$context = hash_init('sha256'); hash_update_stream($context, $stream); fclose($stream); $hash = hash_final($context);
				$item = ['uid' => $account, 'sourceId' => (int)$entry->getId(), 'source' => $revealAs . substr($relative, strlen($storageRoot)), 'target' => $relative, 'size' => (int)$entry->getSize(), 'mtime' => (int)$entry->getMTime(), 'hash' => $hash, 'action' => 'COPY'];
				$existingHash = $planned[$relative] ?? $this->existingHash($target, $relative);
				if ($existingHash !== null) {
					if ($existingHash === $hash) $item['action'] = 'SAME';
					else { $item['action'] = 'CONFLICT'; $item['target'] = $this->conflictName($relative, $account, $planned, $target); }
				}
				if ($item['action'] !== 'SAME') $planned[$item['target']] = $hash;
				$items[] = $item;
			}
		}
		$sourceIds = array_fill_keys(array_column($items, 'sourceId'), true);
		return [
			'account' => $account, 'targetRoot' => $account . '/' . $storageRoot, 'currentMode' => $this->installation->storageMode(),
			'targetLocation' => 'TEAMFOLDER', 'sourceFolder' => $revealAs, 'shadowed' => true,
			'warnings' => ['Die bisherige Kopie „' . $storageRoot . '“ im Ablagekonto ist vom gleichnamigen Teamordner verdeckt. Beim Ausführen wird sie zuerst in „' . $revealAs . '“ umbenannt (Datei-IDs und Freigaben bleiben erhalten) und dann in den Teamordner zusammengeführt.'],
			'teamfolderSources' => 0,
			'sources' => [['uid' => $account . '/' . $storageRoot . ' (verdeckt)', 'files' => count($items), 'bytes' => array_sum(array_column($items, 'size')), 'paths' => $paths, 'teamfolder' => false]],
			'files' => count($items), 'bytes' => array_sum(array_column($items, 'size')),
			'copy' => count(array_filter($items, static fn(array $item): bool => $item['action'] === 'COPY')),
			'same' => count(array_filter($items, static fn(array $item): bool => $item['action'] === 'SAME')),
			'conflicts' => array_values(array_map(static fn(array $item): array => ['uid' => $item['uid'], 'source' => $item['source'], 'target' => $item['target']], array_filter($items, static fn(array $item): bool => $item['action'] === 'CONFLICT'))),
			'references' => $this->countReferences($sourceIds),
			'items' => $items,
		];
	}

	/** @return \Generator<\OCP\Files\Cache\ICacheEntry> */
	private function cacheFiles(\OCP\Files\Cache\ICache $cache, string $path): \Generator {
		foreach ($cache->getFolderContents($path) as $entry) {
			if ($entry->getMimeType() === 'httpd/unix-directory') yield from $this->cacheFiles($cache, $entry->getPath());
			else yield $entry;
		}
	}

	private function revealShadowedCopy(string $account, string $revealAs): string {
		$target = $this->rootFolder->getUserFolder($account);
		$storageRoot = trim($this->installation->storageRoot(), '/');
		$shadow = $this->shadowedCopy($target, $this->existingPath($target, $storageRoot), $storageRoot, $account);
		if ($shadow === null) throw new \RuntimeException('Die verdeckte Kopie wurde nicht mehr gefunden.');
		$home = $shadow['storage']; $cache = $home->getCache();
		if ($cache->inCache('files/' . $revealAs) || $home->file_exists('files/' . $revealAs)) $revealAs = $shadow['revealAs'];
		if (!$home->rename($shadow['path'], 'files/' . $revealAs)) throw new \RuntimeException('Die verdeckte Kopie konnte nicht umbenannt werden.');
		$cache->move($shadow['path'], 'files/' . $revealAs);
		$home->getPropagator()->propagateChange('files/' . $revealAs, time());
		$this->logger->info('Verdeckte Ablage-Kopie sichtbar gemacht.', ['account' => $account, 'folder' => $revealAs]);
		return $revealAs;
	}

	private function rootListing(Folder $target): string {
		try { return implode(', ', array_map(static fn($node): string => '„' . $node->getName() . '“', $target->getDirectoryListing())) ?: '(leer)'; } catch (\Throwable) { return '(nicht lesbar)'; }
	}

	/** Ersetzt den führenden Pfadteil $from durch $to; null, wenn $path nicht unter $from liegt. */
	private function remap(string $path, string $from, string $to): ?string {
		$path = trim($path, '/'); $from = trim($from, '/'); $to = trim($to, '/');
		if ($path === $from) return $to;
		if (!str_starts_with($path, $from . '/')) return null;
		return ($to === '' ? '' : $to . '/') . substr($path, strlen($from) + 1);
	}

	/**
	 * Ordner neben dem Arbeitsordner, die wie eine verdrängte Ablage aussehen („Bestatter (1)“ …): Teamordner
	 * benennen einen gleichnamigen eigenen Ordner beim Einbinden so um.
	 * @return list<string>
	 */
	private function leftoverCopies(Folder $target, ?Folder $targetRoot, string $storageRoot, string $account): array {
		if ($targetRoot === null || $this->storage->locationOf($targetRoot, $account) !== 'TEAMFOLDER') return [];
		$parentPath = str_contains($storageRoot, '/') ? dirname($storageRoot) : '';
		$parent = $parentPath === '' ? $target : $this->existingPath($target, $parentPath);
		if ($parent === null) return [];
		$name = basename($storageRoot); $result = [];
		foreach ($parent->getDirectoryListing() as $node) {
			if (!$node instanceof Folder || !preg_match('/^' . preg_quote($name, '/') . ' \\(\\d+\\)$/u', $node->getName())) continue;
			if ($this->storage->locationOf($node, $account) !== 'OWN') continue;
			if ($this->existingPath($node, (string)$this->remap($this->installation->casesPath(), $storageRoot, '')) === null) continue;
			$result[] = ($parentPath === '' ? '' : $parentPath . '/') . $node->getName();
		}
		return $result;
	}

	private function existingPath(Folder $base, string $relativePath): ?Folder {
		$folder = $base;
		foreach (explode('/', trim($relativePath, '/')) as $name) {
			if ($name === '') continue;
			if (!$folder->nodeExists($name)) return null;
			$node = $folder->get($name);
			if (!$node instanceof Folder) return null;
			$folder = $node;
		}
		return $folder;
	}
}
