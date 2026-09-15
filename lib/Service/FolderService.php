<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;

class FolderService {
	public function __construct(
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private InstallationConfigService $installationConfig,
	) {}

	public function caseRoot(string $caseNumber): Folder {
		$folder = $this->installationConfig->ensurePath($this->userFolder(), $this->installationConfig->casesPath());
		foreach ([$this->caseYear($caseNumber), $caseNumber] as $name) {
			$folder = $folder->nodeExists($name) ? $folder->get($name) : $folder->newFolder($name);
		}
		if (!$folder instanceof Folder) throw new \RuntimeException('Der Fallordner konnte nicht geöffnet werden.');
		return $folder;
	}

	public function caseSubfolder(string $caseNumber, string $subfolder): Folder {
		if (!in_array($subfolder, $this->installationConfig->caseSubfolders(), true)) throw new \InvalidArgumentException('Der gewählte Ablageordner ist nicht zulässig.');
		$folder = $this->caseRoot($caseNumber);
		$node = $folder->nodeExists($subfolder) ? $folder->get($subfolder) : $folder->newFolder($subfolder);
		if (!$node instanceof Folder) throw new \RuntimeException('Der Ablageordner konnte nicht geöffnet werden.');
		return $node;
	}

	public function createCaseFolder(string $caseNumber): array {
		$folder = $this->caseRoot($caseNumber);
		foreach ($this->installationConfig->caseSubfolders() as $name) if (!$folder->nodeExists($name)) $folder->newFolder($name);
		return ['status' => 'OK', 'path' => $this->caseRelativePath($caseNumber), 'folders' => $this->installationConfig->caseSubfolders()];
	}

	public function allowedSubfolders(): array { return $this->installationConfig->caseSubfolders(); }

	public function caseRelativePath(string $caseNumber): string {
		return $this->installationConfig->casesPath() . '/' . $this->caseYear($caseNumber) . '/' . $caseNumber;
	}

	public function templatesFolder(): Folder {
		return $this->installationConfig->ensurePath($this->userFolder(), $this->installationConfig->templatesPath());
	}

	/** Nicht fallbezogener, geschützter Zwischenspeicher für noch ungeprüfte Erfassungsbögen. */
	public function captureInbox(): Folder {
		return $this->installationConfig->ensurePath($this->userFolder(), $this->installationConfig->storageRoot() . '/.Erfassungseingang');
	}

	public function configuredPaths(): array { return $this->installationConfig->settings()['paths']; }
	public function defaultUploadFolder(): string { return $this->installationConfig->defaultUploadFolder(); }

	private function userFolder(): Folder {
		$user = $this->userSession->getUser();
		if ($user === null) throw new \RuntimeException('Kein angemeldeter Nextcloud-Benutzer.');
		return $this->rootFolder->getUserFolder($user->getUID());
	}

	private function caseYear(string $caseNumber): string {
		$year = substr(trim($caseNumber), 0, 4);
		if (!preg_match('/^20\d{2}$/', $year)) throw new \InvalidArgumentException('Die Fallnummer enthält kein gültiges Jahr.');
		return $year;
	}
}
