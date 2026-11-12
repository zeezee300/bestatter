<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IDBConnection;

/** Vollständiger, maschinenlesbarer Datenexport eines Falls ohne externe Nebenwirkungen. */
class CaseExportService {
	public function __construct(
		private IDBConnection $db,
		private CaseService $cases,
		private AuditService $audit,
		private LabelService $labels,
	) {
	}

	public function export(int $caseId): array {
		$case = $this->cases->getCase($caseId);
		$invoices = $this->rows('bestatter_invoices', 'case_id', $caseId);
		$incoming = $this->rows('bestatter_incoming_invoices', 'case_id', $caseId);
		$externalDocuments = $this->rows('bestatter_external_documents', 'case_id', $caseId);
		$result = [
			'format' => 'bestatter-case-export/2',
			'appVersion' => Application::VERSION,
			'exportedAt' => date(DATE_ATOM),
			'case' => $case,
			'records' => $this->decodedRows($this->rows('bestatter_records', 'case_id', $caseId), ['payload']),
			// 0.75.1: Nebenaufträge (weitere Auftraggeber) mit Kopfdaten und Status; ihre Positionen und Rechnungen
			// stehen mit side_order_id in „services“ bzw. „invoices“.
			'sideOrders' => $this->rows('bestatter_side_orders', 'case_id', $caseId),
			'services' => $this->rows('bestatter_case_services', 'case_id', $caseId),
			'commercialDocuments' => $this->decodedRows($this->rows('bestatter_commercial_docs', 'case_id', $caseId), ['snapshot']),
			'invoices' => $this->decodedRows($invoices, ['recipient', 'billing_check', 'closure_manifest']),
			'invoiceItems' => $this->childRows('bestatter_invoice_items', 'invoice_id', $invoices),
			'incomingInvoices' => $incoming,
			'incomingItems' => $this->childRows('bestatter_incoming_items', 'incoming_invoice_id', $incoming),
			'externalDocuments' => $this->decodedRows($externalDocuments, ['metadata_json']),
			'integrationJobs' => $this->childRows('bestatter_integration_jobs', 'object_id', $externalDocuments),
			'orderFormEntries' => $this->decodedRows($this->rows('bestatter_order_form_entries', 'case_id', $caseId), ['values_json', 'line_services_json']),
			'captureImports' => $this->decodedRows($this->rows('bestatter_capture_imports', 'case_id', $caseId), ['suggestions_json']),
			// 0.76.0: Marker (auch entfernte, mit Zeitpunkten) und die daraus berechneten Hinweise.
			'labels' => $this->labels->exportRows($caseId),
			// 0.89.7: Vorsorge – Vertragsstand und Finanzierungen
			'precare' => $this->rows('bestatter_precare', 'case_id', $caseId),
			'precareFundings' => $this->rows('bestatter_precare_funding', 'case_id', $caseId),
			'hints' => $this->labels->forCase($caseId)['hints'],
			'auditHistory' => $this->audit->caseHistory($caseId),
		];
		$this->audit->log($caseId, 'CASE_EXPORT', $caseId, 'EXPORTED', null, ['format' => $result['format']]);
		return $result;
	}

	/**
	 * 0.91.0: Packt die Exportdateien in ein ZIP (im Speicher über eine temporäre Datei).
	 * @param array<string, string> $files Dateiname => Inhalt
	 */
	public static function zip(array $files): string {
		if (!class_exists(\ZipArchive::class)) {
			throw new \RuntimeException('Die PHP-Erweiterung ZipArchive ist für den Fallexport erforderlich.');
		}
		$path = tempnam(sys_get_temp_dir(), 'bestatter-export-');
		if ($path === false) {
			throw new \RuntimeException('Der Fallexport konnte nicht vorbereitet werden.');
		}
		try {
			$zip = new \ZipArchive();
			if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
				throw new \RuntimeException('Der Fallexport konnte nicht vorbereitet werden.');
			}
			foreach ($files as $name => $content) {
				$zip->addFromString((string)$name, $content);
			}
			$zip->close();
			return (string)file_get_contents($path);
		} finally {
			@unlink($path);
		}
	}

	private function rows(string $table, string $field, int $value): array {
		$query = $this->db->getQueryBuilder();
		return $query->select('*')->from($table)->where($query->expr()->eq($field, $query->createNamedParameter($value)))->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
	}

	private function childRows(string $table, string $field, array $parents): array {
		$result = [];
		foreach ($parents as $parent) {
			foreach ($this->rows($table, $field, (int)$parent['id']) as $row) {
				$result[] = $row;
			}
		}
		return $result;
	}

	private function decodedRows(array $rows, array $fields): array {
		foreach ($rows as &$row) {
			foreach ($fields as $field) {
				if (!isset($row[$field]) || !is_string($row[$field])) {
					continue;
				}
				try {
					$row[$field] = json_decode($row[$field], true, 512, JSON_THROW_ON_ERROR);
				} catch (\Throwable) {
				}
			}
		}
		return $rows;
	}
}
