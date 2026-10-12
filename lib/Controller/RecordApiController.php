<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\ProcessService;
use OCA\Bestatter\Service\AuditService;
use OCA\Bestatter\Service\ContactService;
use OCA\Bestatter\Service\GroupwareService;
use OCA\Bestatter\Service\ProcessActionService;
use OCA\Bestatter\Service\RecordService;
use OCA\Bestatter\Service\SchedulingService;
use OCA\Bestatter\Service\TeamService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class RecordApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private RecordService $recordService, private AuditService $audit, private ContactService $contacts, private GroupwareService $groupware, private ProcessActionService $actions, private TeamService $teamService, private SchedulingService $scheduling, private ProcessService $process) {
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
		if ($type === 'schedule') { $this->scheduling->log($item, 'CREATED', (string)($item['data']['changeReason'] ?? '')); $item['history'] = $this->scheduling->history((int)$item['id']); }
		if (in_array($type, ['task', 'schedule'], true) && (int)($item['caseId'] ?? 0) > 0) $item['process'] = $this->process->reconcileQuietly((int)$item['caseId']);
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
			$updated['reactivatedActions'] = $this->actions->invalidateScheduleDependents($previous, $updated);
		}
		if (in_array($updated['type'], ['task', 'schedule'], true) && (int)($updated['caseId'] ?? 0) > 0) $updated['process'] = $this->process->reconcileQuietly((int)$updated['caseId']);
		return new DataResponse($updated);
	}

	#[NoAdminRequired]
	public function deleteRecord(int $id): DataResponse {
		$this->recordService->delete($id);
		return new DataResponse([], 204);
	}

	#[NoAdminRequired]
	public function syncGroupware(string $type = 'task'): DataResponse {
		return new DataResponse($this->groupware->synchronize($type));
	}

	#[NoAdminRequired]
	public function schedulePresets(): DataResponse { return new DataResponse($this->actions->schedulePresets()); }

	#[NoAdminRequired]
	public function schedulingCatalog(): DataResponse { return new DataResponse($this->scheduling->catalog()); }

	#[NoAdminRequired]
	public function saveScheduleType(int $id = 0, string $data = '{}'): DataResponse { $this->teamService->requireBestatterAdmin(); return new DataResponse($this->scheduling->saveType(json_decode($data, true, 512, JSON_THROW_ON_ERROR), $id)); }

	#[NoAdminRequired]
	public function saveScheduleResource(int $id = 0, string $data = '{}'): DataResponse { $this->teamService->requireBestatterAdmin(); return new DataResponse($this->scheduling->saveResource(json_decode($data, true, 512, JSON_THROW_ON_ERROR), $id)); }

	#[NoAdminRequired]
	public function scheduleHistory(int $id): DataResponse { return new DataResponse($this->scheduling->history($id)); }

	#[NoAdminRequired]
	public function availableActions(int $recordId): DataResponse { return new DataResponse($this->actions->available($recordId)); }

	#[NoAdminRequired]
	public function executeAction(int $recordId, string $actionKey, string $input = '{}'): DataResponse {
		$result = $this->actions->execute($recordId, $actionKey, $input);
		// Die Aktion ist bereits ausgeführt: Ein Fehler beim Ablaufabgleich darf sie nicht als gescheitert melden.
		try {
			$caseId = (int)($result['case']['id'] ?? $result['result']['case']['id'] ?? 0) ?: (int)($this->recordService->get($recordId)['caseId'] ?? 0);
			if ($caseId > 0) $result['process'] = $this->process->reconcileQuietly($caseId);
		} catch (\Throwable) {
		}
		return new DataResponse($result, 201);
	}
}
