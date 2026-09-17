<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCA\Bestatter\BackgroundJob\MaintenanceJob;
use OCA\Bestatter\BackgroundJob\PaperlessSyncJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\Server;

/** Wiederholungssichere, ausschließlich explizit aufrufbare Datenbereinigung. */
class PurgeService {
	private const DELETE_TABLES = [
		'bestatter_integration_jobs','bestatter_external_documents','bestatter_capture_imports','bestatter_incoming_items','bestatter_incoming_invoices',
		'bestatter_invoice_items','bestatter_invoices','bestatter_commercial_docs','bestatter_workflow_runs','bestatter_schedule_history',
		'bestatter_case_automation','bestatter_case_services','bestatter_mail_outbox','bestatter_records','bestatter_cases','bestatter_invoice_sequences','bestatter_assistant_rules',
		'bestatter_burial_variant_rules','bestatter_surcharge_rules','bestatter_article_components','bestatter_articles','bestatter_article_groups','bestatter_checklist_items','bestatter_checklist_templates',
		'bestatter_choice_items','bestatter_choice_lists','bestatter_document_templates','bestatter_dereg_templates','bestatter_workflows',
		'bestatter_resources','bestatter_schedule_types','bestatter_branches','bestatter_country_profiles','bestatter_invoice_settings','bestatter_audit_log',
	];

	public function __construct(private IDBConnection $db, private IRootFolder $rootFolder, private IGroupManager $groups, private IConfig $config, private InstallationConfigService $installation, private BackupService $backup, private IJobList $jobs) {}

	public function preview(): array {
		$holds = $this->legalHolds();
		return [
			'tables' => $this->tableCounts(), 'legalHolds' => $holds, 'calendarObjects' => count($this->calendarTargets()),
			'activityEntries' => $this->activityCount(), 'fileRoots' => $this->existingRoots(), 'appConfigKeys' => count($this->config->getAppKeys(Application::APP_ID)),
			'backgroundJobs' => [MaintenanceJob::class, PaperlessSyncJob::class],
			'preserved' => ['Nextcloud-Benutzer und Gruppen', 'Sicherungspakete', 'externe Paperless-Dokumente', 'nicht eindeutig verwaltete Dateien'],
		];
	}

	public function purge(string $packagePath): array {
		$inspection = $this->backup->inspectPackage($packagePath);
		if (($inspection['status'] ?? 'WARN') !== 'OK') throw new \RuntimeException('Purge abgebrochen: Das angegebene Sicherungspaket ist unvollständig.');
		if ($this->legalHolds() !== []) throw new \RuntimeException('Purge abgebrochen: Mindestens ein Fall steht unter Legal Hold.');
		$calendar = $this->deleteCalendarObjects();
		if ($calendar['errors'] !== []) throw new \RuntimeException('Purge abgebrochen: Nicht alle von der App angelegten Kalenderobjekte konnten entfernt werden. Details stehen im Trockenlauf beziehungsweise Serverprotokoll.');
		$files = $this->deleteRoots();
		if ($files['errors'] !== []) throw new \RuntimeException('Purge abgebrochen: Nicht alle angekündigten Dateiwurzeln konnten entfernt werden. Die Datenbank blieb unverändert.');
		$deleted = [];
		$this->db->beginTransaction();
		try {
			$activity = $this->db->getQueryBuilder(); $deleted['activity'] = $activity->delete('activity')->where($activity->expr()->eq('app', $activity->createNamedParameter(Application::APP_ID)))->executeStatement();
			foreach (self::DELETE_TABLES as $table) { $query = $this->db->getQueryBuilder(); $deleted[$table] = $query->delete($table)->executeStatement(); }
			$this->db->commit();
		} catch (\Throwable $error) { try { $this->db->rollBack(); } catch (\Throwable) {} throw new \RuntimeException('Die Datenbankbereinigung wurde zurückgerollt: ' . $error->getMessage(), 0, $error); }
		foreach ($this->config->getAppKeys(Application::APP_ID) as $key) $this->config->deleteAppValue(Application::APP_ID, $key);
		$this->jobs->remove(MaintenanceJob::class); $this->jobs->remove(PaperlessSyncJob::class);
		return ['purged' => true, 'verifiedPackage' => $packagePath, 'deletedRows' => $deleted, 'calendar' => $calendar, 'files' => $files, 'preserved' => ['Benutzer', 'Gruppen', 'Sicherungspakete', 'externe Paperless-Originale']];
	}

	private function tableCounts(): array { $result = []; foreach (self::DELETE_TABLES as $table) { $query = $this->db->getQueryBuilder(); $result[$table] = (int)$query->select($query->func()->count('*', 'count'))->from($table)->executeQuery()->fetchOne(); } return $result; }
	private function legalHolds(): array { $query = $this->db->getQueryBuilder(); return $query->select('id','case_number','retention_hold_reason')->from('bestatter_cases')->where($query->expr()->eq('retention_hold',$query->createNamedParameter(1)))->executeQuery()->fetchAllAssociative(); }
	private function activityCount(): int { $query = $this->db->getQueryBuilder(); return (int)$query->select($query->func()->count('*','count'))->from('activity')->where($query->expr()->eq('app',$query->createNamedParameter(Application::APP_ID)))->executeQuery()->fetchOne(); }
	private function calendarTargets(): array {
		$query=$this->db->getQueryBuilder();$rows=$query->select('payload','nextcloud_calendar_key','nextcloud_uri')->from('bestatter_records')->executeQuery()->fetchAllAssociative();$targets=[];
		foreach($rows as $row){$this->addCalendarTarget($targets,(int)($row['nextcloud_calendar_key']??0),(string)($row['nextcloud_uri']??''));$payload=json_decode((string)($row['payload']??'{}'),true)?:[];$nextcloud=(array)($payload['nextcloud']??[]);$this->addCalendarTarget($targets,(int)($nextcloud['calendarKey']??0),(string)($nextcloud['uri']??''));foreach((array)($payload['nextcloudCopies']??[]) as $copy)$this->addCalendarTarget($targets,(int)($copy['calendarKey']??0),(string)($copy['uri']??''));}
		return array_values($targets);
	}
	private function addCalendarTarget(array &$targets,int $calendarId,string $uri):void{if($calendarId>0&&trim($uri)!=='')$targets[$calendarId.':'.$uri]=['calendarId'=>$calendarId,'uri'=>$uri];}
	private function deleteCalendarObjects():array{$result=['planned'=>0,'deleted'=>0,'missing'=>0,'errors'=>[]];$targets=$this->calendarTargets();$result['planned']=count($targets);foreach($targets as $target){try{$this->calDavBackend()->deleteCalendarObject($target['calendarId'],$target['uri']);$result['deleted']++;}catch(\Throwable $error){if(str_contains(strtolower($error->getMessage()),'not found')||str_contains($error->getMessage(),'404'))$result['missing']++;else$result['errors'][]=['uri'=>$target['uri'],'message'=>$error->getMessage()];}}return $result;}
	private function calDavBackend():object{$class='\\OCA\\DAV\\CalDAV\\CalDavBackend';if(!class_exists($class))throw new \RuntimeException('Das Nextcloud-DAV-Backend ist nicht verfügbar.');return Server::get($class);}
	private function managedRoots():array{$paths=[$this->installation->casesPath(),$this->installation->templatesPath(),$this->installation->storageRoot().'/'.$this->installation->articleImagesFolder(),$this->installation->storageRoot().'/'.$this->installation->assistantFolder(),$this->installation->storageRoot().'/.Erfassungseingang'];$result=[];foreach($this->bestatterUids() as $uid)foreach(array_unique($paths)as $path)$result[]=['uid'=>$uid,'path'=>$path];return $result;}
	private function existingRoots():array{$result=[];foreach($this->managedRoots()as $root){try{$folder=$this->existingPath($this->rootFolder->getUserFolder($root['uid']),$root['path']);if($folder!==null)$result[]=$root['uid'].'/'.$root['path'];}catch(\Throwable){}}return $result;}
	private function deleteRoots():array{$result=['deleted'=>[],'missing'=>[],'errors'=>[]];foreach($this->managedRoots()as $root){try{$folder=$this->existingPath($this->rootFolder->getUserFolder($root['uid']),$root['path']);if($folder===null){$result['missing'][]=$root['uid'].'/'.$root['path'];continue;}$folder->delete();$result['deleted'][]=$root['uid'].'/'.$root['path'];}catch(\Throwable $error){$result['errors'][]=['path'=>$root['uid'].'/'.$root['path'],'message'=>$error->getMessage()];}}return $result;}
	private function bestatterUids():array{$uids=[];foreach(array_unique([$this->installation->memberGroup(),...$this->installation->adminGroups()])as $name){$group=$this->groups->get($name);if($group!==null)foreach($group->getUsers()as $user)$uids[$user->getUID()]=$user->getUID();}$uids=array_values($uids);sort($uids,SORT_NATURAL|SORT_FLAG_CASE);return $uids;}
	private function existingPath(Folder $base,string $path):?Folder{$folder=$base;foreach(explode('/',trim($path,'/'))as $name){if($name===''||!$folder->nodeExists($name))return null;$node=$folder->get($name);if(!$node instanceof Folder)return null;$folder=$node;}return $folder;}
}
