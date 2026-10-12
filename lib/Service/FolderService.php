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
		private CaseStorageService $storage,
	) {}

	public function caseRoot(string $caseNumber): Folder {
		$folder = $this->installationConfig->ensurePath($this->userFolder(), $this->installationConfig->casesPath());
		foreach ([$this->caseYear($caseNumber), $caseNumber] as $name) {
			$node = $folder->nodeExists($name) ? $folder->get($name) : $folder->newFolder($name);
			if (!$node instanceof Folder) throw new \RuntimeException('Der Fallordner konnte nicht geöffnet werden („' . $name . '“ ist eine Datei, kein Ordner).');
			$folder = $node;
		}
		return $folder;
	}

	/**
	 * 0.72.0: Verweise auf Unterordner (Vorlagen, gespeicherte Angaben, Standardnamen) werden über den Ordnerplan
	 * aufgelöst – auch nach einer Umbenennung. Während einer Umstellung werden keine Unterordner angelegt.
	 */
	public function caseSubfolder(string $caseNumber, string $subfolder): Folder {
		$resolved = $this->installationConfig->resolveSubfolder($subfolder);
		if ($resolved === null) throw new \InvalidArgumentException('Der gewählte Ablageordner ist nicht zulässig.');
		$subfolder = $resolved;
		$folder = $this->caseRoot($caseNumber);
		if (!$folder->nodeExists($subfolder) && $this->installationConfig->folderPlanLocked()) throw new \RuntimeException('Der Ordnerplan der Fallakte wird gerade umgestellt. Bitte in einigen Minuten erneut versuchen.');
		$node = $folder->nodeExists($subfolder) ? $folder->get($subfolder) : $folder->newFolder($subfolder);
		if (!$node instanceof Folder) throw new \RuntimeException('Der Ablageordner konnte nicht geöffnet werden.');
		return $node;
	}

	public function createCaseFolder(string $caseNumber): array {
		$folder = $this->caseRoot($caseNumber);
		if (!$this->installationConfig->folderPlanLocked()) foreach ($this->installationConfig->caseSubfolders() as $name) if (!$folder->nodeExists($name)) $folder->newFolder($name);
		return ['status' => 'OK', 'path' => $this->caseRelativePath($caseNumber), 'folders' => $this->installationConfig->caseSubfolders()];
	}

	public function allowedSubfolders(): array { return $this->installationConfig->caseSubfolders(); }
	public function resolveSubfolder(string $reference): ?string { return $this->installationConfig->resolveSubfolder($reference); }

	public function caseRelativePath(string $caseNumber): string {
		return $this->installationConfig->casesPath() . '/' . $this->caseYear($caseNumber) . '/' . $caseNumber;
	}

	public function templatesFolder(): Folder {
		return $this->installationConfig->ensurePath($this->userFolder(), $this->installationConfig->templatesPath());
	}

	/** Nicht fallbezogener, geschützter Zwischenspeicher für noch ungeprüfte Erfassungsbögen. */
	/**
	 * 0.71.0: Erfassungsbögen liegen bis zur Zuordnung im persönlichen, nicht geteilten Bereich der hochladenden
	 * Person (vorher im Arbeitsordner – bei zentraler Ablage oder Teamordner für alle Mitglieder sichtbar).
	 */
	public function captureInbox(): Folder {
		return $this->installationConfig->ensurePath($this->storage->personalFolder(), $this->installationConfig->privateCaptureInboxPath());
	}

	public function configuredPaths(): array { return $this->installationConfig->settings()['paths']; }
	public function defaultUploadFolder(): string { return $this->installationConfig->defaultUploadFolder(); }

	/** 0.70.0 (OP-040): zentrale Ablage oder – im Modus USER – der Ordner der angemeldeten Person. */
	private function userFolder(): Folder {
		return $this->storage->baseFolder();
	}

	/** Basisordner der Fallablage (für Pfadprüfungen und relative Pfade). */
	public function baseFolder(): Folder { return $this->storage->baseFolder(); }
	/** Im Modus USER der Ordner der angegebenen Person (z. B. Ausführende eines Ablaufschritts), sonst die zentrale Ablage. */
	public function baseFolderFor(?string $uid): Folder { return $this->storage->baseFolder($uid); }

	private function caseYear(string $caseNumber): string {
		$year = substr(trim($caseNumber), 0, 4);
		if (!preg_match('/^20\d{2}$/', $year)) throw new \InvalidArgumentException('Die Fallnummer enthält kein gültiges Jahr.');
		return $year;
	}
}
