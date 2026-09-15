<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;

/** Compact, non-destructive health snapshot for scheduled operation. */
class MaintenanceService {
	public function __construct(private IDBConnection $db, private IConfig $config, private RetentionPolicyService $retention, private AuditService $audit) {}

	public function runScheduled(): array {
		$summary = [
			'orphanRecords' => $this->countOrphanRecords(),
			'syncErrors' => $this->countWhere('bestatter_records', "payload LIKE '%\"syncError\"%'"),
			'failedWorkflowRuns' => $this->countValue('bestatter_workflow_runs', 'run_status', 'FAILED'),
			'runningWorkflowRuns' => $this->countValue('bestatter_workflow_runs', 'run_status', 'RUNNING'),
			'pendingAssistantRules' => $this->countValue('bestatter_assistant_rules', 'status', 'PENDING'),
			'retentionDueCases' => $this->retention->settings()['enabled'] ? $this->retention->preview(500)['count'] : 0,
			'auditIntegrity' => $this->audit->verifyIntegrity()['status'],
			'failedPaperlessJobs' => $this->countValue('bestatter_integration_jobs', 'job_status', 'FAILED'),
			'staleCaptureImports' => $this->countStaleCaptureImports(),
		];
		$status = ($summary['orphanRecords'] + $summary['runningWorkflowRuns']) > 0 || $summary['auditIntegrity'] === 'ERROR' ? 'ERROR' : (($summary['syncErrors'] + $summary['failedWorkflowRuns'] + $summary['retentionDueCases'] + $summary['failedPaperlessJobs'] + $summary['staleCaptureImports']) > 0 ? 'WARN' : 'OK');
		$now = date(DATE_ATOM);
		$this->config->setAppValue(Application::APP_ID, 'maintenance_last_run', $now);
		$this->config->setAppValue(Application::APP_ID, 'maintenance_last_status', $status);
		$this->config->setAppValue(Application::APP_ID, 'maintenance_last_summary', json_encode($summary, JSON_THROW_ON_ERROR));
		return ['status'=>$status, 'runAt'=>$now, 'summary'=>$summary, 'automaticDeletions'=>0, 'retentionMode'=>'preview-only'];
	}

	private function countWhere(string $table, string $where): int {
		$query = $this->db->getQueryBuilder();
		return (int)$query->select($query->func()->count('id', 'count'))->from($table)->where($where)->executeQuery()->fetchOne();
	}

	private function countValue(string $table, string $field, string $value): int {
		$query = $this->db->getQueryBuilder();
		return (int)$query->select($query->func()->count('id', 'count'))->from($table)->where($query->expr()->eq($field, $query->createNamedParameter($value)))->executeQuery()->fetchOne();
	}

	private function countOrphanRecords(): int {
		$query = $this->db->getQueryBuilder();
		return (int)$query->select($query->func()->count('r.id', 'count'))->from('bestatter_records', 'r')->leftJoin('r', 'bestatter_cases', 'c', $query->expr()->eq('c.id', 'r.case_id'))->where($query->expr()->isNotNull('r.case_id'))->andWhere($query->expr()->isNull('c.id'))->executeQuery()->fetchOne();
	}

	private function countStaleCaptureImports(): int {
		$query=$this->db->getQueryBuilder();
		return (int)$query->select($query->func()->count('id','count'))->from('bestatter_capture_imports')->where($query->expr()->neq('import_status',$query->createNamedParameter('COMPLETED')))->andWhere($query->expr()->lt('updated_at',$query->createNamedParameter(date('c',time()-7*86400))))->executeQuery()->fetchOne();
	}
}
