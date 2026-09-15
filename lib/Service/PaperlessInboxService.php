<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/** Überführt einen Paperless-Eingang kontrolliert in den bestehenden Fachprozess. */
class PaperlessInboxService {
	public function __construct(private PaperlessService $paperless, private IncomingInvoiceService $incomingInvoices) {}

	public function inbox(): array {
		return $this->paperless->inbox();
	}

	public function assign(int $externalId, int $caseId, array $data): array {
		$item = $this->paperless->inboxItem($externalId);
		$paperlessDocumentId = (string)$item['externalDocumentId'];
		$download = $this->paperless->downloadDocument($paperlessDocumentId);
		$temp = tempnam(sys_get_temp_dir(), 'bestatter-paperless-');
		if ($temp === false) throw new \RuntimeException('Für den Paperless-Import konnte keine temporäre Datei angelegt werden.');
		try {
			if (file_put_contents($temp, $download['content'], LOCK_EX) === false) throw new \RuntimeException('Der Paperless-Beleg konnte nicht temporär gespeichert werden.');
			$upload = [
				'name' => 'Paperless-Eingangsbeleg-' . $externalId . '.' . $download['extension'],
				'tmp_name' => $temp,
				'size' => strlen($download['content']),
				'error' => UPLOAD_ERR_OK,
				'type' => $download['mimeType'],
			];
			$invoice = $this->incomingInvoices->create($caseId, $data, $upload, 'PAPERLESS', $paperlessDocumentId, false);
			$link = $this->paperless->linkImported($externalId, $invoice);
			return ['invoice'=>$invoice, 'externalDocument'=>$link, 'nextStep'=>'Rechnungsdaten und Positionen prüfen. OCR-Vorschläge werden erst in der getrennt abzunehmenden Ausbaustufe 0.49 ergänzt.'];
		} finally {
			if (is_file($temp)) @unlink($temp);
		}
	}
}
