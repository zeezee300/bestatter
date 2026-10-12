<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * 0.70.0 (OP-040) – Zentrale Auflösung der Fallablage.
 *
 * USER    = bisheriges Verhalten: Ablage im Nextcloud-Ordner der angemeldeten Person.
 * CENTRAL = gemeinsame Fallablage im Nextcloud-Konto „storage_account“, unabhängig davon, wer angemeldet
 *           ist. Liegt der Arbeitsordner in einem Teamordner (App „Group folders“/„Team folders“), sehen alle
 *           Mitglieder denselben Ordner; liegt er im eigenen Ordner des Kontos, gibt die App ihn für die
 *           Bestatter-Gruppen frei. Die App meldet sich dafür nie an und speichert keine Zugangsdaten.
 */
class CaseStorageService {
	public function __construct(
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private InstallationConfigService $installation,
		private IShareManager $shareManager,
		private LoggerInterface $logger,
	) {}

	public function isCentral(): bool { return $this->installation->storageMode() === 'CENTRAL' && $this->installation->storageAccount() !== ''; }

	/**
	 * Basisordner, relativ zu dem alle konfigurierten Pfade (Arbeitsordner, Fälle, Vorlagen …) gelten.
	 * $uid wird nur im Modus USER ausgewertet (z. B. ausführende Person eines Ablaufschritts).
	 */
	public function baseFolder(?string $uid = null): Folder {
		if ($this->isCentral()) return $this->accountFolder($this->installation->storageAccount());
		$uid = $uid !== null && $uid !== '' ? $uid : $this->userSession->getUser()?->getUID();
		if ($uid === null || $uid === '') throw new \RuntimeException('Kein angemeldeter Nextcloud-Benutzer.');
		return $this->rootFolder->getUserFolder($uid);
	}

	/** 0.71.0: Eigener Nextcloud-Ordner einer Person – unabhängig von der Betriebsart (für private Zwischenablagen). */
	public function personalFolder(?string $uid = null): Folder {
		$uid = $uid !== null && $uid !== '' ? $uid : $this->userSession->getUser()?->getUID();
		if ($uid === null || $uid === '') throw new \RuntimeException('Kein angemeldeter Nextcloud-Benutzer.');
		return $this->rootFolder->getUserFolder($uid);
	}

	/** Wie baseFolder(), liefert aber null statt einer Ausnahme (z. B. ohne Sitzung im Hintergrundjob). */
	public function baseFolderOrNull(?string $uid = null): ?Folder {
		try { return $this->baseFolder($uid); } catch (\Throwable) { return null; }
	}

	/** Relativer Pfad eines Knotens zum Basisordner (für Anzeige und Protokoll). */
	public function relativePath(Node $node, ?Folder $base = null): string {
		$base ??= $this->baseFolderOrNull();
		$path = $node->getPath();
		if ($base !== null && str_starts_with($path, rtrim($base->getPath(), '/') . '/')) return substr($path, strlen(rtrim($base->getPath(), '/')) + 1);
		return (string)preg_replace('#^/[^/]+/files/#', '', $path);
	}

	/**
	 * Alle Orte, an denen Bestatter-Dateien liegen können: die zentrale Ablage und – für Aufbewahrung,
	 * Löschung und Bestandsaufnahme – die bisherigen persönlichen Ordner der Bestatter-Gruppenmitglieder.
	 * @return list<array{uid:string, central:bool, folder:Folder}>
	 */
	public function allBases(bool $includeLegacy = true): array {
		$result = [];
		if ($this->isCentral()) {
			try { $result[] = ['uid' => $this->installation->storageAccount(), 'central' => true, 'folder' => $this->accountFolder($this->installation->storageAccount())]; } catch (\Throwable $error) { $this->logger->warning('Zentrale Ablage nicht erreichbar.', ['reason' => $error->getMessage()]); }
			if (!$includeLegacy) return $result;
		}
		foreach ($this->bestatterUids() as $uid) {
			if ($this->isCentral() && $uid === $this->installation->storageAccount()) continue;
			try { $result[] = ['uid' => $uid, 'central' => false, 'folder' => $this->rootFolder->getUserFolder($uid)]; } catch (\Throwable) {}
		}
		return $result;
	}

	/**
	 * Vorhandene Ordner unter einem relativen Pfad in allen Basisordnern, ohne Doppelungen (ein freigegebener
	 * oder Teamordner erscheint bei mehreren Personen, zählt aber nur einmal).
	 * @return list<array{uid:string, central:bool, label:string, folder:Folder}>
	 */
	public function existingFolders(string $relativePath, bool $includeLegacy = true): array {
		$result = []; $seen = [];
		foreach ($this->allBases($includeLegacy) as $base) {
			$folder = $this->existingPath($base['folder'], $relativePath);
			if ($folder === null) continue;
			$id = (int)$folder->getId();
			if (isset($seen[$id])) continue;
			$seen[$id] = true;
			$result[] = ['uid' => $base['uid'], 'central' => $base['central'], 'label' => ($base['central'] ? 'zentral:' : '') . $base['uid'] . '/' . trim($relativePath, '/'), 'folder' => $folder];
		}
		return $result;
	}

	/** @return list<string> Mitglieder der Bestatter-Gruppen (sortiert). */
	public function bestatterUids(): array {
		$uids = [];
		foreach (array_unique([$this->installation->memberGroup(), ...$this->installation->adminGroups()]) as $name) {
			$group = $this->groupManager->get($name);
			if ($group !== null) foreach ($group->getUsers() as $user) $uids[$user->getUID()] = $user->getUID();
		}
		$uids = array_values($uids);
		sort($uids, SORT_NATURAL | SORT_FLAG_CASE);
		return $uids;
	}

	/** Zustand der Ablage für die Administration (ohne Änderungen). */
	public function status(): array {
		$account = $this->installation->storageAccount();
		$status = ['mode' => $this->installation->storageMode(), 'account' => $account, 'central' => $this->isCentral(), 'accountValid' => false, 'rootPath' => $this->installation->storageRoot(), 'location' => '', 'shares' => [], 'missingShares' => [], 'warnings' => []];
		if ($account !== '') {
			$user = $this->userManager->get($account);
			if ($user === null) $status['warnings'][] = 'Das Ablagekonto „' . $account . '“ existiert nicht.';
			elseif (!$user->isEnabled()) $status['warnings'][] = 'Das Ablagekonto „' . $account . '“ ist deaktiviert.';
			else {
				$status['accountValid'] = true;
				try {
					$root = $this->existingPath($this->rootFolder->getUserFolder($account), $this->installation->storageRoot());
					if ($root === null) $status['location'] = 'NOT_CREATED';
					else {
						$status['location'] = $this->locationType($root, $account);
						$missing = $this->missingRights($root);
						if ($missing !== []) $status['warnings'][] = 'Dem Ablagekonto fehlen im Arbeitsordner Rechte: ' . implode(', ', $missing) . '. Beim Teamordner bitte der Gruppe des Ablagekontos Lesen, Schreiben und Löschen geben.';
						if ($status['location'] === 'OWN') {
							$shared = $this->groupShares($root, $account);
							$status['shares'] = array_keys($shared);
							$status['missingShares'] = array_values(array_diff($this->shareGroups(), array_keys($shared)));
						}
					}
				} catch (\Throwable $error) { $status['warnings'][] = 'Arbeitsordner des Ablagekontos nicht lesbar: ' . $error->getMessage(); }
			}
		}
		$teamfolder = null;
		try { $teamfolder = $this->sharedTeamfolder(); } catch (\Throwable) {}
		$status['existingTeamfolder'] = $teamfolder;
		if (!$this->isCentral() && $teamfolder !== null) $status['warnings'][] = 'Die Bestatter-Ablage liegt bereits im Teamordner „' . $teamfolder['name'] . '“ (' . implode(', ', $teamfolder['members']) . '). Empfehlung: das Ablagekonto diesem Teamordner zuordnen (eigene Gruppe mit Lese-, Schreib- und Löschrecht) statt die Dateien zu kopieren – siehe docs/FALLABLAGE.md, „Vorhandener Teamordner“.';
		elseif (!$this->isCentral()) $status['warnings'][] = 'Die Fallablage ist noch benutzerbezogen: Jede Person arbeitet in ihrem eigenen Nextcloud-Ordner. Für die Arbeit mit mehreren Konten die zentrale Ablage einrichten.';
		return $status;
	}

	/**
	 * Prüft ein Ablagekonto und legt den Arbeitsordner an. Freigaben an die Bestatter-Gruppen werden
	 * ergänzt, wenn der Ordner im eigenen Speicher des Kontos liegt (bei Teamordnern nicht nötig).
	 */
	public function prepareAccount(string $account, bool $createShares = true): array {
		$account = trim($account);
		if ($account === '' || !preg_match('/^[A-Za-z0-9 _.@\-\']{1,64}$/', $account)) throw new \InvalidArgumentException('Bitte die Benutzerkennung des Ablagekontos angeben.');
		$user = $this->userManager->get($account);
		if ($user === null) throw new \InvalidArgumentException('Das Nextcloud-Konto „' . $account . '“ existiert nicht. Bitte zuerst anlegen (z. B. „bestatter-ablage“).');
		if (!$user->isEnabled()) throw new \InvalidArgumentException('Das Nextcloud-Konto „' . $account . '“ ist deaktiviert.');
		$root = $this->installation->ensurePath($this->rootFolder->getUserFolder($account), $this->installation->storageRoot());
		$missing = $this->missingRights($root);
		if ($missing !== []) throw new \InvalidArgumentException('Dem Ablagekonto fehlen im Arbeitsordner Rechte: ' . implode(', ', $missing) . '. Beim Teamordner bitte der Gruppe des Ablagekontos Lesen, Schreiben und Löschen geben.');
		$location = $this->locationType($root, $account);
		$created = [];
		// 0.70.2: Gruppenfreigaben erst beim Aktivieren anlegen. Eine vorher angelegte Freigabe „Bestatter“ kann bei den
		// Mitgliedern den gleichnamigen Teamordner oder eigenen Ordner verdecken und so die Bestandsaufnahme verfälschen.
		if ($location === 'OWN' && $createShares) $created = $this->ensureShares($root, $account);
		return ['account' => $account, 'location' => $location, 'createdShares' => $created, 'rootPath' => $this->installation->storageRoot()];
	}

	/** Setzt die Betriebsart. Der Wechsel auf CENTRAL mit vorhandenen persönlichen Fallordnern läuft über die Migration. */
	public function activate(string $mode, string $account, bool $fromMigration = false): array {
		$prepared = [];
		if ($mode === 'CENTRAL') {
			$prepared = $this->prepareAccount($account);
		} elseif ($this->isCentral() && !$fromMigration) {
			throw new \InvalidArgumentException('Die Rückkehr zur benutzerbezogenen Ablage ist nicht vorgesehen; die Fallakten liegen bereits zentral.');
		}
		$this->installation->setStorage($mode, $account);
		return $prepared;
	}

	private function accountFolder(string $account): Folder {
		$user = $this->userManager->get($account);
		if ($user === null || !$user->isEnabled()) throw new \RuntimeException('Das Ablagekonto „' . $account . '“ ist nicht verfügbar. Bitte in der Administration unter „Ablage“ prüfen.');
		return $this->rootFolder->getUserFolder($account);
	}

	/** Öffentliche Variante von locationType() für Migration und Prüfungen. */
	public function locationOf(Folder $folder, string $uid): string { return $this->locationType($folder, $uid); }

	/**
	 * 0.70.2: Entfernt die von der App angelegten Gruppenfreigaben (Bestatter-Gruppen) eines Ordners des Kontos.
	 * @return list<string> Gruppen, deren Freigabe entfernt wurde
	 */
	public function removeGroupShares(Folder $folder, string $account): array {
		$removed = [];
		$groups = array_flip($this->shareGroups());
		foreach ($this->groupShares($folder, $account) as $group => $share) {
			if (!isset($groups[$group])) continue;
			try { $this->shareManager->deleteShare($share); $removed[] = $group; } catch (\Throwable $error) { $this->logger->warning('Freigabe konnte nicht entfernt werden.', ['group' => $group, 'reason' => $error->getMessage()]); }
		}
		return $removed;
	}

	/**
	 * 0.70.2: Liegt die bisherige benutzerbezogene Ablage der Mitglieder bereits in einem Teamordner?
	 * @return array{name:string, members:list<string>}|null
	 */
	public function sharedTeamfolder(): ?array {
		$found = null; $members = [];
		foreach ($this->bestatterUids() as $uid) {
			if ($this->isCentral() && $uid === $this->installation->storageAccount()) continue;
			try { $root = $this->existingPath($this->rootFolder->getUserFolder($uid), $this->installation->storageRoot()); } catch (\Throwable) { continue; }
			if ($root === null || $this->locationType($root, $uid) !== 'TEAMFOLDER') continue;
			$found ??= ['id' => (int)$root->getId(), 'name' => $root->getName()];
			if ((int)$root->getId() === $found['id']) $members[] = $uid;
		}
		return $found === null ? null : ['name' => $found['name'], 'members' => $members];
	}

	/** OWN = im eigenen Speicher des Kontos, TEAMFOLDER = Teamordner/Group folder, SHARED = für das Konto freigegeben. */
	private function locationType(Folder $root, string $account): string {
		try {
			$mount = $root->getMountPoint();
			$type = method_exists($mount, 'getMountType') ? (string)$mount->getMountType() : '';
			if ($type === 'group') return 'TEAMFOLDER';
			if ($type === 'shared') return 'SHARED';
			$owner = $root->getOwner()?->getUID();
			if ($owner !== null && $owner !== $account) return 'SHARED';
		} catch (\Throwable) {}
		return 'OWN';
	}

	/** @return list<string> fehlende Rechte (die App ersetzt Ausgaben und räumt auf, braucht also auch Löschrecht) */
	private function missingRights(Folder $root): array {
		$permissions = $root->getPermissions(); $missing = [];
		foreach ([Constants::PERMISSION_READ => 'Lesen', Constants::PERMISSION_CREATE => 'Anlegen', Constants::PERMISSION_UPDATE => 'Ändern', Constants::PERMISSION_DELETE => 'Löschen'] as $bit => $label) if (($permissions & $bit) === 0) $missing[] = $label;
		return $missing;
	}

	/** @return list<string> */
	private function shareGroups(): array {
		$groups = [];
		foreach (array_unique([$this->installation->memberGroup(), ...$this->installation->adminGroups()]) as $name) if ($this->groupManager->groupExists($name)) $groups[] = $name;
		return $groups;
	}

	/** @return array<string, IShare> */
	private function groupShares(Folder $root, string $account): array {
		$result = [];
		foreach ($this->shareManager->getSharesBy($account, IShare::TYPE_GROUP, $root, false, -1) as $share) $result[(string)$share->getSharedWith()] = $share;
		return $result;
	}

	/** @return list<string> neu freigegebene Gruppen */
	private function ensureShares(Folder $root, string $account): array {
		$existing = $this->groupShares($root, $account);
		$created = [];
		foreach ($this->shareGroups() as $group) {
			if (isset($existing[$group])) continue;
			$share = $this->shareManager->newShare();
			$share->setNode($root)->setShareType(IShare::TYPE_GROUP)->setSharedWith($group)->setSharedBy($account)->setShareOwner($account)
				->setPermissions(Constants::PERMISSION_ALL & ~Constants::PERMISSION_SHARE);
			$this->shareManager->createShare($share);
			$created[] = $group;
		}
		return $created;
	}

	private function existingPath(Folder $base, string $relativePath): ?Folder {
		$folder = $base;
		foreach (explode('/', trim($relativePath, '/')) as $name) {
			if ($name === '') continue;
			if (!$folder->nodeExists($name)) return null;
			$node = $folder->get($name);
			if (!$node instanceof Folder) return null;
			$folder = $node;
		}
		return $folder;
	}
}
