<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;

/** Konsistenter App-Datensnapshot. Nextcloud-Dateien und Systemkonfiguration sind separat zu sichern. */
class BackupService {
	private const MANAGED_FILE_PATTERN = '/^bestatter-(?:backup|package)-\d{8}-\d{6}(?:-\d+)?\.(?:json|zip)$/';
	private const DEFAULT_DIRECTORY = 'bestatter-backups';
	private const DEFAULT_SNAPSHOT_RETENTION_DAYS = 30;
	private const DEFAULT_PACKAGE_RETENTION_DAYS = 90;
	private const TABLES = [
		'bestatter_choice_lists','bestatter_choice_items','bestatter_checklist_templates','bestatter_checklist_items',
		'bestatter_articles','bestatter_article_groups','bestatter_article_components','bestatter_workflows','bestatter_workflow_runs',
		'bestatter_branches','bestatter_document_templates','bestatter_dereg_templates','bestatter_invoice_settings','bestatter_invoice_sequences',
		'bestatter_cases','bestatter_records','bestatter_case_services','bestatter_commercial_docs','bestatter_invoices','bestatter_invoice_items',
		'bestatter_audit_log','bestatter_assistant_rules','bestatter_incoming_invoices','bestatter_incoming_items',
		'bestatter_schedule_types','bestatter_resources','bestatter_schedule_history','bestatter_country_profiles',
		'bestatter_capture_imports','bestatter_external_documents','bestatter_integration_jobs',
	];

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private AuditService $audit,
		private IRootFolder $rootFolder,
		private IGroupManager $groups,
		private IUserSession $userSession,
		private InstallationConfigService $installationConfig,
	) {}

	public function create(?string $target = null): array {
		$target = $target ?: $this->nextManagedPath();
		$tables = []; foreach (self::TABLES as $table) $tables[$table] = $this->rows($table);
		$appConfig = []; foreach ($this->config->getAppKeys(Application::APP_ID) as $key) $appConfig[$key] = $this->config->getAppValue(Application::APP_ID, $key, '');
		$payload = ['format'=>'bestatter-backup/1','appVersion'=>Application::VERSION,'createdAt'=>date(DATE_ATOM),'tables'=>$tables,'appConfig'=>$appConfig];
		$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$envelope = ['sha256'=>hash('sha256',$payloadJson),'payload'=>$payload];
		$json = json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
		$directory = dirname($target); if (!is_dir($directory) || !is_writable($directory)) throw new \RuntimeException('Das Sicherungsverzeichnis ist nicht beschreibbar: ' . $directory);
		$temp = $target . '.tmp'; if (file_put_contents($temp,$json,LOCK_EX) === false || !rename($temp,$target)) throw new \RuntimeException('Der App-Datensnapshot konnte nicht geschrieben werden.');
		file_put_contents($target . '.sha256', hash_file('sha256',$target) . '  ' . basename($target) . PHP_EOL, LOCK_EX);
		$this->audit->logSystem('BACKUP','CREATED',['path'=>$target,'sha256'=>$envelope['sha256'],'tables'=>array_map('count',$tables)]);
		return ['path'=>$target,'checksumFile'=>$target.'.sha256','payloadSha256'=>$envelope['sha256'],'tables'=>array_map('count',$tables),'scope'=>'App-Datenbank und App-Konfiguration; Dateien, Datenbankserver und Nextcloud-Konfiguration separat sichern'];
	}

	/** Erstellt ein portables Fachpaket aus Datenbanksnapshot und logischen Nextcloud-Dateien. */
	public function createPackage(): array {
		if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('Die PHP-Erweiterung ZipArchive ist für Sicherungspakete erforderlich.');
		$target = $this->nextManagedPath('package', 'zip');
		$snapshotPath = preg_replace('/\.zip$/', '.snapshot.json', $target);
		$snapshot = $this->create($snapshotPath);
		$zip = new \ZipArchive();
		if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) throw new \RuntimeException('Das Sicherungspaket konnte nicht angelegt werden.');
		$manifest = [
			'format' => 'bestatter-package/1', 'appVersion' => Application::VERSION, 'createdAt' => date(DATE_ATOM),
			'database' => ['path' => 'snapshot.json', 'sha256' => hash_file('sha256', $snapshotPath)],
			'files' => [], 'errors' => [],
		];
		try {
			$zip->addFile($snapshotPath, 'snapshot.json');
			$seenRoots = [];
			foreach ($this->backupRoots() as $root) {
				try {
					$userFolder = $this->rootFolder->getUserFolder($root['uid']);
					$folder = $this->existingPath($userFolder, $root['path']);
					if ($folder === null) continue;
					$rootId = (string)$folder->getId();
					if (isset($seenRoots[$rootId])) continue;
					$seenRoots[$rootId] = true;
					$this->addFolderToPackage($zip, $folder, 'files/' . $this->safeSegment($root['uid']) . '/' . trim($root['path'], '/'), $manifest);
				} catch (\Throwable $error) {
					$manifest['errors'][] = ['root' => $root['uid'] . '/' . $root['path'], 'message' => $error->getMessage()];
				}
			}
			$manifest['status'] = $manifest['errors'] === [] ? 'OK' : 'WARN';
			$manifest['fileCount'] = count($manifest['files']);
			$manifest['totalBytes'] = array_sum(array_column($manifest['files'], 'size'));
			$zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
			if (!$zip->close()) throw new \RuntimeException('Das Sicherungspaket konnte nicht abgeschlossen werden.');
		} catch (\Throwable $error) {
			$zip->close(); @unlink($target); throw $error;
		} finally {
			@unlink($snapshotPath); @unlink($snapshotPath . '.sha256');
		}
		$checksum = hash_file('sha256', $target);
		file_put_contents($target . '.sha256', $checksum . '  ' . basename($target) . PHP_EOL, LOCK_EX);
		$this->audit->logSystem('BACKUP_PACKAGE', 'CREATED', ['path' => $target, 'sha256' => $checksum, 'status' => $manifest['status'], 'files' => $manifest['fileCount'], 'bytes' => $manifest['totalBytes']]);
		return ['path' => $target, 'checksumFile' => $target . '.sha256', 'sha256' => $checksum, 'status' => $manifest['status'], 'fileCount' => $manifest['fileCount'], 'totalBytes' => $manifest['totalBytes'], 'errors' => $manifest['errors'], 'scope' => 'App-Datenbank, App-Konfiguration, Fallakten, Dokumentvorlagen und Artikelbilder'];
	}

	/** @return array{directory:string,scope:string,targetEmpty:bool,items:list<array<string,mixed>>} */
	public function managedSnapshots(): array {
		$directory = $this->managedDirectory();
		$paths = [];
		foreach ($this->managedDirectories() as $candidate) $paths = [...$paths, ...(glob($candidate . '/bestatter-backup-*.json') ?: []), ...(glob($candidate . '/bestatter-package-*.zip') ?: [])];
		$paths = array_values(array_unique($paths));
		usort($paths, static fn(string $left, string $right): int => (filemtime($right) ?: 0) <=> (filemtime($left) ?: 0));
		$settings = $this->settings();
		$items = [];
		foreach (array_slice($paths, 0, 100) as $path) {
			$name = basename($path);
			if (!$this->isManagedName($name)) continue;
			$isPackage = str_ends_with($name, '.zip');
			$retentionDays = $isPackage ? $settings['packageRetentionDays'] : $settings['snapshotRetentionDays'];
			$ageDays = max(0, (int)floor((time() - (filemtime($path) ?: time())) / 86400));
			$item = [
				'name' => $name,
				'path' => $path,
				'location' => dirname($path) === $directory ? 'ACTIVE' : 'LEGACY',
				'size' => filesize($path) ?: 0,
				'modifiedAt' => date(DATE_ATOM, filemtime($path) ?: time()),
				'checksumFilePresent' => is_file($path . '.sha256'),
				'ageDays' => $ageDays,
				'expired' => $retentionDays > 0 && $ageDays >= $retentionDays,
			];
			try {
				$inspection = str_ends_with($name, '.zip') ? $this->inspectPackage($path, false) : $this->inspect($path, false);
				$item += [
					'valid' => true,
					'kind' => str_ends_with($name, '.zip') ? 'PACKAGE' : 'SNAPSHOT',
					'appVersion' => (string)$inspection['appVersion'],
					'createdAt' => (string)$inspection['createdAt'],
					'payloadSha256' => (string)($inspection['payloadSha256'] ?? $inspection['sha256'] ?? ''),
					'tables' => $inspection['tables'] ?? [],
					'fileCount' => (int)($inspection['fileCount'] ?? 0),
					'totalBytes' => (int)($inspection['totalBytes'] ?? 0),
					'packageStatus' => (string)($inspection['status'] ?? 'OK'),
				];
			} catch (\Throwable $error) {
				$item += ['valid' => false, 'error' => $error->getMessage()];
			}
			$items[] = $item;
		}
		return [
			'directory' => $directory,
			'legacyDirectory' => $this->dataDirectory(),
			'settings' => $settings,
			'scope' => 'JSON-Snapshot: App-Datenbank und App-Konfiguration. ZIP-Fachpaket: zusätzlich Fallakten, Dokumentvorlagen und Artikelbilder.',
			'targetEmpty' => $this->targetEmpty(),
			'items' => $items,
		];
	}

	public function settings(): array {
		return [
			'directory' => $this->config->getAppValue(Application::APP_ID, 'backup_directory', self::DEFAULT_DIRECTORY),
			'snapshotRetentionDays' => max(0, (int)$this->config->getAppValue(Application::APP_ID, 'backup_snapshot_retention_days', (string)self::DEFAULT_SNAPSHOT_RETENTION_DAYS)),
			'packageRetentionDays' => max(0, (int)$this->config->getAppValue(Application::APP_ID, 'backup_package_retention_days', (string)self::DEFAULT_PACKAGE_RETENTION_DAYS)),
		];
	}

	public function saveSettings(array $input): array {
		$directory = trim(str_replace('\\', '/', (string)($input['directory'] ?? self::DEFAULT_DIRECTORY)), '/');
		if ($directory === '') throw new \InvalidArgumentException('Der Sicherungsunterordner darf nicht leer sein.');
		foreach (explode('/', $directory) as $segment) if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/[\x00-\x1F\x7F]/u', $segment)) throw new \InvalidArgumentException('Der Sicherungsunterordner enthält einen ungültigen Pfadbestandteil.');
		$snapshotDays = max(0, min(3650, (int)($input['snapshotRetentionDays'] ?? self::DEFAULT_SNAPSHOT_RETENTION_DAYS)));
		$packageDays = max(0, min(3650, (int)($input['packageRetentionDays'] ?? self::DEFAULT_PACKAGE_RETENTION_DAYS)));
		$this->config->setAppValue(Application::APP_ID, 'backup_directory', $directory);
		$this->config->setAppValue(Application::APP_ID, 'backup_snapshot_retention_days', (string)$snapshotDays);
		$this->config->setAppValue(Application::APP_ID, 'backup_package_retention_days', (string)$packageDays);
		$this->managedDirectory();
		$this->audit->logSystem('BACKUP_SETTINGS', 'UPDATED', ['directory' => $directory, 'snapshotRetentionDays' => $snapshotDays, 'packageRetentionDays' => $packageDays]);
		return $this->settings();
	}

	public function deleteManaged(string $name): array {
		$path = $this->managedPath($name);
		if (str_ends_with($name, '.zip')) {
			$validTarget = false;
			try { $validTarget = ($this->inspectPackage($path, false)['status'] ?? 'WARN') === 'OK'; } catch (\Throwable) {}
			if ($validTarget && $this->validPackageCount() <= 1) throw new \RuntimeException('Das letzte gültige vollständige Sicherungspaket darf nicht gelöscht werden. Erstellen Sie zuerst ein neues Paket.');
		}
		$size = filesize($path) ?: 0;
		if (!unlink($path)) throw new \RuntimeException('Die Sicherungsdatei konnte nicht gelöscht werden.');
		$checksumDeleted = !is_file($path . '.sha256') || unlink($path . '.sha256');
		$this->audit->logSystem('BACKUP', 'DELETED', ['name' => $name, 'size' => $size, 'checksumDeleted' => $checksumDeleted]);
		return ['deleted' => true, 'name' => $name, 'checksumDeleted' => $checksumDeleted];
	}

	public function deleteExpired(): array {
		$deleted = []; $skipped = [];
		foreach ($this->managedSnapshots()['items'] as $item) {
			if (!($item['expired'] ?? false)) continue;
			try { $deleted[] = $this->deleteManaged((string)$item['name']); }
			catch (\Throwable $error) { $skipped[] = ['name' => $item['name'], 'message' => $error->getMessage()]; }
		}
		return ['deleted' => $deleted, 'skipped' => $skipped, 'remaining' => $this->managedSnapshots()['items']];
	}

	/** @return array<string,mixed> */
	public function inspectManaged(string $name): array {
		$path = $this->managedPath($name);
		$inspection = str_ends_with($name, '.zip') ? $this->inspectPackage($path) : $this->inspect($path);
		unset($inspection['payload']);
		return $inspection;
	}

	public function readManaged(string $name): string {
		$path = $this->managedPath($name);
		str_ends_with($name, '.zip') ? $this->inspectPackage($path) : $this->inspect($path);
		$content = file_get_contents($path);
		if ($content === false) throw new \RuntimeException('Die Sicherungsdatei konnte nicht gelesen werden.');
		return $content;
	}

	/** @return array<string,mixed> */
	public function inspectPackage(string $path, bool $includeTargetState = true): array {
		if (!is_file($path) || !is_readable($path)) throw new \InvalidArgumentException('Das Sicherungspaket ist nicht lesbar.');
		$zip = new \ZipArchive(); if ($zip->open($path) !== true) throw new \InvalidArgumentException('Das Sicherungspaket ist kein lesbares ZIP-Archiv.');
		try {
			$manifestJson = $zip->getFromName('manifest.json'); $snapshotJson = $zip->getFromName('snapshot.json');
			if ($manifestJson === false || $snapshotJson === false) throw new \InvalidArgumentException('Manifest oder Datenbanksnapshot fehlt im Sicherungspaket.');
			$manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
			if (($manifest['format'] ?? '') !== 'bestatter-package/1') throw new \InvalidArgumentException('Das Sicherungspaketformat wird nicht unterstützt.');
			if (!hash_equals((string)($manifest['database']['sha256'] ?? ''), hash('sha256', $snapshotJson))) throw new \RuntimeException('Der eingebettete Datenbanksnapshot wurde verändert.');
			foreach ((array)($manifest['files'] ?? []) as $entry) {
				$stream = $zip->getStream((string)$entry['archivePath']);
				if (!is_resource($stream)) throw new \RuntimeException('Eine gesicherte Datei fehlt: ' . (string)$entry['archivePath']);
				$context = hash_init('sha256'); hash_update_stream($context, $stream); fclose($stream);
				if (!hash_equals((string)$entry['sha256'], hash_final($context))) throw new \RuntimeException('Prüfsummenfehler bei ' . (string)$entry['archivePath']);
			}
			$snapshotEnvelope = json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR);
			$tables = []; foreach ((array)($snapshotEnvelope['payload']['tables'] ?? []) as $table => $rows) $tables[$table] = is_array($rows) ? count($rows) : 0;
			return ['valid' => true, 'format' => $manifest['format'], 'appVersion' => $manifest['appVersion'] ?? '', 'createdAt' => $manifest['createdAt'] ?? '', 'sha256' => hash_file('sha256', $path), 'status' => $manifest['status'] ?? 'OK', 'fileCount' => count((array)($manifest['files'] ?? [])), 'totalBytes' => (int)($manifest['totalBytes'] ?? 0), 'errors' => (array)($manifest['errors'] ?? []), 'tables' => $tables, 'targetEmpty' => $includeTargetState ? $this->targetEmpty() : null, 'manifest' => $manifest];
		} finally { $zip->close(); }
	}

	public function inspect(string $path, bool $includeTargetState = true): array {
		if (!is_file($path) || !is_readable($path)) throw new \InvalidArgumentException('Die Sicherungsdatei ist nicht lesbar.');
		$envelope=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR); $payload=$envelope['payload']??null;
		if (!is_array($payload) || ($payload['format']??'')!=='bestatter-backup/1') throw new \InvalidArgumentException('Das Sicherungsformat wird nicht unterstützt.');
		$actual=hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
		if (!hash_equals((string)($envelope['sha256']??''),$actual)) throw new \RuntimeException('Die Sicherungsdatei ist beschädigt oder wurde verändert.');
		$counts=[]; foreach(($payload['tables']??[]) as $table=>$rows)$counts[$table]=is_array($rows)?count($rows):0;
		return ['valid'=>true,'format'=>$payload['format'],'appVersion'=>$payload['appVersion']??'','createdAt'=>$payload['createdAt']??'','payloadSha256'=>$actual,'tables'=>$counts,'targetEmpty'=>$includeTargetState ? $this->targetEmpty() : null,'payload'=>$payload];
	}

	public function restore(string $path): array {
		$inspection=$this->inspect($path); if(!$inspection['targetEmpty']) throw new \RuntimeException('Restore abgebrochen: Das Ziel enthält bereits Bestatter-Daten. Wiederherstellung nur in eine leere, isolierte Zielinstallation.');
		$payload=$inspection['payload']; $inserted=[]; $this->db->beginTransaction();
		try {
			foreach(self::TABLES as $table){$rows=$payload['tables'][$table]??[];$inserted[$table]=0;foreach($rows as $row){$q=$this->db->getQueryBuilder();$values=[];foreach($row as $column=>$value)$values[$column]=$q->createNamedParameter($value);$q->insert($table)->values($values)->executeStatement();$inserted[$table]++;}}
			foreach(($payload['appConfig']??[]) as $key=>$value)$this->config->setAppValue(Application::APP_ID,(string)$key,(string)$value);
			$this->db->commit();
		}catch(\Throwable $error){$this->db->rollBack();throw new \RuntimeException('Restore wurde vollständig zurückgerollt: '.$error->getMessage(),0,$error);}
		$this->audit->logSystem('RESTORE','COMPLETED',['source'=>$path,'sha256'=>$inspection['payloadSha256'],'inserted'=>$inserted]);
		return ['restored'=>true,'source'=>$path,'inserted'=>$inserted,'verification'=>'Anschließend occ maintenance:repair und bestatter:acceptance-check ausführen.'];
	}

	/** Restauriert ein geprüftes Fachpaket ausschließlich in leere App-Tabellen und ohne Dateikollisionen. */
	public function restorePackage(string $path): array {
		$inspection = $this->inspectPackage($path);
		if (!$inspection['targetEmpty']) throw new \RuntimeException('Restore abgebrochen: Das Ziel enthält bereits Bestatter-Daten.');
		if (($inspection['status'] ?? 'WARN') !== 'OK' || ($inspection['errors'] ?? []) !== []) throw new \RuntimeException('Restore abgebrochen: Das Sicherungspaket wurde nicht vollständig erstellt.');
		$zip = new \ZipArchive(); if ($zip->open($path) !== true) throw new \RuntimeException('Das Sicherungspaket konnte nicht geöffnet werden.');
		$manifest = $inspection['manifest']; $targets = [];
		try {
			foreach ((array)$manifest['files'] as $entry) {
				$uid = $this->safeSegment((string)$entry['ownerUid']); $relative = trim((string)$entry['relativePath'], '/');
				$segments = array_values(array_filter(explode('/', $relative), static fn(string $value): bool => $value !== ''));
				foreach ($segments as $segment) $this->safeSegment($segment);
				if ($segments === []) throw new \RuntimeException('Leerer Wiederherstellungspfad im Manifest.');
				$fileName = array_pop($segments); $folder = $this->rootFolder->getUserFolder($uid);
				foreach ($segments as $segment) { $node = $folder->nodeExists($segment) ? $folder->get($segment) : $folder->newFolder($segment); if (!$node instanceof Folder) throw new \RuntimeException('Wiederherstellungspfad kollidiert mit einer Datei: ' . $relative); $folder = $node; }
				if ($folder->nodeExists($fileName)) throw new \RuntimeException('Restore abgebrochen: Zieldatei existiert bereits: ' . $uid . '/' . $relative);
				$targets[] = ['folder' => $folder, 'name' => $fileName, 'archivePath' => (string)$entry['archivePath']];
			}
			$created = [];
			try {
				foreach ($targets as $target) { $content = $zip->getFromName($target['archivePath']); if ($content === false) throw new \RuntimeException('Datei fehlt im Paket: ' . $target['archivePath']); $file = $target['folder']->newFile($target['name']); $file->putContent($content); $created[] = $file; }
				$snapshot = $zip->getFromName('snapshot.json'); if ($snapshot === false) throw new \RuntimeException('Datenbanksnapshot fehlt im Paket.');
				$temp = tempnam($this->managedDirectory(), 'bestatter-restore-'); if ($temp === false) throw new \RuntimeException('Temporäre Restore-Datei konnte nicht angelegt werden.');
				try { file_put_contents($temp, $snapshot, LOCK_EX); $database = $this->restore($temp); } finally { @unlink($temp); }
			} catch (\Throwable $error) { foreach (array_reverse($created) as $file) try { $file->delete(); } catch (\Throwable) {} throw $error; }
		} finally { $zip->close(); }
		$this->audit->logSystem('BACKUP_PACKAGE', 'RESTORED', ['source' => $path, 'files' => count($targets)]);
		return ['restored' => true, 'source' => $path, 'files' => count($targets), 'database' => $database, 'verification' => 'Anschließend occ maintenance:repair und bestatter:acceptance-check ausführen.'];
	}

	private function rows(string $table): array {$q=$this->db->getQueryBuilder();return $q->select('*')->from($table)->orderBy('id','ASC')->executeQuery()->fetchAllAssociative();}
	private function targetEmpty(): bool { foreach(self::TABLES as $table){$q=$this->db->getQueryBuilder();if((int)$q->select($q->func()->count('id','count'))->from($table)->executeQuery()->fetchOne()>0)return false;}return true; }
	private function dataDirectory(): string { return rtrim((string)$this->config->getSystemValue('datadirectory', sys_get_temp_dir()), '/\\'); }
	private function managedDirectory(): string {
		$relative = trim(str_replace('\\', '/', $this->settings()['directory']), '/');
		$directory = $this->dataDirectory() . '/' . $relative;
		if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new \RuntimeException('Das Sicherungsverzeichnis konnte nicht angelegt werden: ' . $directory);
		$resolvedData = realpath($this->dataDirectory()); $resolvedDirectory = realpath($directory);
		if ($resolvedData === false || $resolvedDirectory === false || !str_starts_with($resolvedDirectory . DIRECTORY_SEPARATOR, $resolvedData . DIRECTORY_SEPARATOR)) throw new \RuntimeException('Der Sicherungsordner muss innerhalb des Nextcloud-Datenverzeichnisses liegen.');
		if (!is_writable($directory)) throw new \RuntimeException('Das Sicherungsverzeichnis ist nicht beschreibbar: ' . $directory);
		return $resolvedDirectory;
	}
	private function managedDirectories(): array { return array_values(array_unique([$this->managedDirectory(), $this->dataDirectory()])); }
	private function nextManagedPath(string $kind = 'backup', string $extension = 'json'): string {
		$base = $this->managedDirectory() . '/bestatter-' . $kind . '-' . date('Ymd-His');
		$path = $base . '.' . $extension; $suffix = 1;
		while (file_exists($path)) { $path = $base . '-' . $suffix . '.' . $extension; $suffix++; }
		return $path;
	}
	private function isManagedName(string $name): bool { return preg_match(self::MANAGED_FILE_PATTERN, $name) === 1; }
	private function managedPath(string $name): string {
		if (!$this->isManagedName($name)) throw new \InvalidArgumentException('Ungültiger Sicherungsdateiname.');
		foreach ($this->managedDirectories() as $directory) { $path = $directory . '/' . $name; if (is_file($path)) return $path; }
		throw new \InvalidArgumentException('Die Sicherungsdatei wurde nicht gefunden.');
	}
	private function validPackageCount(): int {
		$count = 0;
		foreach ($this->managedDirectories() as $directory) foreach (glob($directory . '/bestatter-package-*.zip') ?: [] as $path) try { if (($this->inspectPackage($path, false)['status'] ?? 'WARN') === 'OK') $count++; } catch (\Throwable) {}
		return $count;
	}
	/** @return list<array{uid:string,path:string}> */
	private function backupRoots(): array {
		$uids = []; foreach (array_unique([...$this->installationConfig->adminGroups(), $this->installationConfig->memberGroup()]) as $groupName) { $group = $this->groups->get($groupName); if ($group !== null) foreach ($group->getUsers() as $user) $uids[$user->getUID()] = true; }
		$currentUid = $this->userSession->getUser()?->getUID(); if ($currentUid !== null) $uids[$currentUid] = true;
		$paths = [$this->installationConfig->casesPath(), $this->installationConfig->templatesPath(), $this->installationConfig->storageRoot() . '/' . $this->installationConfig->articleImagesFolder()];
		$result = []; foreach (array_keys($uids) as $uid) foreach (array_unique($paths) as $path) $result[] = ['uid' => (string)$uid, 'path' => trim($path, '/')];
		return $result;
	}
	private function existingPath(Folder $base, string $relativePath): ?Folder {
		$folder = $base; foreach (explode('/', trim($relativePath, '/')) as $name) { if ($name === '' || !$folder->nodeExists($name)) return null; $node = $folder->get($name); if (!$node instanceof Folder) return null; $folder = $node; } return $folder;
	}
	private function addFolderToPackage(\ZipArchive $zip, Folder $folder, string $archiveRoot, array &$manifest): void {
		$zip->addEmptyDir($archiveRoot);
		foreach ($folder->getDirectoryListing() as $node) {
			$archivePath = trim($archiveRoot . '/' . $this->safeSegment($node->getName()), '/');
			if ($node instanceof Folder) { $this->addFolderToPackage($zip, $node, $archivePath, $manifest); continue; }
			if (!$node instanceof File) continue;
			try {
				$content = $node->getContent();
				if (!$zip->addFromString($archivePath, $content)) throw new \RuntimeException('Datei konnte nicht in das ZIP geschrieben werden.');
				$manifest['files'][] = ['archivePath' => $archivePath, 'ownerUid' => explode('/', $archivePath, 3)[1] ?? '', 'relativePath' => preg_replace('#^files/[^/]+/#', '', $archivePath), 'size' => strlen($content), 'mtime' => $node->getMTime(), 'mimeType' => $node->getMimeType(), 'sha256' => hash('sha256', $content)];
			} catch (\Throwable $error) { $manifest['errors'][] = ['file' => $archivePath, 'message' => $error->getMessage()]; }
		}
	}
	private function safeSegment(string $segment): string {
		$segment = str_replace('\\', '_', trim($segment, '/'));
		if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, '/')) throw new \RuntimeException('Unsicherer Dateipfad im Sicherungsumfang.');
		return $segment;
	}
}
