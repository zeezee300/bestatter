<?php

declare(strict_types=1);

namespace OCA\Bestatter\BackgroundJob;

use OCA\Bestatter\Service\GroupwareService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * 0.75.2 – Wiederholt fehlgeschlagene Übertragungen von Aufgaben und Terminen nach Nextcloud, z. B. sobald eine
 * Person sich erstmals angemeldet bzw. die App Aufgaben geöffnet hat. Läuft alle 30 Minuten (Nextcloud-Cron).
 */
class GroupwareRetryJob extends TimedJob {
	public function __construct(ITimeFactory $time, private GroupwareService $groupware, private LoggerInterface $logger) {
		parent::__construct($time);
		$this->setInterval(30 * 60);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$result = $this->groupware->retryFailedSync();
		if ($result['repaired'] > 0 || $result['failed'] > 0) {
			$this->logger->info('Nextcloud-Übertragung wiederholt: {repaired} nachgeholt, {failed} weiterhin fehlerhaft, {waiting} warten auf Kalender/Aufgabenliste.', ['app' => 'bestatter'] + $result);
		}
	}
}
