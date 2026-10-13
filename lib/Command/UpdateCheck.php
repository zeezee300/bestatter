<?php

declare(strict_types=1);

namespace OCA\Bestatter\Command;

use OCA\Bestatter\AppInfo\Application;
use OCA\Bestatter\Service\AuditService;
use OCA\Bestatter\Service\DependencyCheck;
use OCA\Bestatter\Service\InstallationConfigService;
use OCA\Bestatter\Service\StabilizationService;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 0.83.1 – Prüfung nach einem Update (nur lesend, außer mit --record): installierte Version = Programmversion,
 * App aktiv, Systemprüfung (ohne Fehler), Audit-Kette intakt. Wird von tools/update/bestatter-update.sh aufgerufen.
 * Mit --record wird das Ergebnis als letzter Update-Lauf in der App-Konfiguration vermerkt (für eine spätere Anzeige
 * in der Oberfläche, OP-068). Rückgabe: 0 = in Ordnung (auch mit Hinweisen), 1 = Fehler.
 */
class UpdateCheck extends Command {
	public const HISTORY_KEY = 'update_history';

	public function __construct(
		private IAppManager $appManager,
		private IConfig $config,
		private IUserManager $users,
		private IGroupManager $groups,
		private IUserSession $userSession,
		private InstallationConfigService $installation,
		private StabilizationService $stabilization,
		private AuditService $audit,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('bestatter:update-check')
			->setDescription('Prüft nach einem Update Version, Systemprüfung und Audit-Kette (nur lesend; --record vermerkt das Ergebnis).')
			->addOption('expect-version', null, InputOption::VALUE_REQUIRED, 'Erwartete Programmversion, z. B. 0.83.1')
			->addOption('as-user', null, InputOption::VALUE_REQUIRED, 'Konto als Lesekontext der Systemprüfung (Standard: erstes Mitglied einer Bestatter-Administrationsgruppe)')
			->addOption('record', null, InputOption::VALUE_NONE, 'Ergebnis als letzten Update-Lauf vermerken')
			->addOption('from-version', null, InputOption::VALUE_REQUIRED, 'Version vor dem Update (nur mit --record)')
			->addOption('backup', null, InputOption::VALUE_REQUIRED, 'Pfad der vor dem Update erstellten Sicherung (nur mit --record)')
			->addOption('json', null, InputOption::VALUE_NONE, 'Ergebnis nur als JSON ausgeben');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$expected = trim((string)$input->getOption('expect-version'));
		$installed = (string)$this->config->getAppValue(Application::APP_ID, 'installed_version', '');
		$enabled = $this->appManager->isInstalled(Application::APP_ID);
		$checks = [];
		$checks[] = ['key' => 'version', 'label' => 'Programmversion', 'status' => $installed === Application::VERSION && ($expected === '' || $expected === Application::VERSION) ? 'OK' : 'ERROR',
			'message' => 'Programm ' . Application::VERSION . ', in der Datenbank ' . ($installed !== '' ? $installed : '–') . ($expected !== '' ? ', erwartet ' . $expected : '')
				. ($installed !== Application::VERSION ? ' – „occ upgrade“ ausführen' : '')];
		$checks[] = ['key' => 'enabled', 'label' => 'App aktiv', 'status' => $enabled ? 'OK' : 'ERROR', 'message' => $enabled ? 'aktiviert' : 'nicht aktiviert – „occ app:enable bestatter“'];

		// 0.87.0: unabhängig vom Konto der Systemprüfung – fehlt vendor/, wurde nicht das gebaute Paket eingespielt.
		$dependencies = DependencyCheck::report();
		$checks[] = ['key' => 'dependencies', 'label' => 'PHP-Abhängigkeiten', 'status' => $dependencies['status'], 'message' => $dependencies['message'] . ($dependencies['status'] === 'OK' ? '' : ' Bitte das Installationspaket statt des Quellcode-Ordners verwenden.')];

		$asUser = trim((string)$input->getOption('as-user'));
		$context = $this->contextUser($asUser);
		if ($context === null) {
			$checks[] = $asUser !== ''
				? ['key' => 'system', 'label' => 'Systemprüfung', 'status' => 'ERROR', 'message' => 'Konto „' . $asUser . '“ gibt es nicht (--as-user)']
				: ['key' => 'system', 'label' => 'Systemprüfung', 'status' => 'WARN', 'message' => 'übersprungen: kein aktives Konto in einer Bestatter-Administrationsgruppe gefunden (--as-user angeben)'];
			$system = null;
		} else {
			$previous = $this->userSession->getUser();
			try {
				$this->userSession->setUser($context);
				$system = $this->stabilization->report();
			} finally {
				$this->userSession->setUser($previous);
			}
			$problems = array_values(array_map(static fn(array $check): string => $check['label'] . ': ' . $check['message'], array_filter($system['checks'], static fn(array $check): bool => $check['status'] === 'ERROR')));
			$checks[] = ['key' => 'system', 'label' => 'Systemprüfung', 'status' => $system['overall'] === 'ERROR' ? 'ERROR' : ($system['overall'] === 'WARN' ? 'WARN' : 'OK'),
				'message' => sprintf('%d in Ordnung, %d Hinweise, %d Fehler', $system['summary']['ok'], $system['summary']['warnings'], $system['summary']['errors']) . ($problems !== [] ? ' – ' . implode('; ', $problems) : '')];
		}
		$audit = $this->audit->verifyIntegrity();
		// 0.84.1: gleichzeitig geschriebene Einträge (Inhalt unverändert) sind ein Hinweis, echte Schäden ein Fehler.
		$checks[] = ['key' => 'audit', 'label' => 'Audit-Kette', 'status' => in_array($audit['status'], ['OK', 'WARN'], true) ? $audit['status'] : 'ERROR', 'message' => $audit['message']];

		$overall = 'OK';
		foreach ($checks as $check) { if ($check['status'] === 'ERROR') { $overall = 'ERROR'; break; } if ($check['status'] === 'WARN') $overall = 'WARN'; }
		$result = ['version' => Application::VERSION, 'installedVersion' => $installed, 'overall' => $overall, 'checkedAs' => $context?->getUID(), 'checkedAt' => date('c'), 'checks' => $checks];

		if ($input->getOption('record')) {
			$entry = ['from' => trim((string)$input->getOption('from-version')), 'to' => Application::VERSION, 'at' => date('c'), 'overall' => $overall, 'backup' => trim((string)$input->getOption('backup'))];
			$history = json_decode((string)$this->config->getAppValue(Application::APP_ID, self::HISTORY_KEY, '[]'), true);
			$history = is_array($history) ? $history : [];
			array_unshift($history, $entry);
			$this->config->setAppValue(Application::APP_ID, self::HISTORY_KEY, json_encode(array_slice($history, 0, 20), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
			$this->audit->logSystem('APP_UPDATE', 'VERIFIED', $entry);
			$result['recorded'] = $entry;
		}

		if ($input->getOption('json')) {
			$output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		} else {
			$symbols = ['OK' => 'OK     ', 'WARN' => 'HINWEIS', 'ERROR' => 'FEHLER '];
			foreach ($checks as $check) $output->writeln($symbols[$check['status']] . ' ' . $check['label'] . ': ' . $check['message']);
			$output->writeln('Ergebnis: ' . ['OK' => 'in Ordnung', 'WARN' => 'in Ordnung, mit Hinweisen', 'ERROR' => 'FEHLER'][$overall] . ' (Bestatter ' . Application::VERSION . ')');
		}
		return $overall === 'ERROR' ? self::FAILURE : self::SUCCESS;
	}

	private function contextUser(string $uid): ?\OCP\IUser {
		if ($uid !== '') return $this->users->get($uid);
		foreach ($this->installation->adminGroups() as $groupId) {
			$group = $this->groups->get($groupId);
			if ($group === null) continue;
			foreach ($group->getUsers() as $user) if ($user->isEnabled()) return $user;
		}
		return null;
	}
}
