<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\App\IAppManager;
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

		$checks = [
			$this->installationCheck(),
			$this->check('access', 'Rollen und Zugriff', count($team['members'] ?? []) > 0 ? 'OK' : 'ERROR', count($team['members'] ?? []) . ' Mitglied(er) der Gruppe ' . $this->installationConfig->memberGroup() . '; angemeldeter Benutzer ist ' . (($team['isBestatterAdmin'] ?? false) ? 'Administrator.' : 'Mitglied.')),
			$this->check('branches', 'Niederlassungen', $activeBranches === [] ? 'ERROR' : ($incompleteBranches === [] ? 'OK' : 'WARN'), $activeBranches === [] ? 'Keine aktive Niederlassung vorhanden.' : (count($activeBranches) . ' aktive Niederlassung(en)' . ($incompleteBranches === [] ? ' sind für Rechnungen vollständig gepflegt.' : '; unvollständig: ' . implode(', ', $incompleteBranches) . '.')), $incompleteBranches === [] ? '' : 'Platzhalter-Niederlassungen deaktivieren oder die für Rechnungen erforderlichen Adress- und Bankdaten ergänzen.', $branchDetails, ['view' => 'administration', 'tab' => 'branches', 'label' => 'Niederlassungen öffnen']),
			$this->check('templates', 'Dokumentvorlagen', $activeTemplates === [] || $missingTemplateFiles !== [] ? 'ERROR' : 'OK', count($activeTemplates) . ' aktive Vorlage(n)' . ($missingTemplateFiles === [] ? ' mit verwendbarer Quelldatei.' : '; keine verwendbare Quelldatei bei: ' . implode(', ', $missingTemplateFiles) . '.'), $missingTemplateFiles === [] ? 'Berücksichtigt werden alle aktiven DOCX-Vorlagen in ' . $this->installationConfig->templatesPath() . ' sowie mitgelieferte Paketvorlagen.' : 'Die genannten Dateien im konfigurierten Vorlagenordner bereitstellen oder die betroffenen Vorlagen deaktivieren.', $templateDetails, ['view' => 'customizing', 'tab' => 'documents', 'label' => 'Dokumentvorlagen öffnen']),
			$this->countCheck('sync_errors', 'Nextcloud-Synchronisation', 'bestatter_records', "payload LIKE '%\"syncError\"%'", 'Synchronisationsfehler in Aufgaben oder Terminen', true, 'Dies sind gespeicherte Fehlermeldungen betroffener Einträge; sie werden nicht durch ein App-Update automatisch gelöscht.', ['view' => 'task', 'label' => 'Aufgaben prüfen']),
			$this->remoteDeletionCheck(),
			$this->orphanCheck(),
			$this->missingActorCheck(),
			$this->workflowCheck(),
			$this->assistantCheck(),
			$this->assistantPrivacyCheck(),
			$this->assistantFallbackCheck(),
			$this->schedulingCheck(),
			$this->eInvoiceCheck(),
			$this->incomingInvoiceCheck(),
			$this->paperlessCheck(),
			$this->notificationCheck(),
			$this->maintenanceCheck(),
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
		return $this->check('installation', 'Installationsparameter', $status,
			$status === 'OK' ? 'Rollen- und Ablageparameter sind zentral konfiguriert.' : (!$memberExists ? 'Die konfigurierte Mitgliedergruppe existiert nicht.' : 'Keine konfigurierte Administrationsgruppe wurde gefunden; nur Nextcloud-Systemadministratoren besitzen Administrationsrechte.'),
			'Änderungen an Ablagepfaden verschieben vorhandene Dateien nicht automatisch.', [
				'Mitgliedergruppe: ' . $settings['memberGroup'],
				'Administrationsgruppen: ' . implode(', ', $settings['adminGroups']),
				'Fallakten: ' . $settings['paths']['cases'],
				'Vorlagen: ' . $settings['paths']['templates'],
			], ['view'=>'administration', 'tab'=>'system', 'label'=>'Installationsparameter öffnen']);
	}

	private function teamGroupExists(string $groupName): bool {
		return $this->groupManager->get($groupName) !== null;
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
			array_map(static fn(string $key, int $count): string => $key . ': ' . $count, array_keys($summary), array_values($summary)),
			['view'=>'administration', 'tab'=>'system', 'label'=>'Systemprüfung öffnen']);
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
		$query=$this->db->getQueryBuilder();$pending=(int)$query->select($query->func()->count('id','count'))->from('bestatter_external_documents')->where($query->expr()->eq('provider',$query->createNamedParameter('PAPERLESS')))->andWhere($query->expr()->eq('record_type',$query->createNamedParameter('INCOMING_INVOICE')))->andWhere($query->expr()->isNull('case_id'))->executeQuery()->fetchOne();
		$status=!$settings['enabled']?'ERROR':(($failed>0||$settings['captureDocumentTypeId']<=0)?'WARN':'OK');
		return $this->check('paperless','Optionale Paperless-Anbindung',$status,$status==='ERROR'?'Paperless ist aktiviert, aber Basisadresse oder API-Token fehlen.':($failed.' endgültig fehlgeschlagene Übertragung(en), '.$pending.' unzugeordnete Belege.'),'Bei Störungen bleiben Nextcloud-Original und manuelle Erfassung nutzbar. Für OCR-Erfassungsbögen zusätzlich eine eigene Dokumenttyp-ID konfigurieren.',['Betriebsart: '.$settings['mode'],'API-Token: '.($settings['tokenConfigured']?'gesetzt':'fehlt'),'Dokumenttyp Auftragserfassung: '.($settings['captureDocumentTypeId']>0?(string)$settings['captureDocumentTypeId']:'fehlt'),'Unzugeordnete Belege: '.$pending,'Fehlgeschlagene Aufträge: '.$failed],['view'=>'administration','tab'=>'paperless','label'=>'Paperless öffnen']);
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
		$level = $status['available'] ? 'OK' : ((bool)$settings['normativeValidationRequired'] ? 'ERROR' : 'WARN');
		$message = $status['available'] ? 'Mustang CLI ist für PDF/A-3-Einbettung und normative Prüfung verfügbar.' : 'E-Rechnungen können intern strukturell geprüft werden; eine normative EN16931-/PDF/A-3-Prüfung ist nicht verfügbar.';
		$hint = $status['available'] ? 'Vor Produktivbetrieb zusätzlich die jeweils aktuelle KoSIT-Konfiguration mit Referenzrechnungen prüfen.' : 'Mustang CLI auf dem Nextcloud-Host bereitstellen und den absoluten JAR-Pfad als Systemwert bestatter_einvoice_mustang_jar konfigurieren.';
		return $this->check('e_invoice', 'E-Rechnung und PDF/A-3', $level, $message, $hint, [
			'ZUGFeRD 2.5.2: ' . ($settings['zugferdEnabled'] ? 'aktiv' : 'inaktiv'),
			'XRechnung 3.0.x: ' . ($settings['xrechnungEnabled'] ? 'aktiv' : 'inaktiv'),
			'Normative Prüfung verpflichtend: ' . ($settings['normativeValidationRequired'] ? 'ja' : 'nein'),
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
			'Unterstützte Audioformate: ' . implode(', ', $health['supportedAudioFormats']),
		], ['view' => 'administration', 'tab' => 'assistant', 'label' => 'Assistenz-Einstellungen öffnen']);
	}

	private function assistantPrivacyCheck(): array {
		$health = $this->assistant->health();
		$deleteImmediately = $health['audioRetention'] === 'DELETE_AFTER_TRANSCRIPTION';
		return $this->check(
			'assistant_privacy', 'KI-Datenschutz und Audioaufbewahrung', $deleteImmediately ? 'OK' : 'ERROR',
			$deleteImmediately ? 'Temporäre Audiodateien werden nach erfolgreicher, fehlgeschlagener oder abgebrochener Transkription gelöscht.' : 'Die Audioaufbewahrung entspricht nicht der verbindlichen Löschregel.',
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
			->from('bestatter_workflow_runs')->groupBy('run_status')->executeQuery()->fetchAllAssociative();
		$counts = [];
		foreach ($rows as $row) $counts[strtoupper((string)$row['run_status'])] = (int)$row['count'];
		$running = $counts['RUNNING'] ?? 0;
		$failed = $counts['FAILED'] ?? 0;
		$status = $running > 0 ? 'ERROR' : ($failed > 0 ? 'WARN' : 'OK');
		$message = $running . ' laufende und ' . $failed . ' fehlgeschlagene Workflow-Ausführung(en).';
		if ($running === 0 && $failed === 0) $message = 'Keine hängenden oder fehlgeschlagenen Workflow-Ausführungen.';
		return $this->check('workflow_runs', 'Workflow-Stabilität', $status, $message, $failed > 0 ? 'Fehlgeschlagene Läufe bleiben als Prüf- und Wiederholungsnachweis erhalten. Erst eine erfolgreiche Wiederholung erledigt den betreffenden Lauf.' : '', array_map(static fn(string $runStatus, int $count): string => $runStatus . ': ' . $count, array_keys($counts), array_values($counts)), ['view' => 'customizing', 'tab' => 'workflows', 'label' => 'Workflows öffnen']);
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
