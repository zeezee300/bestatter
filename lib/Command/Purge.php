<?php

declare(strict_types=1);

namespace OCA\Bestatter\Command;

use OCA\Bestatter\Service\PurgeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Entfernt Bestatter-Daten kontrolliert – auf Wunsch vollständig bis zum Deaktivieren der App (--remove-app).
 * Standard ist ein rein lesender Trockenlauf.
 */
class Purge extends Command {
	private const CONFIRMATION = 'BESTATTER-ENDGUELTIG-LOESCHEN';
	public function __construct(private PurgeService $purge) { parent::__construct(); }
	protected function configure(): void {
		$this->setName('bestatter:purge')->setDescription('Zeigt oder entfernt alle Bestatter-Daten; mit --remove-app wird die App vollständig aus Nextcloud entfernt. Standard ist ein rein lesender Trockenlauf.')
			->addOption('execute', null, InputOption::VALUE_NONE, 'Bereinigung ausführen')
			->addOption('confirmation', null, InputOption::VALUE_REQUIRED, 'Fester Bestätigungscode')
			->addOption('backup-package', null, InputOption::VALUE_REQUIRED, 'Absoluter Pfad eines zuvor erstellten vollständigen Sicherungspakets (bestatter:backup)')
			->addOption('without-backup', null, InputOption::VALUE_NONE, 'Ausdrücklich ohne Sicherungspaket ausführen (nicht wiederherstellbar)')
			->addOption('despite-legal-hold', null, InputOption::VALUE_NONE, 'Auch Fälle unter Legal Hold löschen')
			->addOption('remove-app', null, InputOption::VALUE_NONE, 'Zusätzlich Tabellen und Migrationseinträge entfernen und die App deaktivieren');
	}
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$preview = $this->purge->preview();
		$output->writeln('<info>Bestatter-Purge – Vorschau</info>');
		$output->writeln(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		foreach ($preview['notices'] as $notice) $output->writeln('<comment>Hinweis: ' . $notice . '</comment>');
		if (!$input->getOption('execute')) {
			$output->writeln('<comment>Keine Daten verändert. Ausführen mit --execute --confirmation=' . self::CONFIRMATION . ' und --backup-package=… oder --without-backup; optional --remove-app.</comment>');
			return self::SUCCESS;
		}
		if ((string)$input->getOption('confirmation') !== self::CONFIRMATION) { $output->writeln('<error>Bestätigungscode fehlt oder ist falsch.</error>'); return self::INVALID; }
		try {
			$result = $this->purge->purge(trim((string)$input->getOption('backup-package')), (bool)$input->getOption('without-backup'), (bool)$input->getOption('despite-legal-hold'), (bool)$input->getOption('remove-app'));
		} catch (\RuntimeException $error) { $output->writeln('<error>' . $error->getMessage() . '</error>'); return self::FAILURE; }
		$output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		foreach ($result['warnings'] as $warning) $output->writeln('<comment>Hinweis: ' . $warning . '</comment>');
		foreach ($result['nextSteps'] as $step) $output->writeln('<info>Nächster Schritt: ' . $step . '</info>');
		return self::SUCCESS;
	}
}
