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
			'//ram:IBANID' => 'IBAN',
		];
		$errors = [];
		foreach ($required as $query => $label) {
			$nodes = $xpath->query($query);
			if ($nodes === false || $nodes->length === 0 || trim((string)$nodes->item(0)?->textContent) === '') $errors[] = $label . ' fehlt.';
		}
		$lines = $xpath->query('//ram:IncludedSupplyChainTradeLineItem');
		if ($lines === false || $lines->length === 0) $errors[] = 'Mindestens eine Rechnungsposition ist erforderlich.';
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
		$parameter = $this->child($doc, $context, $ns['ram'], 'ram:GuidelineSpecifiedDocumentContextParameter');
		$isXRechnung = strtoupper($standard) === 'XRECHNUNG';
		if ($isXRechnung) {
			$business = $this->child($doc, $context, $ns['ram'], 'ram:BusinessProcessSpecifiedDocumentContextParameter');
			$this->text($doc, $business, $ns['ram'], 'ram:ID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');
		}
		$this->text($doc, $parameter, $ns['ram'], 'ram:ID', $isXRechnung ? 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0' : 'urn:cen.eu:en16931:2017');
		$header = $this->child($doc, $root, $ns['rsm'], 'rsm:ExchangedDocument');
		$this->text($doc, $header, $ns['ram'], 'ram:ID', (string)$invoice['invoiceNumber']); $this->text($doc, $header, $ns['ram'], 'ram:TypeCode', '380');
		$date = $this->child($doc, $header, $ns['ram'], 'ram:IssueDateTime'); $ds = $this->text($doc, $date, $ns['udt'], 'udt:DateTimeString', date('Ymd', strtotime((string)$invoice['createdAt']))); $ds->setAttribute('format', '102');
		$transaction = $this->child($doc, $root, $ns['rsm'], 'rsm:SupplyChainTradeTransaction');
		foreach ($invoice['items'] ?? [] as $position => $item) {
			$isPassThrough = strtoupper((string)($item['positionType'] ?? 'EL')) === 'DP';
			$line = $this->child($doc, $transaction, $ns['ram'], 'ram:IncludedSupplyChainTradeLineItem');
			$lineDoc = $this->child($doc, $line, $ns['ram'], 'ram:AssociatedDocumentLineDocument'); $this->text($doc, $lineDoc, $ns['ram'], 'ram:LineID', (string)($position + 1));
			$product = $this->child($doc, $line, $ns['ram'], 'ram:SpecifiedTradeProduct'); $this->text($doc, $product, $ns['ram'], 'ram:Name', (string)$item['description']);
			$agreement = $this->child($doc, $line, $ns['ram'], 'ram:SpecifiedLineTradeAgreement'); $price = $this->child($doc, $agreement, $ns['ram'], 'ram:NetPriceProductTradePrice'); $this->text($doc, $price, $ns['ram'], 'ram:ChargeAmount', $this->amount((int)$item['unitPriceCents']));
			$delivery = $this->child($doc, $line, $ns['ram'], 'ram:SpecifiedLineTradeDelivery'); $quantity = $this->text($doc, $delivery, $ns['ram'], 'ram:BilledQuantity', $this->quantity((int)$item['quantityMilli'])); $quantity->setAttribute('unitCode', $this->unitCode((string)($item['unit'] ?? 'STK')));
			$settlement = $this->child($doc, $line, $ns['ram'], 'ram:SpecifiedLineTradeSettlement'); $tax = $this->child($doc, $settlement, $ns['ram'], 'ram:ApplicableTradeTax'); $this->text($doc, $tax, $ns['ram'], 'ram:TypeCode', 'VAT'); $this->text($doc, $tax, $ns['ram'], 'ram:CategoryCode', $isPassThrough ? 'O' : 'S'); $this->text($doc, $tax, $ns['ram'], 'ram:RateApplicablePercent', (string)$item['vatRate']); if ($isPassThrough) $this->text($doc, $tax, $ns['ram'], 'ram:ExemptionReason', 'Durchlaufender Posten gemäß § 10 Abs. 1 Satz 6 UStG – nicht Teil des Entgelts.');
			$sum = $this->child($doc, $settlement, $ns['ram'], 'ram:SpecifiedTradeSettlementLineMonetarySummation'); $this->text($doc, $sum, $ns['ram'], 'ram:LineTotalAmount', $this->amount((int)$item['netCents']));
		}
		$agreement = $this->child($doc, $transaction, $ns['ram'], 'ram:ApplicableHeaderTradeAgreement');
		$reference = $this->child($doc, $agreement, $ns['ram'], 'ram:BuyerReference'); $reference->nodeValue = (string)$case['caseNumber'];
		$this->party($doc, $agreement, $ns, 'ram:SellerTradeParty', $branch);
		$this->party($doc, $agreement, $ns, 'ram:BuyerTradeParty', $invoice['recipient'] ?? []);
		$deliveryHeader=$this->child($doc, $transaction, $ns['ram'], 'ram:ApplicableHeaderTradeDelivery');
		$periodFrom=trim((string)($invoice['servicePeriodFrom']??''));$periodTo=trim((string)($invoice['servicePeriodTo']??''));
		if($periodFrom!==''||$periodTo!==''){$period=$this->child($doc,$deliveryHeader,$ns['ram'],'ram:BillingSpecifiedPeriod');if($periodFrom!==''){$start=$this->child($doc,$period,$ns['ram'],'ram:StartDateTime');$value=$this->text($doc,$start,$ns['udt'],'udt:DateTimeString',date('Ymd',strtotime($periodFrom)));$value->setAttribute('format','102');}if($periodTo!==''){$end=$this->child($doc,$period,$ns['ram'],'ram:EndDateTime');$value=$this->text($doc,$end,$ns['udt'],'udt:DateTimeString',date('Ymd',strtotime($periodTo)));$value->setAttribute('format','102');}}
		$settlement = $this->child($doc, $transaction, $ns['ram'], 'ram:ApplicableHeaderTradeSettlement'); $this->text($doc, $settlement, $ns['ram'], 'ram:InvoiceCurrencyCode', 'EUR');
		$master = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$directDebit = strtoupper((string)($master['payment_method'] ?? 'TRANSFER')) === 'SEPA_DIRECT_DEBIT';
		$payment = $this->child($doc, $settlement, $ns['ram'], 'ram:SpecifiedTradeSettlementPaymentMeans'); $this->text($doc, $payment, $ns['ram'], 'ram:TypeCode', $directDebit ? '59' : '58');
		$accountTag = $directDebit ? 'ram:PayerPartyDebtorFinancialAccount' : 'ram:PayeePartyCreditorFinancialAccount';
		$account = $this->child($doc, $payment, $ns['ram'], $accountTag); $this->text($doc, $account, $ns['ram'], 'ram:IBANID', (string)($directDebit ? ($master['sepa_debtor_iban'] ?? '') : ($branch['iban'] ?? '')));
		$bic = (string)($directDebit ? ($master['sepa_debtor_bic'] ?? '') : ($branch['bic'] ?? ''));
		if ($bic !== '') { $institution = $this->child($doc, $payment, $ns['ram'], $directDebit ? 'ram:PayerSpecifiedDebtorFinancialInstitution' : 'ram:PayeeSpecifiedCreditorFinancialInstitution'); $this->text($doc, $institution, $ns['ram'], 'ram:BICID', $bic); }
		foreach ($this->taxGroups($invoice['items'] ?? []) as $amounts) { $tax = $this->child($doc, $settlement, $ns['ram'], 'ram:ApplicableTradeTax'); $this->text($doc, $tax, $ns['ram'], 'ram:CalculatedAmount', $this->amount($amounts['vat'])); $this->text($doc, $tax, $ns['ram'], 'ram:TypeCode', 'VAT'); $this->text($doc, $tax, $ns['ram'], 'ram:BasisAmount', $this->amount($amounts['net'])); $this->text($doc, $tax, $ns['ram'], 'ram:CategoryCode', $amounts['category']); $this->text($doc, $tax, $ns['ram'], 'ram:RateApplicablePercent', (string)$amounts['rate']); if ($amounts['category'] === 'O') $this->text($doc, $tax, $ns['ram'], 'ram:ExemptionReason', 'Durchlaufender Posten gemäß § 10 Abs. 1 Satz 6 UStG – nicht Teil des Entgelts.'); }
		$terms = $this->child($doc, $settlement, $ns['ram'], 'ram:SpecifiedTradePaymentTerms');
		$termsDescription = $directDebit
			? 'SEPA-Lastschrift, Mandatsreferenz ' . (string)($master['sepa_mandate_reference'] ?? '') . ', Gläubiger-ID ' . (string)($branch['creditorId'] ?? '')
			: 'Zahlbar ohne Abzug bis ' . (string)($invoice['dueDate'] ?? '');
		$this->text($doc, $terms, $ns['ram'], 'ram:Description', $termsDescription);
		$due = $this->child($doc, $terms, $ns['ram'], 'ram:DueDateDateTime'); $dueString = $this->text($doc, $due, $ns['udt'], 'udt:DateTimeString', date('Ymd', strtotime((string)($invoice['dueDate'] ?? $invoice['createdAt'])))); $dueString->setAttribute('format', '102');
		$totals = $invoice['totals']; $sum = $this->child($doc, $settlement, $ns['ram'], 'ram:SpecifiedTradeSettlementHeaderMonetarySummation');
		foreach ([['LineTotalAmount','netCents'],['TaxBasisTotalAmount','netCents'],['TaxTotalAmount','vatCents'],['GrandTotalAmount','grossCents'],['DuePayableAmount','grossCents']] as [$tag,$key]) { $node = $this->text($doc, $sum, $ns['ram'], 'ram:' . $tag, $this->amount((int)$totals[$key])); if ($tag === 'TaxTotalAmount') $node->setAttribute('currencyID', 'EUR'); }
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

	private function party(\DOMDocument $doc, \DOMElement $parent, array $ns, string $tag, array $party): void { $node=$this->child($doc,$parent,$ns['ram'],$tag); $this->text($doc,$node,$ns['ram'],'ram:Name',(string)($party['name']??'')); $address=$this->child($doc,$node,$ns['ram'],'ram:PostalTradeAddress'); $this->text($doc,$address,$ns['ram'],'ram:PostcodeCode',(string)($party['postalCode']??'')); $this->text($doc,$address,$ns['ram'],'ram:LineOne',(string)($party['street']??'')); $this->text($doc,$address,$ns['ram'],'ram:CityName',(string)($party['city']??'')); $this->text($doc,$address,$ns['ram'],'ram:CountryID','DE'); $electronic=trim((string)($party['electronicAddress']??$party['email']??'')); if($electronic!==''){ $communication=$this->child($doc,$node,$ns['ram'],'ram:URIUniversalCommunication'); $uri=$this->text($doc,$communication,$ns['ram'],'ram:URIID',$electronic); $uri->setAttribute('schemeID',str_contains($electronic,'@')?'EM':'0204'); } }
	private function taxGroups(array $items): array { $groups=[]; foreach($items as $item){$category=strtoupper((string)($item['positionType']??'EL'))==='DP'?'O':'S';$rate=(int)$item['vatRate'];$key=$category.'-'.$rate;$groups[$key]??=['category'=>$category,'rate'=>$rate,'net'=>0,'vat'=>0];$groups[$key]['net']+=(int)$item['netCents'];$groups[$key]['vat']+=(int)$item['vatCents'];} return array_values($groups); }
	private function amount(int $cents): string { return number_format($cents / 100, 2, '.', ''); }
	private function quantity(int $milli): string { return rtrim(rtrim(number_format($milli / 1000, 3, '.', ''), '0'), '.'); }
	private function unitCode(string $unit): string { return ['STD' => 'HUR', 'KM' => 'KMT', 'TAG' => 'DAY', 'KG' => 'KGM', 'L' => 'LTR'][$unit] ?? 'C62'; }
	private function child(\DOMDocument $doc, \DOMElement $parent, string $ns, string $tag): \DOMElement { $node=$doc->createElementNS($ns,$tag);$parent->appendChild($node);return $node; }
	private function text(\DOMDocument $doc, \DOMElement $parent, string $ns, string $tag, string $value): \DOMElement { $node=$doc->createElementNS($ns,$tag);$node->appendChild($doc->createTextNode($value));$parent->appendChild($node);return $node; }
}
