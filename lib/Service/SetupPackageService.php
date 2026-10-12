<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\Files\File;
use OCP\IDBConnection;

/**
 * 0.75.3 – Kundenspezifisches Einrichtungspaket (OP-063): ZIP mit paket.json und den Inhalten, die zu einem
 * Bestattungsunternehmen gehören – Warengruppen, Leistungskatalog, Preislisten, Dokumentvorlagen (DOCX und Textvorlagen),
 * Auftragsformulare, Ablaufvorlagen und Logos je Niederlassung. Keine Falldaten, keine Zugangs- oder Bankdaten.
 *
 * Einspielen in zwei Schritten: preview() zeigt je Eintrag den Stand (neu/vorhanden) und die vorgeschlagene Aktion;
 * apply() führt die gewählten Aktionen aus – nach einer automatischen Sicherung der App-Daten, in fester Reihenfolge
 * (Katalog vor Formularen und Abläufen) und mit Audit-Eintrag. Vorhandenes wird nie stillschweigend geändert.
 */
class SetupPackageService {
	public const FORMAT = 'bestatter-setup-package';
	public const FORMAT_VERSION = 1;
	public const MAX_BYTES = 50 * 1024 * 1024;
	public const TYPES = ['article-groups', 'articles', 'price-list', 'document-template', 'order-form', 'labels', 'document-kinds', 'process-template', 'logo'];
	public const TYPE_LABELS = [
		'article-groups' => 'Warengruppen', 'articles' => 'Leistungskatalog (Artikel)', 'price-list' => 'Preisliste', 'document-template' => 'Dokumentvorlage',
		'order-form' => 'Auftragsformular', 'labels' => 'Marker', 'document-kinds' => 'Dokumentarten', 'process-template' => 'Ablaufvorlage', 'logo' => 'Logo',
	];
	public const ACTION_LABELS = ['CREATE' => 'Neu anlegen', 'UPDATE' => 'Aktualisieren', 'REPLACE' => 'Ersetzen', 'COPY' => 'Als Kopie anlegen', 'SKIP' => 'Überspringen'];
	/** Diese Dokumentvorlagen verwendet die App fest unter ihrem Schlüssel; eine Kopie würde nicht benutzt. */
	private const SYSTEM_DOCUMENT_KEYS = ['RECHNUNG', 'BESTATTUNGSAUFTRAG', 'KOSTENVORANSCHLAG', 'KORREKTUR'];

	public function __construct(
		private IDBConnection $db,
		private ArticleService $articles,
		private CatalogStructureService $structure,
		private PriceListService $priceLists,
		private ConfigurationService $configuration,
		private FolderService $folders,
		private OrderFormService $orderForms,
		private ProcessService $process,
		private BranchLogoService $logos,
		private BackupService $backup,
		private AuditService $audit,
		private LabelService $labels,
		private DocumentKindService $documentKinds,
	) {}

	// ---------------------------------------------------------------------------------------------- Export

	/**
	 * @param list<string> $types Inhaltsarten (leer = alle)
	 * @return array{fileName:string,content:string,contents:list<array>}
	 */
	public function export(string $customer = '', array $types = []): array {
		$types = $types === [] ? self::TYPES : array_values(array_intersect(self::TYPES, $types));
		$tmp = tempnam(sys_get_temp_dir(), 'bestatter-setup-');
		if ($tmp === false) throw new \RuntimeException('Temporäre Datei für das Einrichtungspaket konnte nicht angelegt werden.');
		$zip = new \ZipArchive();
		if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Das Einrichtungspaket konnte nicht angelegt werden.');
		$contents = [];
		$add = function (string $type, string $key, string $title, string $file, string $content, array $extra = []) use ($zip, &$contents): void {
			$zip->addFromString($file, $content);
			$contents[] = ['type' => $type, 'key' => $key, 'title' => $title, 'file' => $file] + $extra;
		};
		if (in_array('article-groups', $types, true)) $add('article-groups', 'GROUPS', 'Warengruppen', 'leistungskatalog/Warengruppen.csv', $this->structure->exportGroupsCsv());
		if (in_array('articles', $types, true)) $add('articles', 'ARTICLES', 'Leistungskatalog', 'leistungskatalog/Artikel.csv', $this->articles->exportCsv());
		if (in_array('price-list', $types, true)) foreach ($this->priceLists->lists() as $list) {
			$add('price-list', (string)$list['key'], (string)$list['name'], 'preislisten/' . self::fileSafe((string)$list['key']) . '.csv', $this->priceLists->exportCsv((int)$list['id']), ['branchKey' => $list['branchKey'], 'standard' => (bool)$list['standard']]);
		}
		if (in_array('document-template', $types, true)) {
			$folder = $this->folders->templatesFolder();
			foreach ($this->configuration->documents() as $template) {
				$fileName = basename((string)$template['fileName']);
				if ($fileName === '' || !$folder->nodeExists($fileName)) continue;
				$node = $folder->get($fileName);
				if (!$node instanceof File) continue;
				$meta = array_intersect_key($template, array_flip(['key', 'name', 'category', 'description', 'fileName', 'requiredFields', 'outputSubfolder', 'supportsPdf', 'active', 'sortOrder']));
				$add('document-template', (string)$template['key'], (string)$template['name'], 'dokumentvorlagen/' . $fileName, (string)$node->getContent(), ['template' => $meta]);
			}
		}
		if (in_array('order-form', $types, true)) foreach ($this->orderForms->forms() as $form) {
			$file = $this->orderForms->export((int)$form['id']);
			$add('order-form', (string)$form['key'], (string)$form['name'], 'auftragsformulare/' . $file['filename'], $file['content']);
		}
		// 0.76.0: alle Marker-Definitionen mit Aktiv-Schalter (so wird die Auswahl einer eingerichteten Installation übertragen).
		if (in_array('labels', $types, true)) $add('labels', 'LABELS', 'Marker', 'marker/Marker.json', $this->labels->exportExchange());
		// 0.80.0: Dokumentarten (vor den Abläufen, die sie als Auslöser verwenden).
		if (in_array('document-kinds', $types, true)) $add('document-kinds', 'DOCUMENT_KINDS', 'Dokumentarten', 'dokumentarten/Dokumentarten.json', $this->documentKinds->exportExchange());
		if (in_array('process-template', $types, true)) foreach ($this->process->templates() as $template) {
			$file = $this->process->exportJson((string)$template['key']);
			$add('process-template', (string)$template['key'], (string)$template['name'], 'ablaufvorlagen/' . $file['fileName'], $file['content']);
		}
		if (in_array('logo', $types, true)) foreach (array_keys($this->logos->index()) as $branchKey) {
			$png = $this->logos->logo((string)$branchKey);
			if ($png !== null) $add('logo', (string)$branchKey, 'Logo ' . $branchKey, 'logos/' . self::fileSafe((string)$branchKey) . '.png', $png);
		}
		$manifest = ['format' => self::FORMAT, 'formatVersion' => self::FORMAT_VERSION, 'customer' => trim($customer), 'createdAt' => date('c'),
			'appVersion' => Application::VERSION, 'minAppVersion' => Application::VERSION, 'contents' => $contents];
		$zip->addFromString('paket.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		$zip->close();
		$content = (string)file_get_contents($tmp);
		@unlink($tmp);
		$this->audit->logSystem('SETUP_PACKAGE', 'EXPORTED', ['customer' => trim($customer), 'items' => count($contents), 'types' => $types]);
		$name = trim($customer) !== '' ? self::fileSafe(trim($customer)) : date('Y-m-d');
		return ['fileName' => 'Einrichtungspaket-' . $name . '.zip', 'content' => $content, 'contents' => $contents];
	}

	// ---------------------------------------------------------------------------------------------- Vorschau

	/** @return array{manifest:array,items:list<array>,warnings:list<string>} */
	public function preview(string $zipContent): array {
		[$manifest, $files] = $this->read($zipContent);
		$items = [];
		foreach ($manifest['contents'] as $index => $entry) $items[] = $this->previewItem($index, $entry, $files);
		usort($items, static fn(array $a, array $b): int => [array_search($a['type'], self::TYPES, true), $a['index']] <=> [array_search($b['type'], self::TYPES, true), $b['index']]);
		$warnings = [];
		if (version_compare(Application::VERSION, (string)($manifest['minAppVersion'] ?? '0'), '<')) $warnings[] = 'Das Paket wurde für Bestatter ' . $manifest['minAppVersion'] . ' erstellt; installiert ist ' . Application::VERSION . '. Bitte zuerst die App aktualisieren.';
		return ['manifest' => array_diff_key($manifest, ['contents' => true]) + ['itemCount' => count($items)], 'items' => $items, 'warnings' => $warnings];
	}

	private function previewItem(int $index, array $entry, array $files): array {
		$type = (string)$entry['type']; $key = (string)$entry['key']; $content = $files[$entry['file']] ?? '';
		$item = ['index' => $index, 'type' => $type, 'typeLabel' => self::TYPE_LABELS[$type], 'key' => $key, 'title' => (string)($entry['title'] ?? $key), 'file' => (string)$entry['file'],
			'exists' => false, 'status' => 'NEU', 'details' => '', 'actions' => [], 'defaultAction' => 'SKIP', 'warnings' => []];
		try {
			switch ($type) {
				case 'article-groups':
				case 'articles':
					$column = $type === 'articles' ? 'artikelnummer' : 'schluessel';
					$keys = []; foreach (CsvTools::read($content, static fn(string $header): string => CsvTools::headerKey($header)) as $row) $keys[] = trim((string)($row['values'][$column] ?? ''));
					$existing = $type === 'articles' ? $this->existingValues('bestatter_articles', 'article_number') : $this->existingValues('bestatter_article_groups', 'group_key');
					$known = count(array_filter($keys, static fn(string $k): bool => isset($existing[$k])));
					$item += ['count' => count($keys)];
					$item['exists'] = $known > 0;
					$item['status'] = $known > 0 ? 'VORHANDEN' : 'NEU';
					$item['details'] = count($keys) . ' Einträge, davon ' . (count($keys) - $known) . ' neu und ' . $known . ' vorhanden (werden aktualisiert).';
					$item['actions'] = ['UPDATE', 'SKIP']; $item['defaultAction'] = 'UPDATE';
					break;
				case 'price-list':
					$list = $this->priceListByKey($key);
					$item['exists'] = $list !== null;
					$branchKey = (string)($entry['branchKey'] ?? '');
					if ($list === null && $branchKey !== '' && $this->configuration->branchByKey($branchKey) === null) { $item['warnings'][] = 'Niederlassung ' . $branchKey . ' fehlt – Preisliste kann nicht angelegt werden.'; $item['actions'] = ['SKIP']; break; }
					$item['status'] = $list !== null ? 'VORHANDEN' : 'NEU';
					$item['details'] = $list !== null ? 'Preise werden in „' . $list['name'] . '“ übernommen (neuer Preis gilt ab dem angegebenen Datum).' : 'Preisliste der Niederlassung ' . $branchKey . ' wird angelegt.';
					$item['actions'] = $list !== null ? ['UPDATE', 'SKIP'] : ['CREATE', 'SKIP']; $item['defaultAction'] = $item['actions'][0];
					break;
				case 'document-template':
					$meta = (array)($entry['template'] ?? []);
					$fileName = basename((string)($meta['fileName'] ?? $entry['file']));
					$isText = self::templateExtension($fileName) === 'txt';
					$existing = $this->documentByKey($key);
					$item['exists'] = $existing !== null;
					$item['status'] = $existing !== null ? 'VORHANDEN' : 'NEU';
					self::checkTemplateContent($fileName, $content);
					$item['details'] = $fileName . ($existing !== null && basename((string)$existing['fileName']) !== $fileName ? ' · bisher: ' . $existing['fileName'] : '');
					$same = $existing !== null && $this->templateContent((string)$existing['fileName']) === $content;
					if (in_array($key, self::SYSTEM_DOCUMENT_KEYS, true) && !$same) $item['warnings'][] = 'Die App verwendet diese Vorlage fest unter dem Schlüssel ' . $key . '. Damit das Layout wirkt, „Ersetzen“ wählen; die bisherige Datei bleibt als Kopie im Vorlagenordner.';
					if (!$isText && $this->folders->resolveSubfolder((string)($meta['outputSubfolder'] ?? '')) === null) $item['warnings'][] = 'Den Ablageordner „' . (string)($meta['outputSubfolder'] ?? '') . '“ gibt es in dieser Installation nicht – Dokumente werden in „' . $this->defaultSubfolder() . '“ abgelegt. Bitte danach unter Customizing → Dokumentvorlagen prüfen.';
					if ($isText) {
						// Textvorlagen (Abmeldungen) sind feste Vorlagen der App: nur der Text einer vorhandenen Vorlage kann ersetzt werden.
						if ($existing === null) { $item['warnings'][] = 'Textvorlagen können nur eine vorhandene Vorlage gleichen Schlüssels ersetzen.'; $item['actions'] = ['SKIP']; $item['defaultAction'] = 'SKIP'; break; }
						$item['actions'] = ['REPLACE', 'SKIP'];
					} else {
						$item['actions'] = $existing !== null ? ['REPLACE', 'COPY', 'SKIP'] : ['CREATE', 'SKIP'];
					}
					$item['defaultAction'] = $existing === null ? 'CREATE' : ($same || $isText ? 'SKIP' : 'COPY');
					if ($same) $item['details'] .= ' · Inhalt unverändert';
					break;
				case 'order-form':
					$data = json_decode($content, true) ?: [];
					if (($data['format'] ?? '') !== OrderFormService::EXCHANGE_FORMAT) throw new \InvalidArgumentException('Kein Auftragsformular im erwarteten Format.');
					$existing = $this->orderFormByKey($key);
					$item['exists'] = $existing !== null;
					$item['status'] = $existing !== null ? 'VORHANDEN' : 'NEU';
					$item['warnings'] = $this->orderForms->catalogWarnings((array)($data['definition'] ?? []));
					$same = $existing !== null && self::sameExchange($this->orderForms->export((int)$existing['id'])['content'], $content);
					$item['details'] = count((array)($data['definition']['sections'] ?? [])) . ' Abschnitte' . ($same ? ' · Inhalt unverändert' : '');
					$item['actions'] = $existing !== null ? ['COPY', 'REPLACE', 'SKIP'] : ['CREATE', 'SKIP'];
					$item['defaultAction'] = $same ? 'SKIP' : $item['actions'][0];
					break;
				case 'labels':
					$parsed = $this->labels->parseExchange($content);
					$same = self::sameExchange($this->labels->exportExchange(), $content);
					$active = count(array_filter($parsed['labels'], static fn(array $label): bool => $label['active']));
					$item['exists'] = $parsed['existing'] > 0;
					$item['status'] = $parsed['existing'] > 0 ? 'VORHANDEN' : 'NEU';
					$item['details'] = count($parsed['labels']) . ' Marker, davon ' . $parsed['new'] . ' neu und ' . $parsed['existing'] . ' vorhanden (werden aktualisiert); ' . $active . ' aktiv' . ($same ? ' · Inhalt unverändert' : '');
					$item['actions'] = ['UPDATE', 'SKIP']; $item['defaultAction'] = $same ? 'SKIP' : 'UPDATE';
					break;
				case 'document-kinds':
					$parsed = $this->documentKinds->parseExchange($content);
					$same = self::sameExchange($this->documentKinds->exportExchange(), $content);
					$active = count(array_filter($parsed['kinds'], static fn(array $kind): bool => $kind['active']));
					$item['exists'] = $parsed['existing'] > 0;
					$item['status'] = $parsed['existing'] > 0 ? 'VORHANDEN' : 'NEU';
					$item['details'] = count($parsed['kinds']) . ' Dokumentarten, davon ' . $parsed['new'] . ' neu und ' . $parsed['existing'] . ' vorhanden (werden aktualisiert); ' . $active . ' aktiv' . ($same ? ' · Inhalt unverändert' : '');
					$item['actions'] = ['UPDATE', 'SKIP']; $item['defaultAction'] = $same ? 'SKIP' : 'UPDATE';
					break;
				case 'process-template':
					$parsed = $this->process->parseExchange($content);
					$exists = $this->process->templateExists($key);
					$item['exists'] = $exists;
					$item['status'] = $exists ? 'VORHANDEN' : 'NEU';
					$item['warnings'] = $this->process->referenceWarnings($parsed['steps']);
					$same = $exists && self::sameExchange($this->process->exportJson($key)['content'], $content);
					$item['details'] = count($parsed['steps']) . ' Schritte' . ($exists ? ($same ? ' · Inhalt unverändert' : '') : ' · wird inaktiv angelegt');
					$item['actions'] = $exists ? ['COPY', 'REPLACE', 'SKIP'] : ['CREATE', 'SKIP'];
					$item['defaultAction'] = $same ? 'SKIP' : $item['actions'][0];
					break;
				case 'logo':
					BranchLogoService::normalize($content);
					if ($this->configuration->branchByKey($key) === null) { $item['warnings'][] = 'Niederlassung ' . $key . ' fehlt – Logo wird nicht übernommen.'; $item['actions'] = ['SKIP']; break; }
					$exists = isset($this->logos->index()[strtoupper($key)]);
					$item['exists'] = $exists;
					$item['status'] = $exists ? 'VORHANDEN' : 'NEU';
					$same = $exists && $this->logos->logo($key) === $content;
					$item['details'] = 'für Niederlassung ' . $key . ($same ? ' · Bild unverändert' : '');
					$item['actions'] = $exists ? ['REPLACE', 'SKIP'] : ['CREATE', 'SKIP'];
					$item['defaultAction'] = $same ? 'SKIP' : $item['actions'][0];
					break;
			}
		} catch (\Throwable $error) {
			$item['status'] = 'FEHLER'; $item['actions'] = ['SKIP']; $item['defaultAction'] = 'SKIP'; $item['warnings'][] = $error->getMessage();
		}
		$item['actionLabels'] = array_map(static fn(string $action): string => self::ACTION_LABELS[$action], $item['actions']);
		return $item;
	}

	// ---------------------------------------------------------------------------------------------- Einspielen

	/**
	 * @param array<string,string> $decisions Aktion je Eintrag („<index>“ => CREATE|UPDATE|REPLACE|COPY|SKIP); fehlende = Vorschlag
	 * @return array{backup:array,results:list<array>,summary:array<string,int>}
	 */
	public function apply(string $zipContent, array $decisions = []): array {
		$preview = $this->preview($zipContent);
		if ($preview['warnings'] !== []) throw new \InvalidArgumentException(implode(' ', $preview['warnings']));
		[$manifest, $files] = $this->read($zipContent);
		// Erst alle Entscheidungen prüfen, dann sichern und einspielen – eine unzulässige Aktion bricht vor jeder Änderung ab.
		foreach ($preview['items'] as $item) {
			$action = strtoupper((string)($decisions[(string)$item['index']] ?? $item['defaultAction']));
			if (!in_array($action, $item['actions'], true)) throw new \InvalidArgumentException('Die Aktion „' . $action . '“ ist für Eintrag ' . $item['index'] . ' („' . $item['title'] . '“) nicht zulässig. Möglich: ' . implode(', ', $item['actions']) . '.');
		}
		$backup = $this->backup->create();
		$results = []; $summary = ['done' => 0, 'skipped' => 0, 'failed' => 0];
		foreach ($preview['items'] as $item) {
			$action = strtoupper((string)($decisions[(string)$item['index']] ?? $item['defaultAction']));
			$entry = $manifest['contents'][$item['index']];
			$result = ['index' => $item['index'], 'type' => $item['type'], 'typeLabel' => $item['typeLabel'], 'key' => $item['key'], 'title' => $item['title'], 'action' => $action, 'actionLabel' => self::ACTION_LABELS[$action], 'message' => '', 'ok' => true];
			if ($action === 'SKIP') { $summary['skipped']++; $results[] = $result + ['message' => 'übersprungen']; continue; }
			try {
				$result['message'] = $this->applyItem($item, $entry, $files[$entry['file']] ?? '', $action);
				$summary['done']++;
			} catch (\Throwable $error) {
				$result['ok'] = false; $result['message'] = $error->getMessage(); $summary['failed']++;
			}
			$results[] = $result;
		}
		$this->audit->logSystem('SETUP_PACKAGE', 'APPLIED', ['customer' => (string)($manifest['customer'] ?? ''), 'createdAt' => (string)($manifest['createdAt'] ?? ''), 'backup' => $backup['path'] ?? '', 'summary' => $summary,
			'items' => array_map(static fn(array $r): string => $r['type'] . ':' . $r['key'] . '=' . $r['action'] . ($r['ok'] ? '' : '!'), $results)]);
		return ['backup' => ['path' => (string)($backup['path'] ?? '')], 'results' => $results, 'summary' => $summary];
	}

	private function applyItem(array $item, array $entry, string $content, string $action): string {
		$key = (string)$item['key'];
		switch ($item['type']) {
			case 'article-groups':
				$r = $this->structure->importGroupsCsv($content);
				return ($r['created'] ?? 0) . ' angelegt, ' . ($r['updated'] ?? 0) . ' aktualisiert';
			case 'articles':
				$r = $this->articles->importCsv($content);
				return ($r['imported'] ?? 0) . ' Artikel übernommen';
			case 'price-list':
				$list = $this->priceListByKey($key);
				if ($list === null) $list = $this->priceLists->createBranchList((string)$entry['branchKey'], (string)$item['title']);
				$r = $this->priceLists->importCsv((int)$list['id'], $content);
				return ($r['saved'] ?? 0) . ' Preise übernommen, ' . ($r['unchanged'] ?? 0) . ' unverändert';
			case 'document-template':
				return $this->applyDocumentTemplate($key, (array)($entry['template'] ?? []), $content, $action);
			case 'order-form':
				if ($action === 'REPLACE') {
					$existing = $this->orderFormByKey($key) ?? throw new \InvalidArgumentException('Das Auftragsformular wurde nicht gefunden.');
					$data = json_decode($content, true) ?: [];
					$this->orderForms->save((int)$existing['id'], ['name' => (string)($data['name'] ?? $existing['name']), 'description' => (string)($data['description'] ?? ''), 'active' => $existing['active'], 'definition' => (array)($data['definition'] ?? [])]);
					return 'ersetzt (neue Version)';
				}
				$r = $this->orderForms->import($content);
				return 'angelegt als ' . $r['form']['key'];
			case 'labels':
				$r = $this->labels->importExchange($content);
				return $r['created'] . ' angelegt, ' . $r['updated'] . ' aktualisiert';
			case 'document-kinds':
				$r = $this->documentKinds->importExchange($content);
				return $r['created'] . ' angelegt, ' . $r['updated'] . ' aktualisiert';
			case 'process-template':
				$r = $this->process->importJson($content, $action === 'REPLACE' ? 'REPLACE' : 'NEW', $action === 'REPLACE' ? $key : '');
				return $action === 'REPLACE' ? 'Schrittliste ersetzt' : 'angelegt als ' . $r['template']['key'] . ' (inaktiv – nach Prüfung aktivieren)';
			case 'logo':
				$this->logos->save($key, $content);
				return 'Logo gespeichert';
		}
		throw new \InvalidArgumentException('Unbekannte Inhaltsart.');
	}

	private function applyDocumentTemplate(string $key, array $meta, string $content, string $action): string {
		$folder = $this->folders->templatesFolder();
		$fileName = basename((string)($meta['fileName'] ?? ($key . '.docx')));
		if (!preg_match('/\.(docx|txt)$/i', $fileName)) $fileName .= '.docx';
		self::checkTemplateContent($fileName, $content);
		$existing = $this->documentByKey($key);
		$keepCopy = null;
		if ($action === 'COPY' && $existing !== null) {
			$base = substr($key, 0, 80) . '_PAKET'; $newKey = $base; $n = 2;
			while ($this->documentByKey($newKey) !== null) $newKey = $base . '_' . $n++;
			$key = $newKey;
			$fileName = self::uniqueFileName($folder, self::suffixed($fileName, '-paket'));
		} elseif ($action === 'REPLACE' && $existing !== null) {
			// Bisherige Datei als datierte Kopie erhalten, dann unter dem bisherigen Namen ersetzen.
			$current = basename((string)$existing['fileName']);
			if ($folder->nodeExists($current)) {
				$node = $folder->get($current);
				if ($node instanceof File) {
					$keep = self::uniqueFileName($folder, self::suffixed($current, '-vor-' . date('Y-m-d')));
					$folder->newFile($keep)->putContent($node->getContent());
					$keepCopy = $keep;
				}
			}
			$fileName = $current;
		} elseif ($folder->nodeExists($fileName)) {
			$node = $folder->get($fileName);
			if ($node instanceof File && hash('sha256', (string)$node->getContent()) !== hash('sha256', $content)) $fileName = self::uniqueFileName($folder, self::suffixed($fileName, '-paket'));
		}
		if (self::templateExtension($fileName) === 'txt') {
			if ($action !== 'REPLACE' || $existing === null) throw new \InvalidArgumentException('Textvorlagen können nur eine vorhandene Vorlage ersetzen.');
			$node = $folder->nodeExists($fileName) ? $folder->get($fileName) : null;
			if ($node instanceof File) $node->putContent($content); else $folder->newFile($fileName)->putContent($content);
			return 'Text in ' . $fileName . ' ersetzt (vorherige Fassung bleibt als Kopie im Vorlagenordner)';
		}
		$previous = null;
		if ($folder->nodeExists($fileName)) { $node = $folder->get($fileName); if ($node instanceof File) { $previous = (string)$node->getContent(); $node->putContent($content); } }
		else $folder->newFile($fileName)->putContent($content);
		$data = $meta + ['name' => $key, 'category' => 'Allgemein', 'outputSubfolder' => '', 'requiredFields' => [], 'supportsPdf' => true, 'active' => true, 'sortOrder' => 0];
		$data['key'] = $key; $data['fileName'] = $fileName;
		$data['outputSubfolder'] = $this->folders->resolveSubfolder((string)$data['outputSubfolder']) ?? $this->defaultSubfolder();
		if ($action === 'COPY' && $existing !== null) { $data['name'] = (string)$data['name'] . ' (Paket)'; $data['active'] = false; }
		if ($action === 'REPLACE' && $existing !== null) $data = array_replace($data, ['name' => $existing['name'], 'active' => $existing['active'], 'sortOrder' => $existing['sortOrder']]);
		try {
			$this->configuration->saveDocument($data, $existing !== null && $action === 'REPLACE' ? (int)$existing['id'] : 0);
		} catch (\Throwable $error) {
			// Vorlage abgelehnt (z. B. unbekannter Platzhalter): Vorlagenordner in den vorherigen Stand zurücksetzen.
			$node = $folder->nodeExists($fileName) ? $folder->get($fileName) : null;
			if ($node instanceof File) { if ($previous !== null) $node->putContent($previous); else $node->delete(); }
			if ($keepCopy !== null && $folder->nodeExists($keepCopy)) $folder->get($keepCopy)->delete();
			throw $error;
		}
		return match (true) {
			$action === 'REPLACE' => 'Datei ' . $fileName . ' ersetzt (vorherige Fassung bleibt als Kopie im Vorlagenordner)',
			$action === 'COPY' => 'als ' . $key . ' angelegt (inaktiv), Datei ' . $fileName,
			default => 'angelegt, Datei ' . $fileName,
		};
	}

	// ---------------------------------------------------------------------------------------------- Hilfen

	/** @return array{0:array,1:array<string,string>} Manifest und Dateien */
	private function read(string $zipContent): array {
		if ($zipContent === '' || strlen($zipContent) > self::MAX_BYTES) throw new \InvalidArgumentException('Das Paket ist leer oder größer als 50 MB.');
		$tmp = tempnam(sys_get_temp_dir(), 'bestatter-setup-in-');
		if ($tmp === false) throw new \RuntimeException('Temporäre Datei konnte nicht angelegt werden.');
		file_put_contents($tmp, $zipContent);
		$zip = new \ZipArchive();
		if ($zip->open($tmp) !== true) { @unlink($tmp); throw new \InvalidArgumentException('Die Datei ist kein ZIP-Archiv.'); }
		$files = []; $root = '';
		$total = 0;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			if (str_ends_with($name, '/') || str_contains($name, '..') || str_starts_with($name, '/')) continue;
			$stat = $zip->statIndex($i);
			$total += (int)($stat['size'] ?? 0);
			if ($total > 4 * self::MAX_BYTES) { $zip->close(); @unlink($tmp); throw new \InvalidArgumentException('Das Paket ist entpackt zu groß.'); }
			if (basename($name) === 'paket.json' && ($root === '' || strlen(dirname($name)) < strlen($root))) $root = dirname($name) === '.' ? '' : dirname($name) . '/';
			$files[$name] = (string)$zip->getFromIndex($i);
		}
		$zip->close(); @unlink($tmp);
		$manifestRaw = $files[$root . 'paket.json'] ?? null;
		if ($manifestRaw === null) throw new \InvalidArgumentException('Das ZIP-Archiv enthält keine paket.json und ist kein Bestatter-Einrichtungspaket.');
		try { $manifest = json_decode($manifestRaw, true, 64, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new \InvalidArgumentException('paket.json ist keine gültige JSON-Datei.'); }
		if (!is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT) throw new \InvalidArgumentException('paket.json beschreibt kein Bestatter-Einrichtungspaket.');
		if ((int)($manifest['formatVersion'] ?? 0) < 1 || (int)$manifest['formatVersion'] > self::FORMAT_VERSION) throw new \InvalidArgumentException('Das Einrichtungspaket hat ein Format, das diese App-Version nicht kennt. Bitte die App aktualisieren.');
		$contents = [];
		foreach ((array)($manifest['contents'] ?? []) as $entry) {
			if (!is_array($entry) || !in_array($entry['type'] ?? '', self::TYPES, true)) continue;
			$file = $root . ltrim((string)($entry['file'] ?? ''), '/');
			if (!isset($files[$file])) throw new \InvalidArgumentException('Im Paket fehlt die Datei ' . ($entry['file'] ?? '') . '.');
			$entry['file'] = $file;
			$entry['key'] = trim((string)($entry['key'] ?? ''));
			$contents[] = $entry;
		}
		if ($contents === []) throw new \InvalidArgumentException('Das Einrichtungspaket enthält keine verwendbaren Inhalte.');
		$manifest['contents'] = $contents;
		return [$manifest, $files];
	}

	/** @return array<string,true> */
	private function existingValues(string $table, string $column): array {
		$q = $this->db->getQueryBuilder();
		$values = [];
		foreach ($q->select($column)->from($table)->executeQuery()->fetchFirstColumn() as $value) $values[(string)$value] = true;
		return $values;
	}

	private function priceListByKey(string $key): ?array {
		foreach ($this->priceLists->lists() as $list) if (strtoupper((string)$list['key']) === strtoupper($key)) return $list;
		return null;
	}

	/** Zwei Austauschdateien (JSON) inhaltlich gleich – ohne Zeitstempel, App-Version und Aktiv-Kennzeichen. */
	public static function sameExchange(string $a, string $b): bool {
		$strip = static function (string $json): ?array {
			$data = json_decode($json, true);
			if (!is_array($data)) return null;
			unset($data['exportedAt'], $data['appVersion'], $data['active']);
			return $data;
		};
		$left = $strip($a);
		return $left !== null && $left == $strip($b);
	}

	private function templateContent(string $fileName): ?string {
		$folder = $this->folders->templatesFolder();
		$fileName = basename($fileName);
		if ($fileName === '' || !$folder->nodeExists($fileName)) return null;
		$node = $folder->get($fileName);
		return $node instanceof File ? (string)$node->getContent() : null;
	}

	private function defaultSubfolder(): string {
		return (string)($this->folders->allowedSubfolders()[0] ?? '');
	}

	private function documentByKey(string $key): ?array {
		foreach ($this->configuration->documents() as $template) if (strtoupper((string)$template['key']) === strtoupper($key)) return $template;
		return null;
	}

	private function orderFormByKey(string $key): ?array {
		foreach ($this->orderForms->forms() as $form) if (strtoupper((string)$form['key']) === strtoupper($key)) return $form;
		return null;
	}

	private static function uniqueFileName(\OCP\Files\Folder $folder, string $name): string {
		$ext = self::templateExtension($name); $base = substr($name, 0, -strlen($ext) - 1); $candidate = $name; $n = 2;
		while ($folder->nodeExists($candidate)) $candidate = $base . '-' . $n++ . '.' . $ext;
		return $candidate;
	}

	/** Dateiendung einer Vorlage: docx oder txt (Textvorlagen für Abmeldungen). */
	private static function templateExtension(string $name): string {
		return preg_match('/\.txt$/i', $name) ? 'txt' : 'docx';
	}

	private static function suffixed(string $name, string $suffix): string {
		$ext = self::templateExtension($name);
		return preg_replace('/\.' . $ext . '$/i', '', $name) . $suffix . '.' . $ext;
	}

	/** DOCX muss ein ZIP sein, Textvorlagen gültiges UTF-8 ohne Steuerzeichen. */
	public static function checkTemplateContent(string $fileName, string $content): void {
		if (self::templateExtension($fileName) === 'txt') {
			if (!mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) throw new \InvalidArgumentException('Die Textvorlage ist keine gültige UTF-8-Textdatei.');
			return;
		}
		if (!str_starts_with($content, "PK")) throw new \InvalidArgumentException('Die Datei ist keine DOCX-Datei.');
	}

	private static function fileSafe(string $value): string {
		$value = strtr($value, ['Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
		return trim((string)preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value), '-') ?: 'paket';
	}
}
