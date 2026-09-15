<?php

declare(strict_types=1);

namespace OCA\Bestatter\Command;

use OCA\Bestatter\Service\StabilizationService;
use OCA\Bestatter\Service\TeamService;
use OCA\Bestatter\Service\AuditService;
use OCA\Bestatter\AppInfo\Application;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Read-only preflight for the three-role acceptance matrix. */
class AcceptanceCheck extends Command {
	public function __construct(
		private IUserManager $users,
		private IGroupManager $groups,
		private IUserSession $userSession,
		private TeamService $team,
		private StabilizationService $stabilization,
		private AuditService $audit,
	) { parent::__construct(); }

	protected function configure(): void {
		$this->setName('bestatter:acceptance-check')->setDescription('Prüft Rollenmatrix und Systemzustand vor dem Browser-Abnahmetest.')
			->addOption('admin', null, InputOption::VALUE_REQUIRED, 'Bestatter-Administrationskonto', 'Bestatter-Administration')
			->addOption('member', null, InputOption::VALUE_REQUIRED, 'Bestatter-Fachbenutzer', 'Bestatter-User1')
			->addOption('outsider', null, InputOption::VALUE_REQUIRED, 'Angemeldetes Konto ohne Bestatter-Rolle');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$admin = (string)$input->getOption('admin'); $member = (string)$input->getOption('member'); $outsider = trim((string)$input->getOption('outsider'));
		$adminRole = $this->users->userExists($admin) && $this->team->hasBestatterAdminRole($admin);
		$memberRole = $this->users->userExists($member) && $this->team->hasBestatterRole($member);
		$outsiderRole = $outsider !== '' && $this->users->userExists($outsider) && !$this->team->hasBestatterRole($outsider);
		$contextUser = $this->users->get($admin) ?? $this->users->get($member);
		if ($contextUser === null) {
			$report = ['overall' => 'ERROR', 'summary' => ['ok' => 0, 'warnings' => 0, 'errors' => 1]];
		} else {
			$previousUser = $this->userSession->getUser();
			try {
				// OCC has no web session. Use an explicitly supplied, existing account
				// solely as read-only context for checks that resolve user folders.
				$this->userSession->setUser($contextUser);
				$report = $this->stabilization->report();
			} finally {
				$this->userSession->setUser($previousUser);
			}
		}
		$routeFile = dirname(__DIR__, 2) . '/appinfo/routes.php';
		$routes = is_file($routeFile) ? (require $routeFile)['routes'] ?? [] : [];
		$auditIntegrity = $this->audit->verifyIntegrity();
		$result = ['version'=>Application::VERSION, 'roles'=>[
			'admin'=>['uid'=>$admin,'exists'=>$this->users->userExists($admin),'expected'=>'Bestatter-Administration','valid'=>$adminRole],
			'member'=>['uid'=>$member,'exists'=>$this->users->userExists($member),'expected'=>'Bestatter-Mitglied','valid'=>$memberRole],
			'outsider'=>['uid'=>$outsider,'exists'=>$outsider!==''&&$this->users->userExists($outsider),'expected'=>'kein Bestatter-Zugriff','valid'=>$outsiderRole],
		], 'authorization'=>['middleware'=>'BestatterAccessMiddleware','registeredRoutes'=>count($routes),'defaultRule'=>'jede App-Route erfordert Mitgliedschaft; administrative Methoden prüfen zusätzlich die Administratorrolle'], 'auditIntegrity'=>$auditIntegrity, 'sampleAcceptance'=>['maximumCases'=>3,'massTestRequired'=>false], 'system'=>['overall'=>$report['overall'],'summary'=>$report['summary']], 'browserChecks'=>[
			'Administration: Systemprüfung, Niederlassungen und Konfiguration erreichbar.',
			'Fachbenutzer: Fälle, Aufgaben, Termine und Workflows nutzbar; Administration abgewiesen.',
			'Außenstehender Nutzer: App-Seite und sämtliche API-Routen liefern 403.',
			'Aufgabe/Termin in Nextcloud ändern und Rücksynchronisation samt Deep-Link prüfen.',
		]];
		$output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		if ($outsider === '') $output->writeln('<comment>Für die vollständige Rollenabnahme zusätzlich --outsider=<Testkonto-ohne-Bestatter-Gruppe> angeben.</comment>');
		return $adminRole && $memberRole && $outsiderRole && $report['overall'] !== 'ERROR' && $auditIntegrity['status'] !== 'ERROR' ? self::SUCCESS : self::FAILURE;
	}
}
