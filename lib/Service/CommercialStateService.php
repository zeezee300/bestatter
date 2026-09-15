<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IDBConnection;

/** Single source of truth for the editable/frozen state of an order or quote. */
class CommercialStateService {
	public function __construct(private IDBConnection $db) {}

	public function state(int $caseId, ?int $sideOrderId = null): array {
		$caseQuery = $this->db->getQueryBuilder();
		$masterJson = $caseQuery->select('master_data')->from('bestatter_cases')
			->where($caseQuery->expr()->eq('id', $caseQuery->createNamedParameter($caseId)))
			->executeQuery()->fetchOne();
		if ($masterJson === false) throw new \InvalidArgumentException('Fall wurde nicht gefunden.');
		$master = json_decode((string)$masterJson, true) ?: [];
		$storedStatus = trim((string)($master['order_status'] ?? 'Entwurf')) ?: 'Entwurf';
		if ($sideOrderId !== null) {
			$sideOrderQuery = $this->db->getQueryBuilder();
			$sideOrderStatus = $sideOrderQuery->select('status')->from('bestatter_side_orders')
				->where($sideOrderQuery->expr()->eq('id', $sideOrderQuery->createNamedParameter($sideOrderId)))
				->andWhere($sideOrderQuery->expr()->eq('case_id', $sideOrderQuery->createNamedParameter($caseId)))
				->executeQuery()->fetchOne();
			if ($sideOrderStatus === false) throw new \InvalidArgumentException('Nebenauftrag wurde nicht gefunden.');
			$storedStatus = strtoupper((string)$sideOrderStatus);
		}

		$documentQuery = $this->db->getQueryBuilder();
		$documents = $documentQuery->select('id', 'document_type', 'document_number', 'status', 'snapshot', 'created_at')
			->from('bestatter_commercial_docs')
			->where($documentQuery->expr()->eq('case_id', $documentQuery->createNamedParameter($caseId)))
			->andWhere($sideOrderId === null ? $documentQuery->expr()->isNull('side_order_id') : $documentQuery->expr()->eq('side_order_id', $documentQuery->createNamedParameter($sideOrderId)))
			->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')
			->executeQuery()->fetchAllAssociative();
		$document = null;
		foreach ($documents as $candidate) if ((string)$candidate['document_type'] === 'ORDER') { $document = $candidate; break; }
		if ($document === null) foreach ($documents as $candidate) if ((string)$candidate['document_type'] === 'QUOTE' && (string)$candidate['status'] !== 'UEBERNOMMEN') { $document = $candidate; break; }

		$invoiceQuery = $this->db->getQueryBuilder();
		$invoiceCount = (int)$invoiceQuery->select($invoiceQuery->func()->count('*', 'count'))->from('bestatter_invoices')
			->where($invoiceQuery->expr()->eq('case_id', $invoiceQuery->createNamedParameter($caseId)))
			->andWhere($sideOrderId === null ? $invoiceQuery->expr()->isNull('side_order_id') : $invoiceQuery->expr()->eq('side_order_id', $invoiceQuery->createNamedParameter($sideOrderId)))
			->andWhere($invoiceQuery->expr()->neq('status', $invoiceQuery->createNamedParameter('STORNIERT')))
			->executeQuery()->fetchOne();
		$finalInvoiceQuery = $this->db->getQueryBuilder();
		$hasActiveFinalInvoice = (int)$finalInvoiceQuery->select($finalInvoiceQuery->func()->count('*', 'count'))->from('bestatter_invoices')
			->where($finalInvoiceQuery->expr()->eq('case_id', $finalInvoiceQuery->createNamedParameter($caseId)))
			->andWhere($sideOrderId === null ? $finalInvoiceQuery->expr()->isNull('side_order_id') : $finalInvoiceQuery->expr()->eq('side_order_id', $finalInvoiceQuery->createNamedParameter($sideOrderId)))
			->andWhere($finalInvoiceQuery->expr()->eq('invoice_type', $finalInvoiceQuery->createNamedParameter('FINAL')))
			->andWhere($finalInvoiceQuery->expr()->neq('status', $finalInvoiceQuery->createNamedParameter('STORNIERT')))
			->executeQuery()->fetchOne() > 0;

		if ($sideOrderId !== null) {
			$effectiveStatus = match ($storedStatus) {
				'BEAUFTRAGT' => 'beauftragt',
				'ABGESCHLOSSEN' => 'abgeschlossen',
				'STORNIERT' => 'storniert',
				default => $invoiceCount > 0 ? 'beauftragt' : 'Entwurf',
			};
		} else {
			$effectiveStatus = ($document !== null && (string)$document['document_type'] === 'ORDER') || $invoiceCount > 0
				? 'beauftragt'
				: ($document !== null ? 'KVA versendet' : (mb_strtolower($storedStatus) === 'storniert' ? 'storniert' : 'Entwurf'));
		}
		$active = $document !== null || $invoiceCount > 0 || ($sideOrderId !== null && $storedStatus !== 'ENTWURF');
		$editable = !$active && $effectiveStatus === 'Entwurf';
		$snapshot = $document !== null ? (json_decode((string)$document['snapshot'], true) ?: []) : [];
		$reason = $hasActiveFinalInvoice
			? 'Da bereits eine aktive Schlussrechnung angelegt wurde, sind Vertragsänderungen gesperrt.'
			: ($invoiceCount > 0
				? 'Teilrechnung vorhanden: Nicht fakturierte Positionen können weiterhin ausschließlich als begründeter Vertragsnachtrag ergänzt oder geändert werden.'
				: ($document !== null
					? 'Das kaufmännische Dokument ist festgeschrieben.'
					: ($effectiveStatus === 'storniert' ? 'Der Auftrag ist storniert.' : ($effectiveStatus === 'abgeschlossen' ? 'Der Auftrag ist abgeschlossen.' : ($active ? 'Der Nebenauftrag ist beauftragt; Änderungen sind nur als begründeter Nachtrag möglich.' : 'Der Auftrag ist ein bearbeitbarer Entwurf.')))));

		return [
			'active' => $active,
			'sideOrderId' => $sideOrderId,
			'editable' => $editable,
			'amendmentsAllowed' => !$hasActiveFinalInvoice && ($document !== null || ($sideOrderId !== null && $storedStatus === 'BEAUFTRAGT')),
			'documentId' => $document !== null ? (int)$document['id'] : null,
			'documentType' => $document['document_type'] ?? null,
			'documentNumber' => (string)($document['document_number'] ?? ''),
			'documentStatus' => (string)($document['status'] ?? ''),
			'validUntil' => (string)($snapshot['validUntil'] ?? ''),
			'hasInvoice' => $invoiceCount > 0,
			'hasActiveFinalInvoice' => $hasActiveFinalInvoice,
			'invoiceCount' => $invoiceCount,
			'storedOrderStatus' => $storedStatus,
			'effectiveOrderStatus' => $effectiveStatus,
			'statusInconsistent' => $sideOrderId === null && mb_strtolower($storedStatus) !== mb_strtolower($effectiveStatus),
			'reason' => $reason,
		];
	}
}
