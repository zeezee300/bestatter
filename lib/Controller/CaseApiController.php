<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\CaseExportService;
use OCA\Bestatter\Service\CaseNoteRules;
use OCA\Bestatter\Service\CaseNoteService;
use OCA\Bestatter\Service\CaseService;
use OCA\Bestatter\Service\CustomizingService;
use OCA\Bestatter\Service\LabelService;
use OCA\Bestatter\Service\OperationalService;
use OCA\Bestatter\Service\ProcessService;
use OCA\Bestatter\Service\TeamService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class CaseApiController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CaseService $caseService,
		private CustomizingService $customizingService,
		private OperationalService $operations,
		private TeamService $teamService,
		private CaseExportService $caseExport,
		private ProcessService $process,
		private LabelService $labels,
		private ?CaseNoteService $caseNotes = null,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function listCases(): DataResponse {
		return new DataResponse($this->caseService->listCases());
	}

	#[NoAdminRequired]
	public function searchCases(string $query = '', string $status = 'ALL', string $branch = 'ALL', string $responsible = 'ALL', int $limit = 25, int $offset = 0, string $sideOrders = 'ALL', string $labels = '', string $kind = 'ALL'): DataResponse {
		return new DataResponse($this->caseService->searchCases($query, $status, $branch, $responsible, $limit, $offset, $sideOrders, $labels, $kind));
	}

	#[NoAdminRequired]
	public function createCase(string $firstName = '', string $lastName = '', string $dateOfDeath = '', string $funeralType = '', string $branch = '', string $responsibleEmployee = '', string $masterData = '', string $creationToken = '', string $labels = '', string $caseKind = '', string $orderType = '', string $captureNote = ''): DataResponse {
		$this->customizingService->ensureSeedData();
		$data = $masterData !== '' ? json_decode($masterData, true, 512, JSON_THROW_ON_ERROR) : [];
		// 0.91.1: Die Gesprächsnotiz der Schnellerfassung wird eine Notiz (Kategorie „Gesprächsnotiz“), kein Stammdatenfeld mehr.
		$captureNote = trim($captureNote !== '' ? $captureNote : (is_array($data) && is_scalar($data['notes'] ?? null) ? (string)$data['notes'] : ''));
		if (is_array($data)) {
			unset($data['notes']);
		}
		// 0.89.7: caseKind STERBEFALL (Standard), AUFTRAG oder VORSORGE; orderType = Auftragsart.
		$case = $this->caseService->createCase(compact('firstName', 'lastName', 'dateOfDeath', 'funeralType', 'branch', 'responsibleEmployee', 'creationToken', 'caseKind', 'orderType') + ['masterData' => $data]);
		if (empty($case['idempotent']) && $captureNote !== '' && $this->caseNotes !== null) {
			try {
				$case['captureNotes'] = count($this->caseNotes->createSystemNotes((int)$case['id'], $captureNote, CaseNoteRules::CATEGORY_CONVERSATION, CaseNoteRules::SOURCE_CAPTURE));
			} catch (\Throwable $error) {
				$case['captureNoteWarning'] = 'Die Gesprächsnotiz konnte nicht gespeichert werden: ' . $error->getMessage();
			}
		}
		// 0.76.0: Marker gleich bei der Anlage (z. B. Kostenträger aus dem Erstgespräch); Fehler nur als Hinweis.
		if (empty($case['idempotent']) && $labels !== '') {
			$case['labelWarnings'] = $this->labels->setInitial((int)$case['id'], (array)json_decode($labels, true));
		}
		// 0.66.0: Die ersten Schritte des Standardablaufs entstehen direkt mit der Fallanlage.
		if (empty($case['idempotent'])) {
			$case['process'] = $this->process->reconcileQuietly((int)$case['id']);
		}
		$case += $this->labels->forCase((int)$case['id']);
		return new DataResponse($case, 201);
	}

	#[NoAdminRequired]
	public function getCase(int $id): DataResponse {
		return new DataResponse($this->caseService->getCase($id) + $this->labels->forCase($id));
	}

	#[NoAdminRequired]
	public function caseCompleteness(int $id, string $phase = 'ALL'): DataResponse {
		return new DataResponse($this->operations->completeness($id, $phase));
	}

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
	public function ensureFolder(int $id): DataResponse {
		return new DataResponse($this->caseService->ensureFolder($id));
	}

	#[NoAdminRequired]
	public function exportCase(int $id): DataDownloadResponse {
		$data = $this->caseExport->export($id);
		$name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)($data['case']['caseNumber'] ?? ('Fall-' . $id)));
		return new DataDownloadResponse(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'Bestatter-Fallexport-' . $name . '.json', 'application/json; charset=UTF-8');
	}

	/**
	 * 0.91.0: „Falldaten exportieren“ als ZIP – der vollständige Fallexport (JSON) und die Notizen des Falls als lesbare
	 * Markdown-Datei (Notizen-<Fallnummer>.md).
	 */
	#[NoAdminRequired]
	public function exportCaseZip(int $id): DataDownloadResponse {
		$data = $this->caseExport->export($id);
		$name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)($data['case']['caseNumber'] ?? ('Fall-' . $id)));
		$files = ['Bestatter-Fallexport-' . $name . '.json' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
		if ($this->caseNotes !== null) {
			$notes = $this->caseNotes->markdown($id);
			$files[$notes['fileName']] = $notes['content'];
		}
		return new DataDownloadResponse(CaseExportService::zip($files), 'Bestatter-Fallexport-' . $name . '.zip', 'application/zip');
	}
}
