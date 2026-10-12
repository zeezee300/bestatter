<?php

declare(strict_types=1);

namespace OCA\Bestatter\BackgroundJob;

use OCA\Bestatter\Service\ProcessService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * 0.68.0 – Zeitgesteuerte Ablaufauslöser („Datum erreicht“, „Rechnung überfällig“). Alle übrigen
 * Auslöser greifen weiterhin sofort bei der fachlichen Änderung bzw. beim Öffnen des Falls.
 * Läuft alle 6 Stunden (Nextcloud-Cron vorausgesetzt) und ist idempotent: Jeder Schritt entsteht je
 * Fall und Anlass nur einmal (eindeutiger Index in bestatter_process_runs).
 */
class ProcessScheduleJob extends TimedJob {
	public function __construct(ITimeFactory $time, private ProcessService $process, private LoggerInterface $logger) {
		parent::__construct($time);
		$this->setInterval(6 * 60 * 60);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$result = $this->process->reconcileTimeTriggers();
		if ($result['created'] > 0 || $result['errors'] > 0) {
			$this->logger->info('Zeitgesteuerter Ablaufabgleich: {created} Aufgabe(n) in {cases} Fällen angelegt, {errors} Fehler.', ['app' => 'bestatter'] + $result);
		}
	}
}
