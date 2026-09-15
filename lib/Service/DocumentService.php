<?php
declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\Files\Folder;
use OCP\Files\Conversion\IConversionManager;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserSession;

class DocumentService {
	/** Jurisdiction-dependent authority documents must not use a German fallback. */
	private const REGIONAL_TEMPLATE_REQUIRED = [
		'STERBEFALLANZEIGE_STANDESAMT',
	];
	private const STATUSES = ['ENTWURF', 'FINAL', 'UNTERSCHRIEBEN'];
	/** Only byte-identical former package defaults may be refreshed automatically. */
	private const REPLACEABLE_STANDARD_TEMPLATE_HASHES = [
		'BESTATTUNGSAUFTRAG.docx' => ['2aba502e61c9ee18af3be3da0926f903644cb1514e22f6fe614fea5f367792ad'],
		// Package version 0.58.0. Individually changed invoice templates remain untouched.
		'RECHNUNG.docx' => ['94695c36a21897c5b60ac269830ceaad3af40f1dcd327b4bfe07540859582f41'],
	];

	public function __construct(
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private FolderService $folders,
		private ArticleService $articles,
		private ConfigurationService $configuration,
		private IConversionManager $conversionManager,
		private EuroOfficeConversionService $euroOffice,
		private EInvoiceService $eInvoices,
		private EInvoiceComplianceService $eInvoiceCompliance,
		private IDBConnection $db,
		private InstallationConfigService $installationConfig,
		private CountryConfigurationService $countryConfiguration,
	) {}

	public function generateOrderDocuments(array $case, string $status = 'ENTWURF', bool $createPdf = true): array {
		$keys = $this->orderDocumentKeys($case);
		return array_map(fn(string $key): array => $this->generateTemplate($case, $key, $status, $createPdf), $keys);
	}

	/** @return list<string> */
	public function orderDocumentKeys(array $case): array {
		return strtoupper((string)($case['masterData']['order_mode'] ?? 'A')) === 'KVA'
			? ['BESTATTUNGSAUFTRAG']
			: ['BESTATTUNGSAUFTRAG', 'BESTATTUNGSVOLLMACHT'];
	}

	/**
	 * Build a PDF entirely as a short-lived preview. No case-file output or
	 * document record is created; the temporary DOCX and converter output are
	 * removed before the response leaves the service.
	 *
	 * @return array{content:string,fileName:string,title:string,templateKey:string}
	 */
	public function previewOrderDocumentPdf(array $case, string $templateKey): array {
		$templateKey = strtoupper(trim($templateKey));
		if (!in_array($templateKey, $this->orderDocumentKeys($case), true)) {
			throw new \InvalidArgumentException('Die angeforderte Vorlage gehört nicht zum aktuellen KVA-/Auftragspaket.');
		}
		if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('Die PHP-Erweiterung ZipArchive wird für DOCX-Vorlagen benötigt.');
		$definition = $this->definition($templateKey, $case);
		if (!$definition['supportsPdf'] || !$this->pdfAvailable()) {
			throw new \RuntimeException('Für diese Dokumentvorschau ist derzeit keine PDF-Konvertierung verfügbar.');
		}
		$user = $this->userSession->getUser();
		if ($user === null) throw new \RuntimeException('Kein angemeldeter Nextcloud-Benutzer.');
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$templateFolder = $this->installationConfig->ensurePath($userFolder, $this->installationConfig->templatesPath());
		$template = $this->template(
			$templateFolder,
			$definition['fileName'],
			(string)($definition['templateNamespace'] ?? ''),
			(bool)($definition['regionalTemplateRequired'] ?? false),
		);
		$costing = $this->articles->caseServices((int)$case['id']);
		$content = $this->replaceDocx((string)$template->getContent(), $this->placeholderData($case, $costing), $costing['items'] ?? []);
		$tempName = '.bestatter-preview-' . bin2hex(random_bytes(12)) . '.docx';
		$temp = $templateFolder->newFile($tempName);
		$temp->putContent($content);
		$converted = null;
		try {
			$pdfContent = null;
			$providerError = null;
			if ($this->conversionManager->hasProviders()) {
				try {
					$path = $this->conversionManager->convert($temp, 'application/pdf');
					$converted = $this->rootFolder->get($path);
					if ($converted instanceof \OCP\Files\File) $pdfContent = (string)$converted->getContent();
				} catch (\Throwable $error) {
					$providerError = $error;
				}
			}
			if (($pdfContent === null || !str_starts_with($pdfContent, '%PDF-')) && $this->euroOffice->isAvailable()) {
				$pdfContent = $this->euroOffice->convert($temp);
			}
			if ($pdfContent === null || !str_starts_with($pdfContent, '%PDF-')) {
				if ($providerError !== null) throw new \RuntimeException('Die Nextcloud-PDF-Konvertierung ist fehlgeschlagen: ' . $providerError->getMessage(), 0, $providerError);
				throw new \RuntimeException('Weder Nextcloud noch EuroOffice stellen eine automatische PDF-Konvertierung bereit.');
			}
			return [
				'content' => $pdfContent,
				'fileName' => $this->safeOutputTitle((string)$definition['title']) . ' - ' . (string)$case['caseNumber'] . ' - Vorschau.pdf',
				'title' => (string)$definition['title'],
				'templateKey' => $templateKey,
			];
		} finally {
			if ($converted instanceof \OCP\Files\File && $converted->getId() !== $temp->getId()) {
				try { $converted->delete(); } catch (\Throwable) {}
			}
			try { $temp->delete(); } catch (\Throwable) {}
		}
	}

	/**
	 * Generate a complete draft package under unique staging names. Existing
	 * drafts are deliberately left untouched until the controller committed all
	 * document records.
	 *
	 * @return array{packageId:string,files:list<array>}
	 */
	public function generateOrderDocumentDraftPackage(array $case, bool $createPdf = true): array {
		$this->assertOrderDraftReplaceable($case);
		$packageId = date('YmdHis') . '-' . bin2hex(random_bytes(4));
		$files = [];
		try {
			foreach ($this->orderDocumentKeys($case) as $key) {
				$file = $this->generateTemplate($case, $key, 'ENTWURF', $createPdf, false, null, 0, null, 'Entwurf - Paket ' . $packageId, false);
				$file['packageId'] = $packageId;
				$file['packageDocumentCount'] = count($this->orderDocumentKeys($case));
				$files[] = $file;
			}
		} catch (\Throwable $error) {
			foreach ($files as $file) $this->discardGeneratedOutput($file);
			throw new \RuntimeException('Das Entwurfspaket konnte nicht vollständig erzeugt werden: ' . $error->getMessage(), 0, $error);
		}
		return ['packageId' => $packageId, 'files' => $files];
	}

	/** @return list<string> */
	public function cleanupSupersededOrderDrafts(array $case, array $keepFiles): array {
		$keepIds = [];
		foreach ($keepFiles as $file) {
			foreach ([(int)($file['fileId'] ?? 0), (int)($file['pdf']['fileId'] ?? 0)] as $id) if ($id > 0) $keepIds[$id] = true;
		}
		$warnings = [];
		foreach ($this->orderOutputNodes($case) as $node) {
			$name = $node->getName();
			if (isset($keepIds[(int)$node->getId()]) || !str_contains($name, ' - Entwurf')) continue;
			try { $node->delete(); } catch (\Throwable $error) { $warnings[] = $name . ': ' . $error->getMessage(); }
		}
		return $warnings;
	}

	private function assertOrderDraftReplaceable(array $case): void {
		foreach ($this->orderOutputNodes($case) as $node) {
			$name = $node->getName();
			if (str_contains($name, ' - Final.') || str_contains($name, ' - Unterschrieben.')) {
				throw new \InvalidArgumentException('Das aktuelle Dokument ist final und kann nicht durch einen Entwurf ersetzt werden.');
			}
		}
	}

	/** @return list<mixed> */
	private function orderOutputNodes(array $case): array {
		$nodes = [];
		foreach ($this->orderDocumentKeys($case) as $key) {
			$definition = $this->definition($key, $case);
			$folder = $this->folders->caseSubfolder((string)$case['caseNumber'], $definition['subfolder']);
			$prefix = sprintf('%s - %s - ', $this->safeOutputTitle((string)$definition['title']), $case['caseNumber']);
			foreach ($folder->getDirectoryListing() as $node) if (str_starts_with($node->getName(), $prefix)) $nodes[(int)$node->getId()] = $node;
		}
		return array_values($nodes);
	}

	public function invoicePaymentData(array $case, array $invoice, bool $includeInternal = false): array {
		$master = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$branch = $this->configuration->branchByKey((string)($case['branch'] ?? $master['branch'] ?? '')) ?? [];
		$settings = $this->configuration->invoiceSettings();
		$snapshot = is_array($invoice['paymentSnapshot'] ?? null) ? $invoice['paymentSnapshot'] : [];
		$hasSnapshot = ($snapshot['schema'] ?? '') === 'bestatter-invoice-payment/1';
		$branchStandard = strtoupper(trim((string)($branch['paymentQrStandard'] ?? 'EPC069-12')));
		$paymentQrStandard = $hasSnapshot
			? strtoupper(trim((string)($snapshot['qrStandard'] ?? 'NONE')))
			: (($settings['qrEnabled'] ?? false) && $branchStandard === 'EPC069-12' ? 'EPC069-12' : 'NONE');
		$paymentBranch = $branch;
		if ($hasSnapshot) foreach (['accountHolder', 'bankName', 'iban', 'bic', 'creditorId', 'paymentTermDays'] as $field) $paymentBranch[$field] = $snapshot[$field] ?? $paymentBranch[$field] ?? '';
		$invoiceDate = substr((string)($invoice['createdAt'] ?? date('c')), 0, 10);
		$dueDate = date('Y-m-d', strtotime($invoiceDate . ' +' . (int)($paymentBranch['paymentTermDays'] ?? 14) . ' days'));
		$method = strtoupper(trim((string)($hasSnapshot ? ($snapshot['method'] ?? 'TRANSFER') : ($master['payment_method'] ?? 'TRANSFER'))));
		if (!in_array($method, ['TRANSFER', 'SEPA_DIRECT_DEBIT'], true)) $method = 'TRANSFER';
		$reference = trim((string)($hasSnapshot ? ($snapshot['mandateReference'] ?? '') : ($master['sepa_mandate_reference'] ?? '')));
		$collectionDate = trim((string)($hasSnapshot ? ($snapshot['collectionDate'] ?? '') : ($master['sepa_collection_date'] ?? ''))) ?: $dueDate;
		$instruction = $method === 'SEPA_DIRECT_DEBIT'
			? sprintf(
				'Der Rechnungsbetrag wird aufgrund des SEPA-Lastschriftmandats %s zum %s eingezogen. Gläubiger-ID: %s. Bitte veranlassen Sie keine Überweisung.',
				$reference,
				$this->formatDocumentDate($collectionDate),
				trim((string)($paymentBranch['creditorId'] ?? '')),
			)
			: 'Bitte überweisen Sie den Rechnungsbetrag bis zum ' . $this->formatDocumentDate($dueDate) . ' unter Angabe der Rechnungsnummer.';
		$qrPayload = $method === 'TRANSFER' && $paymentQrStandard === 'EPC069-12' ? $this->eInvoices->epcPayload($invoice + ['dueDate' => $dueDate], $paymentBranch) : '';
		if ($method === 'TRANSFER' && $paymentQrStandard === 'EPC069-12' && $qrPayload === '') throw new \InvalidArgumentException('Der aktivierte EPC-SEPA-Zahlcode kann ohne gültige Bankverbindung nicht erzeugt werden. Die Rechnung wurde nicht ohne QR-Code ausgegeben.');
		$qrReason = $qrPayload !== '' ? 'INCLUDED' : ($method === 'SEPA_DIRECT_DEBIT' ? 'DIRECT_DEBIT' : 'DISABLED');
		$result = [
			'method' => $method,
			'methodLabel' => $method === 'SEPA_DIRECT_DEBIT' ? 'SEPA-Lastschrift' : 'Überweisung',
			'dueDate' => $dueDate,
			'collectionDate' => $collectionDate,
			'instruction' => $instruction,
			'qrPayload' => $qrPayload,
			'qrRequired' => $qrPayload !== '',
			'qrStandard' => $paymentQrStandard,
			'qrReason' => $qrReason,
			'snapshotUsed' => $hasSnapshot,
		];
		if ($includeInternal) $result['paymentBranch'] = $paymentBranch;
		return $result;
	}

	public function generateInvoice(array $case, array $invoice, bool $createPdf = true): array {
		$recipient = $invoice['recipient'] ?? [];
		$settings = $this->configuration->invoiceSettings();
		$branch = $this->configuration->branchByKey((string)($case['branch'] ?? $case['masterData']['branch'] ?? '')) ?? [];
		$payment = $this->invoicePaymentData($case, $invoice, true);
		$paymentBranch = is_array($payment['paymentBranch'] ?? null) ? $payment['paymentBranch'] : $branch;
		$branch = array_merge($branch, array_intersect_key($paymentBranch, array_flip(['accountHolder', 'bankName', 'iban', 'bic', 'creditorId', 'paymentTermDays'])));
		$invoiceProfile = strtoupper((string)($branch['invoiceProfile'] ?? (($settings['xrechnungEnabled'] ?? false) && !($settings['zugferdEnabled'] ?? false) ? 'XRECHNUNG' : (($settings['zugferdEnabled'] ?? false) ? 'ZUGFERD' : 'NONE'))));
		$requiredBranch = ['name' => 'Niederlassungsname', 'street' => 'Absenderstraße', 'postalCode' => 'Absender-PLZ', 'city' => 'Absenderort', 'accountHolder' => 'Kontoinhaber', 'bankName' => 'Bankname', 'iban' => 'IBAN', 'bic' => 'BIC'];
		$missingBranch = [];
		foreach ($requiredBranch as $field => $label) if (trim((string)($branch[$field] ?? '')) === '') $missingBranch[] = $label;
		if (trim((string)($branch['taxNumber'] ?? '')) === '' && trim((string)($branch['vatId'] ?? '')) === '') $missingBranch[] = 'Steuernummer oder USt-IdNr.';
		if ($missingBranch !== []) throw new \InvalidArgumentException('Die Rechnung kann erst nach Pflege der Niederlassungs- und Bankdaten erzeugt werden: ' . implode(', ', $missingBranch));
		$missingRecipient = [];
		foreach (['name' => 'Name', 'street' => 'Straße', 'postalCode' => 'Postleitzahl', 'city' => 'Ort'] as $field => $label) if (trim((string)($recipient[$field] ?? '')) === '') $missingRecipient[] = $label;
		if ($missingRecipient !== []) throw new \InvalidArgumentException('Die Rechnung kann erst mit vollständigem Rechnungsempfänger erzeugt werden: ' . implode(', ', $missingRecipient));
		if (trim((string)($invoice['servicePeriodFrom'] ?? '')) === '' || trim((string)($invoice['servicePeriodTo'] ?? '')) === '') throw new \InvalidArgumentException('Für eine gesetzeskonforme Rechnung muss der vollständige Leistungszeitraum angegeben werden.');
		$invoiceDate = substr((string)$invoice['createdAt'], 0, 10);
		$dueDate = (string)$payment['dueDate'];
		$invoice['dueDate'] = $dueDate;
		$qrPayload = (string)$payment['qrPayload'];
		$paymentQrPng = $qrPayload !== '' ? $this->eInvoices->epcQrImage($invoice, $paymentBranch) : null;
		if (($payment['snapshotUsed'] ?? false) && is_array($invoice['paymentSnapshot'] ?? null)) {
			$snapshot = $invoice['paymentSnapshot'];
			$case['masterData'] = array_merge($case['masterData'] ?? [], [
				'payment_method' => (string)($snapshot['method'] ?? 'TRANSFER'), 'sepa_mandate_reference' => (string)($snapshot['mandateReference'] ?? ''),
				'sepa_collection_date' => (string)($snapshot['collectionDate'] ?? ''), 'sepa_debtor_name' => (string)($snapshot['debtorName'] ?? ''),
				'sepa_debtor_iban' => (string)($snapshot['debtorIban'] ?? ''), 'sepa_debtor_bic' => (string)($snapshot['debtorBic'] ?? ''),
			]);
		}
		$case['masterData'] = array_merge($case['masterData'] ?? [], [
			'invoice_number' => (string)$invoice['invoiceNumber'],
			'invoice_type' => (string)($invoice['invoiceType'] ?? 'PARTIAL'),
			'invoice_type_label' => (string)($invoice['invoiceTypeLabel'] ?? 'Teilrechnung'),
			'invoice_sequence' => (string)($invoice['invoiceSequence'] ?? 1),
			'invoice_prior_gross' => $this->money((int)($invoice['priorGrossCents'] ?? 0)),
			'invoice_has_prior' => (int)($invoice['invoiceSequence'] ?? 1) > 1 ? '1' : '0',
			'invoice_current_gross' => $this->money((int)($invoice['totals']['grossCents'] ?? 0)),
			'invoice_date' => $this->formatDocumentDate($invoiceDate),
			'invoice_service_period_from' => $this->formatDocumentDate((string)($invoice['servicePeriodFrom'] ?? '')),
			'invoice_service_period_to' => $this->formatDocumentDate((string)($invoice['servicePeriodTo'] ?? '')),
			'invoice_service_period' => $this->servicePeriodLabel((string)($invoice['servicePeriodFrom'] ?? ''), (string)($invoice['servicePeriodTo'] ?? '')),
			'invoice_recipient_name' => (string)($recipient['name'] ?? ''),
			'invoice_recipient_address' => trim(implode(', ', array_filter([(string)($recipient['street'] ?? ''), trim((string)($recipient['postalCode'] ?? '') . ' ' . (string)($recipient['city'] ?? ''))]))),
			'invoice_due_date' => $this->formatDocumentDate($dueDate), 'invoice_payment_reference' => 'Rechnung ' . (string)$invoice['invoiceNumber'], 'invoice_qr_payload' => $qrPayload,
			'invoice_payment_method' => (string)$payment['methodLabel'], 'invoice_payment_instruction' => (string)$payment['instruction'],
			'invoice_payment_qr_label' => $qrPayload !== '' ? 'SEPA-ZAHLCODE' : ($payment['method'] === 'SEPA_DIRECT_DEBIT' ? 'SEPA-LASTSCHRIFT' : 'KEIN ZAHLUNGS-QR'),
			'sepa_mandate_reference' => (string)($case['masterData']['sepa_mandate_reference'] ?? ''), 'sepa_collection_date' => $this->formatDocumentDate((string)$payment['collectionDate']),
			'branch_creditor_id' => (string)($branch['creditorId'] ?? ''),
			'branch_name' => (string)($branch['name'] ?? ''), 'branch_address' => trim(implode(', ', array_filter([(string)($branch['street'] ?? ''), trim((string)($branch['postalCode'] ?? '') . ' ' . (string)($branch['city'] ?? ''))]))),
			'bank_account_holder' => (string)($paymentBranch['accountHolder'] ?? ''), 'bank_name' => (string)($paymentBranch['bankName'] ?? ''), 'bank_iban' => (string)($paymentBranch['iban'] ?? ''), 'bank_bic' => (string)($paymentBranch['bic'] ?? ''),
			'branch_vat_id' => (string)($branch['vatId'] ?? ''), 'branch_tax_number' => (string)($branch['taxNumber'] ?? ''), 'branch_register' => trim((string)($branch['registerCourt'] ?? '') . ' ' . (string)($branch['registerNumber'] ?? '')),
			'branch_managing_directors' => (string)($branch['managingDirectors'] ?? ''),
		]);
		$items = array_map(static fn(array $item): array => [
			'articleNumber' => (string)$item['position'], 'title' => (string)$item['description'], 'quantity' => (float)$item['quantity'],
			'unit' => (string)($item['unit'] ?? 'STK'),
			'unitPriceCents' => (int)$item['unitPriceCents'], 'vatRate' => (int)$item['vatRate'], 'netCents' => (int)$item['netCents'],
			'vatCents' => (int)$item['vatCents'], 'grossCents' => (int)$item['grossCents'], 'positionType' => (string)($item['positionType'] ?? 'EL'), 'sourcePackageName' => '',
		], $invoice['items'] ?? []);
		$taxableNet = array_sum(array_map(static fn(array $item): int => ($item['positionType'] ?? 'EL') === 'DP' ? 0 : (int)$item['netCents'], $items));
		$passThrough = array_sum(array_map(static fn(array $item): int => ($item['positionType'] ?? 'EL') === 'DP' ? (int)$item['grossCents'] : 0, $items));
		$documentStatus = in_array((string)($invoice['status'] ?? ''), ['PRUEFUNG', 'FREIGEGEBEN', 'VERSENDET', 'TEILBEZAHLT', 'BEZAHLT'], true)
			? 'FINAL'
			: 'ENTWURF';
		$isReviewDocument = (string)($invoice['status'] ?? '') === 'PRUEFUNG';
		$result = $this->generateTemplate(
			$case,
			'RECHNUNG',
			$documentStatus,
			$createPdf,
			false,
			['items' => $items, 'totals' => $invoice['totals'], 'invoiceTaxableNetCents' => $taxableNet, 'invoicePassThroughCents' => $passThrough],
			0,
			$qrPayload !== '' ? $paymentQrPng : '',
			$isReviewDocument ? 'Prüfung' : null,
			$isReviewDocument,
		);
		$result['documentStage'] = $isReviewDocument ? 'PRUEFUNG' : $documentStatus;
		$target = $this->folders->caseSubfolder((string)$case['caseNumber'], $this->installationConfig->billingFolder());
		$electronicOutputs = [];
		if ($invoiceProfile === 'ZUGFERD') {
			$xml = $this->eInvoices->createXml($case, $invoice, $branch, $settings);
			$validation = $this->eInvoices->validateXml($xml, 'ZUGFERD');
			if (!$validation['valid']) throw new \RuntimeException('Die E-Rechnung ist strukturell ungültig: ' . implode(' ', $validation['errors']));
			$xmlName = 'factur-x.xml';
			if ($target->nodeExists($xmlName)) $target->get($xmlName)->delete();
			$file = $target->newFile($xmlName); $file->putContent($xml);
			$compliance = $this->eInvoiceCompliance->status();
			$hybridEmbedded = false; $normativelyValidated = false; $reportFileId = null;
			if ($createPdf && is_array($result['pdf']) && $compliance['available']) {
				$pdfNode = $target->get((string)$result['pdf']['fileName']);
				$processed = $this->eInvoiceCompliance->combineAndValidate((string)$pdfNode->getContent(), $xml);
				$pdfNode->putContent($processed['pdf']);
				$reportName = pathinfo((string)$result['pdf']['fileName'], PATHINFO_FILENAME) . ' - Prüfbericht.xml';
				if ($target->nodeExists($reportName)) $target->get($reportName)->delete();
				$report = $target->newFile($reportName); $report->putContent($processed['report']);
				$reportFileId = $report->getId(); $hybridEmbedded = true; $normativelyValidated = true;
			} elseif ($settings['normativeValidationRequired']) {
				throw new \RuntimeException('Die normative E-Rechnungsprüfung ist verpflichtend, aber der Mustang-Validator ist nicht verfügbar.');
			}
			$result['eInvoice'] = ['standard' => 'ZUGFeRD', 'version' => '2.5.2', 'profile' => 'EN16931', 'fileId' => $file->getId(), 'path' => dirname((string)$result['path']) . '/' . $xmlName, 'validationStatus' => $normativelyValidated ? 'NORMATIV_VALIDATED' : 'STRUCTURE_VALIDATED', 'validationLevel' => $normativelyValidated ? 'MUSTANG_EN16931_PDFA3' : $validation['level'], 'hybridEmbedded' => $hybridEmbedded, 'validationReportFileId' => $reportFileId, 'complianceNote' => $normativelyValidated ? 'PDF/A-3 und eingebettete EN16931-Daten wurden extern validiert.' : 'Die XML-Struktur wurde intern geprüft. Eine normative EN-16931-/PDF/A-3-Prüfung ist noch nicht eingerichtet.'];
			$electronicOutputs[] = ['standard' => 'ZUGFeRD', 'fileId' => $file->getId(), 'sha256' => hash('sha256', $xml), 'validation' => $result['eInvoice']['validationStatus']];
		}
		if ($invoiceProfile === 'XRECHNUNG') {
			$xInvoice = $invoice;
			$xInvoice['recipient']['electronicAddress'] = (string)($case['masterData']['invoice_electronic_address'] ?? $case['masterData']['order_client_email'] ?? '');
			$xml = $this->eInvoices->createXml($case, $xInvoice, $branch, $settings, 'XRECHNUNG');
			$validation = $this->eInvoices->validateXml($xml, 'XRECHNUNG');
			if (!$validation['valid']) throw new \InvalidArgumentException('Die XRechnung kann noch nicht erzeugt werden: ' . implode(' ', $validation['errors']));
			$name = pathinfo(basename((string)$result['path']), PATHINFO_FILENAME) . ' - XRechnung.xml';
			if ($target->nodeExists($name)) $target->get($name)->delete();
			$file = $target->newFile($name); $file->putContent($xml);
			$result['xInvoice'] = ['standard' => 'XRechnung', 'version' => '3.0.x', 'syntax' => 'CII', 'fileId' => $file->getId(), 'path' => dirname((string)$result['path']) . '/' . $name, 'validationStatus' => 'STRUCTURE_VALIDATED', 'complianceNote' => 'Vor Versand ist die Datei mit der jeweils aktuellen KoSIT-Konfiguration normativ zu validieren.'];
			$electronicOutputs[] = ['standard' => 'XRechnung', 'fileId' => $file->getId(), 'sha256' => hash('sha256', $xml), 'validation' => 'STRUCTURE_VALIDATED'];
		}
		$manifest = ['schema' => 'bestatter-invoice-closure/1', 'invoiceId' => (int)$invoice['id'], 'invoiceNumber' => (string)$invoice['invoiceNumber'], 'invoiceType' => (string)$invoice['invoiceType'], 'caseNumber' => (string)$case['caseNumber'], 'createdAt' => date('c'), 'createdBy' => $this->userSession->getUser()?->getUID() ?? 'system', 'totals' => $invoice['totals'], 'outputs' => $electronicOutputs];
		if (is_array($result['pdf'])) { $pdfNode = $target->get((string)$result['pdf']['fileName']); $manifest['pdfSha256'] = hash('sha256', (string)$pdfNode->getContent()); $manifest['pdfFileId'] = $pdfNode->getId(); }
		$manifestName = pathinfo(basename((string)$result['path']), PATHINFO_FILENAME) . ' - Abschlussnachweis.json';
		if ($target->nodeExists($manifestName)) $target->get($manifestName)->delete();
		$manifestFile = $target->newFile($manifestName); $manifestFile->putContent(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		$result['closureManifest'] = $manifest + ['fileId' => $manifestFile->getId(), 'path' => dirname((string)$result['path']) . '/' . $manifestName];
		$result['payment'] = $payment;
		$result['invoiceProfile'] = $invoiceProfile;
		$result['paymentQr'] = ['format' => (string)$payment['qrStandard'], 'payload' => $qrPayload, 'structuredReference' => $qrPayload !== '' ? explode("\n", $qrPayload)[9] : '', 'printReady' => $qrPayload !== '' && $paymentQrPng !== null, 'snapshotUsed' => (bool)($payment['snapshotUsed'] ?? false), 'rendering' => $qrPayload !== '' ? 'SERVER_RENDERED_PNG' : ($payment['method'] === 'SEPA_DIRECT_DEBIT' ? 'SUPPRESSED_FOR_DIRECT_DEBIT' : 'DISABLED_BY_CONFIGURATION')];
		return $result;
	}

	private function servicePeriodLabel(string $from, string $to): string {
		$from=$this->formatDocumentDate($from);$to=$this->formatDocumentDate($to);
		if($from!==''&&$to!=='')return $from.' bis '.$to;
		return $from!==''?$from:$to;
	}

	public function preview(array $case, string $templateKey, int $scheduleId = 0): array {
		$definition = $this->definition(strtoupper(trim($templateKey)), $case);
		[$case, $scheduleOptions, $selectedSchedule] = $this->withFuneralEventContext($case, $definition, $scheduleId);
		$costing = $this->articles->caseServices((int)$case['id']);
		$missing = $this->missingRequiredFields($case, $definition, $costing);
		$masterData = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$requiresFuneralEvent = in_array('funeral_event_date', $definition['requiredFields'], true);
		return [
			'templateKey' => $definition['key'],
			'title' => $definition['title'],
			'documentType' => $definition['category'],
			'fileName' => $definition['fileName'],
			'outputSubfolder' => $definition['subfolder'],
			'supportsPdf' => $definition['supportsPdf'] && $this->pdfAvailable(),
			'pdfConfigured' => $definition['supportsPdf'],
			'pdfMessage' => $definition['supportsPdf'] && !$this->pdfAvailable() ? 'PDF-Ausgabe ist derzeit nicht verfügbar; die DOCX-Ausgabe bleibt möglich.' : '',
			'missingFields' => $missing,
			'ready' => $missing === [],
			'requiresFuneralEvent' => $requiresFuneralEvent,
			'scheduleOptions' => $scheduleOptions,
			'selectedScheduleId' => (int)($selectedSchedule['id'] ?? 0),
			'summary' => [
				'caseNumber' => (string)$case['caseNumber'],
				'deceased' => trim((string)$case['firstName'] . ' ' . (string)$case['lastName']),
				'death' => $this->deathDisplay($case['masterData'] ?? [], (string)($case['dateOfDeath'] ?? '')),
				'grossTotal' => $this->money((int)($costing['totals']['grossCents'] ?? 0)),
				'funeralEvent' => $requiresFuneralEvent ? [
					'date' => (string)($masterData['funeral_event_date'] ?? ''),
					'time' => (string)($masterData['funeral_event_time'] ?? ''),
					'endTime' => (string)($masterData['funeral_event_end_time'] ?? ''),
					'location' => (string)($masterData['funeral_event_location'] ?? ''),
					'category' => (string)($masterData['funeral_event_category'] ?? 'Trauerfeier'),
				] : null,
			],
		];
	}

	public function generateTemplate(array $case, string $templateKey, string $status = 'ENTWURF', bool $createPdf = true, bool $allowIncomplete = false, ?array $costingOverride = null, int $scheduleId = 0, ?string $embeddedPaymentQr = null, ?string $outputStatusLabel = null, bool $allowImmutableOutputReplacement = false, bool $replaceExistingOutput = true): array {
		if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('Die PHP-Erweiterung ZipArchive wird für DOCX-Vorlagen benötigt.');
		$templateKey = strtoupper(trim($templateKey));
		$status = strtoupper(trim($status));
		if (!in_array($status, self::STATUSES, true)) throw new \InvalidArgumentException('Ungültiger Dokumentstatus.');
		$definition = $this->definition($templateKey, $case);
		[$case] = $this->withFuneralEventContext($case, $definition, $scheduleId);
		$user = $this->userSession->getUser();
		if ($user === null) throw new \RuntimeException('Kein angemeldeter Nextcloud-Benutzer.');
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$templateFolder = $this->installationConfig->ensurePath($userFolder, $this->installationConfig->templatesPath());
		$targetFolder = $this->folders->caseSubfolder((string)$case['caseNumber'], $definition['subfolder']);
		$costing = $costingOverride ?? $this->articles->caseServices((int)$case['id']);
		$missing = $this->missingRequiredFields($case, $definition, $costing);
		if ($missing !== [] && (!$allowIncomplete || $status !== 'ENTWURF')) throw new \InvalidArgumentException('Dokument kann noch nicht erzeugt werden. Bitte ergänzen: ' . implode(', ', $missing));
		if (in_array($status, ['FINAL', 'UNTERSCHRIEBEN'], true) && (!$createPdf || !$definition['supportsPdf'])) throw new \InvalidArgumentException('Finale Dokumente müssen als nicht bearbeitbare PDF-Ausgabe erzeugt werden.');

		$template = $this->template(
			$templateFolder,
			$definition['fileName'],
			(string)($definition['templateNamespace'] ?? ''),
			(bool)($definition['regionalTemplateRequired'] ?? false),
		);
		$templateContent = (string)$template->getContent();
		$templateSource = $this->templateNodeFingerprint($template, $templateContent);
		$content = $this->replaceDocx($templateContent, $this->placeholderData($case, $costing), $costing['items'] ?? [], $embeddedPaymentQr);
		$safeTitle = $this->safeOutputTitle((string)$definition['title']);
		$masterData = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$scheduleContextId = (int)($masterData['funeral_event_schedule_id'] ?? 0);
		$contextLabel = $scheduleContextId > 0
			? $this->safeOutputTitle('Trauerfeier ' . (string)($masterData['funeral_event_date_iso'] ?? $masterData['funeral_event_date'] ?? $scheduleContextId) . ' ' . str_replace(':', '-', (string)($masterData['funeral_event_time'] ?? '')))
			: '';
		$documentContextKey = $scheduleContextId > 0 ? 'schedule:' . $scheduleContextId : '';
		$statusLabel = trim((string)$outputStatusLabel);
		if ($statusLabel === '') $statusLabel = match ($status) { 'FINAL' => 'Final', 'UNTERSCHRIEBEN' => 'Unterschrieben', default => 'Entwurf' };
		$statusLabel = $this->safeOutputTitle($statusLabel);
		$prefix = sprintf('%s - %s%s - ', $safeTitle, $case['caseNumber'], $contextLabel !== '' ? ' - ' . $contextLabel : '');
		if ($replaceExistingOutput) {
			$existingOutputs = array_values(array_filter($targetFolder->getDirectoryListing(), static fn($existing): bool => str_starts_with($existing->getName(), $prefix)));
			foreach ($existingOutputs as $existing) {
				$name = $existing->getName();
				if (!$allowImmutableOutputReplacement && (str_contains($name, ' - Final.') || str_contains($name, ' - Unterschrieben.'))) throw new \InvalidArgumentException('Das aktuelle Dokument ist final und kann nicht überschrieben werden.');
			}
			foreach ($existingOutputs as $existing) $existing->delete();
		}
		$version = 1;
		$baseName = sprintf('%s - %s%s - %s', $safeTitle, $case['caseNumber'], $contextLabel !== '' ? ' - ' . $contextLabel : '', $statusLabel);
		$docxName = $baseName . '.docx';
		// A previous interrupted workflow may have written the file before its
		// database record was committed. Draft outputs are current-state files,
		// so an orphan with the exact target name must be replaced safely.
		if ($targetFolder->nodeExists($docxName) && $replaceExistingOutput) $targetFolder->get($docxName)->delete();
		if ($targetFolder->nodeExists($docxName)) throw new \RuntimeException('Der temporäre Dokumentname ist bereits vorhanden. Bitte erneut versuchen.');
		$file = $targetFolder->newFile($docxName);
		$file->putContent($content);
		$basePath = $this->folders->caseRelativePath((string)$case['caseNumber']) . '/' . $definition['subfolder'] . '/';
		$result = [
			'title' => $definition['title'], 'path' => $basePath . $docxName, 'fileId' => $file->getId(),
			'template' => $definition['fileName'], 'templateKey' => $templateKey, 'documentType' => $definition['category'], 'status' => $status,
			'version' => $version, 'missingFields' => $missing, 'createdAt' => date('c'), 'pdf' => null,
			'documentContextKey' => $documentContextKey, 'sourceScheduleId' => $scheduleContextId,
			'templateSource' => $templateSource,
		];
		if ($createPdf && $definition['supportsPdf']) {
			try {
				$result['pdf'] = $this->createPdf($file, $targetFolder, $baseName . '.pdf', $basePath);
			} catch (\Throwable $error) {
				if (in_array($status, ['FINAL', 'UNTERSCHRIEBEN'], true)) { $file->delete(); throw $error; }
				$result['pdfWarning'] = 'Die DOCX-Datei wurde erzeugt; nur die PDF-Ausgabe ist fehlgeschlagen: ' . $error->getMessage();
			}
		}
		if (in_array($status, ['FINAL', 'UNTERSCHRIEBEN'], true) && is_array($result['pdf'])) { $file->delete(); $result['fileId'] = null; $result['path'] = $result['pdf']['path']; }
		return $result;
	}

	/**
	 * Fingerprint the effective Nextcloud template used for a workflow output.
	 * This makes a user-replaced template a deliberate new document revision
	 * without ever overwriting the individual source file.
	 */
	public function templateFingerprint(string $templateKey, ?string $userId = null): array {
		$templateKey = strtoupper(trim($templateKey));
		$definition = null;
		foreach ($this->configuration->documents() as $candidate) {
			if (strtoupper((string)($candidate['key'] ?? '')) === $templateKey) {
				$definition = $candidate;
				break;
			}
		}
		if ($definition === null) throw new \InvalidArgumentException('Dokumentenvorlage wurde nicht gefunden.');
		$uid = trim((string)$userId);
		if ($uid === '') $uid = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($uid === '') throw new \RuntimeException('Kein Nextcloud-Benutzer für die Vorlagenprüfung verfügbar.');
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$templateFolder = $this->installationConfig->ensurePath($userFolder, $this->installationConfig->templatesPath());
		$template = $this->template($templateFolder, basename((string)($definition['fileName'] ?? '')));
		$content = (string)$template->getContent();
		return $this->templateNodeFingerprint($template, $content);
	}

	private function templateNodeFingerprint(mixed $template, string $content): array {
		return [
			'fileName' => (string)$template->getName(),
			'fileId' => (int)$template->getId(),
			'sha256' => hash('sha256', $content),
		];
	}

	/**
	 * Direct document generation must use the same confirmed funeral-event
	 * context as workflow-driven generation. If more than one suitable event
	 * exists, the UI must select one explicitly instead of silently guessing.
	 */
	private function withFuneralEventContext(array $case, array $definition, int $scheduleId = 0): array {
		if (!in_array('funeral_event_date', $definition['requiredFields'], true)) return [$case, [], null];
		$masterData = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		if ($scheduleId <= 0 && trim((string)($masterData['funeral_event_date'] ?? '')) !== '') return [$case, [], null];

		$options = $this->funeralEventOptions((int)$case['id']);
		$selected = null;
		if ($scheduleId > 0) {
			foreach ($options as $option) if ((int)$option['id'] === $scheduleId) { $selected = $option; break; }
			if ($selected === null) throw new \InvalidArgumentException('Die ausgewählte Trauerfeier gehört nicht zu diesem Fall oder ist nicht bestätigt.');
		} elseif (count($options) === 1) {
			$selected = $options[0];
		}
		if ($selected === null) return [$case, $options, null];

		$date = new \DateTimeImmutable((string)$selected['date'], new \DateTimeZone('Europe/Berlin'));
		$duration = max(15, (int)($selected['durationMinutes'] ?? 60));
		$case['masterData'] = array_replace($masterData, [
			'funeral_event_schedule_id' => (string)$selected['id'],
			'funeral_event_date' => $date->format('d.m.Y'),
			'funeral_event_date_iso' => $date->format('Y-m-d'),
			'funeral_event_time' => $date->format('H:i'),
			'funeral_event_end_time' => $date->modify('+' . $duration . ' minutes')->format('H:i'),
			'funeral_event_location' => (string)($selected['location'] ?? ''),
			'funeral_event_category' => (string)($selected['category'] ?? $selected['title'] ?? 'Trauerfeier'),
			'funeral_event_external_participants' => (string)($selected['externalParticipants'] ?? ''),
		]);
		return [$case, $options, $selected];
	}

	/** Confirmed or completed funeral ceremonies available for document context. */
	public function funeralEventOptions(int $caseId): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('id', 'title', 'record_date', 'status', 'payload')
			->from('bestatter_records')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->andWhere($query->expr()->eq('record_type', $query->createNamedParameter('schedule')))
			->andWhere($query->expr()->orX(
				$query->expr()->eq('status', $query->createNamedParameter('BESTAETIGT')),
				$query->expr()->eq('status', $query->createNamedParameter('ERLEDIGT')),
			))
			->orderBy('record_date', 'ASC')
			->executeQuery()->fetchAllAssociative();
		$options = [];
		foreach ($rows as $row) {
			$payload = json_decode((string)($row['payload'] ?? ''), true);
			$payload = is_array($payload) ? $payload : [];
			$presetKey = strtoupper(trim((string)($payload['schedulePresetKey'] ?? '')));
			$category = trim((string)($payload['appointmentCategory'] ?? ''));
			$title = trim((string)($row['title'] ?? ''));
			if (!str_contains($presetKey, 'TRAUERFEIER') && !str_contains(mb_strtolower($category . ' ' . $title), 'trauerfeier')) continue;
			$date = trim((string)($row['record_date'] ?? ''));
			if ($date === '') continue;
			try { $dateValue = new \DateTimeImmutable($date, new \DateTimeZone('Europe/Berlin')); } catch (\Throwable) { continue; }
			$options[] = [
				'id' => (int)$row['id'], 'title' => $title, 'date' => $date, 'status' => (string)$row['status'],
				'displayDate' => $dateValue->format('d.m.Y'), 'displayTime' => $dateValue->format('H:i'),
				'durationMinutes' => max(15, (int)($payload['durationMinutes'] ?? 60)),
				'location' => (string)($payload['location'] ?? ''), 'category' => $category !== '' ? $category : $title,
				'externalParticipants' => (string)($payload['externalParticipants'] ?? ''),
			];
		}
		return $options;
	}

	public function discardGeneratedOutput(array $result): void {
		$user = $this->userSession->getUser();
		if ($user === null) return;
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$allowedPrefix = rtrim($userFolder->getPath(), '/') . '/' . $this->installationConfig->casesPath() . '/';
		$fileIds = array_values(array_unique(array_filter([
			(int)($result['fileId'] ?? 0),
			(int)($result['pdf']['fileId'] ?? 0),
		])));
		foreach ($fileIds as $fileId) {
			foreach ($userFolder->getById($fileId) as $node) {
				if (!str_starts_with($node->getPath(), $allowedPrefix)) continue;
				try { $node->delete(); } catch (\Throwable) {}
			}
		}
	}

	private function safeOutputTitle(string $title): string {
		$title = strtr($title, [
			"\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-',
			"\u{00A0}" => ' ', "\u{202F}" => ' ',
		]);
		$title = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $title);
		$title = preg_replace('/[\x00-\x1F\x7F]+/u', '', $title) ?? $title;
		$title = preg_replace('/\s+/u', ' ', $title) ?? $title;
		$title = preg_replace('/\s*-\s*/u', ' - ', $title) ?? $title;
		$title = trim($title, " .-");
		if ($title === '') throw new \InvalidArgumentException('Die Dokumentbezeichnung ergibt keinen gültigen Dateinamen.');
		return $title;
	}

	private function definition(string $key, array $case): array {
		$isEstimate = strtoupper((string)($case['masterData']['order_mode'] ?? 'A')) === 'KVA';
		$branch = $this->configuration->branchByKey((string)($case['branch'] ?? $case['masterData']['branch'] ?? '')) ?? ['countryCode' => 'DE'];
		$templateNamespace = $this->countryConfiguration->templateNamespace($branch);
		if ($templateNamespace === 'DE') $templateNamespace = '';
		foreach ($this->configuration->documents() as $template) {
			if ($template['key'] !== $key || !$template['active'] || !str_ends_with(strtolower((string)$template['fileName']), '.docx')) continue;
			return [
				'key' => $key,
				'fileName' => $this->resolveConfiguredTemplateName((string)$template['fileName']),
				'title' => $key === 'BESTATTUNGSAUFTRAG' ? ($isEstimate ? 'Kostenvoranschlag' : 'Bestattungsauftrag') : (string)$template['name'],
				'subfolder' => (string)($template['outputSubfolder'] ?: ($template['category'] === 'Auftrag' ? $this->installationConfig->orderFolder() : $this->installationConfig->authoritiesFolder())),
				'supportsPdf' => (bool)$template['supportsPdf'], 'category' => (string)($template['category'] ?? 'Allgemein'),
				'requiredFields' => (array)$template['requiredFields'],
				'templateNamespace' => $templateNamespace,
				'regionalTemplateRequired' => $templateNamespace !== '' && in_array($key, self::REGIONAL_TEMPLATE_REQUIRED, true),
			];
		}
		throw new \InvalidArgumentException('Die gewählte Dokumentvorlage ist nicht als aktive DOCX-Vorlage eingerichtet.');
	}

	private function resolveConfiguredTemplateName(string $configuredName): string {
		$user = $this->userSession->getUser();
		if ($user === null) return $configuredName;
		$folder = $this->installationConfig->ensurePath($this->rootFolder->getUserFolder($user->getUID()), $this->installationConfig->templatesPath());
		if ($folder->nodeExists($configuredName)) return $configuredName;
		$needle = $this->normalizedTemplateName($configuredName);
		foreach ($folder->getDirectoryListing() as $node) {
			if ($this->normalizedTemplateName($node->getName()) === $needle) return $node->getName();
		}
		return $configuredName;
	}

	private function normalizedTemplateName(string $name): string {
		$name = mb_strtolower(pathinfo($name, PATHINFO_FILENAME));
		return preg_replace('/[^a-z0-9äöüß]+/u', '', $name) ?? $name;
	}

	private function missingRequiredFields(array $case, array $definition, array $costing): array {
		$m = $case['masterData'] ?? [];
		$labels = [
			'first_name' => 'Vorname', 'last_name' => 'Nachname', 'date_of_death_or_interval' => 'Sterbedatum oder Todeszeitraum',
			'registry_office' => 'Standesamt', 'place_of_death' => 'Sterbeort', 'pension_insurance_number' => 'Postrentennummer',
			'order_client_name' => 'Auftraggeber', 'order_client_address' => 'Anschrift Auftraggeber',
			'order_number' => 'KVA-/Auftragsnummer', 'order_date' => 'KVA-/Auftragsdatum', 'services' => 'beauftragte Leistungen',
			'funeral_event_date' => 'Datum der verknüpften Trauerfeier',
		];
		$missing = [];
		foreach ($definition['requiredFields'] as $field) {
			$present = match ($field) {
				'first_name' => trim((string)$case['firstName']) !== '',
				'last_name' => trim((string)$case['lastName']) !== '',
				'date_of_death_or_interval' => trim((string)($m['date_of_death'] ?? $case['dateOfDeath'] ?? '')) !== '' || (trim((string)($m['death_time_from'] ?? '')) !== '' && trim((string)($m['death_time_to'] ?? '')) !== ''),
				'order_client_name' => trim((string)($m['order_client_name'] ?? '')) !== '',
				'order_client_address' => trim((string)($m['order_client_street'] ?? '')) !== '' && trim((string)($m['order_client_postal_city'] ?? '')) !== '',
				'order_date' => trim((string)($m[strtoupper((string)($m['order_mode'] ?? 'A')) === 'KVA' ? 'kva_date' : 'order_date'] ?? '')) !== '',
				'services' => count($costing['items'] ?? []) > 0,
				default => trim((string)($m[$field] ?? '')) !== '',
			};
			if (!$present) $missing[] = $labels[$field] ?? $field;
		}
		return $missing;
	}

	private function createPdf(mixed $docx, Folder $target, string $name, string $basePath): array {
		$content = null;
		$providerError = null;
		if ($this->conversionManager->hasProviders()) {
			try {
				$path = $this->conversionManager->convert($docx, 'application/pdf');
				$converted = $this->rootFolder->get($path);
				if ($converted instanceof \OCP\Files\File) $content = (string)$converted->getContent();
			} catch (\Throwable $error) {
				$providerError = $error;
			}
		}
		if (($content === null || !str_starts_with($content, '%PDF-')) && $this->euroOffice->isAvailable()) {
			$content = $this->euroOffice->convert($docx);
		}
		if ($content === null || !str_starts_with($content, '%PDF-')) {
			if ($providerError !== null) throw new \RuntimeException('Die Nextcloud-PDF-Konvertierung ist fehlgeschlagen: ' . $providerError->getMessage(), 0, $providerError);
			throw new \RuntimeException('Weder Nextcloud noch EuroOffice stellen eine automatische PDF-Konvertierung bereit.');
		}
		if ($target->nodeExists($name)) $target->get($name)->delete();
		$pdf = $target->newFile($name);
		$pdf->putContent($content);
		return ['fileId' => $pdf->getId(), 'path' => $basePath . $name, 'fileName' => $name];
	}

	private function pdfAvailable(): bool {
		return $this->conversionManager->hasProviders() || $this->euroOffice->isAvailable();
	}

	private function folder(Folder $parent, string $name): Folder {
		return $parent->nodeExists($name) ? $parent->get($name) : $parent->newFolder($name);
	}

	private function template(Folder $folder, string $name, string $namespace = '', bool $regionalTemplateRequired = false): mixed {
		$namespace = trim(str_replace('\\', '/', $namespace), '/');
		if ($namespace !== '') {
			$relative = $namespace . '/' . $name;
			if ($folder->nodeExists($relative)) return $folder->get($relative);
			$countrySource = __DIR__ . '/../../resources/templates/' . $relative;
			if (is_file($countrySource)) {
				$countryFolder = $this->installationConfig->ensurePath($folder, $namespace);
				if (!$countryFolder->nodeExists($name)) $countryFolder->newFile($name, (string)file_get_contents($countrySource));
				return $countryFolder->get($name);
			}
			if ($regionalTemplateRequired) {
				throw new \InvalidArgumentException('Für das Länder- oder Regionalprofil ' . str_replace('/', ' / ', $namespace) . ' liegt die fachlich erforderliche Vorlage „' . $name . '“ noch nicht vor. Bitte eine rechtlich geprüfte Vorlage im entsprechenden Vorlagen-Unterordner hinterlegen.');
			}
		}
		$source = __DIR__ . '/../../resources/templates/' . $name;
		if (!$folder->nodeExists($name)) {
			$fallback = $this->condolenceTemplateFallback($folder, $name);
			if ($fallback !== null) return $fallback;
		}
		if (!$folder->nodeExists($name)) {
			if (!is_file($source)) throw new \RuntimeException('Vorlage ' . $name . ' wurde weder im konfigurierten Vorlagenordner noch im App-Paket gefunden.');
			$folder->newFile($name, (string)file_get_contents($source));
		}
		$template = $folder->get($name);
		$replaceableHashes = self::REPLACEABLE_STANDARD_TEMPLATE_HASHES[$name] ?? [];
		if (is_file($source) && in_array(hash('sha256', (string)$template->getContent()), $replaceableHashes, true)) {
			$template->putContent((string)file_get_contents($source));
		}
		// A file in the configured Nextcloud template directory always wins.
		// Only a byte-identical former package default listed above is refreshed;
		// every individually modified file remains untouched.
		return $template;
	}

	private function condolenceTemplateFallback(Folder $folder, string $expectedName): mixed {
		$deck = strtoupper($expectedName) === 'KONDOLENZLISTE_DECKBLATT.DOCX';
		$list = strtoupper($expectedName) === 'KONDOLENZLISTE.DOCX';
		if (!$deck && !$list) return null;
		foreach ($folder->getDirectoryListing() as $node) {
			$name = mb_strtolower($node->getName());
			if (!str_ends_with($name, '.docx') || !str_contains($name, 'kondolenz')) continue;
			$isDeck = str_contains($name, 'deck');
			if (($deck && $isDeck) || ($list && !$isDeck)) return $node;
		}
		return null;
	}

	private function replaceDocx(string $content, array $values, array $services, ?string $embeddedPaymentQr = null): string {
		$tmp = tempnam(sys_get_temp_dir(), 'bestatter-docx-');
		if ($tmp === false) throw new \RuntimeException('Temporäre DOCX-Datei konnte nicht erstellt werden.');
		file_put_contents($tmp, $content);
		$zip = new \ZipArchive();
		if ($zip->open($tmp) !== true) { @unlink($tmp); throw new \RuntimeException('DOCX-Vorlage konnte nicht geöffnet werden.'); }
		$paymentQrPlaceholderFound = false;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if (str_starts_with($name, 'word/media/') && $embeddedPaymentQr !== null) {
				$media = $zip->getFromIndex($i);
				$placeholder = __DIR__ . '/../../resources/payment-qr-placeholder.png';
				if ($media !== false && is_file($placeholder) && hash_equals(hash('sha256', (string)file_get_contents($placeholder)), hash('sha256', $media))) {
					$paymentQrPlaceholderFound = true;
					$replacement = $embeddedPaymentQr !== '' ? $embeddedPaymentQr : (string)file_get_contents(__DIR__ . '/../../resources/payment-qr-empty.png');
					$zip->addFromString($name, $replacement);
				}
				continue;
			}
			if (!str_starts_with($name, 'word/') || !str_ends_with($name, '.xml')) continue;
			$xml = $zip->getFromIndex($i);
			if ($xml === false) continue;
			if ($name === 'word/document.xml') {
				$isEstimate = ($values['{{order.document_title}}'] ?? '') === 'Kostenvoranschlag';
				$xml = $this->replaceServiceRows($xml, $services, $isEstimate);
				$xml = $this->replaceInvoiceTaxRows($xml, $services);
				$xml = $this->removeEmptyPassThroughSummary($xml, $services);
				$xml = $this->removeEmptyPriorInvoiceSummary($xml, $values);
			}
			$zip->addFromString($name, $this->replaceTextPlaceholders($xml, $values));
		}
		$zip->close();
		if ($embeddedPaymentQr !== null && $embeddedPaymentQr !== '' && !$paymentQrPlaceholderFound) {
			@unlink($tmp);
			throw new \InvalidArgumentException('Die ausgewählte Rechnungsvorlage enthält keinen kompatiblen QR-Code-Platzhalter. Bitte die aktuelle Standardvorlage RECHNUNG.docx verwenden oder den Platzhalter aus dieser Vorlage in die eigene Vorlage übernehmen.');
		}
		$result = (string)file_get_contents($tmp);
		@unlink($tmp);
		if ($embeddedPaymentQr !== null && $embeddedPaymentQr !== '' && !$this->docxContainsMedia($result, $embeddedPaymentQr)) {
			throw new \RuntimeException('Der Zahlungs-QR-Code konnte nicht verlässlich in das Rechnungsdokument eingebettet werden. Die Ausgabe wurde abgebrochen.');
		}
		return $result;
	}

	private function docxContainsMedia(string $content, string $expectedMedia): bool {
		$tmp = tempnam(sys_get_temp_dir(), 'bestatter-docx-check-');
		if ($tmp === false) return false;
		file_put_contents($tmp, $content);
		$zip = new \ZipArchive();
		if ($zip->open($tmp) !== true) { @unlink($tmp); return false; }
		$expectedHash = hash('sha256', $expectedMedia);
		$found = false;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if (!str_starts_with($name, 'word/media/')) continue;
			$media = $zip->getFromIndex($i);
			if ($media !== false && hash_equals($expectedHash, hash('sha256', $media))) { $found = true; break; }
		}
		$zip->close();
		@unlink($tmp);
		return $found;
	}

	private function replaceServiceRows(string $xml, array $services, bool $isEstimate = false): string {
		$document = new \DOMDocument();
		if (!@$document->loadXML($xml, LIBXML_NONET)) return $xml;
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
		$prototype = ['heading' => null, 'item' => null, 'subtotal' => null];
		foreach ($xpath->query('//w:tr') ?: [] as $row) {
			$text = '';
			foreach ($xpath->query('.//w:t', $row) ?: [] as $node) $text .= $node->nodeValue;
			if (str_contains($text, '{{service.group_heading}}')) $prototype['heading'] = $row;
			if (str_contains($text, '{{service.title}}')) $prototype['item'] = $row;
			if (str_contains($text, '{{service.group_subtotal}}')) $prototype['subtotal'] = $row;
		}
		if ($prototype['heading'] !== null && $prototype['item'] !== null && $prototype['subtotal'] !== null) {
			$labels = $isEstimate
				? ['EL' => 'Eigene Leistungen – verbindliche Angebotspreise', 'FK' => 'Voraussichtliche Fremdleistungen und Fremdkosten', 'DP' => 'Voraussichtliche Gebühren und durchlaufende Posten']
				: ['EL' => 'Eigene Leistungen', 'FK' => 'Fremdleistungen und verauslagte Beträge', 'DP' => 'Durchlaufende Posten – nicht Teil des Entgelts'];
			$groups = ['EL' => [], 'FK' => [], 'DP' => []];
			foreach ($services as $service) {
				$type = strtoupper((string)($service['positionType'] ?? 'EL'));
				$groups[isset($groups[$type]) ? $type : 'EL'][] = $service;
			}
			$anchor = $prototype['heading'];
			$parent = $anchor->parentNode;
			foreach ($groups as $type => $items) {
				$subtotal = array_sum(array_map(static fn(array $item): int => (int)($item['grossCents'] ?? 0), $items));
				if ($items === [] || $subtotal === 0) continue;
				$heading = $prototype['heading']->cloneNode(true);
				$this->replacePlaceholdersInNode($xpath, $heading, ['{{service.group_heading}}' => $labels[$type]], false);
				$parent->insertBefore($heading, $anchor);
				foreach ($items as $position => $service) {
					$item = $prototype['item']->cloneNode(true);
					$this->replacePlaceholdersInNode($xpath, $item, $this->serviceReplacements($service, $position), false);
					$parent->insertBefore($item, $anchor);
				}
				$subtotalRow = $prototype['subtotal']->cloneNode(true);
				$this->replacePlaceholdersInNode($xpath, $subtotalRow, ['{{service.group_subtotal}}' => 'Zwischensumme ' . $labels[$type], '{{service.group_subtotal_amount}}' => $this->money($subtotal)], false);
				$parent->insertBefore($subtotalRow, $anchor);
			}
			foreach ($prototype as $row) $row->parentNode?->removeChild($row);
			return (string)$document->saveXML();
		}
		foreach ($xpath->query('//w:tr') ?: [] as $row) {
			$text = '';
			foreach ($xpath->query('.//w:t', $row) ?: [] as $node) $text .= $node->nodeValue;
			if (!str_contains($text, '{{service.title}}')) continue;
			$parent = $row->parentNode;
			if ($parent === null) continue;
			$sourceRows = $services ?: [['articleNumber' => '', 'title' => 'Keine Leistungen beauftragt', 'quantity' => 0, 'unit' => 'STK', 'unitPriceCents' => 0, 'vatRate' => 0, 'netCents' => 0, 'grossCents' => 0, 'sourcePackageName' => '']];
			foreach ($sourceRows as $position => $service) {
				$clone = $row->cloneNode(true);
				$replacements = $this->serviceReplacements($service, $position);
				$this->replacePlaceholdersInNode($xpath, $clone, $replacements, false);
				$parent->insertBefore($clone, $row);
			}
			$parent->removeChild($row);
		}
		return (string)$document->saveXML();
	}

	private function serviceReplacements(array $service, int $position): array {
		return [
			'{{service.position}}' => (string)($service['position'] ?? $service['articleNumber'] ?? (($position + 1) * 10)), '{{service.article_number}}' => (string)($service['articleNumber'] ?? ''),
			'{{service.title}}' => (string)($service['title'] ?? ''), '{{service.quantity}}' => trim($this->number((float)($service['quantity'] ?? 0)) . ' ' . $this->unitLabel((string)($service['unit'] ?? 'STK'))),
			'{{service.unit_price}}' => $this->money((int)($service['unitPriceCents'] ?? 0)), '{{service.vat_rate}}' => (string)($service['vatRate'] ?? 0) . ' %',
			'{{service.net}}' => $this->money((int)($service['netCents'] ?? 0)), '{{service.gross}}' => $this->money((int)($service['grossCents'] ?? 0)),
			'{{service.package}}' => (string)($service['sourcePackageName'] ?? ''),
		];
	}

	private function replaceInvoiceTaxRows(string $xml, array $services): string {
		$document = new \DOMDocument();
		if (!@$document->loadXML($xml, LIBXML_NONET)) return $xml;
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
		foreach ($xpath->query('//w:tr') ?: [] as $row) {
			$text = '';
			foreach ($xpath->query('.//w:t', $row) ?: [] as $node) $text .= $node->nodeValue;
			if (!str_contains($text, '{{tax.rate}}')) continue;
			$groups = [];
			foreach ($services as $service) {
				if (strtoupper((string)($service['positionType'] ?? 'EL')) === 'DP') continue;
				$rate = (int)($service['vatRate'] ?? 0);
				$groups[$rate] ??= ['net' => 0, 'vat' => 0];
				$groups[$rate]['net'] += (int)($service['netCents'] ?? 0);
				$groups[$rate]['vat'] += (int)($service['vatCents'] ?? round((int)($service['netCents'] ?? 0) * $rate / 100));
			}
			ksort($groups, SORT_NUMERIC);
			$parent = $row->parentNode;
			foreach ($groups as $rate => $amounts) {
				if ($amounts['net'] === 0 && $amounts['vat'] === 0) continue;
				$clone = $row->cloneNode(true);
				$this->replacePlaceholdersInNode($xpath, $clone, ['{{tax.rate}}' => $rate . ' %', '{{tax.net}}' => $this->money($amounts['net']), '{{tax.vat}}' => $this->money($amounts['vat'])], false);
				$parent->insertBefore($clone, $row);
			}
			$parent->removeChild($row);
		}
		return (string)$document->saveXML();
	}

	private function removeEmptyPassThroughSummary(string $xml, array $services): string {
		$passThrough = array_sum(array_map(static fn(array $item): int => strtoupper((string)($item['positionType'] ?? 'EL')) === 'DP' ? (int)($item['grossCents'] ?? 0) : 0, $services));
		if ($passThrough !== 0) return $xml;
		$document = new \DOMDocument();
		if (!@$document->loadXML($xml, LIBXML_NONET)) return $xml;
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
		foreach ($xpath->query('//w:tr') ?: [] as $row) {
			$text = '';
			foreach ($xpath->query('.//w:t', $row) ?: [] as $node) $text .= $node->nodeValue;
			if (str_contains($text, '{{order.pass_through_total}}')) $row->parentNode?->removeChild($row);
		}
		return (string)$document->saveXML();
	}

	private function removeEmptyPriorInvoiceSummary(string $xml, array $values): string {
		if (($values['{{case.invoice_has_prior}}'] ?? '0') === '1') return $xml;
		$document = new \DOMDocument();
		if (!@$document->loadXML($xml, LIBXML_NONET)) return $xml;
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
		foreach ($xpath->query('//w:tr') ?: [] as $row) {
			$text = '';
			foreach ($xpath->query('.//w:t', $row) ?: [] as $node) $text .= $node->nodeValue;
			if (str_contains($text, '{{case.invoice_prior_gross}}')) $row->parentNode?->removeChild($row);
		}
		return (string)$document->saveXML();
	}

	/**
	 * Word may split one placeholder over several formatted w:t runs. Replacing
	 * the raw XML therefore leaves visible field names behind. This routine
	 * resolves placeholders per paragraph across run boundaries and lets DOM
	 * perform the UTF-8/XML escaping. Any complete placeholder that has no
	 * value in the current document context is deliberately rendered empty.
	 */
	private function replaceTextPlaceholders(string $xml, array $values): string {
		$document = new \DOMDocument();
		$document->preserveWhiteSpace = true;
		if (!@$document->loadXML($xml, LIBXML_NONET)) return $xml;
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
		$this->replacePlaceholdersInNode($xpath, $document, $values, true);
		return (string)$document->saveXML();
	}

	private function replacePlaceholdersInNode(\DOMXPath $xpath, \DOMNode $context, array $values, bool $blankUnknown): void {
		$paragraphs = $xpath->query($context instanceof \DOMDocument ? '//w:p' : './/w:p', $context);
		foreach ($paragraphs ?: [] as $paragraph) {
			$textNodes = [];
			$text = '';
			foreach ($xpath->query('.//w:t', $paragraph) ?: [] as $node) {
				$textNodes[] = $node;
				$text .= (string)$node->nodeValue;
			}
			if ($textNodes === [] || !str_contains($text, '{{')) continue;
			preg_match_all('/\{\{[^{}]+\}\}/u', $text, $matches, PREG_OFFSET_CAPTURE);
			foreach (array_reverse($matches[0] ?? []) as [$placeholder, $offset]) {
				if (!array_key_exists($placeholder, $values) && !$blankUnknown) continue;
				$replacement = array_key_exists($placeholder, $values) ? (string)$values[$placeholder] : $this->formattedPlaceholder($placeholder, $values);
				$this->replaceTextRange($textNodes, (int)$offset, strlen((string)$placeholder), $replacement);
			}
		}
	}

	private function replaceTextRange(array $nodes, int $start, int $length, string $replacement): void {
		$end = $start + $length;
		$cursor = 0;
		$startIndex = null; $endIndex = null; $startLocal = 0; $endLocal = 0;
		foreach ($nodes as $index => $node) {
			$nodeLength = strlen((string)$node->nodeValue);
			$next = $cursor + $nodeLength;
			if ($startIndex === null && $start >= $cursor && $start < $next) {
				$startIndex = $index;
				$startLocal = $start - $cursor;
			}
			if ($end > $cursor && $end <= $next) {
				$endIndex = $index;
				$endLocal = $end - $cursor;
				break;
			}
			$cursor = $next;
		}
		if ($startIndex === null || $endIndex === null) return;
		$startValue = (string)$nodes[$startIndex]->nodeValue;
		if ($startIndex === $endIndex) {
			$this->setTextNodeContent($nodes[$startIndex], substr($startValue, 0, $startLocal) . $replacement . substr($startValue, $endLocal));
			$this->preserveTextWhitespace($nodes[$startIndex]);
			return;
		}
		$endValue = (string)$nodes[$endIndex]->nodeValue;
		$this->setTextNodeContent($nodes[$startIndex], substr($startValue, 0, $startLocal) . $replacement);
		for ($index = $startIndex + 1; $index < $endIndex; $index++) $this->setTextNodeContent($nodes[$index], '');
		$this->setTextNodeContent($nodes[$endIndex], substr($endValue, $endLocal));
		$this->preserveTextWhitespace($nodes[$startIndex]);
		$this->preserveTextWhitespace($nodes[$endIndex]);
	}

	private function setTextNodeContent(\DOMNode $node, string $value): void {
		while ($node->firstChild !== null) $node->removeChild($node->firstChild);
		$node->appendChild($node->ownerDocument->createTextNode($value));
	}

	private function preserveTextWhitespace(\DOMNode $node): void {
		$value = (string)$node->nodeValue;
		if ($node instanceof \DOMElement && $value !== trim($value)) $node->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
	}

	private function placeholderData(array $case, array $costing): array {
		$m = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$branch = $this->configuration->branchByKey((string)($case['branch'] ?? $m['branch'] ?? '')) ?? [];
		$values = [];
		foreach ($m as $key => $raw) if (is_scalar($raw) || $raw === null) $values['{{case.' . $key . '}}'] = $this->formatDocumentScalar((string)($raw ?? ''));
		$value = static fn(string $key, string $fallback = ''): string => (string)($m[$key] ?? $fallback);
		$isEstimate = strtoupper($value('order_mode', 'A')) === 'KVA';
		$taxableNetFromItems = 0;
		$passThroughFromItems = 0;
		foreach (($costing['items'] ?? []) as $item) {
			if (strtoupper((string)($item['positionType'] ?? 'EL')) === 'DP') $passThroughFromItems += (int)($item['grossCents'] ?? 0);
			else $taxableNetFromItems += (int)($item['netCents'] ?? 0);
		}
		if (($costing['items'] ?? []) === []) $taxableNetFromItems = (int)($costing['totals']['netCents'] ?? 0);
		$lastResidence = trim(implode(', ', array_filter([$value('last_residence_street', $value('last_residence')), trim($value('last_residence_postal_code') . ' ' . $value('last_residence_city')), $value('last_residence_country', 'Deutschland')])));
		$overrides = [
			'{{case.id}}' => (string)$case['caseNumber'], '{{case.case_number}}' => (string)$case['caseNumber'], '{{case.number}}' => (string)$case['caseNumber'],
			'{{case.first_name}}' => (string)$case['firstName'], '{{case.last_name}}' => (string)$case['lastName'],
			'{{case.date_of_death}}' => $this->deathDisplay($m, (string)($case['dateOfDeath'] ?? '')),
			'{{case.time_of_death}}' => $value('date_of_death') !== '' ? $value('time_of_death') : '',
			'{{case.last_residence}}' => $lastResidence, '{{case.cemetery}}' => $value('cemetery_contact', $value('cemetery')),
			'{{branch.name}}' => $value('branch'), '{{standesamt}}' => $value('registry_office'),
			'{{case.branch_name}}' => (string)($branch['name'] ?? $value('branch')), '{{case.branch_address}}' => trim(implode(', ', array_filter([(string)($branch['street'] ?? ''), trim((string)($branch['postalCode'] ?? '') . ' ' . (string)($branch['city'] ?? ''))]))),
			'{{case.branch_phone}}' => (string)($branch['phone'] ?? ''), '{{case.branch_email}}' => (string)($branch['email'] ?? ''), '{{case.branch_city}}' => (string)($branch['city'] ?? ''),
			'{{case.current_date}}' => date('d.m.Y'), '{{case.recipient_name}}' => $value('recipient_name'), '{{case.recipient_address}}' => $value('recipient_address'),
			'{{deceased_name}}' => trim((string)$case['firstName'] . ' ' . (string)$case['lastName']),
			'{{funeral_event.date}}' => $value('funeral_event_date'), '{{funeral_event.date_iso}}' => $value('funeral_event_date_iso'),
			'{{funeral_event.time}}' => $value('funeral_event_time'), '{{funeral_event.end_time}}' => $value('funeral_event_end_time'),
			'{{funeral_event.location}}' => $value('funeral_event_location'), '{{funeral_event.category}}' => $value('funeral_event_category'),
			'{{funeral_event.external_participants}}' => $value('funeral_event_external_participants'),
			'{{religion}}' => $value('religion'), '{{todesort_anschrift}}' => $value('place_of_death'),
			'{{minderjaehrige_kinder}}' => $value('minor_children_count'), '{{eingang_am}}' => '',
			'{{sterberegister_nr}}' => $value('registry_reference'), '{{geburtsregister_hinweis}}' => $value('birth_registry_reference'),
			'{{ehe_register_hinweis}}' => $value('marriage_registry_reference'), '{{lebenspartnerschaft_register_hinweis}}' => $value('partnership_registry_reference'),
			'{{nachweise}}' => $value('registry_documents'), '{{pruefvermerk}}' => '',
			'{{anzeigende_person}}' => trim($value('order_client_first_name') . ' ' . $value('order_client_name')),
			'{{anzeigende_anschrift}}' => trim(implode(', ', array_filter([$value('order_client_street'), $value('order_client_postal_city'), $value('order_client_country', 'Deutschland')]))),
			'{{auftrag_telefon}}' => $value('order_client_phone', $value('order_client_mobile')),
			'{{order.client_phone}}' => $value('order_client_phone', $value('order_client_mobile')),
			'{{urkunden_gebuehrenfrei}}' => $value('certificate_free_count', '2'),
			'{{urkunden_gebuehrenpflichtig}}' => $value('urkunden_gebuehrenpflichtig', '0'),
			'{{order.urkunden_gebuehrenpflichtig}}' => $value('urkunden_gebuehrenpflichtig', '0'),
			'{{ort_datum}}' => trim($value('branch') . ', ' . date('d.m.Y'), ', '),
			'{{order.document_title}}' => $isEstimate ? 'Kostenvoranschlag' : 'Bestattungsauftrag',
			'{{order.confirmation_text}}' => $isEstimate ? 'Dieser Kostenvoranschlag stellt die ausgewählten Bestattungsleistungen sowie die zum Erstellungsdatum voraussichtlich anfallenden Kosten dar.' : 'Ich beauftrage das Bestattungsunternehmen mit den abgestimmten Bestattungsleistungen und bestätige die Angaben dieses Auftrags.',
			'{{order.services_heading}}' => $isEstimate ? 'Voraussichtliche Leistungen und Kosten' : 'Beauftragte Leistungen und Kosten',
			'{{order.cost_note}}' => $isEstimate
				? 'Die Preise unserer eigenen Leistungen sind bis zum ausgewiesenen Gültigkeitsdatum verbindlich, sofern Art und Umfang der aufgeführten Leistungen unverändert bleiben. Beträge für Fremdleistungen und Fremdkosten sind Schätzbeträge, soweit sie nicht ausdrücklich als Festpreis bezeichnet sind; Preisänderungen Dritter können den Endbetrag verändern. Durchlaufende Posten werden in der tatsächlich verauslagten Höhe weitergegeben. Sobald eine wesentliche Kostenabweichung erkennbar wird, informieren wir den Auftraggeber unverzüglich.'
				: 'Die Einzelpreise verstehen sich netto. Die ausgewiesene Gesamtsumme enthält die gesetzliche Mehrwertsteuer. Fremdleistungen, Auslagen und Gebühren werden entsprechend ihrer vertraglichen und steuerlichen Behandlung dargestellt.',
			'{{order.tax_heading}}' => $isEstimate ? 'Voraussichtliche Umsatzsteuerübersicht' : 'Umsatzsteuerübersicht',
			'{{order.taxable_net_label}}' => $isEstimate ? 'Voraussichtliches steuerpflichtiges Entgelt netto' : 'Steuerpflichtiges Entgelt netto',
			'{{order.total_vat_label}}' => $isEstimate ? 'Voraussichtliche Mehrwertsteuer' : 'Mehrwertsteuer',
			'{{order.pass_through_label}}' => $isEstimate ? 'Voraussichtliche durchlaufende Posten' : 'Durchlaufende Posten',
			'{{order.total_gross_label}}' => $isEstimate ? 'Voraussichtlicher Gesamtbetrag' : 'Gesamtbetrag',
			'{{order.status}}' => $value('order_status', 'Entwurf'),
			'{{order.workflow_detail}}' => $isEstimate ? $value('kva_valid_until') : $value('commissioning_type'),
			'{{order.kva_number}}' => $value('order_number', $value('kva_number')),
			'{{order.kva_date}}' => $this->formatDocumentDate($isEstimate ? $value('kva_date') : $value('order_date')),
			'{{order.order_client_name}}' => trim($value('order_client_first_name') . ' ' . $value('order_client_name')),
			'{{order.order_client_relation}}' => $value('order_client_relation'),
			'{{order.order_client_phone}}' => $value('order_client_phone'), '{{order.order_client_mobile}}' => $value('order_client_mobile'),
			'{{order.order_client_email}}' => $value('order_client_email'),
			'{{order.invoice_recipient_address}}' => $value('invoice_recipient_address', trim(implode(', ', array_filter([$value('invoice_first_name', $value('order_client_first_name')), $value('invoice_name', $value('order_client_name')), $value('invoice_street', $value('order_client_street')), $value('invoice_postal_city', $value('order_client_postal_city')), $value('invoice_country', $value('order_client_country', 'Deutschland'))])))),
			'{{order.certificate_free_count}}' => $value('certificate_free_count', '2'),
			'{{order.order_signature_name}}' => $value('order_signature_name'), '{{order.order_signature_date}}' => $this->formatDocumentDate($value('order_signature_date')),
			'{{order.total_net}}' => $this->money((int)($costing['totals']['netCents'] ?? 0)),
			'{{order.total_vat}}' => $this->money((int)($costing['totals']['vatCents'] ?? 0)),
			'{{order.total_gross}}' => $this->money((int)($costing['totals']['grossCents'] ?? 0)),
			'{{order.taxable_net}}' => $this->money((int)($costing['invoiceTaxableNetCents'] ?? $taxableNetFromItems)),
			'{{order.pass_through_total}}' => $this->money((int)($costing['invoicePassThroughCents'] ?? $passThroughFromItems)),
		];
		return array_merge($values, $overrides);
	}

	private function deathDisplay(array $m, string $fallback = ''): string {
		$exact = trim((string)($m['date_of_death'] ?? $fallback));
		if ($exact !== '') return $this->formatDocumentDate($exact) . (($m['time_of_death'] ?? '') !== '' ? ' ' . $m['time_of_death'] . ' Uhr' : '');
		$from = trim((string)($m['death_time_from'] ?? '')); $to = trim((string)($m['death_time_to'] ?? ''));
		return $from !== '' || $to !== '' ? 'zwischen ' . ($from !== '' ? $this->formatDocumentDate($from) : '?') . ' und ' . ($to !== '' ? $this->formatDocumentDate($to) : '?') : '';
	}

	private function formattedPlaceholder(string $placeholder, array $values): string {
		if (!preg_match('/^\{\{([^{}|]+)\|date:(DD\.MM\.YYYY|DD\.MM\.YY|YYYY-MM-DD)\}\}$/u', $placeholder, $match)) return '';
		$base = '{{' . $match[1] . '}}';
		if (!array_key_exists($base, $values)) return '';
		$format = ['DD.MM.YYYY' => 'd.m.Y', 'DD.MM.YY' => 'd.m.y', 'YYYY-MM-DD' => 'Y-m-d'][$match[2]];
		return $this->formatDocumentDate((string)$values[$base], $format);
	}

	private function formatDocumentScalar(string $value): string {
		return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) ? $this->formatDocumentDate($value) : $value;
	}

	private function formatDocumentDate(string $value, string $format = 'd.m.Y'): string {
		$value = trim($value);
		if ($value === '') return '';
		foreach (['!Y-m-d', '!d.m.Y', '!d.m.y'] as $inputFormat) {
			$date = \DateTimeImmutable::createFromFormat($inputFormat, substr($value, 0, 10));
			if ($date instanceof \DateTimeImmutable) return $date->format($format);
		}
		return $value;
	}

	private function money(int $cents): string { return number_format($cents / 100, 2, ',', '.') . ' EUR'; }
	private function number(float $value): string { return rtrim(rtrim(number_format($value, 3, ',', ''), '0'), ','); }
	private function unitLabel(string $unit): string { return ['STK' => 'Stk.', 'PAUSCHAL' => 'pauschal', 'STD' => 'Std.', 'KM' => 'km', 'TAG' => 'Tage', 'KG' => 'kg', 'L' => 'l'][$unit] ?? $unit; }
}
