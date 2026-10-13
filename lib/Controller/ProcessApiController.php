<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\ProcessService;
use OCA\Bestatter\Service\TeamService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** Ablaufvorlagen (Customizing) und Ablauf im Fall (Phasenleiste, Schritte, Abschluss) – 0.66.0. */
class ProcessApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private ProcessService $process, private TeamService $teamService) {
		parent::__construct($appName, $request);
	}

	/** 0.88.1: Unbesetzte Rollen der Abläufe (zentraler Hinweis bei Fallanlage und auf der Startseite). */
	#[NoAdminRequired]
	public function processRoleStatus(): DataResponse { return new DataResponse($this->process->roleStatus()); }

	#[NoAdminRequired]
	public function caseProcess(int $caseId): DataResponse { return new DataResponse($this->process->overview($caseId)); }

	#[NoAdminRequired]
	public function addProcessStep(int $caseId, int $stepId): DataResponse { return new DataResponse($this->process->addStep($caseId, $stepId), 201); }

	#[NoAdminRequired]
	public function saveProcessAttributes(int $caseId, string $attributes = '{}', string $expectedUpdatedAt = ''): DataResponse {
		$data = json_decode($attributes, true, 512, JSON_THROW_ON_ERROR);
		return new DataResponse($this->process->saveAttributes($caseId, is_array($data) ? $data : [], $expectedUpdatedAt));
	}

	#[NoAdminRequired]
	public function processClosure(int $caseId): DataResponse { return new DataResponse($this->process->closureCheck($caseId)); }

	#[NoAdminRequired]
	public function closeCaseProcess(int $caseId, string $note = ''): DataResponse { return new DataResponse($this->process->closeCase($caseId, $note)); }

	#[NoAdminRequired]
	public function processSuggestions(int $caseId): DataResponse { return new DataResponse($this->process->suggestions($caseId)); }

	#[NoAdminRequired]
	public function completeProcessRun(int $runId): DataResponse { return new DataResponse($this->process->completeRun($runId)); }

	#[NoAdminRequired]
	public function skipProcessRun(int $runId, string $reason = ''): DataResponse { return new DataResponse($this->process->skipRun($runId, $reason)); }

	#[NoAdminRequired]
	public function reopenProcessRun(int $runId): DataResponse { return new DataResponse($this->process->reopenRun($runId)); }

	#[NoAdminRequired]
	public function processTemplates(): DataResponse {
		return new DataResponse(['templates' => $this->process->templates(), 'meta' => $this->process->meta()]);
	}

	#[NoAdminRequired]
	public function createProcessTemplate(string $name = '', string $copyFrom = ''): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->process->createTemplate($name, $copyFrom), 201);
	}

	#[NoAdminRequired]
	public function saveProcessTemplate(string $key, string $template = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$data = json_decode($template, true, 512, JSON_THROW_ON_ERROR);
		return new DataResponse($this->process->saveTemplate($key, is_array($data) ? $data : []));
	}

	#[NoAdminRequired]
	public function deleteProcessTemplate(string $key): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$this->process->deleteTemplate($key);
		return new DataResponse([], 204);
	}

	#[NoAdminRequired]
	public function restoreProcessTemplate(string $key): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->process->restoreBundled($key));
	}

	#[NoAdminRequired]
	public function exportProcessTemplate(string $key): DataDownloadResponse {
		$this->teamService->requireBestatterAdmin();
		$export = $this->process->exportCsv($key);
		return new DataDownloadResponse($export['content'], $export['fileName'], 'text/csv; charset=UTF-8');
	}

	/** 0.75.3: verlustfreie JSON-Austauschdatei (inkl. Knöpfe). */
	#[NoAdminRequired]
	public function exportProcessTemplateJson(string $key): DataDownloadResponse {
		$this->teamService->requireBestatterAdmin();
		$export = $this->process->exportJson($key);
		return new DataDownloadResponse($export['content'], $export['fileName'], 'application/json; charset=UTF-8');
	}

	/** 0.75.3: JSON einspielen – mode REPLACE (Schrittliste dieser Vorlage ersetzen) oder NEW (neue, inaktive Vorlage). */
	#[NoAdminRequired]
	public function importProcessTemplateJson(string $key, string $content = '', string $mode = 'REPLACE'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->process->importJson($content, $mode === 'NEW' ? 'NEW' : 'REPLACE', $key));
	}

	#[NoAdminRequired]
	public function importProcessTemplate(string $key, string $content = ''): DataResponse {
		$this->teamService->requireBestatterAdmin();
		if (strlen($content) > 2 * 1024 * 1024) throw new \InvalidArgumentException('Die CSV-Datei ist zu groß (höchstens 2 MB).');
		return new DataResponse($this->process->importCsv($key, $content));
	}
}
