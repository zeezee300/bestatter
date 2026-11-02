<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\Files\File;
use OCP\IDBConnection;

/**
 * 0.75.3 – Kundenspezifisches Einrichtungspaket (OP-063): ZIP mit paket.json und den Inhalten, die zu einem
 * Bestattungsunternehmen gehören – Warengruppen, Leistungskatalog, Preislisten, Dokumentvorlagen (DOCX und Textvorlagen),
 * Auftragsformulare, Ablaufvorlagen, Niederlassungen (0.89.8, Stammdaten ohne Bankverbindung) und Logos je Niederlassung.
 * Keine Falldaten, keine Zugangs- oder Bankdaten.
 *
 * Einspielen in zwei Schritten: preview() zeigt je Eintrag den Stand (neu/vorhanden) und die vorgeschlagene Aktion;
 * apply() führt die gewählten Aktionen aus – nach einer automatischen Sicherung der App-Daten, in fester Reihenfolge
 * (Katalog vor Formularen und Abläufen) und mit Audit-Eintrag. Vorhandenes wird nie stillschweigend geändert.
 */
class SetupPackageService {
	public const FORMAT = 'bestatter-setup-package';
	public const FORMAT_VERSION = 1;
	public const MAX_BYTES = 50 * 1024 * 1024;
	/** 0.89.5: Einstellungen mit Verweisen auf den Katalog (Ankreuzfelder, typische Fremdkosten, Variantenregeln, Zuschlagsstaffeln) nach dem Katalog. */
	/** 0.89.7: Auftragsarten (Aufträge ohne Sterbefall) vor den Abläufen, die für Aufträge gelten. */
	/** 0.89.8: Niederlassungen zuerst – Preislisten und Logos verweisen auf sie. */
	public const TYPES = ['branches', 'article-groups', 'articles', 'price-list', 'document-template', 'order-form', 'labels', 'document-kinds', 'order-types', 'process-template', 'order-choices', 'typical-costs', 'variant-rules', 'surcharge-rules', 'logo'];
	public const TYPE_LABELS = [
		'article-groups' => 'Warengruppen', 'articles' => 'Leistungskatalog (Artikel)', 'price-list' => 'Preisliste', 'document-template' => 'Dokumentvorlage',
		'order-form' => 'Auftragsformular', 'labels' => 'Marker', 'document-kinds' => 'Dokumentarten', 'process-template' => 'Ablaufvorlage',
		'order-choices' => 'Ankreuzfelder (Auftrag)', 'typical-costs' => 'Typische Fremdkosten', 'variant-rules' => 'Variantenregeln', 'surcharge-rules' => 'Zuschlagsstaffeln', 'logo' => 'Logo',
		'order-types' => 'Auftragsarten', 'branches' => 'Niederlassungen',
	];
	public const BRANCH_FORMAT = 'bestatter-branches';
	/** 0.89.8: übertragene Felder einer Niederlassung – bewusst ohne Bankverbindung und ohne zugeordnete Personen. */
	public const BRANCH_FIELDS = ['key', 'name', 'street', 'postalCode', 'city', 'country', 'countryCode', 'federalState', 'invoiceProfile', 'paymentQrStandard',
		'phone', 'email', 'paymentTermDays', 'vatId', 'taxNumber', 'registerCourt', 'registerNumber', 'managingDirectors', 'electronicAddress', 'active', 'sortOrder'];
	/** Dateien der Einstellungen im Paket. */
	private const SETTING_FILES = ['order-choices' => ['ORDER_CHOICES', 'einstellungen/Ankreuzfelder.json'], 'typical-costs' => ['TYPICAL_COSTS', 'einstellungen/Fremdkosten.json'],
		'variant-rules' => ['VARIANT_RULES', 'einstellungen/Variantenregeln.json'], 'surcharge-rules' => ['SURCHARGE_RULES', 'einstellungen/Zuschlagsstaffeln.json'],
		'order-types' => ['ORDER_TYPES', 'einstellungen/Auftragsarten.json']];
	public const ACTION_LABELS = ['CREATE' => 'Neu anlegen', 'UPDATE' => 'Aktualisieren', 'REPLACE' => 'Ersetzen', 'COPY' => 'Als Kopie anlegen', 'SKIP' => 'Überspringen'];
	/** Diese Dokumentvorlagen verwendet die App fest unter ihrem Schlüssel; eine Kopie würde nicht benutzt. */
	private const SYSTEM_DOCUMENT_KEYS = ['RECHNUNG', 'BESTATTUNGSAUFTRAG', 'KOSTENVORANSCHLAG', 'BESTATTUNGSVOLLMACHT', 'WIDERRUFSBELEHRUNG', 'KORREKTUR', 'AUFTRAG', 'VORSORGEVERTRAG'];

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
		private OrderChoiceService $orderChoices,
		private TypicalCostService $typicalCosts,
		private BurialVariantRuleService $variantRules,
		private SurchargeRuleService $surcharges,
		private CatalogReferenceService $references,
		private ?OrderTypeService $orderTypes = null,
	) {
	}

	// ---------------------------------------------------------------------------------------------- Export

	/**
	 * @param list<string> $types Inhaltsarten (leer = alle)
	 * @return array{fileName:string,content:string,contents:list<array>}
	 */
	public function export(string $customer = '', array $types = []): array {
		$types = $types === [] ? self::TYPES : array_values(array_intersect(self::TYPES, $types));
		$tmp = tempnam(sys_get_temp_dir(), 'bestatter-setup-');
		if ($tmp === false) {
			throw new \RuntimeException('Temporäre Datei für das Einrichtungspaket konnte nicht angelegt werden.');
		}
		$zip = new \ZipArchive();
		if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('Das Einrichtungspaket konnte nicht angelegt werden.');
		}
		$contents = [];
		$add = function (string $type, string $key, string $title, string $file, string $content, array $extra = []) use ($zip, &$contents): void {
			$zip->addFromString($file, $content);
			$contents[] = ['type' => $type, 'key' => $key, 'title' => $title, 'file' => $file] + $extra;
		};
		if (in_array('branches', $types, true) && $this->configuration->branches() !== []) {
			$add('branches', 'BRANCHES', 'Niederlassungen', 'niederlassungen/Niederlassungen.json', $this->branchesExport());
		}
		if (in_array('article-groups', $types, true)) {
			$add('article-groups', 'GROUPS', 'Warengruppen', 'leistungskatalog/Warengruppen.csv', $this->structure->exportGroupsCsv());
		}
		if (in_array('articles', $types, true)) {
			$add('articles', 'ARTICLES', 'Leistungskatalog', 'leistungskatalog/Artikel.csv', $this->articles->exportCsv());
		}
		if (in_array('price-list', $types, true)) {
			foreach ($this->priceLists->lists() as $list) {
				$add('price-list', (string)$list['key'], (string)$list['name'], 'preislisten/' . self::fileSafe((string)$list['key']) . '.csv', $this->priceLists->exportCsv((int)$list['id']), ['branchKey' => $list['branchKey'], 'standard' => (bool)$list['standard']]);
			}
		}
		if (in_array('document-template', $types, true)) {
			$folder = $this->folders->templatesFolder();
			foreach ($this->configuration->documents() as $template) {
				$fileName = basename((string)$template['fileName']);
				if ($fileName === '' || !$folder->nodeExists($fileName)) {
					continue;
				}
				$node = $folder->get($fileName);
				if (!$node instanceof File) {
					continue;
				}
				$meta = array_intersect_key($template, array_flip(['key', 'name', 'category', 'description', 'fileName', 'requiredFields', 'outputSubfolder', 'supportsPdf', 'active', 'sortOrder']));
				$add('document-template', (string)$template['key'], (string)$template['name'], 'dokumentvorlagen/' . $fileName, (string)$node->getContent(), ['template' => $meta]);
			}
		}
		if (in_array('order-form', $types, true)) {
			foreach ($this->orderForms->forms() as $form) {
				$file = $this->orderForms->export((int)$form['id']);
				$add('order-form', (string)$form['key'], (string)$form['name'], 'auftragsformulare/' . $file['filename'], $file['content']);
			}
		}
		// 0.76.0: alle Marker-Definitionen mit Aktiv-Schalter (so wird die Auswahl einer eingerichteten Installation übertragen).
		if (in_array('labels', $types, true)) {
			$add('labels', 'LABELS', 'Marker', 'marker/Marker.json', $this->labels->exportExchange());
		}
		// 0.80.0: Dokumentarten (vor den Abläufen, die sie als Auslöser verwenden).
		if (in_array('document-kinds', $types, true)) {
			$add('document-kinds', 'DOCUMENT_KINDS', 'Dokumentarten', 'dokumentarten/Dokumentarten.json', $this->documentKinds->exportExchange());
		}
		if (in_array('process-template', $types, true)) {
			foreach ($this->process->templates() as $template) {
				$file = $this->process->exportJson((string)$template['key']);
				$add('process-template', (string)$template['key'], (string)$template['name'], 'ablaufvorlagen/' . $file['fileName'], $file['content']);
			}
		}
		foreach (self::SETTING_FILES as $type => [$key, $file]) {
			if (in_array($type, $types, true)) {
				$add($type, $key, self::TYPE_LABELS[$type], $file, $this->settingExport($type));
			}
		}
		if (in_array('logo', $types, true)) {
			foreach (array_keys($this->logos->index()) as $branchKey) {
				$png = $this->logos->logo((string)$branchKey);
				if ($png !== null) {
					$add('logo', (string)$branchKey, 'Logo ' . $branchKey, 'logos/' . self::fileSafe((string)$branchKey) . '.png', $png);
				}
			}
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
		$catalog = $this->catalogAfterImport($manifest, $files);
		$items = [];
		foreach ($manifest['contents'] as $index => $entry) {
			$items[] = $this->previewItem($index, $entry, $files, $catalog);
		}
		usort($items, static fn (array $a, array $b): int => [array_search($a['type'], self::TYPES, true), $a['index']] <=> [array_search($b['type'], self::TYPES, true), $b['index']]);
		$warnings = [];
		if (version_compare(Application::VERSION, (string)($manifest['minAppVersion'] ?? '0'), '<')) {
			$warnings[] = 'Das Paket wurde für Bestatter ' . $manifest['minAppVersion'] . ' erstellt; installiert ist ' . Application::VERSION . '. Bitte zuerst die App aktualisieren.';
		}
		return ['manifest' => array_diff_key($manifest, ['contents' => true]) + ['itemCount' => count($items)], 'items' => $items, 'warnings' => $warnings];
	}

	/** @param array{groups:array<string,bool>,articles:array<string,bool>} $catalog Warengruppen und Artikelnummern nach dem Einspielen */
	private function previewItem(int $index, array $entry, array $files, array $catalog): array {
		$type = (string)$entry['type'];
		$key = (string)$entry['key'];
		$content = $files[$entry['file']] ?? '';
		$item = ['index' => $index, 'type' => $type, 'typeLabel' => self::TYPE_LABELS[$type], 'key' => $key, 'title' => (string)($entry['title'] ?? $key), 'file' => (string)$entry['file'],
			'exists' => false, 'status' => 'NEU', 'details' => '', 'actions' => [], 'defaultAction' => 'SKIP', 'warnings' => []];
		try {
			switch ($type) {
				case 'branches':
					$branches = self::parseBranches($content);
					$new = $changed = $same = 0;
					foreach ($branches as $branch) {
						$existing = $this->configuration->branchByKey($branch['key']);
						if ($existing === null) {
							$new++;
						} elseif (self::sameBranch($existing, $branch)) {
							$same++;
						} else {
							$changed++;
						}
					}
					$item['count'] = count($branches);
					$item['exists'] = $changed + $same > 0;
					$item['status'] = $item['exists'] ? 'VORHANDEN' : 'NEU';
					$item['details'] = count($branches) . ' Niederlassung(en): ' . $new . ' neu, ' . $changed . ' geändert, ' . $same . ' unverändert. Bankverbindung und zugeordnete Personen werden nicht übertragen; vorhandene bleiben erhalten.';
					$item['actions'] = ['UPDATE', 'SKIP'];
					$item['defaultAction'] = $new + $changed > 0 ? 'UPDATE' : 'SKIP';
					if ($new > 0) {
						$item['warnings'][] = 'Bankverbindung der neuen Niederlassung(en) danach unter Administration → Niederlassungen bzw. in der Ersteinrichtung eintragen.';
					}
					break;
				case 'article-groups':
				case 'articles':
					$column = $type === 'articles' ? 'artikelnummer' : 'schluessel';
					$keys = [];
					foreach (CsvTools::read($content, static fn (string $header): string => CsvTools::headerKey($header)) as $row) {
						$keys[] = trim((string)($row['values'][$column] ?? ''));
					}
					$existing = $type === 'articles' ? $this->existingValues('bestatter_articles', 'article_number') : $this->existingValues('bestatter_article_groups', 'group_key');
					$known = count(array_filter($keys, static fn (string $k): bool => isset($existing[$k])));
					$item += ['count' => count($keys)];
					$item['exists'] = $known > 0;
					$item['status'] = $known > 0 ? 'VORHANDEN' : 'NEU';
					$item['details'] = count($keys) . ' Einträge, davon ' . (count($keys) - $known) . ' neu und ' . $known . ' vorhanden (werden aktualisiert).';
					$item['actions'] = ['UPDATE', 'SKIP'];
					$item['defaultAction'] = 'UPDATE';
					break;
				case 'price-list':
					$list = $this->priceListByKey($key);
					$item['exists'] = $list !== null;
					$branchKey = (string)($entry['branchKey'] ?? '');
					if ($list === null && $branchKey !== '' && $this->configuration->branchByKey($branchKey) === null && !isset($catalog['branches'][strtoupper($branchKey)])) {
						$item['warnings'][] = 'Niederlassung ' . $branchKey . ' fehlt – Preisliste kann nicht angelegt werden.';
						$item['actions'] = ['SKIP'];
						break;
					}
					$item['status'] = $list !== null ? 'VORHANDEN' : 'NEU';
					$item['details'] = $list !== null ? 'Preise werden in „' . $list['name'] . '“ übernommen (neuer Preis gilt ab dem angegebenen Datum).' : 'Preisliste der Niederlassung ' . $branchKey . ' wird angelegt.';
					$item['actions'] = $list !== null ? ['UPDATE', 'SKIP'] : ['CREATE', 'SKIP'];
					$item['defaultAction'] = $item['actions'][0];
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
					if (in_array($key, self::SYSTEM_DOCUMENT_KEYS, true) && !$same) {
						$item['warnings'][] = 'Die App verwendet diese Vorlage fest unter dem Schlüssel ' . $key . '. Damit das Layout wirkt, „Ersetzen“ wählen; die bisherige Datei bleibt als Kopie im Vorlagenordner.';
					}
					if (!$isText && $this->folders->resolveSubfolder((string)($meta['outputSubfolder'] ?? '')) === null) {
						$item['warnings'][] = 'Den Ablageordner „' . (string)($meta['outputSubfolder'] ?? '') . '“ gibt es in dieser Installation nicht – Dokumente werden in „' . $this->defaultSubfolder() . '“ abgelegt. Bitte danach unter Customizing → Dokumentvorlagen prüfen.';
					}
					if ($isText) {
						// Textvorlagen (Abmeldungen) sind feste Vorlagen der App: nur der Text einer vorhandenen Vorlage kann ersetzt werden.
						if ($existing === null) {
							$item['warnings'][] = 'Textvorlagen können nur eine vorhandene Vorlage gleichen Schlüssels ersetzen.';
							$item['actions'] = ['SKIP'];
							$item['defaultAction'] = 'SKIP';
							break;
						}
						$item['actions'] = ['REPLACE', 'SKIP'];
					} else {
						$item['actions'] = $existing !== null ? ['REPLACE', 'COPY', 'SKIP'] : ['CREATE', 'SKIP'];
					}
					$item['defaultAction'] = $existing === null ? 'CREATE' : ($same || $isText ? 'SKIP' : 'COPY');
					if ($same) {
						$item['details'] .= ' · Inhalt unverändert';
					}
					break;
				case 'order-form':
					$data = json_decode($content, true) ?: [];
					if (($data['format'] ?? '') !== OrderFormService::EXCHANGE_FORMAT) {
						throw new \InvalidArgumentException('Kein Auftragsformular im erwarteten Format.');
					}
					$existing = $this->orderFormByKey($key);
					$item['exists'] = $existing !== null;
					$item['status'] = $existing !== null ? 'VORHANDEN' : 'NEU';
					$item['warnings'] = $this->orderForms->catalogWarnings((array)($data['definition'] ?? []), $catalog['groups'], $catalog['articles']);
					$same = $existing !== null && self::sameExchange($this->orderForms->export((int)$existing['id'])['content'], $content);
					$item['details'] = count((array)($data['definition']['sections'] ?? [])) . ' Abschnitte' . ($same ? ' · Inhalt unverändert' : '');
					$item['actions'] = $existing !== null ? ['COPY', 'REPLACE', 'SKIP'] : ['CREATE', 'SKIP'];
					$item['defaultAction'] = $same ? 'SKIP' : $item['actions'][0];
					break;
				case 'labels':
					$parsed = $this->labels->parseExchange($content);
					$same = self::sameExchange($this->labels->exportExchange(), $content);
					$active = count(array_filter($parsed['labels'], static fn (array $label): bool => $label['active']));
					$item['exists'] = $parsed['existing'] > 0;
					$item['status'] = $parsed['existing'] > 0 ? 'VORHANDEN' : 'NEU';
					$item['details'] = count($parsed['labels']) . ' Marker, davon ' . $parsed['new'] . ' neu und ' . $parsed['existing'] . ' vorhanden (werden aktualisiert); ' . $active . ' aktiv' . ($same ? ' · Inhalt unverändert' : '');
					$item['actions'] = ['UPDATE', 'SKIP'];
					$item['defaultAction'] = $same ? 'SKIP' : 'UPDATE';
					break;
				case 'document-kinds':
					$parsed = $this->documentKinds->parseExchange($content);
					$same = self::sameExchange($this->documentKinds->exportExchange(), $content);
					$active = count(array_filter($parsed['kinds'], static fn (array $kind): bool => $kind['active']));
					$item['exists'] = $parsed['existing'] > 0;
					$item['status'] = $parsed['existing'] > 0 ? 'VORHANDEN' : 'NEU';
					$item['details'] = count($parsed['kinds']) . ' Dokumentarten, davon ' . $parsed['new'] . ' neu und ' . $parsed['existing'] . ' vorhanden (werden aktualisiert); ' . $active . ' aktiv' . ($same ? ' · Inhalt unverändert' : '');
					$item['actions'] = ['UPDATE', 'SKIP'];
					$item['defaultAction'] = $same ? 'SKIP' : 'UPDATE';
					break;
				case 'process-template':
					$parsed = $this->process->parseExchange($content);
					$exists = $this->process->templateExists($key);
					$item['exists'] = $exists;
					$item['status'] = $exists ? 'VORHANDEN' : 'NEU';
					$item['warnings'] = $this->process->referenceWarnings($parsed['steps']);
					// 0.89.7: über die normalisierte Fassung vergleichen – ältere Pakete haben kein „caseKinds“ (= Sterbefall), ältere Vorlagen in der
					// Datenbank nicht alle Felder mit Standardwert. Sonst schlug die Vorschau für inhaltsgleiche Abläufe „Kopie anlegen“ vor.
					$installed = $exists ? $this->process->comparableExchange($this->process->exportJson($key)['content']) : null;
					$incoming = $this->process->comparableExchange($content);
					$same = $installed !== null && self::sameProcessTemplate($installed, $incoming);
					$differences = $installed !== null && !$same ? self::processTemplateDifferences($installed, $incoming) : [];
					$item['details'] = count($parsed['steps']) . ' Schritte' . ($exists ? ($same ? ' · Inhalt unverändert' : ' · weicht ab: ' . implode(', ', $differences)) : ($parsed['active'] ? ' · wird aktiv angelegt' : ' · wird inaktiv angelegt'));
					$item['actions'] = $exists ? ['COPY', 'REPLACE', 'SKIP'] : ['CREATE', 'SKIP'];
					$item['defaultAction'] = $same ? 'SKIP' : $item['actions'][0];
					break;
				case 'order-choices':
				case 'typical-costs':
				case 'variant-rules':
				case 'surcharge-rules':
				case 'order-types':
					$this->previewSetting($item, $type, $content, $catalog);
					break;
				case 'logo':
					BranchLogoService::normalize($content);
					if ($this->configuration->branchByKey($key) === null && !isset($catalog['branches'][strtoupper($key)])) {
						$item['warnings'][] = 'Niederlassung ' . $key . ' fehlt – Logo wird nicht übernommen.';
						$item['actions'] = ['SKIP'];
						break;
					}
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
			$item['status'] = 'FEHLER';
			$item['actions'] = ['SKIP'];
			$item['defaultAction'] = 'SKIP';
			$item['warnings'][] = $error->getMessage();
		}
		$item['actionLabels'] = array_map(static fn (string $action): string => self::ACTION_LABELS[$action], $item['actions']);
		return $item;
	}

	// ---------------------------------------------------------------------------------------------- Einspielen

	/**
	 * @param array<string,string> $decisions Aktion je Eintrag („<index>“ => CREATE|UPDATE|REPLACE|COPY|SKIP); fehlende = Vorschlag
	 * @return array{backup:array,results:list<array>,summary:array<string,int>,references:array}
	 */
	public function apply(string $zipContent, array $decisions = []): array {
		$preview = $this->preview($zipContent);
		if ($preview['warnings'] !== []) {
			throw new \InvalidArgumentException(implode(' ', $preview['warnings']));
		}
		[$manifest, $files] = $this->read($zipContent);
		// Erst alle Entscheidungen prüfen, dann sichern und einspielen – eine unzulässige Aktion bricht vor jeder Änderung ab.
		foreach ($preview['items'] as $item) {
			$action = strtoupper((string)($decisions[(string)$item['index']] ?? $item['defaultAction']));
			if (!in_array($action, $item['actions'], true)) {
				throw new \InvalidArgumentException('Die Aktion „' . $action . '“ ist für Eintrag ' . $item['index'] . ' („' . $item['title'] . '“) nicht zulässig. Möglich: ' . implode(', ', $item['actions']) . '.');
			}
		}
		$backup = $this->backup->create();
		$results = [];
		$summary = ['done' => 0, 'skipped' => 0, 'failed' => 0];
		foreach ($preview['items'] as $item) {
			$action = strtoupper((string)($decisions[(string)$item['index']] ?? $item['defaultAction']));
			$entry = $manifest['contents'][$item['index']];
			$result = ['index' => $item['index'], 'type' => $item['type'], 'typeLabel' => $item['typeLabel'], 'key' => $item['key'], 'title' => $item['title'], 'action' => $action, 'actionLabel' => self::ACTION_LABELS[$action], 'message' => '', 'ok' => true];
			if ($action === 'SKIP') {
				$summary['skipped']++;
				$results[] = $result + ['message' => 'übersprungen'];
				continue;
			}
			try {
				$result['message'] = $this->applyItem($item, $entry, $files[$entry['file']] ?? '', $action);
				$summary['done']++;
			} catch (\Throwable $error) {
				$result['ok'] = false;
				$result['message'] = $error->getMessage();
				$summary['failed']++;
			}
			$results[] = $result;
		}
		$this->audit->logSystem('SETUP_PACKAGE', 'APPLIED', ['customer' => (string)($manifest['customer'] ?? ''), 'createdAt' => (string)($manifest['createdAt'] ?? ''), 'backup' => $backup['path'] ?? '', 'summary' => $summary,
			'items' => array_map(static fn (array $r): string => $r['type'] . ':' . $r['key'] . '=' . $r['action'] . ($r['ok'] ? '' : '!'), $results)]);
		return ['backup' => ['path' => (string)($backup['path'] ?? '')], 'results' => $results, 'summary' => $summary, 'references' => $this->references->check()];
	}

	private function applyItem(array $item, array $entry, string $content, string $action): string {
		$key = (string)$item['key'];
		switch ($item['type']) {
			case 'branches':
				$created = $updated = 0;
				foreach (self::parseBranches($content) as $branch) {
					$existing = $this->configuration->branchByKey($branch['key']);
					$data = array_merge($existing ?? [], $branch);
					unset($data['id']);
					$this->configuration->saveBranch($data, (int)($existing['id'] ?? 0));
					$existing === null ? $created++ : $updated++;
				}
				return $created . ' angelegt, ' . $updated . ' aktualisiert (ohne Bankverbindung)';
			case 'article-groups':
				$r = $this->structure->importGroupsCsv($content);
				return ($r['created'] ?? 0) . ' angelegt, ' . ($r['updated'] ?? 0) . ' aktualisiert' . (($r['areasCreated'] ?? 0) > 0 ? ', ' . $r['areasCreated'] . ' Bereiche angelegt' : '');
			case 'articles':
				$r = $this->articles->importCsv($content);
				return ($r['imported'] ?? 0) . ' Artikel übernommen';
			case 'price-list':
				$list = $this->priceListByKey($key);
				if ($list === null) {
					$list = $this->priceLists->createBranchList((string)$entry['branchKey'], (string)$item['title']);
				}
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
				// 0.89.5: Neu angelegte Vorlagen übernehmen Aktiv-Status und Reihenfolge des Pakets; eine Kopie bleibt inaktiv.
				$r = $this->process->importJson($content, $action === 'REPLACE' ? 'REPLACE' : 'NEW', $action === 'REPLACE' ? $key : '', $action === 'CREATE');
				if ($action === 'REPLACE') {
					return 'Schrittliste ersetzt';
				}
				return 'angelegt als ' . $r['template']['key'] . ($r['template']['active'] ? ' (aktiv)' : ' (inaktiv – nach Prüfung aktivieren)');
			case 'order-choices':
				$r = $this->orderChoices->importExchange($content);
				return $r['created'] . ' angelegt, ' . $r['updated'] . ' aktualisiert';
			case 'typical-costs':
				$r = $this->typicalCosts->importExchange($content);
				return $r['entries'] . ' typische Fremdkosten übernommen' . ($action === 'REPLACE' ? ' (bisherige Liste ersetzt)' : '');
			case 'variant-rules':
				$r = $this->variantRules->importExchange($content);
				return $r['created'] . ' Regeln übernommen' . ($action === 'REPLACE' ? ' (bisherige ersetzt)' : '') . ($r['skipped'] !== [] ? '; übersprungen: ' . implode('; ', $r['skipped']) : '');
			case 'surcharge-rules':
				$r = $this->surcharges->importExchange($content);
				return $r['created'] . ' angelegt, ' . $r['updated'] . ' aktualisiert' . ($r['skipped'] !== [] ? '; Hinweise: ' . implode('; ', $r['skipped']) : '');
			case 'order-types':
				$r = ($this->orderTypes ?? throw new \RuntimeException('Auftragsarten sind nicht verfügbar.'))->importExchange($content);
				return $r['types'] . ' Auftragsarten eingerichtet';
			case 'logo':
				$this->logos->save($key, $content);
				return 'Logo gespeichert';
		}
		throw new \InvalidArgumentException('Unbekannte Inhaltsart.');
	}

	private function settingExport(string $type): string {
		return match ($type) {
			'order-choices' => $this->orderChoices->exportExchange(),
			'typical-costs' => $this->typicalCosts->exportExchange(),
			'variant-rules' => $this->variantRules->exportExchange(),
			'surcharge-rules' => $this->surcharges->exportExchange(),
			'order-types' => ($this->orderTypes ?? throw new \RuntimeException('Auftragsarten sind nicht verfügbar.'))->exportExchange(),
			default => throw new \InvalidArgumentException('Unbekannte Einstellung.'),
		};
	}

	/**
	 * Vorschau einer Einstellung: Umfang, Vergleich mit dem Stand dieser Installation und Verweise, die nach dem Einspielen
	 * (Katalog dieser Installation plus Warengruppen und Artikel des Pakets) kein Ziel hätten.
	 */
	private function previewSetting(array &$item, string $type, string $content, array $catalog): void {
		$same = self::sameExchange($this->settingExport($type), $content);
		$hasGroup = static fn (string $key): bool => isset($catalog['groups'][strtoupper(trim($key))]);
		$hasArticle = static fn (string $number): bool => isset($catalog['articles'][strtoupper(trim($number))]);
		$missing = [];
		switch ($type) {
			case 'order-choices':
				$fields = OrderChoiceService::parseExchange($content);
				$current = count($this->orderChoices->definitions());
				foreach ($fields as $field) {
					if (($field['prefill'] ?? '') === 'RULE' && !empty($field['active'])) {
						foreach ((array)($field['groups'] ?? []) as $group) {
							if (!$hasGroup((string)$group)) {
								$missing[] = '„' . ($field['label'] ?? $field['key'] ?? '') . '“: Warengruppe ' . $group;
							}
						}
					}
				}
				$item['details'] = count($fields) . ' Ankreuzfelder (bisher ' . $current . '); gleiche Schlüssel werden aktualisiert, eigene Felder bleiben';
				$item['exists'] = true;
				$item['status'] = 'VORHANDEN';
				$item['actions'] = ['UPDATE', 'SKIP'];
				break;
			case 'typical-costs':
				$parsed = TypicalCostService::parseExchange($content);
				$current = count($this->typicalCosts->stored()['entries']);
				foreach ($parsed['entries'] as $entry) {
					if (!$hasArticle($entry['article'])) {
						$missing[] = 'Artikel ' . $entry['article'];
					}
				}
				$item['details'] = count($parsed['entries']) . ' typische Fremdkosten, Abweichungsschwelle ' . $parsed['deviationPercent'] . ' %' . ($current > 0 ? ' · ersetzt die bisherigen ' . $current : '');
				$item['exists'] = $current > 0;
				$item['status'] = $current > 0 ? 'VORHANDEN' : 'NEU';
				$item['actions'] = $current > 0 ? ['REPLACE', 'SKIP'] : ['CREATE', 'SKIP'];
				break;
			case 'variant-rules':
				$rules = BurialVariantRuleService::parseExchange($content);
				$current = count($this->variantRules->all());
				foreach ($rules as $rule) {
					if (($rule['articleGroup'] ?? '') !== '' && !$hasGroup((string)$rule['articleGroup'])) {
						$missing[] = 'Regel ' . ($rule['variantCode'] ?? '') . ': Warengruppe ' . $rule['articleGroup'];
					}
					foreach (['article', 'defaultArticle'] as $field) {
						if (($rule[$field] ?? '') !== '' && !$hasArticle((string)$rule[$field])) {
							$missing[] = 'Regel ' . ($rule['variantCode'] ?? '') . ': Artikel ' . $rule[$field];
						}
					}
				}
				$item['details'] = count($rules) . ' Regeln' . ($current > 0 ? ' · ersetzt die bisherigen ' . $current : '');
				$item['exists'] = $current > 0;
				$item['status'] = $current > 0 ? 'VORHANDEN' : 'NEU';
				$item['actions'] = $rules === [] && $current === 0 ? ['SKIP'] : ($current > 0 ? ['REPLACE', 'SKIP'] : ['CREATE', 'SKIP']);
				break;
			case 'order-types':
				$types = OrderTypeService::parseExchange($content);
				$current = array_column($this->orderTypes?->all() ?? [], null, 'key');
				$new = count(array_filter($types, static fn (array $type): bool => !isset($current[$type['key']])));
				foreach ($types as $type) {
					foreach ($type['articleGroups'] as $group) {
						if (!$hasGroup($group)) {
							$missing[] = '„' . $type['label'] . '“: Warengruppe ' . $group;
						}
					}
				}
				$item['details'] = count($types) . ' Auftragsarten, davon ' . $new . ' neu; gleiche Schlüssel werden aktualisiert, eigene Arten bleiben';
				$item['exists'] = count($types) > $new;
				$item['status'] = $item['exists'] ? 'VORHANDEN' : 'NEU';
				$item['actions'] = ['UPDATE', 'SKIP'];
				break;
			case 'surcharge-rules':
				$rules = SurchargeRuleService::parseExchange($content);
				$current = array_column($this->surcharges->all(), null, 'ruleKey');
				$new = count(array_filter($rules, static fn (array $rule): bool => !isset($current[strtoupper((string)($rule['ruleKey'] ?? ''))])));
				foreach ($rules as $rule) {
					if (($rule['article'] ?? '') !== '' && !$hasArticle((string)$rule['article'])) {
						$missing[] = '„' . ($rule['label'] ?? '') . '“: Artikel ' . $rule['article'];
					}
				}
				$item['details'] = count($rules) . ' Staffeln, davon ' . $new . ' neu und ' . (count($rules) - $new) . ' vorhanden (werden aktualisiert)';
				$item['exists'] = count($rules) > $new;
				$item['status'] = $item['exists'] ? 'VORHANDEN' : 'NEU';
				$item['actions'] = ['UPDATE', 'SKIP'];
				break;
		}
		if ($same) {
			$item['details'] .= ' · Inhalt unverändert';
		}
		$item['defaultAction'] = $same ? 'SKIP' : $item['actions'][0];
		if ($missing !== []) {
			$item['warnings'][] = 'Ohne Ziel im Leistungskatalog (wirkt erst, wenn es sie gibt): ' . implode(', ', array_slice(array_values(array_unique($missing)), 0, 12)) . (count(array_unique($missing)) > 12 ? ' …' : '') . '.';
		}
	}

	/** Warengruppen-Schlüssel und Artikelnummern dieser Installation zuzüglich derer im Paket. @return array{groups:array<string,bool>,articles:array<string,bool>} */
	private function catalogAfterImport(array $manifest, array $files): array {
		$branches = [];
		foreach ($manifest['contents'] as $entry) {
			if (($entry['type'] ?? '') === 'branches') {
				try {
					foreach (self::parseBranches($files[$entry['file']] ?? '') as $branch) {
						$branches[$branch['key']] = true;
					}
				} catch (\Throwable) { /* Fehler zeigt die Vorschau des Eintrags */
				}
			}
		}
		$groups = array_change_key_case($this->existingValues('bestatter_article_groups', 'group_key'), CASE_UPPER);
		$articles = array_change_key_case($this->existingValues('bestatter_articles', 'article_number'), CASE_UPPER);
		foreach ($manifest['contents'] as $entry) {
			$type = (string)($entry['type'] ?? '');
			if ($type !== 'article-groups' && $type !== 'articles') {
				continue;
			}
			try {
				foreach (CsvTools::read($files[$entry['file']] ?? '', static fn (string $header): string => CsvTools::headerKey($header)) as $row) {
					$value = strtoupper(trim((string)($row['values'][$type === 'articles' ? 'artikelnummer' : 'schluessel'] ?? '')));
					if ($value !== '') {
						if ($type === 'articles') {
							$articles[$value] = true;
						} else {
							$groups[$value] = true;
						}
					}
				}
			} catch (\Throwable) { /* Fehler zeigt die Vorschau des Eintrags */
			}
		}
		return ['groups' => array_map(static fn (): bool => true, $groups), 'articles' => array_map(static fn (): bool => true, $articles), 'branches' => $branches];
	}

	private function applyDocumentTemplate(string $key, array $meta, string $content, string $action): string {
		$folder = $this->folders->templatesFolder();
		$fileName = basename((string)($meta['fileName'] ?? ($key . '.docx')));
		if (!preg_match('/\.(docx|txt)$/i', $fileName)) {
			$fileName .= '.docx';
		}
		self::checkTemplateContent($fileName, $content);
		$existing = $this->documentByKey($key);
		$keepCopy = null;
		if ($action === 'COPY' && $existing !== null) {
			$base = substr($key, 0, 80) . '_PAKET';
			$newKey = $base;
			$n = 2;
			while ($this->documentByKey($newKey) !== null) {
				$newKey = $base . '_' . $n++;
			}
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
			if ($node instanceof File && hash('sha256', (string)$node->getContent()) !== hash('sha256', $content)) {
				$fileName = self::uniqueFileName($folder, self::suffixed($fileName, '-paket'));
			}
		}
		if (self::templateExtension($fileName) === 'txt') {
			if ($action !== 'REPLACE' || $existing === null) {
				throw new \InvalidArgumentException('Textvorlagen können nur eine vorhandene Vorlage ersetzen.');
			}
			$node = $folder->nodeExists($fileName) ? $folder->get($fileName) : null;
			if ($node instanceof File) {
				$node->putContent($content);
			} else {
				$folder->newFile($fileName)->putContent($content);
			}
			return 'Text in ' . $fileName . ' ersetzt (vorherige Fassung bleibt als Kopie im Vorlagenordner)';
		}
		$previous = null;
		if ($folder->nodeExists($fileName)) {
			$node = $folder->get($fileName);
			if ($node instanceof File) {
				$previous = (string)$node->getContent();
				$node->putContent($content);
			}
		} else {
			$folder->newFile($fileName)->putContent($content);
		}
		$data = $meta + ['name' => $key, 'category' => 'Allgemein', 'outputSubfolder' => '', 'requiredFields' => [], 'supportsPdf' => true, 'active' => true, 'sortOrder' => 0];
		$data['key'] = $key;
		$data['fileName'] = $fileName;
		$data['outputSubfolder'] = $this->folders->resolveSubfolder((string)$data['outputSubfolder']) ?? $this->defaultSubfolder();
		if ($action === 'COPY' && $existing !== null) {
			$data['name'] = (string)$data['name'] . ' (Paket)';
			$data['active'] = false;
		}
		if ($action === 'REPLACE' && $existing !== null) {
			$data = array_replace($data, ['name' => $existing['name'], 'active' => $existing['active'], 'sortOrder' => $existing['sortOrder']]);
		}
		try {
			$this->configuration->saveDocument($data, $existing !== null && $action === 'REPLACE' ? (int)$existing['id'] : 0);
		} catch (\Throwable $error) {
			// Vorlage abgelehnt (z. B. unbekannter Platzhalter): Vorlagenordner in den vorherigen Stand zurücksetzen.
			$node = $folder->nodeExists($fileName) ? $folder->get($fileName) : null;
			if ($node instanceof File) {
				if ($previous !== null) {
					$node->putContent($previous);
				} else {
					$node->delete();
				}
			}
			if ($keepCopy !== null && $folder->nodeExists($keepCopy)) {
				$folder->get($keepCopy)->delete();
			}
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
		if ($zipContent === '' || strlen($zipContent) > self::MAX_BYTES) {
			throw new \InvalidArgumentException('Das Paket ist leer oder größer als 50 MB.');
		}
		$tmp = tempnam(sys_get_temp_dir(), 'bestatter-setup-in-');
		if ($tmp === false) {
			throw new \RuntimeException('Temporäre Datei konnte nicht angelegt werden.');
		}
		file_put_contents($tmp, $zipContent);
		$zip = new \ZipArchive();
		if ($zip->open($tmp) !== true) {
			@unlink($tmp);
			throw new \InvalidArgumentException('Die Datei ist kein ZIP-Archiv.');
		}
		$files = [];
		$root = '';
		$total = 0;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			if (str_ends_with($name, '/') || str_contains($name, '..') || str_starts_with($name, '/')) {
				continue;
			}
			$stat = $zip->statIndex($i);
			$total += (int)($stat['size'] ?? 0);
			if ($total > 4 * self::MAX_BYTES) {
				$zip->close();
				@unlink($tmp);
				throw new \InvalidArgumentException('Das Paket ist entpackt zu groß.');
			}
			if (basename($name) === 'paket.json' && ($root === '' || strlen(dirname($name)) < strlen($root))) {
				$root = dirname($name) === '.' ? '' : dirname($name) . '/';
			}
			$files[$name] = (string)$zip->getFromIndex($i);
		}
		$zip->close();
		@unlink($tmp);
		$manifestRaw = $files[$root . 'paket.json'] ?? null;
		if ($manifestRaw === null) {
			throw new \InvalidArgumentException('Das ZIP-Archiv enthält keine paket.json und ist kein Bestatter-Einrichtungspaket.');
		}
		try {
			$manifest = json_decode($manifestRaw, true, 64, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new \InvalidArgumentException('paket.json ist keine gültige JSON-Datei.');
		}
		if (!is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT) {
			throw new \InvalidArgumentException('paket.json beschreibt kein Bestatter-Einrichtungspaket.');
		}
		if ((int)($manifest['formatVersion'] ?? 0) < 1 || (int)$manifest['formatVersion'] > self::FORMAT_VERSION) {
			throw new \InvalidArgumentException('Das Einrichtungspaket hat ein Format, das diese App-Version nicht kennt. Bitte die App aktualisieren.');
		}
		$contents = [];
		foreach ((array)($manifest['contents'] ?? []) as $entry) {
			if (!is_array($entry) || !in_array($entry['type'] ?? '', self::TYPES, true)) {
				continue;
			}
			$file = $root . ltrim((string)($entry['file'] ?? ''), '/');
			if (!isset($files[$file])) {
				throw new \InvalidArgumentException('Im Paket fehlt die Datei ' . ($entry['file'] ?? '') . '.');
			}
			$entry['file'] = $file;
			$entry['key'] = trim((string)($entry['key'] ?? ''));
			$contents[] = $entry;
		}
		if ($contents === []) {
			throw new \InvalidArgumentException('Das Einrichtungspaket enthält keine verwendbaren Inhalte.');
		}
		$manifest['contents'] = $contents;
		return [$manifest, $files];
	}

	/** @return array<string,true> */
	private function existingValues(string $table, string $column): array {
		$q = $this->db->getQueryBuilder();
		$values = [];
		foreach ($q->select($column)->from($table)->executeQuery()->fetchFirstColumn() as $value) {
			$values[(string)$value] = true;
		}
		return $values;
	}

	private function priceListByKey(string $key): ?array {
		foreach ($this->priceLists->lists() as $list) {
			if (strtoupper((string)$list['key']) === strtoupper($key)) {
				return $list;
			}
		}
		return null;
	}

	/** Zwei Austauschdateien (JSON) inhaltlich gleich – ohne Zeitstempel, App-Version und Aktiv-Kennzeichen. */
	/** Ablaufvorlagen gleich, wenn Name, Beschreibung, Vorgangsarten und Schritte übereinstimmen (Aktiv-Schalter und Reihenfolge zählen nicht). */
	/**
	 * 0.89.7: Was unterscheidet die installierte Ablaufvorlage ($a) von der im Paket ($b)? Kurz für die Vorschau.
	 * @return list<string>
	 */
	public static function processTemplateDifferences(array $a, array $b): array {
		$result = [];
		foreach (['name' => 'Name', 'description' => 'Beschreibung', 'caseKinds' => 'Gilt für'] as $field => $label) {
			if (($a[$field] ?? null) != ($b[$field] ?? null)) {
				$result[] = $label;
			}
		}
		$installed = array_column((array)($a['steps'] ?? []), null, 'key');
		$incoming = array_column((array)($b['steps'] ?? []), null, 'key');
		foreach ($incoming as $key => $step) {
			$title = '„' . (string)($step['title'] ?? $key) . '“';
			if (!isset($installed[$key])) {
				$result[] = 'Schritt ' . $title . ' neu';
			} elseif (array_diff_key($installed[$key], ['sortOrder' => 1]) != array_diff_key($step, ['sortOrder' => 1])) {
				$result[] = 'Schritt ' . $title . ' geändert';
			}
		}
		foreach (array_diff_key($installed, $incoming) as $key => $step) {
			$result[] = 'Schritt „' . (string)($step['title'] ?? $key) . '“ nur in der Installation';
		}
		if ($result === [] && array_keys($installed) !== array_keys($incoming)) {
			$result[] = 'Reihenfolge der Schritte';
		}
		return count($result) > 4 ? [...array_slice($result, 0, 4), 'und ' . (count($result) - 4) . ' weitere'] : $result;
	}

	public static function sameProcessTemplate(array $a, array $b): bool {
		unset($a['active'], $a['sortOrder'], $b['active'], $b['sortOrder']);
		return $a == $b;
	}

	/** 0.89.8: Niederlassungen als JSON (ohne Bankverbindung und zugeordnete Personen). */
	private function branchesExport(): string {
		$branches = array_map(static fn (array $branch): array => array_intersect_key($branch, array_flip(self::BRANCH_FIELDS)), $this->configuration->branches());
		return json_encode(['format' => self::BRANCH_FORMAT, 'formatVersion' => 1, 'appVersion' => Application::VERSION, 'branches' => $branches], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	/**
	 * Liest Niederlassungen aus dem Paket. Nur bekannte Felder; Bankfelder im Paket werden ignoriert.
	 * @return list<array<string,mixed>>
	 */
	public static function parseBranches(string $content): array {
		$data = json_decode($content, true);
		if (!is_array($data) || ($data['format'] ?? '') !== self::BRANCH_FORMAT || !is_array($data['branches'] ?? null)) {
			throw new \InvalidArgumentException('Keine Niederlassungen im erwarteten Format (' . self::BRANCH_FORMAT . ').');
		}
		$result = [];
		foreach ($data['branches'] as $branch) {
			if (!is_array($branch)) {
				continue;
			}
			$branch = array_intersect_key($branch, array_flip(self::BRANCH_FIELDS));
			$branch['key'] = strtoupper(trim((string)($branch['key'] ?? '')));
			$branch['name'] = trim((string)($branch['name'] ?? ''));
			if ($branch['key'] === '' || $branch['name'] === '') {
				throw new \InvalidArgumentException('Jede Niederlassung braucht Schlüssel und Name.');
			}
			if (!preg_match('/^[A-Z0-9_-]+$/', $branch['key'])) {
				throw new \InvalidArgumentException('Niederlassungsschlüssel „' . $branch['key'] . '“: nur Buchstaben, Ziffern, Unterstrich und Bindestrich.');
			}
			$result[$branch['key']] = $branch;
		}
		if ($result === []) {
			throw new \InvalidArgumentException('Das Paket enthält keine Niederlassung.');
		}
		return array_values($result);
	}

	/** Gleich, wenn alle im Paket genannten Felder mit der vorhandenen Niederlassung übereinstimmen. */
	private static function sameBranch(array $existing, array $incoming): bool {
		foreach ($incoming as $field => $value) {
			$current = $existing[$field] ?? null;
			if (is_bool($current) || is_bool($value)) {
				if ((bool)$current !== (bool)$value) {
					return false;
				}
			} elseif (trim((string)$current) !== trim((string)$value)) {
				return false;
			}
		}
		return true;
	}

	public static function sameExchange(string $a, string $b): bool {
		$strip = static function (string $json): ?array {
			$data = json_decode($json, true);
			if (!is_array($data)) {
				return null;
			}
			unset($data['exportedAt'], $data['appVersion'], $data['active'], $data['sortOrder']);
			return $data;
		};
		$left = $strip($a);
		return $left !== null && $left == $strip($b);
	}

	private function templateContent(string $fileName): ?string {
		$folder = $this->folders->templatesFolder();
		$fileName = basename($fileName);
		if ($fileName === '' || !$folder->nodeExists($fileName)) {
			return null;
		}
		$node = $folder->get($fileName);
		return $node instanceof File ? (string)$node->getContent() : null;
	}

	private function defaultSubfolder(): string {
		return (string)($this->folders->allowedSubfolders()[0] ?? '');
	}

	private function documentByKey(string $key): ?array {
		foreach ($this->configuration->documents() as $template) {
			if (strtoupper((string)$template['key']) === strtoupper($key)) {
				return $template;
			}
		}
		return null;
	}

	private function orderFormByKey(string $key): ?array {
		foreach ($this->orderForms->forms() as $form) {
			if (strtoupper((string)$form['key']) === strtoupper($key)) {
				return $form;
			}
		}
		return null;
	}

	private static function uniqueFileName(\OCP\Files\Folder $folder, string $name): string {
		$ext = self::templateExtension($name);
		$base = substr($name, 0, -strlen($ext) - 1);
		$candidate = $name;
		$n = 2;
		while ($folder->nodeExists($candidate)) {
			$candidate = $base . '-' . $n++ . '.' . $ext;
		}
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
			if (!mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
				throw new \InvalidArgumentException('Die Textvorlage ist keine gültige UTF-8-Textdatei.');
			}
			return;
		}
		if (!str_starts_with($content, 'PK')) {
			throw new \InvalidArgumentException('Die Datei ist keine DOCX-Datei.');
		}
	}

	private static function fileSafe(string $value): string {
		$value = strtr($value, ['Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
		return trim((string)preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value), '-') ?: 'paket';
	}
}
