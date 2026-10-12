<?php
declare(strict_types=1);
namespace OCA\Bestatter\Command;

use OCA\Bestatter\Service\StorageMigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 0.70.0 (OP-040): Umzug in die zentrale Fallablage. Standard ist der Trockenlauf; kopiert und
 * umgeschaltet wird nur mit --execute und Bestätigungscode. Die Quellen bleiben unverändert.
 * 0.70.2: --source-folder führt eine Kopie im Ablagekonto (z. B. nach Zuordnung zu einem Teamordner)
 * in den Arbeitsordner zusammen; liegt die bisherige Ablage bereits in einem Teamordner, wird ohne
 * --copy-teamfolder nicht kopiert.
 */
class StorageMigrate extends Command {
	private const CONFIRMATION = 'BESTATTER-ABLAGE-ZENTRAL';

	public function __construct(private StorageMigrationService $migration) { parent::__construct(); }

	protected function configure(): void {
		$this->setName('bestatter:storage-migrate')
			->setDescription('Zeigt bzw. führt den Umzug der persönlichen Bestatter-Ablagen in die zentrale Fallablage aus (Standard: Trockenlauf).')
			->addOption('account', null, InputOption::VALUE_REQUIRED, 'Nextcloud-Konto der zentralen Ablage (Standard: Einstellung „Ablagekonto“)')
			->addOption('execute', null, InputOption::VALUE_NONE, 'Dateien kopieren, Verweise umschreiben und auf die zentrale Ablage umschalten')
			->addOption('confirmation', null, InputOption::VALUE_REQUIRED, 'Bestätigungscode ' . self::CONFIRMATION)
			->addOption('details', null, InputOption::VALUE_NONE, 'Jede Datei im Trockenlauf ausgeben')
			->addOption('source-folder', null, InputOption::VALUE_REQUIRED, 'Ordner im Ablagekonto als Quelle (z. B. „Bestatter (1)“ nach Zuordnung zu einem Teamordner); ersetzt die persönlichen Ordner als Quelle')
			->addOption('copy-teamfolder', null, InputOption::VALUE_NONE, 'Auch kopieren, wenn die bisherige Ablage bereits in einem Teamordner liegt (nicht empfohlen)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$account = (string)($input->getOption('account') ?? '');
		$sourceFolder = (string)($input->getOption('source-folder') ?? '');
		$plan = $this->migration->plan($account, $sourceFolder);
		$print = $plan;
		if (!$input->getOption('details')) unset($print['items']);
		$output->writeln(json_encode($print, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		if (!$input->getOption('execute')) {
			$output->writeln('<comment>Trockenlauf: keine Dateien kopiert, keine Daten verändert. Ausführen mit --execute --confirmation=' . self::CONFIRMATION . '</comment>');
			return self::SUCCESS;
		}
		if ((string)$input->getOption('confirmation') !== self::CONFIRMATION) {
			$output->writeln('<error>Bestätigungscode fehlt oder ist falsch.</error>');
			return self::FAILURE;
		}
		$result = $this->migration->execute($account, $sourceFolder, (bool)$input->getOption('copy-teamfolder'));
		$output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		$output->writeln($sourceFolder !== '' ? '<info>Ablage im Teamordner zusammengeführt. Der Quellordner „' . $sourceFolder . '“ ist unverändert erhalten (ohne Gruppenfreigabe).</info>' : '<info>Zentrale Fallablage aktiv. Die persönlichen Ordner der Quellen sind unverändert erhalten.</info>');
		return self::SUCCESS;
	}
}
