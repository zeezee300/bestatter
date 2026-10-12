<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/** Compact, non-destructive health snapshot for scheduled operation. */
class MaintenanceService {
	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private RetentionPolicyService $retention,
		private AuditService $audit,
		private LoggerInterface $logger,
		private AssistantService $assistant,
	) {}

	/**
	 * Jeder Teilschritt läuft isoliert: schlägt eine einzelne Kennzahl fehl (z.B. wegen
	 * eines vorübergehenden Datenbankfehlers), werden die übrigen Kennzahlen trotzdem
	 * ermittelt, statt den gesamten Wartungslauf ohne jede Aussage abzubrechen. Eine
	 * fehlgeschlagene Kennzahl erscheint als `null` im Summary und in `failedMetrics`
	 * und hebt den Gesamtstatus immer auf ERROR, da ihr tatsächlicher Zustand unbekannt ist.
	 */
	public function runScheduled(): array {
		$steps = [
			'orphanRecords' => fn(): int => $this->countOrphanRecords(),
			'syncErrors' => fn(): int => $this->countWhere('bestatter_records', "payload LIKE '%\"syncError\"%'"),
			'failedWorkflowRuns' => fn(): int => $this->countValue('bestatter_action_runs', 'run_status', 'FAILED'),
			'runningWorkflowRuns' => fn(): int => $this->countValue('bestatter_action_runs', 'run_status', 'RUNNING'),
			'pendingAssistantRules' => fn(): int => $this->countValue('bestatter_assistant_rules', 'status', 'PENDING'),
			'retentionDueCases' => fn(): int => $this->retention->settings()['enabled'] ? $this->retention->preview(500)['count'] : 0,
			'auditIntegrity' => fn(): string => $this->audit->verifyIntegrity()['status'],
			'failedPaperlessJobs' => fn(): int => $this->countValue('bestatter_integration_jobs', 'job_status', 'FAILED'),
			'staleCaptureImports' => fn(): int => $this->countStaleCaptureImports(),
			// 0.71.0: Sprachaufnahmen, deren Transkription nie abgeholt wurde, nach 24 Stunden löschen (Zusage „Audio wird gelöscht“).
			'deletedStaleAudio' => fn(): int => $this->assistant->cleanupStaleAudio(),
		];
		$summary = [];
		$failed = [];
		foreach ($steps as $metric => $step) {
			try {
				$summary[$metric] = $step();
			} catch (\Throwable $error) {
				$summary[$metric] = null;
				$failed[] = $metric;
				// Keine Exception/Trace loggen: könnte Fallbezüge aus der aufrufenden Kette enthalten.
				$this->logger->warning('Eine Kennzahl der Bestatter-Wartungsprüfung konnte nicht ermittelt werden; die übrigen Kennzahlen wurden trotzdem erhoben.', [
					'metric' => $metric,
					'exceptionClass' => $error::class,
				]);
			}
		}
		$count = static fn(string $key): int => is_int($summary[$key] ?? null) ? $summary[$key] : 0;
		$status = $failed !== [] || ($count('orphanRecords') + $count('runningWorkflowRuns')) > 0 || $summary['auditIntegrity'] === 'ERROR'
			? 'ERROR'
			: (($count('syncErrors') + $count('failedWorkflowRuns') + $count('retentionDueCases') + $count('failedPaperlessJobs') + $count('staleCaptureImports')) > 0 ? 'WARN' : 'OK');
		$now = date(DATE_ATOM);
		$this->config->setAppValue(Application::APP_ID, 'maintenance_last_run', $now);
		$this->config->setAppValue(Application::APP_ID, 'maintenance_last_status', $status);
		$this->config->setAppValue(Application::APP_ID, 'maintenance_last_summary', json_encode($summary, JSON_THROW_ON_ERROR));
		// Fallakten werden nie automatisch gelöscht; nur liegengebliebene persönliche Sprachaufnahmen.
		return ['status'=>$status, 'runAt'=>$now, 'summary'=>$summary, 'failedMetrics'=>$failed, 'automaticDeletions'=>$count('deletedStaleAudio'), 'retentionMode'=>'preview-only'];
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
		return (int)$query->select($query->func()->count('id','count'))->from('bestatter_capture_imports')->where($query->expr()->notIn('import_status',$query->createNamedParameter(['COMPLETED','DISCARDED'],\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))->andWhere($query->expr()->lt('updated_at',$query->createNamedParameter(date('c',time()-7*86400))))->executeQuery()->fetchOne();
	}
}
