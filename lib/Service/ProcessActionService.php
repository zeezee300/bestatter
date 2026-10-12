<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * 0.67.0: Ausführung der Knöpfe von Ablaufschritten (Dokument, Dokumentpaket, Termin, Abmeldungen,
 * E-Mail-Entwurf, Folgeaufgabe, Wiedervorlage, Kontakt, Fallstatus) mit Ausführungsprotokoll
 * (bestatter_action_runs), Schutz vor Doppelausführung und Wiederanlauf fehlender Dokumente.
 * Ersetzt die frühere Workflow-Engine; Checklisten und klassische Workflows gibt es nicht mehr.
 */
class ProcessActionService {
	private const PRESETS = __DIR__ . '/../../resources/schedule-presets.json';
	private const ACTION_TYPES = ['TASK', 'REMINDER', 'SCHEDULE', 'DOCUMENT', 'DOCUMENT_BUNDLE', 'EMAIL_DRAFT', 'CONTACT_ACTIVITY', 'CASE_STATUS', 'DEREGISTRATIONS', 'OPEN_VIEW', 'SURCHARGE_CHECK', 'CASE_CLOSE', 'LABEL'];
	/**
	 * Knöpfe, die ausschließlich in der Oberfläche wirken (Navigation, Prüfdialog,
	 * Abschlussdialog) und deshalb serverseitig nie "ausgeführt" oder protokolliert werden.
	 */
	public const CLIENT_ACTION_TYPES = ['OPEN_VIEW', 'SURCHARGE_CHECK', 'CASE_CLOSE'];
	public const OPEN_VIEWS = ['overview', 'master', 'order', 'services', 'finances', 'deregistration', 'document', 'schedule', 'contact', 'task'];
	private const PRIORITIES = ['KRITISCH', 'HOCH', 'NORMAL', 'NIEDRIG'];
	private const STATUSES = ['ANY', 'OFFEN', 'IN_BEARBEITUNG', 'ERLEDIGT', 'ENTWURF', 'BESTAETIGT', 'GEAENDERT', 'ABGESAGT'];
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
		private DeregistrationService $deregistrations,
		private CaseStorageService $storage,
		private LabelService $labels,
	) {}

	/** Terminvorlagen (Knopf „Termin anlegen“, Terminformular) aus resources/schedule-presets.json. */
	public function schedulePresets(): array {
		$resource = json_decode((string)file_get_contents(self::PRESETS), true, 512, JSON_THROW_ON_ERROR);
		$presets = [];
		foreach ($resource['schedulePresets'] ?? [] as $preset) {
			$normalized = $this->normalizeSchedulePreset($preset);
			$presets[$normalized['key']] = $normalized;
		}
		return array_values($presets);
	}

	/** Knöpfe einer Ablaufaufgabe inkl. Ausführungsstand; andere Aufgaben haben keine Knöpfe. */
	public function available(int $recordId): array {
		$source = $this->records->get($recordId);
		$step = $this->stepActions($source);
		if ($step === null || $step['actions'] === []) return [];
		$runs = $this->runs($recordId);
		$scheduleOptions = (int)$source['caseId'] > 0 ? $this->documents->funeralEventOptions((int)$source['caseId']) : [];
		$linkedScheduleId = (int)($source['data']['sourceScheduleId'] ?? $source['data']['link']['sourceScheduleId'] ?? 0);
		return array_replace($step, ['actions' => $this->decorateActions($step['actions'], $runs, $source, $scheduleOptions, $linkedScheduleId)]);
	}

	private function decorateActions(array $actions, array $runs, array $source, array $scheduleOptions, int $linkedScheduleId): array {
		return array_map(function (array $action) use ($runs, $scheduleOptions, $linkedScheduleId, $source): array {
			$action['clientOnly'] = in_array($action['type'], self::CLIENT_ACTION_TYPES, true) || ($action['type'] === 'SCHEDULE' && $this->scheduleActionNeedsForm($action));
			$run = $action['clientOnly'] ? null : ($runs[$action['key']] ?? null);
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
		}, $actions);
	}

	/** Knöpfe des Ablaufschritts, aus dem die Aufgabe stammt (null = keine Ablaufaufgabe). */
	private function stepActions(array $source): ?array {
		$stepId = (int)($source['data']['processStepId'] ?? 0);
		if ($stepId <= 0) return null;
		$query = $this->db->getQueryBuilder();
		$row = $query->select('id', 'step_key', 'title', 'actions_json')->from('bestatter_process_steps')
			->where($query->expr()->eq('id', $query->createNamedParameter($stepId)))
			->executeQuery()->fetchAssociative();
		$row = $row === false ? [] : $row;
		$actions = $row === [] ? [] : (json_decode((string)$row['actions_json'], true) ?: []);
		$actions = array_map(static fn(array $action): array => $action + ['caseStatusAfter' => '', 'recipientCategory' => '', 'templateKeys' => [], 'sourceStatusAfter' => '', 'repeatable' => false, 'dueOffsetDays' => 0, 'priority' => 'NORMAL', 'title' => '', 'description' => '', 'templateKey' => '', 'schedulePresetKey' => '', 'openForm' => false, 'view' => ''], array_values($actions));
		return ['stepId' => $stepId, 'stepKey' => (string)($row['step_key'] ?? $source['data']['processStepKey'] ?? ''), 'title' => (string)($row['title'] ?? $source['title']), 'actions' => $actions];
	}

	private function scheduleActionNeedsForm(array $action): bool {
		if (!empty($action['openForm'])) return true;
		$presetKey = strtoupper((string)($action['schedulePresetKey'] ?? ''));
		if ($presetKey === '') return false;
		foreach ($this->schedulePresets() as $preset) if ($preset['key'] === $presetKey) return $preset['scheduleKind'] === 'EXTERNAL_APPOINTMENT';
		return false;
	}

	/** Reactivates manual task consequences when their authoritative appointment changes. */
	public function invalidateScheduleDependents(array $before, array $after): array {
		if (($after['type'] ?? '') !== 'schedule' || !$this->scheduleContentChanged($before, $after)) return ['tasks' => 0, 'runs' => 0];
		$scheduleId = (int)$after['id']; $caseId = (int)$after['caseId']; $taskCount = 0; $runCount = 0;
		foreach ($this->records->list('task', $caseId) as $task) {
			$linkedScheduleId = (int)($task['data']['sourceScheduleId'] ?? $task['data']['link']['sourceScheduleId'] ?? 0);
			if ($linkedScheduleId !== $scheduleId) continue;
			$taskCount++;
			foreach (['COMPLETED', 'FAILED'] as $status) {
				$query = $this->db->getQueryBuilder();
				$runCount += $query->update('bestatter_action_runs')
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

	/** Führt einen Knopf einer Ablaufaufgabe aus (Protokoll, Wiederanlauf, Schutz vor Doppelausführung). */
	public function execute(int $recordId, string $actionKey, string $input = '{}'): array {
		$source = $this->records->get($recordId);
		$step = $this->stepActions($source);
		if ($step === null) throw new \InvalidArgumentException('Diese Aufgabe stammt nicht aus einem Ablauf und hat keine Knöpfe.');
		$action = null;
		foreach ($step['actions'] as $candidate) {
			if ($candidate['key'] === $actionKey) {
				$action = $candidate;
				break;
			}
		}
		if ($action === null) {
			throw new \InvalidArgumentException('Der Knopf wurde nicht gefunden.');
		}
		if (in_array($action['type'], self::CLIENT_ACTION_TYPES, true) || ($action['type'] === 'SCHEDULE' && $this->scheduleActionNeedsForm($action))) {
			throw new \InvalidArgumentException('Dieser Knopf öffnet einen Dialog in der Oberfläche und wird nicht serverseitig ausgeführt.');
		}
		$existingRun = $this->runs($recordId)[$actionKey] ?? null;
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
			throw new \InvalidArgumentException('Dieser Knopf wurde für die Aufgabe bereits ausgeführt oder begonnen.');
		}

		$case = (int)$source['caseId'] > 0 ? $this->cases->getCase((int)$source['caseId']) : null;
		$inputData = $this->decodeInput($input);
		$runId = $this->claimExecution($recordId, $actionKey, (bool)$action['repeatable']);
		try {
			$context = ['source' => $source, 'case' => $case];
			$date = $this->actionDate($source, (int)$action['dueOffsetDays']);
			$linkMeta = ['source' => 'PROCESS_ACTION', 'stepKey' => $step['stepKey'], 'actionKey' => $actionKey, 'parentRecordId' => $recordId];
			if ($source['type'] === 'schedule') $linkMeta += ['sourceScheduleId' => $recordId, 'sourceScheduleUid' => $source['data']['nextcloud']['uid'] ?? ''];
			$result = match ($action['type']) {
				'TASK' => $this->existingOpenTask((int)$source['caseId'], $this->expand($action['title'], $context), $recordId) ?? $this->groupware->createTask(
					$this->expand($action['title'], $context),
					$date,
					'OFFEN',
					json_encode([
						'description' => $this->expand($action['description'], $context),
						'priority' => $action['priority'],
						'assigneeUid' => $source['data']['assigneeUid'] ?? $source['data']['assigneeUids'][0] ?? $source['assigneeUid'] ?? '',
						'link' => $linkMeta,
						'sourceScheduleId' => $linkMeta['sourceScheduleId'] ?? 0,
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
						'link' => $linkMeta,
						'sourceScheduleId' => $linkMeta['sourceScheduleId'] ?? 0,
					], JSON_THROW_ON_ERROR),
					(int)$source['caseId'],
				),
				'SCHEDULE' => $this->groupware->createEvent(
					$this->expand($action['title'], $context),
					$date,
					'OFFEN',
					json_encode(array_filter([
						'description' => $this->expand($action['description'], $context),
						'priority' => $action['priority'],
						'link' => ['source' => 'PROCESS_ACTION', 'stepKey' => $step['stepKey'], 'actionKey' => $actionKey, 'parentRecordId' => $recordId],
						// Befund 1 (Analyse 2026-09-24): Ohne Terminart griff die Trauerfeier-Automatik nie.
						'schedulePresetKey' => (string)($action['schedulePresetKey'] ?? ''),
					], static fn(mixed $value): bool => $value !== ''), JSON_THROW_ON_ERROR),
					(int)$source['caseId'],
				),
				'DOCUMENT' => $this->executeDocument($case, $action, $recordId, $source, $inputData),
				'DOCUMENT_BUNDLE' => $this->executeDocumentBundle($case, $action, $recordId, $source, $inputData),
				'EMAIL_DRAFT' => $this->executeEmailDraft($case, $action, $recordId, $context, $inputData),
				'CONTACT_ACTIVITY' => $this->executeContactActivity($case, $action, $recordId, $context, $inputData),
				'CASE_STATUS' => ['case' => $this->applyCaseStatus($case, $action['caseStatusAfter'])],
				'DEREGISTRATIONS' => $this->executeDeregistrations($case, $action, $inputData),
				// 0.76.0: Knopf „Marker setzen/entfernen“
				'LABEL' => $case === null ? throw new \InvalidArgumentException('Marker gibt es nur bei fallbezogenen Aufgaben.') : ['labels' => $action['labelMode'] === 'REMOVE' ? $this->labels->remove((int)$case['id'], $action['labelKey']) : $this->labels->set((int)$case['id'], $action['labelKey'], $action['labelValue'], isset($inputData['note']) ? (string)$inputData['note'] : null)],
				default => throw new \InvalidArgumentException('Nicht unterstützter Knopftyp.'),
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

			$this->completeExecution($runId, (int)$source['caseId'], $step, $action, $recordId, $result);
			return ['step' => $step['title'], 'action' => $action, 'result' => $result];
		} catch (\Throwable $error) {
			$this->failExecution($runId, (int)$source['caseId'], $step, $action, $recordId, $error);
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
			'actionSourceRecordId' => $recordId,
		], JSON_THROW_ON_ERROR), (int)$case['id']);
		return ['record' => $record, 'delivery' => 'DRAFT_ONLY'];
	}

	private function executeContactActivity(?array $case, array $action, int $recordId, array $context, array $input): array {
		if ($case === null) throw new \InvalidArgumentException('Kontaktaktivitäten können nur für fallbezogene Aufgaben dokumentiert werden.');
		$record = $this->records->create('activity', trim((string)($input['title'] ?? $this->expand($action['title'], $context))), date('c'), 'DOKUMENTIERT', json_encode([
			'kind' => 'CONTACT_ACTIVITY',
			'description' => trim((string)($input['description'] ?? $this->expand($action['description'], $context))),
			'contact' => trim((string)($input['contact'] ?? '')), 'channel' => trim((string)($input['channel'] ?? 'Telefon')),
			'actionSourceRecordId' => $recordId,
		], JSON_THROW_ON_ERROR), (int)$case['id']);
		return ['record' => $record];
	}

	/**
	 * Befund 6 (Analyse 2026-09-24): statt Aufgaben je Empfänger werden echte Abmeldeentwürfe
	 * angelegt; bereits vorhandene Abmeldungen derselben Vorlage werden nicht doppelt erzeugt.
	 */
	private function executeDeregistrations(?array $case, array $action, array $input): array {
		if ($case === null) throw new \InvalidArgumentException('Abmeldungen können nur für fallbezogene Aufgaben angelegt werden.');
		$requested = array_values(array_unique(array_filter(array_map(static fn(mixed $value): string => strtoupper(trim((string)$value)), (array)($input['templateKeys'] ?? $action['templateKeys'] ?? [])))));
		if ($requested === []) throw new \InvalidArgumentException('Bitte mindestens eine Abmeldung auswählen.');
		$existing = [];
		foreach ($this->deregistrations->list((int)$case['id']) as $record) $existing[strtoupper((string)($record['data']['templateKey'] ?? ''))] = true;
		$created = []; $skipped = []; $failed = [];
		foreach ($requested as $templateKey) {
			if (isset($existing[$templateKey])) { $skipped[] = $templateKey; continue; }
			try {
				$created[] = $this->deregistrations->save((int)$case['id'], ['templateKey' => $templateKey, 'status' => 'ENTWURF']);
			} catch (\InvalidArgumentException $error) {
				$failed[] = ['templateKey' => $templateKey, 'message' => $error->getMessage()];
			}
		}
		if ($created === [] && $failed !== []) throw new \InvalidArgumentException('Es konnte keine Abmeldung angelegt werden: ' . implode('; ', array_map(static fn(array $item): string => $item['message'], $failed)));
		return ['created' => $created, 'skipped' => $skipped, 'failed' => $failed];
	}

	/** Befund 2 (Analyse 2026-09-24): eine offene Aufgabe gleichen Titels im Fall wird wiederverwendet statt verdoppelt. */
	private function existingOpenTask(int $caseId, string $title, int $sourceRecordId): ?array {
		if ($caseId <= 0 || trim($title) === '') return null;
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('id', 'owner_uid', 'title', 'status')->from('bestatter_records')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->andWhere($query->expr()->eq('record_type', $query->createNamedParameter('task')))
			->andWhere($query->expr()->neq('status', $query->createNamedParameter('ERLEDIGT')))
			->executeQuery()->fetchAllAssociative();
		foreach ($rows as $row) {
			if ((int)$row['id'] === $sourceRecordId) continue;
			if (mb_strtolower(trim((string)$row['title'])) !== mb_strtolower(trim($title))) continue;
			return $this->records->get((int)$row['id'], (string)$row['owner_uid']) + ['reusedExisting' => true];
		}
		return null;
	}

	private function applyCaseStatus(?array $case, string $status): array {
		if ($case === null) throw new \InvalidArgumentException('Der Fallstatus kann nur für fallbezogene Aufgaben geändert werden.');
		$masterData = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$masterData['status'] = $status;
		// expectedUpdatedAt schützt vor stillem Überschreiben: $case wurde zu Beginn von
		// execute() geladen, updateMasterData() lehnt ab, falls der Fall zwischenzeitlich
		// von anderer Stelle geändert wurde (siehe BestatterConflictException).
		return $this->cases->updateMasterData((int)$case['id'], $masterData, (string)($case['updatedAt'] ?? ''));
	}

	private function decodeInput(string $input): array {
		if (trim($input) === '') return [];
		try {
			$data = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			throw new \InvalidArgumentException('Ungültige Eingaben für den Knopf.', 0, $error);
		}
		if (!is_array($data)) throw new \InvalidArgumentException('Ungültige Eingaben für den Knopf.');
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
			'actionSourceRecordId' => $recordId,
			'sourceScheduleId' => (int)($schedule['id'] ?? 0),
			'sourceScheduleSnapshot' => $schedule === null ? null : $this->scheduleSnapshot($schedule),
		]);
	}

	private function actionDate(array $source, int $offsetDays): string {
		$base = $source['type'] === 'schedule' && trim((string)$source['date']) !== ''
			? new \DateTimeImmutable((string)$source['date'], new \DateTimeZone('Europe/Berlin'))
			: new \DateTimeImmutable('today 09:00', new \DateTimeZone('Europe/Berlin'));
		$due = $base->modify(sprintf('%+d days', $offsetDays))->setTime(9, 0);
		// Befund 7 (Analyse 2026-09-24): Bei kurzfristig bestätigten Terminen lagen Fristen mit
		// negativem Versatz (z. B. -3 Tage) sonst in der Vergangenheit.
		$today = new \DateTimeImmutable('today 09:00', new \DateTimeZone('Europe/Berlin'));
		if ($due < $today) $due = $today;
		return $due->format('Y-m-d\TH:i');
	}

	private function caseWithScheduleContext(array $case, array $source, int $selectedScheduleId = 0): array {
		$schedule = $source['type'] === 'schedule' ? $source : null;
		if ($schedule === null) {
			$linkedScheduleId = (int)($source['data']['sourceScheduleId'] ?? $source['data']['link']['sourceScheduleId'] ?? 0);
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

	private function normalizeSchedulePreset(array $preset): array {
		$key = strtoupper(trim((string)($preset['key'] ?? '')));
		$label = trim((string)($preset['label'] ?? $preset['title'] ?? ''));
		if (!preg_match('/^[A-Z0-9_-]+$/', $key) || $label === '') {
			throw new \InvalidArgumentException('Eine Terminvorlage enthält einen ungültigen Schlüssel oder keine Bezeichnung.');
		}
		$kind = strtoupper((string)($preset['scheduleKind'] ?? 'EXTERNAL_APPOINTMENT'));
		if (!in_array($kind, ['INTERNAL_ACTIVITY', 'EXTERNAL_APPOINTMENT'], true)) $kind = 'EXTERNAL_APPOINTMENT';
		$priority = strtoupper((string)($preset['priority'] ?? 'NORMAL'));
		if (!in_array($priority, self::PRIORITIES, true)) $priority = 'NORMAL';
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
			'source' => 'CATALOG',
		];
	}

	/** Prüft und normalisiert die Knöpfe eines Ablaufschritts; fehlende Schlüssel werden aus der Beschriftung erzeugt. */
	public function normalizeActions(array $input): array {
		$actions = [];
		$seenKeys = [];
		foreach (array_values($input) as $index => $action) {
			if (!is_array($action)) throw new \InvalidArgumentException('Ein Knopf ist ungültig.');
			$type = strtoupper((string)($action['type'] ?? 'TASK'));
			$key = strtoupper(trim((string)($action['key'] ?? '')));
			if ($key === '') $key = ProcessRules::keyFromTitle((string)($action['label'] ?? ('AKTION_' . ($index + 1))));
			if (!in_array($type, self::ACTION_TYPES, true) || !preg_match('/^[A-Z0-9_-]+$/', $key)) {
				throw new \InvalidArgumentException('Ein Knopf enthält einen ungültigen Typ oder Schlüssel.');
			}
			if (isset($seenKeys[$key])) throw new \InvalidArgumentException('Der Aktionsschlüssel „' . $key . '“ ist doppelt vergeben.');
			$seenKeys[$key] = true;
			$title = trim((string)($action['title'] ?? ''));
			$templateKey = strtoupper(trim((string)($action['templateKey'] ?? '')));
			$templateKeys = array_values(array_unique(array_filter(array_map(static fn(mixed $value): string => strtoupper(trim((string)$value)), (array)($action['templateKeys'] ?? [])))));
			$schedulePresetKey = strtoupper(trim((string)($action['schedulePresetKey'] ?? '')));
			$openForm = filter_var($action['openForm'] ?? false, FILTER_VALIDATE_BOOLEAN);
			$view = strtolower(trim((string)($action['view'] ?? '')));
			$needsTitle = in_array($type, ['TASK', 'REMINDER', 'EMAIL_DRAFT', 'CONTACT_ACTIVITY'], true) || ($type === 'SCHEDULE' && !$openForm);
			if ($needsTitle && $title === '') {
				throw new \InvalidArgumentException('Für diesen Knopf ist ein Titel erforderlich.');
			}
			if ($type === 'DOCUMENT' && $templateKey === '') {
				throw new \InvalidArgumentException('Für eine Dokumentaktion muss eine Vorlage gewählt werden.');
			}
			if ($type === 'DOCUMENT_BUNDLE' && count($templateKeys) < 2) throw new \InvalidArgumentException('Ein Dokumentpaket benötigt mindestens zwei Vorlagen.');
			if ($type === 'DEREGISTRATIONS' && $templateKeys === []) throw new \InvalidArgumentException('Für „Abmeldungen anlegen“ muss mindestens eine Abmeldung vorausgewählt sein.');
			if ($type === 'OPEN_VIEW' && !in_array($view, self::OPEN_VIEWS, true)) throw new \InvalidArgumentException('Für „Bereich öffnen“ muss ein gültiger Bereich gewählt werden.');
			if ($schedulePresetKey !== '' && !preg_match('/^[A-Z0-9_-]+$/', $schedulePresetKey)) throw new \InvalidArgumentException('Ungültige Terminart in einem Knopf.');
			$sourceStatus = strtoupper((string)($action['sourceStatusAfter'] ?? ''));
			if ($sourceStatus !== '' && !in_array($sourceStatus, array_slice(self::STATUSES, 1), true)) {
				throw new \InvalidArgumentException('Ungültiger Folgestatus der Ausgangsaufgabe.');
			}
			$caseStatus = strtoupper((string)($action['caseStatusAfter'] ?? ''));
			if ($caseStatus !== '' && !in_array($caseStatus, self::CASE_STATUSES, true)) {
				throw new \InvalidArgumentException('Ungültiger automatischer Fallstatus.');
			}
			$labelKey = strtoupper(trim((string)($action['labelKey'] ?? '')));
			$labelValue = strtoupper(trim((string)($action['labelValue'] ?? '')));
			$labelMode = strtoupper(trim((string)($action['labelMode'] ?? 'SET')));
			if ($type === 'LABEL') {
				if (!ProcessRules::isLabelKey($labelKey)) throw new \InvalidArgumentException('Für „Marker setzen/entfernen“ muss ein Marker gewählt werden.');
				if (!in_array($labelMode, ['SET', 'REMOVE'], true)) throw new \InvalidArgumentException('Ungültige Marker-Aktion.');
				if ($labelValue !== '' && !ProcessRules::isLabelValue($labelValue)) throw new \InvalidArgumentException('Ungültiger Markerwert in einem Knopf.');
			}
			if ($type === 'CASE_STATUS' && $caseStatus === '') {
				throw new \InvalidArgumentException('Für den Fallstatuswechsel muss ein Zielstatus gewählt werden.');
			}
			$label = trim((string)($action['label'] ?? $title ?: $templateKey));
			if ($label === '') {
				throw new \InvalidArgumentException('Jeder Knopf benötigt eine Beschriftung.');
			}
			$priority = strtoupper((string)($action['priority'] ?? 'NORMAL'));
			$actions[] = [
				'key' => $key,
				'type' => $type,
				'label' => $label,
				'title' => $title,
				'description' => trim((string)($action['description'] ?? '')),
				'dueOffsetDays' => (int)($action['dueOffsetDays'] ?? 0),
				'priority' => in_array($priority, self::PRIORITIES, true) ? $priority : 'NORMAL',
				'templateKey' => $templateKey,
				'templateKeys' => $templateKeys,
				'sourceStatusAfter' => $sourceStatus,
				'caseStatusAfter' => $caseStatus,
				'recipientCategory' => trim((string)($action['recipientCategory'] ?? '')),
				'schedulePresetKey' => $schedulePresetKey,
				'openForm' => $openForm,
				'view' => $view,
				'labelKey' => $type === 'LABEL' ? $labelKey : '',
				'labelValue' => $type === 'LABEL' && $labelMode === 'SET' ? $labelValue : '',
				'labelMode' => $type === 'LABEL' ? $labelMode : '',
				'repeatable' => (bool)($action['repeatable'] ?? false),
			];
		}
		return $actions;
	}

	private function runs(int $recordId): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_action_runs')
			->where($query->expr()->eq('source_record_id', $query->createNamedParameter($recordId)))
			->orderBy('created_at', 'DESC')->executeQuery()->fetchAllAssociative();
		$result = [];
		foreach ($rows as $row) {
			$result[(string)$row['action_key']] ??= [
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
			$userFolder = $this->storage->baseFolder($userId);
		} catch (\Throwable) {
			return false;
		}

		$validKeys = [];
		$templateFingerprints = [];
		$isCondolence = str_contains(strtoupper((string)($action['key'] ?? '')), 'KONDOLENZ');
		$expectedScheduleId = $source['type'] === 'schedule'
			? (int)($source['id'] ?? 0)
			: (int)($source['data']['sourceScheduleId'] ?? $source['data']['link']['sourceScheduleId'] ?? 0);
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
					// Missing/inaccessible nodes make the durable action output incomplete.
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
		$affected = $query->update('bestatter_action_runs')
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
		if ($affected !== 1) throw new \InvalidArgumentException('Der Stand des Knopfs hat sich zwischenzeitlich geändert. Bitte die Aufgabe neu öffnen.');
	}

	private function claimExecution(int $recordId, string $actionKey, bool $repeatable): int {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('Kein Nextcloud-Benutzer angemeldet.');
		}
		$executionKey = $repeatable
			? sprintf('repeat:%d:%s:%s', $recordId, $actionKey, bin2hex(random_bytes(16)))
			: sprintf('once:%d:%s', $recordId, $actionKey);
		$now = date('c');
		$this->db->beginTransaction();
		try {
			$query = $this->db->getQueryBuilder();
			$query->insert('bestatter_action_runs')->values([
				'source_record_id' => $query->createNamedParameter($recordId),
				'action_key' => $query->createNamedParameter($actionKey),
				'execution_key' => $query->createNamedParameter($executionKey),
				'run_status' => $query->createNamedParameter('RUNNING'),
				'result_json' => $query->createNamedParameter('{}'),
				'executed_by' => $query->createNamedParameter($user->getUID()),
				'created_at' => $query->createNamedParameter($now),
				'updated_at' => $query->createNamedParameter($now),
			])->executeStatement();
			$runId = (int)$this->db->lastInsertId('bestatter_action_runs');
			$this->db->commit();
			return $runId;
		} catch (\Throwable $error) {
			$this->safeRollback();
			if (!$repeatable) {
				$retryRunId = $this->restartExecutionClaim($executionKey, $user->getUID());
				if ($retryRunId > 0) return $retryRunId;
			}
			if (!$repeatable && $this->executionClaimExists($executionKey)) {
				throw new \InvalidArgumentException('Dieser Knopf wurde bereits begonnen. Bitte den Stand der Aufgabe prüfen, bevor er erneut ausgeführt wird.', 0, $error);
			}
			throw $error;
		}
	}

	private function restartExecutionClaim(string $executionKey, string $userId): int {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('id', 'run_status')->from('bestatter_action_runs')
			->where($query->expr()->eq('execution_key', $query->createNamedParameter($executionKey)))
			->setMaxResults(1)->executeQuery()->fetchAssociative();
		if ($row === false || !in_array((string)$row['run_status'], ['FAILED', 'SUPERSEDED'], true)) return 0;
		$update = $this->db->getQueryBuilder();
		$affected = $update->update('bestatter_action_runs')
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
		return (bool)$query->select('id')->from('bestatter_action_runs')
			->where($query->expr()->eq('execution_key', $query->createNamedParameter($executionKey)))
			->setMaxResults(1)->executeQuery()->fetchOne();
	}

	private function completeExecution(int $runId, int $caseId, array $step, array $action, int $recordId, array $result): void {
		$this->db->beginTransaction();
		try {
			$this->updateRun($runId, 'COMPLETED', $result);
			$this->audit->log($caseId, 'PROCESS_ACTION', $runId, 'EXECUTED', [
				'stepKey' => $step['stepKey'], 'sourceRecordId' => $recordId, 'actionKey' => $action['key'],
			], [
				'step' => $step['title'], 'action' => $action['label'], 'result' => $result,
			]);
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->safeRollback();
			throw $error;
		}
	}

	private function failExecution(int $runId, int $caseId, array $step, array $action, int $recordId, \Throwable $cause): void {
		try {
			$this->db->beginTransaction();
			$error = ['message' => $cause->getMessage(), 'class' => $cause::class];
			$this->updateRun($runId, 'FAILED', ['error' => $error]);
			$this->audit->log($caseId, 'PROCESS_ACTION', $runId, 'FAILED', [
				'stepKey' => $step['stepKey'], 'sourceRecordId' => $recordId, 'actionKey' => $action['key'],
			], $error + ['step' => $step['title'], 'action' => $action['label']]);
			$this->db->commit();
		} catch (\Throwable) {
			$this->safeRollback();
			// The original action error remains authoritative. A RUNNING claim is
			// intentionally retained if bookkeeping fails, preventing unsafe replay.
		}
	}

	private function updateRun(int $runId, string $status, array $result): void {
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_action_runs')
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
		if (!str_contains($value, '{{')) return $value;
		$master = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$replacements = [
			'{{source.title}}' => (string)$source['title'],
			'{{case.number}}' => (string)($case['caseNumber'] ?? ''),
			'{{case.first_name}}' => (string)($case['firstName'] ?? ''),
			'{{case.last_name}}' => (string)($case['lastName'] ?? ''),
			'{{case.date_of_death}}' => $this->germanDate((string)($master['date_of_death'] ?? $case['dateOfDeath'] ?? '')),
			'{{client.name}}' => trim((string)($master['order_client_first_name'] ?? '') . ' ' . (string)($master['order_client_last_name'] ?? '')),
			'{{deposit.amount}}' => $this->euro((int)($master['deposit_amount_cents'] ?? 0)),
		];
		// Rechnungs- und Bankplatzhalter (Zahlungsaufforderung, Zahlungserinnerung, Mahnung) nur bei Bedarf laden.
		if ((int)($case['id'] ?? 0) > 0 && (str_contains($value, '{{invoice.') || str_contains($value, '{{branch.'))) {
			$invoice = $this->latestFinalInvoice((int)$case['id']);
			$branch = $this->branchRow((string)($case['branch'] ?? $master['branch'] ?? ''));
			$termDays = (int)($branch['payment_term_days'] ?? 14);
			$issued = (string)($invoice['updated_at'] ?? '');
			$replacements += [
				'{{invoice.number}}' => (string)($invoice['invoice_number'] ?? ''),
				'{{invoice.amount}}' => $invoice === null ? '' : $this->euro((int)$invoice['gross_cents']),
				'{{invoice.due_date}}' => $issued === '' ? '' : (new \DateTimeImmutable(substr($issued, 0, 10)))->modify('+' . max(0, $termDays) . ' days')->format('d.m.Y'),
				'{{branch.name}}' => (string)($branch['name'] ?? ''),
				'{{branch.account_holder}}' => (string)($branch['account_holder'] ?? ''),
				'{{branch.iban}}' => (string)($branch['iban'] ?? ''),
				'{{branch.bic}}' => (string)($branch['bic'] ?? ''),
			];
		}
		return strtr($value, $replacements);
	}

	private function latestFinalInvoice(int $caseId): ?array {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('invoice_number', 'gross_cents', 'status', 'updated_at')->from('bestatter_invoices')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->andWhere($query->expr()->eq('invoice_type', $query->createNamedParameter('FINAL')))
			->andWhere($query->expr()->neq('status', $query->createNamedParameter('STORNIERT')))
			->andWhere($query->expr()->isNull('side_order_id'))
			->orderBy('id', 'DESC')->setMaxResults(1)->executeQuery()->fetchAssociative();
		return $row === false ? null : $row;
	}

	private function branchRow(string $branchKey): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from('bestatter_branches');
		if ($branchKey !== '') $query->where($query->expr()->eq('branch_key', $query->createNamedParameter($branchKey)));
		$row = $query->orderBy('sort_order', 'ASC')->setMaxResults(1)->executeQuery()->fetchAssociative();
		return $row === false ? [] : $row;
	}

	private function euro(int $cents): string {
		return number_format($cents / 100, 2, ',', '.') . ' €';
	}

	private function germanDate(string $value): string {
		$value = trim($value);
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $match)) return $value;
		return $match[3] . '.' . $match[2] . '.' . $match[1];
	}
}
