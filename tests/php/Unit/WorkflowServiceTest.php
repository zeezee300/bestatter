<?php

declare(strict_types=1);

namespace OCA\Bestatter\Tests\Unit;

use OCA\Bestatter\Service\WorkflowService;

final class WorkflowServiceTest extends ServiceTestCase {
	public function testInvalidWorkflowInputIsRejected(): void {
		$service = $this->withoutConstructor(WorkflowService::class);
		$this->expectException(\InvalidArgumentException::class);
		$this->invoke($service, 'decodeInput', '{invalid');
	}

	public function testScheduleChangeDetectionUsesRelevantFields(): void {
		$service = $this->withoutConstructor(WorkflowService::class);
		$before = ['date' => '2026-09-05T11:00', 'title' => 'Trauerfeier', 'data' => ['location' => 'Kapelle']];
		$after = $before;
		self::assertFalse($this->invoke($service, 'scheduleContentChanged', $before, $after));
		$after['data']['location'] = 'Friedhof';
		self::assertTrue($this->invoke($service, 'scheduleContentChanged', $before, $after));
	}

	public function testOnlyOldRunningDocumentClaimsAreRecoverable(): void {
		$service = $this->withoutConstructor(WorkflowService::class);
		self::assertFalse($this->invoke($service, 'isStaleRunningDocumentRun', ['updatedAt' => date('c')]));
		self::assertTrue($this->invoke($service, 'isStaleRunningDocumentRun', ['updatedAt' => date('c', time() - 3600)]));
		self::assertTrue($this->invoke($service, 'isStaleRunningDocumentRun', ['updatedAt' => 'ungültig']));
	}
}
