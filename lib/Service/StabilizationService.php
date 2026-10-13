<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;

/**
 * Read-only operational checks used during acceptance and later support.
 * The result deliberately contains counts and configuration names only, never
 * personal case data.
 */
class StabilizationService {
	public function __construct(
		private IDBConnection $db,
		private TeamService $team,
		private ConfigurationService $configuration,
		private AssistantService $assistant,
		private EInvoiceComplianceService $eInvoiceCompliance,
		private IConfig $config,
		private IAppManager $appManager,
		private InstallationConfigService $installationConfig,
		private IGroupManager $groupManager,
		private PaperlessService $paperless,
		private CaseStorageService $storage,
		private GroupwareRepairService $groupwareRepair,
		private BackupService $backup,
		private PaperlessSetupCheckService $paperlessSetup,
		private GroupLookupService $groupLookup,
	) {}

	public function report(): array {
		$team = $this->team->overview();
		$branches = $this->configuration->branches();
		$templates = $this->configuration->documents();
		$templateFiles = array_column($this->configuration->documentTemplateOptions()['files'] ?? [], 'fileName');
		$activeBranches = array_values(array_filter($branches, static fn(array $branch): bool => (bool)($branch['active'] ?? false)));
		$activeTemplates = array_values(array_filter($templates, static fn(array $template): bool => (bool)($template['active'] ?? false)));
		$missingTemplateFiles = array_values(array_map(
			static fn(array $template): string => (string)($template['name'] ?? $template['key'] ?? 'Unbekannte Vorlage'),
			array_filter($activeTemplates, static fn(array $template): bool => !self::templateSourceAvailable((string)($template['fileName'] ?? ''), $templateFiles)),
		));
		$incompleteBranches = array_values(array_map(
			static fn(array $branch): string => (string)($branch['name'] ?? $branch['key'] ?? 'Unbekannte Niederlassung'),
			array_filter($activeBranches, static fn(array $branch): bool => self::missingBranchFields($branch) !== []),
		));
		$branchDetails = array_values(array_map(static function (array $branch): string {
			$missing = self::missingBranchFields($branch);
			return (string)($branch['name'] ?? $branch['key'] ?? 'Niederlassung') . ': fehlt ' . implode(', ', $missing);
		}, array_filter($activeBranches, static fn(array $branch): bool => self::missingBranchFields($branch) !== [])));
		$templateDetails = array_values(array_map(static fn(array $template): string => (string)($template['name'] ?? $template['key'] ?? 'Vorlage') . ' → ' . (string)($template['fileName'] ?? 'keine Datei'), array_filter($activeTemplates, static fn(array $template): bool => !self::templateSourceAvailable((string)($template['fileName'] ?? ''), $templateFiles))));

		$dependencies = DependencyCheck::report();
		$checks = [
			// 0.87.0: Paket statt Quellcode-Ordner (vendor/)
			$this->check('php_dependencies', 'PHP-Abhängigkeiten', $dependencies['status'], $dependencies['message'], $dependencies['hint'], $dependencies['missing']),
			$this->installationCheck(),
			$this->roleGroupsCheck(),
			$this->storageCheck(count($team['members'] ?? [])),
			$this->check('access', 'Rollen und Zugriff', count($team['members'] ?? []) > 0 ? 'OK' : 'ERROR', count($team['members'] ?? []) . ' Mitglied(er) der Gruppe ' . $this->installationConfig->memberGroup() . '; angemeldeter Benutzer ist ' . (($team['isBestatterAdmin'] ?? false) ? 'Administrator.' : 'Mitglied.')),
			$this->check('branches', 'Niederlassungen', $activeBranches === [] ? 'ERROR' : ($incompleteBranches === [] ? 'OK' : 'WARN'), $activeBranches === [] ? 'Keine aktive Niederlassung vorhanden.' : (count($activeBranches) . ' aktive Niederlassung(en)' . ($incompleteBranches === [] ? ' sind für Rechnungen vollständig gepflegt.' : '; unvollständig: ' . implode(', ', $incompleteBranches) . '.')), $incompleteBranches === [] ? '' : 'Platzhalter-Niederlassungen deaktivieren oder die für Rechnungen erforderlichen Adress- und Bankdaten ergänzen.', $branchDetails, ['view' => 'administration', 'tab' => 'branches', 'label' => 'Niederlassungen öffnen']),
			$this->check('templates', 'Dokumentvorlagen', $activeTemplates === [] || $missingTemplateFiles !== [] ? 'ERROR' : 'OK', count($activeTemplates) . ' aktive Vorlage(n)' . ($missingTemplateFiles === [] ? ' mit verwendbarer Quelldatei.' : '; keine verwendbare Quelldatei bei: ' . implode(', ', $missingTemplateFiles) . '.'), $missingTemplateFiles === [] ? 'Berücksichtigt werden alle aktiven DOCX-Vorlagen in ' . $this->installationConfig->templatesPath() . ' sowie mitgelieferte Paketvorlagen.' : 'Die genannten Dateien im konfigurierten Vorlagenordner bereitstellen oder die betroffenen Vorlagen deaktivieren.', $templateDetails, ['view' => 'customizing', 'tab' => 'documents', 'label' => 'Dokumentvorlagen öffnen']),
			$this->syncCheck(),
			$this->groupwareRepairCheck(),
			$this->remoteDeletionCheck(),
			$this->orphanCheck(),
			$this->missingActorCheck(),
			$this->workflowCheck(),
			$this->assistantCheck(),
			$this->captureOcrCheck(),
			$this->assistantPrivacyCheck(),
			$this->assistantFallbackCheck(),
			$this->schedulingCheck(),
			$this->eInvoiceCheck(),
			$this->incomingInvoiceCheck(),
			$this->paperlessCheck(),
			$this->paperlessSetupCheck(),
			$this->notificationCheck(),
			$this->maintenanceCheck(),
			$this->backupCheck(),
			$this->nextcloudCompatibilityCheck(),
		];

		$errors = count(array_filter($checks, static fn(array $check): bool => $check['status'] === 'ERROR'));
		$warnings = count(array_filter($checks, static fn(array $check): bool => $check['status'] === 'WARN'));
		return [
			'version' => Application::VERSION,
			'generatedAt' => date('c'),
			'overall' => $errors > 0 ? 'ERROR' : ($warnings > 0 ? 'WARN' : 'OK'),
			'summary' => ['ok' => count($checks) - $errors - $warnings, 'warnings' => $warnings, 'errors' => $errors],
			'checks' => $checks,
		];
	}

	private function installationCheck(): array {
		$settings = $this->installationConfig->settings();
		$memberExists = $this->teamGroupExists($settings['memberGroup']);
		$adminExists = array_filter($settings['adminGroups'], fn(string $name): bool => $this->teamGroupExists($name));
		$status = !$memberExists ? 'ERROR' : ($adminExists === [] ? 'WARN' : 'OK');
		$missingAdmin = array_values(array_filter(array_map(fn(string $name): string => $this->groupLookup->missingText($name), $settings['adminGroups'])));
		return $this->check('installation', 'Installationsparameter', $status,
			$status === 'OK' ? 'Rollen- und Ablageparameter sind zentral konfiguriert.' . ($missingAdmin === [] ? '' : ' Administrationsgruppen: ' . implode('; ', $missingAdmin) . '.') : (!$memberExists ? 'Die konfigurierte Mitgliedergruppe existiert nicht: ' . $this->groupLookup->missingText($settings['memberGroup']) . '.' : 'Keine konfigurierte Administrationsgruppe wurde gefunden; nur Nextcloud-Systemadministratoren besitzen Administrationsrechte. ' . implode('; ', $missingAdmin) . '.'),
			'Änderungen an Ablagepfaden verschieben vorhandene Dateien nicht automatisch.', [
				'Mitgliedergruppe: ' . $settings['memberGroup'],
				'Administrationsgruppen: ' . implode(', ', $settings['adminGroups']),
				'Fallakten: ' . $settings['paths']['cases'],
				'Vorlagen: ' . $settings['paths']['templates'],
			], ['view'=>'administration', 'tab'=>'system', 'label'=>'Installationsparameter öffnen']);
	}

	/** 0.83.2 – Rollengruppen der Abläufe: eingetragene Gruppen müssen als Gruppen-ID existieren. */
	private function roleGroupsCheck(): array {
		$details = [];
		$missing = [];
		foreach ($this->installationConfig->roleGroups() as $role => $group) {
			$label = ProcessRules::ROLES[$role] ?? $role;
			if ($group === '') { $details[] = $label . ': nicht belegt (Aufgaben gehen an die Fallverantwortung)'; continue; }
			$text = $this->groupLookup->missingText($group);
			if ($text === '') { $details[] = $label . ': Gruppe „' . $group . '“'; continue; }
			$missing[] = $label;
			$details[] = $label . ': ' . $text;
		}
		return $this->check('role_groups', 'Rollengruppen für Abläufe', $missing === [] ? 'OK' : 'WARN',
			$missing === [] ? 'Alle eingetragenen Rollengruppen sind in Nextcloud vorhanden.' : 'Für ' . implode(', ', $missing) . ' wurde die eingetragene Nextcloud-Gruppe nicht gefunden; Aufgaben dieser Rollen gehen an die Fallverantwortung.',
			$missing === [] ? '' : 'Eingetragen wird die Gruppen-ID. Wurde eine Gruppe in der Benutzerverwaltung umbenannt, bleibt ihre ID die alte – dann die vorgeschlagene ID eintragen oder die Gruppe unter dem gewünschten Namen neu anlegen.',
			$details, ['view' => 'administration', 'tab' => 'system', 'label' => 'Installationsparameter öffnen']);
	}

	private function teamGroupExists(string $groupName): bool {
		return $this->groupManager->get($groupName) !== null;
	}

	/**
	 * appinfo/info.xml deklariert eine feste Nextcloud-Höchstversion; Nextcloud deaktiviert
	 * die App automatisch, sobald der Server über diese Version hinaus aktualisiert wird.
	 * Diese Prüfung warnt bereits, sobald die laufende Serverversion die deklarierte
	 * Höchstversion erreicht hat, damit Kompatibilitätstest und Versions-Bump geplant
	 * werden können, bevor die nächste Nextcloud-Hauptversion tatsächlich ansteht.
	 */
	private function nextcloudCompatibilityCheck(): array {
		$runningMajor = (int)explode('.', trim((string)$this->config->getSystemValue('version', '0.0.0.0')))[0];
		$maxSupported = \OCA\Bestatter\AppInfo\Application::MAX_NEXTCLOUD_VERSION;
		$status = $runningMajor >= $maxSupported ? 'WARN' : 'OK';
		$message = $status === 'OK'
			? 'Laufende Nextcloud-Version ' . $runningMajor . ' liegt unterhalb der deklarierten Höchstversion ' . $maxSupported . '.'
			: 'Laufende Nextcloud-Version ' . $runningMajor . ' hat die in appinfo/info.xml deklarierte Höchstversion ' . $maxSupported . ' erreicht. Die App wird bei der nächsten Nextcloud-Hauptversion automatisch deaktiviert, bis max-version angehoben wird.';
		return $this->check('nextcloud_compatibility', 'Nextcloud-Versionskompatibilität', $status, $message,
			$status === 'OK' ? '' : 'Kompatibilität mit der nächsten Nextcloud-Hauptversion testen (siehe bereits vorhandene Kompatibilitätsprüfungen früherer Major-Releases) und appinfo/info.xml max-version danach anheben.',
			['Laufende Version: ' . $runningMajor, 'Deklarierte Höchstversion: ' . $maxSupported],
			['view' => 'administration', 'tab' => 'system', 'label' => 'Systemprüfung öffnen']);
	}

	private function notificationCheck(): array {
		$activityEnabled = $this->appManager->isEnabledForUser('activity');
		$notificationsEnabled = $this->appManager->isEnabledForUser('notifications');
		$backgroundMode = strtolower($this->config->getAppValue('core', 'backgroundjobs_mode', 'ajax'));
		$mailMode = trim((string)$this->config->getSystemValue('mail_smtpmode', ''));
		$mailConfigured = $mailMode !== '';
		$status = !$activityEnabled ? 'ERROR' : ((!$notificationsEnabled || !$mailConfigured || $backgroundMode !== 'cron') ? 'WARN' : 'OK');
		$message = !$activityEnabled
			? 'Die Nextcloud-App „Aktivität“ ist nicht aktiviert; fachliche Benachrichtigungseinstellungen sind deshalb nicht verfügbar.'
			: 'Native Bestatter-Aktivitäten sind registriert. Benutzer steuern E-Mail und Push unter Persönlich → Benachrichtigungen.';
		$hint = $status === 'OK' ? 'Die Versandhäufigkeit wird zentral durch die persönliche Nextcloud-Einstellung schnellstmöglich, stündlich, täglich oder wöchentlich bestimmt.'
			: 'Aktivität und Benachrichtigungen aktivieren, den Mailserver prüfen und Nextcloud-Hintergrundjobs auf Cron stellen. Dabei wird keine Test-E-Mail versendet.';
		return $this->check('notifications', 'Fachliche Benachrichtigungen', $status, $message, $hint, [
			'Aktivität: ' . ($activityEnabled ? 'aktiv' : 'inaktiv'),
			'Push-Infrastruktur: ' . ($notificationsEnabled ? 'aktiv' : 'inaktiv'),
			'E-Mail-Konfiguration: ' . ($mailConfigured ? 'vorhanden' : 'nicht erkannt'),
			'Hintergrundmodus: ' . $backgroundMode,
		], ['view' => 'administration', 'tab' => 'system', 'label' => 'Systemprüfung öffnen']);
	}

	private function maintenanceCheck(): array {
		$lastRun = $this->config->getAppValue(Application::APP_ID, 'maintenance_last_run', '');
		$status = $this->config->getAppValue(Application::APP_ID, 'maintenance_last_status', 'UNKNOWN');
		$summary = json_decode($this->config->getAppValue(Application::APP_ID, 'maintenance_last_summary', '{}'), true) ?: [];
		$age = $lastRun !== '' ? time() - (strtotime($lastRun) ?: 0) : PHP_INT_MAX;
		$level = $lastRun === '' || $age > 24 * 60 * 60 ? 'WARN' : ($status === 'ERROR' ? 'ERROR' : ($status === 'WARN' ? 'WARN' : 'OK'));
		$message = $lastRun === '' ? 'Der Bestatter-Wartungsjob wurde seit der Installation noch nicht ausgeführt.' : 'Letzter automatischer Prüflauf: ' . $lastRun . ' (' . $status . ').';
		return $this->check('scheduled_maintenance', 'Regelmäßige Wartung', $level, $message,
			$level === 'OK' ? 'Der Job führt ausschließlich nicht-destruktive Integritäts- und Betriebsprüfungen aus.' : 'Nextcloud auf Cron-Betrieb stellen und prüfen, ob der Cron-Container cron.php regelmäßig ausführt.',
			// $summary-Werte sind int (Kennzahlen), string (z.B. auditIntegrity-Status) oder
			// null (Kennzahl beim letzten Lauf nicht ermittelbar) - daher kein int-Type-Hint.
			array_map(static fn(string $key, mixed $value): string => $key . ': ' . ($value === null ? 'nicht ermittelbar' : (string)$value), array_keys($summary), array_values($summary)),
			['view'=>'administration', 'tab'=>'system', 'label'=>'Systemprüfung öffnen']);
	}

	/** 0.78.0 – Automatische Datensicherung: Zeitplan, letztes Ergebnis und Alter der neuesten Sicherung. */
	private function backupCheck(): array {
		$action = ['view' => 'administration', 'tab' => 'backup', 'label' => 'Backup & Restore öffnen'];
		try { $status = $this->backup->scheduleStatus(); } catch (\Throwable $error) { return $this->check('backup_schedule', 'Automatische Datensicherung', 'WARN', 'Konnte nicht geprüft werden: ' . $error->getMessage(), '', [], $action); }
		$newest = $status['newestBackupAt'] !== null ? (int)floor((time() - (strtotime((string)$status['newestBackupAt']) ?: time())) / 86400) : null;
		$newestText = $newest === null ? 'Es gibt noch keine Sicherung.' : 'Neueste Sicherung vor ' . $newest . ' Tag(en).';
		$hint = 'Unter Administration → „Backup & Restore“ → „Automatische Sicherung“ einen Zeitplan einrichten, zum Beispiel täglich nachts. Die App-Sicherung ergänzt die Sicherung der gesamten Nextcloud.';
		if ($status['settings']['frequency'] === 'OFF') {
			$recent = $newest !== null && $newest <= 8;
			return $this->check('backup_schedule', 'Automatische Datensicherung', $recent ? 'OK' : 'WARN', 'Kein Zeitplan in der App eingerichtet. ' . $newestText . ($recent ? ' (Sicherungen entstehen offenbar auf anderem Weg, z. B. per Cronjob.)' : ''), $recent ? '' : $hint, [], $action);
		}
		$last = is_array($status['lastResult']) ? $status['lastResult'] : [];
		$details = ['Zeitplan: ' . $status['description'] . ' (' . $status['timezone'] . ')', 'Nächste Sicherung: ' . ($status['nextRun'] ?? '–'), 'Letzte erfolgreiche: ' . ($status['lastSuccessAt'] ?? 'noch keine')];
		if ($status['cronMode'] !== 'cron') return $this->check('backup_schedule', 'Automatische Datensicherung', 'WARN', 'Zeitplan ' . $status['description'] . ', aber Nextcloud-Hintergrundjobs laufen nicht über Cron (' . $status['cronMode'] . ') – die Sicherung startet dann unzuverlässig.', 'Verwaltungseinstellungen → Grundeinstellungen → Hintergrundaufgaben auf „Cron“ stellen.', $details, $action);
		if (($last['status'] ?? '') === 'ERROR') return $this->check('backup_schedule', 'Automatische Datensicherung', 'ERROR', 'Die letzte automatische Sicherung ist fehlgeschlagen: ' . (string)($last['message'] ?? ''), 'Ursache beheben und unter „Backup & Restore“ ein Sicherungspaket von Hand erstellen.', $details, $action);
		if (($last['status'] ?? '') === 'RUNNING' && time() - (strtotime((string)($last['startedAt'] ?? '')) ?: time()) > 6 * 3600) return $this->check('backup_schedule', 'Automatische Datensicherung', 'WARN', 'Die automatische Sicherung vom ' . (string)$last['startedAt'] . ' wurde nicht abgeschlossen (Abbruch durch Zeit- oder Speicherlimit?).', 'Nextcloud-Protokoll prüfen; bei sehr großen Fallakten das PHP-Speicherlimit für Cron erhöhen.', $details, $action);
		if ($status['overdue']) return $this->check('backup_schedule', 'Automatische Datensicherung', 'WARN', 'Die fällige automatische Sicherung (' . $status['description'] . ') ist nicht gelaufen.', 'Prüfen, ob der Nextcloud-Cron regelmäßig läuft (Systemprüfung „Regelmäßige Wartung“).', $details, $action);
		if (($last['status'] ?? '') === 'WARN') return $this->check('backup_schedule', 'Automatische Datensicherung', 'WARN', (string)($last['message'] ?? 'Die letzte automatische Sicherung ist unvollständig.'), 'Die betroffenen Dateien stehen in der Sicherungsliste unter „Unvollständig“.', $details, $action);
		return $this->check('backup_schedule', 'Automatische Datensicherung', 'OK', 'Zeitplan ' . $status['description'] . '. ' . $newestText, '', $details, $action);
	}

	private function incomingInvoiceCheck(): array {
		$query = $this->db->getQueryBuilder();
		$unbalanced = (int)$query->select($query->func()->count('id', 'count'))->from('bestatter_incoming_invoices')
			->where($query->expr()->neq('gross_cents', 'declared_gross_cents'))
			->andWhere($query->expr()->neq('status', $query->createNamedParameter('FREIGEGEBEN')))
			->andWhere($query->expr()->neq('status', $query->createNamedParameter('ABGELEHNT')))
			->executeQuery()->fetchOne();
		$query = $this->db->getQueryBuilder();
		$pending = (int)$query->select($query->func()->count('id', 'count'))->from('bestatter_incoming_items')
			->where($query->expr()->eq('classification', $query->createNamedParameter('CLASSIFICATION_PENDING')))->executeQuery()->fetchOne();
		$status = ($unbalanced + $pending) > 0 ? 'WARN' : 'OK';
		return $this->check('incoming_invoices', 'Eingangsrechnungen', $status,
			$status === 'OK' ? 'Keine offenen Summen- oder Klassifikationsabweichungen bei Eingangsrechnungen.' : $unbalanced . ' Beleg(e) mit Summenabweichung und ' . $pending . ' noch nicht klassifizierte Position(en).',
			$status === 'OK' ? '' : 'Die betroffenen Fälle unter Finanzen öffnen, Belegsumme abstimmen und jede Position fachlich klassifizieren.',
			['Summenabweichungen: ' . $unbalanced, 'Offene Klassifikationen: ' . $pending], ['view' => 'cases', 'label' => 'Fälle öffnen']);
	}

	private function paperlessCheck(): array {
		$settings=$this->paperless->settings();
		if($settings['mode']==='OFF')return $this->check('paperless','Optionale Paperless-Anbindung','OK','Paperless ist deaktiviert; der vollständige manuelle Eingangsrechnungsprozess ist verfügbar.','Paperless ist eine optionale Komfortfunktion und keine Betriebsvoraussetzung.',[],['view'=>'administration','tab'=>'paperless','label'=>'Paperless konfigurieren']);
		$query=$this->db->getQueryBuilder();$failed=(int)$query->select($query->func()->count('id','count'))->from('bestatter_integration_jobs')->where($query->expr()->eq('provider',$query->createNamedParameter('PAPERLESS')))->andWhere($query->expr()->eq('job_status',$query->createNamedParameter('FAILED')))->executeQuery()->fetchOne();
		$query=$this->db->getQueryBuilder();$pending=(int)$query->select($query->func()->count('id','count'))->from('bestatter_external_documents')->where($query->expr()->eq('provider',$query->createNamedParameter('PAPERLESS')))->andWhere($query->expr()->in('record_type',$query->createNamedParameter(PaperlessService::INBOX_RECORD_TYPES,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))->andWhere($query->expr()->eq('sync_status',$query->createNamedParameter('INBOX')))->andWhere($query->expr()->isNull('case_id'))->executeQuery()->fetchOne();
		$ncOcr=$this->assistant->nextcloudOcrAvailability()['available'];$status=!$settings['enabled']?'ERROR':(($failed>0||($settings['captureDocumentTypeId']<=0&&!$ncOcr))?'WARN':'OK');
		return $this->check('paperless','Optionale Paperless-Anbindung',$status,$status==='ERROR'?'Paperless ist aktiviert, aber Basisadresse oder API-Token fehlen.':($failed.' endgültig fehlgeschlagene Übertragung(en), '.$pending.' unzugeordnete Dokumente im Belegeingang.'),'Bei Störungen bleiben Nextcloud-Original und manuelle Erfassung nutzbar. Für OCR-Erfassungsbögen über Paperless zusätzlich eine eigene Dokumenttyp-ID konfigurieren (ohne sie wird die Nextcloud-Texterkennung verwendet, sofern freigegeben).',['Betriebsart: '.$settings['mode'],'API-Token: '.($settings['tokenConfigured']?'gesetzt':'fehlt'),'Dokumenttyp Auftragserfassung: '.($settings['captureDocumentTypeId']>0?(string)$settings['captureDocumentTypeId']:'fehlt'),'Unzugeordnete Belege: '.$pending,'Fehlgeschlagene Aufträge: '.$failed,'Archivkopie von Hand erfasster Eingangsrechnungen: '.($settings['archiveIncomingInvoices']?'an':'aus'),'Erfassungsbogen-Kopie nach der Texterkennung: '.($settings['deleteCaptureAfterOcr']?'wird gelöscht':'bleibt in Paperless')],['view'=>'administration','tab'=>'paperless','label'=>'Paperless öffnen']);
	}

	/** 0.81.0: Einrichtung des Belegeingangs in Paperless (Feld „Fallnummer“, Dokumenttypen, Webhook, letzte Meldung). */
	private function paperlessSetupCheck(): array {
		$result = $this->paperlessSetup->check();
		$action = ['view' => 'customizing', 'tab' => 'document-kinds', 'label' => 'Dokumentarten öffnen'];
		if (!$result['applicable']) return $this->check('paperless_setup', 'Paperless-Belegeingang (Einrichtung)', 'OK', $result['message'], '', [], ['view' => 'administration', 'tab' => 'paperless', 'label' => 'Paperless öffnen']);
		return $this->check('paperless_setup', 'Paperless-Belegeingang (Einrichtung)', $result['status'], $result['message'],
			$result['status'] === 'OK' ? '' : 'Feld „Fallnummer“ und Dokumenttypen legen Sie in Paperless an, die Zuordnung der Dokumenttypen unter Customizing → „Dokumentarten“. Anleitung: Handbuch, Kapitel Technik → Paperless-ngx einrichten.',
			array_map(static fn(array $item): string => $item['label'] . ': ' . $item['detail'], $result['items']), $action);
	}

	private function schedulingCheck(): array {
		$query = $this->db->getQueryBuilder();
		$types = (int)$query->select($query->func()->count('id', 'count'))->from('bestatter_schedule_types')->where($query->expr()->eq('active', $query->createNamedParameter(1)))->executeQuery()->fetchOne();
		$query = $this->db->getQueryBuilder();
		$resources = (int)$query->select($query->func()->count('id', 'count'))->from('bestatter_resources')->where($query->expr()->eq('active', $query->createNamedParameter(1)))->andWhere($query->expr()->eq('conflict_relevant', $query->createNamedParameter(1)))->executeQuery()->fetchOne();
		$status = $types === 0 ? 'ERROR' : ($resources === 0 ? 'WARN' : 'OK');
		return $this->check('scheduling', 'Terminplanung und Disposition', $status,
			$types . ' aktive Terminart(en), ' . $resources . ' konfliktrelevante Ressource(n).',
			$status === 'OK' ? 'Personal wird zusätzlich direkt über die Terminzuständigkeit geprüft.' : 'Unter Customizing → Terminplanung Terminarten prüfen und mindestens Fahrzeuge, Räume oder Kapellen ergänzen, die nicht doppelt belegt werden dürfen.',
			['Aktive Terminarten: ' . $types, 'Konfliktrelevante Ressourcen: ' . $resources], ['view' => 'customizing', 'tab' => 'scheduling', 'label' => 'Terminplanung öffnen']);
	}

	private function eInvoiceCheck(): array {
		$settings = $this->configuration->invoiceSettings();
		$status = $this->eInvoiceCompliance->status();
		$enabled = (bool)$settings['zugferdEnabled'] || (bool)$settings['xrechnungEnabled'];
		if (!$enabled) return $this->check('e_invoice', 'E-Rechnung', 'OK', 'ZUGFeRD und XRechnung sind deaktiviert.');
		$last = $this->eInvoiceCompliance->lastOutput();
		$required = (bool)$settings['normativeValidationRequired'];
		$lastLabel = $last === null ? 'noch keine ZUGFeRD-Rechnung mit PDF erzeugt'
			: (!$last['embedded'] ? 'XML nicht eingebettet' . ($last['embedError'] ? ' (' . mb_substr((string)$last['embedError'], 0, 160) . ')' : '')
			: ($last['pdfa'] !== null ? 'PDF/A-' . $last['pdfa'] . ' mit eingebetteter factur-x.xml' : 'eingebettet, aber ohne PDF/A (Konverter liefert kein PDF/A)'))
			. ($last !== null ? ' – ' . date('d.m.Y H:i', (int)strtotime((string)$last['at'])) : '');
		$pdfaMissing = $last !== null && (!$last['embedded'] || $last['pdfa'] === null);
		$level = $pdfaMissing ? ($required ? 'ERROR' : 'WARN') : ($required && !$status['available'] ? 'ERROR' : 'OK');
		$message = $status['available']
			? 'ZUGFeRD-PDFs werden mit eingebetteter factur-x.xml erzeugt und mit Mustang normativ geprüft (EN 16931, PDF/A-3).'
			: 'ZUGFeRD-PDFs werden mit eingebetteter factur-x.xml erzeugt; die XML wird intern geprüft (Pflichtangaben, Rechensummen, Steuergruppen). Eine normative Prüfung (EN 16931, PDF/A-3) ist nicht eingerichtet.';
		if ($pdfaMissing) $message .= ' Die letzte Rechnung wurde ohne PDF/A-Kennzeichnung ausgegeben.';
		$hint = $pdfaMissing
			? 'Die PDF-Konvertierung liefert kein PDF/A. EuroOffice wird für Rechnungen automatisch um PDF/A gebeten; bei Nextcloud Office (Collabora) lässt sich das über Nextcloud nicht steuern. PDF/A-fähigen Konverter verwenden.'
			: ($status['available'] ? 'Vor Produktivbetrieb zusätzlich die jeweils aktuelle KoSIT-Konfiguration mit Referenzrechnungen prüfen.' : 'Optional: Mustang CLI (Java) auf dem Nextcloud-Host bereitstellen und den absoluten JAR-Pfad als Systemwert bestatter_einvoice_mustang_jar eintragen; dann wird jede ZUGFeRD-Rechnung normativ geprüft und ein Prüfbericht abgelegt.');
		return $this->check('e_invoice', 'E-Rechnung (ZUGFeRD/PDF/A-3)', $level, $message, $hint, [
			'ZUGFeRD 2.5.2: ' . ($settings['zugferdEnabled'] ? 'aktiv' : 'inaktiv'),
			'XRechnung 3.0.x: ' . ($settings['xrechnungEnabled'] ? 'aktiv' : 'inaktiv'),
			'Letzte ZUGFeRD-Ausgabe: ' . $lastLabel,
			'Normative Prüfung verpflichtend: ' . ($required ? 'ja' : 'nein'),
			'Mustang-JAR lesbar: ' . ($status['jarReadable'] ? 'ja' : 'nein'),
		], ['view' => 'administration', 'tab' => 'invoices', 'label' => 'Rechnungswesen öffnen']);
	}

	private function assistantCheck(): array {
		$health = $this->assistant->health();
		if (!$health['enabled']) return $this->check('assistant', 'Schnellerfassung und Assistenz', 'OK', 'Die Assistenzfunktionen sind administrativ deaktiviert.');
		$status = $health['speechProvider'] && $health['speechProviderApproved'] ? 'OK' : 'WARN';
		$message = $health['speechProvider'] && $health['speechProviderApproved']
			? 'Speech-to-Text ist verfügbar; Audioaufbewahrung: ' . $health['audioRetention'] . '; Mikrofonrichtlinie: ' . $health['microphonePolicyRequired'] . '. Text-zu-Text: ' . ($health['textProvider'] ? 'verfügbar' : 'nicht eingerichtet (regelbasierter Fallback aktiv)') . '.'
			: ($health['speechProvider'] ? 'Speech-to-Text ist technisch verfügbar, wurde für sensible Falldaten aber noch nicht administrativ freigegeben.' : 'Geführte Schnellerfassung ist verfügbar; ein Speech-to-Text-Provider ist noch nicht eingerichtet.');
		$hint = !$health['speechProvider'] ? 'AppAPI, Nextcloud Assistant und einen Speech-to-Text-Provider wie stt_whisper2 einrichten. Die manuelle Schnellerfassung bleibt nutzbar.' : (!$health['speechProviderApproved'] ? 'Unter Administration → Assistenz den Provider nach technischer und datenschutzrechtlicher Prüfung ausdrücklich freigeben.' : '');
		return $this->check('assistant', 'Schnellerfassung und Assistenz', $status, $message, $hint, [
			'Speech-to-Text: ' . ($health['speechProvider'] ? 'technisch verfügbar' : 'nicht verfügbar'),
			'Datenschutzfreigabe: ' . ($health['speechProviderApproved'] ? 'erteilt' : 'nicht erteilt'),
			'Erfassungsbogen einlesen: ' . $this->captureOcrLabel(),
			'Unterstützte Audioformate: ' . implode(', ', $health['supportedAudioFormats']),
		], ['view' => 'administration', 'tab' => 'assistant', 'label' => 'Assistenz-Einstellungen öffnen']);
	}

	/** 0.71.0: Welche Texterkennung liest Auftragserfassungsbögen? (gleiche Regel wie CaptureImportService::ocrAvailability) */
	/**
	 * 0.73.1: Eigene Prüfung der Texterkennung für Auftragserfassungsbögen. Ohne Paperless läuft die Erkennung als
	 * Nextcloud-TaskProcessing-Auftrag („core:image2text:ocr“) über die Hintergrundjobs – deshalb werden auch
	 * Hintergrundmodus, letzter Cron-Lauf und hängende Erkennungen geprüft.
	 */
	private function captureOcrCheck(): array {
		$configuration = $this->assistant->configuration();
		$action = ['view' => 'administration', 'tab' => 'assistant', 'label' => 'Assistenz-Einstellungen öffnen'];
		if (!(bool)($configuration['guidedCaptureEnabled'] ?? true)) return $this->check('capture_ocr', 'Texterkennung für Erfassungsbögen', 'OK', 'Die Schnellerfassung ist deaktiviert; eine Texterkennung wird nicht benötigt.', '', [], $action);
		$paperless = $this->paperless->settings();
		$paperlessReady = $paperless['enabled'] && (int)$paperless['captureDocumentTypeId'] > 0;
		$providerPresent = (bool)($configuration['providers']['ocr'] ?? false);
		$approved = (bool)($configuration['ocrProviderApproved'] ?? false);
		$backgroundMode = strtolower($this->config->getAppValue('core', 'backgroundjobs_mode', 'ajax'));
		$lastCron = (int)$this->config->getAppValue('core', 'lastcron', '0');
		$cronAge = $lastCron > 0 ? time() - $lastCron : null;
		$query = $this->db->getQueryBuilder();
		$stuck = (int)$query->select($query->func()->count('id', 'count'))->from('bestatter_capture_imports')
			->where($query->expr()->in('import_status', $query->createNamedParameter(['OCR_QUEUED', 'OCR_PROCESSING'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($query->expr()->lt('updated_at', $query->createNamedParameter(date('c', time() - 1800))))
			->executeQuery()->fetchOne();
		$engine = $paperlessReady ? 'PAPERLESS' : ($providerPresent && $approved ? 'NEXTCLOUD' : '');
		$details = [
			'Verwendet: ' . match ($engine) { 'PAPERLESS' => 'Paperless-ngx (hat Vorrang, archiviert den Bogen zugleich)', 'NEXTCLOUD' => 'Nextcloud-Texterkennung', default => 'keine – „Auftragserfassungsbogen einlesen“ ist ausgeblendet' },
			'Paperless für Erfassungsbögen: ' . ($paperlessReady ? 'eingerichtet' : ($paperless['enabled'] ? 'verbunden, Dokumenttyp für Erfassungsbögen fehlt' : 'nicht verbunden')),
			'Nextcloud-Texterkennung (Aufgabentyp core:image2text:ocr): ' . ($providerPresent ? 'vorhanden' : 'kein Anbieter installiert'),
			'Freigabe für Falldaten: ' . ($approved ? 'erteilt' : 'nicht erteilt'),
			'Hintergrundjobs: ' . $backgroundMode . ($cronAge !== null ? ', letzter Lauf vor ' . max(0, intdiv($cronAge, 60)) . ' Min.' : ', noch kein Lauf'),
			'Hängende Erkennungen (> 30 Min.): ' . $stuck,
		];
		if ($engine === '') {
			$message = $providerPresent ? 'Die Nextcloud-Texterkennung ist installiert, aber für Falldaten noch nicht freigegeben.' : 'Keine Texterkennung eingerichtet. Erfassungsbögen können nicht eingelesen werden; Gesprächsaufnahme und manuelle Erfassung bleiben nutzbar.';
			$hint = $providerPresent ? 'Unter Administration → Assistenz die Freigabe der Nextcloud-Texterkennung (OCR) für Auftragserfassungsbögen nach Datenschutzprüfung setzen und speichern.' : 'Entweder Paperless-ngx verbinden und den Dokumenttyp für Erfassungsbögen setzen, oder in Nextcloud eine App installieren, die unter Verwaltung → Künstliche Intelligenz die Aufgabe „Texterkennung (OCR)“ anbietet, und sie danach unter Administration → Assistenz freigeben.';
			return $this->check('capture_ocr', 'Texterkennung für Erfassungsbögen', 'WARN', $message, $hint, $details, $action);
		}
		$problems = [];
		if ($engine === 'NEXTCLOUD' && $backgroundMode === 'ajax') $problems[] = 'Die Nextcloud-Hintergrundjobs laufen im Modus „AJAX“ – die Texterkennung startet dann nur, während jemand Nextcloud im Browser geöffnet hat.';
		elseif ($engine === 'NEXTCLOUD' && ($cronAge === null || $cronAge > 1800)) $problems[] = 'Die Nextcloud-Hintergrundjobs sind seit über 30 Minuten nicht gelaufen; eingereichte Bögen werden nicht erkannt.';
		if ($stuck > 0) $problems[] = $stuck . ' Erfassungsbogen/-bögen warten seit über 30 Minuten auf die Texterkennung.';
		$message = ($engine === 'PAPERLESS' ? 'Erfassungsbögen werden über Paperless-ngx erkannt.' : 'Erfassungsbögen (PDF, JPG, PNG) werden mit der Nextcloud-Texterkennung erkannt.') . ($problems !== [] ? ' ' . implode(' ', $problems) : '');
		$hint = $problems === [] ? ($engine === 'NEXTCLOUD' ? 'Für eine Erkennung innerhalb von Sekunden empfiehlt Nextcloud einen eigenen Worker: occ background-job:worker \'OC\\TaskProcessing\\SynchronousBackgroundJob\'.' : '')
			: ($engine === 'NEXTCLOUD' ? 'Hintergrundjobs auf „Cron“ stellen (Verwaltung → Grundeinstellungen) und den Systemdienst bzw. Cronjob prüfen; optional einen TaskProcessing-Worker einrichten. Hängende Bögen in der Schnellerfassung mit „Texterkennung erneut versuchen“ neu anstoßen.' : 'Paperless-Verbindung und Warteschlange unter Administration → Paperless prüfen.');
		return $this->check('capture_ocr', 'Texterkennung für Erfassungsbögen', $problems === [] ? 'OK' : 'WARN', $message, $hint, $details, $action);
	}

	private function captureOcrLabel(): string {
		$paperless = $this->paperless->settings();
		if ($paperless['enabled'] && (int)$paperless['captureDocumentTypeId'] > 0) return 'Paperless-ngx';
		$nextcloud = $this->assistant->nextcloudOcrAvailability();
		return $nextcloud['available'] ? 'Nextcloud-Texterkennung' : 'nicht verfügbar (' . $nextcloud['reason'] . ') – der Weg wird in der Schnellerfassung ausgeblendet';
	}

	private function assistantPrivacyCheck(): array {
		$health = $this->assistant->health();
		$deleteImmediately = $health['audioRetention'] === 'DELETE_AFTER_TRANSCRIPTION';
		return $this->check(
			'assistant_privacy', 'KI-Datenschutz und Audioaufbewahrung', $deleteImmediately ? 'OK' : 'ERROR',
			$deleteImmediately ? 'Temporäre Audiodateien liegen im privaten Bereich der aufnehmenden Person und werden nach erfolgreicher, fehlgeschlagener oder abgebrochener Transkription gelöscht; nicht abgeholte Aufnahmen spätestens nach 24 Stunden.' : 'Die Audioaufbewahrung entspricht nicht der verbindlichen Löschregel.',
			$deleteImmediately ? 'Es werden standardmäßig weder Roh-Audio noch vollständige Assistentenfragen im Audit-Log gespeichert.' : 'Audioaufbewahrung auf DELETE_AFTER_TRANSCRIPTION zurücksetzen.',
		);
	}

	private function assistantFallbackCheck(): array {
		$health = $this->assistant->health();
		return $this->check(
			'assistant_fallback', 'Betrieb ohne KI-Anbieter', $health['manualFallback'] ? 'OK' : 'ERROR',
			$health['manualFallback'] ? 'Schnellerfassung, Prozessprüfung, Suche und alle Fachfunktionen bleiben ohne KI-Anbieter bedienbar.' : 'Der manuelle Fallback ist nicht verfügbar.',
			!$health['textProvider'] ? 'Ein Text-zu-Text-Provider ist optional. Der Bestatter-Assistent verwendet aktuell die geprüften fachlichen Regeln.' : '',
		);
	}

	private function orphanCheck(): array {
		$query = $this->db->getQueryBuilder();
		$count = (int)$query->select($query->func()->count('r.id', 'count'))
			->from('bestatter_records', 'r')
			->leftJoin('r', 'bestatter_cases', 'c', $query->expr()->eq('c.id', 'r.case_id'))
			->where($query->expr()->isNotNull('r.case_id'))
			->andWhere($query->expr()->isNull('c.id'))
			->executeQuery()->fetchOne();
		return $this->check('orphan_records', 'Datenintegrität', $count === 0 ? 'OK' : 'ERROR', $count === 0 ? 'Keine verwaisten Fallaktivitäten gefunden.' : $count . ' Datensätze verweisen auf einen nicht vorhandenen Fall.');
	}

	private function missingActorCheck(): array {
		$query = $this->db->getQueryBuilder();
		$count = (int)$query->select($query->func()->count('id', 'count'))
			->from('bestatter_records')
			->where($query->expr()->orX(
				$query->expr()->isNull('created_by'),
				$query->expr()->eq('created_by', $query->createNamedParameter('')),
				$query->expr()->isNull('updated_by'),
				$query->expr()->eq('updated_by', $query->createNamedParameter('')),
			))->executeQuery()->fetchOne();
		return $this->check('audit_actor', 'Bearbeitungsnachweis', $count === 0 ? 'OK' : 'WARN', $count === 0 ? 'Alle Aktivitäten enthalten Ersteller und letzten Bearbeiter.' : $count . ' ältere Datensätze enthalten noch keinen vollständigen Bearbeitungsnachweis.');
	}

	private function workflowCheck(): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('run_status', $query->func()->count('id', 'count'))
			->from('bestatter_action_runs')->groupBy('run_status')->executeQuery()->fetchAllAssociative();
		$counts = [];
		foreach ($rows as $row) $counts[strtoupper((string)$row['run_status'])] = (int)$row['count'];
		$running = $counts['RUNNING'] ?? 0;
		$failed = $counts['FAILED'] ?? 0;
		$status = $running > 0 ? 'ERROR' : ($failed > 0 ? 'WARN' : 'OK');
		$message = $running . ' laufende und ' . $failed . ' fehlgeschlagene Ablaufaktion(en).';
		if ($running === 0 && $failed === 0) $message = 'Keine hängenden oder fehlgeschlagenen Ablaufaktionen.';
		return $this->check('action_runs', 'Ablaufaktionen', $status, $message, $failed > 0 ? 'Fehlgeschlagene Läufe bleiben als Prüf- und Wiederholungsnachweis erhalten. Erst eine erfolgreiche Wiederholung erledigt den betreffenden Lauf.' : '', array_map(static fn(string $runStatus, int $count): string => $runStatus . ': ' . $count, array_keys($counts), array_values($counts)), ['view' => 'customizing', 'tab' => 'processes', 'label' => 'Abläufe öffnen']);
	}

	/**
	 * 0.75.2: Übertragungsfehler je betroffener Person (Benutzerkennung, Anzahl, häufigste Meldung). Der Hintergrundjob
	 * wiederholt sie automatisch, sobald Kalender bzw. Aufgabenliste der Person existiert.
	 */
	private function syncCheck(): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('owner_uid', 'payload')->from('bestatter_records')
			->where($query->expr()->in('record_type', $query->createNamedParameter(['task', 'schedule'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($query->expr()->like('payload', $query->createNamedParameter('%"syncError"%')))
			->executeQuery()->fetchAllAssociative();
		$people = [];
		$records = 0;
		foreach ($rows as $row) {
			$payload = json_decode((string)$row['payload'], true);
			if (!is_array($payload)) continue;
			$failures = [];
			$nextcloud = is_array($payload['nextcloud'] ?? null) ? $payload['nextcloud'] : [];
			if ((string)($nextcloud['syncError'] ?? '') !== '' && ($nextcloud['remoteDeletedAt'] ?? '') === '') $failures[(string)($nextcloud['ownerUid'] ?? '') ?: (string)$row['owner_uid']] = (string)$nextcloud['syncError'];
			foreach ((array)($payload['nextcloudCopies'] ?? []) as $copy) if (is_array($copy) && !($copy['stored'] ?? false) && (string)($copy['ownerUid'] ?? '') !== '') $failures[(string)$copy['ownerUid']] = (string)($copy['syncError'] ?? 'Kopie nicht gespeichert');
			if ($failures === []) continue;
			$records++;
			foreach ($failures as $uid => $message) {
				$people[$uid]['count'] = ($people[$uid]['count'] ?? 0) + 1;
				$people[$uid]['messages'][$message] = ($people[$uid]['messages'][$message] ?? 0) + 1;
			}
		}
		if ($records === 0) return $this->check('sync_errors', 'Nextcloud-Synchronisation', 'OK', 'Alle Aufgaben und Termine sind in Nextcloud gespeichert.');
		ksort($people);
		$details = [];
		foreach ($people as $uid => $info) {
			arsort($info['messages']);
			$details[] = $uid . ': ' . $info['count'] . ' Eintrag/Einträge – ' . (string)array_key_first($info['messages']);
		}
		return $this->check('sync_errors', 'Nextcloud-Synchronisation', 'WARN', $records . ' Aufgabe(n) oder Termin(e) noch nicht vollständig in Nextcloud gespeichert; betroffen: ' . implode(', ', array_keys($people)) . '.', 'Meist fehlt der Person der Kalender oder die Aufgabenliste: einmal in Nextcloud anmelden und einmal die App Aufgaben öffnen. Die App wiederholt die Übertragung danach automatisch alle 30 Minuten (Hintergrundjob, Nextcloud-Cron erforderlich).', $details, ['view' => 'task', 'label' => 'Aufgaben prüfen']);
	}

	/** 0.75.2: Altlasten aus der Nextcloud-Anbindung, bereinigt per occ bestatter:groupware-repair. */
	private function groupwareRepairCheck(): array {
		try { $preview = $this->groupwareRepair->preview(); } catch (\Throwable $error) { return $this->check('groupware_repair', 'Altlasten der Nextcloud-Anbindung', 'WARN', 'Konnte nicht geprüft werden: ' . $error->getMessage()); }
		$mirrors = count($preview['mirrors']);
		$followUps = array_sum(array_map(static fn(array $mirror): int => count($mirror['followUps']), $preview['mirrors']));
		$contacts = count($preview['contacts']);
		$lost = count($preview['lostData']);
		$books = array_count_values(array_map(static fn(array $contact): string => (string)$contact['addressBook'], $preview['contacts']));
		if ($mirrors === 0 && $contacts === 0 && $lost === 0) return $this->check('groupware_repair', 'Altlasten der Nextcloud-Anbindung', 'OK', 'Keine doppelten Einträge aus Terminkopien, keine fehlerhaften Kontaktadressen, keine verlorenen App-Daten.');
		return $this->check('groupware_repair', 'Altlasten der Nextcloud-Anbindung', 'WARN', $mirrors . ' doppelte(r) Eintrag/Einträge aus Terminkopien mit ' . $followUps . ' Folgeaufgabe(n), ' . $contacts . ' Kontakt(e) mit fehlerhafter Adresse, ' . $lost . ' Eintrag/Einträge mit verlorenen App-Daten.', 'Altlast aus Versionen bis 0.75.1. Der Nextcloud-Administrator zeigt die Liste mit occ bestatter:groupware-repair an und bereinigt mit --execute --confirmation=BESTATTER-GROUPWARE-BEREINIGEN.', array_merge(
			array_map(static fn(array $mirror): string => ($mirror['type'] === 'task' ? 'Aufgabe' : 'Termin') . ' #' . $mirror['id'] . ' (Kopie bei ' . $mirror['ownerUid'] . ', Original #' . $mirror['originalId'] . ', ' . count($mirror['followUps']) . ' Folgeaufgaben)', array_slice($preview['mirrors'], 0, 20)),
			array_map(static fn(string $book, int $count): string => 'Adressbuch ' . $book . ': ' . $count . ' Kontakt(e)', array_keys($books), array_values($books)),
			array_map(static fn(array $item): string => ($item['type'] === 'task' ? 'Aufgabe' : 'Termin') . ' #' . $item['id'] . ': ' . implode(', ', $item['keys']), array_slice($preview['lostData'], 0, 20)),
		));
	}

	private function remoteDeletionCheck(): array {
		$query = $this->db->getQueryBuilder();
		$total = (int)$query->select($query->func()->count('id', 'count'))->from('bestatter_records')
			->where("payload LIKE '%\"remoteDeletedAt\"%'")->executeQuery()->fetchOne();
		if ($total === 0) return $this->check('remote_deleted', 'Extern gelöschte Einträge', 'OK', 'Keine extern gelöschten Aufgaben oder Termine vorgemerkt.');
		$query = $this->db->getQueryBuilder();
		$legacy = (int)$query->select($query->func()->count('id', 'count'))->from('bestatter_records')
			->where("payload LIKE '%\"remoteDeletedAt\"%'")
			->andWhere("payload NOT LIKE '%\"statusBeforeRemoteDeletion\"%'")
			->executeQuery()->fetchOne();
		$message = $total . ' lokal erhaltene, in Nextcloud als gelöscht markierte Einträge.';
		$hint = $legacy > 0
			? $legacy . ' Markierung(en) stammen aus dem Altbestand vor 0.31.5 und müssen fachlich geprüft werden; die Systemprüfung ändert keinen Status automatisch.'
			: 'Seit 0.31.5 wird eine Löschung nur noch aus einem echten Kalender-Löschereignis übernommen und verändert den fachlichen Status nicht.';
		return $this->check('remote_deleted', 'Extern gelöschte Einträge', 'WARN', $message, $hint);
	}

	private function countCheck(string $key, string $label, string $table, string $where, string $noun, bool $warning, string $hint = '', ?array $action = null): array {
		$query = $this->db->getQueryBuilder();
		$count = (int)$query->select($query->func()->count('id', 'count'))->from($table)->where($where)->executeQuery()->fetchOne();
		return $this->check($key, $label, $count === 0 ? 'OK' : ($warning ? 'WARN' : 'ERROR'), $count === 0 ? 'Keine Auffälligkeiten gefunden.' : $count . ' ' . $noun . '.', $count === 0 ? '' : $hint, $count === 0 ? [] : ['Betroffene Datensätze: ' . $count], $count === 0 ? null : $action);
	}

	/** 0.70.0 (OP-040): Mehrbenutzerbetrieb braucht die gemeinsame Fallablage. */
	private function storageCheck(int $members): array {
		try { $status = $this->storage->status(); } catch (\Throwable $error) { return $this->check('storage', 'Fallablage', 'ERROR', 'Die Fallablage konnte nicht geprüft werden: ' . $error->getMessage()); }
		if ($status['central']) {
			$problems = array_values(array_filter([$status['accountValid'] ? '' : 'Ablagekonto nicht verfügbar', ($status['missingShares'] ?? []) !== [] ? 'Freigabe fehlt für ' . implode(', ', $status['missingShares']) : '']));
			return $this->check('storage', 'Fallablage', $problems === [] ? 'OK' : 'ERROR', $problems === [] ? 'Zentrale Fallablage im Konto „' . $status['account'] . '“ (' . ($status['location'] === 'TEAMFOLDER' ? 'Teamordner' : 'mit Gruppenfreigabe') . ').' : implode('; ', $problems) . '.', 'Unter Administration → Abnahme und Stabilität → Fallablage prüfen.', [], ['view' => 'administration', 'tab' => 'system', 'anchor' => 'bp-storage-admin', 'label' => 'Fallablage öffnen']);
		}
		return $this->check('storage', 'Fallablage', $members > 1 ? 'WARN' : 'OK', 'Benutzerbezogene Ablage: Jede Person arbeitet in ihrem eigenen Nextcloud-Ordner' . ($members > 1 ? ' – bei ' . $members . ' Mitgliedern sehen andere Personen die Dateien eines Falls nicht.' : '.'), 'Zentrale Fallablage einrichten (Administration → Abnahme und Stabilität → Fallablage).', [], ['view' => 'administration', 'tab' => 'system', 'anchor' => 'bp-storage-admin', 'label' => 'Fallablage öffnen']);
	}

	private function check(string $key, string $label, string $status, string $message, string $hint = '', array $details = [], ?array $action = null): array {
		return ['key' => $key, 'label' => $label, 'status' => $status, 'message' => $message, 'hint' => $hint, 'details' => $details, 'action' => $action];
	}

	private static function templateSourceAvailable(string $expectedName, array $availableNames): bool {
		$expectedName = basename(trim($expectedName));
		if ($expectedName === '') return false;
		if (in_array($expectedName, $availableNames, true)) return true;
		if (is_file(__DIR__ . '/../../resources/templates/' . $expectedName)) return true;
		$expected = self::normalizedTemplateName($expectedName);
		$expectsCondolence = str_contains($expected, 'kondolenz');
		$expectsDeck = str_contains($expected, 'deck');
		foreach ($availableNames as $availableName) {
			$available = self::normalizedTemplateName((string)$availableName);
			if ($available === $expected) return true;
			if ($expectsCondolence && str_contains($available, 'kondolenz') && (str_contains($available, 'deck') === $expectsDeck)) return true;
		}
		return false;
	}

	private static function normalizedTemplateName(string $name): string {
		$name = mb_strtolower(pathinfo($name, PATHINFO_FILENAME));
		return preg_replace('/[^a-z0-9äöüß]+/u', '', $name) ?? $name;
	}

	private static function missingBranchFields(array $branch): array {
		$labels = ['name' => 'Name', 'street' => 'Straße', 'postalCode' => 'PLZ', 'city' => 'Ort', 'accountHolder' => 'Kontoinhaber', 'bankName' => 'Bank', 'iban' => 'IBAN', 'bic' => 'BIC'];
		return array_values(array_filter($labels, static fn(string $label, string $field): bool => trim((string)($branch[$field] ?? '')) === '', ARRAY_FILTER_USE_BOTH));
	}
}
