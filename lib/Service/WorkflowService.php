<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserSession;

class WorkflowService {
	private const RESOURCE = __DIR__ . '/../../resources/workflow-templates.json';
	private const ACTION_TYPES = ['TASK', 'REMINDER', 'SCHEDULE', 'DOCUMENT', 'DOCUMENT_BUNDLE', 'EMAIL_DRAFT', 'CONTACT_ACTIVITY', 'CASE_STATUS'];
	private const STATUSES = ['ANY', 'OFFEN', 'IN_BEARBEITUNG', 'ERLEDIGT', 'ENTWURF', 'BESTAETIGT', 'GEAENDERT', 'ABGESAGT'];
	private const TRIGGER_TYPES = ['TASK', 'SCHEDULE'];
	private const TRIGGER_EVENTS = ['MANUAL', 'CONFIRMED'];
	private const CASE_STATUSES = ['NEU', 'IN_BEARBEITUNG', 'ABGESCHLOSSEN', 'STORNIERT'];

	public function __construct(
		private IDBConnection $db,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private RecordService $records,
		private GroupwareService $groupware,
		private CaseService $cases,
		private DocumentService $documents,
		private AuditService $audit,
	) {}

	public function ensureSeedData(): void {
		$data = json_decode((string)file_get_contents(self::RESOURCE), true, 512, JSON_THROW_ON_ERROR);
		foreach ($data['workflows'] ?? [] as $workflow) {
			$query = $this->db->getQueryBuilder();
			$exists = $query->select('id')->from('bestatter_workflows')
				->where($query->expr()->eq('workflow_key', $query->createNamedParameter($workflow['key'])))
				->executeQuery()->fetchOne();
			if ($exists) {
				$this->alignBundledWorkflowCompatibility((int)$exists, (string)($workflow['key'] ?? ''));
				continue;
			}
			$this->insert($this->validate($workflow));
		}
	}

	private function alignBundledWorkflowCompatibility(int $workflowId, string $workflowKey): void {
		if (strtoupper($workflowKey) !== 'KONDOLENZLISTEN_ERSTELLEN') return;
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_workflows')
			->set('required_status', $query->createNamedParameter('ANY'))
			->set('trigger_type', $query->createNamedParameter('TASK'))
			->set('trigger_event', $query->createNamedParameter('MANUAL'))
			->set('task_title_pattern', $query->createNamedParameter('Kondolenzlisten erstellen'))
			->where($query->expr()->eq('id', $query->createNamedParameter($workflowId)))
			->executeStatement();
	}

	public function list(bool $activeOnly = false): array {
		$this->ensureSeedData();
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from('bestatter_workflows')->orderBy('sort_order', 'ASC')->addOrderBy('name', 'ASC');
		if ($activeOnly) {
			$query->where($query->expr()->eq('active', $query->createNamedParameter(1)));
		}
		return array_map([$this, 'map'], $query->executeQuery()->fetchAllAssociative());
	}

	public function schedulePresets(): array {
		$resource = json_decode((string)file_get_contents(self::RESOURCE), true, 512, JSON_THROW_ON_ERROR);
		$presets = [];
		foreach ($resource['schedulePresets'] ?? [] as $preset) {
			$normalized = $this->normalizeSchedulePreset($preset, 'CATALOG');
			$presets[$normalized['key']] = $normalized;
		}
		foreach ($this->list(true) as $workflow) {
			foreach ($workflow['actions'] as $action) {
				if (($action['type'] ?? '') !== 'SCHEDULE') continue;
				$normalized = $this->normalizeSchedulePreset($action + ['workflowName' => $workflow['name']], 'WORKFLOW');
				$presets[$normalized['key']] ??= $normalized;
			}
		}
		return array_values($presets);
	}

	public function create(string $workflow): array {
		$data = $this->decode($workflow);
		$data['key'] = strtoupper(trim((string)($data['key'] ?? '')));
		if (!preg_match('/^[A-Z0-9_-]+$/', $data['key'])) {
			throw new \InvalidArgumentException('Der Workflow-Schlüssel darf nur Großbuchstaben, Zahlen, Bindestrich und Unterstrich enthalten.');
		}
		return $this->insert($this->validate($data));
	}

	public function update(int $id, string $workflow): array {
		$this->getRow($id);
		$data = $this->validate($this->decode($workflow));
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_workflows')
			->set('name', $query->createNamedParameter($data['name']))
			->set('description', $query->createNamedParameter($data['description']))
			->set('task_title_pattern', $query->createNamedParameter($data['taskTitlePattern'] ?: null))
			->set('checklist_key', $query->createNamedParameter($data['checklistKey'] ?: null))
			->set('required_status', $query->createNamedParameter($data['requiredStatus']))
			->set('trigger_type', $query->createNamedParameter($data['triggerType']))
			->set('trigger_event', $query->createNamedParameter($data['triggerEvent']))
			->set('trigger_config', $query->createNamedParameter(json_encode($data['triggerConfig'], JSON_THROW_ON_ERROR)))
			->set('actions_json', $query->createNamedParameter(json_encode($data['actions'], JSON_THROW_ON_ERROR)))
			->set('active', $query->createNamedParameter((int)$data['active']))
			->set('sort_order', $query->createNamedParameter($data['sortOrder']))
			->set('updated_at', $query->createNamedParameter(date('c')))
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->executeStatement();
		return $this->get($id);
	}

	public function reorder(array $workflowIds): array {
		$ids = array_values(array_unique(array_filter(array_map('intval', $workflowIds), static fn(int $id): bool => $id > 0)));
		$existing = array_map(static fn(array $row): int => (int)$row['id'], $this->list());
		$expected = $existing;
		$submitted = $ids;
		sort($expected);
		sort($submitted);
		if ($submitted !== $expected) {
			throw new \InvalidArgumentException('Für die Sortierung müssen alle vorhandenen Workflows genau einmal übergeben werden.');
		}

		$this->db->beginTransaction();
		try {
			foreach ($ids as $index => $id) {
				$query = $this->db->getQueryBuilder();
				$query->update('bestatter_workflows')
					->set('sort_order', $query->createNamedParameter(($index + 1) * 10))
					->set('updated_at', $query->createNamedParameter(date('c')))
					->where($query->expr()->eq('id', $query->createNamedParameter($id)))
					->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		return $this->list();
	}

	public function delete(int $id): void {
		$this->getRow($id);
		$runs = $this->db->getQueryBuilder();
		$runs->delete('bestatter_workflow_runs')->where($runs->expr()->eq('workflow_id', $runs->createNamedParameter($id)))->executeStatement();
		$query = $this->db->getQueryBuilder();
		$query->delete('bestatter_workflows')->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeStatement();
	}

	public function available(int $recordId): array {
		$source = $this->records->get($recordId);
		$runs = $this->runs($recordId);
		$scheduleOptions = (int)$source['caseId'] > 0 ? $this->documents->funeralEventOptions((int)$source['caseId']) : [];
		$linkedScheduleId = (int)($source['data']['sourceScheduleId'] ?? $source['data']['workflow']['sourceScheduleId'] ?? 0);
		$result = [];
		foreach ($this->list(true) as $workflow) {
			if ($workflow['triggerEvent'] !== 'MANUAL') continue;
			if (!$this->matches($workflow, $source)) {
				continue;
			}
			$actions = array_map(function (array $action) use ($runs, $workflow, $scheduleOptions, $linkedScheduleId, $source): array {
				$runKey = $workflow['id'] . ':' . $action['key'];
				$run = $runs[$runKey] ?? null;
				$outputMissing = $run !== null
					&& $run['status'] === 'COMPLETED'
					&& in_array($action['type'], ['DOCUMENT', 'DOCUMENT_BUNDLE'], true)
					&& !$this->documentOutputsComplete($action, $run, (int)$source['caseId'], $source);
				$staleRunning = $run !== null
					&& $run['status'] === 'RUNNING'
					&& in_array($action['type'], ['DOCUMENT', 'DOCUMENT_BUNDLE'], true)
					&& $this->isStaleRunningDocumentRun($run);
				$action['executed'] = $run !== null && (($run['status'] === 'RUNNING' && !$staleRunning) || ($run['status'] === 'COMPLETED' && !$outputMissing));
				$action['retryable'] = $run !== null && (in_array($run['status'], ['FAILED', 'SUPERSEDED'], true) || $outputMissing || $staleRunning);
				$action['outputMissing'] = $outputMissing || $staleRunning;
				$action['recoveryReason'] = $staleRunning ? 'STALE_RUNNING' : ($outputMissing ? 'OUTPUTS_MISSING' : '');
				$action['lastRun'] = $run;
				if (in_array($action['type'], ['DOCUMENT', 'DOCUMENT_BUNDLE'], true) && str_contains(strtoupper((string)$action['key']), 'KONDOLENZ')) {
					$availableIds = array_map(static fn(array $option): int => (int)$option['id'], $scheduleOptions);
					$selectedScheduleId = in_array($linkedScheduleId, $availableIds, true) ? $linkedScheduleId : (count($scheduleOptions) === 1 ? (int)$scheduleOptions[0]['id'] : 0);
					$action['scheduleOptions'] = $scheduleOptions;
					$action['selectedScheduleId'] = $selectedScheduleId;
					$action['scheduleSelectionRequired'] = count($scheduleOptions) > 1 && $selectedScheduleId === 0;
				}
				return $action;
			}, $workflow['actions']);
			$result[] = array_replace($workflow, ['actions' => $actions]);
		}
		return $result;
	}

	/** Runs the configured one-time consequences of a confirmed appointment. */
	public function handleScheduleEvent(array $schedule): array {
		if (($schedule['type'] ?? '') !== 'schedule' || strtoupper((string)($schedule['status'] ?? '')) !== 'BESTAETIGT') {
			return ['executed' => 0, 'skipped' => 0, 'errors' => []];
		}
		$result = ['executed' => 0, 'skipped' => 0, 'errors' => []];
		$runs = $this->runs((int)$schedule['id']);
		foreach ($this->list(true) as $workflow) {
			if ($workflow['triggerType'] !== 'SCHEDULE' || $workflow['triggerEvent'] !== 'CONFIRMED' || !$this->matches($workflow, $schedule)) continue;
			foreach ($workflow['actions'] as $action) {
				$runKey = $workflow['id'] . ':' . $action['key'];
				if (!$action['repeatable'] && isset($runs[$runKey]) && in_array($runs[$runKey]['status'], ['RUNNING', 'COMPLETED'], true)) { $result['skipped']++; continue; }
				try {
					$this->execute((int)$schedule['id'], (int)$workflow['id'], (string)$action['key']);
					$result['executed']++;
				} catch (\Throwable $error) {
					$result['errors'][] = ['workflow' => $workflow['key'], 'action' => $action['key'], 'message' => $error->getMessage()];
				}
			}
		}
		return $result;
	}

	/** Reconciles confirmed calendar entries after a remote Nextcloud sync. */
	public function handlePendingScheduleEvents(): array {
		$total = ['appointments' => 0, 'executed' => 0, 'skipped' => 0, 'errors' => []];
		foreach ($this->records->list('schedule') as $schedule) {
			if (strtoupper((string)$schedule['status']) !== 'BESTAETIGT') continue;
			$total['appointments']++;
			$run = $this->handleScheduleEvent($schedule);
			$total['executed'] += $run['executed'];
			$total['skipped'] += $run['skipped'];
			$total['errors'] = array_merge($total['errors'], $run['errors']);
		}
		return $total;
	}

	/** Reactivates manual task consequences when their authoritative appointment changes. */
	public function invalidateScheduleDependents(array $before, array $after): array {
		if (($after['type'] ?? '') !== 'schedule' || !$this->scheduleContentChanged($before, $after)) return ['tasks' => 0, 'runs' => 0];
		$scheduleId = (int)$after['id']; $caseId = (int)$after['caseId']; $taskCount = 0; $runCount = 0;
		foreach ($this->records->list('task', $caseId) as $task) {
			$linkedScheduleId = (int)($task['data']['sourceScheduleId'] ?? $task['data']['workflow']['sourceScheduleId'] ?? 0);
			if ($linkedScheduleId !== $scheduleId) continue;
			$taskCount++;
			foreach (['COMPLETED', 'FAILED'] as $status) {
				$query = $this->db->getQueryBuilder();
				$runCount += $query->update('bestatter_workflow_runs')
					->set('run_status', $query->createNamedParameter('SUPERSEDED'))
					->set('result_json', $query->createNamedParameter(json_encode(['reason' => 'SOURCE_SCHEDULE_CHANGED', 'scheduleId' => $scheduleId], JSON_THROW_ON_ERROR)))
					->set('updated_at', $query->createNamedParameter(date('c')))
					->where($query->expr()->eq('source_record_id', $query->createNamedParameter((int)$task['id'])))
					->andWhere($query->expr()->eq('run_status', $query->createNamedParameter($status)))
					->executeStatement();
			}
		}
		if ($runCount > 0) $this->audit->log($caseId, 'SCHEDULE', $scheduleId, 'DEPENDENT_ACTIONS_REACTIVATED', $this->scheduleSnapshot($before), $this->scheduleSnapshot($after) + ['reactivatedRuns' => $runCount]);
		return ['tasks' => $taskCount, 'runs' => $runCount];
	}

	private function scheduleContentChanged(array $before, array $after): bool {
		$fields = static fn(array $record): array => [
			'date' => (string)($record['date'] ?? ''), 'status' => (string)($record['status'] ?? ''), 'title' => (string)($record['title'] ?? ''),
			'durationMinutes' => (int)($record['data']['durationMinutes'] ?? 60), 'location' => (string)($record['data']['location'] ?? ''),
			'appointmentCategory' => (string)($record['data']['appointmentCategory'] ?? ''), 'externalContactIds' => array_values((array)($record['data']['externalContactIds'] ?? [])),
			'externalParticipantsAdditional' => (string)($record['data']['externalParticipantsAdditional'] ?? ''),
		];
		return $fields($before) !== $fields($after);
	}

	public function execute(int $recordId, int $workflowId, string $actionKey, string $input = '{}'): array {
		$source = $this->records->get($recordId);
		$workflow = $this->get($workflowId);
		if (!$this->matches($workflow, $source)) {
			throw new \InvalidArgumentException('Der Workflow ist für diesen Ausgangseintrag nicht freigegeben.');
		}
		$action = null;
		foreach ($workflow['actions'] as $candidate) {
			if ($candidate['key'] === $actionKey) {
				$action = $candidate;
				break;
			}
		}
		if ($action === null) {
			throw new \InvalidArgumentException('Die Workflow-Aktion wurde nicht gefunden.');
		}
		$runKey = $workflowId . ':' . $actionKey;
		$existingRun = $this->runs($recordId)[$runKey] ?? null;
		$recoverableStatus = null;
		if (
			!$action['repeatable']
			&& $existingRun !== null
			&& $existingRun['status'] === 'COMPLETED'
			&& in_array($action['type'], ['DOCUMENT', 'DOCUMENT_BUNDLE'], true)
			&& !$this->documentOutputsComplete($action, $existingRun, (int)$source['caseId'], $source)
		) {
			$recoverableStatus = 'COMPLETED';
		} elseif (
			!$action['repeatable']
			&& $existingRun !== null
			&& $existingRun['status'] === 'RUNNING'
			&& in_array($action['type'], ['DOCUMENT', 'DOCUMENT_BUNDLE'], true)
			&& $this->isStaleRunningDocumentRun($existingRun)
		) {
			$recoverableStatus = 'RUNNING';
		}
		if ($recoverableStatus !== null) {
			$this->supersedeIncompleteDocumentRun((int)$existingRun['id'], $recoverableStatus);
			$existingRun['status'] = 'SUPERSEDED';
		}
		if (!$action['repeatable'] && $existingRun !== null && in_array($existingRun['status'], ['RUNNING', 'COMPLETED'], true)) {
			throw new \InvalidArgumentException('Diese Folgeaktion wurde für den Ausgangseintrag bereits ausgeführt oder begonnen.');
		}

		$case = (int)$source['caseId'] > 0 ? $this->cases->getCase((int)$source['caseId']) : null;
		$inputData = $this->decodeInput($input);
		$runId = $this->claimExecution($workflowId, $recordId, $actionKey, (bool)$action['repeatable']);
		try {
			$context = ['source' => $source, 'case' => $case];
			$date = $this->actionDate($source, (int)$action['dueOffsetDays']);
			$workflowMeta = ['workflowId' => $workflowId, 'actionKey' => $actionKey, 'parentRecordId' => $recordId];
			if ($source['type'] === 'schedule') $workflowMeta += ['sourceScheduleId' => $recordId, 'sourceScheduleUid' => $source['data']['nextcloud']['uid'] ?? ''];
			$result = match ($action['type']) {
				'TASK' => $this->groupware->createTask(
					$this->expand($action['title'], $context),
					$date,
					'OFFEN',
					json_encode([
						'description' => $this->expand($action['description'], $context),
						'priority' => $action['priority'],
						'assigneeUid' => $source['data']['assigneeUid'] ?? $source['data']['assigneeUids'][0] ?? $source['assigneeUid'] ?? '',
						'workflow' => $workflowMeta,
						'sourceScheduleId' => $workflowMeta['sourceScheduleId'] ?? 0,
					], JSON_THROW_ON_ERROR),
					(int)$source['caseId'],
				),
				'REMINDER' => $this->groupware->createTask(
					$this->expand($action['title'], $context),
					$date,
					'OFFEN',
					json_encode([
						'description' => $this->expand($action['description'], $context),
						'priority' => $action['priority'],
						'assigneeUid' => $source['data']['assigneeUid'] ?? $source['data']['assigneeUids'][0] ?? $source['assigneeUid'] ?? '',
						'kind' => 'REMINDER',
						'workflow' => $workflowMeta,
						'sourceScheduleId' => $workflowMeta['sourceScheduleId'] ?? 0,
					], JSON_THROW_ON_ERROR),
					(int)$source['caseId'],
				),
				'SCHEDULE' => $this->groupware->createEvent(
					$this->expand($action['title'], $context),
					$date,
					'OFFEN',
					json_encode([
						'description' => $this->expand($action['description'], $context),
						'priority' => $action['priority'],
						'workflow' => ['workflowId' => $workflowId, 'actionKey' => $actionKey, 'parentRecordId' => $recordId],
					], JSON_THROW_ON_ERROR),
					(int)$source['caseId'],
				),
				'DOCUMENT' => $this->executeDocument($case, $action, $recordId, $source, $inputData),
				'DOCUMENT_BUNDLE' => $this->executeDocumentBundle($case, $action, $recordId, $source, $inputData),
				'EMAIL_DRAFT' => $this->executeEmailDraft($case, $action, $recordId, $context, $inputData),
				'CONTACT_ACTIVITY' => $this->executeContactActivity($case, $action, $recordId, $context, $inputData),
				'CASE_STATUS' => ['case' => $this->applyCaseStatus($case, $action['caseStatusAfter'])],
				default => throw new \InvalidArgumentException('Nicht unterstützte Workflow-Aktion.'),
			};

			if ($action['sourceStatusAfter'] !== '') {
				$updated = $this->records->update(
					$recordId,
					$source['title'],
					$source['date'],
					$action['sourceStatusAfter'],
					json_encode($source['data'], JSON_THROW_ON_ERROR),
					(int)$source['caseId'],
				);
				$this->groupware->update($updated);
			}

			if ($action['caseStatusAfter'] !== '' && $action['type'] !== 'CASE_STATUS') {
				$result['case'] = $this->applyCaseStatus($case, $action['caseStatusAfter']);
			}

			$this->completeExecution($runId, (int)$source['caseId'], $workflow, $action, $recordId, $result);
			return ['workflow' => $workflow['name'], 'action' => $action, 'result' => $result];
		} catch (\Throwable $error) {
			$this->failExecution($runId, (int)$source['caseId'], $workflow, $action, $recordId, $error);
			throw $error;
		}
	}

	private function executeEmailDraft(?array $case, array $action, int $recordId, array $context, array $input): array {
		if ($case === null) throw new \InvalidArgumentException('E-Mail-Entwürfe können nur für fallbezogene Aufgaben angelegt werden.');
		$subject = trim((string)($input['subject'] ?? $this->expand($action['title'], $context)));
		$body = trim((string)($input['body'] ?? $this->expand($action['description'], $context)));
		$record = $this->records->create('document', $subject, date('c'), 'ENTWURF', json_encode([
			'kind' => 'EMAIL_DRAFT', 'description' => $body,
			'recipient' => trim((string)($input['recipient'] ?? '')),
			'recipientCategory' => $action['recipientCategory'], 'sendStatus' => 'DRAFT_ONLY',
			'workflowSourceRecordId' => $recordId,
		], JSON_THROW_ON_ERROR), (int)$case['id']);
		return ['record' => $record, 'delivery' => 'DRAFT_ONLY'];
	}

	private function executeContactActivity(?array $case, array $action, int $recordId, array $context, array $input): array {
		if ($case === null) throw new \InvalidArgumentException('Kontaktaktivitäten können nur für fallbezogene Aufgaben dokumentiert werden.');
		$record = $this->records->create('activity', trim((string)($input['title'] ?? $this->expand($action['title'], $context))), date('c'), 'DOKUMENTIERT', json_encode([
			'kind' => 'CONTACT_ACTIVITY',
			'description' => trim((string)($input['description'] ?? $this->expand($action['description'], $context))),
			'contact' => trim((string)($input['contact'] ?? '')), 'channel' => trim((string)($input['channel'] ?? 'Telefon')),
			'workflowSourceRecordId' => $recordId,
		], JSON_THROW_ON_ERROR), (int)$case['id']);
		return ['record' => $record];
	}

	private function applyCaseStatus(?array $case, string $status): array {
		if ($case === null) throw new \InvalidArgumentException('Der Fallstatus kann nur für fallbezogene Aufgaben geändert werden.');
		$masterData = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$masterData['status'] = $status;
		return $this->cases->updateMasterData((int)$case['id'], $masterData);
	}

	private function decodeInput(string $input): array {
		if (trim($input) === '') return [];
		try {
			$data = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			throw new \InvalidArgumentException('Ungültige JSON-Eingaben für die Workflow-Aktion.', 0, $error);
		}
		if (!is_array($data)) throw new \InvalidArgumentException('Ungültige Eingaben für die Workflow-Aktion.');
		return $data;
	}

	private function executeDocument(?array $case, array $action, int $recordId, array $source, array $input = []): array {
		if ($case === null) {
			throw new \InvalidArgumentException('Dokumente können nur für fallbezogene Vorgänge erzeugt werden.');
		}
		[$case, $schedule] = $this->caseWithScheduleContext($case, $source, (int)($input['scheduleId'] ?? 0));
		$file = $this->documents->generateTemplate($case, $action['templateKey']);
		try {
			$record = $this->saveGeneratedDocument($case, $schedule, $file, $recordId);
			return ['file' => $file, 'record' => $record];
		} catch (\Throwable $error) {
			$this->documents->discardGeneratedOutput($file);
			throw $error;
		}
	}

	private function executeDocumentBundle(?array $case, array $action, int $recordId, array $source, array $input = []): array {
		$templateKeys = array_values(array_unique(array_filter((array)($action['templateKeys'] ?? []))));
		if ($templateKeys === []) throw new \InvalidArgumentException('Das Dokumentpaket enthält keine Vorlagen.');
		if ($case === null) throw new \InvalidArgumentException('Dokumente können nur für fallbezogene Vorgänge erzeugt werden.');
		[$case, $schedule] = $this->caseWithScheduleContext($case, $source, (int)($input['scheduleId'] ?? 0));
		$files = [];
		try {
			foreach ($templateKeys as $templateKey) {
				$files[] = $this->documents->generateTemplate($case, $templateKey);
			}
		} catch (\Throwable $error) {
			foreach ($files as $file) $this->documents->discardGeneratedOutput($file);
			throw new \InvalidArgumentException('Das Dokumentpaket konnte nicht vollständig erzeugt werden: ' . $error->getMessage(), 0, $error);
		}

		$this->db->beginTransaction();
		try {
			$result = [];
			foreach ($files as $file) {
				$result[] = ['file' => $file, 'record' => $this->saveGeneratedDocument($case, $schedule, $file, $recordId)];
			}
			$this->db->commit();
			return ['bundle' => $action['key'], 'documents' => $result];
		} catch (\Throwable $error) {
			$this->safeRollback();
			foreach ($files as $file) $this->documents->discardGeneratedOutput($file);
			throw new \InvalidArgumentException('Das Dokumentpaket konnte nicht vollständig gespeichert werden: ' . $error->getMessage(), 0, $error);
		}
	}

	private function saveGeneratedDocument(array $case, ?array $schedule, array $file, int $recordId): array {
		return $this->records->saveDocument((int)$case['id'], (string)$file['title'], 'ERSTELLT', $file + [
			'workflowSourceRecordId' => $recordId,
			'sourceScheduleId' => (int)($schedule['id'] ?? 0),
			'sourceScheduleSnapshot' => $schedule === null ? null : $this->scheduleSnapshot($schedule),
		]);
	}

	private function actionDate(array $source, int $offsetDays): string {
		$base = $source['type'] === 'schedule' && trim((string)$source['date']) !== ''
			? new \DateTimeImmutable((string)$source['date'], new \DateTimeZone('Europe/Berlin'))
			: new \DateTimeImmutable('today 09:00', new \DateTimeZone('Europe/Berlin'));
		return $base->modify(sprintf('%+d days', $offsetDays))->setTime(9, 0)->format('Y-m-d\TH:i');
	}

	private function caseWithScheduleContext(array $case, array $source, int $selectedScheduleId = 0): array {
		$schedule = $source['type'] === 'schedule' ? $source : null;
		if ($schedule === null) {
			$linkedScheduleId = (int)($source['data']['sourceScheduleId'] ?? $source['data']['workflow']['sourceScheduleId'] ?? 0);
			if ($linkedScheduleId > 0 && $selectedScheduleId > 0 && $selectedScheduleId !== $linkedScheduleId) throw new \InvalidArgumentException('Diese Aufgabe ist bereits eindeutig mit einer anderen Trauerfeier verknüpft.');
			$options = $this->documents->funeralEventOptions((int)$case['id']);
			$availableIds = array_map(static fn(array $option): int => (int)$option['id'], $options);
			if ($linkedScheduleId <= 0 && $selectedScheduleId > 0 && !in_array($selectedScheduleId, $availableIds, true)) throw new \InvalidArgumentException('Die ausgewählte Trauerfeier gehört nicht zu diesem Fall oder ist nicht bestätigt.');
			$scheduleId = $linkedScheduleId > 0 ? $linkedScheduleId : $selectedScheduleId;
			if ($scheduleId <= 0) {
				if (count($options) > 1) throw new \InvalidArgumentException('Bitte wählen Sie aus, für welche Trauerfeier die Kondolenzlisten erzeugt werden sollen.');
				if (count($options) === 1) $scheduleId = (int)$options[0]['id'];
			}
			if ($scheduleId > 0) {
				$candidate = $this->records->get($scheduleId);
				if ($candidate['type'] === 'schedule' && (int)$candidate['caseId'] === (int)$case['id'] && in_array(strtoupper((string)$candidate['status']), ['BESTAETIGT', 'ERLEDIGT'], true)) $schedule = $candidate;
			}
		}
		if ($schedule === null) return [$case, null];
		$date = new \DateTimeImmutable((string)$schedule['date'], new \DateTimeZone('Europe/Berlin'));
		$duration = max(15, (int)($schedule['data']['durationMinutes'] ?? 60));
		$masterData = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$masterData = array_replace($masterData, [
			'funeral_event_schedule_id' => (string)$schedule['id'],
			'funeral_event_date' => $date->format('d.m.Y'),
			'funeral_event_date_iso' => $date->format('Y-m-d'),
			'funeral_event_time' => $date->format('H:i'),
			'funeral_event_end_time' => $date->modify('+' . $duration . ' minutes')->format('H:i'),
			'funeral_event_location' => (string)($schedule['data']['location'] ?? ''),
			'funeral_event_category' => (string)($schedule['data']['appointmentCategory'] ?? $schedule['title']),
			'funeral_event_external_participants' => (string)($schedule['data']['externalParticipants'] ?? ''),
		]);
		$case['masterData'] = $masterData;
		return [$case, $schedule];
	}

	private function scheduleSnapshot(array $schedule): array {
		return [
			'id' => (int)$schedule['id'], 'uid' => (string)($schedule['data']['nextcloud']['uid'] ?? ''),
			'title' => (string)$schedule['title'], 'date' => (string)$schedule['date'], 'status' => (string)$schedule['status'],
			'location' => (string)($schedule['data']['location'] ?? ''), 'presetKey' => (string)($schedule['data']['schedulePresetKey'] ?? ''),
		];
	}

	private function insert(array $data): array {
		$query = $this->db->getQueryBuilder();
		$now = date('c');
		$query->insert('bestatter_workflows')->values([
			'workflow_key' => $query->createNamedParameter($data['key']),
			'name' => $query->createNamedParameter($data['name']),
			'description' => $query->createNamedParameter($data['description']),
			'task_title_pattern' => $query->createNamedParameter($data['taskTitlePattern'] ?: null),
			'checklist_key' => $query->createNamedParameter($data['checklistKey'] ?: null),
			'required_status' => $query->createNamedParameter($data['requiredStatus']),
			'trigger_type' => $query->createNamedParameter($data['triggerType']),
			'trigger_event' => $query->createNamedParameter($data['triggerEvent']),
			'trigger_config' => $query->createNamedParameter(json_encode($data['triggerConfig'], JSON_THROW_ON_ERROR)),
			'actions_json' => $query->createNamedParameter(json_encode($data['actions'], JSON_THROW_ON_ERROR)),
			'active' => $query->createNamedParameter((int)$data['active']),
			'sort_order' => $query->createNamedParameter($data['sortOrder']),
			'created_at' => $query->createNamedParameter($now),
			'updated_at' => $query->createNamedParameter($now),
		])->executeStatement();
		return $this->get((int)$this->db->lastInsertId('bestatter_workflows'));
	}

	private function get(int $id): array {
		return $this->map($this->getRow($id));
	}

	private function getRow(int $id): array {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('*')->from('bestatter_workflows')
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->executeQuery()->fetchAssociative();
		if ($row === false) {
			throw new \InvalidArgumentException('Workflow wurde nicht gefunden.');
		}
		return $row;
	}

	private function map(array $row): array {
		$actions = json_decode((string)$row['actions_json'], true, 512, JSON_THROW_ON_ERROR);
		$actions = array_map(static fn(array $action): array => $action + [
			'caseStatusAfter' => '',
			'recipientCategory' => '',
			'templateKeys' => [],
		], $actions);
		return [
			'id' => (int)$row['id'],
			'key' => $row['workflow_key'],
			'name' => $row['name'],
			'description' => $row['description'] ?? '',
			'taskTitlePattern' => $row['task_title_pattern'] ?? '',
			'checklistKey' => $row['checklist_key'] ?? '',
			'requiredStatus' => $row['required_status'],
			'triggerType' => strtoupper((string)($row['trigger_type'] ?? 'TASK')),
			'triggerEvent' => strtoupper((string)($row['trigger_event'] ?? 'MANUAL')),
			'triggerConfig' => json_decode((string)($row['trigger_config'] ?? '{}'), true) ?: [],
			'actions' => $actions,
			'active' => (bool)$row['active'],
			'sortOrder' => (int)$row['sort_order'],
		];
	}

	private function normalizeSchedulePreset(array $preset, string $source): array {
		$key = strtoupper(trim((string)($preset['key'] ?? '')));
		$label = trim((string)($preset['label'] ?? $preset['title'] ?? ''));
		if (!preg_match('/^[A-Z0-9_-]+$/', $key) || $label === '') {
			throw new \InvalidArgumentException('Eine Terminvorlage enthält einen ungültigen Schlüssel oder keine Bezeichnung.');
		}
		$kind = strtoupper((string)($preset['scheduleKind'] ?? 'EXTERNAL_APPOINTMENT'));
		if (!in_array($kind, ['INTERNAL_ACTIVITY', 'EXTERNAL_APPOINTMENT'], true)) $kind = 'EXTERNAL_APPOINTMENT';
		$priority = strtoupper((string)($preset['priority'] ?? 'NORMAL'));
		if (!in_array($priority, ['HOCH', 'NORMAL', 'NIEDRIG'], true)) $priority = 'NORMAL';
		return [
			'key' => $key,
			'label' => $label,
			'title' => trim((string)($preset['title'] ?? $label)),
			'description' => trim((string)($preset['description'] ?? '')),
			'scheduleKind' => $kind,
			'appointmentCategory' => trim((string)($preset['appointmentCategory'] ?? ($kind === 'EXTERNAL_APPOINTMENT' ? $label : ''))),
			'dueOffsetDays' => (int)($preset['dueOffsetDays'] ?? 0),
			'durationMinutes' => max(15, (int)($preset['durationMinutes'] ?? 60)),
			'priority' => $priority,
			'source' => $source,
			'workflowName' => trim((string)($preset['workflowName'] ?? '')),
		];
	}

	private function validate(array $data): array {
		$name = trim((string)($data['name'] ?? ''));
		if ($name === '') {
			throw new \InvalidArgumentException('Der Workflow-Name ist erforderlich.');
		}
		$triggerType = strtoupper((string)($data['triggerType'] ?? 'TASK'));
		$triggerEvent = strtoupper((string)($data['triggerEvent'] ?? 'MANUAL'));
		if (!in_array($triggerType, self::TRIGGER_TYPES, true) || !in_array($triggerEvent, self::TRIGGER_EVENTS, true)) {
			throw new \InvalidArgumentException('Ungültiger Workflow-Auslöser.');
		}
		if ($triggerEvent === 'CONFIRMED' && $triggerType !== 'SCHEDULE') throw new \InvalidArgumentException('Bestätigungs-Automatik ist nur für Termine zulässig.');
		$triggerConfig = is_array($data['triggerConfig'] ?? null) ? $data['triggerConfig'] : [];
		$triggerConfig['schedulePresetKeys'] = array_values(array_unique(array_filter(array_map(
			static fn(mixed $value): string => strtoupper(trim((string)$value)), (array)($triggerConfig['schedulePresetKeys'] ?? [])
		))));
		$status = strtoupper((string)($data['requiredStatus'] ?? 'ANY'));
		if (!in_array($status, self::STATUSES, true)) {
			throw new \InvalidArgumentException('Ungültiger Aufgabenstatus im Workflow.');
		}
		$actions = [];
		foreach (array_values($data['actions'] ?? []) as $index => $action) {
			$type = strtoupper((string)($action['type'] ?? 'TASK'));
			$key = strtoupper(trim((string)($action['key'] ?? 'ACTION_' . ($index + 1))));
			if (!in_array($type, self::ACTION_TYPES, true) || !preg_match('/^[A-Z0-9_-]+$/', $key)) {
				throw new \InvalidArgumentException('Eine Workflow-Aktion enthält einen ungültigen Typ oder Schlüssel.');
			}
			$title = trim((string)($action['title'] ?? ''));
			$templateKey = strtoupper(trim((string)($action['templateKey'] ?? '')));
			$templateKeys = array_values(array_unique(array_filter(array_map(static fn(mixed $value): string => strtoupper(trim((string)$value)), (array)($action['templateKeys'] ?? [])))));
			if (!in_array($type, ['DOCUMENT', 'DOCUMENT_BUNDLE', 'CASE_STATUS'], true) && $title === '') {
				throw new \InvalidArgumentException('Für diese Workflow-Aktion ist eine Bezeichnung erforderlich.');
			}
			if ($type === 'DOCUMENT' && $templateKey === '') {
				throw new \InvalidArgumentException('Für eine Dokumentaktion muss eine Vorlage gewählt werden.');
			}
			if ($type === 'DOCUMENT_BUNDLE' && count($templateKeys) < 2) throw new \InvalidArgumentException('Ein Dokumentpaket benötigt mindestens zwei Vorlagen.');
			$sourceStatus = strtoupper((string)($action['sourceStatusAfter'] ?? ''));
			if ($sourceStatus !== '' && !in_array($sourceStatus, array_slice(self::STATUSES, 1), true)) {
				throw new \InvalidArgumentException('Ungültiger Folgestatus der Ausgangsaufgabe.');
			}
			$caseStatus = strtoupper((string)($action['caseStatusAfter'] ?? ''));
			if ($caseStatus !== '' && !in_array($caseStatus, self::CASE_STATUSES, true)) {
				throw new \InvalidArgumentException('Ungültiger automatischer Fallstatus.');
			}
			if ($type === 'CASE_STATUS' && $caseStatus === '') {
				throw new \InvalidArgumentException('Für den Fallstatuswechsel muss ein Zielstatus gewählt werden.');
			}
			$label = trim((string)($action['label'] ?? $title ?: $templateKey));
			if ($label === '') {
				throw new \InvalidArgumentException('Jede Workflow-Aktion benötigt eine Beschriftung.');
			}
			$actions[] = [
				'key' => $key,
				'type' => $type,
				'label' => $label,
				'title' => $title,
				'description' => trim((string)($action['description'] ?? '')),
				'dueOffsetDays' => (int)($action['dueOffsetDays'] ?? 0),
				'priority' => in_array(strtoupper((string)($action['priority'] ?? 'NORMAL')), ['HOCH', 'NORMAL', 'NIEDRIG'], true) ? strtoupper((string)($action['priority'] ?? 'NORMAL')) : 'NORMAL',
				'templateKey' => $templateKey,
				'templateKeys' => $templateKeys,
				'sourceStatusAfter' => $sourceStatus,
				'caseStatusAfter' => $caseStatus,
				'recipientCategory' => trim((string)($action['recipientCategory'] ?? '')),
				'repeatable' => (bool)($action['repeatable'] ?? false),
			];
		}
		if ($actions === []) {
			throw new \InvalidArgumentException('Ein Workflow benötigt mindestens eine Folgeaktion.');
		}
		return [
			'key' => strtoupper(trim((string)($data['key'] ?? ''))),
			'name' => $name,
			'description' => trim((string)($data['description'] ?? '')),
			'taskTitlePattern' => trim((string)($data['taskTitlePattern'] ?? '')),
			'checklistKey' => strtoupper(trim((string)($data['checklistKey'] ?? ''))),
			'requiredStatus' => $status,
			'triggerType' => $triggerType,
			'triggerEvent' => $triggerEvent,
			'triggerConfig' => $triggerConfig,
			'actions' => $actions,
			'active' => (bool)($data['active'] ?? true),
			'sortOrder' => (int)($data['sortOrder'] ?? 0),
		];
	}

	private function decode(string $workflow): array {
		$data = json_decode($workflow, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($data)) {
			throw new \InvalidArgumentException('Ungültige Workflow-Daten.');
		}
		return $data;
	}

	private function matches(array $workflow, array $source): bool {
		if (!$workflow['active']) {
			return false;
		}
		if ($workflow['triggerType'] !== strtoupper((string)$source['type'])) return false;
		if ($workflow['requiredStatus'] !== 'ANY' && $workflow['requiredStatus'] !== $source['status']) {
			return false;
		}
		if ($workflow['triggerType'] === 'SCHEDULE') {
			$keys = (array)($workflow['triggerConfig']['schedulePresetKeys'] ?? []);
			return $keys === [] || in_array(strtoupper((string)($source['data']['schedulePresetKey'] ?? '')), $keys, true);
		}
		if ($workflow['taskTitlePattern'] !== '' && stripos((string)$source['title'], $workflow['taskTitlePattern']) === false) {
			return false;
		}
		$sourceChecklist = strtoupper((string)($source['data']['checklist'] ?? ''));
		return $workflow['checklistKey'] === '' || $workflow['checklistKey'] === $sourceChecklist;
	}

	private function runs(int $recordId): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_workflow_runs')
			->where($query->expr()->eq('source_record_id', $query->createNamedParameter($recordId)))
			->orderBy('created_at', 'DESC')->executeQuery()->fetchAllAssociative();
		$result = [];
		foreach ($rows as $row) {
			$key = $row['workflow_id'] . ':' . $row['action_key'];
			$result[$key] ??= [
				'id' => (int)$row['id'],
				'at' => $row['created_at'],
				'updatedAt' => $row['updated_at'] ?? $row['created_at'],
				'by' => $row['executed_by'],
				'status' => $row['run_status'] ?? 'COMPLETED',
				'result' => json_decode((string)($row['result_json'] ?? '{}'), true) ?: [],
			];
		}
		return $result;
	}

	private function documentOutputsComplete(array $action, array $run, int $caseId, array $source): bool {
		$result = is_array($run['result'] ?? null) ? $run['result'] : [];
		$entries = $action['type'] === 'DOCUMENT_BUNDLE'
			? (is_array($result['documents'] ?? null) ? $result['documents'] : [])
			: ($result !== [] ? [$result] : []);
		$expectedKeys = $action['type'] === 'DOCUMENT_BUNDLE'
			? array_values(array_unique(array_filter(array_map('strval', (array)($action['templateKeys'] ?? [])))))
			: array_values(array_filter([(string)($action['templateKey'] ?? '')]));
		if ($entries === [] || $expectedKeys === []) return false;

		$userId = trim((string)($run['by'] ?? ''));
		if ($userId === '') return false;
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable) {
			return false;
		}

		$validKeys = [];
		$templateFingerprints = [];
		$isCondolence = str_contains(strtoupper((string)($action['key'] ?? '')), 'KONDOLENZ');
		$expectedScheduleId = $source['type'] === 'schedule'
			? (int)($source['id'] ?? 0)
			: (int)($source['data']['sourceScheduleId'] ?? $source['data']['workflow']['sourceScheduleId'] ?? 0);
		foreach ($entries as $entry) {
			if (!is_array($entry)) continue;
			$file = is_array($entry['file'] ?? null) ? $entry['file'] : $entry;
			$record = is_array($entry['record'] ?? null) ? $entry['record'] : [];
			$templateKey = strtoupper(trim((string)($file['templateKey'] ?? '')));
			if ($templateKey === '' || !in_array($templateKey, array_map('strtoupper', $expectedKeys), true)) continue;
			$storedTemplateHash = trim((string)($file['templateSource']['sha256'] ?? ''));
			try {
				$templateFingerprints[$templateKey] ??= $this->documents->templateFingerprint($templateKey, $userId);
				$currentTemplateHash = trim((string)($templateFingerprints[$templateKey]['sha256'] ?? ''));
			} catch (\Throwable) {
				continue;
			}
			if ($storedTemplateHash === '' || $currentTemplateHash === '' || !hash_equals($currentTemplateHash, $storedTemplateHash)) continue;
			if ($isCondolence && (
				$expectedScheduleId <= 0
				|| (int)($file['sourceScheduleId'] ?? 0) !== $expectedScheduleId
				|| (string)($file['documentContextKey'] ?? '') !== 'schedule:' . $expectedScheduleId
			)) continue;
			$recordId = (int)($record['id'] ?? 0);
			if ($recordId <= 0 || !$this->documentRecordExists($recordId, $caseId)) continue;
			$fileIds = array_values(array_unique(array_filter([
				(int)($file['fileId'] ?? 0),
				(int)($file['pdf']['fileId'] ?? 0),
			])));
			$fileExists = false;
			foreach ($fileIds as $fileId) {
				try {
					if ($userFolder->getById($fileId) !== []) { $fileExists = true; break; }
				} catch (\Throwable) {
					// Missing/inaccessible nodes make the durable workflow output incomplete.
				}
			}
			if ($fileExists) $validKeys[$templateKey] = true;
		}
		foreach ($expectedKeys as $expectedKey) {
			if (!isset($validKeys[strtoupper($expectedKey)])) return false;
		}
		return true;
	}

	private function documentRecordExists(int $recordId, int $caseId): bool {
		$query = $this->db->getQueryBuilder();
		return (bool)$query->select('id')->from('bestatter_records')
			->where($query->expr()->eq('id', $query->createNamedParameter($recordId)))
			->andWhere($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->andWhere($query->expr()->eq('record_type', $query->createNamedParameter('document')))
			->setMaxResults(1)->executeQuery()->fetchOne();
	}

	private function isStaleRunningDocumentRun(array $run): bool {
		try {
			$updatedAt = new \DateTimeImmutable((string)($run['updatedAt'] ?? $run['at'] ?? ''));
		} catch (\Throwable) {
			return true;
		}
		return $updatedAt < new \DateTimeImmutable('-30 minutes');
	}

	private function supersedeIncompleteDocumentRun(int $runId, string $expectedStatus): void {
		$query = $this->db->getQueryBuilder();
		$affected = $query->update('bestatter_workflow_runs')
			->set('run_status', $query->createNamedParameter('SUPERSEDED'))
			->set('result_json', $query->createNamedParameter(json_encode([
				'reason' => $expectedStatus === 'RUNNING' ? 'STALE_DOCUMENT_RUN' : 'DOCUMENT_OUTPUTS_MISSING',
				'message' => $expectedStatus === 'RUNNING'
					? 'Der frühere Dokumentlauf wurde nicht abgeschlossen und ist veraltet.'
					: 'Der frühere Lauf enthielt nicht mehr alle Dokumentdatensätze und Dateien.',
			], JSON_THROW_ON_ERROR)))
			->set('updated_at', $query->createNamedParameter(date('c')))
			->where($query->expr()->eq('id', $query->createNamedParameter($runId)))
			->andWhere($query->expr()->eq('run_status', $query->createNamedParameter($expectedStatus)))
			->executeStatement();
		if ($affected !== 1) throw new \InvalidArgumentException('Der Workflowstatus hat sich zwischenzeitlich geändert. Bitte die Aufgabe neu öffnen.');
	}

	private function claimExecution(int $workflowId, int $recordId, string $actionKey, bool $repeatable): int {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('Kein Nextcloud-Benutzer angemeldet.');
		}
		$executionKey = $repeatable
			? sprintf('repeat:%d:%d:%s:%s', $workflowId, $recordId, $actionKey, bin2hex(random_bytes(16)))
			: sprintf('once:%d:%d:%s', $workflowId, $recordId, $actionKey);
		$now = date('c');
		$this->db->beginTransaction();
		try {
			$query = $this->db->getQueryBuilder();
			$query->insert('bestatter_workflow_runs')->values([
				'workflow_id' => $query->createNamedParameter($workflowId),
				'source_record_id' => $query->createNamedParameter($recordId),
				'action_key' => $query->createNamedParameter($actionKey),
				'execution_key' => $query->createNamedParameter($executionKey),
				'run_status' => $query->createNamedParameter('RUNNING'),
				'result_json' => $query->createNamedParameter('{}'),
				'executed_by' => $query->createNamedParameter($user->getUID()),
				'created_at' => $query->createNamedParameter($now),
				'updated_at' => $query->createNamedParameter($now),
			])->executeStatement();
			$runId = (int)$this->db->lastInsertId('bestatter_workflow_runs');
			$this->db->commit();
			return $runId;
		} catch (\Throwable $error) {
			$this->safeRollback();
			if (!$repeatable) {
				$retryRunId = $this->restartExecutionClaim($executionKey, $user->getUID());
				if ($retryRunId > 0) return $retryRunId;
			}
			if (!$repeatable && $this->executionClaimExists($executionKey)) {
				throw new \InvalidArgumentException('Diese Folgeaktion wurde bereits begonnen. Bitte den vorhandenen Workflow-Lauf prüfen, bevor sie erneut ausgeführt wird.', 0, $error);
			}
			throw $error;
		}
	}

	private function restartExecutionClaim(string $executionKey, string $userId): int {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('id', 'run_status')->from('bestatter_workflow_runs')
			->where($query->expr()->eq('execution_key', $query->createNamedParameter($executionKey)))
			->setMaxResults(1)->executeQuery()->fetchAssociative();
		if ($row === false || !in_array((string)$row['run_status'], ['FAILED', 'SUPERSEDED'], true)) return 0;
		$update = $this->db->getQueryBuilder();
		$affected = $update->update('bestatter_workflow_runs')
			->set('run_status', $update->createNamedParameter('RUNNING'))
			->set('result_json', $update->createNamedParameter('{}'))
			->set('executed_by', $update->createNamedParameter($userId))
			->set('updated_at', $update->createNamedParameter(date('c')))
			->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'])))
			->andWhere($update->expr()->eq('run_status', $update->createNamedParameter((string)$row['run_status'])))
			->executeStatement();
		return $affected === 1 ? (int)$row['id'] : 0;
	}

	private function executionClaimExists(string $executionKey): bool {
		$query = $this->db->getQueryBuilder();
		return (bool)$query->select('id')->from('bestatter_workflow_runs')
			->where($query->expr()->eq('execution_key', $query->createNamedParameter($executionKey)))
			->setMaxResults(1)->executeQuery()->fetchOne();
	}

	private function completeExecution(int $runId, int $caseId, array $workflow, array $action, int $recordId, array $result): void {
		$this->db->beginTransaction();
		try {
			$this->updateRun($runId, 'COMPLETED', $result);
			$this->audit->log($caseId, 'WORKFLOW', $runId, 'EXECUTED', [
				'workflowId' => $workflow['id'], 'sourceRecordId' => $recordId, 'actionKey' => $action['key'],
			], [
				'workflow' => $workflow['name'], 'action' => $action['label'], 'result' => $result,
			]);
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->safeRollback();
			throw $error;
		}
	}

	private function failExecution(int $runId, int $caseId, array $workflow, array $action, int $recordId, \Throwable $cause): void {
		try {
			$this->db->beginTransaction();
			$error = ['message' => $cause->getMessage(), 'class' => $cause::class];
			$this->updateRun($runId, 'FAILED', ['error' => $error]);
			$this->audit->log($caseId, 'WORKFLOW', $runId, 'FAILED', [
				'workflowId' => $workflow['id'], 'sourceRecordId' => $recordId, 'actionKey' => $action['key'],
			], $error + ['workflow' => $workflow['name'], 'action' => $action['label']]);
			$this->db->commit();
		} catch (\Throwable) {
			$this->safeRollback();
			// The original workflow error remains authoritative. A RUNNING claim is
			// intentionally retained if bookkeeping fails, preventing unsafe replay.
		}
	}

	private function updateRun(int $runId, string $status, array $result): void {
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_workflow_runs')
			->set('run_status', $query->createNamedParameter($status))
			->set('result_json', $query->createNamedParameter(json_encode($result, JSON_THROW_ON_ERROR)))
			->set('updated_at', $query->createNamedParameter(date('c')))
			->where($query->expr()->eq('id', $query->createNamedParameter($runId)))
			->executeStatement();
	}

	private function safeRollback(): void {
		try {
			$this->db->rollBack();
		} catch (\Throwable) {
			// Preserve the original failure; the durable execution claim still
			// prevents an unsafe automatic replay.
		}
	}

	private function expand(string $value, array $context): string {
		$source = $context['source'];
		$case = $context['case'];
		return strtr($value, [
			'{{source.title}}' => (string)$source['title'],
			'{{case.number}}' => (string)($case['caseNumber'] ?? ''),
			'{{case.first_name}}' => (string)($case['firstName'] ?? ''),
			'{{case.last_name}}' => (string)($case['lastName'] ?? ''),
		]);
	}
}
