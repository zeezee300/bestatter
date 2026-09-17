<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\Exception\BestatterAccessDeniedException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;

class TeamService {
	public function __construct(
		private IGroupManager $groups,
		private IUserSession $userSession,
		private InstallationConfigService $installationConfig,
	) {}

	public function overview(): array {
		$current = $this->currentUser();
		$members = [];
		$group = $this->groups->get($this->installationConfig->memberGroup());
		if ($group !== null) {
			foreach ($group->getUsers() as $user) {
				$members[$user->getUID()] = $this->map($user);
			}
		}
		$members[$current->getUID()] ??= $this->map($current);
		$members = array_values($members);
		usort($members, static fn(array $left, array $right): int => strcasecmp($left['displayName'], $right['displayName']));

		return [
			'group' => $this->installationConfig->memberGroup(),
			'adminGroup' => $this->installationConfig->adminGroups()[0] ?? '',
			'adminGroups' => $this->installationConfig->adminGroups(),
			'currentUid' => $current->getUID(),
			'isBestatterAdmin' => $this->isBestatterAdmin(),
			'members' => $members,
		];
	}

	public function isBestatterAdmin(): bool {
		$user = $this->currentUser();
		return $this->hasBestatterAdminRole($user->getUID());
	}

	public function hasBestatterAdminRole(string $uid): bool {
		if ($this->groups->isAdmin($uid)) return true;
		foreach ($this->installationConfig->adminGroups() as $groupName) {
			if ($this->groups->isInGroup($uid, $groupName)) return true;
		}
		return false;
	}

	public function isBestatterMember(): bool {
		return $this->hasBestatterRole($this->currentUser()->getUID());
	}

	public function hasBestatterRole(string $uid): bool {
		$uid = trim($uid);
		if ($uid === '') return false;
		if ($this->groups->isAdmin($uid)) return true;
		foreach (array_unique([$this->installationConfig->memberGroup(), ...$this->installationConfig->adminGroups()]) as $groupName) {
			if ($this->groups->isInGroup($uid, $groupName)) return true;
		}
		return false;
	}

	public function requireBestatterMember(): void {
		if (!$this->isBestatterMember()) {
			throw new BestatterAccessDeniedException('Zugriff nur für Mitglieder der konfigurierten Bestatter-Gruppe "' . $this->installationConfig->memberGroup() . '".');
		}
	}

	public function requireBestatterAdmin(): void {
		if (!$this->isBestatterAdmin()) throw new BestatterAccessDeniedException('Diese Funktion ist Bestatter-Administratoren vorbehalten.');
	}

	/** Case assignment is stored as a Nextcloud UID, never as a contact display name. */
	public function canEditCase(array $case): bool {
		if ($this->isBestatterAdmin()) return true;
		$uid = $this->currentUser()->getUID();
		return $this->hasBestatterRole($uid)
			&& $uid === trim((string)($case['responsibleEmployee'] ?? ''));
	}

	public function requireCaseEditor(array $case): void {
		if (!$this->canEditCase($case)) {
			throw new BestatterAccessDeniedException('Diese Aktion ist nur für die zuständige Person oder Bestatter-Administratoren zulässig.');
		}
	}

	public function assignee(string $uid = ''): array {
		$uid = trim($uid);
		if ($uid !== '') {
			$current = $this->currentUser();
			if ($uid === $current->getUID()) {
				return $this->map($current);
			}
			$member = $this->member($uid);
			if ($member !== null) {
				return $member;
			}
			throw new \InvalidArgumentException('Die ausgewählte zuständige Person gehört nicht zur Nextcloud-Gruppe "' . $this->installationConfig->memberGroup() . '".');
		}
		$overview = $this->overview();
		foreach ($overview['members'] as $member) {
			if ($member['uid'] === $overview['currentUid']) {
				return $member;
			}
		}
		throw new \RuntimeException('Der aktuelle Nextcloud-Benutzer konnte nicht als zuständige Person bestimmt werden.');
	}

	public function member(string $uid): ?array {
		$group = $this->groups->get($this->installationConfig->memberGroup());
		if ($group === null) {
			return null;
		}
		foreach ($group->getUsers() as $user) {
			if ($user->getUID() === $uid) {
				return $this->map($user);
			}
		}
		return null;
	}

	private function currentUser(): IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new BestatterAccessDeniedException('Für den Zugriff auf die Bestatter-Anwendung ist eine Anmeldung erforderlich.');
		}
		return $user;
	}

	private function map(IUser $user): array {
		return [
			'uid' => $user->getUID(),
			'displayName' => $user->getDisplayName() ?: $user->getUID(),
			'email' => $user->getEMailAddress() ?: '',
		];
	}
}
