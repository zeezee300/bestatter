<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\AuditService;
use OCA\Bestatter\Service\ChecklistService;
use OCA\Bestatter\Service\ContactService;
use OCA\Bestatter\Service\GroupwareService;
use OCA\Bestatter\Service\RecordService;
use OCA\Bestatter\Service\SchedulingService;
use OCA\Bestatter\Service\TeamService;
use OCA\Bestatter\Service\WorkflowService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class RecordApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private RecordService $recordService, private AuditService $audit, private ContactService $contacts, private GroupwareService $groupware, private WorkflowService $workflows, private ChecklistService $checklists, private TeamService $teamService, private SchedulingService $scheduling) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function records(string $type, int $caseId = 0): DataResponse {
		if ($type === 'activity' && $caseId > 0) return new DataResponse($this->audit->caseHistory($caseId));
		return new DataResponse($type === 'contact' ? $this->contacts->list() : $this->recordService->list($type, $caseId));
	}

	#[NoAdminRequired]
	public function createRecord(string $type, string $title = '', string $date = '', string $status = 'OFFEN', string $data = '{}', int $caseId = 0): DataResponse {
		if ($type === 'activity') throw new \InvalidArgumentException('Der Fall-Verlauf wird automatisch als unveränderbares Protokoll geführt.');
		if ($type === 'document') throw new \InvalidArgumentException('Dokumente werden ausschließlich über die geprüften Fall- und Dokumentprozesse angelegt.');
		if ($type === 'schedule') $data = json_encode($this->scheduling->prepare($data, $date), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$item = match ($type) {
			'task' => $this->groupware->createTask($title, $date, $status, $data, $caseId),
			'schedule' => $this->groupware->createEvent($title, $date, $status, $data, $caseId),
			'contact' => $this->contacts->create($title, $data),
			default => $this->recordService->create($type, $title, $date, $status, $data, $caseId),
		};
		if ($type === 'schedule') { $this->scheduling->log($item, 'CREATED', (string)($item['data']['changeReason'] ?? '')); $item['history'] = $this->scheduling->history((int)$item['id']); $item['automation'] = $this->workflows->handleScheduleEvent($item); }
		return new DataResponse($item, 201);
	}

	#[NoAdminRequired]
	public function updateRecord(int $id, string $title = '', string $date = '', string $status = 'OFFEN', string $data = '{}', int $caseId = 0): DataResponse {
		$previous = $this->recordService->get($id);
		if (($previous['type'] ?? '') === 'document') throw new \InvalidArgumentException('Dokumente werden ausschließlich über die geprüften Fall- und Dokumentprozesse bearbeitet.');
		if (($previous['type'] ?? '') === 'schedule') {
			$prepared = $this->scheduling->prepare($data, $date, $id);
			$this->scheduling->requireChangeReason($previous, $title, $date, $status, $prepared);
			$data = json_encode($prepared, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		}
		$updated = $this->recordService->update($id, $title, $date, $status, $data, $caseId);
		if (in_array($updated['type'], ['task', 'schedule'], true)) $updated = $this->groupware->update($updated);
		if ($updated['type'] === 'schedule') {
			$this->scheduling->log($updated, $status === 'ABGESAGT' ? 'CANCELLED' : ($status === 'ERLEDIGT' ? 'COMPLETED' : 'UPDATED'), (string)($updated['data']['changeReason'] ?? ''), $previous);
			$updated['history'] = $this->scheduling->history($id);
			$updated['reactivatedActions'] = $this->workflows->invalidateScheduleDependents($previous, $updated);
			$updated['automation'] = $this->workflows->handleScheduleEvent($updated);
		}
		return new DataResponse($updated);
	}

	#[NoAdminRequired]
	public function deleteRecord(int $id): DataResponse {
		$this->recordService->delete($id);
		return new DataResponse([], 204);
	}

	#[NoAdminRequired]
	public function syncGroupware(string $type = 'task'): DataResponse {
		$result = $this->groupware->synchronize($type);
		if ($type === 'schedule') $result['automation'] = $this->workflows->handlePendingScheduleEvents();
		return new DataResponse($result);
	}

	#[NoAdminRequired]
	public function checklists(): DataResponse { return new DataResponse($this->checklists->list()); }

	#[NoAdminRequired]
	public function manageChecklists(): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->checklists->manage());
	}

	#[NoAdminRequired]
	public function saveChecklist(string $key, string $name = '', string $description = '', string $items = '[]', bool $active = true): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->checklists->save($key, $name, $description, $items, $active));
	}

	#[NoAdminRequired]
	public function createChecklist(string $key, string $name = '', string $description = ''): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->checklists->create($key, $name, $description), 201);
	}

	#[NoAdminRequired]
	public function deleteChecklist(string $key): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$this->checklists->delete($key);
		return new DataResponse([], 204);
	}

	#[NoAdminRequired]
	public function applyChecklist(string $key, int $caseId, string $selected = ''): DataResponse {
		$items = $selected !== '' ? json_decode($selected, true, 512, JSON_THROW_ON_ERROR) : [];
		return new DataResponse($this->checklists->apply($key, $caseId, is_array($items) ? $items : []), 201);
	}

	#[NoAdminRequired]
	public function workflows(): DataResponse { return new DataResponse($this->workflows->list()); }

	#[NoAdminRequired]
	public function schedulePresets(): DataResponse { return new DataResponse($this->workflows->schedulePresets()); }

	#[NoAdminRequired]
	public function schedulingCatalog(): DataResponse { return new DataResponse($this->scheduling->catalog()); }

	#[NoAdminRequired]
	public function saveScheduleType(int $id = 0, string $data = '{}'): DataResponse { $this->teamService->requireBestatterAdmin(); return new DataResponse($this->scheduling->saveType(json_decode($data, true, 512, JSON_THROW_ON_ERROR), $id)); }

	#[NoAdminRequired]
	public function saveScheduleResource(int $id = 0, string $data = '{}'): DataResponse { $this->teamService->requireBestatterAdmin(); return new DataResponse($this->scheduling->saveResource(json_decode($data, true, 512, JSON_THROW_ON_ERROR), $id)); }

	#[NoAdminRequired]
	public function scheduleHistory(int $id): DataResponse { return new DataResponse($this->scheduling->history($id)); }

	#[NoAdminRequired]
	public function reorderWorkflows(string $workflowIds = '[]'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$ids = json_decode($workflowIds, true, 512, JSON_THROW_ON_ERROR);
		return new DataResponse($this->workflows->reorder(is_array($ids) ? $ids : []));
	}

	#[NoAdminRequired]
	public function createWorkflow(string $workflow = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->workflows->create($workflow), 201);
	}

	#[NoAdminRequired]
	public function updateWorkflow(int $id, string $workflow = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->workflows->update($id, $workflow));
	}

	#[NoAdminRequired]
	public function deleteWorkflow(int $id): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$this->workflows->delete($id);
		return new DataResponse([], 204);
	}

	#[NoAdminRequired]
	public function availableWorkflowActions(int $recordId): DataResponse { return new DataResponse($this->workflows->available($recordId)); }

	#[NoAdminRequired]
	public function executeWorkflowAction(int $recordId, int $workflowId, string $actionKey, string $input = '{}'): DataResponse { return new DataResponse($this->workflows->execute($recordId, $workflowId, $actionKey, $input), 201); }
}
