<?php

declare(strict_types=1);

namespace OCA\Bestatter\Command;

use OCA\Bestatter\Service\FileReferenceRepairService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 0.92.6 – Datei-Verweise nach einem Restore vor 0.92.6 reparieren.
 *   occ bestatter:repair-file-references                       Prüflauf (ändert nichts)
 *   occ bestatter:repair-file-references --execute --confirmation=BESTATTER-DATEIVERWEISE
 */
class RepairFileReferences extends Command {
	public function __construct(
		private FileReferenceRepairService $repair,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('bestatter:repair-file-references')
			->setDescription('Ordnet Datei-Verweise der Falldaten nach einer Wiederherstellung (vor 0.92.6) über den Ablageort neu zu. Ohne --execute nur Prüflauf.')
			->addOption('execute', null, InputOption::VALUE_NONE, 'Verweise ändern')
			->addOption('confirmation', null, InputOption::VALUE_REQUIRED, 'Bestätigungscode');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$execute = (bool)$input->getOption('execute');
		if ($execute && (string)$input->getOption('confirmation') !== FileReferenceRepairService::CONFIRMATION) {
			$output->writeln('<error>Bestätigungscode fehlt oder ist falsch.</error>');
			return self::FAILURE;
		}
		$result = $this->repair->run($execute);
		foreach ($result['tables'] as $table => $counts) {
			$output->writeln(sprintf('  %-34s %4d Zeile(n), %4d Verweis(e)', preg_replace('/^bestatter_/', '', $table), $counts['rows'], $counts['references']));
		}
		foreach ($result['examples'] as $example) {
			$output->writeln(sprintf('    %s #%d: %d → %d  %s', preg_replace('/^bestatter_/', '', (string)$example['table']), $example['id'], $example['from'], $example['to'], $example['path']));
		}
		if ($result['references'] === 0) {
			$output->writeln('<info>Alle Datei-Verweise mit Ablageort passen – nichts zu tun.</info>');
		} elseif ($execute) {
			$output->writeln('<info>' . $result['references'] . ' Datei-Verweis(e) in ' . $result['rows'] . ' Zeile(n) neu zugeordnet.</info>');
		} else {
			$output->writeln('<comment>Prüflauf: ' . $result['references'] . ' Datei-Verweis(e) in ' . $result['rows'] . ' Zeile(n) würden neu zugeordnet. Ändern mit --execute --confirmation=' . FileReferenceRepairService::CONFIRMATION . '</comment>');
		}
		if ($result['unresolved'] > 0) {
			$output->writeln('<comment>' . $result['unresolved'] . ' Verweis(e) zeigen auf keine vorhandene Datei und haben keinen Ablageort – sie bleiben unverändert (z. B. gelöschte Dateien oder abgeschlossene Paperless-Übertragungen).</comment>');
		}
		return self::SUCCESS;
	}
}
