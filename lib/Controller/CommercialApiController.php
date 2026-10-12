<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\ProcessService;
use OCA\Bestatter\Service\CaseService;
use OCA\Bestatter\Service\CommercialService;
use OCA\Bestatter\Service\DocumentService;
use OCA\Bestatter\Service\IncomingInvoiceService;
use OCA\Bestatter\Service\RecordService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class CommercialApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private CommercialService $commercial, private DocumentService $documents, private CaseService $caseService, private RecordService $recordService, private IncomingInvoiceService $incomingInvoices, private ProcessService $process) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function commercialOverview(int $caseId, ?int $sideOrderId = null): DataResponse { return new DataResponse($this->commercial->overview($caseId, $sideOrderId)); }

	#[NoAdminRequired]
	public function finalizationCheck(int $caseId, string $documentType = 'ORDER', string $data = '{}'): DataResponse { return new DataResponse($this->commercial->finalizationCheck($caseId, $documentType, json_decode($data, true, 512, JSON_THROW_ON_ERROR))); }

	#[NoAdminRequired]
	public function freezeQuote(int $caseId, string $data = '{}'): DataResponse {
		$quote = $this->commercial->freezeQuote($caseId, json_decode($data, true, 512, JSON_THROW_ON_ERROR));
		try {
			$file = $this->documents->generateTemplate($this->caseService->getCase($caseId), 'BESTATTUNGSAUFTRAG', 'FINAL', true);
			$record = $this->recordService->saveDocument($caseId, $file['title'], $file['status'], $file + ['commercialDocumentId' => $quote['id'], 'commercialDocumentType' => 'QUOTE']);
			$quote['pdf'] = $file['pdf'] ?? null;
			$quote['documentRecordId'] = $record['id'];
		} catch (\Throwable $error) {
			$quote['pdfWarning'] = 'Der KVA wurde festgeschrieben, die PDF-Ausgabe konnte jedoch nicht erzeugt werden: ' . $error->getMessage();
		}
		$this->process->reconcileQuietly($caseId);
		return new DataResponse($quote, 201);
	}

	#[NoAdminRequired]
	public function freezeOrder(int $caseId, string $data = '{}'): DataResponse {
		$order = $this->commercial->freezeOrder($caseId, json_decode($data, true, 512, JSON_THROW_ON_ERROR));
		try {
			$file = $this->documents->generateTemplate($this->caseService->getCase($caseId), 'BESTATTUNGSAUFTRAG', 'FINAL', true);
			$record = $this->recordService->saveDocument($caseId, $file['title'], $file['status'], $file + ['commercialDocumentId' => $order['id'], 'commercialDocumentType' => 'ORDER']);
			$order['pdf'] = $file['pdf'] ?? null;
			$order['documentRecordId'] = $record['id'];
		} catch (\Throwable $error) {
			$order['pdfWarning'] = 'Der Auftrag wurde festgeschrieben, die PDF-Ausgabe konnte jedoch nicht erzeugt werden: ' . $error->getMessage();
		}
		$this->process->reconcileQuietly($caseId);
		return new DataResponse($order, 201);
	}

	#[NoAdminRequired]
	public function convertQuoteToOrder(int $caseId, int $quoteId, string $data = '{}'): DataResponse {
		$result = $this->commercial->convertQuoteToOrder($caseId, $quoteId, json_decode($data, true, 512, JSON_THROW_ON_ERROR));
		$this->process->reconcileQuietly($caseId);
		return new DataResponse($result, 201);
	}

	#[NoAdminRequired]
	public function updateServiceLifecycle(int $caseId, int $serviceId, string $data = '{}'): DataResponse { return new DataResponse($this->commercial->updateService($caseId, $serviceId, json_decode($data, true, 512, JSON_THROW_ON_ERROR))); }

	#[NoAdminRequired]
	public function closeOrder(int $caseId, string $reason = '', string $expectedUpdatedAt = ''): DataResponse {
		$result = $this->commercial->closeOrderWithoutInvoice($caseId, $reason, $expectedUpdatedAt !== '' ? $expectedUpdatedAt : null);
		$this->process->reconcileQuietly($caseId);
		return new DataResponse($result);
	}

	#[NoAdminRequired]
	public function billingCheck(int $caseId, string $invoiceType = 'FINAL', ?int $sideOrderId = null): DataResponse { return new DataResponse($this->commercial->billingCheckForScope($caseId, $invoiceType, $sideOrderId)); }

	#[NoAdminRequired]
	public function createInvoice(int $caseId, string $data = '{}'): DataResponse {
		$result = $this->commercial->createInvoice($caseId, json_decode($data, true, 512, JSON_THROW_ON_ERROR));
		$this->process->reconcileQuietly($caseId);
		return new DataResponse($result, 201);
	}

	#[NoAdminRequired]
	public function transitionInvoice(int $id, string $status = '', string $reason = ''): DataResponse {
		$result = $this->commercial->transitionInvoice($id, $status, $reason);
		if ((int)($result['caseId'] ?? 0) > 0) $this->process->reconcileQuietly((int)$result['caseId']);
		return new DataResponse($result);
	}

	/** 0.70.0: Stornorechnung zu einer ausgestellten Rechnung (Entwurf mit eigener Nummer; wirksam mit Freigabe). */
	#[NoAdminRequired]
	public function createCancellation(int $id, string $reason = ''): DataResponse {
		$result = $this->commercial->createCancellation($id, $reason);
		$this->process->reconcileQuietly((int)$result['caseId']);
		return new DataResponse($result, 201);
	}

	/** 0.70.0: Gutschrift (Rechnungskorrektur) zu einzelnen Positionen einer ausgestellten Rechnung. */
	#[NoAdminRequired]
	public function createCreditNote(int $id, string $lines = '[]', string $reason = '', bool $releaseQuantities = false): DataResponse {
		$decoded = json_decode($lines, true, 512, JSON_THROW_ON_ERROR);
		$result = $this->commercial->createCreditNote($id, is_array($decoded) ? $decoded : [], $reason, $releaseQuantities);
		$this->process->reconcileQuietly((int)$result['caseId']);
		return new DataResponse($result, 201);
	}

	#[NoAdminRequired]
	public function invoicePaymentData(int $id): DataResponse {
		$invoice = $this->commercial->invoiceData($id);
		return new DataResponse($this->documents->invoicePaymentData($this->caseService->getCase($invoice['caseId']), $invoice));
	}

	#[NoAdminRequired]
	public function previewInvoiceDocument(int $id): DataDownloadResponse {
		$invoice = $this->commercial->invoiceData($id);
		$preview = $this->documents->previewInvoiceDocumentPdf($this->caseService->getCase($invoice['caseId']), $invoice);
		return new DataDownloadResponse($preview['content'], $preview['fileName'], 'application/pdf');
	}

	#[NoAdminRequired]
	public function generateInvoiceDocument(int $id, bool $createPdf = true): DataResponse {
		$invoice = $this->commercial->invoiceData($id);
		if ($invoice['status'] !== 'PRUEFUNG') throw new \InvalidArgumentException('Der verbindliche Rechnungsabschluss kann nur im Status „Prüfung“ erzeugt werden.');
		$file = $this->documents->generateInvoice($this->caseService->getCase($invoice['caseId']), $invoice, $createPdf);
		$this->commercial->storeClosureManifest($id, $file['closureManifest'] ?? []);
		// PRUEFUNG is deliberately replaceable: a failed/missing QR render may be repaired.
		// FREIGEGEBEN and later statuses are rejected above and remain immutable.
		$record = $this->recordService->saveDocument($invoice['caseId'], $file['title'], $file['status'], $file + ['invoiceId' => $id], true);
		return new DataResponse(['file' => $file, 'record' => $record], 201);
	}

	#[NoAdminRequired]
	public function incomingInvoices(int $caseId): DataResponse { return new DataResponse($this->incomingInvoices->overview($caseId)); }

	#[NoAdminRequired]
	public function createIncomingInvoice(int $caseId, string $data = '{}'): DataResponse {
		$upload = $this->apiRequest->getUploadedFile('file');
		$result = $this->incomingInvoices->create($caseId, json_decode($data, true, 512, JSON_THROW_ON_ERROR), is_array($upload) ? $upload : null);
		$this->process->reconcileQuietly($caseId);
		return new DataResponse($result, 201);
	}

	#[NoAdminRequired]
	public function updateIncomingInvoice(int $id, string $data = '{}'): DataResponse { return new DataResponse($this->incomingInvoices->update($id, json_decode($data, true, 512, JSON_THROW_ON_ERROR))); }

	#[NoAdminRequired]
	public function transferIncomingInvoice(int $id): DataResponse {
		$result = $this->incomingInvoices->transfer($id);
		if ((int)($result['caseId'] ?? 0) > 0) $this->process->reconcileQuietly((int)$result['caseId']);
		return new DataResponse($result);
	}

	#[NoAdminRequired]
	public function transitionIncomingInvoice(int $id, string $status = '', string $reason = ''): DataResponse {
		$result = $this->incomingInvoices->transition($id, $status, $reason);
		if ((int)($result['caseId'] ?? 0) > 0) $this->process->reconcileQuietly((int)$result['caseId']);
		return new DataResponse($result);
	}
}
