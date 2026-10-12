<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IGroupManager;

/**
 * Aufbewahrungsrichtlinie. Änderungen erfolgen ausschließlich über ein bestätigtes OCC-Kommando und nur bei
 * eingeschalteter Richtlinie. 0.75.1: Die gesetzliche Aufbewahrung von Rechnungen/Belegen leistet die Buchhaltung
 * (z. B. DATEV); die App weist auf noch laufende Fristen hin, sperrt aber nicht (siehe CaseDeletionService).
 */
class RetentionPolicyService {
	public function __construct(private IDBConnection $db, private IConfig $config, private AuditService $audit, private IRootFolder $rootFolder, private IGroupManager $groups, private InstallationConfigService $installationConfig, private CaseStorageService $storage, private CaseDeletionService $deletion, private PermanentDeletion $permanent, private AppCalendarObjects $calendar, private LabelService $labels) {}

	public function settings(): array {
		return [
			'enabled' => $this->bool('retention_enabled', false),
			'mode' => $this->value('retention_mode', 'ANONYMIZE'),
			'years' => (int)$this->value('retention_years', '10'),
			'automaticExecution' => false,
			'confirmationRequired' => true,
			'note' => 'Fristen sind vom Betreiber rechtlich festzulegen. Der Hintergrundjob löscht niemals automatisch.',
			'notices' => $this->policyNotices($this->value('retention_mode', 'ANONYMIZE'), (int)$this->value('retention_years', '10')),
		];
	}

	public function save(array $input): array {
		$years = (int)($input['years'] ?? 10);
		$mode = strtoupper(trim((string)($input['mode'] ?? 'ANONYMIZE')));
		if ($years < 1 || $years > 30) throw new \InvalidArgumentException('Die Aufbewahrungsfrist muss zwischen 1 und 30 Jahren liegen.');
		if (!in_array($mode, ['ANONYMIZE', 'DELETE'], true)) throw new \InvalidArgumentException('Die Löschmethode ist ungültig.');
		$this->config->setAppValue(Application::APP_ID, 'retention_enabled', !empty($input['enabled']) ? 'yes' : 'no');
		$this->config->setAppValue(Application::APP_ID, 'retention_mode', $mode);
		$this->config->setAppValue(Application::APP_ID, 'retention_years', (string)$years);
		$this->audit->logSystem('RETENTION_POLICY', 'UPDATED', ['enabled' => !empty($input['enabled']), 'mode' => $mode, 'years' => $years]);
		return $this->settings();
	}

	/** @return list<string> Hinweise zur Richtlinie selbst (keine Sperre). */
	private function policyNotices(string $mode, int $years): array {
		$notices = [];
		if ($mode === 'DELETE') $notices[] = 'Modus „Löschen“ entfernt Fälle vollständig – auch Rechnungen, Aufträge und Eingangsrechnungen in der App und im Fallordner. Die gesetzliche Aufbewahrung muss die Buchhaltung (z. B. DATEV) sicherstellen.';
		if ($mode === 'DELETE' && $years < CaseDeletionService::INVOICE_YEARS) $notices[] = 'Die eingestellte Frist (' . $years . ' Jahre) ist kürzer als die gesetzliche Aufbewahrung von Rechnungen und Buchungsbelegen (' . CaseDeletionService::INVOICE_YEARS . ' Jahre ab Jahresende). Betroffene Fälle werden in der Vorschau einzeln gekennzeichnet.';
		return $notices;
	}

	public function preview(int $limit = 100): array {
		$settings = $this->settings();
		$cutoff = date('Y-m-d', strtotime('-' . $settings['years'] . ' years'));
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('id', 'case_number', 'first_name', 'last_name', 'status', 'updated_at', 'retention_due_at')
			->from('bestatter_cases')
			->where($query->expr()->orX(
				$query->expr()->eq('status', $query->createNamedParameter('ABGESCHLOSSEN')),
				$query->expr()->eq('status', $query->createNamedParameter('STORNIERT')),
			))
			->andWhere($query->expr()->eq('retention_hold', $query->createNamedParameter(false)))
			->andWhere($query->expr()->orX(
				$query->expr()->andX($query->expr()->isNotNull('retention_due_at'), $query->expr()->lte('retention_due_at', $query->createNamedParameter(date('Y-m-d')))),
				$query->expr()->andX($query->expr()->isNull('retention_due_at'), $query->expr()->lte('updated_at', $query->createNamedParameter($cutoff . ' 23:59:59'))),
			))->orderBy('updated_at', 'ASC')->setMaxResults(max(1, min(500, $limit)))->executeQuery()->fetchAllAssociative();
		$deleteMode = $settings['mode'] === 'DELETE';
		return ['settings' => $settings, 'cutoff' => $cutoff, 'count' => count($rows), 'cases' => array_map(fn(array $row): array => [
			'id' => (int)$row['id'], 'caseNumber' => (string)$row['case_number'], 'name' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']), 'status' => (string)$row['status'], 'lastChanged' => (string)$row['updated_at'], 'individualDueDate' => $row['retention_due_at'],
			// Beim Anonymisieren bleiben Belege erhalten; Hinweise nur für den Modus „Löschen“.
			'notices' => $deleteMode ? $this->deletion->notices((int)$row['id']) : [],
		], $rows)];
	}

	public function setHold(int $caseId, bool $hold, ?string $dueAt = null, string $reason = '', ?array $responsible = null, ?string $reviewAt = null): array {
		if ($dueAt !== null && $dueAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueAt)) throw new \InvalidArgumentException('Das individuelle Löschdatum ist ungültig.');
		$reason = trim($reason);
		if (mb_strlen($reason) < 5) throw new \InvalidArgumentException($hold ? 'Bitte geben Sie einen nachvollziehbaren Grund für den Legal Hold an.' : 'Bitte begründen Sie das Aufheben des Legal Holds.');
		if ($reviewAt !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewAt)) throw new \InvalidArgumentException('Das Überprüfungsdatum ist ungültig.');
		if ($hold && $reviewAt !== null && $reviewAt < date('Y-m-d')) throw new \InvalidArgumentException('Das Überprüfungsdatum darf nicht in der Vergangenheit liegen.');
		if ($hold && (empty($responsible['uid']) || empty($responsible['displayName']))) throw new \InvalidArgumentException('Bitte wählen Sie eine verantwortliche Person aus der Bestatter-Gruppe.');

		$before = $this->holdState($caseId);
		$setAt = $hold ? date('c') : null;
		$query = $this->db->getQueryBuilder();
		$changed = $query->update('bestatter_cases')
			->set('retention_hold', $query->createNamedParameter($hold))
			->set('retention_due_at', $query->createNamedParameter($dueAt ?: null))
			->set('retention_hold_reason', $query->createNamedParameter($hold ? $reason : null))
			->set('retention_hold_responsible', $query->createNamedParameter($hold ? (string)$responsible['uid'] : null))
			->set('retention_hold_set_at', $query->createNamedParameter($setAt))
			->set('retention_hold_review_at', $query->createNamedParameter($hold ? $reviewAt : null))
			->where($query->expr()->eq('id', $query->createNamedParameter($caseId)))
			->executeStatement();
		if ($changed !== 1) throw new \InvalidArgumentException('Der Fall wurde nicht gefunden.');
		$after = [
			'hold' => $hold,
			'dueAt' => $dueAt,
			'reason' => $reason,
			'responsibleUid' => $hold ? (string)$responsible['uid'] : null,
			'responsibleName' => $hold ? (string)$responsible['displayName'] : null,
			'setAt' => $setAt,
			'reviewAt' => $hold ? $reviewAt : null,
		];
		$this->audit->log($caseId, 'RETENTION', $caseId, $hold ? 'LEGAL_HOLD_SET' : 'LEGAL_HOLD_RELEASED', $before, $after);
		return ['caseId' => $caseId, ...$after];
	}

	private function holdState(int $caseId): array {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('retention_hold', 'retention_due_at', 'retention_hold_reason', 'retention_hold_responsible', 'retention_hold_set_at', 'retention_hold_review_at')
			->from('bestatter_cases')->where($query->expr()->eq('id', $query->createNamedParameter($caseId)))
			->executeQuery()->fetchAssociative();
		if ($row === false) throw new \InvalidArgumentException('Der Fall wurde nicht gefunden.');
		return [
			'hold' => (bool)$row['retention_hold'],
			'dueAt' => $row['retention_due_at'],
			'reason' => $row['retention_hold_reason'],
			'responsibleUid' => $row['retention_hold_responsible'],
			'setAt' => $row['retention_hold_set_at'],
			'reviewAt' => $row['retention_hold_review_at'],
		];
	}

	public function execute(array $caseIds): array {
		if (!$this->settings()['enabled']) throw new \InvalidArgumentException('Die Aufbewahrungsrichtlinie ist ausgeschaltet. Bitte zuerst unter Administration → Datenschutz einschalten; es wurde nichts verändert.');
		$preview = $this->preview(500);
		$allowed = array_column($preview['cases'], 'id');
		$targets = array_values(array_intersect(array_map('intval', $caseIds), $allowed));
		$result = ['mode' => $preview['settings']['mode'], 'processed' => [], 'skipped' => array_values(array_diff(array_map('intval', $caseIds), $targets))];
		foreach ($targets as $caseId) {
			$result['processed'][] = $preview['settings']['mode'] === 'DELETE' ? $this->deletion->delete($caseId, 'RETENTION') : $this->anonymizeCase($caseId);
		}
		return $result;
	}

	/**
	 * Anonymisierung entfernt personenbezogene Felder des Falls sowie rein operative
	 * Begleitdaten. Rechnungen, eingehende Rechnungen, kaufmännische Dokumente und das
	 * Audit-Log unterliegen einer eigenständigen, von der DSGVO-Löschung unabhängigen
	 * gesetzlichen Aufbewahrungspflicht (§257 HGB / §147 AO) bzw. dienen als Nachweis
	 * rechtmäßiger Verarbeitung und werden daher NICHT gelöscht (Audit-Inhalte werden
	 * geleert, die Zeilen bleiben). Der Modus DELETE (CaseDeletionService) entfernt den
	 * Fall vollständig, nach Hinweis auf noch laufende Fristen.
	 *
	 * Die Datei-Löschung erfolgt bewusst innerhalb der noch offenen Transaktion, bevor
	 * committet wird: schlägt sie fehl, wird die Transaktion zurückgerollt, sodass der
	 * Fall unverändert (inkl. Status) bleibt und beim nächsten Lauf erneut ausgewählt
	 * wird. Nur wenn Dateien und Datenbank beide erfolgreich waren, wird committet -
	 * damit kann nie der Zustand "Dateien gelöscht, Fall aber nicht anonymisiert/
	 * gelöscht" oder "Fall anonymisiert/gelöscht, aber Dateien bleiben für immer liegen"
	 * entstehen.
	 */
	private function anonymizeCase(int $caseId): array {
		$caseNumber = $this->caseNumber($caseId);
		$calendarTargets = $this->calendar->targets($caseId);
		$this->db->beginTransaction();
		try {
			$this->deleteDependants($caseId);
			// 0.76.0: Marker – nur solche mit „bei Anonymisierung behalten“ (z. B. Kostenträger) bleiben, ohne Benutzerkennungen.
			$this->labels->anonymizeCase($caseId);
			// 0.75.1: Personenbezogene Inhalte auch aus dem Audit-Log entfernen (Zeilen und Hash-Kette bleiben).
			$this->audit->redactCase($caseId);
			$q = $this->db->getQueryBuilder();
			$q->update('bestatter_cases')->set('first_name', $q->createNamedParameter('Anonymisiert'))->set('last_name', $q->createNamedParameter('#' . $caseId))->set('date_of_death', $q->createNamedParameter(null))->set('responsible_employee', $q->createNamedParameter(null))->set('master_data', $q->createNamedParameter('{}'))->set('status', $q->createNamedParameter('ANONYMISIERT'))->set('anonymized_at', $q->createNamedParameter(date('Y-m-d H:i:s')))->set('updated_at', $q->createNamedParameter(date('Y-m-d H:i:s')))->where($q->expr()->eq('id', $q->createNamedParameter($caseId)))->executeStatement();
			// 0.70.1: Rechnungs-/Korrekturbelege (PDF, E-Rechnung), Eingangsrechnungen und Auftragsunterlagen
			// unterliegen der Aufbewahrungspflicht und bleiben erhalten; nur der übrige Fallordner wird gelöscht.
			$files = $this->deleteCaseFolders($caseNumber, $this->retainedFileIds($caseId));
			$this->db->commit();
		} catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
		$calendar = $this->calendar->delete($calendarTargets);
		$this->audit->logSystem('RETENTION', 'ANONYMIZED', ['caseId' => $caseId, 'retainedFiles' => count($files['retained'] ?? []), 'calendarDeleted' => $calendar['deleted'], 'calendarErrors' => count($calendar['errors'])]);
		return ['caseId' => $caseId, 'action' => 'ANONYMIZED', 'folders' => $files, 'calendar' => $calendar];
	}

	/**
	 * Anonymisieren: lässt Rechnungen, kaufmännische Dokumente und Leistungen (deren Beleggrundlage) sowie die
	 * Zeilen des Audit-Logs stehen. Vollständiges Löschen übernimmt CaseDeletionService.
	 */
	private function deleteDependants(int $caseId): void {
		$this->deleteWhere('bestatter_capture_imports','case_id',$caseId);
		// 0.74.0: Formularwerte sind nur Erfassungshilfe (Beleggrundlage bleiben die Leistungen) und enthalten
		// personenbezogene Hinweise (Kassen, Redner) – daher auch beim Anonymisieren entfernen.
		$this->deleteWhere('bestatter_order_form_entries','case_id',$caseId);
		$externalIds=$this->ids('bestatter_external_documents','case_id',$caseId); foreach($externalIds as $id)$this->deleteWhere('bestatter_integration_jobs','object_id',$id);
		$this->deleteWhere('bestatter_external_documents','case_id',$caseId);
		$recordIds=$this->ids('bestatter_records','case_id',$caseId);
		foreach($recordIds as $recordId)$this->deleteWhere('bestatter_action_runs','source_record_id',$recordId);
		$this->deleteWhere('bestatter_process_runs','case_id',$caseId);
		$this->deleteWhere('bestatter_schedule_history','case_id',$caseId);
		$this->deleteWhere('bestatter_mail_outbox','case_id',$caseId);
		$this->deleteWhere('bestatter_dereg_mail_outbox','case_id',$caseId);
		foreach($recordIds as $recordId)$this->deleteWhere('bestatter_record_assignees','record_id',$recordId);
		$this->deleteWhere('bestatter_records','case_id',$caseId);
		// 0.75.1: Nebenaufträge enthalten Kontaktdaten weiterer Auftraggeber. Beim Anonymisieren bleiben Nummer und
		// Status als Bezug der aufbewahrten Rechnungen erhalten (Empfänger steht als Momentaufnahme in der Rechnung).
		$q=$this->db->getQueryBuilder();
		$q->update('bestatter_side_orders')->set('first_name',$q->createNamedParameter(''))->set('last_name',$q->createNamedParameter('Anonymisiert'))->set('street',$q->createNamedParameter(''))->set('postal_city',$q->createNamedParameter(''))->set('phone',$q->createNamedParameter(''))->set('email',$q->createNamedParameter(''))->set('relation',$q->createNamedParameter(''))->where($q->expr()->eq('case_id',$q->createNamedParameter($caseId)))->executeStatement();
	}

	private function caseNumber(int $caseId): string { $q=$this->db->getQueryBuilder();$value=$q->select('case_number')->from('bestatter_cases')->where($q->expr()->eq('id',$q->createNamedParameter($caseId)))->executeQuery()->fetchOne();if($value===false)throw new \InvalidArgumentException('Fall wurde nicht gefunden.');return (string)$value; }

	/**
	 * Löscht die Fallordner eines Falls. Mit $retainFileIds (Modus ANONYMIZE) bleiben die aufbewahrungspflichtigen
	 * Unterlagen erhalten: der Abrechnungs- und der Auftragsordner vollständig sowie jede Datei, auf die eine
	 * Rechnung (Abschlussnachweis) oder Eingangsrechnung verweist – auch wenn sie in einem anderen Unterordner liegt.
	 * Alles Übrige (Stammdaten, Behörden, Fotos, Korrespondenz …) wird gelöscht.
	 * @param list<int>|null $retainFileIds null = vollständige Löschung (Modus DELETE)
	 */
	private function deleteCaseFolders(string $caseNumber, ?array $retainFileIds = null): array {
		$year=substr($caseNumber,0,4); if(!preg_match('/^20\d{2}$/',$year))throw new \RuntimeException('Fallordner konnte wegen ungültiger Fallnummer nicht sicher bestimmt werden.');
		$result=['deleted'=>[],'missing'=>[],'retained'=>[]]; $relative=$this->installationConfig->casesPath().'/'.$year.'/'.$caseNumber;
		// 0.70.0: zentrale Ablage und persönliche Altbestände (Kopien aus der Zeit vor der Migration) werden gleichermaßen behandelt.
		$entries=$this->storage->existingFolders($relative);
		if($entries===[]){$result['missing'][]=$relative;return $result;}
		if($retainFileIds===null){
			foreach($entries as $entry){if(!$entry['folder']->isDeletable())throw new \RuntimeException('Fallordner ist nicht löschbar: '.$entry['label']);}
			foreach($entries as $entry){$this->permanent->delete($entry['folder']);$result['deleted'][]=$entry['label'];}
			return $result;
		}
		$keepFolders=array_values(array_filter([trim($this->installationConfig->billingFolder(),'/'),trim($this->installationConfig->orderFolder(),'/')],static fn(string $name):bool=>$name!==''));
		$keepIds=array_fill_keys(array_map('intval',$retainFileIds),true);
		foreach($entries as $entry){
			$plan=['delete'=>[],'retain'=>[]];
			$this->planPartialDelete($entry['folder'],$entry['label'],$keepFolders,$keepIds,$plan,true);
			foreach($plan['delete'] as [$node,$label]){if(!$node->isDeletable())throw new \RuntimeException('Datei oder Ordner ist nicht löschbar: '.$label);}
			foreach($plan['delete'] as [$node,$label]){$this->permanent->delete($node);$result['deleted'][]=$label;}
			array_push($result['retained'],...$plan['retain']);
		}
		return $result;
	}

	/** @param array<int,bool> $keepIds @param array{delete:list<array{0:Node,1:string}>,retain:list<string>} $plan */
	private function planPartialDelete(Folder $folder,string $label,array $keepFolders,array $keepIds,array &$plan,bool $isCaseRoot): void {
		foreach($folder->getDirectoryListing() as $node){
			$nodeLabel=$label.'/'.$node->getName();
			if($isCaseRoot&&$node instanceof Folder&&in_array($node->getName(),$keepFolders,true)){$plan['retain'][]=$nodeLabel;continue;}
			if(isset($keepIds[(int)$node->getId()])){$plan['retain'][]=$nodeLabel;continue;}
			if($node instanceof Folder&&$this->containsAny($node,$keepIds)){$this->planPartialDelete($node,$nodeLabel,$keepFolders,$keepIds,$plan,false);continue;}
			$plan['delete'][]=[$node,$nodeLabel];
		}
	}

	/** @param array<int,bool> $keepIds */
	private function containsAny(Folder $folder,array $keepIds): bool {
		if($keepIds===[])return false;
		foreach(array_keys($keepIds) as $id){if($folder->getById($id)!==[])return true;}
		return false;
	}

	/** @return list<int> Datei-IDs der aufbewahrungspflichtigen Belege eines Falls (Rechnungen inkl. Korrekturbelege, Eingangsrechnungen). */
	private function retainedFileIds(int $caseId): array {
		$ids=[];
		$q=$this->db->getQueryBuilder();
		foreach($q->select('closure_manifest')->from('bestatter_invoices')->where($q->expr()->eq('case_id',$q->createNamedParameter($caseId)))->executeQuery()->fetchFirstColumn() as $manifest){
			$data=json_decode((string)$manifest,true);
			if(is_array($data))array_walk_recursive($data,static function($value,$key)use(&$ids):void{if(is_string($key)&&($key==='fileId'||str_ends_with($key,'FileId'))&&is_numeric($value)&&(int)$value>0)$ids[]=(int)$value;});
		}
		$q=$this->db->getQueryBuilder();
		foreach($q->select('file_id')->from('bestatter_incoming_invoices')->where($q->expr()->eq('case_id',$q->createNamedParameter($caseId)))->executeQuery()->fetchFirstColumn() as $id){if($id!==null&&(int)$id>0)$ids[]=(int)$id;}
		return array_values(array_unique($ids));
	}

	private function ids(string $table,string $field,int $value): array { $q=$this->db->getQueryBuilder(); return array_map('intval',$q->select('id')->from($table)->where($q->expr()->eq($field,$q->createNamedParameter($value)))->executeQuery()->fetchFirstColumn()); }
	private function deleteWhere(string $table,string $field,int $value): void { $q=$this->db->getQueryBuilder(); $q->delete($table)->where($q->expr()->eq($field,$q->createNamedParameter($value)))->executeStatement(); }
	private function value(string $key,string $default): string { return trim($this->config->getAppValue(Application::APP_ID,$key,$default)); }
	private function bool(string $key,bool $default): bool { return in_array(strtolower($this->value($key,$default?'yes':'no')),['1','yes','true','on'],true); }
}
