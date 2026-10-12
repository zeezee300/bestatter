<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\ProcessService;
use OCA\Bestatter\Service\CaseFileService;
use OCA\Bestatter\Service\CaseService;
use OCA\Bestatter\Service\DeregistrationService;
use OCA\Bestatter\Service\DocumentService;
use OCA\Bestatter\Service\RecordService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\IDBConnection;
use OCP\IRequest;

class DocumentApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private CaseService $caseService, private CaseFileService $caseFiles, private RecordService $recordService, private DocumentService $documents, private \OCA\Bestatter\Service\DocumentKindService $documentKinds, private DeregistrationService $deregistrations, private IDBConnection $db, private ProcessService $process) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function generateOrderDocuments(int $id, string $documentStatus = 'ENTWURF', bool $createPdf = true): DataResponse {
		if (strtoupper(trim($documentStatus)) !== 'ENTWURF') throw new \InvalidArgumentException('Dieser Vorgang erzeugt ausschließlich bearbeitbare Entwurfspakete.');
		$case = $this->caseService->getCase($id);
		$package = $this->documents->generateOrderDocumentDraftPackage($case, $createPdf);
		$this->db->beginTransaction();
		try {
			$records = [];
			foreach ($package['files'] as $file) $records[] = $this->recordService->saveDocument($id, $file['title'], $file['status'], $file);
			$this->db->commit();
		} catch (\Throwable $error) {
			try { $this->db->rollBack(); } catch (\Throwable) {}
			foreach ($package['files'] as $file) $this->documents->discardGeneratedOutput($file);
			throw new \RuntimeException('Das Entwurfspaket konnte nicht atomar gespeichert werden: ' . $error->getMessage(), 0, $error);
		}
		$cleanupWarnings = $this->documents->cleanupSupersededOrderDrafts($case, $package['files']);
		$this->process->reconcileQuietly($id);
		return new DataResponse(['packageId' => $package['packageId'], 'files' => $package['files'], 'records' => $records, 'cleanupWarnings' => $cleanupWarnings], 201);
	}

	#[NoAdminRequired]
	public function previewOrderDocument(int $id, string $templateKey): DataDownloadResponse {
		$preview = $this->documents->previewOrderDocumentPdf($this->caseService->getCase($id), $templateKey);
		return new DataDownloadResponse($preview['content'], $preview['fileName'], 'application/pdf');
	}

	#[NoAdminRequired]
	public function documentPreview(int $id, string $templateKey, int $scheduleId = 0): DataResponse { return new DataResponse($this->documents->preview($this->caseService->getCase($id), $templateKey, $scheduleId)); }

	#[NoAdminRequired]
	public function generateDocument(int $id, string $templateKey, string $documentStatus = 'ENTWURF', bool $createPdf = true, bool $allowIncomplete = false, int $scheduleId = 0): DataResponse {
		$file = $this->documents->generateTemplate($this->caseService->getCase($id), $templateKey, $documentStatus, $createPdf, $allowIncomplete, null, $scheduleId);
		try { $record = $this->recordService->saveDocument($id, $file['title'], $file['status'], $file); }
		catch (\Throwable $error) { $this->documents->discardGeneratedOutput($file); throw $error; }
		$this->process->reconcileQuietly($id);
		return new DataResponse(['file' => $file, 'record' => $record], 201);
	}

	#[NoAdminRequired]
	public function caseFiles(int $caseId): DataResponse { return new DataResponse($this->caseFiles->list($this->caseService->getCase($caseId))); }

	#[NoAdminRequired]
	public function allCaseFiles(): DataResponse { return new DataResponse($this->caseFiles->listAll($this->caseService->listCases())); }

	#[NoAdminRequired]
	public function displayCasePdf(int $caseId, int $fileId): FileDisplayResponse {
		$file = $this->caseFiles->pdf($this->caseService->getCase($caseId), $fileId);
		$fileName = str_replace(["\r", "\n", '"'], ['', '', "'"], $file->getName());
		$response = new FileDisplayResponse($file);
		$response->addHeader('Content-Type', 'application/pdf');
		$response->addHeader('Content-Disposition', 'inline; filename="' . $fileName . '"');
		$response->addHeader('Cache-Control', 'private, no-store');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		return $response;
	}

	#[NoAdminRequired]
	public function displayCaseFile(int $caseId, int $fileId): FileDisplayResponse {
		$file = $this->caseFiles->file($this->caseService->getCase($caseId), $fileId);
		$fileName = str_replace(["\r", "\n", '"'], ['', '', "'"], $file->getName());
		$response = new FileDisplayResponse($file);
		$response->addHeader('Content-Type', $file->getMimeType() ?: 'application/octet-stream');
		$response->addHeader('Content-Disposition', 'inline; filename="' . $fileName . '"');
		$response->addHeader('Cache-Control', 'private, no-store');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		return $response;
	}

	#[NoAdminRequired]
	public function uploadCaseFile(int $caseId, string $subfolder = '', string $documentType = 'Sonstiges', string $title = '', string $documentKind = ''): DataResponse {
		$upload = $this->apiRequest->getUploadedFile('file');
		if (!is_array($upload)) throw new \InvalidArgumentException('Bitte eine Datei auswählen.');
		$extra = [];
		// 0.80.0 (OP-058): Dokumentart – bestimmt die Kategorie; Ablage wie gewählt (vorbelegt mit dem Ordner der Art).
		if (trim($documentKind) !== '') {
			$kind = $this->documentKinds->activeDefinition($documentKind);
			if (in_array($kind['effect'], ['INCOMING_INVOICE', 'PAPER_CONTRACT'], true)) throw new \InvalidArgumentException($kind['effect'] === 'INCOMING_INVOICE' ? 'Eingangsrechnungen bitte im Reiter „Finanzen“ mit „+ Eingangsrechnung erfassen“ hochladen.' : 'Unterschriebene Verträge bitte unter „Handschriftlich unterschriebener Vertrag“ hochladen.');
			$documentType = $kind['category'];
			if (trim($subfolder) === '') $subfolder = $this->documentKinds->folderName($kind);
			$extra = ['documentKind' => $kind['key']];
		}
		$result = $this->caseFiles->upload($this->caseService->getCase($caseId), $upload, $subfolder, $documentType, $title, [], $extra);
		$this->process->reconcileQuietly($caseId);
		return new DataResponse($result, 201);
	}

	#[NoAdminRequired]
	public function uploadPaperContract(int $caseId, string $source = '', int $sourceRecordId = 0): DataResponse {
		$upload = $this->apiRequest->getUploadedFile('file');
		if (!is_array($upload)) throw new \InvalidArgumentException('Bitte einen PDF-Scan auswählen.');
		return new DataResponse($this->caseFiles->uploadPaperContract($this->caseService->getCase($caseId), $upload, $source, $sourceRecordId), 201);
	}

	#[NoAdminRequired]
	public function confirmPaperContract(int $caseId, int $recordId, string $review = '{}'): DataResponse {
		$result = $this->caseFiles->confirmPaperContract($this->caseService->getCase($caseId), $recordId, json_decode($review, true, 512, JSON_THROW_ON_ERROR));
		$this->process->reconcileQuietly($caseId);
		return new DataResponse($result);
	}

	#[NoAdminRequired]
	public function caseDeregistrations(int $caseId): DataResponse { return new DataResponse($this->deregistrations->list($caseId)); }

	#[NoAdminRequired]
	public function previewDeregistration(int $caseId, string $deregistration = '{}'): DataResponse { return new DataResponse($this->deregistrations->preview($caseId, json_decode($deregistration, true, 512, JSON_THROW_ON_ERROR))); }

	#[NoAdminRequired]
	public function createDeregistration(int $caseId, string $deregistration = '{}'): DataResponse {
		$result = $this->deregistrations->save($caseId, json_decode($deregistration, true, 512, JSON_THROW_ON_ERROR));
		$this->process->reconcileQuietly($caseId);
		return new DataResponse($result, 201);
	}

	#[NoAdminRequired]
	public function updateDeregistration(int $caseId, int $id, string $deregistration = '{}'): DataResponse {
		$result = $this->deregistrations->save($caseId, json_decode($deregistration, true, 512, JSON_THROW_ON_ERROR), $id);
		$this->process->reconcileQuietly($caseId);
		return new DataResponse($result);
	}

	#[NoAdminRequired]
	public function transitionDeregistration(int $id, string $status = '', string $data = '{}'): DataResponse {
		$result = $this->deregistrations->transition($id, $status, json_decode($data, true, 512, JSON_THROW_ON_ERROR));
		if ((int)($result['caseId'] ?? 0) > 0) $this->process->reconcileQuietly((int)$result['caseId']);
		return new DataResponse($result);
	}
}
