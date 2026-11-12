<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * 0.91.0: Fallnotizen – kurze interne Notizen zu einem Fall mit Verfasser, Kategorie, Anheften und Erwähnungen.
 * Gespeichert als record_type „note“ in bestatter_records (gemeinsame Fallakte: alle Bestatter-Mitglieder sehen,
 * bearbeiten und löschen sie). Fall-Verlauf, Fallexport, Aufbewahrung und Löschen laufen über die vorhandenen Wege
 * für bestatter_records. Optional: Übernahme aus der Nextcloud-App „Notizen“ (Kategorie „Bestatter“) des angemeldeten
 * Benutzers.
 */
class CaseNoteService {
	/** @var array<string, string> */
	private array $nameCache = [];

	public function __construct(
		private RecordService $records,
		private CaseService $cases,
		private CustomizingService $customizing,
		private TeamService $team,
		private IUserManager $userManager,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private IConfig $config,
		private IAppManager $appManager,
	) {
	}

	/** Notizen eines Falls, angeheftete zuerst, sonst neueste zuerst; dazu Kategorien und Stand der Notes-App. */
	public function list(int $caseId): array {
		$case = $this->cases->getCase($caseId);
		$categories = $this->categories();
		$labels = array_column($categories, 'label', 'value');
		$items = array_map(fn (array $record): array => $this->present($record, $labels), $this->records->list(CaseNoteRules::RECORD_TYPE, $caseId));
		usort($items, static fn (array $left, array $right): int => ((int)$right['pinned'] <=> (int)$left['pinned']) ?: strcmp((string)$right['createdAt'], (string)$left['createdAt']) ?: ((int)$right['id'] <=> (int)$left['id']));
		return [
			'caseId' => $caseId,
			'caseNumber' => (string)($case['caseNumber'] ?? ''),
			'items' => $items,
			'categories' => $categories,
			'maxLength' => CaseNoteRules::MAX_TEXT,
			'notesApp' => $this->notesAppStatus(),
		];
	}

	/** @param list<string> $mentions */
	public function create(int $caseId, string $text, string $category = '', bool $pinned = false, array $mentions = []): array {
		$this->cases->getCase($caseId);
		$payload = $this->payload(null, $text, $category, $pinned, $mentions);
		$record = $this->records->create(CaseNoteRules::RECORD_TYPE, CaseNoteRules::title($payload['text']), date('c'), CaseNoteRules::STATUS, $this->json($payload), $caseId);
		return $this->present($record, array_column($this->categories(), 'label', 'value'));
	}

	/**
	 * 0.91.1: Notizen, die die App selbst anlegt – die Gesprächsnotiz der Schnellerfassung (Kategorie „Gesprächsnotiz“)
	 * und Vermerke bei Vorgangsübergängen. Verfasser ist die angemeldete Person; lange Texte werden auf mehrere Notizen
	 * verteilt.
	 * @return list<array<string, mixed>>
	 */
	public function createSystemNotes(int $caseId, string $text, string $category, string $source): array {
		$labels = array_column($this->categories(), 'label', 'value');
		$created = [];
		foreach (CaseNoteRules::systemPayloads($text, $category, $source) as $payload) {
			$record = $this->records->create(CaseNoteRules::RECORD_TYPE, CaseNoteRules::title((string)$payload['text']), date('c'), CaseNoteRules::STATUS, $this->json($payload), $caseId);
			$created[] = $this->present($record, $labels);
		}
		return $created;
	}

	/**
	 * Ändert Text, Kategorie, Anheften und Erwähnungen. Fehlende Angaben (null) bleiben unverändert;
	 * „bearbeitet“ wird nur bei geändertem Text oder geänderter Kategorie vermerkt.
	 * @param list<string>|null $mentions
	 */
	public function update(int $caseId, int $id, ?string $text = null, ?string $category = null, ?bool $pinned = null, ?array $mentions = null): array {
		$existing = $this->note($caseId, $id);
		$data = $existing['data'] ?? [];
		$payload = $this->payload(
			$data,
			$text ?? (string)($data['text'] ?? ''),
			$category ?? (string)($data['category'] ?? ''),
			$pinned ?? !empty($data['pinned']),
			$mentions ?? (array)($data['mentions'] ?? []),
		);
		$record = $this->records->update($id, CaseNoteRules::title($payload['text']), (string)($existing['date'] ?? date('c')), CaseNoteRules::STATUS, $this->json($payload), $caseId);
		return $this->present($record, array_column($this->categories(), 'label', 'value'));
	}

	public function delete(int $caseId, int $id): void {
		$this->note($caseId, $id);
		$this->records->delete($id);
	}

	/** Markdown mit allen Notizen eines Falls (für den Download und den Fallexport). */
	public function markdown(int $caseId): array {
		$case = $this->cases->getCase($caseId);
		$list = $this->list($caseId);
		$name = trim((string)($case['lastName'] ?? '') . ', ' . (string)($case['firstName'] ?? ''), ' ,');
		$uid = $this->currentUid();
		return [
			'fileName' => CaseNoteRules::markdownFileName((string)($case['caseNumber'] ?? '')),
			'content' => CaseNoteRules::markdown(['caseNumber' => (string)($case['caseNumber'] ?? ''), 'name' => $name], $list['items'], date('c'), $uid !== '' ? $this->displayName($uid) : ''),
		];
	}

	/**
	 * Notizen des angemeldeten Benutzers in der Kategorie „Bestatter“ der Nextcloud-App „Notizen“, die übernommen werden
	 * können; Notizen mit der Fallnummer im Namen oder Text stehen oben und sind vorgewählt.
	 */
	public function notesAppCandidates(int $caseId): array {
		$case = $this->cases->getCase($caseId);
		$caseNumber = (string)($case['caseNumber'] ?? '');
		$status = $this->notesAppStatus();
		$folder = $this->notesCategoryFolder();
		$items = [];
		if ($folder !== null) {
			foreach ($folder->getDirectoryListing() as $node) {
				if (!$node instanceof File || !$this->isNoteFile($node)) {
					continue;
				}
				$content = $node->getSize() <= CaseNoteRules::NOTES_APP_MAX_BYTES ? (string)$node->getContent() : '';
				$text = CaseNoteRules::textFromNotesFile($node->getName(), $content);
				$items[] = [
					'fileId' => $node->getId(),
					'name' => $node->getName(),
					'title' => CaseNoteRules::title($text),
					'preview' => mb_substr($text, 0, 280),
					'length' => mb_strlen($text),
					'tooLong' => mb_strlen($text) > CaseNoteRules::MAX_TEXT || $node->getSize() > CaseNoteRules::NOTES_APP_MAX_BYTES,
					'modifiedAt' => date('c', $node->getMTime()),
					'matchesCase' => CaseNoteRules::mentionsCaseNumber($caseNumber, $node->getName(), $text),
				];
			}
		}
		usort($items, static fn (array $left, array $right): int => ((int)$right['matchesCase'] <=> (int)$left['matchesCase']) ?: strcmp((string)$right['modifiedAt'], (string)$left['modifiedAt']));
		return $status + [
			'caseNumber' => $caseNumber,
			'folderFound' => $folder !== null,
			'items' => $items,
		];
	}

	/**
	 * Übernimmt Notizen aus der Nextcloud-App „Notizen“ in den Fall und verschiebt die Dateien danach in den Unterordner
	 * „Übernommen“ der Kategorie (nichts wird gelöscht).
	 * @param list<int> $fileIds
	 */
	public function importFromNotesApp(int $caseId, array $fileIds, string $category = ''): array {
		$this->cases->getCase($caseId);
		$folder = $this->notesCategoryFolder();
		if ($folder === null) {
			throw new \InvalidArgumentException('In Ihren Notizen gibt es keine Kategorie „' . CaseNoteRules::NOTES_APP_CATEGORY . '“.');
		}
		$fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds), static fn (int $id): bool => $id > 0)));
		if ($fileIds === []) {
			throw new \InvalidArgumentException('Bitte mindestens eine Notiz auswählen.');
		}
		$created = [];
		$skipped = [];
		$done = null;
		foreach ($fileIds as $fileId) {
			$node = $folder->getFirstNodeById($fileId);
			if (!$node instanceof File || !$this->isNoteFile($node) || $node->getParent()->getId() !== $folder->getId()) {
				$skipped[] = ['fileId' => $fileId, 'reason' => 'Die Notiz wurde nicht gefunden.'];
				continue;
			}
			if ($node->getSize() > CaseNoteRules::NOTES_APP_MAX_BYTES) {
				$skipped[] = ['fileId' => $fileId, 'name' => $node->getName(), 'reason' => 'Die Notiz ist zu lang.'];
				continue;
			}
			try {
				$text = CaseNoteRules::textFromNotesFile($node->getName(), (string)$node->getContent());
				$payload = $this->payload(null, $text, $category, false, []);
				$payload['source'] = CaseNoteRules::SOURCE_NEXTCLOUD_NOTES;
				$payload['sourceName'] = $node->getName();
				$record = $this->records->create(CaseNoteRules::RECORD_TYPE, CaseNoteRules::title($payload['text']), date('c'), CaseNoteRules::STATUS, $this->json($payload), $caseId);
				$created[] = $record;
			} catch (\InvalidArgumentException $error) {
				$skipped[] = ['fileId' => $fileId, 'name' => $node->getName(), 'reason' => $error->getMessage()];
				continue;
			}
			try {
				$done ??= $this->doneFolder($folder);
				$node->move($done->getPath() . '/' . $this->uniqueName($done, $node->getName()));
			} catch (\Throwable) {
				// Die Notiz ist übernommen; bleibt die Datei liegen, erscheint sie beim nächsten Mal wieder in der Auswahl.
			}
		}
		$labels = array_column($this->categories(), 'label', 'value');
		return ['created' => array_map(fn (array $record): array => $this->present($record, $labels), $created), 'skipped' => $skipped];
	}

	/** Stand der Nextcloud-App „Notizen“ für den angemeldeten Benutzer (für Oberfläche und Systemprüfung). */
	public function notesAppStatus(): array {
		$uid = $this->currentUid();
		$enabled = $uid !== '' && $this->appManager->isEnabledForUser('notes');
		return ['notesAppEnabled' => $enabled, 'category' => CaseNoteRules::NOTES_APP_CATEGORY, 'doneFolder' => CaseNoteRules::NOTES_APP_DONE_FOLDER];
	}

	/** Werte der Werteliste „Kategorie der Notiz“ (Stammdaten → Wertelisten). */
	public function categories(): array {
		return array_values(array_map(static fn (array $item): array => ['value' => (string)$item['value'], 'label' => (string)$item['label']], $this->customizing->valuesForKey(CaseNoteRules::CATEGORY_LIST)));
	}

	private function note(int $caseId, int $id): array {
		$record = $this->records->get($id);
		if (($record['type'] ?? '') !== CaseNoteRules::RECORD_TYPE || (int)($record['caseId'] ?? 0) !== $caseId) {
			throw new \InvalidArgumentException('Die Notiz wurde nicht gefunden.');
		}
		return $record;
	}

	/**
	 * @param array<string, mixed>|null $previous bisherige Daten (null bei einer neuen Notiz)
	 * @param array<int|string, mixed> $mentions
	 */
	private function payload(?array $previous, string $text, string $category, bool $pinned, array $mentions): array {
		$text = CaseNoteRules::normalizeText($text);
		$previousCategory = (string)($previous['category'] ?? '');
		$category = trim($category);
		// Eine Kategorie, die im Customizing inzwischen entfernt wurde, bleibt an einer bestehenden Notiz erhalten.
		if ($previous === null || $category !== $previousCategory) {
			$category = CaseNoteRules::normalizeCategory($category, array_column($this->categories(), 'value'));
		}
		$mentions = CaseNoteRules::normalizeMentions($mentions, fn (string $uid): bool => $this->team->hasBestatterRole($uid) && $this->userManager->get($uid) !== null);
		$payload = [
			'text' => $text,
			'category' => $category,
			'pinned' => $pinned,
			'mentions' => $mentions,
			'source' => (string)($previous['source'] ?? CaseNoteRules::SOURCE_APP),
		];
		foreach (['sourceName', 'editedAt', 'editedBy'] as $key) {
			if (isset($previous[$key])) {
				$payload[$key] = $previous[$key];
			}
		}
		if ($previous !== null && ((string)($previous['text'] ?? '') !== $text || $previousCategory !== $category)) {
			$payload['editedAt'] = date('c');
			$payload['editedBy'] = $this->currentUid();
		}
		return $payload;
	}

	private function present(array $record, array $categoryLabels): array {
		$data = is_array($record['data'] ?? null) ? $record['data'] : [];
		$category = (string)($data['category'] ?? '');
		$createdBy = (string)($record['createdBy'] ?? '');
		$editedBy = (string)($data['editedBy'] ?? '');
		$mentions = array_values(array_filter(array_map('strval', (array)($data['mentions'] ?? [])), static fn (string $uid): bool => $uid !== ''));
		return [
			'id' => (int)$record['id'],
			'caseId' => (int)($record['caseId'] ?? 0),
			'title' => (string)($record['title'] ?? ''),
			'text' => (string)($data['text'] ?? ''),
			'category' => $category,
			'categoryLabel' => $category !== '' ? (string)($categoryLabels[$category] ?? $category) : '',
			'pinned' => !empty($data['pinned']),
			'mentions' => array_map(fn (string $uid): array => ['uid' => $uid, 'displayName' => $this->displayName($uid)], $mentions),
			'source' => (string)($data['source'] ?? CaseNoteRules::SOURCE_APP),
			'sourceName' => (string)($data['sourceName'] ?? ''),
			'sourceLabel' => CaseNoteRules::sourceLabel((string)($data['source'] ?? '')),
			'createdAt' => (string)($record['date'] ?? ''),
			'createdBy' => $createdBy,
			'createdByName' => $this->displayName($createdBy),
			'edited' => $editedBy !== '' && (string)($data['editedAt'] ?? '') !== '',
			'editedAt' => (string)($data['editedAt'] ?? ''),
			'editedBy' => $editedBy,
			'editedByName' => $editedBy !== '' ? $this->displayName($editedBy) : '',
		];
	}

	private function displayName(string $uid): string {
		if ($uid === '' || $uid === 'system') {
			return $uid === 'system' ? 'System' : '';
		}
		if (!isset($this->nameCache[$uid])) {
			$user = $this->userManager->get($uid);
			$this->nameCache[$uid] = $user !== null ? ($user->getDisplayName() ?: $uid) : $uid;
		}
		return $this->nameCache[$uid];
	}

	/** Ordner der Kategorie „Bestatter“ in den Notizen des angemeldeten Benutzers (Notizordner laut Notes-Einstellung). */
	private function notesCategoryFolder(): ?Folder {
		$uid = $this->currentUid();
		if ($uid === '') {
			return null;
		}
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$settings = json_decode($this->config->getUserValue($uid, 'notes', 'settings', ''), true);
		$configured = is_array($settings) ? trim((string)($settings['notesPath'] ?? ''), " /\t") : '';
		foreach (array_values(array_unique(array_filter([$configured, 'Notizen', 'Notes']))) as $path) {
			if (!$userFolder->nodeExists($path)) {
				continue;
			}
			$notes = $userFolder->get($path);
			if (!$notes instanceof Folder) {
				continue;
			}
			if ($notes->nodeExists(CaseNoteRules::NOTES_APP_CATEGORY)) {
				$category = $notes->get(CaseNoteRules::NOTES_APP_CATEGORY);
				return $category instanceof Folder ? $category : null;
			}
			return null;
		}
		return null;
	}

	private function doneFolder(Folder $category): Folder {
		if ($category->nodeExists(CaseNoteRules::NOTES_APP_DONE_FOLDER)) {
			$node = $category->get(CaseNoteRules::NOTES_APP_DONE_FOLDER);
			if ($node instanceof Folder) {
				return $node;
			}
		}
		return $category->newFolder(CaseNoteRules::NOTES_APP_DONE_FOLDER);
	}

	private function uniqueName(Folder $folder, string $name): string {
		if (!$folder->nodeExists($name)) {
			return $name;
		}
		$extension = pathinfo($name, PATHINFO_EXTENSION);
		$base = $extension !== '' ? substr($name, 0, -strlen($extension) - 1) : $name;
		for ($number = 2; $number < 1000; $number++) {
			$candidate = $base . ' (' . $number . ')' . ($extension !== '' ? '.' . $extension : '');
			if (!$folder->nodeExists($candidate)) {
				return $candidate;
			}
		}
		return $base . ' (' . bin2hex(random_bytes(3)) . ')' . ($extension !== '' ? '.' . $extension : '');
	}

	private function isNoteFile(File $file): bool {
		return in_array(strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)), ['md', 'txt', 'markdown'], true);
	}

	private function json(array $payload): string {
		return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	private function currentUid(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}
}
