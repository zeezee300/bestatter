<?php

declare(strict_types=1);

namespace OCA\Bestatter\Tests\Unit;

use OCA\Bestatter\Service\AssistantService;

final class AssistantServiceTest extends ServiceTestCase {
	public function testCurrentGermanIntakeDictationIsMappedToFields(): void {
		$service = $this->withoutConstructor(AssistantService::class);
		$text = 'Es geht um Maria Muster, geborene Beispiel 2, geboren am 15.04.1952 in Bremen. Das Geburtsstandesamt ist Bremen-Mitte. Sie ist vom Beruf Kauffrau. Sie ist am 23.08.2022 gestorben und zwar im Klinikum Bremen-Mitte. Der letzte Wohnsitz ist Kibelskrieg 123 in 28105 Bremen. Es ist eine Feuerbestattung gewünscht auf dem Riensberger Friedhof. Die Auftraggeberin ist ihre Tochter Anna Muster mit der Mobilfunknummer 0170 1234567. Ich möchte zwei gebührenfreie Sterbeurkunden und drei weitere gebührenpflichtige.';
		$result = $service->analyze($text);
		$values = [];
		foreach ($result['suggestions'] as $suggestion) {
			$values[$suggestion['field']] = $suggestion['value'];
		}

		self::assertSame('Maria', $values['first_name']);
		self::assertSame('Muster', $values['last_name']);
		self::assertSame('2022-08-23', $values['date_of_death']);
		self::assertSame('Klinikum Bremen-Mitte', $values['place_of_death']);
		self::assertSame('Riensberger Friedhof', $values['cemetery_contact']);
		self::assertSame('3', $values['certificate_paid_count']);
	}

	public function testEmptyTextIsRejected(): void {
		$service = $this->withoutConstructor(AssistantService::class);
		$this->expectException(\InvalidArgumentException::class);
		$service->analyze('   ');
	}
}
