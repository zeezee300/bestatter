<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IDBConnection;
use OCP\IUserSession;

/** Incoming supplier invoices with an explicit, auditable transfer into case billing. */
class IncomingInvoiceService {
	private const STATUSES = ['ENTWURF', 'PRUEFUNG', 'ZUGEORDNET', 'FREIGEGEBEN', 'ABGELEHNT'];
	private const CLASSIFICATIONS = ['TRUE_PASS_THROUGH', 'THIRD_PARTY_SERVICE', 'EXPENSE_FEE', 'NOT_BILLABLE', 'CLASSIFICATION_PENDING'];
	private const UNITS = ['STK', 'PAUSCHAL', 'STD', 'KM', 'TAG', 'KG', 'L'];

	public function __construct(
		private IDBConnection $db,
		private IUserSession $userSession,
		private CaseService $cases,
		private CaseFileService $caseFiles,
		private AuditService $audit,
		private InstallationConfigService $installationConfig,
		private PaperlessService $paperless,
	) {}

	public function overview(int $caseId): array {
		$this->cases->getCase($caseId);
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_incoming_invoices')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->orderBy('invoice_date', 'DESC')->addOrderBy('id', 'DESC')->executeQuery()->fetchAllAssociative();
		return [
			'items' => array_map(fn(array $row): array => $this->mapInvoice($row, true), $rows),
			'statuses' => self::STATUSES,
			'classifications' => self::CLASSIFICATIONS,
			'units' => self::UNITS,
		];
	}

	public function create(int $caseId, array $input, ?array $upload = null, string $sourceSystem = 'BESTATTER_UPLOAD', ?string $sourceReference = null, bool $queuePaperless = true): array {
		$case = $this->cases->getCase($caseId);
		if ($upload === null) throw new \InvalidArgumentException('Bitte den Originalbeleg der Eingangsrechnung auswählen.');
		$validated = $this->validate($input);
		$hash = $this->uploadHash($upload);
		$this->assertUnique($validated['supplierNormalized'], $validated['supplierInvoiceNumber'], $hash);
		$file = null;
		$file = $this->caseFiles->upload($case, $upload, $this->installationConfig->billingFolder(), 'Rechnung', 'Eingangsrechnung ' . $validated['supplierInvoiceNumber'] . ' – ' . $validated['supplierName']);
		$now = date('c');
		$this->db->beginTransaction();
		try {
			$query = $this->db->getQueryBuilder();
			$query->insert('bestatter_incoming_invoices')->values([
				'case_id' => $query->createNamedParameter($caseId), 'supplier_name' => $query->createNamedParameter($validated['supplierName']),
				'supplier_normalized' => $query->createNamedParameter($validated['supplierNormalized']), 'supplier_invoice_no' => $query->createNamedParameter($validated['supplierInvoiceNumber']),
				'invoice_date' => $query->createNamedParameter($validated['invoiceDate']), 'received_date' => $query->createNamedParameter($validated['receivedDate']),
				'due_date' => $query->createNamedParameter($validated['dueDate'] ?: null), 'currency' => $query->createNamedParameter('EUR'),
				'status' => $query->createNamedParameter('ENTWURF'), 'declared_gross_cents' => $query->createNamedParameter($validated['declaredGrossCents']),
				'net_cents' => $query->createNamedParameter($validated['totals']['netCents']), 'vat_cents' => $query->createNamedParameter($validated['totals']['vatCents']), 'gross_cents' => $query->createNamedParameter($validated['totals']['grossCents']),
				'file_id' => $query->createNamedParameter($file['file']['fileId'] ?? null), 'document_record_id' => $query->createNamedParameter($file['record']['id'] ?? null),
				'file_path' => $query->createNamedParameter($file['file']['path'] ?? null), 'document_sha256' => $query->createNamedParameter($hash),
				'source_system' => $query->createNamedParameter($sourceSystem), 'source_reference' => $query->createNamedParameter($sourceReference),
				'notes' => $query->createNamedParameter($validated['notes']), 'created_by' => $query->createNamedParameter($this->uid()), 'updated_by' => $query->createNamedParameter($this->uid()),
				'created_at' => $query->createNamedParameter($now), 'updated_at' => $query->createNamedParameter($now),
			])->executeStatement();
			$id = (int)$this->db->lastInsertId('bestatter_incoming_invoices');
			$this->replaceItems($id, $validated['items']);
			$invoice = $this->invoice($id);
			$this->audit->log($caseId, 'INCOMING_INVOICE', $id, 'CREATED', null, $invoice);
			$this->db->commit();
		} catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
		if ($queuePaperless) {
			try { $invoice['paperless'] = $this->paperless->enqueueIncomingInvoice($invoice); }
			catch (\Throwable) { $invoice['paperlessWarning'] = 'Der Beleg wurde vollständig in Nextcloud gespeichert, konnte aber noch nicht an Paperless übergeben werden. Die Übergabe kann später erneut gestartet werden.'; }
		}
		return $invoice;
	}

	public function update(int $id, array $input): array {
		$before = $this->invoice($id);
		if (!in_array($before['status'], ['ENTWURF', 'PRUEFUNG'], true)) throw new \InvalidArgumentException('Diese Eingangsrechnung ist nicht mehr bearbeitbar.');
		$validated = $this->validate($input);
		$this->assertUnique($validated['supplierNormalized'], $validated['supplierInvoiceNumber'], null, $id);
		$this->db->beginTransaction();
		try {
			$this->lockInvoice($id);
			$before = $this->invoice($id);
			if (!in_array($before['status'], ['ENTWURF', 'PRUEFUNG'], true)) throw new \InvalidArgumentException('Diese Eingangsrechnung ist nicht mehr bearbeitbar.');
			$query = $this->db->getQueryBuilder();
			$query->update('bestatter_incoming_invoices')->set('supplier_name', $query->createNamedParameter($validated['supplierName']))
				->set('supplier_normalized', $query->createNamedParameter($validated['supplierNormalized']))->set('supplier_invoice_no', $query->createNamedParameter($validated['supplierInvoiceNumber']))
				->set('invoice_date', $query->createNamedParameter($validated['invoiceDate']))->set('received_date', $query->createNamedParameter($validated['receivedDate']))
				->set('due_date', $query->createNamedParameter($validated['dueDate'] ?: null))->set('declared_gross_cents', $query->createNamedParameter($validated['declaredGrossCents']))
				->set('net_cents', $query->createNamedParameter($validated['totals']['netCents']))->set('vat_cents', $query->createNamedParameter($validated['totals']['vatCents']))->set('gross_cents', $query->createNamedParameter($validated['totals']['grossCents']))
				->set('notes', $query->createNamedParameter($validated['notes']))->set('updated_by', $query->createNamedParameter($this->uid()))->set('updated_at', $query->createNamedParameter(date('c')))
				->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeStatement();
			$query = $this->db->getQueryBuilder();
			$transferred = (int)$query->select($query->func()->count('*', 'count'))->from('bestatter_incoming_items')->where($query->expr()->eq('incoming_invoice_id', $query->createNamedParameter($id)))->andWhere($query->expr()->neq('transfer_status', $query->createNamedParameter('OFFEN')))->executeQuery()->fetchOne();
			if ($transferred > 0) throw new \InvalidArgumentException('Nach der Übernahme einer Position dürfen die Rechnungspositionen nicht mehr ersetzt werden.');
			$query = $this->db->getQueryBuilder(); $query->delete('bestatter_incoming_items')->where($query->expr()->eq('incoming_invoice_id', $query->createNamedParameter($id)))->executeStatement();
			$this->replaceItems($id, $validated['items']);
			$after = $this->invoice($id);
			$this->audit->log($after['caseId'], 'INCOMING_INVOICE', $id, 'UPDATED', $before, $after);
			$this->db->commit();
		} catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
		return $after;
	}

	public function transfer(int $id): array {
		$before = $this->invoice($id);
		if (!in_array($before['status'], ['ENTWURF', 'PRUEFUNG'], true)) throw new \InvalidArgumentException('Positionen können in diesem Status nicht übernommen werden.');
		if (!$before['balanced']) throw new \InvalidArgumentException('Rechnungssumme und Positionssumme müssen vor der Übernahme übereinstimmen.');
		$this->db->beginTransaction();
		try {
			$this->lockInvoice($id);
			$before = $this->invoice($id);
			if (!in_array($before['status'], ['ENTWURF', 'PRUEFUNG'], true)) throw new \InvalidArgumentException('Die Positionen dieser Eingangsrechnung wurden bereits übernommen.');
			foreach ($before['items'] as $item) {
				if ($item['transferStatus'] !== 'OFFEN') continue;
				if ($item['classification'] === 'CLASSIFICATION_PENDING') throw new \InvalidArgumentException('Alle Positionen benötigen vor der Übernahme eine fachliche Klassifikation.');
				if ($item['classification'] === 'NOT_BILLABLE') { $this->markTransferred($item['id'], 'IGNORIERT', null); continue; }
				$serviceId = $item['caseServiceId'] > 0 ? $this->linkExistingService($before['caseId'], $item) : $this->createService($before, $item);
				$this->markTransferred($item['id'], 'UEBERNOMMEN', $serviceId);
			}
			$query = $this->db->getQueryBuilder(); $query->update('bestatter_incoming_invoices')->set('status', $query->createNamedParameter('ZUGEORDNET'))->set('updated_by', $query->createNamedParameter($this->uid()))->set('updated_at', $query->createNamedParameter(date('c')))->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeStatement();
			$after = $this->invoice($id);
			$this->audit->log($after['caseId'], 'INCOMING_INVOICE', $id, 'TRANSFERRED', $before, $after);
			$this->db->commit();
		} catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
		return $after;
	}

	public function transition(int $id, string $target, string $reason = ''): array {
		$before = $this->invoice($id); $target = strtoupper(trim($target));
		$allowed = ['ENTWURF' => ['PRUEFUNG', 'ABGELEHNT'], 'PRUEFUNG' => ['ENTWURF', 'ABGELEHNT'], 'ZUGEORDNET' => ['FREIGEGEBEN']];
		if (!in_array($target, $allowed[$before['status']] ?? [], true)) throw new \InvalidArgumentException('Dieser Statuswechsel ist für die Eingangsrechnung nicht zulässig.');
		if ($target === 'ABGELEHNT' && trim($reason) === '') throw new \InvalidArgumentException('Für die Ablehnung ist eine Begründung erforderlich.');
		$this->db->beginTransaction();
		try {
			$this->lockInvoice($id);
			$query = $this->db->getQueryBuilder(); $affected = $query->update('bestatter_incoming_invoices')->set('status', $query->createNamedParameter($target))->set('notes', $query->createNamedParameter(trim($reason) !== '' ? trim($reason) : $before['notes']))->set('updated_by', $query->createNamedParameter($this->uid()))->set('updated_at', $query->createNamedParameter(date('c')))->where($query->expr()->eq('id', $query->createNamedParameter($id)))->andWhere($query->expr()->eq('status', $query->createNamedParameter($before['status'])))->executeStatement();
			if ($affected !== 1) throw new \InvalidArgumentException('Der Status der Eingangsrechnung wurde zwischenzeitlich geändert. Bitte die Ansicht aktualisieren.');
			$after = $this->invoice($id); $this->audit->log($after['caseId'], 'INCOMING_INVOICE', $id, 'STATUS_' . $target, $before, $after);
			$this->db->commit();
		} catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
		return $after;
	}

	private function validate(array $input): array {
		$supplier = trim((string)($input['supplierName'] ?? '')); $number = trim((string)($input['supplierInvoiceNumber'] ?? ''));
		if ($supplier === '' || $number === '') throw new \InvalidArgumentException('Lieferant und Lieferanten-Rechnungsnummer sind erforderlich.');
		$invoiceDate = $this->date((string)($input['invoiceDate'] ?? ''), 'Rechnungsdatum');
		$receivedDate = $this->date((string)($input['receivedDate'] ?? date('Y-m-d')), 'Eingangsdatum');
		$dueDate = trim((string)($input['dueDate'] ?? '')); if ($dueDate !== '') $dueDate = $this->date($dueDate, 'Fälligkeitsdatum');
		$declared = max(0, (int)($input['declaredGrossCents'] ?? 0)); if ($declared <= 0) throw new \InvalidArgumentException('Der Bruttorechnungsbetrag muss größer als 0 sein.');
		$items = []; $totals = ['netCents' => 0, 'vatCents' => 0, 'grossCents' => 0];
		foreach ((array)($input['items'] ?? []) as $index => $raw) {
			$description = trim((string)($raw['description'] ?? '')); if ($description === '') throw new \InvalidArgumentException('Jede Rechnungsposition benötigt eine Bezeichnung.');
			$quantity = max(1, (int)($raw['supplierQuantityMilli'] ?? 1000)); $unit = strtoupper((string)($raw['unit'] ?? 'STK')); if (!in_array($unit, self::UNITS, true)) throw new \InvalidArgumentException('Unzulässige Mengeneinheit.');
			$unitCents = max(0, (int)($raw['supplierUnitCents'] ?? 0)); $vatRate = (int)($raw['supplierVatRate'] ?? 19); if (!in_array($vatRate, [0, 7, 19], true)) throw new \InvalidArgumentException('Nur 0 %, 7 % oder 19 % Umsatzsteuer sind zulässig.');
			$net = (int)round($quantity * $unitCents / 1000); $vat = (int)round($net * $vatRate / 100); $gross = $net + $vat;
			$classification = strtoupper((string)($raw['classification'] ?? 'CLASSIFICATION_PENDING')); if (!in_array($classification, self::CLASSIFICATIONS, true)) throw new \InvalidArgumentException('Unzulässige fachliche Klassifikation.');
			$customerQuantity = max(1, (int)($raw['customerQuantityMilli'] ?? $quantity)); $customerUnit = max(0, (int)($raw['customerUnitCents'] ?? $unitCents)); $customerVat = (int)($raw['customerVatRate'] ?? $vatRate); if (!in_array($customerVat, [0, 7, 19], true)) throw new \InvalidArgumentException('Unzulässiger Umsatzsteuersatz für die Kundenposition.');
			$varianceReason = trim((string)($raw['varianceReason'] ?? ''));
			if (($customerQuantity !== $quantity || $customerUnit !== $unitCents || $customerVat !== $vatRate) && $varianceReason === '') throw new \InvalidArgumentException('Abweichende Kundenmenge, Verkaufspreise oder Steuersätze müssen begründet werden.');
			$items[] = compact('description', 'quantity', 'unit', 'unitCents', 'vatRate', 'net', 'vat', 'gross', 'classification', 'customerQuantity', 'customerUnit', 'customerVat', 'varianceReason') + ['caseServiceId' => max(0, (int)($raw['caseServiceId'] ?? 0)), 'position' => $index + 1];
			$totals['netCents'] += $net; $totals['vatCents'] += $vat; $totals['grossCents'] += $gross;
		}
		if ($items === []) throw new \InvalidArgumentException('Mindestens eine Rechnungsposition ist erforderlich.');
		return ['supplierName' => $supplier, 'supplierNormalized' => $this->normalizeSupplier($supplier), 'supplierInvoiceNumber' => $number, 'invoiceDate' => $invoiceDate, 'receivedDate' => $receivedDate, 'dueDate' => $dueDate, 'declaredGrossCents' => $declared, 'notes' => trim((string)($input['notes'] ?? '')), 'items' => $items, 'totals' => $totals];
	}

	private function replaceItems(int $invoiceId, array $items): void {
		foreach ($items as $item) { $query = $this->db->getQueryBuilder(); $query->insert('bestatter_incoming_items')->values([
			'incoming_invoice_id'=>$query->createNamedParameter($invoiceId),'position_no'=>$query->createNamedParameter($item['position']),'description'=>$query->createNamedParameter($item['description']),'supplier_quantity_milli'=>$query->createNamedParameter($item['quantity']),'unit'=>$query->createNamedParameter($item['unit']),'supplier_unit_cents'=>$query->createNamedParameter($item['unitCents']),'supplier_vat_rate'=>$query->createNamedParameter($item['vatRate']),'supplier_net_cents'=>$query->createNamedParameter($item['net']),'supplier_vat_cents'=>$query->createNamedParameter($item['vat']),'supplier_gross_cents'=>$query->createNamedParameter($item['gross']),'classification'=>$query->createNamedParameter($item['classification']),'case_service_id'=>$query->createNamedParameter($item['caseServiceId'] ?: null),'customer_quantity_milli'=>$query->createNamedParameter($item['customerQuantity']),'customer_unit_cents'=>$query->createNamedParameter($item['customerUnit']),'customer_vat_rate'=>$query->createNamedParameter($item['customerVat']),'variance_reason'=>$query->createNamedParameter($item['varianceReason'] ?: null),'transfer_status'=>$query->createNamedParameter('OFFEN'),'created_service_id'=>$query->createNamedParameter(null),'transferred_by'=>$query->createNamedParameter(null),'transferred_at'=>$query->createNamedParameter(null),
		])->executeStatement(); }
	}

	private function createService(array $invoice, array $item): int {
		$costType = $item['classification'] === 'THIRD_PARTY_SERVICE' ? 'THIRD_PARTY' : 'EXPENSE'; $now = date('c');
		$query = $this->db->getQueryBuilder(); $query->insert('bestatter_case_services')->values([
			'case_id'=>$query->createNamedParameter($invoice['caseId']),'article_id'=>$query->createNamedParameter(null),'source_package_id'=>$query->createNamedParameter(null),'source_package_name'=>$query->createNamedParameter(null),'article_number'=>$query->createNamedParameter('ER-' . $invoice['id'] . '-' . $item['position']),'title'=>$query->createNamedParameter($item['description']),'long_text'=>$query->createNamedParameter('Aus Eingangsrechnung ' . $invoice['supplierInvoiceNumber'] . ' von ' . $invoice['supplierName']),'article_group'=>$query->createNamedParameter($item['classification'] === 'TRUE_PASS_THROUGH' ? 'Durchlaufende Posten' : 'Fremdleistungen / Auslagen'),'cost_type'=>$query->createNamedParameter($costType),'quantity_milli'=>$query->createNamedParameter($item['customerQuantityMilli']),'unit'=>$query->createNamedParameter($item['unit']),'quantity_decimals'=>$query->createNamedParameter($item['unit']==='STK'?0:3),'unit_price_cents'=>$query->createNamedParameter($item['customerUnitCents']),'vat_rate'=>$query->createNamedParameter($item['customerVatRate']),'sort_order'=>$query->createNamedParameter(9000 + $item['position']),'origin'=>$query->createNamedParameter('INCOMING_INVOICE'),'service_status'=>$query->createNamedParameter('ABRECHENBAR'),'ordered_quantity_milli'=>$query->createNamedParameter($item['customerQuantityMilli']),'performed_quantity_milli'=>$query->createNamedParameter($item['customerQuantityMilli']),'invoiced_quantity_milli'=>$query->createNamedParameter(0),'billability'=>$query->createNamedParameter('ABRECHENBAR'),'classification_reason'=>$query->createNamedParameter($item['varianceReason'] ?: 'Übernahme aus geprüfter Eingangsrechnung.'),'performed_at'=>$query->createNamedParameter($now),'performed_by'=>$query->createNamedParameter($this->uid()),'created_at'=>$query->createNamedParameter($now),'updated_at'=>$query->createNamedParameter($now),
		])->executeStatement(); return (int)$this->db->lastInsertId('bestatter_case_services');
	}

	private function linkExistingService(int $caseId, array $item): int {
		$query = $this->db->getQueryBuilder(); $row = $query->select('*')->from('bestatter_case_services')->where($query->expr()->eq('id', $query->createNamedParameter($item['caseServiceId'])))->andWhere($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))->executeQuery()->fetchAssociative();
		if (!$row) throw new \InvalidArgumentException('Die zugeordnete Fallleistung wurde nicht gefunden.');
		if ((int)$row['invoiced_quantity_milli'] > 0 && ((int)$row['unit_price_cents'] !== $item['customerUnitCents'] || (int)$row['vat_rate'] !== $item['customerVatRate'])) throw new \InvalidArgumentException('Eine bereits fakturierte Fallleistung darf nicht über eine Eingangsrechnung preislich verändert werden.');
		$query = $this->db->getQueryBuilder(); $query->update('bestatter_case_services')->set('performed_quantity_milli', $query->createNamedParameter(max((int)$row['performed_quantity_milli'], $item['customerQuantityMilli'])))->set('service_status', $query->createNamedParameter('ABRECHENBAR'))->set('billability', $query->createNamedParameter('ABRECHENBAR'))->set('performed_at', $query->createNamedParameter(date('c')))->set('performed_by', $query->createNamedParameter($this->uid()))->set('classification_reason', $query->createNamedParameter($item['varianceReason'] ?: 'Lieferantenbeleg zugeordnet.'))->set('updated_at', $query->createNamedParameter(date('c')))->where($query->expr()->eq('id', $query->createNamedParameter($item['caseServiceId'])))->executeStatement();
		return $item['caseServiceId'];
	}

	private function markTransferred(int $itemId, string $status, ?int $serviceId): void { $query=$this->db->getQueryBuilder(); $affected=$query->update('bestatter_incoming_items')->set('transfer_status',$query->createNamedParameter($status))->set('created_service_id',$query->createNamedParameter($serviceId))->set('transferred_by',$query->createNamedParameter($this->uid()))->set('transferred_at',$query->createNamedParameter(date('c')))->where($query->expr()->eq('id',$query->createNamedParameter($itemId)))->andWhere($query->expr()->eq('transfer_status',$query->createNamedParameter('OFFEN')))->executeStatement(); if($affected!==1)throw new \InvalidArgumentException('Diese Eingangsrechnungsposition wurde bereits übernommen.'); }
	private function lockInvoice(int $id): void { $query=$this->db->getQueryBuilder();$query->select('id')->from('bestatter_incoming_invoices')->where($query->expr()->eq('id',$query->createNamedParameter($id)));if(method_exists($query,'forUpdate'))$query->forUpdate();if(!$query->executeQuery()->fetchOne())throw new \InvalidArgumentException('Eingangsrechnung wurde nicht gefunden.'); }
	private function invoice(int $id): array { $query=$this->db->getQueryBuilder(); $row=$query->select('*')->from('bestatter_incoming_invoices')->where($query->expr()->eq('id',$query->createNamedParameter($id)))->executeQuery()->fetchAssociative(); if(!$row)throw new \InvalidArgumentException('Eingangsrechnung wurde nicht gefunden.'); return $this->mapInvoice($row,true); }
	private function mapInvoice(array $row, bool $withItems): array {
		$items = [];
		if ($withItems) {
			$query = $this->db->getQueryBuilder();
			$items = array_map(fn(array $item): array => $this->mapItem($item), $query->select('*')->from('bestatter_incoming_items')->where($query->expr()->eq('incoming_invoice_id', $query->createNamedParameter((int)$row['id'])))->orderBy('position_no', 'ASC')->executeQuery()->fetchAllAssociative());
		}
		$difference = (int)$row['gross_cents'] - (int)$row['declared_gross_cents'];
		$pending = count(array_filter($items, static fn(array $item): bool => $item['classification'] === 'CLASSIFICATION_PENDING'));
		return ['id'=>(int)$row['id'],'caseId'=>(int)$row['case_id'],'supplierName'=>$row['supplier_name'],'supplierInvoiceNumber'=>$row['supplier_invoice_no'],'invoiceDate'=>(string)$row['invoice_date'],'receivedDate'=>(string)$row['received_date'],'dueDate'=>$row['due_date']!==null?(string)$row['due_date']:'','currency'=>$row['currency'],'status'=>$row['status'],'declaredGrossCents'=>(int)$row['declared_gross_cents'],'totals'=>['netCents'=>(int)$row['net_cents'],'vatCents'=>(int)$row['vat_cents'],'grossCents'=>(int)$row['gross_cents']],'differenceCents'=>$difference,'balanced'=>abs($difference)<=1,'pendingClassifications'=>$pending,'fileId'=>$row['file_id']!==null?(int)$row['file_id']:null,'documentRecordId'=>$row['document_record_id']!==null?(int)$row['document_record_id']:null,'filePath'=>$row['file_path'],'documentSha256'=>$row['document_sha256'],'sourceSystem'=>$row['source_system'],'sourceReference'=>$row['source_reference'],'paperless'=>$this->paperlessForInvoice((int)$row['id']),'notes'=>(string)($row['notes']??''),'createdBy'=>$row['created_by'],'updatedBy'=>$row['updated_by'],'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at'],'canEdit'=>in_array($row['status'],['ENTWURF','PRUEFUNG'],true),'canTransfer'=>in_array($row['status'],['ENTWURF','PRUEFUNG'],true)&&abs($difference)<=1&&$pending===0,'items'=>$items];
	}
	private function paperlessForInvoice(int $id): ?array {$q=$this->db->getQueryBuilder();$row=$q->select('id','external_document_id','sync_status','sync_error','last_synced_at')->from('bestatter_external_documents')->where($q->expr()->eq('provider',$q->createNamedParameter('PAPERLESS')))->andWhere($q->expr()->eq('record_type',$q->createNamedParameter('INCOMING_INVOICE')))->andWhere($q->expr()->eq('record_id',$q->createNamedParameter($id)))->orderBy('id','DESC')->setMaxResults(1)->executeQuery()->fetchAssociative();return $row?['id'=>(int)$row['id'],'documentId'=>$row['external_document_id'],'status'=>$row['sync_status'],'error'=>(string)($row['sync_error']??''),'lastSyncedAt'=>$row['last_synced_at']]:null;}
	private function mapItem(array $row): array { return ['id'=>(int)$row['id'],'position'=>(int)$row['position_no'],'description'=>$row['description'],'supplierQuantityMilli'=>(int)$row['supplier_quantity_milli'],'supplierQuantity'=>(int)$row['supplier_quantity_milli']/1000,'unit'=>$row['unit'],'supplierUnitCents'=>(int)$row['supplier_unit_cents'],'supplierVatRate'=>(int)$row['supplier_vat_rate'],'supplierNetCents'=>(int)$row['supplier_net_cents'],'supplierVatCents'=>(int)$row['supplier_vat_cents'],'supplierGrossCents'=>(int)$row['supplier_gross_cents'],'classification'=>$row['classification'],'caseServiceId'=>$row['case_service_id']!==null?(int)$row['case_service_id']:0,'customerQuantityMilli'=>(int)$row['customer_quantity_milli'],'customerQuantity'=>(int)$row['customer_quantity_milli']/1000,'customerUnitCents'=>(int)$row['customer_unit_cents'],'customerVatRate'=>(int)$row['customer_vat_rate'],'varianceReason'=>(string)($row['variance_reason']??''),'transferStatus'=>$row['transfer_status'],'createdServiceId'=>$row['created_service_id']!==null?(int)$row['created_service_id']:null,'transferredBy'=>$row['transferred_by'],'transferredAt'=>$row['transferred_at']]; }
	private function assertUnique(string $supplier, string $number, ?string $hash, int $excludeId=0): void { $query=$this->db->getQueryBuilder();$query->select('id')->from('bestatter_incoming_invoices')->where($query->expr()->eq('supplier_normalized',$query->createNamedParameter($supplier)))->andWhere($query->expr()->eq('supplier_invoice_no',$query->createNamedParameter($number)));if($excludeId>0)$query->andWhere($query->expr()->neq('id',$query->createNamedParameter($excludeId)));if($query->executeQuery()->fetchOne())throw new \InvalidArgumentException('Diese Lieferanten-Rechnungsnummer wurde bereits erfasst.');if($hash!==null){$query=$this->db->getQueryBuilder();if($query->select('id')->from('bestatter_incoming_invoices')->where($query->expr()->eq('document_sha256',$query->createNamedParameter($hash)))->executeQuery()->fetchOne())throw new \InvalidArgumentException('Diese Belegdatei wurde bereits als Eingangsrechnung erfasst.');} }
	private function normalizeSupplier(string $value): string { $value=mb_strtolower(trim($value));return preg_replace('/[^a-z0-9äöüß]+/u','',$value)??$value; }
	private function uploadHash(?array $upload): ?string { if($upload===null)return null;$path=(string)($upload['tmp_name']??'');if($path===''||!is_file($path))throw new \InvalidArgumentException('Die Belegdatei ist nicht verfügbar.');return hash_file('sha256',$path)?:null; }
	private function date(string $value,string $label): string { $date=\DateTimeImmutable::createFromFormat('!Y-m-d',trim($value));if(!$date||$date->format('Y-m-d')!==trim($value))throw new \InvalidArgumentException($label.' ist ungültig.');return $date->format('Y-m-d'); }
	private function uid(): string { return $this->userSession->getUser()?->getUID()??'system'; }
}
