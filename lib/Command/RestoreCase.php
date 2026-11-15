<?php

declare(strict_types=1);

namespace OCA\Bestatter\Command;

use OCA\Bestatter\Service\CaseRestoreService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 0.92.6 – Einzelnen gelöschten Fall aus einem Sicherungspaket zurückholen.
 *   occ bestatter:restore-case <paket.zip>                       gelöschte Fälle im Paket auflisten
 *   occ bestatter:restore-case <paket.zip> <Fallnummer>          Prüflauf (ändert nichts)
 *   occ bestatter:restore-case <paket.zip> <Fallnummer> --execute --confirmation=BESTATTER-FALL-ZURUECK
 */
class RestoreCase extends Command {
	public function __construct(
		private CaseRestoreService $restore,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('bestatter:restore-case')
			->setDescription('Holt einen gelöschten Fall aus einem Sicherungspaket (ZIP) zurück. Ohne Fallnummer: Fälle im Paket auflisten; ohne --execute: Prüflauf.')
			->addArgument('file', InputArgument::REQUIRED, 'Absoluter Pfad des Sicherungspakets (bestatter-package-….zip)')
			->addArgument('case', InputArgument::OPTIONAL, 'Fallnummer (z. B. 2026-0012) oder Fall-ID')
			->addOption('execute', null, InputOption::VALUE_NONE, 'Fall zurückholen')
			->addOption('confirmation', null, InputOption::VALUE_REQUIRED, 'Bestätigungscode');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$path = (string)$input->getArgument('file');
		$case = trim((string)$input->getArgument('case'));
		if ($case === '') {
			$list = $this->restore->candidates($path);
			$output->writeln('Sicherung ' . $list['package']['name'] . ' vom ' . $list['package']['createdAt'] . ' (App-Version ' . $list['package']['appVersion'] . ')');
			$restorable = array_values(array_filter($list['cases'], static fn (array $item): bool => $item['restorable']));
			if ($restorable === []) {
				$output->writeln('<comment>Alle Fälle dieser Sicherung sind in der App vorhanden – es gibt nichts zurückzuholen.</comment>');
				return self::SUCCESS;
			}
			$output->writeln('Gelöschte Fälle, die sich zurückholen lassen:');
			foreach ($restorable as $item) {
				$output->writeln(sprintf('  %-16s %-30s %-10s %3d Dokument(e), %3d Rechnung(en)%s', $item['caseNumber'], $item['name'] !== '' ? $item['name'] : '(ohne Namen)', $item['kindLabel'], $item['files'], $item['invoices'], $item['deletedAt'] !== '' ? ', gelöscht ' . $item['deletedAt'] . ($item['deletedBy'] !== '' ? ' von ' . $item['deletedBy'] : '') : ''));
			}
			$output->writeln('<comment>Prüflauf: occ bestatter:restore-case ' . $path . ' <Fallnummer></comment>');
			return self::SUCCESS;
		}
		$preview = $this->restore->preview($path, $case);
		$output->writeln('Fall ' . $preview['caseNumber'] . ($preview['name'] !== '' ? ' (' . $preview['name'] . ')' : '') . ' aus ' . $preview['package']['name'] . ' vom ' . $preview['package']['createdAt']);
		foreach ($preview['tables'] as $table) {
			$output->writeln(sprintf('  %4d  %s', $table['count'], $table['label']));
		}
		$output->writeln(sprintf('  %4d  Dokumente der Fallakte (%s KB)', $preview['fileCount'], number_format($preview['fileBytes'] / 1024, 0, ',', '.')));
		if ($preview['auditEntries'] > 0) {
			$output->writeln(sprintf('  %4d  Einträge im Fall-Verlauf bekommen ihre Inhalte zurück', $preview['auditEntries']));
		}
		foreach ($preview['hints'] as $hint) {
			$output->writeln('<info>Hinweis: ' . $hint . '</info>');
		}
		foreach ($preview['warnings'] as $warning) {
			$output->writeln('<comment>Achtung: ' . $warning . '</comment>');
		}
		foreach ($preview['conflicts'] as $conflict) {
			$output->writeln('<error>Sperrt: ' . $conflict . '</error>');
		}
		if (!$input->getOption('execute')) {
			$output->writeln($preview['canRestore']
				? '<comment>Prüflauf: nichts verändert. Zurückholen mit --execute --confirmation=' . CaseRestoreService::CONFIRMATION . '</comment>'
				: '<error>Der Fall lässt sich so nicht zurückholen.</error>');
			return $preview['canRestore'] ? self::SUCCESS : self::FAILURE;
		}
		if ((string)$input->getOption('confirmation') !== CaseRestoreService::CONFIRMATION) {
			$output->writeln('<error>Bestätigungscode fehlt oder ist falsch.</error>');
			return self::FAILURE;
		}
		$result = $this->restore->restore($path, $case);
		$output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		$output->writeln('<info>Fall ' . $result['caseNumber'] . ' ist zurück. Bitte in der App öffnen und Dokumente, Termine und Rechnungen stichprobenartig prüfen.</info>');
		return self::SUCCESS;
	}
}
