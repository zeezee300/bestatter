<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\Files\File;
use OCP\Files\Folder;

class CaseFileService {
	private const MAX_UPLOAD_BYTES = 50 * 1024 * 1024;
	private const BLOCKED_EXTENSIONS = ['bat', 'cmd', 'com', 'exe', 'hta', 'html', 'htm', 'js', 'jse', 'msi', 'msp', 'phar', 'php', 'php3', 'php4', 'php5', 'phtml', 'ps1', 'scr', 'sh', 'svg', 'vbs', 'wsf'];
	private const DOCUMENT_TYPES = ['Korrespondenz', 'Auftrag', 'Formular', 'Urkunde', 'Foto', 'Rechnung', 'Nachweis', 'Sonstiges'];

	public function __construct(private FolderService $folders, private RecordService $records) {}

	public function list(array $case): array {
		$caseId = (int)($case['id'] ?? 0);
		$caseNumber = (string)($case['caseNumber'] ?? '');
		$root = $this->folders->caseRoot($caseNumber);
		$recordIndex = $this->recordIndex($caseId);
		$files = [];
		$this->collect($root, $root->getPath(), $recordIndex, $files);
		usort($files, static fn(array $left, array $right): int => strnatcasecmp((string)$left['relativePath'], (string)$right['relativePath']));
		return ['caseId' => $caseId, 'caseNumber' => $caseNumber, 'folders' => $this->folders->allowedSubfolders(), 'documentTypes' => self::DOCUMENT_TYPES, 'files' => $files];
	}

	public function upload(array $case, array $upload, string $subfolder, string $documentType = 'Sonstiges', string $title = ''): array {
		$this->validateUpload($upload);
		if (trim($subfolder) === '') $subfolder = $this->folders->defaultUploadFolder();
		if (!in_array($documentType, self::DOCUMENT_TYPES, true)) throw new \InvalidArgumentException('Die gewählte Dokumentenart ist nicht zulässig.');
		$originalName = $this->safeName((string)($upload['name'] ?? ''));
		$content = file_get_contents((string)($upload['tmp_name'] ?? ''));
		if ($content === false) throw new \RuntimeException('Die hochgeladene Datei konnte nicht gelesen werden.');
		$folder = $this->folders->caseSubfolder((string)$case['caseNumber'], $subfolder);
		$fileName = $this->uniqueName($folder, $originalName);
		$file = $folder->newFile($fileName);
		$file->putContent($content);
		$relativePath = $subfolder . '/' . $fileName;
		$payload = [
			'source' => 'UPLOAD', 'fileId' => $file->getId(),
			'path' => $this->folders->caseRelativePath((string)$case['caseNumber']) . '/' . $relativePath,
			'relativePath' => $relativePath, 'subfolder' => $subfolder, 'documentType' => $documentType,
			'mimeType' => $file->getMimeType(), 'size' => $file->getSize(), 'originalName' => $originalName, 'uploadedAt' => date('c'),
		];
		$record = $this->records->saveDocument((int)$case['id'], trim($title) ?: pathinfo($fileName, PATHINFO_FILENAME), 'EINGEGANGEN', $payload);
		return ['file' => $this->mapFile($file, $this->folders->caseRoot((string)$case['caseNumber'])->getPath(), $record), 'record' => $record];
	}

	public function attachmentFiles(array $case): array { return $this->list($case)['files']; }

	public function listAll(array $cases): array {
		$files = [];
		foreach ($cases as $case) foreach ($this->list($case)['files'] as $file) $files[] = $file + ['caseId' => (int)$case['id'], 'caseNumber' => (string)$case['caseNumber']];
		usort($files, static fn(array $left, array $right): int => strcmp((string)$right['modifiedAt'], (string)$left['modifiedAt']));
		return ['files' => $files];
	}

	private function collect(Folder $folder, string $rootPath, array $recordIndex, array &$files): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) $this->collect($node, $rootPath, $recordIndex, $files);
			elseif ($node instanceof File) $files[] = $this->mapFile($node, $rootPath, $recordIndex[$node->getId()] ?? null);
		}
	}

	private function mapFile(File $file, string $rootPath, ?array $record): array {
		$relativePath = ltrim(substr($file->getPath(), strlen($rootPath)), '/');
		$parts = explode('/', $relativePath, 2);
		$data = $record['data'] ?? [];
		return [
			'fileId' => $file->getId(), 'recordId' => (int)($record['id'] ?? 0), 'name' => $file->getName(),
			'title' => (string)($record['title'] ?? pathinfo($file->getName(), PATHINFO_FILENAME)),
			'path' => ltrim(preg_replace('#^/[^/]+/files/#', '', $file->getPath()) ?? $file->getPath(), '/'),
			'relativePath' => $relativePath, 'subfolder' => $parts[0] ?? '', 'mimeType' => $file->getMimeType(),
			'size' => $file->getSize(), 'modifiedAt' => date('c', $file->getMTime()), 'extension' => strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)),
			'documentType' => (string)($data['documentType'] ?? $this->inferDocumentType($relativePath, $file->getMimeType())),
			'status' => (string)($record['status'] ?? 'ABLAGE'), 'source' => (string)($data['source'] ?? ($record === null ? 'NEXTCLOUD' : 'ERZEUGT')),
			'readOnly' => in_array(strtoupper((string)($record['status'] ?? '')), ['FINAL', 'UNTERSCHRIEBEN', 'VERSENDET'], true),
		];
	}

	private function recordIndex(int $caseId): array {
		$index = [];
		foreach ($this->records->list('document', $caseId) as $record) {
			$data = $record['data'] ?? [];
			foreach ([(int)($data['fileId'] ?? 0), (int)($data['pdf']['fileId'] ?? 0), (int)($data['eInvoice']['fileId'] ?? 0)] as $fileId) if ($fileId > 0) $index[$fileId] = $record;
		}
		return $index;
	}

	private function validateUpload(array $upload): void {
		$error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
		if ($error !== UPLOAD_ERR_OK) throw new \InvalidArgumentException(in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'Die Datei überschreitet die zulässige Uploadgröße.' : 'Bitte eine Datei auswählen.');
		$size = (int)($upload['size'] ?? 0);
		if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) throw new \InvalidArgumentException('Die Datei muss zwischen 1 Byte und 50 MB groß sein.');
		$name = $this->safeName((string)($upload['name'] ?? ''));
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if ($extension === '' || in_array($extension, self::BLOCKED_EXTENSIONS, true)) throw new \InvalidArgumentException('Dieser Dateityp darf aus Sicherheitsgründen nicht hochgeladen werden.');
		$tmp = (string)($upload['tmp_name'] ?? '');
		if ($tmp === '' || !is_file($tmp)) throw new \InvalidArgumentException('Die Upload-Datei ist nicht verfügbar.');
	}

	private function safeName(string $name): string {
		$name = trim(basename(str_replace('\\', '/', $name)));
		$name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
		if ($name === '' || $name === '.' || $name === '..' || strlen($name) > 180) throw new \InvalidArgumentException('Der Dateiname ist ungültig oder zu lang.');
		return $name;
	}

	private function uniqueName(Folder $folder, string $name): string {
		if (!$folder->nodeExists($name)) return $name;
		$base = pathinfo($name, PATHINFO_FILENAME); $extension = pathinfo($name, PATHINFO_EXTENSION);
		for ($number = 2; $number <= 999; $number++) {
			$candidate = $base . ' (' . $number . ')' . ($extension !== '' ? '.' . $extension : '');
			if (!$folder->nodeExists($candidate)) return $candidate;
		}
		throw new \RuntimeException('Für diesen Dateinamen konnten keine weiteren Varianten angelegt werden.');
	}

	private function inferDocumentType(string $path, string $mimeType): string {
		$value = mb_strtolower($path);
		if (str_starts_with($mimeType, 'image/')) return 'Foto';
		if (str_contains($value, 'rechnung')) return 'Rechnung';
		if (str_contains($value, 'urkunde')) return 'Urkunde';
		if (str_contains($value, 'auftrag')) return 'Auftrag';
		if (str_contains($value, 'formular') || str_contains($value, 'behörden')) return 'Formular';
		if (str_contains($value, 'nachweis')) return 'Nachweis';
		return 'Sonstiges';
	}
}
