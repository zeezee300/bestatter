<?php

declare(strict_types=1);

namespace OCA\Bestatter\Tests\Unit;

use OCA\Bestatter\Service\CaseService;

class CaseServiceTest extends ServiceTestCase {
	private CaseService $service;

	protected function setUp(): void {
		$this->service = $this->withoutConstructor(CaseService::class);
	}

	public function testNormalizesAllOptionalFamilyDates(): void {
		$data = [
			'spouse_date_of_birth' => ' 1942-04-15 ',
			'spouse_date_of_death' => '',
			'marriage_date' => '1964-06-20',
			'partnership_date' => '2002-08-01',
			'divorce_date' => '2010-11-30',
			'spouse_birth_place' => 'Bremen',
		];

		$result = $this->invoke($this->service, 'normalizeMasterDates', $data);

		self::assertSame('1942-04-15', $result['spouse_date_of_birth']);
		self::assertSame('', $result['spouse_date_of_death']);
		self::assertSame('1964-06-20', $result['marriage_date']);
		self::assertSame('2002-08-01', $result['partnership_date']);
		self::assertSame('2010-11-30', $result['divorce_date']);
		self::assertSame('Bremen', $result['spouse_birth_place']);
	}

	public function testRejectsInvalidOptionalFamilyDate(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Datum der Eheschließung');
		$this->invoke($this->service, 'normalizeMasterDates', ['marriage_date' => '31.12.2020']);
	}

	public function testRejectsChronologicallyImpossibleFamilyDates(): void {
		$invalid = [
			['spouse_date_of_birth' => '1950-01-01', 'spouse_date_of_death' => '1949-12-31'],
			['date_of_birth' => '1950-01-01', 'marriage_date' => '1949-12-31'],
			['spouse_date_of_birth' => '1950-01-01', 'marriage_date' => '1949-12-31'],
			['marriage_date' => '1970-01-01', 'divorce_date' => '1969-12-31'],
		];
		foreach ($invalid as $data) {
			try {
				$this->invoke($this->service, 'validateFamilyData', $data);
				self::fail('Eine unmögliche Datumsfolge wurde akzeptiert.');
			} catch (\InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}

	public function testFamilyStatusProducesWarningsWithoutBlockingSave(): void {
		$single = $this->invoke($this->service, 'validateFamilyData', [
			'civil_status' => 'ledig',
			'spouse_first_name' => 'Widerspruch',
		]);
		$widowed = $this->invoke($this->service, 'validateFamilyData', [
			'civil_status' => 'verwitwet',
			'spouse_first_name' => 'Erika',
		]);
		self::assertStringContainsString('ledig', $single[0]);
		self::assertStringContainsString('kein Todesdatum', $widowed[0]);
	}

	public function testWidowedWithDeathDateHasNoWarning(): void {
		$result = $this->invoke($this->service, 'validateFamilyData', [
			'civil_status' => 'verwitwet',
			'spouse_date_of_birth' => '1940-01-01',
			'spouse_date_of_death' => '2020-01-01',
		]);
		self::assertSame([], $result);
	}
}
