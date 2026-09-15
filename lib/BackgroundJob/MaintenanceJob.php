<?php

declare(strict_types=1);

namespace OCA\Bestatter\BackgroundJob;

use OCA\Bestatter\Service\MaintenanceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

class MaintenanceJob extends TimedJob {
	public function __construct(ITimeFactory $time, private MaintenanceService $maintenance) {
		parent::__construct($time);
		$this->setInterval(6 * 60 * 60);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->maintenance->runScheduled();
	}
}
