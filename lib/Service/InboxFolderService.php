<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;

/**
 * 0.90.1 – Belegeingang ohne Paperless: Eingangsordner in Nextcloud und Direkt-Upload.
 *
 * - Eingangsordner: <Arbeitsordner>/<Ordner Belegeingang> (Installationsparameter, Standard „Bestatter/Belegeingang“).
 *   Neue Dateien erscheinen beim Öffnen des Belegeingangs sofort, sonst über den Hintergrundjob (alle 5 Minuten).
 *   Darüber laufen Scanner (Ablage in einen Ordner), die Nextcloud-App am Handy, der Desktop-Sync und Mailanhänge,
 *   die in der Mail-App mit „In Dateien speichern“ dort abgelegt werden.
 * - Direkt-Upload: Dateien werden in den Eingangsordner der hochladenden Person gelegt und sofort übernommen.
 * - Einträge stehen wie Paperless-Eingänge in bestatter_external_documents (provider NEXTCLOUD, Dokument-ID = Datei-ID).
 * - Bei der Zuordnung wird die Datei in den Fallordner übernommen und aus dem Eingangsordner entfernt (Nextcloud-Papierkorb);
 *   „Nicht übernehmen“ verschiebt sie in den Unterordner „Nicht übernommen“, der nicht mehr gelesen wird.
 * - Texterkennung für Scans über die Nextcloud-Texterkennung (TaskProcessing), nur wenn unter Administration → Assistenz
 *   für Falldaten freigegeben. Gespeichert wird nur der Vorschlag, nicht der erkannte Text.
 */
class InboxFolderService {
	public const PROVIDER = 'NEXTCLOUD';
	public const DISCARDED_FOLDER = 'Nicht übernommen';
	public const ALLOWED_EXTENSIONS = ['pdf', 'xml', 'jpg', 'jpeg', 'png', 'tif', 'tiff'];
	public const MAX_BYTES = 50 * 1024 * 1024;
	private const MAX_NEW_PER_SCAN = 200;
	private const MAX_DEPTH = 2;
	private const OCR_PREFIX = 'inbox:';

	public function __construct(
		private IDBConnection $db,
		private CaseStorageService $storage,
		private InstallationConfigService $installation,
		private AuditService $audit,
		private IUserSession $userSession,
		private IManager $tasks,
		private LoggerInterface $logger,
	) {
	}

	/** Relativer Pfad des Eingangsordners (zum Basisordner der Ablage). */
	public function relativePath(): string {
		return $this->installation->inboxPath();
	}

	/**
	 * Eingangsordner der angemeldeten Person anlegen (falls nötig) und lesen.
	 * @return array{path:string,found:int,folderId:int|null,error:string}
	 */
	public function scanCurrentUser(): array {
		$uid = $this->uid();
		try {
			$folder = $this->installation->ensurePath($this->storage->baseFolder(), $this->relativePath());
		} catch (\Throwable $error) {
			return ['path' => $this->relativePath(), 'found' => 0, 'folderId' => null, 'error' => 'Der Eingangsordner konnte nicht geöffnet werden: ' . $error->getMessage()];
		}
		$seen = [];
		$found = $this->scanFolder($folder, $uid, 0, $seen);
		$this->markVanished([(int)$folder->getId()], $seen);
		return ['path' => $this->relativePath(), 'found' => $found, 'folderId' => (int)$folder->getId(), 'error' => ''];
	}

	/** Hintergrundjob: alle vorhandenen Eingangsordner (zentrale Ablage, Teamordner nur einmal, sonst je Person). */
	public function scanAll(): int {
		$found = 0;
		$folders = [];
		$seen = [];
		foreach ($this->storage->existingFolders($this->relativePath(), false) as $entry) {
			$folders[] = (int)$entry['folder']->getId();
			try {
				$found += $this->scanFolder($entry['folder'], (string)$entry['uid'], 0, $seen);
			} catch (\Throwable $error) {
				$this->logger->warning('Eingangsordner konnte nicht gelesen werden.', ['folder' => $entry['label'], 'reason' => $error->getMessage()]);
			}
		}
		$this->markVanished($folders, $seen);
		return $found;
	}

	/** Unzugeordnete Einträge aus Eingangsordner und Upload (neueste zuerst). */
	public function items(): array {
		$q = $this->db->getQueryBuilder();
		$rows = $q->select('*')->from('bestatter_external_documents')
			->where($q->expr()->eq('provider', $q->createNamedParameter(self::PROVIDER)))
			->andWhere($q->expr()->isNull('case_id'))
			->andWhere($q->expr()->eq('sync_status', $q->createNamedParameter('INBOX')))
			->orderBy('created_at', 'DESC')->setMaxResults(200)->executeQuery()->fetchAllAssociative();
		return array_map(fn (array $row): array => $this->map($row), $rows);
	}

	/** Eintrag gleich welcher Quelle (für die Weiterleitung in PaperlessInboxService); null, wenn unbekannt. */
	public function find(int $id): ?array {
		$q = $this->db->getQueryBuilder();
		$row = $q->select('*')->from('bestatter_external_documents')->where($q->expr()->eq('id', $q->createNamedParameter($id)))->executeQuery()->fetchAssociative();
		return $row ? $this->map($row) : null;
	}

	public function inboxItem(int $id): array {
		$item = $this->find($id);
		if ($item === null || $item['provider'] !== self::PROVIDER || $item['caseId'] !== null || $item['syncStatus'] !== 'INBOX') {
			throw new \InvalidArgumentException('Diese Datei ist nicht mehr als unzugeordneter Eingang verfügbar.');
		}
		return $item;
	}

	/**
	 * Direkt-Upload in den Eingangsordner der angemeldeten Person.
	 * @param array{name?:string,tmp_name?:string,size?:int,error?:int} $upload
	 */
	public function upload(array $upload): array {
		$error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
		if ($error !== UPLOAD_ERR_OK) {
			throw new \InvalidArgumentException(in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'Die Datei überschreitet die zulässige Uploadgröße.' : 'Bitte eine Datei auswählen.');
		}
		$name = self::safeName((string)($upload['name'] ?? ''));
		$size = (int)($upload['size'] ?? 0);
		$tmp = (string)($upload['tmp_name'] ?? '');
		if ($size <= 0 || $size > self::MAX_BYTES || $tmp === '' || !is_file($tmp)) {
			throw new \InvalidArgumentException('Die Datei muss zwischen 1 Byte und 50 MB groß sein.');
		}
		$content = (string)file_get_contents($tmp);
		self::assertAllowed($name, $content);
		$folder = $this->installation->ensurePath($this->storage->baseFolder(), $this->relativePath());
		$file = $folder->newFile(self::uniqueName($folder, $name));
		$file->putContent($content);
		$id = $this->register($file, $folder, $this->uid(), 'UPLOAD');
		return $this->inboxItem($id);
	}

	/** Dateiinhalt eines Eingangs. @return array{content:string,extension:string,mimeType:string,name:string} */
	public function content(array $item): array {
		$file = $this->file($item);
		if ($file->getSize() > self::MAX_BYTES) {
			throw new \InvalidArgumentException('Die Datei ist größer als 50 MB.');
		}
		$content = (string)$file->getContent();
		if ($content === '') {
			throw new \RuntimeException('Die Datei im Eingangsordner ist leer.');
		}
		$name = $file->getName();
		return ['content' => $content, 'extension' => strtolower(pathinfo($name, PATHINFO_EXTENSION)), 'mimeType' => (string)$file->getMimeType(), 'name' => $name];
	}

	/** Datei eines Eingangs – nur innerhalb des Eingangsordners. */
	public function file(array $item): File {
		$fileId = (int)$item['externalDocumentId'];
		$uids = array_values(array_unique(array_filter([(string)($item['metadata']['uid'] ?? ''), (string)($this->userSession->getUser()?->getUID() ?? '')])));
		foreach ($uids as $uid) {
			try {
				$folder = $this->existingInbox($this->storage->baseFolder($uid));
			} catch (\Throwable) {
				continue;
			}
			if ($folder === null) {
				continue;
			}
			foreach ($folder->getById($fileId) as $node) {
				if ($node instanceof File && str_starts_with($node->getPath(), rtrim($folder->getPath(), '/') . '/')) {
					return $node;
				}
			}
		}
		throw new \InvalidArgumentException('Die Datei liegt nicht mehr im Eingangsordner. Bitte den Belegeingang aktualisieren.');
	}

	public function updateMetadata(int $id, array $metadata, ?string $title = null): array {
		$values = ['metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
		if ($title !== null && trim($title) !== '') {
			$values['title'] = mb_substr(trim($title), 0, 500);
		}
		$this->update($id, $values);
		return $this->find($id) ?? throw new \RuntimeException('Der Eingang wurde nicht gefunden.');
	}

	/** Eingang einem Fall zugeordnet (CASE_DOCUMENT oder INCOMING_INVOICE); die Datei ist dann bereits im Fallordner. */
	public function link(int $id, int $caseId, int $recordId, int $fileId, string $sha256, string $recordType, string $documentKind): array {
		$item = $this->inboxItem($id);
		$metadata = $item['metadata'];
		$metadata['documentKind'] = $documentKind;
		$metadata['assignedBy'] = $this->uid();
		$metadata['assignedAt'] = date('c');
		$this->update($id, ['case_id' => $caseId, 'record_type' => $recordType, 'record_id' => $recordId, 'nextcloud_file_id' => $fileId ?: null, 'document_sha256' => $sha256 !== '' ? $sha256 : null, 'sync_status' => 'LINKED', 'sync_error' => null, 'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'last_synced_at' => date('c')]);
		$this->audit->log($caseId, $recordType === 'INCOMING_INVOICE' ? 'INCOMING_INVOICE' : 'DOCUMENT', $recordId, 'INBOX_LINKED', null, ['channel' => (string)($metadata['channel'] ?? 'FOLDER'), 'fileName' => (string)($metadata['name'] ?? $item['title']), 'nextcloudFileId' => $fileId, 'documentKind' => $documentKind]);
		return $this->find($id) ?? $item;
	}

	/** Nach der Übernahme in den Fall: Datei aus dem Eingangsordner entfernen (landet im Nextcloud-Papierkorb). */
	public function removeOriginal(array $item): bool {
		try {
			$this->file($item)->delete();
			return true;
		} catch (\Throwable $error) {
			$this->logger->warning('Datei aus dem Eingangsordner konnte nicht entfernt werden.', ['id' => $item['id'], 'reason' => $error->getMessage()]);
			return false;
		}
	}

	/** „Nicht übernehmen“: Datei in den Unterordner „Nicht übernommen“ verschieben (wird nicht mehr gelesen). */
	public function discard(int $id, string $reason): array {
		$item = $this->inboxItem($id);
		$metadata = $item['metadata'];
		$metadata['discardedBy'] = $this->uid();
		$metadata['discardedAt'] = date('c');
		$metadata['discardReason'] = mb_substr(trim($reason), 0, 300);
		try {
			$file = $this->file($item);
			$inbox = $this->existingInbox($this->storage->baseFolder((string)($item['metadata']['uid'] ?? '') ?: null));
			if ($inbox !== null) {
				$target = $inbox->nodeExists(self::DISCARDED_FOLDER) ? $inbox->get(self::DISCARDED_FOLDER) : $inbox->newFolder(self::DISCARDED_FOLDER);
				if ($target instanceof Folder) {
					$moved = $file->move(rtrim($target->getPath(), '/') . '/' . self::uniqueName($target, $file->getName()));
					$metadata['discardedPath'] = $this->storage->relativePath($moved);
				}
			}
		} catch (\Throwable $error) {
			$metadata['discardMoveError'] = mb_substr($error->getMessage(), 0, 300);
		}
		$this->update($id, ['sync_status' => 'DISCARDED', 'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
		$this->audit->logSystem('INBOX', 'DISCARDED', ['externalId' => $id, 'fileName' => (string)($metadata['name'] ?? ''), 'reason' => $metadata['discardReason']]);
		return $this->find($id) ?? $item;
	}

	/**
	 * Nextcloud-Texterkennung starten (nur wenn eingerichtet und freigegeben). Ergebnis in metadata['ocr'].
	 * @return array{status:string,message:string}
	 */
	public function startOcr(array $item): array {
		$availability = $this->ocrAvailability();
		if (!$availability['available']) {
			return ['status' => 'UNAVAILABLE', 'message' => $availability['reason']];
		}
		$uid = (string)($item['metadata']['uid'] ?? '') ?: $this->uid();
		try {
			$task = new Task(AssistantService::OCR_TASK_TYPE, ['input' => [(int)$item['externalDocumentId']]], Application::APP_ID, $uid !== 'system' ? $uid : null, self::OCR_PREFIX . (int)$item['id']);
			$this->tasks->scheduleTask($task);
			return ['status' => 'QUEUED', 'message' => '', 'taskId' => (int)$task->getId()];
		} catch (\Throwable $error) {
			return ['status' => 'FAILED', 'message' => 'Die Nextcloud-Texterkennung konnte nicht gestartet werden.'];
		}
	}

	/**
	 * Stand der Texterkennung. @return array{status:string,message:string,text:string}
	 */
	public function pollOcr(array $item): array {
		$taskId = (int)($item['metadata']['ocr']['taskId'] ?? 0);
		if ($taskId <= 0) {
			return ['status' => 'FAILED', 'message' => 'Der Texterkennungsauftrag fehlt.', 'text' => ''];
		}
		try {
			$task = $this->tasks->getTask($taskId);
		} catch (\Throwable) {
			return ['status' => 'FAILED', 'message' => 'Der Texterkennungsauftrag wurde nicht gefunden.', 'text' => ''];
		}
		if ($task->getCustomId() !== self::OCR_PREFIX . (int)$item['id'] || $task->getAppId() !== Application::APP_ID) {
			return ['status' => 'FAILED', 'message' => 'Der Texterkennungsauftrag gehört nicht zu diesem Eingang.', 'text' => ''];
		}
		$status = $task->getStatus();
		if ($status === Task::STATUS_FAILED || $status === Task::STATUS_CANCELLED) {
			return ['status' => 'FAILED', 'message' => $task->getUserFacingErrorMessage() ?: 'Die Texterkennung ist fehlgeschlagen.', 'text' => ''];
		}
		if ($status !== Task::STATUS_SUCCESSFUL) {
			return ['status' => $status === Task::STATUS_RUNNING ? 'RUNNING' : 'QUEUED', 'message' => '', 'text' => ''];
		}
		$output = ($task->getOutput() ?? [])['output'] ?? [];
		$text = trim(implode("\n\n", array_map('strval', is_array($output) ? $output : [$output])));
		return ['status' => 'DONE', 'message' => $text === '' ? 'Die Texterkennung hat keinen Text gefunden.' : '', 'text' => $text];
	}

	/** @return array{available:bool,reason:string} */
	public function ocrAvailability(): array {
		try {
			return \OCP\Server::get(AssistantService::class)->nextcloudOcrAvailability();
		} catch (\Throwable) {
			return ['available' => false, 'reason' => 'Die Nextcloud-Texterkennung ist nicht verfügbar.'];
		}
	}

	/** Dateiname und Inhalt prüfen (Endung, Inhaltstyp). */
	public static function assertAllowed(string $name, string $content): void {
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
			throw new \InvalidArgumentException('Im Belegeingang sind nur PDF, XML (E-Rechnung) und Bilder (JPG, PNG, TIFF) erlaubt.');
		}
		$ok = match ($extension) {
			'pdf' => str_starts_with($content, '%PDF-'),
			'xml' => preg_match('/^\s*(\xEF\xBB\xBF)?\s*</', $content) === 1,
			'jpg', 'jpeg' => str_starts_with($content, "\xFF\xD8\xFF"),
			'png' => str_starts_with($content, "\x89PNG"),
			'tif', 'tiff' => str_starts_with($content, "II*\x00") || str_starts_with($content, "MM\x00*"),
			default => false,
		};
		if (!$ok) {
			throw new \InvalidArgumentException('Der Dateiinhalt passt nicht zur Endung „.' . $extension . '“.');
		}
	}

	/** Wird die Datei beim Lesen des Eingangsordners berücksichtigt? */
	public static function isCandidate(string $name): bool {
		if ($name === '' || str_starts_with($name, '.') || str_starts_with($name, '~$') || str_ends_with(strtolower($name), '.part')) {
			return false;
		}
		return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::ALLOWED_EXTENSIONS, true);
	}

	// ------------------------------------------------------------------ intern

	/** @param array<int,true> $seen Datei-IDs, die beim Lesen gefunden wurden */
	private function scanFolder(Folder $folder, string $uid, int $depth, array &$seen): int {
		$found = 0;
		foreach ($folder->getDirectoryListing() as $node) {
			$name = $node->getName();
			if ($node instanceof Folder) {
				if ($depth < self::MAX_DEPTH && $name !== self::DISCARDED_FOLDER && !str_starts_with($name, '.')) {
					$found += $this->scanFolder($node, $uid, $depth + 1, $seen);
				}
				continue;
			}
			if (!$node instanceof File || !self::isCandidate($name) || $node->getSize() <= 0) {
				continue;
			}
			$seen[(int)$node->getId()] = true;
			$existing = $this->byFileId((int)$node->getId());
			if ($existing !== null) {
				$this->refreshChanged($existing, $node);
				continue;
			}
			if ($found >= self::MAX_NEW_PER_SCAN) {
				continue;
			}
			$this->register($node, $this->inboxRoot($folder, $depth), $uid, 'FOLDER');
			$found++;
		}
		return $found;
	}

	private function inboxRoot(Folder $folder, int $depth): Folder {
		for ($i = 0; $i < $depth; $i++) {
			$folder = $folder->getParent();
		}
		return $folder;
	}

	private function register(File $file, Folder $inbox, string $uid, string $channel): int {
		$now = date('c');
		$metadata = [
			'channel' => $channel, 'uid' => $uid, 'name' => $file->getName(), 'path' => $this->storage->relativePath($file), 'folderId' => (int)$inbox->getId(),
			'size' => (int)$file->getSize(), 'mtime' => (int)$file->getMTime(), 'mimeType' => (string)$file->getMimeType(),
		] + ($channel === 'UPLOAD' ? ['uploadedBy' => $this->uid()] : []);
		$q = $this->db->getQueryBuilder();
		$q->insert('bestatter_external_documents')->values([
			'case_id' => $q->createNamedParameter(null), 'record_type' => $q->createNamedParameter(PaperlessService::INBOX_RECORD_TYPE), 'record_id' => $q->createNamedParameter(null),
			'provider' => $q->createNamedParameter(self::PROVIDER), 'external_document_id' => $q->createNamedParameter((string)$file->getId()), 'external_task_id' => $q->createNamedParameter(null),
			'nextcloud_file_id' => $q->createNamedParameter((int)$file->getId()), 'document_sha256' => $q->createNamedParameter(null), 'title' => $q->createNamedParameter(mb_substr($file->getName(), 0, 500)),
			'sync_status' => $q->createNamedParameter('INBOX'), 'sync_error' => $q->createNamedParameter(null),
			'metadata_json' => $q->createNamedParameter(json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
			'last_synced_at' => $q->createNamedParameter($now), 'created_at' => $q->createNamedParameter($now), 'updated_at' => $q->createNamedParameter($now),
		])->executeStatement();
		$id = (int)$this->db->lastInsertId('bestatter_external_documents');
		$this->audit->logSystem('INBOX', 'RECEIVED', ['externalId' => $id, 'channel' => $channel, 'fileName' => $file->getName()]);
		return $id;
	}

	/** Geänderte Datei (gleiche ID, anderer Inhalt): Vorschlag neu ermitteln. */
	private function refreshChanged(array $item, File $file): void {
		if ($item['syncStatus'] !== 'INBOX' || $item['caseId'] !== null) {
			return;
		}
		$metadata = $item['metadata'];
		if ((int)($metadata['mtime'] ?? 0) === (int)$file->getMTime() && (int)($metadata['size'] ?? 0) === (int)$file->getSize() && ($metadata['name'] ?? '') === $file->getName()) {
			return;
		}
		$metadata = array_diff_key($metadata, ['enrichedAt' => true, 'suggestion' => true, 'eInvoice' => true, 'ocr' => true, 'textSource' => true, 'enrichError' => true]);
		$metadata['name'] = $file->getName();
		$metadata['path'] = $this->storage->relativePath($file);
		$metadata['size'] = (int)$file->getSize();
		$metadata['mtime'] = (int)$file->getMTime();
		$this->updateMetadata((int)$item['id'], $metadata, $file->getName());
	}

	/**
	 * Einträge, deren Datei aus einem gelesenen Eingangsordner verschwunden ist (verschoben, gelöscht), ausblenden.
	 * @param list<int> $folderIds
	 * @param array<int,true> $seen
	 */
	private function markVanished(array $folderIds, array $seen): void {
		if ($folderIds === []) {
			return;
		}
		foreach ($this->items() as $item) {
			if (!in_array((int)($item['metadata']['folderId'] ?? 0), $folderIds, true) || isset($seen[(int)$item['externalDocumentId']])) {
				continue;
			}
			$metadata = $item['metadata'];
			$metadata['removedAt'] = date('c');
			$this->update((int)$item['id'], ['sync_status' => 'REMOVED', 'sync_error' => 'Datei nicht mehr im Eingangsordner', 'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
		}
	}

	private function byFileId(int $fileId): ?array {
		$q = $this->db->getQueryBuilder();
		$row = $q->select('*')->from('bestatter_external_documents')->where($q->expr()->eq('provider', $q->createNamedParameter(self::PROVIDER)))
			->andWhere($q->expr()->eq('external_document_id', $q->createNamedParameter((string)$fileId)))->executeQuery()->fetchAssociative();
		return $row ? $this->map($row) : null;
	}

	private function existingInbox(Folder $base): ?Folder {
		$folder = $base;
		foreach (explode('/', trim($this->relativePath(), '/')) as $name) {
			if ($name === '') {
				continue;
			}
			if (!$folder->nodeExists($name)) {
				return null;
			}
			$node = $folder->get($name);
			if (!$node instanceof Folder) {
				return null;
			}
			$folder = $node;
		}
		return $folder;
	}

	private function update(int $id, array $values): void {
		$q = $this->db->getQueryBuilder();
		$q->update('bestatter_external_documents');
		foreach ($values as $column => $value) {
			$q->set($column, $q->createNamedParameter($value));
		}
		$q->set('updated_at', $q->createNamedParameter(date('c')))->where($q->expr()->eq('id', $q->createNamedParameter($id)))->executeStatement();
	}

	private function map(array $row): array {
		$metadata = json_decode((string)($row['metadata_json'] ?? '{}'), true) ?: [];
		return [
			'id' => (int)$row['id'], 'caseId' => $row['case_id'] !== null ? (int)$row['case_id'] : null, 'recordType' => (string)$row['record_type'],
			'recordId' => $row['record_id'] !== null ? (int)$row['record_id'] : null, 'provider' => (string)$row['provider'],
			'externalDocumentId' => $row['external_document_id'] !== null ? (string)$row['external_document_id'] : null,
			'nextcloudFileId' => $row['nextcloud_file_id'] !== null ? (int)$row['nextcloud_file_id'] : null, 'documentSha256' => $row['document_sha256'],
			'title' => (string)($row['title'] ?? ''), 'syncStatus' => (string)$row['sync_status'], 'syncError' => (string)($row['sync_error'] ?? ''),
			'metadata' => is_array($metadata) ? $metadata : [], 'lastSyncedAt' => $row['last_synced_at'], 'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at'],
		];
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? 'system';
	}

	private static function safeName(string $name): string {
		$name = trim(basename(str_replace('\\', '/', $name)));
		$name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
		if ($name === '' || $name === '.' || $name === '..' || strlen($name) > 180 || str_starts_with($name, '.')) {
			throw new \InvalidArgumentException('Der Dateiname ist ungültig oder zu lang.');
		}
		return $name;
	}

	private static function uniqueName(Folder $folder, string $name): string {
		if (!$folder->nodeExists($name)) {
			return $name;
		}
		$base = pathinfo($name, PATHINFO_FILENAME);
		$extension = pathinfo($name, PATHINFO_EXTENSION);
		for ($number = 2; $number <= 999; $number++) {
			$candidate = $base . ' (' . $number . ')' . ($extension !== '' ? '.' . $extension : '');
			if (!$folder->nodeExists($candidate)) {
				return $candidate;
			}
		}
		throw new \RuntimeException('Für diesen Dateinamen konnten keine weiteren Varianten angelegt werden.');
	}
}
