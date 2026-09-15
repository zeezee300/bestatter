<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\Files\Folder;
use OCP\IConfig;

/**
 * Installation-specific role and storage configuration.
 *
 * Values live in Nextcloud's persistent app config and can therefore be
 * maintained through the UI or provisioned with occ config:app:set.
 */
class InstallationConfigService {
	private const DEFAULTS = [
		'member_group' => 'Bestatter',
		'admin_groups' => 'Bestatter-Administratoren,Bestatter Administratoren',
		'storage_root' => 'Bestatter',
		'cases_folder' => 'Fälle',
		'templates_folder' => 'Vorlagen',
		'assistant_folder' => '.Assistenz',
		'article_images_folder' => 'Artikelbilder',
		'default_upload_folder' => '01 Stammdaten',
		'order_folder' => '02 Auftrag',
		'authorities_folder' => '03 Behörden',
		'billing_folder' => '06 Abrechnung',
		'case_subfolders' => '["01 Stammdaten","02 Auftrag","03 Behörden","04 Friedhof","05 Trauerdruck","06 Abrechnung"]',
	];

	public function __construct(private IConfig $config) {}

	public function settings(): array {
		return [
			'memberGroup' => $this->memberGroup(),
			'adminGroups' => $this->adminGroups(),
			'storageRoot' => $this->storageRoot(),
			'casesFolder' => $this->casesFolder(),
			'templatesFolder' => $this->templatesFolder(),
			'assistantFolder' => $this->assistantFolder(),
			'articleImagesFolder' => $this->articleImagesFolder(),
			'defaultUploadFolder' => $this->defaultUploadFolder(),
			'orderFolder' => $this->orderFolder(),
			'authoritiesFolder' => $this->authoritiesFolder(),
			'billingFolder' => $this->billingFolder(),
			'caseSubfolders' => $this->caseSubfolders(),
			'paths' => [
				'cases' => $this->casesPath(),
				'templates' => $this->templatesPath(),
				'assistant' => $this->join($this->storageRoot(), $this->assistantFolder()),
				'articleImages' => $this->join($this->storageRoot(), $this->articleImagesFolder()),
			],
		];
	}

	public function save(array $input): array {
		$memberGroup = $this->groupName((string)($input['memberGroup'] ?? ''), 'Mitgliedergruppe');
		$adminGroups = array_values(array_unique(array_filter(array_map(
			fn(mixed $value): string => $this->groupName((string)$value, 'Administrationsgruppe'),
			(array)($input['adminGroups'] ?? []),
		))));
		if ($adminGroups === []) throw new \InvalidArgumentException('Mindestens eine Administrationsgruppe ist erforderlich.');

		$values = [
			'member_group' => $memberGroup,
			'admin_groups' => implode(',', $adminGroups),
			'storage_root' => $this->relativePath((string)($input['storageRoot'] ?? ''), 'Arbeitsordner'),
			'cases_folder' => $this->folderName((string)($input['casesFolder'] ?? ''), 'Fallordner'),
			'templates_folder' => $this->folderName((string)($input['templatesFolder'] ?? ''), 'Vorlagenordner'),
			'assistant_folder' => $this->folderName((string)($input['assistantFolder'] ?? ''), 'Assistenzordner'),
			'article_images_folder' => $this->folderName((string)($input['articleImagesFolder'] ?? ''), 'Artikelbilderordner'),
		];
		$subfolders = array_values(array_unique(array_map(
			fn(mixed $value): string => $this->folderName((string)$value, 'Fall-Unterordner'),
			(array)($input['caseSubfolders'] ?? []),
		)));
		if ($subfolders === []) throw new \InvalidArgumentException('Mindestens ein Fall-Unterordner ist erforderlich.');
		$values['case_subfolders'] = json_encode($subfolders, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		foreach (['defaultUploadFolder'=>'default_upload_folder','orderFolder'=>'order_folder','authoritiesFolder'=>'authorities_folder','billingFolder'=>'billing_folder'] as $inputKey=>$configKey) {
			$current = match ($inputKey) { 'defaultUploadFolder'=>$this->defaultUploadFolder(), 'orderFolder'=>$this->orderFolder(), 'authoritiesFolder'=>$this->authoritiesFolder(), default=>$this->billingFolder() };
			$value = $this->folderName((string)($input[$inputKey] ?? $current), $inputKey);
			if (!in_array($value, $subfolders, true)) throw new \InvalidArgumentException('Der Funktionsordner "' . $value . '" ist nicht in den Standard-Unterordnern enthalten.');
			$values[$configKey] = $value;
		}

		foreach ($values as $key => $value) $this->config->setAppValue(Application::APP_ID, $key, $value);
		return $this->settings();
	}

	public function memberGroup(): string { return $this->value('member_group'); }
	public function adminGroups(): array { return array_values(array_filter(array_map('trim', explode(',', $this->value('admin_groups'))))); }
	public function storageRoot(): string { return $this->value('storage_root'); }
	public function casesFolder(): string { return $this->value('cases_folder'); }
	public function templatesFolder(): string { return $this->value('templates_folder'); }
	public function assistantFolder(): string { return $this->value('assistant_folder'); }
	public function articleImagesFolder(): string { return $this->value('article_images_folder'); }
	public function defaultUploadFolder(): string { return $this->value('default_upload_folder'); }
	public function orderFolder(): string { return $this->value('order_folder'); }
	public function authoritiesFolder(): string { return $this->value('authorities_folder'); }
	public function billingFolder(): string { return $this->value('billing_folder'); }

	public function caseSubfolders(): array {
		$decoded = json_decode($this->value('case_subfolders'), true);
		return is_array($decoded) && $decoded !== [] ? array_values(array_map('strval', $decoded)) : [];
	}

	public function casesPath(): string { return $this->join($this->storageRoot(), $this->casesFolder()); }
	public function templatesPath(): string { return $this->join($this->storageRoot(), $this->templatesFolder()); }

	public function ensurePath(Folder $base, string $relativePath): Folder {
		$folder = $base;
		foreach (explode('/', trim($relativePath, '/')) as $name) {
			if ($name === '') continue;
			$node = $folder->nodeExists($name) ? $folder->get($name) : $folder->newFolder($name);
			if (!$node instanceof Folder) throw new \RuntimeException('Der konfigurierte Pfad "' . $relativePath . '" ist keine Ordnerstruktur.');
			$folder = $node;
		}
		return $folder;
	}

	private function value(string $key): string {
		return trim($this->config->getAppValue(Application::APP_ID, $key, self::DEFAULTS[$key]));
	}

	private function join(string ...$parts): string {
		return implode('/', array_values(array_filter(array_map(static fn(string $part): string => trim($part, '/'), $parts), static fn(string $part): bool => $part !== '')));
	}

	private function groupName(string $value, string $label): string {
		$value = trim($value);
		if ($value === '' || preg_match('/[\x00-\x1F\x7F]/u', $value)) throw new \InvalidArgumentException($label . ' ist ungültig.');
		return $value;
	}

	private function folderName(string $value, string $label): string {
		$value = trim($value);
		if ($value === '' || $value === '.' || $value === '..' || str_contains($value, '/') || str_contains($value, '\\') || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
			throw new \InvalidArgumentException($label . ' muss ein einzelner gültiger Ordnername sein.');
		}
		return $value;
	}

	private function relativePath(string $value, string $label): string {
		$value = trim(str_replace('\\', '/', $value), '/');
		if ($value === '') throw new \InvalidArgumentException($label . ' darf nicht leer sein.');
		foreach (explode('/', $value) as $segment) $this->folderName($segment, $label);
		return $value;
	}
}
