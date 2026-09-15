<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * Sicherer Eingang für Auftragserfassungsbögen.
 * OCR-Werte sind ausschließlich Vorschläge und werden nie automatisch gespeichert.
 */
class CaptureImportService {
	private const MAX_BYTES = 25 * 1024 * 1024;

	public function __construct(
		private IDBConnection $db,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private FolderService $folders,
		private PaperlessService $paperless,
		private RecordService $records,
		private AuditService $audit,
	) {}

	public function create(array $upload, string $templateKey = 'AUFTRAGSERFASSUNG_STANDARD', string $templateVersion = '1'): array {
		$this->validateUpload($upload);
		$content = file_get_contents((string)$upload['tmp_name']);
		if ($content === false || !str_starts_with($content, '%PDF-')) throw new \InvalidArgumentException('Die ausgewählte Datei ist keine gültige PDF-Datei.');
		$uid = $this->uid();
		$hash = hash('sha256', $content);
		$duplicate = $this->findByHash($hash, $uid);
		if ($duplicate !== null) {
			throw new \InvalidArgumentException('Dieser Erfassungsbogen wurde bereits hochgeladen und wartet noch auf Prüfung.');
		}

		$name = $this->safeName((string)($upload['name'] ?? 'Auftragserfassungsbogen.pdf'));
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
			'original_name'=>$query->createNamedParameter($name), 'mime_type'=>$query->createNamedParameter('application/pdf'),
			'document_sha256'=>$query->createNamedParameter($hash), 'import_status'=>$query->createNamedParameter('UPLOADED'),
			'ocr_text'=>$query->createNamedParameter(null), 'suggestions_json'=>$query->createNamedParameter(null), 'error_message'=>$query->createNamedParameter(null),
			'created_at'=>$query->createNamedParameter($now), 'updated_at'=>$query->createNamedParameter($now), 'reviewed_at'=>$query->createNamedParameter(null),
		])->executeStatement();
		$id = (int)$this->db->lastInsertId('bestatter_capture_imports');

		$external = $this->paperless->enqueueCaptureImport([
			'id'=>$id, 'fileId'=>$file->getId(), 'documentSha256'=>$hash,
			'title'=>'Auftragserfassungsbogen – ' . pathinfo($name, PATHINFO_FILENAME),
		]);
		if ($external === null) {
			$this->update($id, ['import_status'=>'MANUAL_REVIEW', 'error_message'=>'Paperless ist nicht aktiv oder der eigene Dokumenttyp für Auftragserfassungsbögen fehlt. Der Bogen bleibt sicher gespeichert und kann manuell übertragen werden.']);
		} else {
			$this->update($id, ['external_document_id'=>(int)$external['id'], 'import_status'=>'OCR_QUEUED']);
		}
		$this->audit->logSystem('CAPTURE_IMPORT', 'UPLOADED', ['id'=>$id, 'fileId'=>$file->getId(), 'sha256'=>$hash, 'paperlessQueued'=>$external !== null]);
		return $this->get($id, true);
	}

	public function status(int $id): array {
		$item = $this->get($id, true);
		if (in_array($item['status'], ['OCR_QUEUED', 'OCR_PROCESSING'], true)) {
			try { $this->paperless->processDue(4); } catch (\Throwable) { /* Persistente Queue bleibt führend. */ }
			$item = $this->get($id, true);
		}
		return $item;
	}

	public function retry(int $id): array {
		$item = $this->get($id, true);
		if (!$item['externalDocumentId']) {
			$external = $this->paperless->enqueueCaptureImport(['id'=>$id,'fileId'=>$item['fileId'],'documentSha256'=>$item['documentSha256'],'title'=>'Auftragserfassungsbogen – '.pathinfo($item['originalName'], PATHINFO_FILENAME)]);
			if ($external === null) throw new \InvalidArgumentException('Paperless ist nicht aktiv oder nicht vollständig konfiguriert.');
			$this->update($id, ['external_document_id'=>(int)$external['id'], 'import_status'=>'OCR_QUEUED', 'error_message'=>null]);
		} else {
			$this->paperless->retry((int)$item['externalDocumentId']);
			$this->update($id, ['import_status'=>'OCR_QUEUED', 'error_message'=>null]);
		}
		return $this->get($id, true);
	}

	public function complete(int $id, array $case, array $confirmedFields): array {
		$item = $this->get($id, true);
		if ($item['caseId'] !== null && (int)$item['caseId'] !== (int)$case['id']) throw new \InvalidArgumentException('Der Erfassungsbogen wurde bereits einem anderen Fall zugeordnet.');
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
				'mimeType'=>'application/pdf', 'size'=>$target->getSize(), 'originalName'=>$item['originalName'], 'uploadedAt'=>date('c'),
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
		$result=['id'=>(int)$row['id'],'caseId'=>$row['case_id']!==null?(int)$row['case_id']:null,'fileId'=>(int)$row['nextcloud_file_id'],'externalDocumentId'=>$row['external_document_id']!==null?(int)$row['external_document_id']:null,'templateKey'=>$row['template_key'],'templateVersion'=>$row['template_version'],'originalName'=>$row['original_name'],'documentSha256'=>$row['document_sha256'],'status'=>$row['import_status'],'suggestions'=>$suggestions,'errorMessage'=>(string)($row['error_message']??''),'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at']];
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
	private function safeName(string $name):string{$name=trim(basename(str_replace('\\','/',$name)));if(!preg_match('/\.pdf$/i',$name)||preg_match('/[\x00-\x1F\x7F]/u',$name)||strlen($name)>200)throw new \InvalidArgumentException('Bitte eine PDF-Datei mit gültigem Dateinamen auswählen.');return $name;}
	private function validateUpload(array $upload):void{$error=(int)($upload['error']??UPLOAD_ERR_NO_FILE);$size=(int)($upload['size']??0);if($error!==UPLOAD_ERR_OK)throw new \InvalidArgumentException('Bitte einen Auftragserfassungsbogen als PDF auswählen.');if($size<=0||$size>self::MAX_BYTES)throw new \InvalidArgumentException('Der PDF-Erfassungsbogen muss zwischen 1 Byte und 25 MB groß sein.');$tmp=(string)($upload['tmp_name']??'');if($tmp===''||!is_file($tmp))throw new \InvalidArgumentException('Die Upload-Datei ist nicht verfügbar.');}
	private function findByHash(string $hash,string $uid):?array{$q=$this->db->getQueryBuilder();$row=$q->select('*')->from('bestatter_capture_imports')->where($q->expr()->eq('document_sha256',$q->createNamedParameter($hash)))->andWhere($q->expr()->eq('user_id',$q->createNamedParameter($uid)))->orderBy('id','DESC')->setMaxResults(1)->executeQuery()->fetchAssociative();return $row?['id'=>(int)$row['id'],'status'=>$row['import_status']]:null;}
	private function uniqueName(\OCP\Files\Folder $folder,string $name):string{if(!$folder->nodeExists($name))return $name;$base=pathinfo($name,PATHINFO_FILENAME);for($i=2;$i<1000;$i++){ $candidate=$base.' ('.$i.').pdf';if(!$folder->nodeExists($candidate))return $candidate;}throw new \RuntimeException('Der Dateiname kann im Fallordner nicht eindeutig vergeben werden.');}
}
