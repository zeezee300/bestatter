<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCA\Bestatter\BackgroundJob\MaintenanceJob;
use OCA\Bestatter\BackgroundJob\PaperlessSyncJob;
use OCA\Bestatter\BackgroundJob\GroupwareRetryJob;
use OCA\Bestatter\BackgroundJob\ScheduledBackupJob;
use OCA\Bestatter\BackgroundJob\ProcessScheduleJob;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;

/**
 * Wiederholungssichere, ausschließlich explizit aufrufbare Datenbereinigung – bis hin zum vollständigen Entfernen
 * der App aus Nextcloud (removeApp). 0.75.1: Fehlende Sicherung und Legal Holds sind Hinweise, die der
 * Administrator ausdrücklich bestätigen muss, keine unumgehbaren Sperren.
 */
class PurgeService {
	private const DELETE_TABLES = [
		'bestatter_case_labels','bestatter_label_values','bestatter_labels',
		'bestatter_document_kinds',
		'bestatter_integration_jobs','bestatter_external_documents','bestatter_capture_imports','bestatter_order_form_entries','bestatter_order_forms','bestatter_incoming_items','bestatter_incoming_invoices',
		'bestatter_invoice_items','bestatter_invoices','bestatter_commercial_docs','bestatter_action_runs','bestatter_process_runs','bestatter_schedule_history',
		'bestatter_case_services','bestatter_side_orders','bestatter_mail_outbox','bestatter_dereg_mail_outbox','bestatter_record_assignees','bestatter_records','bestatter_cases','bestatter_invoice_sequences','bestatter_assistant_rules',
		'bestatter_burial_variant_rules','bestatter_surcharge_rules','bestatter_bom_items','bestatter_boms','bestatter_price_list_items','bestatter_price_lists','bestatter_articles','bestatter_article_groups','bestatter_article_areas',
		'bestatter_choice_items','bestatter_choice_lists','bestatter_document_templates','bestatter_dereg_templates','bestatter_process_steps','bestatter_process_templates',
		'bestatter_resources','bestatter_schedule_types','bestatter_branches','bestatter_branch_logos','bestatter_country_profiles','bestatter_invoice_settings','bestatter_audit_log',
	];

	public function __construct(private IDBConnection $db, private IRootFolder $rootFolder, private IGroupManager $groups, private IConfig $config, private InstallationConfigService $installation, private BackupService $backup, private IJobList $jobs, private CaseStorageService $storage, private PermanentDeletion $permanent, private AppCalendarObjects $calendar, private INotificationManager $notifications, private IAppManager $apps) {}

	/** Hintergrundjobs laut appinfo/info.xml. */
	private const JOBS = [MaintenanceJob::class, PaperlessSyncJob::class, ProcessScheduleJob::class, GroupwareRetryJob::class, ScheduledBackupJob::class];

	public function preview(): array {
		$holds = $this->legalHolds();
		return [
			'tables' => $this->tableCounts(), 'legalHolds' => $holds, 'calendarObjects' => count($this->calendar->targets()),
			'activityEntries' => $this->activityCount(), 'fileRoots' => $this->existingRoots(), 'appConfigKeys' => count($this->config->getAppKeys(Application::APP_ID)),
			'backgroundJobs' => self::JOBS,
			'appTables' => count($this->appTables()),
			'notices' => $this->notices($holds),
			'preserved' => ['Nextcloud-Benutzer und Gruppen (auch das Ablagekonto)', 'Sicherungspakete', 'externe Paperless-Dokumente', 'nicht eindeutig verwaltete Dateien'],
		];
	}

	/** @return list<string> */
	private function notices(array $holds): array {
		$notices = ['Alle Falldaten, Rechnungen, Aufträge und Eingangsrechnungen der App werden gelöscht – auch aus den Fallordnern und am Papierkorb vorbei. Die gesetzliche Aufbewahrung muss die Buchhaltung (z. B. DATEV) sicherstellen.'];
		if ($holds !== []) $notices[] = count($holds) . ' Fall/Fälle stehen unter Legal Hold (' . implode(', ', array_slice(array_map(static fn(array $row): string => (string)$row['case_number'], $holds), 0, 10)) . '). Löschen nur mit --despite-legal-hold.';
		return $notices;
	}

	/**
	 * @param string $packagePath Absoluter Pfad eines vollständigen Sicherungspakets ('' = ohne Sicherung, nur mit $withoutBackup)
	 * @param bool $removeApp Zusätzlich Tabellen und Migrationseinträge entfernen und die App deaktivieren; danach genügt „occ app:remove bestatter“.
	 */
	public function purge(string $packagePath, bool $withoutBackup = false, bool $despiteLegalHold = false, bool $removeApp = false): array {
		$warnings = [];
		if ($packagePath !== '') {
			$inspection = $this->backup->inspectPackage($packagePath);
			if (($inspection['status'] ?? 'WARN') !== 'OK') throw new \RuntimeException('Purge abgebrochen: Das angegebene Sicherungspaket ist unvollständig. Ohne gültige Sicherung nur mit --without-backup.');
		} elseif (!$withoutBackup) {
			throw new \RuntimeException('Purge abgebrochen: Kein Sicherungspaket angegeben. Mit --backup-package=… sichern oder ausdrücklich --without-backup angeben.');
		} else {
			$warnings[] = 'Ohne Sicherungspaket ausgeführt – die Daten sind nicht wiederherstellbar.';
		}
		$holds = $this->legalHolds();
		if ($holds !== [] && !$despiteLegalHold) throw new \RuntimeException('Purge abgebrochen: ' . count($holds) . ' Fall/Fälle stehen unter Legal Hold. Nur mit --despite-legal-hold fortsetzen.');
		if ($holds !== []) $warnings[] = count($holds) . ' Fall/Fälle trotz Legal Hold gelöscht.';
		$calendar = $this->calendar->delete($this->calendar->targets());
		if ($calendar['errors'] !== []) $warnings[] = count($calendar['errors']) . ' Kalendereintrag/-einträge konnten nicht entfernt werden und müssen in den Kalendern gelöscht werden.';
		$files = $this->deleteRoots();
		if ($files['errors'] !== []) throw new \RuntimeException('Purge abgebrochen: Nicht alle angekündigten Dateiwurzeln konnten entfernt werden. Die Datenbank blieb unverändert.');
		$deleted = [];
		$this->db->beginTransaction();
		try {
			if ($this->db->tableExists('activity')) { $activity = $this->db->getQueryBuilder(); $deleted['activity'] = $activity->delete('activity')->where($activity->expr()->eq('app', $activity->createNamedParameter(Application::APP_ID)))->executeStatement(); }
			foreach (self::DELETE_TABLES as $table) { $query = $this->db->getQueryBuilder(); $deleted[$table] = $query->delete($table)->executeStatement(); }
			$this->db->commit();
		} catch (\Throwable $error) { try { $this->db->rollBack(); } catch (\Throwable) {} throw new \RuntimeException('Die Datenbankbereinigung wurde zurückgerollt: ' . $error->getMessage(), 0, $error); }
		foreach ($this->config->getAppKeys(Application::APP_ID) as $key) $this->config->deleteAppValue(Application::APP_ID, $key);
		$this->config->deleteAppFromAllUsers(Application::APP_ID);
		foreach (self::JOBS as $job) $this->jobs->remove($job);
		try { $notification = $this->notifications->createNotification(); $notification->setApp(Application::APP_ID); $this->notifications->markProcessed($notification); }
		catch (\Throwable $error) { $warnings[] = 'Benachrichtigungen der App konnten nicht entfernt werden: ' . $error->getMessage(); }
		$removed = $removeApp ? $this->removeApp() : null;
		return [
			'purged' => true, 'verifiedPackage' => $packagePath !== '' ? $packagePath : null, 'deletedRows' => $deleted, 'calendar' => $calendar, 'files' => $files,
			'appRemoved' => $removed, 'warnings' => $warnings,
			'preserved' => ['Benutzer und Gruppen (auch das Ablagekonto)', 'Sicherungspakete', 'externe Paperless-Originale'],
			'nextSteps' => $removeApp ? ['occ app:remove bestatter   (entfernt die Programmdateien)'] : ['Optional: erneut mit --remove-app, um Tabellen zu entfernen und die App zu deaktivieren.'],
		];
	}

	/** Entfernt Tabellen und Migrationseinträge (eine Neuinstallation beginnt dann sauber) und deaktiviert die App. */
	private function removeApp(): array {
		$tables = $this->appTables();
		foreach ($tables as $table) $this->db->dropTable($table);
		$migrations = 0;
		if ($this->db->tableExists('migrations')) { $query = $this->db->getQueryBuilder(); $migrations = $query->delete('migrations')->where($query->expr()->eq('app', $query->createNamedParameter(Application::APP_ID)))->executeStatement(); }
		$this->apps->disableApp(Application::APP_ID);
		return ['droppedTables' => $tables, 'migrationEntries' => $migrations, 'disabled' => true];
	}

	/** @return list<string> Tabellennamen (ohne Präfix) aller App-Tabellen, auch Altbestand früherer Versionen. */
	private function appTables(): array {
		$dbPrefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');
		$tables = [];
		foreach ($this->db->createSchema()->getTables() as $table) {
			$name = trim($table->getName(), '`"');
			if (str_starts_with($name, $dbPrefix . 'bestatter_')) $tables[] = substr($name, strlen($dbPrefix));
		}
		sort($tables);
		return $tables;
	}

	private function tableCounts(): array { $result = []; foreach (self::DELETE_TABLES as $table) { $query = $this->db->getQueryBuilder(); $result[$table] = (int)$query->select($query->func()->count('*', 'count'))->from($table)->executeQuery()->fetchOne(); } return $result; }
	private function legalHolds(): array { $query = $this->db->getQueryBuilder(); return $query->select('id','case_number','retention_hold_reason')->from('bestatter_cases')->where($query->expr()->eq('retention_hold',$query->createNamedParameter(1)))->executeQuery()->fetchAllAssociative(); }
	private function activityCount(): int { $query = $this->db->getQueryBuilder(); return (int)$query->select($query->func()->count('*','count'))->from('activity')->where($query->expr()->eq('app',$query->createNamedParameter(Application::APP_ID)))->executeQuery()->fetchOne(); }
	private function managedPaths():array{return array_values(array_unique([$this->installation->casesPath(),$this->installation->templatesPath(),$this->installation->articleImagesPath(),$this->installation->assistantPath(),$this->installation->captureInboxPath()]));}
	/** 0.70.0: zentrale Ablage und persönliche Altbestände, ohne doppelt gezählte freigegebene Ordner. */
	private function existingRoots():array{$result=[];foreach($this->managedPaths()as $path){try{foreach($this->storage->existingFolders($path)as $entry)$result[]=$entry['label'];}catch(\Throwable){}}return $result;}
	private function deleteRoots():array{$result=['deleted'=>[],'missing'=>[],'errors'=>[]];foreach($this->managedPaths()as $path){try{$entries=$this->storage->existingFolders($path);}catch(\Throwable $error){$result['errors'][]=['path'=>$path,'message'=>$error->getMessage()];continue;}if($entries===[]){$result['missing'][]=$path;continue;}foreach($entries as $entry){try{$this->permanent->delete($entry['folder']);$result['deleted'][]=$entry['label'];}catch(\Throwable $error){$result['errors'][]=['path'=>$entry['label'],'message'=>$error->getMessage()];}}}return $result;}
	private function bestatterUids():array{$uids=[];foreach(array_unique([$this->installation->memberGroup(),...$this->installation->adminGroups()])as $name){$group=$this->groups->get($name);if($group!==null)foreach($group->getUsers()as $user)$uids[$user->getUID()]=$user->getUID();}$uids=array_values($uids);sort($uids,SORT_NATURAL|SORT_FLAG_CASE);return $uids;}
	private function existingPath(Folder $base,string $path):?Folder{$folder=$base;foreach(explode('/',trim($path,'/'))as $name){if($name===''||!$folder->nodeExists($name))return null;$node=$folder->get($name);if(!$node instanceof Folder)return null;$folder=$node;}return $folder;}
}
