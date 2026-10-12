<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\ProcessService;
use OCA\Bestatter\Service\CaseService;
use OCA\Bestatter\Service\CustomizingService;
use OCA\Bestatter\Service\OperationalService;
use OCA\Bestatter\Service\CaseExportService;
use OCA\Bestatter\Service\LabelService;
use OCA\Bestatter\Service\TeamService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;

class CaseApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private CaseService $caseService, private CustomizingService $customizingService, private OperationalService $operations, private TeamService $teamService, private CaseExportService $caseExport, private ProcessService $process, private LabelService $labels) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function listCases(): DataResponse { return new DataResponse($this->caseService->listCases()); }

	#[NoAdminRequired]
	public function searchCases(string $query = '', string $status = 'ALL', string $branch = 'ALL', string $responsible = 'ALL', int $limit = 25, int $offset = 0, string $sideOrders = 'ALL', string $labels = ''): DataResponse { return new DataResponse($this->caseService->searchCases($query, $status, $branch, $responsible, $limit, $offset, $sideOrders, $labels)); }

	#[NoAdminRequired]
	public function createCase(string $firstName = '', string $lastName = '', string $dateOfDeath = '', string $funeralType = '', string $branch = '', string $responsibleEmployee = '', string $masterData = '', string $creationToken = '', string $labels = ''): DataResponse {
		$this->customizingService->ensureSeedData();
		$data = $masterData !== '' ? json_decode($masterData, true, 512, JSON_THROW_ON_ERROR) : [];
		$this->process->ensureSeedData(); // setzt ggf. den Einführungsstichtag, bevor der Fall entsteht
		$case = $this->caseService->createCase(compact('firstName', 'lastName', 'dateOfDeath', 'funeralType', 'branch', 'responsibleEmployee', 'creationToken') + ['masterData' => $data]);
		// 0.76.0: Marker gleich bei der Anlage (z. B. Kostenträger aus dem Erstgespräch); Fehler nur als Hinweis.
		if (empty($case['idempotent']) && $labels !== '') $case['labelWarnings'] = $this->labels->setInitial((int)$case['id'], (array)json_decode($labels, true));
		// 0.66.0: Die ersten Schritte des Standardablaufs entstehen direkt mit der Fallanlage.
		if (empty($case['idempotent'])) $case['process'] = $this->process->reconcileQuietly((int)$case['id']);
		$case += $this->labels->forCase((int)$case['id']);
		return new DataResponse($case, 201);
	}

	#[NoAdminRequired]
	public function getCase(int $id): DataResponse { return new DataResponse($this->caseService->getCase($id) + $this->labels->forCase($id)); }

	#[NoAdminRequired]
	public function caseCompleteness(int $id, string $phase = 'ALL'): DataResponse { return new DataResponse($this->operations->completeness($id, $phase)); }

	#[NoAdminRequired]
	public function deleteCase(int $id, bool $confirmed = false): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->caseService->deleteCase($id, $confirmed));
	}

	/** 0.75.1: Hinweise vor dem Löschen (laufende Aufbewahrungsfristen, Paperless) für den Bestätigungsdialog. */
	#[NoAdminRequired]
	public function caseDeletionCheck(int $id): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->caseService->deletionCheck($id));
	}

	#[NoAdminRequired]
	public function updateMasterData(int $id, string $masterData = '', string $expectedUpdatedAt = ''): DataResponse {
		$case = $this->caseService->updateMasterData($id, json_decode($masterData, true, 512, JSON_THROW_ON_ERROR), $expectedUpdatedAt !== '' ? $expectedUpdatedAt : null);
		$this->process->reconcileQuietly($id);
		return new DataResponse($case + $this->labels->forCase($id));
	}

	#[NoAdminRequired]
	public function ensureFolder(int $id): DataResponse { return new DataResponse($this->caseService->ensureFolder($id)); }

	#[NoAdminRequired]
	public function exportCase(int $id): DataDownloadResponse {
		$data = $this->caseExport->export($id);
		$name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)($data['case']['caseNumber'] ?? ('Fall-'.$id)));
		return new DataDownloadResponse(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'Bestatter-Fallexport-' . $name . '.json', 'application/json; charset=UTF-8');
	}
}
