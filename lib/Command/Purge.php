<?php

declare(strict_types=1);

namespace OCA\Bestatter\Command;

use OCA\Bestatter\Service\PurgeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Purge extends Command {
	private const CONFIRMATION = 'BESTATTER-ENDGUELTIG-LOESCHEN';
	public function __construct(private PurgeService $purge) { parent::__construct(); }
	protected function configure(): void { $this->setName('bestatter:purge')->setDescription('Zeigt oder entfernt Bestatter-Daten kontrolliert; Standard ist ein rein lesender Trockenlauf.')->addOption('execute',null,InputOption::VALUE_NONE,'Bereinigung ausführen')->addOption('confirmation',null,InputOption::VALUE_REQUIRED,'Fester Bestätigungscode')->addOption('backup-package',null,InputOption::VALUE_REQUIRED,'Absoluter Pfad eines zuvor geprüften vollständigen Sicherungspakets'); }
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln('<info>Bestatter-Purge – Vorschau</info>'); $output->writeln(json_encode($this->purge->preview(), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
		if (!$input->getOption('execute')) { $output->writeln('<comment>Keine Daten verändert. Sicherungspaket, --execute und --confirmation='.self::CONFIRMATION.' sind erforderlich.</comment>'); return self::SUCCESS; }
		if ((string)$input->getOption('confirmation') !== self::CONFIRMATION) { $output->writeln('<error>Bestätigungscode fehlt oder ist falsch.</error>'); return self::INVALID; }
		$package = trim((string)$input->getOption('backup-package')); if ($package === '') { $output->writeln('<error>Ein geprüftes vollständiges Sicherungspaket ist erforderlich.</error>'); return self::INVALID; }
		$output->writeln(json_encode($this->purge->purge($package), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); return self::SUCCESS;
	}
}
