<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\Files\File;
use OCP\Files\Folder;

/**
 * 0.88.2: Zustand der Vorlagendateien im Nextcloud-Vorlagenordner (Standardfassung, ältere Standardfassung, eigene
 * Fassung, noch nicht angelegt) und ausdrückliche Übernahme der mitgelieferten Standardfassung. Die bisherige Datei bleibt
 * dabei als datierte Kopie erhalten; nichts wird ohne Auftrag überschrieben.
 */
class DocumentTemplateFileService {
	/** Vorlagen, die die App fest unter ihrem Schlüssel verwendet (nicht löschen oder deaktivieren). */
	public const SYSTEM_KEYS = ['BESTATTUNGSAUFTRAG', 'KOSTENVORANSCHLAG', 'BESTATTUNGSVOLLMACHT', 'WIDERRUFSBELEHRUNG', 'RECHNUNG', 'KORREKTUR'];
	private const PACKAGE_DIR = __DIR__ . '/../../resources/templates/';
	private const DEFAULTS = __DIR__ . '/../../resources/document-templates.json';

	public function __construct(
		private ConfigurationService $configuration,
		private FolderService $folders,
		private AuditService $audit,
	) {
	}

	public function status(): array {
		$folder = $this->folders->templatesFolder();
		$defaults = $this->defaultNames();
		$templates = [];
		foreach ($this->configuration->documents() as $template) {
			$key = (string)$template['key'];
			$templates[] = ['key' => $key, 'system' => in_array($key, self::SYSTEM_KEYS, true), 'defaultName' => $defaults[$key] ?? ''] + $this->fileStatus($folder, basename((string)$template['fileName']));
		}
		return ['templates' => $templates, 'templatePath' => (string)($this->folders->configuredPaths()['templates'] ?? '')];
	}

	/**
	 * Übernimmt die mitgelieferte Fassung der Datei einer Vorlage. Die bisherige Datei bleibt als „NAME-vor-JJJJ-MM-TT.docx“
	 * erhalten. Beim Bestattungsauftrag übernimmt eine noch nicht vorhandene KOSTENVORANSCHLAG.docx das Layout einer eigenen,
	 * älteren Auftragsvorlage (wie beim ersten Kostenvoranschlag nach dem Update).
	 */
	public function adoptStandard(string $key): array {
		$template = null;
		foreach ($this->configuration->documents() as $candidate) if ((string)$candidate['key'] === $key) { $template = $candidate; break; }
		if ($template === null) throw new \InvalidArgumentException('Dokumentvorlage wurde nicht gefunden.');
		$fileName = basename((string)$template['fileName']);
		$source = $this->packageSource($fileName);
		if ($source === null) throw new \InvalidArgumentException('Für „' . $fileName . '“ gibt es keine mitgelieferte Standardfassung.');
		$standard = (string)file_get_contents($source);
		$folder = $this->folders->templatesFolder();
		$backup = '';
		$estimate = '';
		if ($folder->nodeExists($fileName)) {
			$node = $folder->get($fileName);
			if (!$node instanceof File) throw new \RuntimeException('„' . $fileName . '“ ist im Vorlagenordner keine Datei, sondern ein Ordner. Bitte umbenennen oder entfernen.');
			$content = (string)$node->getContent();
			if (hash('sha256', $content) === hash('sha256', $standard)) return ['changed' => false, 'backup' => '', 'estimate' => '', 'status' => $this->fileStatus($folder, $fileName)];
			if ($fileName === 'BESTATTUNGSAUFTRAG.docx' && !$folder->nodeExists('KOSTENVORANSCHLAG.docx')
				&& !in_array(hash('sha256', $content), DocumentService::REPLACEABLE_STANDARD_TEMPLATE_HASHES[$fileName] ?? [], true)
				&& !DocumentService::docxContainsText($content, DocumentService::COMBINED_ORDER_MARKER)) {
				$folder->newFile('KOSTENVORANSCHLAG.docx', $content);
				$estimate = 'KOSTENVORANSCHLAG.docx';
			}
			$backup = $this->freeName($folder, pathinfo($fileName, PATHINFO_FILENAME) . '-vor-' . date('Y-m-d'), (string)pathinfo($fileName, PATHINFO_EXTENSION));
			$folder->newFile($backup, $content);
			$node->putContent($standard);
		} else {
			$folder->newFile($fileName, $standard);
		}
		$this->audit->logSystem('DOCUMENT_TEMPLATE', 'STANDARD_ADOPTED', ['key' => $key, 'fileName' => $fileName, 'backup' => $backup, 'estimate' => $estimate]);
		return ['changed' => true, 'backup' => $backup, 'estimate' => $estimate, 'status' => $this->fileStatus($folder, $fileName)];
	}

	private function fileStatus(Folder $folder, string $fileName): array {
		$source = $this->packageSource($fileName);
		$base = ['fileName' => $fileName, 'text' => !preg_match('/\.docx$/i', $fileName), 'packageAvailable' => $source !== null];
		if ($fileName === '') return $base + ['state' => 'MISSING'];
		if (!$folder->nodeExists($fileName)) return $base + ['state' => $source !== null ? 'NOT_PROVISIONED' : 'MISSING'];
		$node = $folder->get($fileName);
		if (!$node instanceof File) return $base + ['state' => 'MISSING'];
		$content = (string)$node->getContent();
		$hash = hash('sha256', $content);
		$state = 'CUSTOM';
		if ($source === null) $state = 'OWN';
		elseif ($hash === hash_file('sha256', $source)) $state = 'STANDARD';
		elseif (in_array($hash, DocumentService::REPLACEABLE_STANDARD_TEMPLATE_HASHES[$fileName] ?? [], true)) $state = 'OUTDATED_STANDARD';
		$result = $base + ['state' => $state, 'modifiedAt' => date('c', (int)$node->getMTime())];
		// Bestattungsauftrag: Vollmachtsteil vorhanden? Sonst erzeugt die App die Vollmacht gesondert.
		if ($fileName === 'BESTATTUNGSAUFTRAG.docx') $result['powerOfAttorney'] = DocumentService::docxContainsText($content, DocumentService::COMBINED_ORDER_MARKER);
		return $result;
	}

	private function packageSource(string $fileName): ?string {
		if ($fileName === '' || str_contains($fileName, '/') || str_contains($fileName, '..')) return null;
		$path = self::PACKAGE_DIR . $fileName;
		return is_file($path) ? $path : null;
	}

	private function freeName(Folder $folder, string $base, string $extension): string {
		$suffix = $extension !== '' ? '.' . $extension : '';
		$name = $base . $suffix;
		for ($number = 2; $folder->nodeExists($name); $number++) $name = $base . '-' . $number . $suffix;
		return $name;
	}

	/** Mitgelieferte Bezeichnungen je Schlüssel (Hinweis, wenn eine Bezeichnung davon abweicht). */
	private function defaultNames(): array {
		$data = json_decode((string)@file_get_contents(self::DEFAULTS), true);
		$names = [];
		foreach ((array)($data['templates'] ?? []) as $template) if (is_array($template) && isset($template['key'], $template['name'])) $names[(string)$template['key']] = (string)$template['name'];
		return $names;
	}
}
