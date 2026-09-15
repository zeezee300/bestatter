<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/** Geführte, wiederaufnehmbare Ersteinrichtung ohne automatische Benutzeranlage. */
class OnboardingService {
	public function __construct(
		private InstallationConfigService $installation,
		private ConfigurationService $configuration,
		private CustomizingService $customizing,
		private FolderService $folders,
		private IGroupManager $groups,
		private IUserManager $users,
		private IUserSession $userSession,
		private IConfig $config,
		private AuditService $audit,
	) {}

	public function status(): array {
		$settings = $this->installation->settings();
		$member = $this->groupStatus($settings['memberGroup']);
		$admins = array_map(fn(string $name): array => $this->groupStatus($name), $settings['adminGroups']);
		$adminCount = array_sum(array_column($admins, 'members'));
		$currentUid = $this->userSession->getUser()?->getUID() ?? '';
		$currentIsSystemAdmin = $currentUid !== '' && $this->groups->isAdmin($currentUid);
		$branches = $this->configuration->branches();
		$activeBranches = array_values(array_filter($branches, static fn(array $branch): bool => (bool)$branch['active']));
		$templates = array_values(array_filter($this->configuration->documents(), static fn(array $template): bool => (bool)$template['active']));
		$checks = [
			['key' => 'memberGroup', 'label' => 'Bestatter-Mitgliedergruppe', 'status' => $member['exists'] && $member['members'] > 0 ? 'OK' : 'ERROR', 'message' => $member['exists'] ? $member['members'] . ' Mitglied(er)' : 'Gruppe fehlt'],
			['key' => 'adminGroup', 'label' => 'Bestatter-Administration', 'status' => $adminCount > 0 || $currentIsSystemAdmin ? 'OK' : 'ERROR', 'message' => $adminCount > 0 ? $adminCount . ' zugeordnete(r) Administrator(en)' : ($currentIsSystemAdmin ? 'Nextcloud-Systemadministrator ist handlungsfähig; Gruppenzuordnung empfohlen' : 'Keine administrative Person zugeordnet')],
			['key' => 'branch', 'label' => 'Aktive Niederlassung', 'status' => $activeBranches !== [] ? 'OK' : 'ERROR', 'message' => count($activeBranches) . ' aktive Niederlassung(en)'],
			['key' => 'templates', 'label' => 'Dokumentvorlagen', 'status' => $templates !== [] ? 'OK' : 'WARN', 'message' => count($templates) . ' aktive Vorlagenkonfiguration(en)'],
		];
		$ready = !array_filter($checks, static fn(array $check): bool => $check['status'] === 'ERROR');
		return ['ready' => $ready, 'completed' => $this->config->getAppValue(Application::APP_ID, 'onboarding_completed', '0') === '1', 'blocking' => false, 'settings' => $settings, 'groups' => ['member' => $member, 'admins' => $admins], 'branches' => $branches, 'checks' => $checks, 'users' => $this->availableUsers(), 'currentUid' => $currentUid];
	}

	public function configure(array $input): array {
		$settings = $this->installation->save((array)($input['settings'] ?? []));
		$groupNames = array_unique([$settings['memberGroup'], ...$settings['adminGroups']]);
		if ((bool)($input['createMissingGroups'] ?? false)) foreach ($groupNames as $name) if ($this->groups->get($name) === null) $this->groups->createGroup($name);
		foreach ($groupNames as $name) if ($this->groups->get($name) === null) throw new \InvalidArgumentException('Die Gruppe "' . $name . '" existiert nicht. Bitte anlegen oder die automatische Anlage bestätigen.');
		$memberUids = $this->validUids((array)($input['memberUids'] ?? []));
		$adminUids = $this->validUids((array)($input['adminUids'] ?? []));
		if ((bool)($input['singlePerson'] ?? false)) $memberUids = array_values(array_unique([...$memberUids, ...$adminUids]));
		if ($memberUids === [] || $adminUids === []) throw new \InvalidArgumentException('Mindestens ein Fachbenutzer und ein Bestatter-Administrator müssen ausgewählt werden.');
		$memberGroup = $this->groups->get($settings['memberGroup']);
		foreach ($memberUids as $uid) $memberGroup?->addUser($this->requiredUser($uid));
		$adminGroup = $this->groups->get($settings['adminGroups'][0]);
		foreach ($adminUids as $uid) $adminGroup?->addUser($this->requiredUser($uid));
		$this->customizing->ensureSeedData(); $this->configuration->ensureSeedData(); $this->folders->templatesFolder();
		$branch = (array)($input['branch'] ?? []);
		if (trim((string)($branch['key'] ?? '')) !== '' || trim((string)($branch['name'] ?? '')) !== '') {
			$branch['memberUids'] = $memberUids; $branch['active'] = true;
			$this->configuration->saveBranch($branch, (int)($branch['id'] ?? 0));
		}
		if ((bool)($input['provisionTemplates'] ?? true)) $this->configuration->provisionTemplates();
		$status = $this->status();
		if (!$status['ready']) throw new \RuntimeException('Die Einrichtung ist noch nicht betriebsbereit. Bitte die markierten Punkte ergänzen.');
		$this->config->setAppValue(Application::APP_ID, 'onboarding_completed', '1');
		$this->audit->logSystem('ONBOARDING', 'COMPLETED', ['memberGroup' => $settings['memberGroup'], 'adminGroups' => $settings['adminGroups'], 'memberCount' => count($memberUids), 'adminCount' => count($adminUids)]);
		return $this->status();
	}

	private function groupStatus(string $name): array {
		$group = $this->groups->get($name);
		$uids = $group === null ? [] : array_map(static fn(\OCP\IUser $user): string => $user->getUID(), $group->getUsers());
		sort($uids, SORT_NATURAL | SORT_FLAG_CASE);
		return ['name' => $name, 'exists' => $group !== null, 'members' => count($uids), 'uids' => $uids];
	}
	private function availableUsers(): array {
		$result = []; foreach ($this->users->search('', 100) as $user) $result[] = ['uid' => $user->getUID(), 'displayName' => $user->getDisplayName() ?: $user->getUID(), 'email' => $user->getEMailAddress() ?: ''];
		usort($result, static fn(array $left, array $right): int => strcasecmp($left['displayName'], $right['displayName'])); return $result;
	}
	private function validUids(array $uids): array { return array_values(array_unique(array_filter(array_map('trim', array_map('strval', $uids))))); }
	private function requiredUser(string $uid): \OCP\IUser { $user = $this->users->get($uid); if ($user === null) throw new \InvalidArgumentException('Der ausgewählte Nextcloud-Benutzer "' . $uid . '" existiert nicht.'); return $user; }
}
