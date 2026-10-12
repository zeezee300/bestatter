<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\Server;

/**
 * One-time development reset. Configuration and master data are deliberately
 * excluded. The command wrapper requires an explicit confirmation token.
 */
class DevelopmentResetService {
	private const OPERATIONAL_TABLES = [
		'bestatter_integration_jobs',
		'bestatter_capture_imports',
		'bestatter_order_form_entries',
		'bestatter_external_documents',
		'bestatter_incoming_items',
		'bestatter_incoming_invoices',
		'bestatter_invoice_items',
		'bestatter_invoices',
		'bestatter_commercial_docs',
		'bestatter_action_runs',
		'bestatter_process_runs',
		'bestatter_audit_log',
		'bestatter_case_services',
		'bestatter_side_orders',
		'bestatter_mail_outbox',
		'bestatter_dereg_mail_outbox',
		'bestatter_record_assignees',
		'bestatter_case_labels',
		'bestatter_records',
		'bestatter_cases',
		'bestatter_invoice_sequences',
	];

	public function __construct(
		private IDBConnection $db,
		private IRootFolder $rootFolder,
		private IGroupManager $groupManager,
		private IConfig $config,
		private InstallationConfigService $installationConfig,
		private CaseStorageService $storage,
	) {}

	public function preview(): array {
		$records = $this->recordRows();
		return [
			'tables' => $this->tableCounts(),
			'recordTypes' => $this->recordTypeCounts(),
			'remoteCalendarObjects' => count($this->calendarTargets($records)),
			'caseFolders' => $this->caseFolders(),
			'preserved' => [
				'Niederlassungen und Bankdaten', 'Artikel und Artikelgruppen', 'Wertelisten',
				'Ablaufvorlagen', 'Dokument- und Abmeldevorlagen', 'Rechnungseinstellungen',
			],
		];
	}

	/**
	 * Zusätzlich zum Bestätigungscode im Kommando: Der Reset ist nur ausführbar, wenn der
	 * Betreiber diese Instanz explizit als Entwicklungs-/Testsystem markiert hat. Ohne diese
	 * bewusste, separate Aktivierung (Standard: deaktiviert) bleibt --execute wirkungslos,
	 * selbst mit korrektem Bestätigungscode – das verhindert eine versehentliche Ausführung
	 * gegen eine Produktivinstanz allein durch ein falsch abgeschicktes Kommando/Skript.
	 */
	private function requireDevelopmentInstance(): void {
		$flag = strtolower(trim($this->config->getAppValue(\OCA\Bestatter\AppInfo\Application::APP_ID, 'development_reset_enabled', 'no')));
		if (!in_array($flag, ['1', 'yes', 'true', 'on'], true)) {
			throw new \RuntimeException(
				'Der Entwicklungsreset ist auf dieser Instanz deaktiviert. Diese Instanz muss zuerst ausdrücklich als '
				. 'Entwicklungs-/Testsystem markiert werden: occ config:app:set bestatter development_reset_enabled --value=yes '
				. '(niemals auf einer Produktivinstanz setzen).'
			);
		}
	}

	public function reset(?string $backupPath = null): array {
		$this->requireDevelopmentInstance();
		$records = $this->recordRows();
		$backupPath = $this->backup($backupPath);
		$calendar = $this->deleteCalendarObjects($records);
		$folders = $this->deleteCaseFolders();
		$folders['captureInboxes'] = $this->deleteCaptureInboxes();
		$deleted = [];
		$this->db->beginTransaction();
		try {
			foreach (self::OPERATIONAL_TABLES as $table) {
				$query = $this->db->getQueryBuilder();
				$deleted[$table] = $query->delete($table)->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->safeRollback();
			throw new \RuntimeException('Die Datenbank wurde nicht zurückgesetzt. Die Sicherung liegt unter ' . $backupPath . '.', 0, $error);
		}
		return [
			'backupPath' => $backupPath,
			'deletedRows' => $deleted,
			'calendar' => $calendar,
			'folders' => $folders,
			'remaining' => $this->tableCounts(),
		];
	}

	private function backup(?string $requestedPath): string {
		$path = trim((string)$requestedPath);
		if ($path === '') {
			$dataDirectory = rtrim((string)$this->config->getSystemValueString('datadirectory'), '/\\');
			$path = $dataDirectory . DIRECTORY_SEPARATOR . 'bestatter-development-reset-' . date('Ymd-His') . '.json';
		}
		$directory = dirname($path);
		if (!is_dir($directory) || !is_writable($directory)) throw new \RuntimeException('Das Sicherungsverzeichnis ist nicht beschreibbar: ' . $directory);
		if (file_exists($path)) throw new \RuntimeException('Die Sicherungsdatei existiert bereits: ' . $path);
		$data = [
			'createdAt' => date('c'),
			'appVersion' => \OCA\Bestatter\AppInfo\Application::VERSION,
			'tables' => [],
		];
		foreach (self::OPERATIONAL_TABLES as $table) {
			$query = $this->db->getQueryBuilder();
			$data['tables'][$table] = $query->select('*')->from($table)->executeQuery()->fetchAllAssociative();
		}
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		if (file_put_contents($path, $json, LOCK_EX) === false) throw new \RuntimeException('Die Sicherungsdatei konnte nicht geschrieben werden.');
		@chmod($path, 0600);
		return $path;
	}

	private function deleteCalendarObjects(array $records): array {
		$targets = $this->calendarTargets($records);
		$deleted = 0; $alreadyMissing = 0; $errors = [];
		foreach ($targets as $target) {
			try {
				$this->calDavBackend()->deleteCalendarObject((int)$target['calendarId'], (string)$target['uri'], 0, true);
				$deleted++;
			} catch (\Throwable $error) {
				$message = $error->getMessage();
				if (str_contains(strtolower($message), 'not found') || str_contains($message, '404')) $alreadyMissing++;
				else $errors[] = ['calendarId' => $target['calendarId'], 'uri' => $target['uri'], 'message' => $message];
			}
		}
		return ['planned' => count($targets), 'deleted' => $deleted, 'alreadyMissing' => $alreadyMissing, 'errors' => $errors];
	}

	private function calendarTargets(array $records): array {
		$targets = [];
		foreach ($records as $record) {
			$this->addCalendarTarget($targets, (int)($record['nextcloud_calendar_key'] ?? 0), (string)($record['nextcloud_uri'] ?? ''));
			$payload = json_decode((string)($record['payload'] ?? '{}'), true) ?: [];
			$nextcloud = (array)($payload['nextcloud'] ?? []);
			$this->addCalendarTarget($targets, (int)($nextcloud['calendarKey'] ?? 0), (string)($nextcloud['uri'] ?? ''));
			foreach ((array)($payload['nextcloudCopies'] ?? []) as $copy) {
				$this->addCalendarTarget($targets, (int)($copy['calendarKey'] ?? 0), (string)($copy['uri'] ?? ''));
			}
		}
		return array_values($targets);
	}

	private function addCalendarTarget(array &$targets, int $calendarId, string $uri): void {
		if ($calendarId <= 0 || trim($uri) === '') return;
		$targets[$calendarId . ':' . $uri] = ['calendarId' => $calendarId, 'uri' => $uri];
	}

	private function deleteCaseFolders(): array { return $this->deleteManaged($this->installationConfig->casesPath()); }

	private function deleteCaptureInboxes(): array { return $this->deleteManaged($this->installationConfig->captureInboxPath()); }

	/** 0.70.0: zentrale Ablage und persönliche Altbestände, freigegebene Ordner nur einmal. */
	private function deleteManaged(string $relativePath): array {
		$result = ['deleted' => [], 'missing' => [], 'errors' => []];
		try { $entries = $this->storage->existingFolders($relativePath); } catch (\Throwable $error) { $result['errors'][] = ['path' => $relativePath, 'message' => $error->getMessage()]; return $result; }
		if ($entries === []) $result['missing'][] = $relativePath;
		foreach ($entries as $entry) {
			try { $entry['folder']->delete(); $result['deleted'][] = $entry['label']; } catch (\Throwable $error) { $result['errors'][] = ['path' => $entry['label'], 'message' => $error->getMessage()]; }
		}
		return $result;
	}

	private function caseFolders(): array {
		try { return array_map(static fn(array $entry): string => $entry['label'], $this->storage->existingFolders($this->installationConfig->casesPath())); } catch (\Throwable) { return []; }
	}

	private function bestatterUserIds(): array {
		$uids = [];
		foreach (array_unique([$this->installationConfig->memberGroup(), ...$this->installationConfig->adminGroups()]) as $groupName) {
			$group = $this->groupManager->get($groupName);
			if ($group === null) continue;
			foreach ($group->getUsers() as $user) $uids[$user->getUID()] = $user->getUID();
		}
		$uids = array_values($uids);
		sort($uids, SORT_NATURAL | SORT_FLAG_CASE);
		return $uids;
	}

	private function existingPath(Folder $base, string $relativePath): ?Folder {
		$folder = $base;
		foreach (explode('/', trim($relativePath, '/')) as $name) {
			if ($name === '' || !$folder->nodeExists($name)) return null;
			$node = $folder->get($name);
			if (!$node instanceof Folder) return null;
			$folder = $node;
		}
		return $folder;
	}

	private function tableCounts(): array {
		$result = [];
		foreach (self::OPERATIONAL_TABLES as $table) {
			$query = $this->db->getQueryBuilder();
			$result[$table] = (int)$query->select($query->func()->count('*', 'count'))->from($table)->executeQuery()->fetchOne();
		}
		return $result;
	}

	private function recordTypeCounts(): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('record_type', $query->func()->count('id', 'count'))->from('bestatter_records')->groupBy('record_type')->executeQuery()->fetchAllAssociative();
		$result = [];
		foreach ($rows as $row) $result[(string)$row['record_type']] = (int)$row['count'];
		return $result;
	}

	private function recordRows(): array {
		$query = $this->db->getQueryBuilder();
		return $query->select('id', 'payload', 'nextcloud_calendar_key', 'nextcloud_uri')->from('bestatter_records')->executeQuery()->fetchAllAssociative();
	}

	private function calDavBackend(): object {
		$class = '\\OCA\\DAV\\CalDAV\\CalDavBackend';
		if (!class_exists($class)) throw new \RuntimeException('Das Nextcloud-DAV-Backend ist nicht verfügbar.');
		return Server::get($class);
	}

	private function safeRollback(): void {
		try { $this->db->rollBack(); } catch (\Throwable) {}
	}
}
