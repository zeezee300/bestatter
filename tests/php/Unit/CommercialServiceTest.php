<?php

declare(strict_types=1);

namespace OCA\Bestatter\Tests\Unit;

use OCA\Bestatter\Service\CommercialService;

final class CommercialServiceTest extends ServiceTestCase {
	public function testGermanDecimalQuantityIsConvertedToMilliUnits(): void {
		$service = $this->withoutConstructor(CommercialService::class);
		self::assertSame(1250, $this->invoke($service, 'quantityToMilli', '1,25'));
		self::assertSame(0, $this->invoke($service, 'quantityToMilli', '-2'));
	}

	public function testQuantityPrecisionIsValidatedForPieces(): void {
		$service = $this->withoutConstructor(CommercialService::class);
		$this->expectException(\InvalidArgumentException::class);
		$this->invoke($service, 'validateQuantityPrecision', 1500, 0, 'STK');
	}

	public function testInvoiceRecipientFallsBackToOrderClient(): void {
		$service = $this->withoutConstructor(CommercialService::class);
		$recipient = $this->invoke($service, 'recipient', [
			'order_client_first_name' => 'Paula',
			'order_client_name' => 'Abnahme',
			'order_client_street' => 'Testweg 7',
			'order_client_postal_city' => '28195 Bremen',
		]);
		self::assertSame([
			'name' => 'Paula Abnahme',
			'street' => 'Testweg 7',
			'postalCode' => '28195',
			'city' => 'Bremen',
		], $recipient);
	}
}
