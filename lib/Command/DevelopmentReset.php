<?php

declare(strict_types=1);

namespace OCA\Bestatter\Command;

use OCA\Bestatter\Service\DevelopmentResetService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DevelopmentReset extends Command {
	private const CONFIRMATION = 'BESTATTER-ENTWICKLUNGSRESET';

	public function __construct(private DevelopmentResetService $resetService) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('bestatter:development-reset')
			->setDescription('Zeigt oder löscht freigegebene Bestatter-Testbetriebsdaten; Konfiguration und Stammdaten bleiben erhalten.')
			->addOption('execute', null, InputOption::VALUE_NONE, 'Reset tatsächlich ausführen')
			->addOption('confirmation', null, InputOption::VALUE_REQUIRED, 'Verpflichtender Bestätigungscode')
			->addOption('backup', null, InputOption::VALUE_REQUIRED, 'Optionaler absoluter Pfad der JSON-Sicherung');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$preview = $this->resetService->preview();
		$output->writeln('<info>Bestatter-Entwicklungsreset – Vorschau</info>');
		$output->writeln(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		if (!$input->getOption('execute')) {
			$output->writeln('<comment>Keine Daten verändert. Zur Ausführung --execute und den ausgegebenen Bestätigungscode verwenden.</comment>');
			$output->writeln('Bestätigungscode: ' . self::CONFIRMATION);
			return self::SUCCESS;
		}
		if ((string)$input->getOption('confirmation') !== self::CONFIRMATION) {
			$output->writeln('<error>Reset abgebrochen: Bestätigungscode fehlt oder ist falsch.</error>');
			return self::INVALID;
		}
		$result = $this->resetService->reset($input->getOption('backup') ?: null);
		$output->writeln('<info>Entwicklungsreset abgeschlossen.</info>');
		$output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		if (($result['calendar']['errors'] ?? []) !== [] || ($result['folders']['errors'] ?? []) !== []) {
			$output->writeln('<comment>Der Datenbankreset ist abgeschlossen; externe Bereinigungswarnungen stehen in der Ausgabe und in der Sicherung.</comment>');
		}
		return self::SUCCESS;
	}
}
