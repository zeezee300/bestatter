<?php

declare(strict_types=1);

namespace OCA\Bestatter\BackgroundJob;

use OCA\Bestatter\Service\PaperlessService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

class PaperlessSyncJob extends TimedJob {
	public function __construct(ITimeFactory $time, private PaperlessService $paperless) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->paperless->processDue(10);
	}
}
