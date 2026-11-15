<?php

declare(strict_types=1);

namespace OCA\Bestatter\BackgroundJob;

use OCA\Bestatter\Service\PaperlessInboxService;
use OCA\Bestatter\Service\PaperlessService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class PaperlessSyncJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private PaperlessService $paperless,
		private PaperlessInboxService $inbox,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->paperless->processDue(10);
		// 0.90.1: Eingangsordner des Belegeingangs lesen und neue Dateien bewerten (unabhängig von Paperless).
		try {
			$this->inbox->backgroundRun(10);
		} catch (\Throwable $error) {
			$this->logger->warning('Belegeingang: Eingangsordner konnte nicht gelesen werden.', ['reason' => $error->getMessage()]);
		}
	}
}
