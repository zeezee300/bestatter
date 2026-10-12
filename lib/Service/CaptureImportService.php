<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager as ITaskManager;
use OCP\TaskProcessing\Task;

/**
 * Sicherer Eingang für Auftragserfassungsbögen.
 * OCR-Werte sind ausschließlich Vorschläge und werden nie automatisch gespeichert.
 * 0.71.0: Texterkennung über Paperless (PDF) oder die Nextcloud-Texterkennung (PDF/Foto); ohne eingerichtete
 * Texterkennung wird der Weg nicht angeboten. Offene Bögen können fortgesetzt oder verworfen werden.
 */
class CaptureImportService {
	private const MAX_BYTES = 25 * 1024 * 1024;
	/** Abgeschlossene Vorgänge – ein erneutes Hochladen desselben Bogens ist dann wieder möglich. */
	private const CLOSED = ['COMPLETED', 'DISCARDED'];
	private const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

	public function __construct(
		private IDBConnection $db,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private FolderService $folders,
		private PaperlessService $paperless,
		private RecordService $records,
		private AuditService $audit,
		private AssistantService $assistant,
		private ITaskManager $tasks,
	) {}

	/**
	 * Welche Texterkennung steht für Erfassungsbögen zur Verfügung? Paperless hat Vorrang (dort ist der Bogen
	 * zugleich archiviert), sonst die Nextcloud-Texterkennung.
	 * @return array{available: bool, engine: string, reason: string, acceptsImages: bool, accept: string}
	 */
	public function ocrAvailability(): array {
		$paperless = $this->paperless->settings();
		if ($paperless['enabled'] && (int)$paperless['captureDocumentTypeId'] > 0) return ['available' => true, 'engine' => 'PAPERLESS', 'reason' => '', 'acceptsImages' => false, 'accept' => 'application/pdf,.pdf'];
		$nextcloud = $this->assistant->nextcloudOcrAvailability();
		if ($nextcloud['available']) return ['available' => true, 'engine' => 'NEXTCLOUD', 'reason' => '', 'acceptsImages' => true, 'accept' => 'application/pdf,.pdf,image/jpeg,image/png,.jpg,.jpeg,.png'];
		$reason = $paperless['enabled'] ? 'Paperless ist verbunden, aber der Dokumenttyp für Auftragserfassungsbögen fehlt (Administration → Paperless).' : 'Keine Texterkennung eingerichtet: Paperless-ngx verbinden oder die Nextcloud-Texterkennung einrichten und freigeben.';
		if (!$paperless['enabled'] && $nextcloud['reason'] !== '' && !str_starts_with($nextcloud['reason'], 'In Nextcloud ist keine')) $reason = $nextcloud['reason'];
		return ['available' => false, 'engine' => '', 'reason' => $reason, 'acceptsImages' => false, 'accept' => ''];
	}

	public function create(array $upload, string $templateKey = 'AUFTRAGSERFASSUNG_STANDARD', string $templateVersion = '1'): array {
		$ocr = $this->ocrAvailability();
		if (!$ocr['available']) throw new \InvalidArgumentException($ocr['reason']);
		$this->validateUpload($upload);
		$content = file_get_contents((string)$upload['tmp_name']);
		if ($content === false) throw new \InvalidArgumentException('Die Upload-Datei ist nicht lesbar.');
		$mime = $this->detectMime($content);
		if ($mime === null || ($mime !== 'application/pdf' && !$ocr['acceptsImages'])) throw new \InvalidArgumentException($ocr['acceptsImages'] ? 'Bitte den Erfassungsbogen als PDF oder Foto (JPG/PNG) auswählen.' : 'Bitte den Erfassungsbogen als PDF auswählen (Fotos erkennt nur die Nextcloud-Texterkennung).');
		$uid = $this->uid();
		$hash = hash('sha256', $content);
		$open = $this->findOpenByHash($hash, $uid);
		// 0.71.0: Derselbe Bogen ist noch in Bearbeitung – fortsetzen statt Fehlermeldung (vorher dauerhaft gesperrt).
		if ($open !== null) return $this->status($open) + ['resumed' => true];

		$name = $this->safeName((string)($upload['name'] ?? 'Auftragserfassungsbogen.pdf'), $mime);
		$folder = $this->folders->captureInbox();
		$fileName = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '-' . $name;
		$file = $folder->newFile($fileName);
		$file->putContent($content);
		$now = date('c');
		$query = $this->db->getQueryBuilder();
		$query->insert('bestatter_capture_imports')->values([
			'user_id'=>$query->createNamedParameter($uid), 'case_id'=>$query->createNamedParameter(null),
			'nextcloud_file_id'=>$query->createNamedParameter($file->getId()), 'external_document_id'=>$query->createNamedParameter(null),
			'template_key'=>$query->createNamedParameter($this->key($templateKey)), 'template_version'=>$query->createNamedParameter(mb_substr(trim($templateVersion) ?: '1', 0, 30)),
			'original_name'=>$query->createNamedParameter($name), 'mime_type'=>$query->createNamedParameter($mime),
			'document_sha256'=>$query->createNamedParameter($hash), 'import_status'=>$query->createNamedParameter('UPLOADED'),
			'ocr_text'=>$query->createNamedParameter(null), 'suggestions_json'=>$query->createNamedParameter(null), 'error_message'=>$query->createNamedParameter(null),
			'ocr_engine'=>$query->createNamedParameter($ocr['engine']), 'ocr_task_id'=>$query->createNamedParameter(null),
			'created_at'=>$query->createNamedParameter($now), 'updated_at'=>$query->createNamedParameter($now), 'reviewed_at'=>$query->createNamedParameter(null),
		])->executeStatement();
		$id = (int)$this->db->lastInsertId('bestatter_capture_imports');
		$this->startOcr($id, $file->getId(), $hash, $name, $ocr['engine']);
		$this->audit->logSystem('CAPTURE_IMPORT', 'UPLOADED', ['id'=>$id, 'fileId'=>$file->getId(), 'sha256'=>$hash, 'engine'=>$ocr['engine']]);
		// 0.79.0: sofort an Paperless übergeben (vorher erst beim ersten Statusabruf oder durch den Hintergrundjob).
		$this->processPaperless($id);
		return $this->status($id);
	}

	public function status(int $id): array {
		$item = $this->get($id, true);
		if (!in_array($item['status'], ['OCR_QUEUED', 'OCR_PROCESSING'], true)) return $this->withStage($item);
		if ($item['ocrEngine'] === 'NEXTCLOUD') { $progress = $this->pollNextcloudOcr($id); return $this->withStage($this->get($id, true), $progress); }
		$this->processPaperless($id);
		return $this->withStage($this->get($id, true));
	}

	/** Nur die Aufträge dieses Bogens ausführen – fremde Warteschlangen-Aufträge verlängern die Statusabfrage nicht mehr. */
	private function processPaperless(int $id): void {
		$q = $this->db->getQueryBuilder();
		$externalId = $q->select('external_document_id')->from('bestatter_capture_imports')->where($q->expr()->eq('id', $q->createNamedParameter($id)))->executeQuery()->fetchOne();
		if ($externalId === false || $externalId === null) return;
		try { $this->paperless->processFor((int)$externalId); } catch (\Throwable) { /* Persistente Queue bleibt führend; der Hintergrundjob versucht es erneut. */ }
	}

	/**
	 * 0.79.0 – Verarbeitungsstufe für die Fortschrittsanzeige: was gerade passiert, seit wann, ggf. Fortschritt (0–1)
	 * und wann ein neuer Versuch folgt. Ohne Datenbankänderung, nur aus vorhandenen Angaben abgeleitet.
	 */
	private function withStage(array $item, ?float $progress = null): array {
		$item['elapsedSeconds'] = max(0, time() - (strtotime((string)$item['createdAt']) ?: time()));
		$item['progress'] = $progress;
		$item['nextAttemptAt'] = null;
		$stage = match ($item['status']) {
			'EXTRACTION_READY', 'REVIEW_REQUIRED', 'MANUAL_REVIEW', 'COMPLETED' => ['DONE', 'Texterkennung abgeschlossen'],
			'OCR_FAILED' => ['FAILED', 'Texterkennung fehlgeschlagen'],
			'DISCARDED' => ['DONE', 'Verworfen'],
			default => null,
		};
		// 0.79.1: einheitliche Schritte für alle Texterkennungen (SENDING → RECOGNIZING); das System steht in ocrEngineLabel
		// und in der Tätigkeitszeile. Bis 0.79.0 hieß der Wartezustand der Nextcloud-Texterkennung QUEUED („Eingeplant“).
		if ($stage === null && $item['ocrEngine'] === 'NEXTCLOUD') {
			$stage = $item['status'] === 'OCR_PROCESSING' ? ['RECOGNIZING', 'Die Nextcloud-Texterkennung erkennt den Text'] : ['SENDING', 'Wartet auf die Nextcloud-Hintergrundverarbeitung'];
		}
		if ($stage === null && $item['externalDocumentId'] !== null) {
			$q = $this->db->getQueryBuilder();
			$external = $q->select('sync_status', 'sync_error', 'external_task_id', 'metadata_json')->from('bestatter_external_documents')->where($q->expr()->eq('id', $q->createNamedParameter($item['externalDocumentId'])))->executeQuery()->fetchAssociative() ?: [];
			$sync = strtoupper((string)($external['sync_status'] ?? 'QUEUED'));
			$taskStatus = strtoupper((string)((json_decode((string)($external['metadata_json'] ?? '{}'), true) ?: [])['paperlessTaskStatus'] ?? ''));
			if ($sync === 'RETRY') {
				$job = $this->db->getQueryBuilder();
				$next = $job->select('next_attempt_at')->from('bestatter_integration_jobs')->where($job->expr()->eq('object_type', $job->createNamedParameter('EXTERNAL_DOCUMENT')))->andWhere($job->expr()->eq('object_id', $job->createNamedParameter($item['externalDocumentId'])))->andWhere($job->expr()->eq('job_status', $job->createNamedParameter('RETRY')))->orderBy('id', 'DESC')->setMaxResults(1)->executeQuery()->fetchOne();
				$item['nextAttemptAt'] = $next !== false ? (string)$next : null;
				$item['errorMessage'] = (string)($external['sync_error'] ?? '');
				$stage = ['WAITING', 'Paperless-ngx hat nicht geantwortet – neuer Versuch folgt automatisch'];
			} elseif (in_array($sync, ['QUEUED'], true) || (string)($external['external_task_id'] ?? '') === '') {
				$stage = ['SENDING', 'Wird an Paperless-ngx übergeben'];
			} else {
				$stage = $taskStatus === 'PENDING' || $taskStatus === '' ? ['RECOGNIZING', 'Paperless-ngx hat den Bogen angenommen und wartet auf die Verarbeitung'] : ['RECOGNIZING', 'Paperless-ngx erkennt den Text'];
			}
		}
		$stage ??= ['SENDING', 'Wird an die Texterkennung übergeben'];
		[$item['stage'], $item['stageLabel']] = $stage;
		$item['ocrEngineLabel'] = self::engineLabel((string)$item['ocrEngine']);
		return $item;
	}

	/** Anzeigename des Systems, das die Texterkennung ausführt (0.79.1). */
	public static function engineLabel(string $engine): string {
		return match ($engine) {
			'PAPERLESS' => 'Paperless-ngx',
			'NEXTCLOUD' => 'Nextcloud',
			default => '',
		};
	}

	public function retry(int $id): array {
		$item = $this->get($id, true);
		if (in_array($item['status'], self::CLOSED, true)) throw new \InvalidArgumentException('Der Erfassungsbogen ist bereits abgeschlossen.');
		$engine = $item['ocrEngine'] !== '' ? $item['ocrEngine'] : 'PAPERLESS';
		$ocr = $this->ocrAvailability();
		if (!$ocr['available']) throw new \InvalidArgumentException($ocr['reason']);
		if ($engine !== $ocr['engine']) $engine = $ocr['engine'];
		if ($engine === 'NEXTCLOUD') {
			$this->startOcr($id, $item['fileId'], $item['documentSha256'], $item['originalName'], 'NEXTCLOUD');
		} elseif (!$item['externalDocumentId']) {
			$this->startOcr($id, $item['fileId'], $item['documentSha256'], $item['originalName'], 'PAPERLESS');
		} else {
			$this->paperless->retry((int)$item['externalDocumentId']);
			$this->update($id, ['import_status'=>'OCR_QUEUED', 'error_message'=>null, 'ocr_engine'=>'PAPERLESS']);
		}
		if ($engine === 'PAPERLESS') $this->processPaperless($id);
		return $this->status($id);
	}

	/** 0.71.0: Offenen Bogen verwerfen – der zwischengespeicherte Scan wird gelöscht, der Vorgang bleibt protokolliert. */
	public function discard(int $id): array {
		$item = $this->get($id);
		if ($item['status'] === 'COMPLETED') throw new \InvalidArgumentException('Der Erfassungsbogen ist bereits einem Fall zugeordnet.');
		if ($item['status'] === 'DISCARDED') return $item;
		foreach ($this->foldersByFileId($item['fileId']) as $node) {
			if ($node instanceof File && $item['caseId'] === null && $this->isInbox($node)) { try { $node->delete(); } catch (\Throwable) {} }
		}
		$this->update($id, ['import_status'=>'DISCARDED', 'ocr_text'=>null, 'suggestions_json'=>null, 'error_message'=>null]);
		$this->audit->logSystem('CAPTURE_IMPORT', 'DISCARDED', ['id'=>$id]);
		return $this->get($id);
	}

	/** Offene Bögen der angemeldeten Person (zum Fortsetzen nach Neuladen oder auf einem anderen Gerät). */
	public function openImports(): array {
		$q = $this->db->getQueryBuilder();
		$rows = $q->select('id')->from('bestatter_capture_imports')->where($q->expr()->eq('user_id', $q->createNamedParameter($this->uid())))
			->andWhere($q->expr()->notIn('import_status', $q->createNamedParameter(self::CLOSED, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'DESC')->setMaxResults(20)->executeQuery()->fetchFirstColumn();
		return array_map(fn($id): array => $this->get((int)$id), $rows);
	}

	private function startOcr(int $id, int $fileId, string $hash, string $name, string $engine): void {
		if ($engine === 'NEXTCLOUD') {
			try {
				$task = new Task(AssistantService::OCR_TASK_TYPE, ['input' => [$fileId]], Application::APP_ID, $this->uid(), 'capture:' . $id);
				$this->tasks->scheduleTask($task);
				$this->update($id, ['ocr_engine'=>'NEXTCLOUD', 'ocr_task_id'=>$task->getId(), 'import_status'=>'OCR_QUEUED', 'error_message'=>null]);
			} catch (\Throwable $error) {
				$this->update($id, ['ocr_engine'=>'NEXTCLOUD', 'import_status'=>'OCR_FAILED', 'error_message'=>'Die Nextcloud-Texterkennung konnte nicht gestartet werden. Der Bogen bleibt gespeichert; die Angaben können manuell übertragen werden.']);
			}
			return;
		}
		$external = $this->paperless->enqueueCaptureImport(['id'=>$id, 'fileId'=>$fileId, 'documentSha256'=>$hash, 'title'=>'Auftragserfassungsbogen – ' . pathinfo($name, PATHINFO_FILENAME)]);
		if ($external === null) $this->update($id, ['ocr_engine'=>'PAPERLESS', 'import_status'=>'OCR_FAILED', 'error_message'=>'Paperless ist nicht mehr vollständig eingerichtet. Der Bogen bleibt gespeichert; die Angaben können manuell übertragen werden.']);
		else $this->update($id, ['ocr_engine'=>'PAPERLESS', 'external_document_id'=>(int)$external['id'], 'import_status'=>'OCR_QUEUED', 'error_message'=>null]);
	}

	private function pollNextcloudOcr(int $id): ?float {
		$q = $this->db->getQueryBuilder();
		$taskId = (int)$q->select('ocr_task_id')->from('bestatter_capture_imports')->where($q->expr()->eq('id', $q->createNamedParameter($id)))->executeQuery()->fetchOne();
		if ($taskId <= 0) { $this->update($id, ['import_status'=>'OCR_FAILED', 'error_message'=>'Der Texterkennungsauftrag fehlt. Bitte erneut versuchen.']); return null; }
		try { $task = $this->tasks->getTask($taskId); } catch (\Throwable) { $this->update($id, ['import_status'=>'OCR_FAILED', 'error_message'=>'Der Texterkennungsauftrag wurde nicht gefunden. Bitte erneut versuchen.']); return null; }
		if ($task->getCustomId() !== 'capture:' . $id || $task->getUserId() !== $this->uid()) return null;
		$status = $task->getStatus();
		if ($status === Task::STATUS_RUNNING) { $this->update($id, ['import_status'=>'OCR_PROCESSING']); $progress = $task->getProgress(); return $progress !== null ? max(0.0, min(1.0, (float)$progress)) : null; }
		if ($status === Task::STATUS_FAILED || $status === Task::STATUS_CANCELLED) {
			$this->update($id, ['import_status'=>'OCR_FAILED', 'error_message'=>($task->getUserFacingErrorMessage() ?: 'Die Texterkennung ist fehlgeschlagen.') . ' Der Bogen bleibt gespeichert.']);
			return null;
		}
		if ($status !== Task::STATUS_SUCCESSFUL) return null;
		$output = ($task->getOutput() ?? [])['output'] ?? [];
		$text = trim(implode("\n\n", array_map('strval', is_array($output) ? $output : [$output])));
		if ($text === '') { $this->update($id, ['import_status'=>'REVIEW_REQUIRED', 'error_message'=>'Die Texterkennung hat keinen Text gefunden. Der Scan kann manuell geprüft werden.']); return null; }
		try { $analysis = $this->assistant->analyze($text, 'OCR_FORM'); } catch (\Throwable $error) { $analysis = ['suggestions' => []]; }
		$this->update($id, ['import_status'=>'EXTRACTION_READY', 'ocr_text'=>$text, 'suggestions_json'=>json_encode($analysis['suggestions'] ?? [], JSON_UNESCAPED_UNICODE), 'error_message'=>null]);
		return 1.0;
	}

	private function detectMime(string $content): ?string {
		if (str_starts_with($content, '%PDF-')) return 'application/pdf';
		if (str_starts_with($content, "\xFF\xD8\xFF")) return 'image/jpeg';
		if (str_starts_with($content, "\x89PNG\r\n\x1A\n")) return 'image/png';
		return null;
	}

	private function isInbox(File $node): bool {
		return str_contains($node->getPath(), '/files/' . InstallationConfigService::PRIVATE_WORK_ROOT . '/') || str_contains($node->getPath(), '/.Erfassungseingang/');
	}

	public function complete(int $id, array $case, array $confirmedFields): array {
		$item = $this->get($id, true);
		if ($item['caseId'] !== null && (int)$item['caseId'] !== (int)$case['id']) throw new \InvalidArgumentException('Der Erfassungsbogen wurde bereits einem anderen Fall zugeordnet.');
		if($item['status']==='DISCARDED')throw new \InvalidArgumentException('Der Erfassungsbogen wurde verworfen.');
		if($item['status']==='COMPLETED'&&$item['caseId']===(int)$case['id'])return ['status'=>'COMPLETED','fileId'=>$item['fileId'],'record'=>null,'folder'=>$this->folders->defaultUploadFolder(),'idempotent'=>true];
		$nodes = $this->foldersByFileId((int)$item['fileId']);
		$source = $nodes[0] ?? null;
		if (!$source instanceof File) throw new \RuntimeException('Der zwischengespeicherte Erfassungsbogen wurde nicht gefunden.');
		$targetFolder = $this->folders->caseSubfolder((string)$case['caseNumber'], $this->folders->defaultUploadFolder());
		$name = $this->uniqueName($targetFolder, $item['originalName']);
		$target = $targetFolder->newFile($name);
		try{$target->putContent($source->getContent());}catch(\Throwable $error){try{$target->delete();}catch(\Throwable){}throw $error;}
		$relativePath = $this->folders->defaultUploadFolder() . '/' . $name;
		$this->db->beginTransaction();
		try{
			$record = $this->records->saveDocument((int)$case['id'], pathinfo($name, PATHINFO_FILENAME), 'EINGEGANGEN', [
				'source'=>'OCR_CAPTURE', 'fileId'=>$target->getId(), 'path'=>$this->folders->caseRelativePath((string)$case['caseNumber']).'/'.$relativePath,
				'relativePath'=>$relativePath, 'subfolder'=>$this->folders->defaultUploadFolder(), 'documentType'=>'Auftrag',
				'mimeType'=>$item['mimeType'], 'size'=>$target->getSize(), 'originalName'=>$item['originalName'], 'uploadedAt'=>date('c'),
				'captureImportId'=>$id, 'templateKey'=>$item['templateKey'], 'templateVersion'=>$item['templateVersion'],
			]);
			$this->update($id, ['case_id'=>(int)$case['id'], 'nextcloud_file_id'=>$target->getId(), 'import_status'=>'COMPLETED', 'reviewed_at'=>date('c'), 'error_message'=>null, 'ocr_text'=>null, 'suggestions_json'=>null]);
			if($item['externalDocumentId']!==null){$external=$this->db->getQueryBuilder();$external->update('bestatter_external_documents')->set('case_id',$external->createNamedParameter((int)$case['id']))->set('nextcloud_file_id',$external->createNamedParameter($target->getId()))->set('sync_status',$external->createNamedParameter('LINKED'))->set('updated_at',$external->createNamedParameter(date('c')))->where($external->expr()->eq('id',$external->createNamedParameter((int)$item['externalDocumentId'])))->executeStatement();}
			$this->db->commit();
		}catch(\Throwable $error){$this->db->rollBack();try{$target->delete();}catch(\Throwable){}throw $error;}
		try{$source->delete();}catch(\Throwable){/* Der abgeschlossene Vorgang verweist bereits eindeutig auf die Fallakte. */}
		$this->audit->log((int)$case['id'], 'CAPTURE_IMPORT', $id, 'CONFIRMED_AND_FILED', null, ['fileId'=>$target->getId(), 'recordId'=>$record['id'] ?? null, 'confirmedFields'=>array_values(array_unique(array_map('strval', $confirmedFields)))]);
		return ['status'=>'COMPLETED', 'fileId'=>$target->getId(), 'record'=>$record, 'folder'=>$this->folders->defaultUploadFolder()];
	}

	public function get(int $id, bool $withSensitiveText = false): array {
		$query=$this->db->getQueryBuilder();
		$row=$query->select('*')->from('bestatter_capture_imports')->where($query->expr()->eq('id',$query->createNamedParameter($id)))->executeQuery()->fetchAssociative();
		if (!$row || (string)$row['user_id'] !== $this->uid()) throw new \InvalidArgumentException('Der Erfassungsbogen wurde nicht gefunden.');
		$suggestions=json_decode((string)($row['suggestions_json']??'[]'),true); if(!is_array($suggestions))$suggestions=[];
		$result=['id'=>(int)$row['id'],'caseId'=>$row['case_id']!==null?(int)$row['case_id']:null,'fileId'=>(int)$row['nextcloud_file_id'],'externalDocumentId'=>$row['external_document_id']!==null?(int)$row['external_document_id']:null,'templateKey'=>$row['template_key'],'templateVersion'=>$row['template_version'],'originalName'=>$row['original_name'],'documentSha256'=>$row['document_sha256'],'status'=>$row['import_status'],'ocrEngine'=>(string)($row['ocr_engine']??''),'mimeType'=>(string)($row['mime_type']??'application/pdf'),'suggestions'=>$suggestions,'errorMessage'=>(string)($row['error_message']??''),'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at']];
		if($result['externalDocumentId']!==null&&in_array($result['status'],['OCR_QUEUED','OCR_PROCESSING'],true)){
			$external=$this->db->getQueryBuilder();$externalRow=$external->select('sync_status','sync_error')->from('bestatter_external_documents')->where($external->expr()->eq('id',$external->createNamedParameter($result['externalDocumentId'])))->executeQuery()->fetchAssociative();
			if($externalRow){$sync=strtoupper((string)$externalRow['sync_status']);if($sync==='PROCESSING')$result['status']='OCR_PROCESSING';elseif($sync==='FAILED'){$result['status']='OCR_FAILED';$result['errorMessage']=(string)($externalRow['sync_error']??'Die OCR-Verarbeitung ist fehlgeschlagen.');}}
		}
		if($withSensitiveText)$result['ocrText']=(string)($row['ocr_text']??'');
		return $result;
	}

	private function update(int $id,array $values):void{$q=$this->db->getQueryBuilder();$q->update('bestatter_capture_imports');foreach($values as $column=>$value)$q->set($column,$q->createNamedParameter($value));$q->set('updated_at',$q->createNamedParameter(date('c')))->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();}
	private function foldersByFileId(int $fileId):array{return $this->rootFolder->getById($fileId);}
	private function uid():string{$uid=$this->userSession->getUser()?->getUID();if(!$uid)throw new \RuntimeException('Für die Schnellerfassung ist eine Anmeldung erforderlich.');return $uid;}
	private function key(string $value):string{$value=strtoupper(trim($value));if(!preg_match('/^[A-Z0-9_\-]{2,100}$/',$value))throw new \InvalidArgumentException('Der Formularschlüssel ist ungültig.');return $value;}
	private function safeName(string $name,string $mime='application/pdf'):string{$name=trim(basename(str_replace('\\','/',$name)));if($name===''||preg_match('/[\x00-\x1F\x7F]/u',$name)||strlen($name)>200)throw new \InvalidArgumentException('Bitte eine Datei mit gültigem Dateinamen auswählen.');$extension=$mime==='application/pdf'?'pdf':self::IMAGE_TYPES[$mime];if(!preg_match('/\.'.($extension==='jpg'?'jpe?g':$extension).'$/i',$name))$name=pathinfo($name,PATHINFO_FILENAME).'.'.$extension;return $name;}
	private function validateUpload(array $upload):void{$error=(int)($upload['error']??UPLOAD_ERR_NO_FILE);$size=(int)($upload['size']??0);if($error!==UPLOAD_ERR_OK)throw new \InvalidArgumentException('Bitte einen Auftragserfassungsbogen als PDF auswählen.');if($size<=0||$size>self::MAX_BYTES)throw new \InvalidArgumentException('Der Erfassungsbogen muss zwischen 1 Byte und 25 MB groß sein.');$tmp=(string)($upload['tmp_name']??'');if($tmp===''||!is_file($tmp))throw new \InvalidArgumentException('Die Upload-Datei ist nicht verfügbar.');}
	private function findOpenByHash(string $hash,string $uid):?int{$q=$this->db->getQueryBuilder();$id=$q->select('id')->from('bestatter_capture_imports')->where($q->expr()->eq('document_sha256',$q->createNamedParameter($hash)))->andWhere($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->andWhere($q->expr()->notIn('import_status',$q->createNamedParameter(self::CLOSED,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))->orderBy('id','DESC')->setMaxResults(1)->executeQuery()->fetchOne();return $id!==false&&$id!==null?(int)$id:null;}
	private function uniqueName(\OCP\Files\Folder $folder,string $name):string{if(!$folder->nodeExists($name))return $name;$base=pathinfo($name,PATHINFO_FILENAME);$ext=pathinfo($name,PATHINFO_EXTENSION)?:'pdf';for($i=2;$i<1000;$i++){ $candidate=$base.' ('.$i.').'.$ext;if(!$folder->nodeExists($candidate))return $candidate;}throw new \RuntimeException('Der Dateiname kann im Fallordner nicht eindeutig vergeben werden.');}
}
