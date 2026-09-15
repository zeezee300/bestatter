<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;

/**
 * Optionaler Paperless-Connector. Er enthält keinerlei fachliche Rechnungslogik:
 * Ausfälle dürfen den eigenständigen Bestatter-Prozess nicht blockieren.
 */
class PaperlessService {
	private const MODES = ['OFF', 'EXPORT', 'FULL'];
	private const MAX_ATTEMPTS = 5;

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private ICrypto $crypto,
		private IClientService $clients,
		private IRootFolder $rootFolder,
		private IURLGenerator $urls,
		private AuditService $audit,
		private AssistantService $assistant,
	) {}

	public function settings(): array {
		$mode = strtoupper($this->value('paperless_mode', 'OFF'));
		if (!in_array($mode, self::MODES, true)) $mode = 'OFF';
		$baseUrl = rtrim($this->value('paperless_url'), '/');
		return [
			'mode' => $mode,
			'enabled' => $mode !== 'OFF' && $baseUrl !== '' && $this->secret('paperless_api_token') !== '',
			'baseUrl' => $baseUrl,
			'tokenConfigured' => $this->secret('paperless_api_token') !== '',
			'webhookSecretConfigured' => $this->secret('paperless_webhook_secret') !== '',
			'documentTypeId' => max(0, (int)$this->value('paperless_document_type_id', '0')),
			'tagIds' => array_values(array_filter(array_map('intval', explode(',', $this->value('paperless_tag_ids'))))),
			'captureDocumentTypeId' => max(0, (int)$this->value('paperless_capture_document_type_id', '0')),
			'captureTagIds' => array_values(array_filter(array_map('intval', explode(',', $this->value('paperless_capture_tag_ids'))))),
			'webhookUrl' => $this->urls->linkToRouteAbsolute('bestatter.paperlessWebhook.receive'),
			'fallback' => 'Nextcloud-Original und manuelle Eingangsrechnung bleiben immer verfügbar.',
		];
	}

	public function saveSettings(array $input): array {
		$mode = strtoupper(trim((string)($input['mode'] ?? 'OFF')));
		if (!in_array($mode, self::MODES, true)) throw new \InvalidArgumentException('Die Paperless-Betriebsart ist ungültig.');
		$baseUrl = $this->validateBaseUrl((string)($input['baseUrl'] ?? ''));
		if ($mode !== 'OFF' && $baseUrl === '') throw new \InvalidArgumentException('Für die aktive Paperless-Anbindung ist eine Basisadresse erforderlich.');
		$this->config->setAppValue(Application::APP_ID, 'paperless_mode', $mode);
		$this->config->setAppValue(Application::APP_ID, 'paperless_url', $baseUrl);
		$this->config->setAppValue(Application::APP_ID, 'paperless_document_type_id', (string)max(0, (int)($input['documentTypeId'] ?? 0)));
		$tagIds = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,;]+/', (string)($input['tagIds'] ?? '')) ?: []), static fn(int $id): bool => $id > 0)));
		$this->config->setAppValue(Application::APP_ID, 'paperless_tag_ids', implode(',', $tagIds));
		$this->config->setAppValue(Application::APP_ID, 'paperless_capture_document_type_id', (string)max(0, (int)($input['captureDocumentTypeId'] ?? 0)));
		$captureTagIds = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,;]+/', (string)($input['captureTagIds'] ?? '')) ?: []), static fn(int $id): bool => $id > 0)));
		$this->config->setAppValue(Application::APP_ID, 'paperless_capture_tag_ids', implode(',', $captureTagIds));
		if (trim((string)($input['apiToken'] ?? '')) !== '') $this->setSecret('paperless_api_token', trim((string)$input['apiToken']));
		if (!empty($input['clearApiToken'])) $this->config->deleteAppValue(Application::APP_ID, 'paperless_api_token');
		$generated = '';
		if (trim((string)($input['webhookSecret'] ?? '')) !== '') $this->setSecret('paperless_webhook_secret', trim((string)$input['webhookSecret']));
		elseif ($mode === 'FULL' && $this->secret('paperless_webhook_secret') === '') {
			$generated = bin2hex(random_bytes(24));
			$this->setSecret('paperless_webhook_secret', $generated);
		}
		$this->audit->logSystem('PAPERLESS_CONFIGURATION', 'UPDATED', ['mode' => $mode, 'baseUrlConfigured' => $baseUrl !== '', 'documentTypeId' => max(0, (int)($input['documentTypeId'] ?? 0)), 'tagCount' => count($tagIds), 'captureDocumentTypeId'=>max(0,(int)($input['captureDocumentTypeId']??0)), 'captureTagCount'=>count($captureTagIds)]);
		$result = $this->settings();
		if ($generated !== '') $result['generatedWebhookSecret'] = $generated;
		return $result;
	}

	public function connectionTest(): array {
		$started = microtime(true);
		try {
			$settings = $this->requireConfigured();
			$data = $this->requestJson('GET', '/api/documents/?page_size=1');
			return ['status' => 'OK', 'reachable' => true, 'durationMs' => (int)round((microtime(true) - $started) * 1000), 'apiResponse' => isset($data['count']) ? 'Dokumenten-API erreichbar' : 'API erreichbar', 'mode' => $settings['mode']];
		} catch (\Throwable $error) {
			$diagnosis = $this->connectionDiagnosis($error);
			return ['status' => 'ERROR', 'reachable' => false, 'durationMs' => (int)round((microtime(true) - $started) * 1000), 'diagnosticCode' => $diagnosis['code'], 'message' => $diagnosis['message'], 'recommendation' => $diagnosis['recommendation']];
		}
	}

	/** Verknüpft einen vorhandenen Nextcloud-Beleg asynchron mit Paperless. */
	public function enqueueIncomingInvoice(array $invoice): ?array {
		$settings = $this->settings();
		if (!$settings['enabled'] || $settings['mode'] === 'OFF' || (int)($invoice['fileId'] ?? 0) <= 0) return null;
		$query = $this->db->getQueryBuilder();
		$existing = $query->select('*')->from('bestatter_external_documents')
			->where($query->expr()->eq('provider', $query->createNamedParameter('PAPERLESS')))
			->andWhere($query->expr()->eq('record_type', $query->createNamedParameter('INCOMING_INVOICE')))
			->andWhere($query->expr()->eq('record_id', $query->createNamedParameter((int)$invoice['id'])))
			->executeQuery()->fetchAssociative();
		if ($existing) return $this->mapExternal($existing);
		$now = date('c');
		$query = $this->db->getQueryBuilder();
		$query->insert('bestatter_external_documents')->values([
			'case_id'=>$query->createNamedParameter((int)$invoice['caseId']), 'record_type'=>$query->createNamedParameter('INCOMING_INVOICE'), 'record_id'=>$query->createNamedParameter((int)$invoice['id']),
			'provider'=>$query->createNamedParameter('PAPERLESS'), 'external_document_id'=>$query->createNamedParameter(null), 'external_task_id'=>$query->createNamedParameter(null),
			'nextcloud_file_id'=>$query->createNamedParameter((int)$invoice['fileId']), 'document_sha256'=>$query->createNamedParameter($invoice['documentSha256'] ?: null),
			'title'=>$query->createNamedParameter('Eingangsrechnung ' . (string)$invoice['supplierInvoiceNumber'] . ' – ' . (string)$invoice['supplierName']),
			'sync_status'=>$query->createNamedParameter('QUEUED'), 'sync_error'=>$query->createNamedParameter(null), 'metadata_json'=>$query->createNamedParameter('{}'),
			'last_synced_at'=>$query->createNamedParameter(null), 'created_at'=>$query->createNamedParameter($now), 'updated_at'=>$query->createNamedParameter($now),
		])->executeStatement();
		$id = (int)$this->db->lastInsertId('bestatter_external_documents');
		$this->enqueue('UPLOAD', 'EXTERNAL_DOCUMENT', $id);
		return $this->external($id);
	}

	/** Separater Paperless-Kanal für Auftragserfassungsbögen; keine Rechnungslogik. */
	public function enqueueCaptureImport(array $import): ?array {
		$settings=$this->settings();
		if(!$settings['enabled']||$settings['mode']==='OFF'||$settings['captureDocumentTypeId']<=0||(int)($import['fileId']??0)<=0)return null;
		$q=$this->db->getQueryBuilder();
		$existing=$q->select('*')->from('bestatter_external_documents')->where($q->expr()->eq('provider',$q->createNamedParameter('PAPERLESS')))->andWhere($q->expr()->eq('record_type',$q->createNamedParameter('CASE_CAPTURE_FORM')))->andWhere($q->expr()->eq('record_id',$q->createNamedParameter((int)$import['id'])))->executeQuery()->fetchAssociative();
		if($existing)return $this->mapExternal($existing);
		$now=date('c');$q=$this->db->getQueryBuilder();
		$q->insert('bestatter_external_documents')->values([
			'case_id'=>$q->createNamedParameter(null),'record_type'=>$q->createNamedParameter('CASE_CAPTURE_FORM'),'record_id'=>$q->createNamedParameter((int)$import['id']),'provider'=>$q->createNamedParameter('PAPERLESS'),
			'external_document_id'=>$q->createNamedParameter(null),'external_task_id'=>$q->createNamedParameter(null),'nextcloud_file_id'=>$q->createNamedParameter((int)$import['fileId']),'document_sha256'=>$q->createNamedParameter($import['documentSha256']??null),
			'title'=>$q->createNamedParameter(mb_substr((string)($import['title']??'Auftragserfassungsbogen'),0,500)),'sync_status'=>$q->createNamedParameter('QUEUED'),'sync_error'=>$q->createNamedParameter(null),'metadata_json'=>$q->createNamedParameter('{"classification":"AUFTRAGSERFASSUNGSBOGEN"}'),
			'last_synced_at'=>$q->createNamedParameter(null),'created_at'=>$q->createNamedParameter($now),'updated_at'=>$q->createNamedParameter($now),
		])->executeStatement();
		$id=(int)$this->db->lastInsertId('bestatter_external_documents');$this->enqueue('UPLOAD','EXTERNAL_DOCUMENT',$id);return $this->external($id);
	}

	public function retry(int $externalId): array {
		$external = $this->external($externalId);
		if ($external['recordId'] === null || ($external['caseId'] === null && $external['recordType'] !== 'CASE_CAPTURE_FORM')) throw new \InvalidArgumentException('Dieser Beleg ist noch keinem Fallvorgang zugeordnet.');
		if ($external['externalDocumentId'] !== null) $this->enqueue('POLL', 'EXTERNAL_DOCUMENT', $externalId, ['taskId' => $external['externalTaskId']]);
		else $this->enqueue('UPLOAD', 'EXTERNAL_DOCUMENT', $externalId);
		$this->updateExternal($externalId, ['sync_status'=>'QUEUED', 'sync_error'=>null]);
		return $this->external($externalId);
	}

	/** Von einem Paperless-Workflow mit statischem Geheimnis aufgerufener Eingang. */
	public function receiveWebhook(string $providedSecret, array $payload): array {
		$settings = $this->settings();
		$expected = $this->secret('paperless_webhook_secret');
		if ($settings['mode'] !== 'FULL' || $expected === '' || !hash_equals($expected, trim($providedSecret))) throw new \InvalidArgumentException('Webhook nicht autorisiert.');
		$documentId = trim((string)($payload['document_id'] ?? $payload['documentId'] ?? $payload['id'] ?? ''));
		if ($documentId === '' || !preg_match('/^[0-9]+$/', $documentId)) throw new \InvalidArgumentException('Der Webhook enthält keine gültige Paperless-Dokument-ID.');
		$title = mb_substr(trim((string)($payload['title'] ?? 'Eingangsbeleg aus Paperless')), 0, 500);
		$query = $this->db->getQueryBuilder();
		$row = $query->select('id')->from('bestatter_external_documents')->where($query->expr()->eq('provider',$query->createNamedParameter('PAPERLESS')))->andWhere($query->expr()->eq('external_document_id',$query->createNamedParameter($documentId)))->executeQuery()->fetchAssociative();
		if ($row) return ['accepted'=>true, 'duplicate'=>true, 'id'=>(int)$row['id']];
		$now = date('c'); $metadata = ['event'=>(string)($payload['event'] ?? 'document_added')];
		$query = $this->db->getQueryBuilder();
		$query->insert('bestatter_external_documents')->values([
			'case_id'=>$query->createNamedParameter(null), 'record_type'=>$query->createNamedParameter('INCOMING_INVOICE'), 'record_id'=>$query->createNamedParameter(null), 'provider'=>$query->createNamedParameter('PAPERLESS'),
			'external_document_id'=>$query->createNamedParameter($documentId), 'external_task_id'=>$query->createNamedParameter(null), 'nextcloud_file_id'=>$query->createNamedParameter(null),
			'document_sha256'=>$query->createNamedParameter(null), 'title'=>$query->createNamedParameter($title), 'sync_status'=>$query->createNamedParameter('INBOX'), 'sync_error'=>$query->createNamedParameter(null),
			'metadata_json'=>$query->createNamedParameter(json_encode($metadata, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)), 'last_synced_at'=>$query->createNamedParameter($now), 'created_at'=>$query->createNamedParameter($now), 'updated_at'=>$query->createNamedParameter($now),
		])->executeStatement();
		$id = (int)$this->db->lastInsertId('bestatter_external_documents');
		$this->audit->logSystem('PAPERLESS_INBOX', 'RECEIVED', ['externalId'=>$id, 'paperlessDocumentId'=>$documentId]);
		return ['accepted'=>true, 'duplicate'=>false, 'id'=>$id];
	}

	public function inbox(): array {
		$settings = $this->settings();
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_external_documents')->where($query->expr()->eq('provider',$query->createNamedParameter('PAPERLESS')))->andWhere($query->expr()->eq('record_type',$query->createNamedParameter('INCOMING_INVOICE')))->andWhere($query->expr()->isNull('case_id'))->orderBy('created_at','DESC')->setMaxResults(200)->executeQuery()->fetchAllAssociative();
		return ['enabled'=>$settings['enabled'], 'mode'=>$settings['mode'], 'baseUrl'=>$settings['baseUrl'], 'items'=>array_map(fn(array $row):array=>$this->mapExternal($row),$rows), 'manualFallback'=>true];
	}

	public function inboxItem(int $id): array {
		$item = $this->external($id);
		if ($item['caseId'] !== null || $item['externalDocumentId'] === null) throw new \InvalidArgumentException('Dieser Paperless-Beleg ist nicht mehr als unzugeordneter Eingang verfügbar.');
		return $item;
	}

	public function downloadDocument(string $documentId): array {
		if (!preg_match('/^[0-9]+$/', $documentId)) throw new \InvalidArgumentException('Ungültige Paperless-Dokument-ID.');
		$response = $this->request('GET', '/api/documents/' . $documentId . '/download/', [
			'headers' => ['Accept' => '*/*'],
		]);
		$content = (string)$response->getBody();
		if ($content === '') throw new \RuntimeException('Paperless lieferte eine leere Dokumentdatei.');
		$header = $response->getHeader('Content-Type');
		$contentType = strtolower(is_array($header) ? implode(';', $header) : (string)$header);
		$extension = str_contains($contentType, 'pdf') ? 'pdf' : (str_contains($contentType, 'png') ? 'png' : (str_contains($contentType, 'jpeg') ? 'jpg' : 'bin'));
		return ['content'=>$content, 'extension'=>$extension, 'mimeType'=>trim(explode(';',$contentType)[0] ?? 'application/octet-stream')];
	}

	public function linkImported(int $externalId, array $invoice): array {
		$external = $this->external($externalId);
		$this->updateExternal($externalId, ['case_id'=>(int)$invoice['caseId'], 'record_id'=>(int)$invoice['id'], 'nextcloud_file_id'=>(int)$invoice['fileId'], 'document_sha256'=>$invoice['documentSha256'] ?: null, 'sync_status'=>'LINKED', 'sync_error'=>null, 'last_synced_at'=>date('c')]);
		$this->audit->log((int)$invoice['caseId'], 'INCOMING_INVOICE', (int)$invoice['id'], 'PAPERLESS_LINKED', null, ['externalDocumentId'=>$external['externalDocumentId'], 'nextcloudFileId'=>$invoice['fileId']]);
		return $this->external($externalId);
	}

	public function processDue(int $limit = 10): array {
		if (!$this->settings()['enabled']) return ['processed'=>0,'failed'=>0,'pending'=>0,'disabled'=>true];
		// Nach Container-Neustart dürfen steckengebliebene Claims erneut laufen.
		$recovery=$this->db->getQueryBuilder();$recovery->update('bestatter_integration_jobs')->set('job_status',$recovery->createNamedParameter('RETRY'))->set('next_attempt_at',$recovery->createNamedParameter(date('c')))->where($recovery->expr()->eq('job_status',$recovery->createNamedParameter('RUNNING')))->andWhere($recovery->expr()->lt('updated_at',$recovery->createNamedParameter(date('c',time()-900))))->executeStatement();
		$query=$this->db->getQueryBuilder();
		$rows=$query->select('*')->from('bestatter_integration_jobs')->where($query->expr()->eq('provider',$query->createNamedParameter('PAPERLESS')))->andWhere($query->expr()->orX($query->expr()->eq('job_status',$query->createNamedParameter('QUEUED')),$query->expr()->eq('job_status',$query->createNamedParameter('RETRY'))))->andWhere($query->expr()->lte('next_attempt_at',$query->createNamedParameter(date('c'))))->orderBy('id','ASC')->setMaxResults(max(1,min(50,$limit)))->executeQuery()->fetchAllAssociative();
		$result=['processed'=>0,'failed'=>0,'pending'=>0,'disabled'=>false];
		foreach($rows as $row){$id=(int)$row['id'];if(!$this->claim($id,(string)$row['job_status']))continue;try{$done=$this->processJob($row);if($done){$this->finishJob($id);$result['processed']++;}else{$this->delayJob($id,(int)$row['attempts'],60);$result['pending']++;}}catch(\Throwable $error){$attempts=(int)$row['attempts']+1;$this->failJob($id,$attempts,$this->safeError($error));$result['failed']++;}}
		return $result;
	}

	private function processJob(array $job): bool {
		$externalId=(int)$job['object_id']; $external=$this->external($externalId);
		if ($job['operation']==='UPLOAD') {
			$files=$this->rootFolder->getById((int)$external['nextcloudFileId']); $file=$files[0]??null;
			if(!$file instanceof File) throw new \RuntimeException('Die Nextcloud-Originaldatei wurde nicht gefunden.');
			$fields=[]; $settings=$this->settings(); $capture=$external['recordType']==='CASE_CAPTURE_FORM';
			$documentTypeId=$capture?$settings['captureDocumentTypeId']:$settings['documentTypeId'];$tagIds=$capture?$settings['captureTagIds']:$settings['tagIds'];
			if($documentTypeId>0)$fields[]=['name'=>'document_type','contents'=>(string)$documentTypeId];
			foreach($tagIds as $tag)$fields[]=['name'=>'tags','contents'=>(string)$tag];
			$fields[]=['name'=>'title','contents'=>(string)$external['title']];
			$fields[]=['name'=>'document','contents'=>$file->getContent(),'filename'=>$file->getName()];
			$response=$this->request('POST','/api/documents/post_document/',['multipart'=>$fields]);
			$decoded=json_decode((string)$response->getBody(),true); $taskId=is_string($decoded)?$decoded:(string)($decoded['task_id']??$decoded['id']??trim((string)$response->getBody(),'" \r\n'));
			if($taskId==='')throw new \RuntimeException('Paperless lieferte keine Verarbeitungs-ID.');
			$this->updateExternal($externalId,['external_task_id'=>$taskId,'sync_status'=>'PROCESSING','sync_error'=>null]);
			$this->enqueue('POLL','EXTERNAL_DOCUMENT',$externalId,['taskId'=>$taskId],$external['recordType']==='CASE_CAPTURE_FORM'?2:60);
			return true;
		}
		if ($job['operation']==='POLL') {
			$taskId=(string)($external['externalTaskId']??''); if($taskId==='')throw new \RuntimeException('Die Paperless-Verarbeitungs-ID fehlt.');
			$data=$this->requestJson('GET','/api/tasks/?task_id='.rawurlencode($taskId)); $task=($data['results'][0]??$data[0]??null);
			if(!is_array($task))return false; $status=strtoupper((string)($task['status']??''));
			if(in_array($status,['FAILURE','FAILED'],true))throw new \RuntimeException('Paperless konnte das Dokument nicht verarbeiten.');
			$documentId=(string)($task['related_document']??$task['document_id']??'');
			if(!in_array($status,['SUCCESS','COMPLETED'],true)||$documentId==='')return false;
			$this->reconcileExternalDocument($externalId,$documentId);
			if($external['recordType']==='CASE_CAPTURE_FORM'){
				$document=$this->requestJson('GET','/api/documents/'.rawurlencode($documentId).'/');$ocr=trim((string)($document['content']??''));
				if($ocr===''){$this->updateCapture((int)$external['recordId'],'REVIEW_REQUIRED',null,[],'Paperless hat noch keinen OCR-Text geliefert. Der Scan kann manuell geprüft werden.');return true;}
				$analysis=$this->assistant->analyze($ocr,'OCR_FORM');
				$this->updateCapture((int)$external['recordId'],'EXTRACTION_READY',$ocr,$analysis['suggestions']??[],null);
			}else{
				$query=$this->db->getQueryBuilder();$query->update('bestatter_incoming_invoices')->set('source_reference',$query->createNamedParameter($documentId))->where($query->expr()->eq('id',$query->createNamedParameter((int)$external['recordId'])))->executeStatement();
			}
			return true;
		}
		throw new \RuntimeException('Unbekannter Paperless-Auftrag.');
	}

	private function requestJson(string $method,string $path,array $options=[]): array {$response=$this->request($method,$path,$options);$data=json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}
	private function request(string $method,string $path,array $options=[]): object {
		$settings=$this->requireConfigured();$options['headers']=array_merge(['Authorization'=>'Token '.$this->secret('paperless_api_token'),'Accept'=>'application/json'],$options['headers']??[]);$options['timeout']=20;$options['connect_timeout']=5;
		try{$response=$this->clients->newClient()->{strtolower($method)}($settings['baseUrl'].$path,$options);}catch(\Throwable $error){$status=(int)$error->getCode();if(method_exists($error,'getResponse')){$errorResponse=$error->getResponse();if(is_object($errorResponse)&&method_exists($errorResponse,'getStatusCode'))$status=(int)$errorResponse->getStatusCode();}throw new \RuntimeException('Paperless-Netzwerkfehler.',$status,$error);}
		$status=(int)$response->getStatusCode();if($status<200||$status>=300)throw new \RuntimeException('Paperless-HTTP-Fehler.', $status);return $response;
	}

	private function connectionDiagnosis(\Throwable $error): array {
		$status = (int)$error->getCode();
		$source = strtolower($error->getMessage() . ' ' . ($error->getPrevious()?->getMessage() ?? ''));
		if ($status === 401) return ['code'=>'AUTHENTICATION_REJECTED','message'=>'Paperless hat den API-Token abgelehnt (HTTP 401).','recommendation'=>'Im Paperless-Benutzerprofil einen neuen Token erzeugen und in der Bestatter-App erneut speichern.'];
		if ($status === 403) return ['code'=>'PERMISSION_DENIED','message'=>'Der technische Paperless-Benutzer hat nicht die erforderlichen Rechte (HTTP 403).','recommendation'=>'Mindestens Dokumente hinzufügen/anzeigen/ändern sowie Dokumenttypen, Tags und Tasks anzeigen erlauben.'];
		if ($status === 404) return ['code'=>'API_NOT_FOUND','message'=>'Die Paperless-Dokumenten-API wurde unter der angegebenen Basisadresse nicht gefunden (HTTP 404).','recommendation'=>'Nur die Paperless-Basisadresse ohne /api und ohne abschließenden Pfad eintragen.'];
		if ($status >= 500 && $status <= 599) return ['code'=>'REMOTE_SERVER_ERROR','message'=>'Paperless meldet einen internen Serverfehler (HTTP '.$status.').','recommendation'=>'Paperless-Containerstatus und Paperless-Protokoll prüfen.'];
		if (str_contains($source,'local address') || str_contains($source,'private address') || str_contains($source,'remote host was blocked') || str_contains($source,'not allowed')) return ['code'=>'LOCAL_REMOTE_BLOCKED','message'=>'Nextcloud blockiert die Verbindung zu einer lokalen oder privaten Paperless-Adresse.','recommendation'=>'Vorzugsweise die interne Docker-DNS-Adresse verwenden und die Nextcloud-Einstellung allow_local_remote_servers nur nach Sicherheitsprüfung aktivieren.'];
		if (str_contains($source,'certificate') || str_contains($source,'ssl') || str_contains($source,'tls')) return ['code'=>'TLS_ERROR','message'=>'Das TLS-Zertifikat der Paperless-Adresse konnte nicht geprüft werden.','recommendation'=>'Zertifikatskette, Hostnamen und Reverse-Proxy-Konfiguration von Paperless korrigieren; die Zertifikatsprüfung nicht abschalten.'];
		if (str_contains($source,'timed out') || str_contains($source,'timeout')) return ['code'=>'TIMEOUT','message'=>'Paperless hat nicht innerhalb des Zeitlimits geantwortet.','recommendation'=>'Containerstatus, DNS, Reverse Proxy und Auslastung von Paperless prüfen.'];
		if (str_contains($source,'could not resolve') || str_contains($source,'name or service not known') || str_contains($source,'getaddrinfo')) return ['code'=>'DNS_ERROR','message'=>'Der Paperless-Hostname kann aus dem Nextcloud-Container nicht aufgelöst werden.','recommendation'=>'Docker-Netzwerk, DNS-Alias und die eingetragene Paperless-Basisadresse prüfen.'];
		if (str_contains($source,'connection refused') || str_contains($source,'failed to connect')) return ['code'=>'CONNECTION_REFUSED','message'=>'Die Paperless-Adresse ist erreichbar, nimmt aber auf dem Zielport keine Verbindung an.','recommendation'=>'Paperless-Webserver, Container-Port und Reverse Proxy prüfen.'];
		if (str_contains($source,'not fully configured') || str_contains($source,'nicht vollständig konfiguriert')) return ['code'=>'CONFIGURATION_INCOMPLETE','message'=>'Paperless ist in der Bestatter-App deaktiviert oder noch nicht vollständig konfiguriert.','recommendation'=>'Betriebsart, Basisadresse und API-Token speichern und danach erneut testen.'];
		return ['code'=>'CONNECTION_FAILED','message'=>'Die Verbindung zur Paperless-API konnte nicht hergestellt werden.','recommendation'=>'Erreichbarkeit aus dem Nextcloud-Container und anschließend das Nextcloud-Protokoll prüfen.'];
	}

	private function requireConfigured(): array {$settings=$this->settings();if(!$settings['enabled'])throw new \RuntimeException('Paperless ist nicht vollständig konfiguriert oder deaktiviert.');return $settings;}
	private function validateBaseUrl(string $value): string {$value=rtrim(trim($value),'/');if($value==='')return '';if(filter_var($value,FILTER_VALIDATE_URL)===false||!in_array(strtolower((string)parse_url($value,PHP_URL_SCHEME)),['http','https'],true)||parse_url($value,PHP_URL_USER)!==null)throw new \InvalidArgumentException('Die Paperless-Basisadresse ist ungültig.');return $value;}
	private function value(string $key,string $default=''): string {return $this->config->getAppValue(Application::APP_ID,$key,$default);}
	private function setSecret(string $key,string $value): void {$this->config->setAppValue(Application::APP_ID,$key,$this->crypto->encrypt($value));}
	private function secret(string $key): string {$value=$this->value($key);if($value==='')return '';try{return $this->crypto->decrypt($value);}catch(\Throwable){return '';}}
	private function enqueue(string $operation,string $objectType,int $objectId,array $payload=[],int $delay=0): void {$q=$this->db->getQueryBuilder();$now=date('c');$q->insert('bestatter_integration_jobs')->values(['provider'=>$q->createNamedParameter('PAPERLESS'),'operation'=>$q->createNamedParameter($operation),'object_type'=>$q->createNamedParameter($objectType),'object_id'=>$q->createNamedParameter($objectId),'payload_json'=>$q->createNamedParameter(json_encode($payload,JSON_THROW_ON_ERROR)),'job_status'=>$q->createNamedParameter('QUEUED'),'attempts'=>$q->createNamedParameter(0),'next_attempt_at'=>$q->createNamedParameter(date('c',time()+$delay)),'last_error'=>$q->createNamedParameter(null),'created_at'=>$q->createNamedParameter($now),'updated_at'=>$q->createNamedParameter($now)])->executeStatement();}
	private function claim(int $id,string $status): bool {$q=$this->db->getQueryBuilder();return $q->update('bestatter_integration_jobs')->set('job_status',$q->createNamedParameter('RUNNING'))->set('updated_at',$q->createNamedParameter(date('c')))->where($q->expr()->eq('id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('job_status',$q->createNamedParameter($status)))->executeStatement()===1;}
	private function finishJob(int $id): void {$q=$this->db->getQueryBuilder();$q->update('bestatter_integration_jobs')->set('job_status',$q->createNamedParameter('DONE'))->set('last_error',$q->createNamedParameter(null))->set('updated_at',$q->createNamedParameter(date('c')))->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();}
	private function delayJob(int $id,int $attempts,int $seconds): void {$q=$this->db->getQueryBuilder();$q->update('bestatter_integration_jobs')->set('job_status',$q->createNamedParameter('RETRY'))->set('attempts',$q->createNamedParameter($attempts))->set('next_attempt_at',$q->createNamedParameter(date('c',time()+$seconds)))->set('updated_at',$q->createNamedParameter(date('c')))->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();}
	private function failJob(int $id,int $attempts,string $error): void {$status=$attempts>=self::MAX_ATTEMPTS?'FAILED':'RETRY';$q=$this->db->getQueryBuilder();$q->update('bestatter_integration_jobs')->set('job_status',$q->createNamedParameter($status))->set('attempts',$q->createNamedParameter($attempts))->set('last_error',$q->createNamedParameter($error))->set('next_attempt_at',$q->createNamedParameter(date('c',time()+min(3600,60*(2**min(5,$attempts))))))->set('updated_at',$q->createNamedParameter(date('c')))->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();$job=$this->job($id);if($job)$this->updateExternal((int)$job['object_id'],['sync_status'=>$status,'sync_error'=>$error]);}
	private function job(int $id): ?array {$q=$this->db->getQueryBuilder();$row=$q->select('*')->from('bestatter_integration_jobs')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeQuery()->fetchAssociative();return $row?:null;}
	private function external(int $id): array {$q=$this->db->getQueryBuilder();$row=$q->select('*')->from('bestatter_external_documents')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeQuery()->fetchAssociative();if(!$row)throw new \InvalidArgumentException('Die externe Dokumentverknüpfung wurde nicht gefunden.');return $this->mapExternal($row);}
	private function reconcileExternalDocument(int $id,string $documentId): void {$q=$this->db->getQueryBuilder();$other=$q->select('id','case_id')->from('bestatter_external_documents')->where($q->expr()->eq('provider',$q->createNamedParameter('PAPERLESS')))->andWhere($q->expr()->eq('external_document_id',$q->createNamedParameter($documentId)))->andWhere($q->expr()->neq('id',$q->createNamedParameter($id)))->executeQuery()->fetchAssociative();if($other&&$other['case_id']===null){$delete=$this->db->getQueryBuilder();$delete->delete('bestatter_external_documents')->where($delete->expr()->eq('id',$delete->createNamedParameter((int)$other['id'])))->executeStatement();}elseif($other){throw new \RuntimeException('Das Paperless-Dokument ist bereits mit einem anderen Fallvorgang verknüpft.');}$this->updateExternal($id,['external_document_id'=>$documentId,'sync_status'=>'READY','sync_error'=>null,'last_synced_at'=>date('c')]);}
	private function updateExternal(int $id,array $values): void {$q=$this->db->getQueryBuilder();$q->update('bestatter_external_documents');foreach($values as $column=>$value)$q->set($column,$q->createNamedParameter($value));$q->set('updated_at',$q->createNamedParameter(date('c')))->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();}
	private function updateCapture(int $id,string $status,?string $ocr,array $suggestions,?string $error):void{$q=$this->db->getQueryBuilder();$q->update('bestatter_capture_imports')->set('import_status',$q->createNamedParameter($status))->set('ocr_text',$q->createNamedParameter($ocr))->set('suggestions_json',$q->createNamedParameter(json_encode($suggestions,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)))->set('error_message',$q->createNamedParameter($error))->set('updated_at',$q->createNamedParameter(date('c')))->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeStatement();}
	private function mapExternal(array $row): array {$metadata=json_decode((string)($row['metadata_json']??'{}'),true)?:[];return ['id'=>(int)$row['id'],'caseId'=>$row['case_id']!==null?(int)$row['case_id']:null,'recordType'=>$row['record_type'],'recordId'=>$row['record_id']!==null?(int)$row['record_id']:null,'provider'=>$row['provider'],'externalDocumentId'=>$row['external_document_id']!==null?(string)$row['external_document_id']:null,'externalTaskId'=>$row['external_task_id']!==null?(string)$row['external_task_id']:null,'nextcloudFileId'=>$row['nextcloud_file_id']!==null?(int)$row['nextcloud_file_id']:null,'documentSha256'=>$row['document_sha256'],'title'=>(string)($row['title']??''),'syncStatus'=>$row['sync_status'],'syncError'=>(string)($row['sync_error']??''),'metadata'=>$metadata,'lastSyncedAt'=>$row['last_synced_at'],'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at']];}
	private function safeError(\Throwable $error): string {return mb_substr(preg_replace('/Token\s+\S+/i','Token [geschützt]',$error->getMessage())??'Paperless-Verarbeitung fehlgeschlagen.',0,500);}
}
