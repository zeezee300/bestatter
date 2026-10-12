<?php

declare(strict_types=1);

namespace OCA\Bestatter\BackgroundJob;

use OCA\Bestatter\AppInfo\Application;
use OCA\Bestatter\Service\BackupService;
use OCA\Bestatter\Service\BestatterActivityService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * 0.78.0 – Automatische Datensicherung nach dem Zeitplan unter Administration → Backup & Restore.
 * Prüft alle 15 Minuten, ob ein Termin fällig ist; die eigentliche Arbeit und alle Regeln liegen im BackupService.
 * TIME_SENSITIVE, damit Nextcloud den Job nicht in ein Wartungsfenster verschiebt – die gewählte Uhrzeit soll gelten.
 */
class ScheduledBackupJob extends TimedJob {
	public function __construct(ITimeFactory $time, private BackupService $backup, private BestatterActivityService $activity, private LoggerInterface $logger) {
		parent::__construct($time);
		$this->setInterval(15 * 60);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$result = $this->backup->runScheduled();
		if ($result === null) return;
		$context = ['app' => Application::APP_ID, 'file' => $result['file'] ?? '', 'deleted' => count((array)($result['deleted'] ?? []))];
		if ($result['status'] === 'OK') { $this->logger->info('Automatische Datensicherung erstellt: {file}, {deleted} abgelaufene Sicherung(en) gelöscht.', $context); return; }
		$this->logger->error('Automatische Datensicherung: ' . (string)$result['message'], $context);
		$this->activity->publishBackupAttention((string)$result['message']);
	}
}
