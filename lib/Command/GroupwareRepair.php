<?php
declare(strict_types=1);
namespace OCA\Bestatter\Command;
use OCA\Bestatter\Service\GroupwareRepairService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
/** 0.75.2: Zeigt bzw. bereinigt doppelte Termine/Folgeaufgaben aus Terminkopien und fehlerhafte Kontaktadressen. */
class GroupwareRepair extends Command {
	private const CONFIRMATION = 'BESTATTER-GROUPWARE-BEREINIGEN';
	public function __construct(private GroupwareRepairService $repair) { parent::__construct(); }
	protected function configure(): void {
		$this->setName('bestatter:groupware-repair')
			->setDescription('Zeigt bzw. bereinigt Altlasten der Nextcloud-Anbindung bis 0.75.1: doppelte Termine/Folgeaufgaben aus Terminkopien, über „Neu“ angelegte Kontaktadressen, durch Änderungen in Nextcloud verlorene App-Daten.')
			->addOption('execute', null, InputOption::VALUE_NONE, 'Bereinigung ausführen (ohne: nur Vorschau)')
			->addOption('confirmation', null, InputOption::VALUE_REQUIRED, 'Bestätigungscode ' . self::CONFIRMATION);
	}
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$preview = $this->repair->preview();
		foreach ($preview['mirrors'] as $mirror) {
			$output->writeln(sprintf('Doppelter %s #%d „%s“ (Kalender von %s, Original #%d von %s)', $mirror['type'] === 'task' ? 'Aufgabe' : 'Termin', $mirror['id'], $mirror['title'], $mirror['ownerUid'], $mirror['originalId'], $mirror['originalOwnerUid']));
			foreach ($mirror['followUps'] as $task) $output->writeln(sprintf('  └ Folgeaufgabe #%d „%s“ (%s, %s)', $task['id'], $task['title'], $task['status'], $task['ownerUid']));
		}
		foreach ($preview['contacts'] as $contact) $output->writeln(sprintf('Kontaktadresse reparieren: „%s“ (Adressbuch %s)', $contact['name'], $contact['addressBook']));
		foreach ($preview['lostData'] as $item) $output->writeln(sprintf('App-Daten wiederherstellen: %s #%d (%s) aus %s: %s', $item['type'] === 'task' ? 'Aufgabe' : 'Termin', $item['id'], $item['ownerUid'], $item['source'], implode(', ', $item['keys'])));
		$followUps = array_sum(array_map(static fn(array $mirror): int => count($mirror['followUps']), $preview['mirrors']));
		$output->writeln(sprintf('Gefunden: %d doppelte Einträge, %d Folgeaufgaben, %d Kontaktadressen, %d Einträge mit verlorenen App-Daten.', count($preview['mirrors']), $followUps, count($preview['contacts']), count($preview['lostData'])));
		if (!$input->getOption('execute')) {
			if ($preview['mirrors'] !== [] || $preview['contacts'] !== [] || $preview['lostData'] !== []) $output->writeln('<comment>Vorschau: keine Daten verändert. Ausführen mit --execute --confirmation=' . self::CONFIRMATION . '</comment>');
			return self::SUCCESS;
		}
		if ((string)$input->getOption('confirmation') !== self::CONFIRMATION) { $output->writeln('<error>Bestätigungscode fehlt oder ist falsch.</error>'); return self::FAILURE; }
		$result = $this->repair->execute();
		$output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		return $result['calendar']['errors'] === [] && $result['contactErrors'] === [] ? self::SUCCESS : self::FAILURE;
	}
}
