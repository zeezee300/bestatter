<?php

declare(strict_types=1);

namespace OCA\Bestatter\Tests\Unit;

use OCA\Bestatter\Service\GroupwareService;

final class GroupwareServiceTest extends ServiceTestCase {
	public function testTaskRoundTripPreservesBestatterMetadata(): void {
		$service = $this->withoutConstructor(GroupwareService::class);
		$ics = $this->invoke(
			$service,
			'buildComponent',
			'task',
			'test-uid',
			'Angehörige informieren',
			'2026-08-23T09:00',
			'IN_BEARBEITUNG',
			[
				'description' => 'Telefonisch abstimmen',
				'priority' => 'HOCH',
				'assigneeUid' => 'kollegin',
				'assigneeName' => 'Erika Kollegin',
				'workflowUrl' => 'https://cloud.example/apps/bestatter/?caseId=12&taskUid=test-uid',
			],
			12,
			'2026-0001',
		);

		self::assertStringContainsString('SUMMARY:[2026-0001] Angehörige informieren', $ics);
		self::assertStringContainsString('PERCENT-COMPLETE:50', $ics);
		self::assertStringContainsString('X-BESTATTER-CASE-ID:12', $ics);

		$parsed = $this->invoke($service, 'parseComponent', $ics, 'task');
		self::assertSame('Angehörige informieren', $parsed['title']);
		self::assertSame(12, $parsed['caseId']);
		self::assertSame('2026-0001', $parsed['caseNumber']);
		self::assertSame('IN_BEARBEITUNG', $parsed['status']);
		self::assertSame('kollegin', $parsed['data']['assigneeUid']);
	}

	public function testEventRequiresStartDate(): void {
		$service = $this->withoutConstructor(GroupwareService::class);
		$this->expectException(\InvalidArgumentException::class);
		$service->createEvent('Trauerfeier', '', 'OFFEN', '{}', 1);
	}

	public function testBestatterMarkersAndBirthdayCalendarsAreClassified(): void {
		$service = $this->withoutConstructor(GroupwareService::class);
		$ics = "BEGIN:VEVENT\r\nUID:test\r\nCATEGORIES:Bestatter,Externer Termin\r\nX-BESTATTER-RECORD-TYPE:SCHEDULE\r\nEND:VEVENT\r\n";

		self::assertTrue($this->invoke($service, 'hasBestatterMarker', $ics, 'schedule'));
		self::assertTrue($this->invoke($service, 'excludedCalendarName', 'Kontaktgeburtstage'));
		self::assertTrue($this->invoke($service, 'excludedCalendarName', 'Contact birthdays'));
		self::assertTrue($this->invoke($service, 'excludedCalendarName', 'contact_birthdays'));
		self::assertFalse($this->invoke($service, 'excludedCalendarName', 'Bestatter – Termine'));
	}
}
