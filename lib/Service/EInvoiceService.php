<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

/** Creates an EN 16931 CII data record and the EPC payment QR payload. */
class EInvoiceService {
	/** Durchlaufende Posten (§ 10 Abs. 1 Satz 6 UStG): EN-16931-Kategorie E mit Befreiungsgrund, da „O“ nicht mit anderen Kategorien kombiniert werden darf (BR-O-11). */
	public const PASS_THROUGH_REASON = 'Durchlaufender Posten gemäß § 10 Abs. 1 Satz 6 UStG – nicht Teil des Entgelts.';

	public function __construct(
		private ?StructuredReferenceGenerator $references = null,
		private ?IbanValidator $ibanValidator = null,
	) {
		$this->references ??= new StructuredReferenceGenerator();
		$this->ibanValidator ??= new IbanValidator();
	}
	public function validateXml(string $xml, string $standard = 'ZUGFERD'): array {
		$doc = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $doc->loadXML($xml, LIBXML_NONET);
		$parseErrors = array_map(static fn(\LibXMLError $error): string => trim($error->message), libxml_get_errors());
		libxml_clear_errors(); libxml_use_internal_errors($previous);
		if (!$loaded) return ['valid' => false, 'level' => 'INTERNAL_STRUCTURE', 'errors' => $parseErrors ?: ['XML ist nicht wohlgeformt.']];
		$xpath = new \DOMXPath($doc);
		$xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
		$xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
		$required = [
			'//rsm:ExchangedDocument/ram:ID' => 'Rechnungsnummer',
			'//rsm:ExchangedDocument/ram:IssueDateTime' => 'Rechnungsdatum',
			'//ram:SellerTradeParty/ram:Name' => 'Verkäufer',
			'//ram:BuyerTradeParty/ram:Name' => 'Käufer',
			'//ram:InvoiceCurrencyCode' => 'Währung',
			'//ram:GrandTotalAmount' => 'Bruttosumme',
			'//ram:DuePayableAmount' => 'Zahlbetrag',
		];
		// 0.70.0: Bei Korrekturbelegen (381) keine Überweisungs-IBAN, dafür Pflichtbezug auf die Rechnung.
		$isCreditNote = trim((string)$xpath->evaluate('string(//rsm:ExchangedDocument/ram:TypeCode)')) === '381';
		if ($isCreditNote) $required['//ram:InvoiceReferencedDocument/ram:IssuerAssignedID'] = 'Bezug auf die Rechnung';
		else $required['//ram:IBANID'] = 'IBAN';
		$errors = [];
		foreach ($required as $query => $label) {
			$nodes = $xpath->query($query);
			if ($nodes === false || $nodes->length === 0 || trim((string)$nodes->item(0)?->textContent) === '') $errors[] = $label . ' fehlt.';
		}
		$lines = $xpath->query('//ram:IncludedSupplyChainTradeLineItem');
		if ($lines === false || $lines->length === 0) $errors[] = 'Mindestens eine Rechnungsposition ist erforderlich.';
		// 0.73.0: Ausgewählte EN-16931-Geschäftsregeln (Rechensummen, Steuerkennung), die bisher erst ein externer
		// Validator gemeldet hätte. Maßgeblich bleibt die normative Prüfung (Mustang), sofern eingerichtet.
		$number = static fn(string $query): float => (float)$xpath->evaluate('sum(' . $query . ')');
		$cents = static fn(float $value): int => (int)round($value * 100);
		$lineSum = $cents($number('//ram:IncludedSupplyChainTradeLineItem/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount'));
		$summation = '//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/';
		$lineTotal = $cents($number($summation . 'ram:LineTotalAmount'));
		$basisTotal = $cents($number($summation . 'ram:TaxBasisTotalAmount'));
		$taxTotal = $cents($number($summation . 'ram:TaxTotalAmount'));
		$grandTotal = $cents($number($summation . 'ram:GrandTotalAmount'));
		$groupTax = $cents($number('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CalculatedAmount'));
		$groupBasis = $cents($number('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:BasisAmount'));
		if ($lineSum !== $lineTotal) $errors[] = 'Summe der Positionen weicht von der Nettosumme ab (BR-CO-10).';
		if ($groupBasis !== $basisTotal) $errors[] = 'Summe der Steuergruppen weicht von der Steuerbemessungsgrundlage ab.';
		if ($groupTax !== $taxTotal) $errors[] = 'Summe der Umsatzsteuer je Steuergruppe weicht von der Steuersumme ab (BR-CO-14).';
		if ($basisTotal + $taxTotal !== $grandTotal) $errors[] = 'Bruttosumme ist nicht Nettosumme plus Umsatzsteuer (BR-CO-15).';
		$groups = $xpath->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax');
		foreach ($groups === false ? [] : $groups as $group) {
			$category = trim((string)$xpath->evaluate('string(ram:CategoryCode)', $group));
			$rate = trim((string)$xpath->evaluate('string(ram:RateApplicablePercent)', $group));
			$basis = $cents((float)$xpath->evaluate('number(ram:BasisAmount)', $group));
			$tax = $cents((float)$xpath->evaluate('number(ram:CalculatedAmount)', $group));
			$lineBasis = $cents($number('//ram:IncludedSupplyChainTradeLineItem[ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode="' . $category . '" and number(ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent)=' . (float)$rate . ']/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount'));
			if ($lineBasis !== $basis) $errors[] = 'Steuergruppe ' . $category . ' ' . $rate . ' %: Bemessungsgrundlage passt nicht zu den Positionen.';
			if (abs((int)round($basis * (float)$rate / 100) - $tax) > 100) $errors[] = 'Steuergruppe ' . $category . ' ' . $rate . ' %: Steuerbetrag passt nicht zum Steuersatz.';
			if ($category === 'E' && trim((string)$xpath->evaluate('string(ram:ExemptionReason)', $group)) === '') $errors[] = 'Befreiungsgrund der steuerfreien Steuergruppe fehlt (BR-E-10).';
		}
		$standardRated = (float)$xpath->evaluate('count(//ram:IncludedSupplyChainTradeLineItem/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax[ram:CategoryCode="S"])') > 0;
		$sellerTax = trim((string)$xpath->evaluate('string(//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID)'));
		if ($standardRated && $sellerTax === '') $errors[] = 'USt-IdNr. oder Steuernummer der Niederlassung fehlt (BR-S-02). Bitte unter Administration → Niederlassungen ergänzen.';
		$sellerIdentifier = trim((string)$xpath->evaluate('string(//ram:SellerTradeParty/ram:ID)')) . trim((string)$xpath->evaluate('string(//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID)')) . trim((string)$xpath->evaluate('string(//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"])'));
		if ($sellerIdentifier === '') $errors[] = 'Kennung des Verkäufers fehlt (BR-CO-26): USt-IdNr., Registernummer oder Steuernummer der Niederlassung ergänzen.';
		if (strtoupper($standard) === 'XRECHNUNG') {
			$guideline = trim((string)$xpath->evaluate('string(//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID)'));
			if (!str_contains($guideline, 'xrechnung_3.0')) $errors[] = 'Die XRechnung-Kennung 3.0 fehlt.';
			foreach ([
				'//ram:SellerTradeParty/ram:URIUniversalCommunication/ram:URIID' => 'Elektronische Adresse des Verkäufers',
				'//ram:BuyerTradeParty/ram:URIUniversalCommunication/ram:URIID' => 'Elektronische Adresse des Käufers',
			] as $query => $label) {
				$nodes = $xpath->query($query);
				if ($nodes === false || $nodes->length === 0 || trim((string)$nodes->item(0)?->textContent) === '') $errors[] = $label . ' fehlt.';
			}
		}
		return ['valid' => $errors === [], 'level' => 'INTERNAL_STRUCTURE', 'standard' => strtoupper($standard), 'errors' => $errors];
	}

	public function createXml(array $case, array $invoice, array $branch, array $settings, string $standard = 'ZUGFERD'): string {
		$doc = new \DOMDocument('1.0', 'UTF-8');
		$doc->formatOutput = true;
		$ns = ['rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100', 'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100', 'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100'];
		$root = $doc->createElementNS($ns['rsm'], 'rsm:CrossIndustryInvoice'); $doc->appendChild($root);
		$root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ram', $ns['ram']); $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:udt', $ns['udt']);
		$context = $this->child($doc, $root, $ns['rsm'], 'rsm:ExchangedDocumentContext');
		$isXRechnung = strtoupper($standard) === 'XRECHNUNG';
		// 0.73.0: BusinessProcess (BT-23) steht laut CII-Schema vor der Guideline-Kennung (BT-24).
		if ($isXRechnung) {
			$business = $this->child($doc, $context, $ns['ram'], 'ram:BusinessProcessSpecifiedDocumentContextParameter');
			$this->text($doc, $business, $ns['ram'], 'ram:ID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');
		}
		$parameter = $this->child($doc, $context, $ns['ram'], 'ram:GuidelineSpecifiedDocumentContextParameter');
		$this->text($doc, $parameter, $ns['ram'], 'ram:ID', $isXRechnung ? 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0' : 'urn:cen.eu:en16931:2017');
		$header = $this->child($doc, $root, $ns['rsm'], 'rsm:ExchangedDocument');
		// 0.70.0: Stornorechnung und Gutschrift als Credit Note (381) mit positiven Beträgen und Rechnungsbezug.
		$isCredit = ($invoice['documentKind'] ?? 'INVOICE') !== 'INVOICE';
		$sign = $isCredit ? -1 : 1;
		$this->text($doc, $header, $ns['ram'], 'ram:ID', (string)$invoice['invoiceNumber']); $this->text($doc, $header, $ns['ram'], 'ram:TypeCode', $isCredit ? '381' : '380');
		$date = $this->child($doc, $header, $ns['ram'], 'ram:IssueDateTime'); $ds = $this->text($doc, $date, $ns['udt'], 'udt:DateTimeString', date('Ymd', strtotime((string)$invoice['createdAt']))); $ds->setAttribute('format', '102');
		$transaction = $this->child($doc, $root, $ns['rsm'], 'rsm:SupplyChainTradeTransaction');
		foreach ($invoice['items'] ?? [] as $position => $item) {
			$isPassThrough = strtoupper((string)($item['positionType'] ?? 'EL')) === 'DP';
			$line = $this->child($doc, $transaction, $ns['ram'], 'ram:IncludedSupplyChainTradeLineItem');
			$lineDoc = $this->child($doc, $line, $ns['ram'], 'ram:AssociatedDocumentLineDocument'); $this->text($doc, $lineDoc, $ns['ram'], 'ram:LineID', (string)($position + 1));
			$product = $this->child($doc, $line, $ns['ram'], 'ram:SpecifiedTradeProduct'); $this->text($doc, $product, $ns['ram'], 'ram:Name', (string)$item['description']); if (trim((string)($item['packageTitle'] ?? '')) !== '') $this->text($doc, $product, $ns['ram'], 'ram:Description', 'Bestandteil von „' . (string)$item['packageTitle'] . '“ (Pos. ' . (string)($item['packagePosition'] ?? '') . ').');
			$agreement = $this->child($doc, $line, $ns['ram'], 'ram:SpecifiedLineTradeAgreement'); $price = $this->child($doc, $agreement, $ns['ram'], 'ram:NetPriceProductTradePrice'); $this->text($doc, $price, $ns['ram'], 'ram:ChargeAmount', $this->amount($sign * (int)$item['unitPriceCents']));
			$delivery = $this->child($doc, $line, $ns['ram'], 'ram:SpecifiedLineTradeDelivery'); $quantity = $this->text($doc, $delivery, $ns['ram'], 'ram:BilledQuantity', $this->quantity((int)$item['quantityMilli'])); $quantity->setAttribute('unitCode', $this->unitCode((string)($item['unit'] ?? 'STK')));
			$settlement = $this->child($doc, $line, $ns['ram'], 'ram:SpecifiedLineTradeSettlement'); $tax = $this->child($doc, $settlement, $ns['ram'], 'ram:ApplicableTradeTax'); $this->text($doc, $tax, $ns['ram'], 'ram:TypeCode', 'VAT'); $this->text($doc, $tax, $ns['ram'], 'ram:CategoryCode', $isPassThrough ? 'E' : 'S'); $this->text($doc, $tax, $ns['ram'], 'ram:RateApplicablePercent', $isPassThrough ? '0' : (string)$item['vatRate']);
			$sum = $this->child($doc, $settlement, $ns['ram'], 'ram:SpecifiedTradeSettlementLineMonetarySummation'); $this->text($doc, $sum, $ns['ram'], 'ram:LineTotalAmount', $this->amount($sign * (int)$item['netCents']));
		}
		$agreement = $this->child($doc, $transaction, $ns['ram'], 'ram:ApplicableHeaderTradeAgreement');
		$reference = $this->child($doc, $agreement, $ns['ram'], 'ram:BuyerReference'); $reference->nodeValue = (string)$case['caseNumber'];
		$this->party($doc, $agreement, $ns, 'ram:SellerTradeParty', $branch);
		$this->party($doc, $agreement, $ns, 'ram:BuyerTradeParty', $invoice['recipient'] ?? []);
		// 0.73.0: Element-Reihenfolge nach dem CII-Schema (EN 16931 / Factur-X XSD). Der Leistungszeitraum (BG-14)
		// gehört in die Abrechnung, nicht in die Lieferung; Gläubiger-ID (BT-90) steht vor der Währung.
		$this->child($doc, $transaction, $ns['ram'], 'ram:ApplicableHeaderTradeDelivery');
		$settlement = $this->child($doc, $transaction, $ns['ram'], 'ram:ApplicableHeaderTradeSettlement');
		$master = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$directDebit = !$isCredit && strtoupper((string)($master['payment_method'] ?? 'TRANSFER')) === 'SEPA_DIRECT_DEBIT';
		if ($directDebit && trim((string)($branch['creditorId'] ?? '')) !== '') $this->text($doc, $settlement, $ns['ram'], 'ram:CreditorReferenceID', trim((string)$branch['creditorId']));
		if (!$isCredit && !$directDebit) $this->text($doc, $settlement, $ns['ram'], 'ram:PaymentReference', (string)$invoice['invoiceNumber']);
		$this->text($doc, $settlement, $ns['ram'], 'ram:InvoiceCurrencyCode', 'EUR');
		$payment = $this->child($doc, $settlement, $ns['ram'], 'ram:SpecifiedTradeSettlementPaymentMeans');
		if ($isCredit) {
			$this->text($doc, $payment, $ns['ram'], 'ram:TypeCode', '1'); $this->text($doc, $payment, $ns['ram'], 'ram:Information', 'Erstattung bzw. Verrechnung');
		} elseif ($directDebit) {
			// EN 16931 kennt für die Lastschrift nur die IBAN des Zahlers (BT-91), keine BIC.
			$this->text($doc, $payment, $ns['ram'], 'ram:TypeCode', '59');
			$account = $this->child($doc, $payment, $ns['ram'], 'ram:PayerPartyDebtorFinancialAccount'); $this->text($doc, $account, $ns['ram'], 'ram:IBANID', (string)($master['sepa_debtor_iban'] ?? ''));
		} else {
			$this->text($doc, $payment, $ns['ram'], 'ram:TypeCode', '58');
			$account = $this->child($doc, $payment, $ns['ram'], 'ram:PayeePartyCreditorFinancialAccount'); $this->text($doc, $account, $ns['ram'], 'ram:IBANID', (string)($branch['iban'] ?? ''));
			if (trim((string)($branch['accountHolder'] ?? '')) !== '') $this->text($doc, $account, $ns['ram'], 'ram:AccountName', trim((string)$branch['accountHolder']));
			$bic = trim((string)($branch['bic'] ?? ''));
			if ($bic !== '') { $institution = $this->child($doc, $payment, $ns['ram'], 'ram:PayeeSpecifiedCreditorFinancialInstitution'); $this->text($doc, $institution, $ns['ram'], 'ram:BICID', $bic); }
		}
		foreach ($this->taxGroups($invoice['items'] ?? []) as $amounts) {
			$tax = $this->child($doc, $settlement, $ns['ram'], 'ram:ApplicableTradeTax');
			$this->text($doc, $tax, $ns['ram'], 'ram:CalculatedAmount', $this->amount($sign * $amounts['vat']));
			$this->text($doc, $tax, $ns['ram'], 'ram:TypeCode', 'VAT');
			if ($amounts['category'] === 'E') $this->text($doc, $tax, $ns['ram'], 'ram:ExemptionReason', self::PASS_THROUGH_REASON);
			$this->text($doc, $tax, $ns['ram'], 'ram:BasisAmount', $this->amount($sign * $amounts['net']));
			$this->text($doc, $tax, $ns['ram'], 'ram:CategoryCode', $amounts['category']);
			$this->text($doc, $tax, $ns['ram'], 'ram:RateApplicablePercent', (string)$amounts['rate']);
		}
		$periodFrom = trim((string)($invoice['servicePeriodFrom'] ?? '')); $periodTo = trim((string)($invoice['servicePeriodTo'] ?? ''));
		if ($periodFrom !== '' || $periodTo !== '') {
			$period = $this->child($doc, $settlement, $ns['ram'], 'ram:BillingSpecifiedPeriod');
			foreach ([['ram:StartDateTime', $periodFrom], ['ram:EndDateTime', $periodTo]] as [$tag, $value]) {
				if ($value === '') continue;
				$node = $this->child($doc, $period, $ns['ram'], $tag); $string = $this->text($doc, $node, $ns['udt'], 'udt:DateTimeString', date('Ymd', strtotime($value))); $string->setAttribute('format', '102');
			}
		}
		$terms = $this->child($doc, $settlement, $ns['ram'], 'ram:SpecifiedTradePaymentTerms');
		$termsDescription = $isCredit ? ((($invoice['documentKind'] ?? '') === 'CANCELLATION' ? 'Stornierung der Rechnung ' : 'Gutschrift zur Rechnung ') . (string)($invoice['referenceInvoiceNumber'] ?? '') . '; Erstattung bzw. Verrechnung bereits gezahlter Beträge.') : ($directDebit
			? 'SEPA-Lastschrift, Mandatsreferenz ' . (string)($master['sepa_mandate_reference'] ?? '') . ', Gläubiger-ID ' . (string)($branch['creditorId'] ?? '')
			: 'Zahlbar ohne Abzug bis ' . (string)($invoice['dueDate'] ?? ''));
		$this->text($doc, $terms, $ns['ram'], 'ram:Description', $termsDescription);
		if (!$isCredit) { $due = $this->child($doc, $terms, $ns['ram'], 'ram:DueDateDateTime'); $dueString = $this->text($doc, $due, $ns['udt'], 'udt:DateTimeString', date('Ymd', strtotime((string)($invoice['dueDate'] ?? $invoice['createdAt'])))); $dueString->setAttribute('format', '102'); }
		if ($directDebit && trim((string)($master['sepa_mandate_reference'] ?? '')) !== '') $this->text($doc, $terms, $ns['ram'], 'ram:DirectDebitMandateID', trim((string)$master['sepa_mandate_reference']));
		$totals = $invoice['totals']; $sum = $this->child($doc, $settlement, $ns['ram'], 'ram:SpecifiedTradeSettlementHeaderMonetarySummation');
		foreach ([['LineTotalAmount','netCents'],['TaxBasisTotalAmount','netCents'],['TaxTotalAmount','vatCents'],['GrandTotalAmount','grossCents'],['DuePayableAmount','grossCents']] as [$tag,$key]) { $node = $this->text($doc, $sum, $ns['ram'], 'ram:' . $tag, $this->amount($sign * (int)$totals[$key])); if ($tag === 'TaxTotalAmount') $node->setAttribute('currencyID', 'EUR'); }
		if ($isCredit && trim((string)($invoice['referenceInvoiceNumber'] ?? '')) !== '') {
			$referenced = $this->child($doc, $settlement, $ns['ram'], 'ram:InvoiceReferencedDocument');
			$this->text($doc, $referenced, $ns['ram'], 'ram:IssuerAssignedID', (string)$invoice['referenceInvoiceNumber']);
			if (trim((string)($invoice['referenceInvoiceDate'] ?? '')) !== '') {
				$issued = $this->child($doc, $referenced, $ns['ram'], 'ram:FormattedIssueDateTime');
				$qdt = $doc->createElementNS('urn:un:unece:uncefact:data:standard:QualifiedDataType:100', 'qdt:DateTimeString', date('Ymd', strtotime((string)$invoice['referenceInvoiceDate'])));
				$qdt->setAttribute('format', '102'); $issued->appendChild($qdt);
			}
		}
		return (string)$doc->saveXML();
	}

	public function epcPayload(array $invoice, array $branch): string {
		$iban = $this->ibanValidator->normalize((string)($branch['iban'] ?? ''));
		if ($iban === '') return '';
		if (!$this->ibanValidator->hasValidFormat($iban)) throw new \InvalidArgumentException('Für den EPC-SEPA-Zahlcode ist eine formal gültige IBAN erforderlich.');
		if (!$this->ibanValidator->isValid($iban)) throw new \InvalidArgumentException('Die IBAN ist ungültig (Prüfziffer stimmt nicht).');
		$accountHolder = trim((string)($branch['accountHolder'] ?? ''));
		$name = mb_substr($accountHolder !== '' ? $accountHolder : (string)($branch['name'] ?? ''), 0, 70);
		if ($name === '') throw new \InvalidArgumentException('Für den EPC-SEPA-Zahlcode fehlt der Kontoinhaber.');
		$grossCents = (int)($invoice['totals']['grossCents'] ?? 0);
		if ($grossCents < 1 || $grossCents > 99_999_999_999) throw new \InvalidArgumentException('Für einen Rechnungsbetrag von 0,00 €, einen negativen Betrag oder mehr als 999.999.999,99 € kann kein EPC-SEPA-Zahlcode erzeugt werden.');
		$reference = $this->references->fromInvoiceNumber((string)($invoice['invoiceNumber'] ?? ''));
		// EPC069-12 erlaubt strukturierte und unstrukturierte Zahlungsreferenz nur alternativ.
		return implode("\n", ['BCD', '002', '1', 'SCT', strtoupper((string)($branch['bic'] ?? '')), $name, $iban, 'EUR' . number_format($grossCents / 100, 2, '.', ''), '', $reference]);
	}

	public function epcQrImage(array $invoice, array $branch): string {
		if (!class_exists(PngWriter::class)) throw new \RuntimeException('Die serverseitige QR-Bibliothek ist nicht installiert. Bitte das vollständige Release-Paket einschließlich vendor/ verwenden.');
		if (!extension_loaded('gd')) throw new \RuntimeException('Die PHP-Erweiterung GD wird für die serverseitige Erzeugung des Zahlungs-QR-Codes benötigt.');
		$qrCode = new QrCode(
			data: $this->epcPayload($invoice, $branch),
			encoding: new Encoding('UTF-8'),
			errorCorrectionLevel: ErrorCorrectionLevel::Medium,
			size: 420,
			margin: 16,
			roundBlockSizeMode: RoundBlockSizeMode::Margin,
		);
		return (new PngWriter())->write($qrCode)->getString();
	}

	private function party(\DOMDocument $doc, \DOMElement $parent, array $ns, string $tag, array $party): void {
		$node = $this->child($doc, $parent, $ns['ram'], $tag);
		$isSeller = $tag === 'ram:SellerTradeParty';
		$vatId = $isSeller ? strtoupper(str_replace(' ', '', trim((string)($party['vatId'] ?? '')))) : '';
		$taxNumber = $isSeller ? trim((string)($party['taxNumber'] ?? '')) : '';
		$register = $isSeller ? trim((string)($party['registerNumber'] ?? '')) : '';
		// 0.73.0: BR-CO-26 verlangt eine Kennung des Verkäufers (BT-29, BT-30 oder BT-31). Ohne USt-IdNr. und
		// Registernummer wird die Steuernummer bzw. der Niederlassungsschlüssel als Verkäuferkennung ausgegeben.
		if ($isSeller && $vatId === '' && $register === '') {
			$sellerId = $taxNumber !== '' ? $taxNumber : trim((string)($party['key'] ?? ''));
			if ($sellerId !== '') $this->text($doc, $node, $ns['ram'], 'ram:ID', $sellerId);
		}
		$this->text($doc, $node, $ns['ram'], 'ram:Name', (string)($party['name'] ?? ''));
		if ($register !== '') {
			$legal = $this->child($doc, $node, $ns['ram'], 'ram:SpecifiedLegalOrganization');
			$this->text($doc, $legal, $ns['ram'], 'ram:ID', trim(trim((string)($party['registerCourt'] ?? '')) . ' ' . $register));
		}
		$address = $this->child($doc, $node, $ns['ram'], 'ram:PostalTradeAddress');
		$this->text($doc, $address, $ns['ram'], 'ram:PostcodeCode', (string)($party['postalCode'] ?? ''));
		$this->text($doc, $address, $ns['ram'], 'ram:LineOne', (string)($party['street'] ?? ''));
		$this->text($doc, $address, $ns['ram'], 'ram:CityName', (string)($party['city'] ?? ''));
		$country = strtoupper(trim((string)($party['countryCode'] ?? '')));
		$this->text($doc, $address, $ns['ram'], 'ram:CountryID', preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : 'DE');
		$electronic = trim((string)($party['electronicAddress'] ?? $party['email'] ?? ''));
		if ($electronic !== '') {
			$communication = $this->child($doc, $node, $ns['ram'], 'ram:URIUniversalCommunication');
			$uri = $this->text($doc, $communication, $ns['ram'], 'ram:URIID', $electronic);
			$uri->setAttribute('schemeID', str_contains($electronic, '@') ? 'EM' : '0204');
		}
		foreach ([['VA', $vatId], ['FC', $taxNumber]] as [$scheme, $value]) {
			if ($value === '') continue;
			$registration = $this->child($doc, $node, $ns['ram'], 'ram:SpecifiedTaxRegistration');
			$id = $this->text($doc, $registration, $ns['ram'], 'ram:ID', $value); $id->setAttribute('schemeID', $scheme);
		}
	}
	private function taxGroups(array $items): array { $groups=[]; foreach($items as $item){$category=strtoupper((string)($item['positionType']??'EL'))==='DP'?'E':'S';$rate=$category==='E'?0:(int)$item['vatRate'];$key=$category.'-'.$rate;$groups[$key]??=['category'=>$category,'rate'=>$rate,'net'=>0,'vat'=>0];$groups[$key]['net']+=(int)$item['netCents'];$groups[$key]['vat']+=(int)$item['vatCents'];} return array_values($groups); }
	private function amount(int $cents): string { return number_format($cents / 100, 2, '.', ''); }
	private function quantity(int $milli): string { return rtrim(rtrim(number_format($milli / 1000, 3, '.', ''), '0'), '.'); }
	private function unitCode(string $unit): string { return ['STD' => 'HUR', 'KM' => 'KMT', 'TAG' => 'DAY', 'KG' => 'KGM', 'L' => 'LTR', 'SATZ' => 'SET', 'PERS' => 'IE'][$unit] ?? 'C62'; }
	private function child(\DOMDocument $doc, \DOMElement $parent, string $ns, string $tag): \DOMElement { $node=$doc->createElementNS($ns,$tag);$parent->appendChild($node);return $node; }
	private function text(\DOMDocument $doc, \DOMElement $parent, string $ns, string $tag, string $value): \DOMElement { $node=$doc->createElementNS($ns,$tag);$node->appendChild($doc->createTextNode($value));$parent->appendChild($node);return $node; }
}
