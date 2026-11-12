<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * 0.90.1 – Eingehende E-Rechnungen lesen (Belegeingang): XRechnung und ZUGFeRD/Factur-X in der Syntax UN/CEFACT CII
 * (CrossIndustryInvoice) sowie XRechnung in der Syntax UBL (Invoice, CreditNote).
 *
 * Reine Regeln ohne Nextcloud und ohne Datenbank (geprüft in tests/inbox-sources-0901-fixture.php):
 *  - read() liefert die Rechnungsangaben in einheitlicher Form oder null, wenn die Datei keine E-Rechnung ist.
 *  - toIncomingInvoice() macht daraus einen Vorschlag für die Eingangsrechnung der App (Kopf, Positionen, Hinweise).
 *    Was die Eingangsrechnung nicht abbilden kann (Steuersätze außer 0/7/19 %, Nachlässe, Gutschriften, Fremdwährung),
 *    wird nicht umgedeutet, sondern als Hinweis gemeldet; die Werte prüft und bestätigt immer ein Mensch.
 *  - Beträge werden aus den Dezimalzeichenketten der XML in Cent umgerechnet (ohne Gleitkomma-Rundungsfehler).
 *  - Externe Entitäten und DOCTYPE-Angaben werden abgelehnt.
 */
final class EInvoiceReader {
	public const MAX_XML_BYTES = 5 * 1024 * 1024;
	private const CII = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
	private const RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
	private const UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';
	private const UBL_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
	private const UBL_CREDIT = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';
	private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
	private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
	/** UN/ECE-Einheiten (Rec. 20/21) → Mengeneinheit der Eingangsrechnung. */
	private const UNITS = [
		'C62' => 'STK', 'H87' => 'STK', 'EA' => 'STK', 'XPP' => 'STK', 'PCE' => 'STK', 'NAR' => 'STK', 'SET' => 'STK',
		'LS' => 'PAUSCHAL', 'HUR' => 'STD', 'KMT' => 'KM', 'DAY' => 'TAG', 'KGM' => 'KG', 'LTR' => 'L',
	];
	private const UNIT_LABELS = ['STK' => 'Stk.', 'PAUSCHAL' => 'pauschal', 'STD' => 'Std.', 'KM' => 'km', 'TAG' => 'Tag(e)', 'KG' => 'kg', 'L' => 'l'];
	/** Rechnungsarten (UNTDID 1001), die als Rechnung gelten; 381/396 sind Gutschriften. */
	private const CREDIT_TYPES = ['381', '396', '261', '262', '296', '308', '532'];

	/** Erkennt eine E-Rechnung am Wurzelelement, ohne die Datei vollständig zu lesen. */
	public static function looksLikeInvoiceXml(string $xml): bool {
		$head = substr($xml, 0, 4096);
		return str_contains($head, self::CII) || str_contains($head, self::UBL_INVOICE) || str_contains($head, self::UBL_CREDIT);
	}

	/**
	 * @return array{syntax:string,syntaxLabel:string,typeCode:string,creditNote:bool,number:string,issueDate:string,dueDate:string,currency:string,
	 *   seller:array{name:string,vatId:string,taxNumber:string},buyer:array{name:string,reference:string},orderReference:string,paymentReference:string,
	 *   notes:list<string>,iban:string,lines:list<array{name:string,quantity:string,unitCode:string,netPrice:string,netAmount:string,vatRate:string}>,
	 *   charges:list<array{reason:string,amount:string,vatRate:string,charge:bool}>,totals:array{net:string,tax:string,gross:string,payable:string},guideline:string}|null
	 */
	public static function read(string $xml): ?array {
		$xml = ltrim($xml, "\xEF\xBB\xBF \t\r\n");
		if ($xml === '' || strlen($xml) > self::MAX_XML_BYTES || !self::looksLikeInvoiceXml($xml)) {
			return null;
		}
		if (stripos(substr($xml, 0, 4096), '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
			return null;
		}
		$doc = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		try {
			if (!$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT)) {
				return null;
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		$root = $doc->documentElement;
		if ($root === null) {
			return null;
		}
		$path = new \DOMXPath($doc);
		if ($root->namespaceURI === self::CII && $root->localName === 'CrossIndustryInvoice') {
			$path->registerNamespace('rsm', self::CII);
			$path->registerNamespace('ram', self::RAM);
			$path->registerNamespace('udt', self::UDT);
			return self::readCii($path);
		}
		if (in_array($root->namespaceURI, [self::UBL_INVOICE, self::UBL_CREDIT], true) && in_array($root->localName, ['Invoice', 'CreditNote'], true)) {
			$path->registerNamespace('ubl', (string)$root->namespaceURI);
			$path->registerNamespace('cac', self::CAC);
			$path->registerNamespace('cbc', self::CBC);
			return self::readUbl($path, $root->localName === 'CreditNote');
		}
		return null;
	}

	/**
	 * Vorschlag für die Eingangsrechnung (Felder wie IncomingInvoiceService::create). Positionen, deren Menge × Preis
	 * nicht centgenau den Positionsbetrag ergibt oder deren Einheit unbekannt ist, werden pauschal mit dem
	 * Positionsbetrag übernommen; Menge und Einzelpreis stehen dann in der Bezeichnung.
	 * @return array{data:array<string,mixed>,warnings:list<string>,importable:bool}
	 */
	public static function toIncomingInvoice(array $invoice, string $classification = 'CLASSIFICATION_PENDING', string $receivedDate = ''): array {
		$warnings = [];
		$importable = true;
		if ($invoice['creditNote']) {
			$warnings[] = 'Die E-Rechnung ist eine Gutschrift oder Rechnungskorrektur (Art ' . $invoice['typeCode'] . '). Als Eingangsrechnung wird sie nicht vorbelegt; bitte als Dokument ablegen oder von Hand erfassen.';
			$importable = false;
		}
		if ($invoice['currency'] !== '' && $invoice['currency'] !== 'EUR') {
			$warnings[] = 'Die E-Rechnung lautet auf ' . $invoice['currency'] . ', nicht auf Euro. Beträge bitte von Hand prüfen.';
			$importable = false;
		}
		$items = [];
		// Gutschriften bildet die Eingangsrechnung nicht ab: keine Positionen vorbelegen (Beträge wären negativ oder umgedeutet).
		foreach ($invoice['creditNote'] ? [] : $invoice['lines'] as $line) {
			$item = self::item($line['name'], $line['quantity'], $line['unitCode'], $line['netPrice'], $line['netAmount'], $line['vatRate'], $classification, $warnings);
			if ($item !== null) {
				$items[] = $item;
			}
		}
		foreach ($invoice['creditNote'] ? [] : $invoice['charges'] as $charge) {
			if (!$charge['charge']) {
				$warnings[] = sprintf('Nachlass auf Belegebene „%s“ (%s €) kann nicht als Position übernommen werden – die Differenz zum Bruttobetrag bitte prüfen.', $charge['reason'] !== '' ? $charge['reason'] : 'ohne Bezeichnung', self::formatAmount($charge['amount']));
				continue;
			}
			$item = self::item('Zuschlag' . ($charge['reason'] !== '' ? ': ' . $charge['reason'] : ''), '1', 'LS', $charge['amount'], $charge['amount'], $charge['vatRate'], $classification, $warnings);
			if ($item !== null) {
				$items[] = $item;
			}
		}
		if ($items === [] && !$invoice['creditNote']) {
			$warnings[] = 'Die E-Rechnung enthält keine übernehmbare Position.';
			$importable = false;
		}
		$gross = self::toCents($invoice['totals']['gross'] !== '' ? $invoice['totals']['gross'] : $invoice['totals']['payable']);
		if ($gross <= 0 && !$invoice['creditNote']) {
			$warnings[] = 'Der Gesamtbetrag der E-Rechnung ist nicht positiv.';
			$importable = false;
		}
		$sum = 0;
		foreach ($items as $item) {
			$net = (int)round($item['supplierQuantityMilli'] * $item['supplierUnitCents'] / 1000);
			$sum += $net + (int)round($net * $item['supplierVatRate'] / 100);
		}
		if ($items !== [] && $gross > 0 && abs($sum - $gross) > 1) {
			$warnings[] = sprintf('Die übernommenen Positionen ergeben %s € brutto, die E-Rechnung nennt %s €. Bitte vor der Freigabe abgleichen.', self::formatCents($sum), self::formatCents($gross));
		}
		$notes = ['Vorbelegt aus der E-Rechnung (' . $invoice['syntaxLabel'] . '). Alle Werte vor der Freigabe prüfen.'];
		if ($invoice['iban'] !== '') {
			$notes[] = 'Zahlung an IBAN ' . $invoice['iban'] . ($invoice['paymentReference'] !== '' ? ', Verwendungszweck ' . $invoice['paymentReference'] : '') . '.';
		}
		if ($invoice['seller']['vatId'] !== '') {
			$notes[] = 'USt-IdNr. Lieferant: ' . $invoice['seller']['vatId'] . '.';
		}
		$data = [
			'supplierName' => mb_substr($invoice['seller']['name'], 0, 200), 'supplierInvoiceNumber' => mb_substr($invoice['number'], 0, 100),
			'invoiceDate' => $invoice['issueDate'], 'receivedDate' => $receivedDate !== '' ? $receivedDate : date('Y-m-d'), 'dueDate' => $invoice['dueDate'],
			'declaredGrossCents' => max(0, $gross), 'notes' => implode(' ', $notes), 'items' => $items,
		];
		if ($data['supplierName'] === '' || $data['supplierInvoiceNumber'] === '' || $data['invoiceDate'] === '') {
			$warnings[] = 'Lieferant, Rechnungsnummer oder Rechnungsdatum fehlen in der E-Rechnung.';
		}
		return ['data' => $data, 'warnings' => array_values(array_unique($warnings)), 'importable' => $importable];
	}

	/** Text für die Fallerkennung (Käuferreferenz, Bestellnummer, Hinweise, Positionen, Rechnungsempfänger). */
	public static function searchText(array $invoice): string {
		$parts = [$invoice['buyer']['reference'], $invoice['orderReference'], $invoice['paymentReference'], $invoice['buyer']['name'], ...$invoice['notes']];
		foreach ($invoice['lines'] as $line) {
			$parts[] = $line['name'];
		}
		return trim(implode("\n", array_filter(array_map('trim', $parts), static fn (string $value): bool => $value !== '')));
	}

	/** Kurzfassung für Belegeingang und Metadaten (ohne Positionstexte). */
	public static function summary(array $invoice): array {
		return [
			'syntax' => $invoice['syntaxLabel'], 'number' => $invoice['number'], 'issueDate' => $invoice['issueDate'], 'dueDate' => $invoice['dueDate'],
			'seller' => $invoice['seller']['name'], 'grossCents' => self::toCents($invoice['totals']['gross'] !== '' ? $invoice['totals']['gross'] : $invoice['totals']['payable']),
			'currency' => $invoice['currency'], 'lines' => count($invoice['lines']), 'creditNote' => $invoice['creditNote'], 'buyerReference' => $invoice['buyer']['reference'],
		];
	}

	/** „123.45“, „-5“, „12.3456“ → Cent (kaufmännisch gerundet). */
	public static function toCents(string $value): int {
		return self::scaled($value, 2);
	}

	/** Menge mit drei Nachkommastellen („2.5“ → 2500). */
	public static function toMilli(string $value): int {
		return self::scaled($value, 3);
	}

	// ------------------------------------------------------------------ intern

	private static function readCii(\DOMXPath $p): array {
		$header = 'rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement';
		$agreement = 'rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement';
		$typeCode = self::text($p, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode');
		$lines = [];
		foreach ($p->query('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem') ?: [] as $node) {
			$basis = self::text($p, 'ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity', $node);
			$price = self::text($p, 'ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', $node);
			$lines[] = [
				'name' => self::text($p, 'ram:SpecifiedTradeProduct/ram:Name', $node),
				'quantity' => self::text($p, 'ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', $node),
				'unitCode' => self::attribute($p, 'ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', 'unitCode', $node),
				'netPrice' => self::perUnit($price, $basis),
				'netAmount' => self::text($p, 'ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', $node),
				'vatRate' => self::text($p, 'ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent', $node),
			];
		}
		$charges = [];
		foreach ($p->query('/rsm:CrossIndustryInvoice/' . $header . '/ram:SpecifiedTradeAllowanceCharge') ?: [] as $node) {
			$charges[] = [
				'reason' => self::text($p, 'ram:Reason', $node),
				'amount' => self::text($p, 'ram:ActualAmount', $node),
				'vatRate' => self::text($p, 'ram:CategoryTradeTax/ram:RateApplicablePercent', $node),
				'charge' => in_array(strtolower(self::text($p, 'ram:ChargeIndicator/udt:Indicator', $node)), ['true', '1'], true),
			];
		}
		$notes = [];
		foreach ($p->query('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IncludedNote/ram:Content') ?: [] as $node) {
			$notes[] = trim((string)$node->textContent);
		}
		$seller = '/rsm:CrossIndustryInvoice/' . $agreement . '/ram:SellerTradeParty';
		$taxRegistration = static function (string $scheme) use ($p, $seller): string {
			foreach ($p->query($seller . '/ram:SpecifiedTaxRegistration/ram:ID') ?: [] as $node) {
				if ($node instanceof \DOMElement && strtoupper($node->getAttribute('schemeID')) === $scheme) {
					return trim((string)$node->textContent);
				}
			}
			return '';
		};
		$guideline = self::text($p, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID');
		$totals = '/rsm:CrossIndustryInvoice/' . $header . '/ram:SpecifiedTradeSettlementHeaderMonetarySummation';
		return [
			'syntax' => 'CII', 'syntaxLabel' => str_contains($guideline, 'xrechnung') ? 'XRechnung (CII)' : 'ZUGFeRD/Factur-X (CII)', 'guideline' => $guideline,
			'typeCode' => $typeCode, 'creditNote' => in_array($typeCode, self::CREDIT_TYPES, true),
			'number' => self::text($p, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID'),
			'issueDate' => self::ciiDate(self::text($p, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString')),
			'dueDate' => self::ciiDate(self::text($p, '/rsm:CrossIndustryInvoice/' . $header . '/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString')),
			'currency' => strtoupper(self::text($p, '/rsm:CrossIndustryInvoice/' . $header . '/ram:InvoiceCurrencyCode')),
			'seller' => ['name' => self::text($p, $seller . '/ram:Name'), 'vatId' => $taxRegistration('VA'), 'taxNumber' => $taxRegistration('FC')],
			'buyer' => ['name' => self::text($p, '/rsm:CrossIndustryInvoice/' . $agreement . '/ram:BuyerTradeParty/ram:Name'), 'reference' => self::text($p, '/rsm:CrossIndustryInvoice/' . $agreement . '/ram:BuyerReference')],
			'orderReference' => self::text($p, '/rsm:CrossIndustryInvoice/' . $agreement . '/ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID'),
			'paymentReference' => self::text($p, '/rsm:CrossIndustryInvoice/' . $header . '/ram:PaymentReference'),
			'notes' => array_values(array_filter($notes, static fn (string $value): bool => $value !== '')),
			'iban' => self::text($p, '/rsm:CrossIndustryInvoice/' . $header . '/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:IBANID'),
			'lines' => $lines, 'charges' => $charges,
			'totals' => [
				'net' => self::text($p, $totals . '/ram:TaxBasisTotalAmount'),
				'tax' => self::firstTaxTotal($p, $totals . '/ram:TaxTotalAmount', strtoupper(self::text($p, '/rsm:CrossIndustryInvoice/' . $header . '/ram:InvoiceCurrencyCode'))),
				'gross' => self::text($p, $totals . '/ram:GrandTotalAmount'),
				'payable' => self::text($p, $totals . '/ram:DuePayableAmount'),
			],
		];
	}

	private static function readUbl(\DOMXPath $p, bool $creditNote): array {
		$root = $creditNote ? '/ubl:CreditNote' : '/ubl:Invoice';
		$typeCode = self::text($p, $root . ($creditNote ? '/cbc:CreditNoteTypeCode' : '/cbc:InvoiceTypeCode'));
		$lines = [];
		foreach ($p->query($root . ($creditNote ? '/cac:CreditNoteLine' : '/cac:InvoiceLine')) ?: [] as $node) {
			$quantityPath = $creditNote ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity';
			$lines[] = [
				'name' => self::text($p, 'cac:Item/cbc:Name', $node),
				'quantity' => self::text($p, $quantityPath, $node),
				'unitCode' => self::attribute($p, $quantityPath, 'unitCode', $node),
				'netPrice' => self::perUnit(self::text($p, 'cac:Price/cbc:PriceAmount', $node), self::text($p, 'cac:Price/cbc:BaseQuantity', $node)),
				'netAmount' => self::text($p, 'cbc:LineExtensionAmount', $node),
				'vatRate' => self::text($p, 'cac:Item/cac:ClassifiedTaxCategory/cbc:Percent', $node),
			];
		}
		$charges = [];
		foreach ($p->query($root . '/cac:AllowanceCharge') ?: [] as $node) {
			$charges[] = [
				'reason' => self::text($p, 'cbc:AllowanceChargeReason', $node),
				'amount' => self::text($p, 'cbc:Amount', $node),
				'vatRate' => self::text($p, 'cac:TaxCategory/cbc:Percent', $node),
				'charge' => in_array(strtolower(self::text($p, 'cbc:ChargeIndicator', $node)), ['true', '1'], true),
			];
		}
		$notes = [];
		foreach ($p->query($root . '/cbc:Note') ?: [] as $node) {
			$notes[] = trim((string)$node->textContent);
		}
		$supplier = $root . '/cac:AccountingSupplierParty/cac:Party';
		$sellerName = self::text($p, $supplier . '/cac:PartyLegalEntity/cbc:RegistrationName');
		if ($sellerName === '') {
			$sellerName = self::text($p, $supplier . '/cac:PartyName/cbc:Name');
		}
		$vatId = '';
		$taxNumber = '';
		foreach ($p->query($supplier . '/cac:PartyTaxScheme') ?: [] as $node) {
			$scheme = strtoupper(self::text($p, 'cac:TaxScheme/cbc:ID', $node));
			$value = self::text($p, 'cbc:CompanyID', $node);
			if ($scheme === 'VAT' && $vatId === '') {
				$vatId = $value;
			} elseif ($scheme !== 'VAT' && $taxNumber === '') {
				$taxNumber = $value;
			}
		}
		$buyerName = self::text($p, $root . '/cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName');
		if ($buyerName === '') {
			$buyerName = self::text($p, $root . '/cac:AccountingCustomerParty/cac:Party/cac:PartyName/cbc:Name');
		}
		$currency = strtoupper(self::text($p, $root . '/cbc:DocumentCurrencyCode'));
		return [
			'syntax' => 'UBL', 'syntaxLabel' => 'XRechnung (UBL)', 'guideline' => self::text($p, $root . '/cbc:CustomizationID'),
			'typeCode' => $typeCode !== '' ? $typeCode : ($creditNote ? '381' : '380'), 'creditNote' => $creditNote || in_array($typeCode, self::CREDIT_TYPES, true),
			'number' => self::text($p, $root . '/cbc:ID'),
			'issueDate' => self::isoDate(self::text($p, $root . '/cbc:IssueDate')),
			'dueDate' => self::isoDate(self::text($p, $root . '/cbc:DueDate') !== '' ? self::text($p, $root . '/cbc:DueDate') : self::text($p, $root . '/cac:PaymentMeans/cbc:PaymentDueDate')),
			'currency' => $currency,
			'seller' => ['name' => $sellerName, 'vatId' => $vatId, 'taxNumber' => $taxNumber],
			'buyer' => ['name' => $buyerName, 'reference' => self::text($p, $root . '/cbc:BuyerReference')],
			'orderReference' => self::text($p, $root . '/cac:OrderReference/cbc:ID'),
			'paymentReference' => self::text($p, $root . '/cac:PaymentMeans/cbc:PaymentID'),
			'notes' => array_values(array_filter($notes, static fn (string $value): bool => $value !== '')),
			'iban' => self::text($p, $root . '/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID'),
			'lines' => $lines, 'charges' => $charges,
			'totals' => [
				'net' => self::text($p, $root . '/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'),
				'tax' => self::firstTaxTotal($p, $root . '/cac:TaxTotal/cbc:TaxAmount', $currency),
				'gross' => self::text($p, $root . '/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'),
				'payable' => self::text($p, $root . '/cac:LegalMonetaryTotal/cbc:PayableAmount'),
			],
		];
	}

	/** @param list<string> $warnings */
	private static function item(string $name, string $quantity, string $unitCode, string $netPrice, string $netAmount, string $vatRate, string $classification, array &$warnings): ?array {
		$name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
		$name = $name !== '' ? $name : 'Position ohne Bezeichnung';
		$net = self::toCents($netAmount !== '' ? $netAmount : $netPrice);
		if ($net < 0) {
			$warnings[] = sprintf('Position „%s“ mit negativem Betrag (%s €) kann nicht übernommen werden – bitte prüfen.', mb_substr($name, 0, 80), self::formatCents($net));
			return null;
		}
		$rateCents = self::toCents($vatRate !== '' ? $vatRate : '0');
		$rate = intdiv($rateCents, 100);
		if ($rateCents % 100 !== 0 || !in_array($rate, [0, 7, 19], true)) {
			$warnings[] = sprintf('Position „%s“: Steuersatz %s %% wird von der Eingangsrechnung nicht unterstützt (nur 0, 7 oder 19 %%); vorbelegt mit 19 %% – bitte korrigieren.', mb_substr($name, 0, 80), self::formatAmount($vatRate));
			$rate = 19;
		}
		$milli = self::toMilli($quantity !== '' ? $quantity : '1');
		$unit = self::UNITS[strtoupper(trim($unitCode))] ?? null;
		$unitCents = $milli > 0 ? (int)round($net * 1000 / $milli) : 0;
		if ($milli > 0 && $unit !== null && (int)round($milli * $unitCents / 1000) === $net) {
			$description = $name;
		} else {
			// Menge, Einheit oder Einzelpreis nicht centgenau abbildbar: pauschal mit dem Positionsbetrag.
			$detail = $quantity !== '' ? sprintf(' (%s %s × %s €)', self::formatQuantity($quantity), $unit !== null ? self::UNIT_LABELS[$unit] : (trim($unitCode) !== '' ? trim($unitCode) : 'Einh.'), self::formatAmount($netPrice !== '' ? $netPrice : $netAmount)) : '';
			$description = $name . $detail;
			$milli = 1000;
			$unit = 'PAUSCHAL';
			$unitCents = $net;
		}
		return [
			'description' => mb_substr($description, 0, 500), 'supplierQuantityMilli' => $milli, 'unit' => $unit, 'supplierUnitCents' => $unitCents,
			'supplierVatRate' => $rate, 'classification' => $classification, 'customerQuantityMilli' => $milli, 'customerUnitCents' => $unitCents, 'customerVatRate' => $rate,
		];
	}

	private static function text(\DOMXPath $p, string $query, ?\DOMNode $context = null): string {
		$nodes = $context !== null ? $p->query($query, $context) : $p->query($query);
		if ($nodes === false || $nodes->length === 0) {
			return '';
		}
		return trim(preg_replace('/\s+/u', ' ', (string)$nodes->item(0)->textContent) ?? '');
	}

	private static function attribute(\DOMXPath $p, string $query, string $name, ?\DOMNode $context = null): string {
		$nodes = $context !== null ? $p->query($query, $context) : $p->query($query);
		$node = $nodes !== false ? $nodes->item(0) : null;
		return $node instanceof \DOMElement ? trim($node->getAttribute($name)) : '';
	}

	/** Steuerbetrag in Rechnungswährung (es kann einen zweiten in Buchungswährung geben). */
	private static function firstTaxTotal(\DOMXPath $p, string $query, string $currency): string {
		$fallback = '';
		foreach ($p->query($query) ?: [] as $node) {
			$value = trim((string)$node->textContent);
			if ($node instanceof \DOMElement && ($currency === '' || strtoupper($node->getAttribute('currencyID')) === $currency || $node->getAttribute('currencyID') === '')) {
				return $value;
			}
			$fallback = $fallback !== '' ? $fallback : $value;
		}
		return $fallback;
	}

	/** Preis je Einheit; ein Basispreis für z. B. 100 Stück wird umgerechnet (nur zur Anzeige). */
	private static function perUnit(string $price, string $basis): string {
		if ($price === '' || $basis === '' || self::toMilli($basis) === 1000 || self::toMilli($basis) <= 0) {
			return $price;
		}
		return number_format(self::toCents($price) / 100 / (self::toMilli($basis) / 1000), 4, '.', '');
	}

	private static function ciiDate(string $value): string {
		$value = trim($value);
		if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m) === 1 && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			return sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
		}
		return self::isoDate($value);
	}

	private static function isoDate(string $value): string {
		$value = trim($value);
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1 && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			return sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
		}
		return '';
	}

	private static function scaled(string $value, int $digits): int {
		$value = str_replace([' ', "\u{00A0}"], '', trim($value));
		if (preg_match('/^([+-]?)(\d*)(?:[.,](\d*))?$/', $value, $m) !== 1 || ($m[2] === '' && ($m[3] ?? '') === '')) {
			return 0;
		}
		$fraction = str_pad($m[3] ?? '', $digits + 1, '0');
		$result = (int)($m[2] !== '' ? $m[2] : '0') * (10 ** $digits) + (int)substr($fraction, 0, $digits);
		if ((int)$fraction[$digits] >= 5) {
			$result++;
		}
		return $m[1] === '-' ? -$result : $result;
	}

	private static function formatCents(int $cents): string {
		return number_format($cents / 100, 2, ',', '.');
	}

	private static function formatAmount(string $value): string {
		$cents = self::toCents($value);
		$exact = self::scaled($value, 4);
		return $exact % 100 !== 0 ? number_format($exact / 10000, 4, ',', '.') : self::formatCents($cents);
	}

	private static function formatQuantity(string $value): string {
		$milli = self::toMilli($value);
		return $milli % 1000 === 0 ? (string)intdiv($milli, 1000) : rtrim(rtrim(number_format($milli / 1000, 3, ',', ''), '0'), ',');
	}
}
