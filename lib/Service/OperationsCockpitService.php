<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;

/** Administrative, read-only overview of operational exceptions. */
class OperationsCockpitService {
	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private RetentionPolicyService $retention,
	) {}

	public function report(): array {
		$failedWorkflows = $this->failedWorkflows();
		$paperlessJobs = $this->paperlessJobs();
		$retention = $this->retention->preview(500);
		$paperlessCounts = $this->paperlessCounts();
		$failedPaperless = (int)($paperlessCounts['FAILED'] ?? 0);
		$openPaperless = array_sum(array_intersect_key($paperlessCounts, array_flip(['QUEUED', 'RUNNING', 'RETRY'])));
		$maintenanceLastRun = $this->config->getAppValue(Application::APP_ID, 'maintenance_last_run', '');
		$maintenanceStatus = $this->config->getAppValue(Application::APP_ID, 'maintenance_last_status', 'UNKNOWN');
		$maintenanceAge = $maintenanceLastRun !== '' ? time() - (strtotime($maintenanceLastRun) ?: 0) : PHP_INT_MAX;
		$maintenanceStale = $maintenanceLastRun === '' || $maintenanceAge > 24 * 60 * 60;
		$failedWorkflowCount = $this->countFailedWorkflows();
		$retentionCount = (int)($retention['count'] ?? 0);
		$inconsistentCases = $this->inconsistentClosedCases();
		$overall = ($failedWorkflowCount + $failedPaperless) > 0 ? 'ERROR' : (($openPaperless + $retentionCount + count($inconsistentCases) + ($maintenanceStale ? 1 : 0)) > 0 ? 'WARN' : 'OK');

		return [
			'version'=>Application::VERSION,
			'generatedAt'=>date('c'),
			'overall'=>$overall,
			'summary'=>[
				'failedWorkflows'=>$failedWorkflowCount,
				'openPaperlessJobs'=>$openPaperless,
				'failedPaperlessJobs'=>$failedPaperless,
				'unassignedPaperlessDocuments'=>$this->countUnassignedPaperless(),
				'retentionDue'=>$retentionCount,
				'inconsistentSideOrderCases'=>count($inconsistentCases),
			],
			'workflowRuns'=>['items'=>$failedWorkflows, 'truncated'=>$failedWorkflowCount > count($failedWorkflows)],
			'paperless'=>['counts'=>$paperlessCounts, 'items'=>$paperlessJobs, 'truncated'=>array_sum($paperlessCounts) > count($paperlessJobs)],
			'retention'=>[
				'enabled'=>(bool)($retention['settings']['enabled'] ?? false),
				'mode'=>(string)($retention['settings']['mode'] ?? 'ANONYMIZE'),
				'cutoff'=>(string)($retention['cutoff'] ?? ''),
				'items'=>array_slice($retention['cases'] ?? [], 0, 25),
				'truncated'=>$retentionCount > 25,
			],
			'maintenance'=>['lastRun'=>$maintenanceLastRun, 'status'=>$maintenanceStatus, 'stale'=>$maintenanceStale],
			'inconsistentSideOrderCases'=>['items'=>$inconsistentCases, 'truncated'=>false],
			'readOnly'=>true,
		];
	}

	private function inconsistentClosedCases(): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->selectDistinct('c.id', 'c.case_number', 'so.side_order_number', 'so.status')
			->from('bestatter_cases', 'c')->innerJoin('c', 'bestatter_side_orders', 'so', 'so.case_id = c.id')
			->where($query->expr()->eq('c.status', $query->createNamedParameter('ABGESCHLOSSEN')))
			->andWhere($query->expr()->in('so.status', $query->createNamedParameter(['ENTWURF', 'BEAUFTRAGT'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('c.case_number', 'ASC')->setMaxResults(100)->executeQuery()->fetchAllAssociative();
		return array_map(static fn(array $row): array => ['id'=>(int)$row['id'], 'caseNumber'=>(string)$row['case_number'], 'sideOrderNumber'=>(string)$row['side_order_number'], 'status'=>(string)$row['status']], $rows);
	}

	private function failedWorkflows(): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('wr.id', 'wr.action_key', 'wr.result_json', 'wr.created_at', 'r.case_id', 'r.title', 'c.case_number')
			->from('bestatter_workflow_runs', 'wr')
			->leftJoin('wr', 'bestatter_records', 'r', 'r.id = wr.source_record_id')
			->leftJoin('r', 'bestatter_cases', 'c', 'c.id = r.case_id')
			->where($query->expr()->eq('wr.run_status', $query->createNamedParameter('FAILED')))
			->orderBy('wr.created_at', 'DESC')->setMaxResults(25)->executeQuery()->fetchAllAssociative();
		return array_map(static function (array $row): array {
			$result = json_decode((string)($row['result_json'] ?? '{}'), true) ?: [];
			return ['id'=>(int)$row['id'], 'caseId'=>$row['case_id'] !== null ? (int)$row['case_id'] : null,
				'caseNumber'=>(string)($row['case_number'] ?? ''), 'sourceTitle'=>(string)($row['title'] ?? ''),
				'actionKey'=>(string)$row['action_key'], 'error'=>mb_substr((string)($result['error']['message'] ?? 'Workflow-Ausführung fehlgeschlagen.'), 0, 500),
				'createdAt'=>(string)$row['created_at']];
		}, $rows);
	}

	private function paperlessJobs(): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('j.id', 'j.operation', 'j.job_status', 'j.attempts', 'j.next_attempt_at', 'j.last_error', 'j.updated_at', 'e.case_id', 'e.external_document_id', 'e.title', 'c.case_number')
			->from('bestatter_integration_jobs', 'j')
			->leftJoin('j', 'bestatter_external_documents', 'e', 'e.id = j.object_id')
			->leftJoin('e', 'bestatter_cases', 'c', 'c.id = e.case_id')
			->where($query->expr()->eq('j.provider', $query->createNamedParameter('PAPERLESS')))
			->andWhere($query->expr()->neq('j.job_status', $query->createNamedParameter('DONE')))
			->orderBy('j.updated_at', 'DESC')->setMaxResults(50)->executeQuery()->fetchAllAssociative();
		return array_map(static fn(array $row): array => [
			'id'=>(int)$row['id'], 'caseId'=>$row['case_id'] !== null ? (int)$row['case_id'] : null,
			'caseNumber'=>(string)($row['case_number'] ?? ''), 'externalDocumentId'=>$row['external_document_id'] !== null ? (string)$row['external_document_id'] : null,
			'title'=>(string)($row['title'] ?? ''), 'operation'=>(string)$row['operation'], 'status'=>(string)$row['job_status'],
			'attempts'=>(int)$row['attempts'], 'nextAttemptAt'=>(string)($row['next_attempt_at'] ?? ''),
			'error'=>mb_substr((string)($row['last_error'] ?? ''), 0, 500), 'updatedAt'=>(string)$row['updated_at'],
		], $rows);
	}

	private function paperlessCounts(): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('job_status', $query->func()->count('id', 'count'))->from('bestatter_integration_jobs')
			->where($query->expr()->eq('provider', $query->createNamedParameter('PAPERLESS')))
			->andWhere($query->expr()->neq('job_status', $query->createNamedParameter('DONE')))
			->groupBy('job_status')->executeQuery()->fetchAllAssociative();
		$result = [];
		foreach ($rows as $row) $result[(string)$row['job_status']] = (int)$row['count'];
		ksort($result);
		return $result;
	}

	private function countFailedWorkflows(): int {
		$query = $this->db->getQueryBuilder();
		return (int)$query->select($query->func()->count('id', 'count'))->from('bestatter_workflow_runs')
			->where($query->expr()->eq('run_status', $query->createNamedParameter('FAILED')))->executeQuery()->fetchOne();
	}

	private function countUnassignedPaperless(): int {
		$query = $this->db->getQueryBuilder();
		return (int)$query->select($query->func()->count('id', 'count'))->from('bestatter_external_documents')
			->where($query->expr()->eq('provider', $query->createNamedParameter('PAPERLESS')))
			->andWhere($query->expr()->eq('record_type', $query->createNamedParameter('INCOMING_INVOICE')))
			->andWhere($query->expr()->isNull('case_id'))->executeQuery()->fetchOne();
	}
}
