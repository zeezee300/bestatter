<?php
declare(strict_types=1);
namespace OCA\Bestatter\Command;
use OCA\Bestatter\Service\SetupPackageService;
use OCA\Bestatter\Service\TeamService;
use OCP\IUserManager;
use OCP\IUserSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
/**
 * 0.75.3: Einrichtungspaket exportieren oder einspielen (OP-063).
 *   occ bestatter:setup-package export <ziel.zip> --user=<admin> [--customer=…] [--types=articles,order-form,…]
 *   occ bestatter:setup-package import <paket.zip> --user=<admin>                       (Vorschau)
 *   occ bestatter:setup-package import <paket.zip> --user=<admin> --execute --confirmation=BESTATTER-PAKET-EINSPIELEN [--action=<Nr>=<AKTION> …]
 */
class SetupPackage extends Command {
	private const CONFIRMATION = 'BESTATTER-PAKET-EINSPIELEN';
	public function __construct(private SetupPackageService $packages, private IUserManager $users, private IUserSession $session, private TeamService $team) { parent::__construct(); }
	protected function configure(): void {
		$this->setName('bestatter:setup-package')
			->setDescription('Exportiert oder spielt ein kundenspezifisches Einrichtungspaket ein (Katalog, Preislisten, Dokumentvorlagen, Auftragsformulare, Ablaufvorlagen, Logos).')
			->addArgument('mode', InputArgument::REQUIRED, 'export oder import')
			->addArgument('file', InputArgument::REQUIRED, 'ZIP-Datei (Ziel beim Export, Quelle beim Import)')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'Bestatter-Administrator, in dessen Namen gearbeitet wird (Vorlagenordner, Protokoll)')
			->addOption('customer', null, InputOption::VALUE_REQUIRED, 'Kundenname im Paket (nur Export)', '')
			->addOption('types', null, InputOption::VALUE_REQUIRED, 'Inhaltsarten, kommagetrennt: ' . implode(',', SetupPackageService::TYPES), '')
			->addOption('execute', null, InputOption::VALUE_NONE, 'Einspielen ausführen (ohne: nur Vorschau)')
			->addOption('confirmation', null, InputOption::VALUE_REQUIRED, 'Bestätigungscode ' . self::CONFIRMATION)
			->addOption('action', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Aktion für einen Eintrag der Vorschau: <Nr>=CREATE|UPDATE|REPLACE|COPY|SKIP');
	}
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = $this->users->get((string)$input->getOption('user'));
		if ($user === null) { $output->writeln('<error>Bitte mit --user=<Benutzer> einen Bestatter-Administrator angeben.</error>'); return self::FAILURE; }
		$previous = $this->session->getUser();
		$this->session->setVolatileActiveUser($user);
		try {
			if (!$this->team->isBestatterAdmin()) { $output->writeln('<error>' . $user->getUID() . ' ist kein Bestatter-Administrator.</error>'); return self::FAILURE; }
			$file = (string)$input->getArgument('file');
			if ($input->getArgument('mode') === 'export') {
				$result = $this->packages->export((string)$input->getOption('customer'), array_values(array_filter(array_map('trim', explode(',', (string)$input->getOption('types'))))));
				if (file_put_contents($file, $result['content']) === false) { $output->writeln('<error>Die Datei konnte nicht geschrieben werden: ' . $file . '</error>'); return self::FAILURE; }
				foreach ($result['contents'] as $entry) $output->writeln(sprintf('  %-22s %s', SetupPackageService::TYPE_LABELS[$entry['type']], $entry['title']));
				$output->writeln(sprintf('Einrichtungspaket mit %d Einträgen geschrieben: %s', count($result['contents']), $file));
				return self::SUCCESS;
			}
			if ($input->getArgument('mode') !== 'import') { $output->writeln('<error>Modus „export“ oder „import“ angeben.</error>'); return self::FAILURE; }
			$content = is_file($file) ? (string)file_get_contents($file) : '';
			$preview = $this->packages->preview($content);
			$output->writeln('Paket: ' . ($preview['manifest']['customer'] ?: '–') . ' · erstellt ' . ($preview['manifest']['createdAt'] ?? '') . ' · Bestatter ' . ($preview['manifest']['appVersion'] ?? ''));
			foreach ($preview['warnings'] as $warning) $output->writeln('<comment>Hinweis: ' . $warning . '</comment>');
			foreach ($preview['items'] as $item) {
				$output->writeln(sprintf('[%d] %-22s %-28s %-9s Vorschlag: %-8s (möglich: %s) %s', $item['index'], $item['typeLabel'], $item['title'], $item['status'], $item['defaultAction'], implode('/', $item['actions']), $item['details']));
				foreach ($item['warnings'] as $warning) $output->writeln('      <comment>' . $warning . '</comment>');
			}
			if (!$input->getOption('execute')) { $output->writeln('<comment>Vorschau: keine Daten verändert. Einspielen mit --execute --confirmation=' . self::CONFIRMATION . ' [--action=<Nr>=<AKTION>]</comment>'); return self::SUCCESS; }
			if ((string)$input->getOption('confirmation') !== self::CONFIRMATION) { $output->writeln('<error>Bestätigungscode fehlt oder ist falsch.</error>'); return self::FAILURE; }
			$decisions = [];
			foreach ((array)$input->getOption('action') as $pair) if (preg_match('/^(\d+)=([A-Z]+)$/', strtoupper(trim((string)$pair)), $m)) $decisions[$m[1]] = $m[2];
			$result = $this->packages->apply($content, $decisions);
			$output->writeln('Sicherung vorher: ' . $result['backup']['path']);
			foreach ($result['results'] as $row) $output->writeln(sprintf('[%d] %-22s %-28s %-18s %s%s', $row['index'], $row['typeLabel'], $row['title'], $row['actionLabel'], $row['ok'] ? '' : 'FEHLER: ', $row['message']));
			$output->writeln(sprintf('Erledigt: %d, übersprungen: %d, fehlgeschlagen: %d', $result['summary']['done'], $result['summary']['skipped'], $result['summary']['failed']));
			return $result['summary']['failed'] === 0 ? self::SUCCESS : self::FAILURE;
		} catch (\InvalidArgumentException $error) {
			$output->writeln('<error>' . $error->getMessage() . '</error>');
			return self::FAILURE;
		} finally {
			$this->session->setVolatileActiveUser($previous);
		}
	}
}
