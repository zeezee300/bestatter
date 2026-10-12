<?php
declare(strict_types=1);
namespace OCA\Bestatter\Command;

use OCA\Bestatter\Service\CaseFolderPlanService;
use OCA\Bestatter\Service\InstallationConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 0.72.0: Fall-Unterordner umbenennen – für große Bestände, bei denen die Übernahme in der Oberfläche zu lange
 * dauern würde. Standard ist der Trockenlauf; umbenannt wird nur mit --execute und Bestätigungscode.
 */
class CaseFolderRename extends Command {
	public function __construct(private CaseFolderPlanService $plans, private InstallationConfigService $installation) { parent::__construct(); }

	protected function configure(): void {
		$this->setName('bestatter:case-folder-rename')
			->setDescription('Benennt einen Fall-Unterordner im Ordnerplan und in allen vorhandenen Fallakten um (Standard: Trockenlauf).')
			->addArgument('folder', InputArgument::REQUIRED, 'Kennzeichen (z. B. STAMMDATEN) oder aktueller Name (z. B. „01 Stammdaten“)')
			->addArgument('name', InputArgument::REQUIRED, 'Neuer Ordnername')
			->addOption('execute', null, InputOption::VALUE_NONE, 'Umbenennung ausführen')
			->addOption('confirmation', null, InputOption::VALUE_REQUIRED, 'Bestätigungscode ' . CaseFolderPlanService::CONFIRMATION);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$reference = (string)$input->getArgument('folder');
		$plan = $this->installation->folderPlan();
		$found = false;
		foreach ($plan as &$item) {
			if ($item['key'] === strtoupper($reference) || $item['name'] === $reference) { $item['name'] = trim((string)$input->getArgument('name')); $found = true; }
		}
		unset($item);
		if (!$found) { $output->writeln('<error>Ordner „' . $reference . '“ ist nicht im Ordnerplan. Vorhanden: ' . implode(', ', array_map(static fn(array $item): string => $item['key'] . ' = ' . $item['name'], $this->installation->folderPlan())) . '</error>'); return self::FAILURE; }
		$preview = $this->plans->preview($plan);
		unset($preview['plan']);
		$output->writeln(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		if (!$input->getOption('execute')) {
			$output->writeln('<comment>Trockenlauf: nichts verändert. Ausführen mit --execute --confirmation=' . CaseFolderPlanService::CONFIRMATION . '</comment>');
			return self::SUCCESS;
		}
		if ((string)$input->getOption('confirmation') !== CaseFolderPlanService::CONFIRMATION) { $output->writeln('<error>Bestätigungscode fehlt oder ist falsch.</error>'); return self::FAILURE; }
		$result = $this->plans->apply($plan);
		$output->writeln(json_encode(['renamedFolders' => $result['renamedFolders'] ?? 0, 'updatedReferences' => $result['updatedReferences'] ?? []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		$output->writeln('<info>Ordner umbenannt.</info>');
		return self::SUCCESS;
	}
}
