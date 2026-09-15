<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IDBConnection;

/** Vollständiger, maschinenlesbarer Datenexport eines Falls ohne externe Nebenwirkungen. */
class CaseExportService {
	public function __construct(private IDBConnection $db, private CaseService $cases, private AuditService $audit) {}

	public function export(int $caseId): array {
		$case = $this->cases->getCase($caseId);
		$invoices = $this->rows('bestatter_invoices', 'case_id', $caseId);
		$incoming = $this->rows('bestatter_incoming_invoices', 'case_id', $caseId);
		$externalDocuments = $this->rows('bestatter_external_documents', 'case_id', $caseId);
		$result = [
			'format' => 'bestatter-case-export/1',
			'appVersion' => Application::VERSION,
			'exportedAt' => date(DATE_ATOM),
			'case' => $case,
			'records' => $this->decodedRows($this->rows('bestatter_records', 'case_id', $caseId), ['payload']),
			'services' => $this->rows('bestatter_case_services', 'case_id', $caseId),
			'commercialDocuments' => $this->decodedRows($this->rows('bestatter_commercial_docs', 'case_id', $caseId), ['snapshot']),
			'invoices' => $this->decodedRows($invoices, ['recipient', 'billing_check', 'closure_manifest']),
			'invoiceItems' => $this->childRows('bestatter_invoice_items', 'invoice_id', $invoices),
			'incomingInvoices' => $incoming,
			'incomingItems' => $this->childRows('bestatter_incoming_items', 'incoming_invoice_id', $incoming),
			'externalDocuments' => $this->decodedRows($externalDocuments, ['metadata_json']),
			'integrationJobs' => $this->childRows('bestatter_integration_jobs', 'object_id', $externalDocuments),
			'captureImports' => $this->decodedRows($this->rows('bestatter_capture_imports', 'case_id', $caseId), ['suggestions_json']),
			'auditHistory' => $this->audit->caseHistory($caseId),
		];
		$this->audit->log($caseId, 'CASE_EXPORT', $caseId, 'EXPORTED', null, ['format' => $result['format']]);
		return $result;
	}

	private function rows(string $table, string $field, int $value): array {
		$query = $this->db->getQueryBuilder();
		return $query->select('*')->from($table)->where($query->expr()->eq($field, $query->createNamedParameter($value)))->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
	}

	private function childRows(string $table, string $field, array $parents): array {
		$result = [];
		foreach ($parents as $parent) foreach ($this->rows($table, $field, (int)$parent['id']) as $row) $result[] = $row;
		return $result;
	}

	private function decodedRows(array $rows, array $fields): array {
		foreach ($rows as &$row) foreach ($fields as $field) {
			if (!isset($row[$field]) || !is_string($row[$field])) continue;
			try { $row[$field] = json_decode($row[$field], true, 512, JSON_THROW_ON_ERROR); } catch (\Throwable) {}
		}
		return $rows;
	}
}
