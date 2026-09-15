<?php

declare(strict_types=1);

namespace OCA\Bestatter\Tests\Unit;

use OCA\Bestatter\Service\EInvoiceService;
use OCA\Bestatter\Service\IbanValidator;
use OCA\Bestatter\Service\StructuredReferenceGenerator;

final class EInvoiceServiceTest extends ServiceTestCase {
	private function fixture(): array {
		$case = ['caseNumber'=>'2026-0001'];
		$invoice = ['invoiceNumber'=>'RE-2026-000001','createdAt'=>'2026-08-31T10:00:00+00:00','dueDate'=>'2026-09-14','recipient'=>['name'=>'Anna Muster','street'=>'Testweg 1','postalCode'=>'28195','city'=>'Bremen','electronicAddress'=>'anna@example.test'],'items'=>[['description'=>'Bestattungsleistung','quantityMilli'=>1000,'unit'=>'STK','unitPriceCents'=>10000,'vatRate'=>19,'netCents'=>10000,'vatCents'=>1900,'grossCents'=>11900]],'totals'=>['netCents'=>10000,'vatCents'=>1900,'grossCents'=>11900]];
		$branch = ['name'=>'Bestattung Muster','street'=>'Hauptweg 1','postalCode'=>'28195','city'=>'Bremen','iban'=>'DE02120300000000202051','bic'=>'BYLADEM1001','electronicAddress'=>'rechnung@example.test'];
		return [$case,$invoice,$branch];
	}

	public function testZugferdCiiPassesInternalStructureCheck(): void {
		[$case,$invoice,$branch]=$this->fixture(); $service=new EInvoiceService();
		$xml=$service->createXml($case,$invoice,$branch,[]);
		$result=$service->validateXml($xml,'ZUGFERD');
		self::assertTrue($result['valid']); self::assertSame('INTERNAL_STRUCTURE',$result['level']);
	}

	public function testXRechnungContainsGuidelineAndElectronicAddresses(): void {
		[$case,$invoice,$branch]=$this->fixture(); $service=new EInvoiceService();
		$xml=$service->createXml($case,$invoice,$branch,[],'XRECHNUNG');
		$result=$service->validateXml($xml,'XRECHNUNG');
		self::assertTrue($result['valid']); self::assertStringContainsString('xrechnung_3.0',$xml);
	}

	public function testEpcPayloadUsesOnlyStructuredRfReference(): void {
		[, $invoice, $branch] = $this->fixture();
		$payload = (new EInvoiceService())->epcPayload($invoice, $branch);
		$lines = explode("\n", $payload);
		self::assertCount(10, $lines);
		self::assertSame('', $lines[8]);
		self::assertSame('RF69RE2026000001', $lines[9]);
		self::assertStringNotContainsString('Rechnung ', $payload);
		self::assertFalse(str_ends_with($payload, "\n"));
	}

	public function testStructuredReferenceAndIbanChecksum(): void {
		self::assertSame('RF69RE2026000001', (new StructuredReferenceGenerator())->fromInvoiceNumber('RE-2026-000001'));
		$validator = new IbanValidator();
		self::assertTrue($validator->isValid('DE02 1203 0000 0000 2020 51'));
		self::assertFalse($validator->isValid('DE02 1203 0000 0000 2020 15'));
	}

	public function testEpcPayloadRejectsInvalidAmountsAndIban(): void {
		[, $invoice, $branch] = $this->fixture();
		$invoice['totals']['grossCents'] = 0;
		$this->expectException(\InvalidArgumentException::class);
		(new EInvoiceService())->epcPayload($invoice, $branch);
	}

	public function testEpcQrImageIsGeneratedAsPng(): void {
		[, $invoice, $branch] = $this->fixture();
		$png = (new EInvoiceService())->epcQrImage($invoice, $branch);
		self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
		self::assertGreaterThan(500, strlen($png));
	}
}
