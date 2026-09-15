<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;

/** Sichere Aufbewahrungsprüfung. Änderungen erfolgen ausschließlich über ein bestätigtes OCC-Kommando. */
class RetentionPolicyService {
	public function __construct(private IDBConnection $db, private IConfig $config, private AuditService $audit, private IRootFolder $rootFolder, private IGroupManager $groups, private InstallationConfigService $installationConfig) {}

	public function settings(): array {
		return [
			'enabled' => $this->bool('retention_enabled', false),
			'mode' => $this->value('retention_mode', 'ANONYMIZE'),
			'years' => (int)$this->value('retention_years', '10'),
			'automaticExecution' => false,
			'confirmationRequired' => true,
			'note' => 'Fristen sind vom Betreiber rechtlich festzulegen. Der Hintergrundjob löscht niemals automatisch.',
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
		return ['settings' => $settings, 'cutoff' => $cutoff, 'count' => count($rows), 'cases' => array_map(static fn(array $row): array => [
			'id' => (int)$row['id'], 'caseNumber' => (string)$row['case_number'], 'name' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']), 'status' => (string)$row['status'], 'lastChanged' => (string)$row['updated_at'], 'individualDueDate' => $row['retention_due_at'],
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
		$preview = $this->preview(500);
		$allowed = array_column($preview['cases'], 'id');
		$targets = array_values(array_intersect(array_map('intval', $caseIds), $allowed));
		$result = ['mode' => $preview['settings']['mode'], 'processed' => [], 'skipped' => array_values(array_diff(array_map('intval', $caseIds), $targets))];
		foreach ($targets as $caseId) {
			$result['processed'][] = $preview['settings']['mode'] === 'DELETE' ? $this->deleteCase($caseId) : $this->anonymizeCase($caseId);
		}
		return $result;
	}

	private function anonymizeCase(int $caseId): array {
		$caseNumber=$this->caseNumber($caseId); $files=$this->deleteCaseFolders($caseNumber);
		$this->db->beginTransaction();
		try {
			$this->deleteDependants($caseId);
			$q = $this->db->getQueryBuilder();
			$q->update('bestatter_cases')->set('first_name', $q->createNamedParameter('Anonymisiert'))->set('last_name', $q->createNamedParameter('#' . $caseId))->set('date_of_death', $q->createNamedParameter(null))->set('responsible_employee', $q->createNamedParameter(null))->set('master_data', $q->createNamedParameter('{}'))->set('status', $q->createNamedParameter('ANONYMISIERT'))->set('anonymized_at', $q->createNamedParameter(date('Y-m-d H:i:s')))->set('updated_at', $q->createNamedParameter(date('Y-m-d H:i:s')))->where($q->expr()->eq('id', $q->createNamedParameter($caseId)))->executeStatement();
			$this->db->commit();
		} catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
		$this->audit->logSystem('RETENTION', 'ANONYMIZED', ['caseId' => $caseId]);
		return ['caseId' => $caseId, 'action' => 'ANONYMIZED', 'folders' => $files];
	}

	private function deleteCase(int $caseId): array {
		$caseNumber=$this->caseNumber($caseId); $files=$this->deleteCaseFolders($caseNumber);
		$this->db->beginTransaction();
		try { $this->deleteDependants($caseId); $q=$this->db->getQueryBuilder(); $q->delete('bestatter_cases')->where($q->expr()->eq('id',$q->createNamedParameter($caseId)))->executeStatement(); $this->db->commit(); }
		catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
		$this->audit->logSystem('RETENTION', 'DELETED', ['caseId' => $caseId]);
		return ['caseId' => $caseId, 'action' => 'DELETED', 'folders' => $files];
	}

	private function deleteDependants(int $caseId): void {
		$this->deleteWhere('bestatter_capture_imports','case_id',$caseId);
		$externalIds=$this->ids('bestatter_external_documents','case_id',$caseId); foreach($externalIds as $id)$this->deleteWhere('bestatter_integration_jobs','object_id',$id);
		$this->deleteWhere('bestatter_external_documents','case_id',$caseId);
		$recordIds=$this->ids('bestatter_records','case_id',$caseId);
		foreach($recordIds as $recordId)$this->deleteWhere('bestatter_workflow_runs','source_record_id',$recordId);
		$this->deleteWhere('bestatter_schedule_history','case_id',$caseId);
		$invoiceIds=$this->ids('bestatter_invoices','case_id',$caseId); foreach($invoiceIds as $id)$this->deleteWhere('bestatter_invoice_items','invoice_id',$id);
		$incomingIds=$this->ids('bestatter_incoming_invoices','case_id',$caseId); foreach($incomingIds as $id)$this->deleteWhere('bestatter_incoming_items','incoming_invoice_id',$id);
		foreach (['bestatter_audit_log','bestatter_invoices','bestatter_commercial_docs','bestatter_incoming_invoices','bestatter_case_services','bestatter_records'] as $table) $this->deleteWhere($table,'case_id',$caseId);
	}

	private function caseNumber(int $caseId): string { $q=$this->db->getQueryBuilder();$value=$q->select('case_number')->from('bestatter_cases')->where($q->expr()->eq('id',$q->createNamedParameter($caseId)))->executeQuery()->fetchOne();if($value===false)throw new \InvalidArgumentException('Fall wurde nicht gefunden.');return (string)$value; }

	private function deleteCaseFolders(string $caseNumber): array {
		$year=substr($caseNumber,0,4); if(!preg_match('/^20\d{2}$/',$year))throw new \RuntimeException('Fallordner konnte wegen ungültiger Fallnummer nicht sicher bestimmt werden.');
		$uids=[]; foreach(array_unique([$this->installationConfig->memberGroup(),...$this->installationConfig->adminGroups()]) as $groupName){$group=$this->groups->get($groupName);if($group!==null)foreach($group->getUsers() as $user)$uids[$user->getUID()]=true;}
		$result=['deleted'=>[],'missing'=>[]]; $relative=$this->installationConfig->casesPath().'/'.$year.'/'.$caseNumber;
		foreach(array_keys($uids) as $uid){$folder=$this->rootFolder->getUserFolder($uid);if(!$folder->nodeExists($relative)){$result['missing'][]=$uid.'/'.$relative;continue;}$node=$folder->get($relative);if(!$node->isDeletable())throw new \RuntimeException('Fallordner ist nicht löschbar: '.$uid.'/'.$relative);$node->delete();$result['deleted'][]=$uid.'/'.$relative;}
		return $result;
	}

	private function ids(string $table,string $field,int $value): array { $q=$this->db->getQueryBuilder(); return array_map('intval',$q->select('id')->from($table)->where($q->expr()->eq($field,$q->createNamedParameter($value)))->executeQuery()->fetchFirstColumn()); }
	private function deleteWhere(string $table,string $field,int $value): void { $q=$this->db->getQueryBuilder(); $q->delete($table)->where($q->expr()->eq($field,$q->createNamedParameter($value)))->executeStatement(); }
	private function value(string $key,string $default): string { return trim($this->config->getAppValue(Application::APP_ID,$key,$default)); }
	private function bool(string $key,bool $default): bool { return in_array(strtolower($this->value($key,$default?'yes':'no')),['1','yes','true','on'],true); }
}
