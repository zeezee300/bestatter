<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * Leistungskatalog (Artikelstamm) und Leistungserfassung im Fall.
 *
 * Seit 0.69.0 nach SAP-Vorbild: Artikel mit Artikelart, Warengruppe (Schlüssel) und Steuerklasse,
 * Verkaufspreise aus Preislisten mit Gültigkeit, Pakete als Stückliste. Im Fall wird ein Paket als
 * Kopfposition mit Unterpositionen erfasst; Positionsnummern bleiben nach der ersten
 * Festschreibung stabil, der Leistungsstatus ergibt sich aus den Mengen.
 */
class ArticleService {
	private const SEED_FILE = __DIR__ . '/../../resources/article-catalog.json';
	/** Beispielartikel bis 0.68 – werden bei Übernahme des Beispielkatalogs 2026 deaktiviert. */
	private const LEGACY_SAMPLE_NUMBERS = ['DL-EINBETTEN', 'LA-SARG-STANDARD', 'LA-URNE-STANDARD', 'LA-DECKE-KISSEN', 'LA-TOTENBEKLEIDUNG', 'DL-BEKLEIDUNG-EIGEN', 'DL-UEBERFUEHRUNG', 'DL-AUFBAHRUNG-OFFEN', 'DL-AUFBAHRUNG-TRAUERFEIER', 'DL-TRAUERFEIER-ORG', 'DL-KREMATORIUM-TRANSFER', 'DL-URNE-FRIEDHOF', 'DL-URNENBEISETZUNG', 'DL-KRANZTRANSPORT', 'DL-LAUTSPRECHER', 'DL-FORMALITAETEN', 'DR-TRAUERANZEIGE', 'DR-TRAUERBRIEF', 'ZU-AUSSERHALB', 'AU-ARZT-TOTENSCHEIN', 'GE-MELDEAMT', 'GE-STANDESAMT-ABSCHRIFT', 'GE-STANDESAMT-STERBEURKUNDE', 'GE-FRIEDHOF', 'AU-ORGANIST', 'AU-TRAEGER', 'AU-GRABARBEITEN', 'AU-RECHTSMEDIZIN', 'FL-SARGSCHMUCK', 'FL-URNENSCHMUCK', 'FL-KRANZ', 'FL-NACHWERFBLUMEN', 'FL-GESTECK', 'PK-FEUER-BASIS', 'PK-ERDE-BASIS'];
	private const FALLBACK_UNITS = ['STK', 'PAUSCHAL', 'STD', 'KM', 'TAG', 'KG', 'L', 'SATZ', 'PERS'];

	public function __construct(
		private IDBConnection $db,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private AuditService $audit,
		private InstallationConfigService $installationConfig,
		private CountryConfigurationService $countryConfiguration,
		private CustomizingService $customizing,
		private CommercialStateService $commercialState,
		private CatalogStructureService $structure,
		private PriceListService $priceLists,
		private BomService $boms,
		private ConfigurationService $configuration,
		private IConfig $config,
		private CaseStorageService $storage,
	) {
	}

	// ------------------------------------------------------------------ Katalog

	public function catalog(): array {
		$this->customizing->ensureSeedData();
		$this->structure->ensureSeedData();
		$this->ensureSeedData();
		$groups = $this->structure->groups();
		$articles = $this->articles();
		return [
			'articles' => array_values($articles),
			'areas' => $this->structure->areas(),
			'groups' => $groups,
			// Kompatibilität für ältere Oberflächen/Exporte.
			'groupRules' => array_map(static fn(array $group): array => ['groupKey' => $group['key'], 'groupName' => $group['name'], 'exclusiveSelection' => $group['selectionRule'] !== 'MANY', 'selectionRule' => $group['selectionRule']], $groups),
			'priceLists' => $this->priceLists->lists(),
			'articleTypes' => array_map(static fn(string $key, array $type): array => ['value' => $key, 'label' => $type['label'], 'positionType' => $type['positionType']], array_keys(CatalogRules::ARTICLE_TYPES), CatalogRules::ARTICLE_TYPES),
			'taxClasses' => array_map(static fn(string $key, string $label): array => ['value' => $key, 'label' => $label, 'rate' => CatalogRules::taxRate($key)], array_keys(CatalogRules::TAX_CLASSES), CatalogRules::TAX_CLASSES),
			'selectionRules' => $this->options(CatalogRules::SELECTION_RULES),
			'funeralScopes' => $this->options(CatalogRules::FUNERAL_SCOPES),
			'bomPricing' => $this->options(CatalogRules::BOM_PRICING),
			'bomItemTypes' => $this->options(CatalogRules::BOM_ITEM_TYPES),
			'printModes' => $this->options(CatalogRules::PRINT_MODES),
			'allowedVatRates' => $this->countryConfiguration->allowedVatRates(),
			'countryProfiles' => $this->countryConfiguration->profiles(),
			'positionTypes' => $this->customizing->valuesForKey('POSITION_TYPE'),
			'quantityUnits' => $this->customizing->valuesForKey('QUANTITY_UNIT'),
		];
	}

	/** @return array<int, array> */
	private function articles(): array {
		$groups = $this->structure->groupMap();
		$prices = $this->priceLists->prices();
		$boms = $this->boms->all();
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_articles')->orderBy('article_number', 'ASC')->executeQuery()->fetchAllAssociative();
		$result = [];
		foreach ($rows as $row) $result[(int)$row['id']] = $this->mapArticle($row, $groups, $prices, $boms);
		uasort($result, static fn(array $left, array $right): int => [$left['areaSortOrder'], $left['groupSortOrder'], $left['articleNumber']] <=> [$right['areaSortOrder'], $right['groupSortOrder'], $right['articleNumber']]);
		return $result;
	}

	public function article(int $id): array {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('*')->from('bestatter_articles')->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeQuery()->fetchAssociative();
		if (!$row) throw new \InvalidArgumentException('Leistung wurde nicht gefunden.');
		return $this->mapArticle($row, $this->structure->groupMap(), $this->priceLists->prices(), $this->boms->all());
	}

	public function suggestArticleNumber(string $groupKey): string {
		return $this->structure->suggestArticleNumber($groupKey);
	}

	public function save(array $input, array $components = [], ?int $id = null): array {
		$current = $id !== null ? $this->article($id) : null;
		$values = $this->validateArticle($input, $current);
		$group = $this->structure->group($values['articleGroup']);
		$rule = (string)($group['selectionRule'] ?? 'MANY');
		$now = date('c');
		$columns = [
			'item_type' => $values['articleType'] === 'PAKET' ? 'PACKAGE' : 'SINGLE',
			'article_number' => $values['articleNumber'], 'short_name' => $values['shortName'], 'long_text' => $values['longText'],
			'internal_note' => $values['internalNote'], 'search_term' => $values['searchTerm'],
			'category' => CatalogRules::ARTICLE_TYPES[$values['articleType']]['label'], 'article_group' => $values['articleGroup'],
			'article_type' => $values['articleType'], 'tax_class' => $values['taxClass'],
			'cost_type' => CatalogRules::costType($values['articleType']), 'funeral_scope' => (string)($group['funeralScope'] ?? 'ALL'),
			'vat_rate' => CatalogRules::taxRate($values['taxClass']), 'unit' => $values['unit'], 'quantity_decimals' => $values['quantityDecimals'],
			'supplier_contact' => $values['supplierContact'], 'photo_path' => $values['photoPath'], 'purchase_price_cents' => $values['purchasePriceCents'],
			'guide_price_cents' => $values['guidePriceCents'],
		];
		$this->db->beginTransaction();
		try {
			$query = $this->db->getQueryBuilder();
			if ($id === null) {
				$insert = [];
				foreach ($columns as $column => $value) $insert[$column] = $query->createNamedParameter($value);
				$insert['exclusive_group'] = $query->createNamedParameter($rule !== 'MANY', IQueryBuilder::PARAM_BOOL);
				$insert['price_on_entry'] = $query->createNamedParameter($values['priceOnEntry'], IQueryBuilder::PARAM_BOOL);
				$insert['active'] = $query->createNamedParameter($values['active'], IQueryBuilder::PARAM_BOOL);
				$insert['sales_price_cents'] = $query->createNamedParameter(0);
				$insert['created_at'] = $query->createNamedParameter($now);
				$insert['updated_at'] = $query->createNamedParameter($now);
				if ($this->findIdByNumber($values['articleNumber'])) throw new \InvalidArgumentException('Die Artikelnummer ' . $values['articleNumber'] . ' ist bereits vergeben.');
				try {
					$query->insert('bestatter_articles')->values($insert)->executeStatement();
				} catch (\OCP\DB\Exception $error) {
					if ($error->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) throw $error;
					throw new \InvalidArgumentException('Die Artikelnummer ' . $values['articleNumber'] . ' ist bereits vergeben.');
				}
				$id = (int)$this->db->lastInsertId('bestatter_articles');
			} else {
				$query->update('bestatter_articles');
				foreach ($columns as $column => $value) $query->set($column, $query->createNamedParameter($value));
				$query->set('exclusive_group', $query->createNamedParameter($rule !== 'MANY', IQueryBuilder::PARAM_BOOL))
					->set('price_on_entry', $query->createNamedParameter($values['priceOnEntry'], IQueryBuilder::PARAM_BOOL))
					->set('active', $query->createNamedParameter($values['active'], IQueryBuilder::PARAM_BOOL))
					->set('updated_at', $query->createNamedParameter($now))
					->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeStatement();
			}
			if ($values['salesPriceCents'] !== null) {
				$currentPrice = $this->priceLists->prices('', $values['priceValidFrom'] ?: date('Y-m-d'))[$id]['cents'] ?? null;
				if ($currentPrice !== $values['salesPriceCents'] && !($currentPrice === null && $values['salesPriceCents'] === 0 && ($values['priceOnEntry'] || $values['articleType'] === 'PAKET'))) {
					$this->priceLists->setPrice($this->priceLists->standardListId(), $id, $values['salesPriceCents'], $values['priceValidFrom']);
				}
			}
			if ($values['articleType'] === 'PAKET') {
				$bom = is_array($input['bom'] ?? null) ? $input['bom'] : null;
				if ($bom === null && $components !== []) $bom = ['pricing' => 'ITEMS', 'printMode' => 'ITEMS_NO_PRICES', 'items' => array_map(static fn(array $component, int $index): array => ['itemNo' => ($index + 1) * 10, 'articleId' => (int)($component['articleId'] ?? 0), 'quantity' => isset($component['quantityMilli']) ? (int)$component['quantityMilli'] / 1000 : ($component['quantity'] ?? 1), 'itemType' => 'FIXED'], $components, array_keys($components))];
				if ($bom !== null) $this->boms->save($id, $bom, $this->articles());
			} elseif ($current !== null && ($current['articleType'] ?? '') === 'PAKET') {
				$this->boms->delete($id);
			}
			$this->syncMirrorPrice($id);
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		return $this->article($id);
	}

	public function delete(int $id): void {
		$this->article($id);
		$caseQuery = $this->db->getQueryBuilder();
		$caseReferences = (int)$caseQuery->select($caseQuery->func()->count('*', 'count'))->from('bestatter_case_services')
			->where($caseQuery->expr()->orX(
				$caseQuery->expr()->eq('article_id', $caseQuery->createNamedParameter($id)),
				$caseQuery->expr()->eq('source_package_id', $caseQuery->createNamedParameter($id)),
			))->executeQuery()->fetchOne();
		if ($caseReferences > 0 || $this->boms->isComponent($id)) {
			$query = $this->db->getQueryBuilder();
			$query->update('bestatter_articles')->set('active', $query->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))->set('updated_at', $query->createNamedParameter(date('c')))
				->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeStatement();
			return;
		}
		$this->boms->delete($id);
		$this->priceLists->deleteArticlePrices($id);
		$query = $this->db->getQueryBuilder();
		$query->delete('bestatter_articles')->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeStatement();
	}

	/**
	 * Legt den Beispielkatalog 2026 an (vorhandene Artikelnummern bleiben unangetastet) und
	 * deaktiviert auf Wunsch die Beispielartikel bis 0.68. Fälle behalten ihre Snapshots.
	 */
	public function installSampleCatalog(bool $deactivateLegacySamples = true): array {
		$seed = $this->seed();
		foreach ((array)($seed['areas'] ?? []) as $area) {
			if (!in_array((string)$area['key'], array_column($this->structure->areas(), 'key'), true)) $this->structure->saveArea(['key' => $area['key'], 'name' => $area['name'], 'sortOrder' => $area['sortOrder'] ?? 0]);
		}
		foreach ((array)($seed['groups'] ?? []) as $group) {
			if ($this->structure->group((string)$group['key']) !== null) continue;
			$this->structure->saveGroup(['key' => $group['key'], 'name' => $group['name'], 'areaKey' => $group['area'], 'selectionRule' => $group['selectionRule'] ?? 'MANY', 'funeralScope' => $group['funeralScope'] ?? 'ALL', 'sortOrder' => $group['sortOrder'] ?? 0], true);
		}
		$created = 0;
		foreach ((array)($seed['articles'] ?? []) as $entry) {
			if ($this->findIdByNumber((string)$entry['articleNumber']) > 0) continue;
			$entry['priceValidFrom'] = '2026-01-01';
			$this->save($entry);
			$created++;
		}
		foreach ((array)($seed['boms'] ?? []) as $bom) {
			$packageId = $this->findIdByNumber((string)$bom['package']);
			if ($packageId === 0 || $this->boms->forPackage($packageId) !== null) continue;
			$items = [];
			foreach ((array)$bom['items'] as $item) {
				$articleId = $this->findIdByNumber((string)$item['article']);
				if ($articleId === 0) continue;
				$items[] = ['itemNo' => $item['no'], 'articleId' => $articleId, 'quantity' => $item['quantity'] ?? 1, 'itemType' => $item['type'] ?? 'FIXED', 'altGroup' => $item['group'] ?? '', 'isDefault' => $item['default'] ?? false, 'credit' => $item['credit'] ?? 0];
			}
			if ($items !== []) $this->boms->save($packageId, ['pricing' => $bom['pricing'] ?? 'HEADER', 'printMode' => $bom['printMode'] ?? 'ITEMS_NO_PRICES', 'items' => $items], $this->articles());
		}
		$deactivated = 0;
		if ($deactivateLegacySamples) {
			$query = $this->db->getQueryBuilder();
			$deactivated = $query->update('bestatter_articles')->set('active', $query->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))->set('updated_at', $query->createNamedParameter(date('c')))
				->where($query->expr()->in('article_number', $query->createNamedParameter(self::LEGACY_SAMPLE_NUMBERS, IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($query->expr()->eq('active', $query->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))->executeStatement();
		}
		$this->config->deleteAppValue(Application::APP_ID, 'catalog_sample_pending');
		return ['created' => $created, 'deactivated' => $deactivated];
	}

	/** CSV-Export des Leistungskatalogs; Spalten wie beim Import, Werte im Klartext (Excel-tauglich). */
	public function exportCsv(): string {
		$headers = ['Artikelnummer', 'Suchbegriff', 'Kurztext', 'Verkaufstext', 'Interner Hinweis', 'Warengruppe', 'Artikelart', 'Steuerklasse', 'Einheit', 'Nachkommastellen', 'VK', 'Preis gültig ab', 'Preis im Fall', 'Richtwert', 'EK', 'Standardlieferant', 'Aktiv', 'Preisfindung', 'Stückliste', 'Löschen'];
		$rows = [];
		foreach ($this->articles() as $article) {
			$bom = is_array($article['bom'] ?? null) ? $article['bom'] : null;
			$components = implode('|', array_map(static fn(array $item): string => rtrim(implode(':', [(string)$item['articleNumber'], str_replace('.', ',', (string)(float)$item['quantity']), (string)$item['itemType'], (string)($item['altGroup'] ?? ''), !empty($item['isDefault']) ? '*' : '', (int)($item['creditCents'] ?? 0) > 0 ? CsvTools::money((int)$item['creditCents']) : '']), ':'), $bom['items'] ?? []));
			$hasPrice = !empty($article['hasPrice']);
			$rows[] = [
				$article['articleNumber'], $article['searchTerm'] ?? '', $article['shortName'], $article['longText'] ?? '', $article['internalNote'] ?? '',
				$article['articleGroup'], $article['articleType'], $article['taxClass'], $article['unit'], (string)(int)$article['quantityDecimals'],
				$hasPrice ? CsvTools::money((int)$article['salesPriceCents']) : '', $hasPrice ? (string)($article['priceValidFrom'] ?? '') : '',
				!empty($article['priceOnEntry']) ? 'ja' : 'nein', CsvTools::money(isset($article['guidePriceCents']) ? (int)$article['guidePriceCents'] : null),
				CsvTools::money((int)($article['purchasePriceCents'] ?? 0)), $article['supplierContact'] ?? '', !empty($article['active']) ? 'ja' : 'nein',
				$bom['pricing'] ?? '', $components, '',
			];
		}
		return CsvTools::write($headers, $rows);
	}

	public function importCsv(string $content): array {
		$rows = [];
		$lines = [];
		foreach (CsvTools::read($content, fn(string $header): string => $this->normalizeHeader($header)) as $entry) { $rows[] = $entry['values']; $lines[] = $entry['line']; }
		if ($rows !== [] && !array_key_exists('articleNumber', $rows[0])) throw new \InvalidArgumentException('Die CSV-Datei enthält keine Spalte „Artikelnummer“. Bitte die Datei aus „Leistungskatalog als CSV herunterladen“ als Vorlage verwenden.');
		$today = date('Y-m-d');

		$imported = 0; $deleted = 0;
		$bomDefinitions = [];
		// Alles oder nichts: bei einer fehlerhaften Zeile bleibt der Katalog unverändert.
		$this->db->beginTransaction();
		try {
		foreach ($rows as $index => $row) {
			$articleNumber = trim((string)($row['articleNumber'] ?? ''));
			$id = $this->findIdByNumber($articleNumber);
			try {
				if (CsvTools::flag($row['delete'] ?? '')) { if ($id) { $this->delete($id); $deleted++; } continue; }
				$row['priceValidFrom'] = CsvTools::date($row['priceValidFrom'] ?? '', 'Preis gültig ab');
				// Ein geänderter VK mit unverändertem „gültig ab“ aus dem Export gilt ab heute und überschreibt nicht rückwirkend den bisherigen Preis.
				if ($id && trim((string)($row['salesPrice'] ?? '')) !== '') {
					$current = $this->priceLists->prices('', $today)[$id] ?? null;
					if ($current !== null && CsvTools::cents($row['salesPrice']) !== (int)$current['cents'] && ($row['priceValidFrom'] === '' || $row['priceValidFrom'] <= (string)$current['validFrom'])) $row['priceValidFrom'] = $today;
				}
				$article = $this->save($row, [], $id ?: null);
			} catch (\InvalidArgumentException $error) {
				throw new \InvalidArgumentException('CSV-Zeile ' . $lines[$index] . ' (' . $articleNumber . '): ' . $error->getMessage());
			}
			if (($article['articleType'] ?? '') === 'PAKET' && trim((string)($row['components'] ?? '')) !== '') $bomDefinitions[(int)$article['id']] = [trim((string)$row['components']), strtoupper(trim((string)($row['packagePricing'] ?? ''))) ?: 'ITEMS'];
			$imported++;
		}
		foreach ($bomDefinitions as $packageId => [$definition, $pricing]) {
			// Format: Nummer:Menge[:Art[:Alternativgruppe[:*[:Minderpreis]]]] – „*“ markiert den Standard einer Alternativgruppe.
			// Vorhandene Positionen behalten Positionsnummer, ID (Bezug laufender Fälle), Minderpreis und die Druckart des Pakets.
			$existing = $this->boms->forPackage($packageId);
			$oldItems = $existing['items'] ?? [];
			$usedOld = [];
			$items = [];
			$nextNo = 10;
			foreach (preg_split('/[|]/', $definition) ?: [] as $index => $part) {
				$fields = array_map('trim', explode(':', $part));
				$articleId = $this->findIdByNumber($fields[0] ?? '');
				if (!$articleId) throw new \InvalidArgumentException('Stückliste von Paket ' . (string)($this->article($packageId)['articleNumber'] ?? '') . ': Artikelnummer „' . ($fields[0] ?? '') . '“ ist nicht im Leistungskatalog.');
				$match = null;
				foreach ($oldItems as $position => $old) if (!isset($usedOld[$position]) && (int)$old['articleId'] === $articleId) { $match = $old; $usedOld[$position] = true; break; }
				$type = strtoupper($fields[2] ?? '') ?: 'FIXED';
				$itemNo = $match !== null ? (int)$match['itemNo'] : $nextNo;
				$nextNo = max($nextNo, $itemNo) + 10;
				$items[] = ['itemNo' => $itemNo, 'articleId' => $articleId, 'quantity' => $fields[1] ?? '1', 'itemType' => $type, 'altGroup' => $fields[3] ?? '', 'isDefault' => ($fields[4] ?? '') === '*',
					'creditCents' => ($fields[5] ?? '') !== '' ? CsvTools::cents($fields[5]) : (int)($match['creditCents'] ?? 0)];
			}
			if ($items === []) continue;
			$signature = static fn(array $list): array => array_map(static fn(array $item): string => implode('|', [(int)$item['itemNo'], (int)$item['articleId'], (int)round((float)str_replace(',', '.', (string)($item['quantity'] ?? 1)) * 1000), strtoupper((string)$item['itemType']), strtoupper((string)($item['altGroup'] ?? '')), !empty($item['isDefault']) ? 1 : 0, (int)($item['creditCents'] ?? 0)]), $list);
			if ($existing !== null && $existing['pricing'] === $pricing && $signature($items) === $signature(array_map(static fn(array $old): array => $old + ['quantity' => $old['quantityMilli'] / 1000], $oldItems))) continue;
			$this->boms->save($packageId, ['pricing' => $pricing, 'printMode' => $existing['printMode'] ?? 'ITEMS_NO_PRICES', 'items' => $items], $this->articles());
		}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		$catalog = $this->catalog();
		return ['imported' => $imported, 'deleted' => $deleted] + $catalog;
	}

	public function savePhoto(int $id, array $upload): array {
		$article = $this->article($id);
		if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string)($upload['tmp_name'] ?? ''))) {
			throw new \InvalidArgumentException('Es wurde kein gültiges Bild hochgeladen.');
		}
		if ((int)($upload['size'] ?? 0) > 5 * 1024 * 1024) throw new \InvalidArgumentException('Das Bild darf höchstens 5 MB groß sein.');
		// Anhand der tatsächlichen Bilddaten (Magic Bytes) statt nur der Dateiendung prüfen.
		$imageInfo = @getimagesize((string)$upload['tmp_name']);
		$extensionByImageType = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
		$extension = $imageInfo !== false ? ($extensionByImageType[$imageInfo[2]] ?? null) : null;
		if ($extension === null) throw new \InvalidArgumentException('Die Datei ist kein gültiges JPG-, PNG- oder WebP-Bild.');
		$folder = $this->installationConfig->ensurePath($this->storage->baseFolder(), $this->installationConfig->articleImagesPath());
		$fileName = preg_replace('/[^A-Za-z0-9._-]/', '_', $article['articleNumber']) . '.' . $extension;
		$existing = $folder->nodeExists($fileName) ? $folder->get($fileName) : null;
		if ($existing !== null && !$existing instanceof File) throw new \RuntimeException('Im Ordner für Artikelbilder liegt unter dem Namen „' . $fileName . '“ ein Ordner. Bitte diesen umbenennen oder entfernen.');
		$file = $existing ?? $folder->newFile($fileName);
		$file->putContent((string)file_get_contents((string)$upload['tmp_name']));
		$path = $this->installationConfig->storageRoot() . '/' . $this->installationConfig->articleImagesFolder() . '/' . $fileName;
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_articles')->set('photo_path', $query->createNamedParameter($path))->set('updated_at', $query->createNamedParameter(date('c')))
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeStatement();
		return $this->article($id);
	}

	// ------------------------------------------------------------------ Fallpositionen

	public function caseServices(int $caseId, ?int $sideOrderId = null): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_case_services')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->andWhere($sideOrderId === null ? $query->expr()->isNull('side_order_id') : $query->expr()->eq('side_order_id', $query->createNamedParameter($sideOrderId)))
			->orderBy('sort_order', 'ASC')->addOrderBy('sub_position', 'ASC')->addOrderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		$items = array_map(fn(array $row): array => $this->mapCaseService($row), $rows);
		$items = $this->linkPackages($items);
		$billable = array_values(array_filter($items, static fn(array $item): bool => ($item['serviceStatus'] ?? '') !== 'STORNIERT' && !$item['isPackageHeader']));
		$context = $this->caseContext($caseId);
		return [
			'items' => $items,
			'totals' => $this->totalsForQuantity($billable, 'orderedQuantityMilli'),
			'performanceTotals' => $this->totalsForQuantity($billable, 'performedQuantityMilli'),
			'invoicedTotals' => $this->totalsForQuantity($billable, 'invoicedQuantityMilli'),
			'outstandingTotals' => $this->outstandingTotals($billable),
			'contractProtection' => $this->contractProtection($caseId, $sideOrderId),
			'branch' => $context['branch'],
			// Verkaufspreise der Niederlassung zum heutigen Tag (für die Anzeige neuer Positionen).
			'prices' => array_map(static fn(array $price): int => $price['cents'], $this->priceLists->prices($context['branch'])),
		];
	}

	/**
	 * 0.74.0: Katalogkontext eines Falls für das Auftragsformular – Artikel, Warengruppen, Land und
	 * Verkaufspreise der Niederlassung (netto, Cent) zum heutigen Tag.
	 * @return array{branch:string,country:string,articles:array<int,array>,groups:array,prices:array<int,int>}
	 */
	public function orderFormContext(int $caseId): array {
		$this->structure->ensureSeedData();
		$this->ensureSeedData();
		$context = $this->caseContext($caseId);
		return [
			'branch' => $context['branch'], 'country' => $context['country'], 'articles' => $this->articles(), 'groups' => $this->structure->groupMap(),
			'prices' => array_map(static fn(array $price): int => (int)$price['cents'], $this->priceLists->prices($context['branch'])),
		];
	}

	/**
	 * Speichert die Positionsauswahl eines Auftragskreises.
	 *
	 * Eine Auswahl ist entweder eine Freitextposition, eine Katalogposition oder ein Paket
	 * ({articleId, packageConfig: {selected: [bomItemId…]}, packagePriceCents, printMode}).
	 * Die Unterpositionen eines Pakets bildet der Server aus der Stückliste.
	 */
	public function saveCaseServices(int $caseId, array $selections, string $amendmentReason = '', ?int $sideOrderId = null): array {
		$before = $this->caseServices($caseId, $sideOrderId);
		$beforeById = [];
		foreach ($before['items'] as $item) $beforeById[(int)$item['id']] = $item;
		$articles = $this->articles();
		$context = $this->caseContext($caseId);
		$prices = $this->priceLists->prices($context['branch']);
		$groups = $this->structure->groupMap();

		$specs = [];
		foreach ($selections as $selection) {
			$articleId = (int)($selection['articleId'] ?? 0);
			$existing = $beforeById[(int)($selection['serviceId'] ?? 0)] ?? null;
			if ($existing !== null && (($existing['serviceStatus'] ?? '') === 'STORNIERT' || ($existing['origin'] ?? '') === 'INCOMING_INVOICE' || $existing['parentServiceId'] !== null)) $existing = null;
			if ($articleId === 0) {
				$specs[] = $this->freeTextSpec($selection, $existing && (int)$existing['articleId'] === 0 && !$existing['isPackageHeader'] ? $existing : null);
				continue;
			}
			$article = $articles[$articleId] ?? null;
			if ($article === null) continue;
			if ($existing !== null && (int)$existing['articleId'] !== $articleId) $existing = null;
			if (($article['articleType'] ?? '') === 'PAKET') {
				$specs[] = $this->packageSpec($article, $selection, $existing !== null && $existing['isPackageHeader'] ? $existing : null, $articles, $prices, $groups, $context, $before['items']);
				continue;
			}
			$specs[] = $this->catalogSpec($article, $selection, $existing !== null && !$existing['isPackageHeader'] ? $existing : null, $prices, $groups, $context);
		}
		$this->assertSelectionRules($specs, $groups);

		// Vertragsänderung ermitteln (Neu, geändert, entfernt).
		$matched = [];
		$contractChanged = false;
		$flat = [];
		foreach ($specs as $spec) { $flat[] = $spec; foreach ($spec['children'] ?? [] as $child) $flat[] = $child; }
		foreach ($flat as $spec) {
			$current = $spec['existing'];
			if ($current === null) { $contractChanged = true; continue; }
			$matched[(int)$current['id']] = true;
			if ($this->contractValuesDiffer($current, $spec['line'])) $contractChanged = true;
		}
		$removed = [];
		foreach ($before['items'] as $item) {
			if (($item['origin'] ?? '') === 'INCOMING_INVOICE' || ($item['serviceStatus'] ?? '') === 'STORNIERT' || isset($matched[(int)$item['id']])) continue;
			$removed[] = $item;
			$contractChanged = true;
		}
		$contractProtection = $this->contractProtection($caseId, $sideOrderId);
		$amendmentReason = trim($amendmentReason);
		if ($contractProtection['active'] && $contractChanged) {
			if (!$contractProtection['amendmentsAllowed']) throw new \InvalidArgumentException('Die Vertragspositionen können nicht mehr geändert werden, weil bereits eine aktive Schlussrechnung angelegt wurde. Korrekturen müssen über den vorgesehenen Rechnungsprozess erfolgen.');
			if (mb_strlen($amendmentReason) < 5) throw new \InvalidArgumentException('Nach Festschreibung des KVA oder Auftrags ist eine Änderung nur als begründeter Vertragsnachtrag möglich.');
		}
		foreach ($flat as $spec) {
			$current = $spec['existing'];
			if ($current !== null && (int)$current['invoicedQuantityMilli'] > 0 && $this->contractValuesDiffer($current, $spec['line'])) throw new \InvalidArgumentException('Bereits fakturierte Leistungen dürfen in Menge oder Preis sowie weiteren Vertragsdaten nicht mehr geändert werden („' . $current['title'] . '“).');
		}
		foreach ($removed as $item) if ((int)$item['invoicedQuantityMilli'] > 0) throw new \InvalidArgumentException('Bereits fakturierte Leistungen dürfen nicht entfernt werden („' . $item['title'] . '“).');

		// Positionsnummern: bis zur ersten Festschreibung fortlaufend, danach stabil.
		$stable = (bool)$contractProtection['active'];
		$maxPosition = 0;
		foreach ($before['items'] as $item) if (($item['origin'] ?? '') !== 'INCOMING_INVOICE') $maxPosition = max($maxPosition, (int)$item['position']);
		$next = $stable ? $maxPosition : 0;
		foreach ($specs as &$spec) {
			if ($stable && $spec['existing'] !== null && (int)$spec['existing']['position'] > 0) $spec['position'] = (int)$spec['existing']['position'];
			else { $next += 10; $spec['position'] = $next; }
			$maxSub = 0;
			foreach ($spec['children'] ?? [] as $child) if ($stable && $child['existing'] !== null) $maxSub = max($maxSub, (int)$child['existing']['subPosition']);
			$sub = 0;
			foreach ($spec['children'] ?? [] as $index => $child) {
				if ($stable && $child['existing'] !== null && (int)$child['existing']['subPosition'] > 0) $spec['children'][$index]['subPosition'] = (int)$child['existing']['subPosition'];
				elseif ($stable) $spec['children'][$index]['subPosition'] = ++$maxSub;
				else $spec['children'][$index]['subPosition'] = ++$sub;
			}
		}
		unset($spec);

		$markNew = $contractProtection['active'] && $contractChanged;
		$this->db->beginTransaction();
		try {
			foreach ($specs as $spec) {
				$parentId = $this->persistLine($caseId, $sideOrderId, $spec['line'], $spec['existing'], (int)$spec['position'], 0, null, $markNew);
				foreach ($spec['children'] ?? [] as $child) $this->persistLine($caseId, $sideOrderId, $child['line'], $child['existing'], (int)$spec['position'], (int)$child['subPosition'], $parentId, $markNew);
			}
			foreach ($removed as $item) {
				$query = $this->db->getQueryBuilder();
				$query->update('bestatter_case_services')->set('service_status', $query->createNamedParameter('STORNIERT'))->set('billability', $query->createNamedParameter('NICHT_ABRECHENBAR'))
					->set('classification_reason', $query->createNamedParameter('Aus der aktuellen Auswahl entfernt.'))->set('updated_at', $query->createNamedParameter(date('c')))
					->where($query->expr()->eq('id', $query->createNamedParameter((int)$item['id'])))->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}
		$after = $this->caseServices($caseId, $sideOrderId);
		$this->audit->log($caseId, 'SERVICE', null, 'SELECTION_UPDATED', $before, $after);
		if ($contractProtection['active'] && $contractChanged) {
			$this->audit->log($caseId, 'CONTRACT', (int)$contractProtection['documentId'], 'CONTRACT_AMENDMENT', ['contract' => $contractProtection, 'services' => $before], ['reason' => $amendmentReason, 'services' => $after]);
		}
		return $after;
	}

	/** Auswahlregeln der Warengruppen über Einzel- und Paketpositionen (Pflicht erst bei Festschreibung). */
	public function selectionRuleViolations(array $items, bool $includeMandatory): array {
		$groups = $this->structure->groupMap();
		$byGroup = [];
		foreach ($items as $item) {
			if (($item['serviceStatus'] ?? '') === 'STORNIERT' || !empty($item['isPackageHeader']) || (int)($item['articleId'] ?? 0) === 0) continue;
			$key = (string)($item['articleGroupKey'] ?? '');
			if ($key === '') continue;
			$byGroup[$key][(int)$item['articleId']] = (string)$item['title'];
		}
		$messages = [];
		foreach ($groups as $key => $group) {
			$entries = $byGroup[$key] ?? [];
			if (in_array($group['selectionRule'], ['MAX_ONE', 'EXACTLY_ONE'], true) && count($entries) > 1) $messages[] = ['groupKey' => (string)$key, 'type' => 'TOO_MANY', 'message' => 'In der Warengruppe „' . $group['name'] . '“ ist nur eine Position zulässig. Enthalten: ' . implode(', ', array_values($entries)) . '.'];
			if ($includeMandatory && $group['selectionRule'] === 'EXACTLY_ONE' && $group['active'] && $entries === []) $messages[] = ['groupKey' => (string)$key, 'type' => 'MISSING', 'message' => 'Aus der Warengruppe „' . $group['name'] . '“ muss genau eine Position gewählt sein.'];
		}
		return $messages;
	}

	private function assertSelectionRules(array $specs, array $groups): void {
		$items = [];
		foreach ($specs as $spec) {
			if (($spec['line']['itemCategory'] ?? 'NORMAL') !== 'PACKAGE') $items[] = $spec['line'] + ['serviceStatus' => 'BEAUFTRAGT'];
			foreach ($spec['children'] ?? [] as $child) $items[] = $child['line'] + ['serviceStatus' => 'BEAUFTRAGT'];
		}
		$violations = array_filter($this->selectionRuleViolations($items, false), static fn(array $entry): bool => $entry['type'] === 'TOO_MANY');
		if ($violations !== []) throw new \InvalidArgumentException(implode(' ', array_column($violations, 'message')));
	}

	private function freeTextSpec(array $selection, ?array $existing): array {
		$title = trim((string)($selection['title'] ?? ''));
		if ($title === '') throw new \InvalidArgumentException('Eine Freitextposition benötigt eine Bezeichnung.');
		$quantityMilli = $this->quantityToMilli($selection['quantity'] ?? 1);
		if ($quantityMilli <= 0) throw new \InvalidArgumentException('Die Positionsmenge muss größer als null sein.');
		$positionType = strtoupper(trim((string)($selection['positionType'] ?? 'EL')));
		if (!in_array($positionType, ['EL', 'FK', 'DP'], true)) throw new \InvalidArgumentException('Ungültiger Positionstyp.');
		$unitPriceCents = (int)($selection['unitPriceCents'] ?? 0);
		if ($unitPriceCents <= 0) throw new \InvalidArgumentException('Eine Freitextposition benötigt einen Preis größer als 0,00 Euro.');
		$vatRate = $positionType === 'DP' ? 0 : (int)($selection['vatRate'] ?? 19);
		if (!in_array($vatRate, $this->countryConfiguration->allowedVatRates(), true)) throw new \InvalidArgumentException('Der Mehrwertsteuersatz der Freitextposition ist nicht zulässig.');
		return ['existing' => $existing, 'line' => $this->line([
			'articleId' => 0, 'articleNumber' => '', 'title' => $title, 'longText' => trim((string)($selection['longText'] ?? '')), 'note' => trim((string)($selection['note'] ?? '')),
			'category' => 'Freitext', 'articleGroup' => 'Freitext', 'articleGroupKey' => null, 'articleType' => null,
			'costType' => $positionType === 'EL' ? 'INTERNAL' : ($positionType === 'FK' ? 'THIRD_PARTY' : 'EXPENSE'),
			'taxClass' => CatalogRules::taxClassForRate($vatRate, $positionType), 'quantityMilli' => $quantityMilli, 'unitPriceCents' => $unitPriceCents, 'listPriceCents' => $unitPriceCents,
			'vatRate' => $vatRate, 'unit' => $this->validatedUnit((string)($selection['unit'] ?? 'STK')), 'quantityDecimals' => 3, 'positionType' => $positionType,
			'origin' => $existing['origin'] ?? 'FREE_TEXT',
		])];
	}

	private function catalogSpec(array $article, array $selection, ?array $existing, array $prices, array $groups, array $context): array {
		$quantityMilli = $this->quantityToMilli($selection['quantity'] ?? ($existing['quantity'] ?? 1));
		if ($quantityMilli <= 0) throw new \InvalidArgumentException('Die beauftragte Menge muss größer als null sein.');
		$positionType = strtoupper(trim((string)($selection['positionType'] ?? ($existing['positionType'] ?? CatalogRules::positionType((string)$article['articleType'])))));
		if (!in_array($positionType, ['EL', 'FK', 'DP'], true)) throw new \InvalidArgumentException('Ungültiger Positionstyp. Zulässig sind EL, FK und DP.');
		if ($existing !== null) {
			$decimals = (int)$existing['quantityDecimals'];
			$this->validateQuantityPrecision($quantityMilli, $decimals, (string)$existing['unit']);
			$taxClass = (string)($existing['taxClass'] ?: CatalogRules::taxClassForRate((int)$existing['vatRate'], (string)$existing['positionType']));
			// Echte Auslage (DP) ist immer ohne Umsatzsteuer; beim Wechsel zurück gilt die Steuerklasse des Snapshots.
			$vatRate = $positionType === (string)$existing['positionType'] ? (int)$existing['vatRate'] : ($positionType === 'DP' ? 0 : CatalogRules::taxRate($taxClass === 'A' ? 'V' : $taxClass, $context['country']));
			if ($positionType === 'DP') $vatRate = 0;
			$price = array_key_exists('unitPriceCents', $selection) ? max(0, (int)$selection['unitPriceCents']) : (int)$existing['unitPriceCents'];
			if ($existing['sourcePackageId'] !== null) $price = (int)$existing['unitPriceCents'];
			return ['existing' => $existing, 'line' => $this->line([
				'articleId' => (int)$article['id'], 'articleNumber' => $existing['articleNumber'], 'title' => $existing['title'], 'longText' => $existing['longText'],
				'note' => trim((string)($selection['note'] ?? $existing['note'])), 'category' => $existing['category'], 'articleGroup' => $existing['articleGroup'],
				'articleGroupKey' => $existing['articleGroupKey'], 'articleType' => $existing['articleType'], 'costType' => $existing['costType'], 'taxClass' => $taxClass,
				'quantityMilli' => $quantityMilli, 'unitPriceCents' => $price, 'listPriceCents' => (int)$existing['listPriceCents'], 'vatRate' => $vatRate,
				'unit' => $this->validatedUnit((string)($selection['unit'] ?? $existing['unit'])), 'quantityDecimals' => $decimals, 'positionType' => $positionType,
				'origin' => (string)$existing['origin'], 'priceOnEntry' => (bool)$existing['priceOnEntry'], 'guidePriceCents' => $existing['guidePriceCents'],
				'sourcePackageId' => $existing['sourcePackageId'], 'sourcePackageName' => $existing['sourcePackageName'],
			])];
		}
		$this->validateQuantityPrecision($quantityMilli, (int)$article['quantityDecimals'], (string)$article['unit']);
		$listPrice = $prices[(int)$article['id']]['cents'] ?? 0;
		$price = !empty($selection['priceEdited']) ? max(0, (int)($selection['unitPriceCents'] ?? 0)) : ($article['priceOnEntry'] ? 0 : $listPrice);
		$taxClass = (string)$article['taxClass'];
		return ['existing' => null, 'line' => $this->line([
			'articleId' => (int)$article['id'], 'articleNumber' => $article['articleNumber'], 'title' => $article['shortName'], 'longText' => $article['longText'],
			'note' => trim((string)($selection['note'] ?? '')), 'category' => $article['articleTypeLabel'], 'articleGroup' => (string)($groups[$article['articleGroup']]['name'] ?? $article['articleGroup']),
			'articleGroupKey' => $article['articleGroup'], 'articleType' => $article['articleType'], 'costType' => $article['costType'], 'taxClass' => $taxClass,
			'quantityMilli' => $quantityMilli, 'unitPriceCents' => $price, 'listPriceCents' => $listPrice,
			'vatRate' => $positionType === 'DP' ? 0 : CatalogRules::taxRate($taxClass, $context['country']),
			'unit' => $this->validatedUnit((string)($selection['unit'] ?? $article['unit'])), 'quantityDecimals' => (int)$article['quantityDecimals'], 'positionType' => $positionType,
			'origin' => 'ORDER', 'priceOnEntry' => (bool)$article['priceOnEntry'], 'guidePriceCents' => $article['guidePriceCents'],
		])];
	}

	/**
	 * Paket = Kopfposition + Unterpositionen aus der Stückliste. Bei Festpreis trägt der Kopf den
	 * vereinbarten Paketpreis; er wird anteilig nach Listenpreisen auf die Unterpositionen verteilt
	 * (jede behält ihre Steuerklasse). Bei „Summe der Positionen“ gilt der Preis je Unterposition.
	 */
	private function packageSpec(array $package, array $selection, ?array $header, array $articles, array $prices, array $groups, array $context, array $allItems): array {
		$bom = $package['bom'] ?? null;
		if (!is_array($bom) || ($bom['items'] ?? []) === []) throw new \InvalidArgumentException('Das Paket „' . $package['shortName'] . '“ hat noch keine Stückliste.');
		$existingChildren = [];
		if ($header !== null) {
			foreach ($allItems as $item) if ((int)($item['parentServiceId'] ?? 0) === (int)$header['id'] && ($item['serviceStatus'] ?? '') !== 'STORNIERT') $existingChildren[(int)($item['bomItemId'] ?? 0)] = $item;
		}
		$bomItems = [];
		foreach ($bom['items'] as $item) $bomItems[(int)$item['id']] = $item;
		// Auswahl: gesendete Konfiguration, sonst die bestehenden Unterpositionen, sonst der Stücklisten-Standard.
		$requested = null;
		if (is_array($selection['packageConfig']['selected'] ?? null)) $requested = array_map('intval', $selection['packageConfig']['selected']);
		elseif ($header !== null) $requested = array_keys($existingChildren);
		$selected = [];
		$alternativeChosen = [];
		foreach ($bomItems as $id => $item) {
			if ($item['itemType'] === 'FIXED') $selected[$id] = true;
			elseif ($item['itemType'] === 'OPTIONAL') { if ($requested === null || in_array($id, $requested, true)) $selected[$id] = true; }
			elseif ($requested !== null && in_array($id, $requested, true) && !isset($alternativeChosen[$item['altGroup']])) { $selected[$id] = true; $alternativeChosen[$item['altGroup']] = $id; }
		}
		foreach ($bomItems as $id => $item) {
			if ($item['itemType'] !== 'ALTERNATIVE' || isset($alternativeChosen[$item['altGroup']]) || !$item['isDefault']) continue;
			$selected[$id] = true; $alternativeChosen[$item['altGroup']] = $id;
		}
		// Unterpositionen, deren Stücklistenposition inzwischen entfernt wurde, bleiben als Snapshot erhalten.
		foreach ($existingChildren as $bomItemId => $child) if (!isset($bomItems[$bomItemId]) && ($requested === null || in_array($bomItemId, $requested, true))) $selected[$bomItemId] = true;

		$pricing = (string)($header['packagePricing'] ?? $bom['pricing']);
		$selectedIds = array_keys($selected); sort($selectedIds);
		$existingIds = array_keys($existingChildren); sort($existingIds);
		$configChanged = $header === null || $selectedIds !== $existingIds;
		$listPrice = $header !== null ? (int)$header['listPriceCents'] : ($prices[(int)$package['id']]['cents'] ?? 0);
		$packagePrice = array_key_exists('packagePriceCents', $selection) && $selection['packagePriceCents'] !== null && $selection['packagePriceCents'] !== '' ? max(0, (int)$selection['packagePriceCents']) : ($header !== null ? (int)$header['packagePriceCents'] : $listPrice);
		$printMode = strtoupper((string)($selection['printMode'] ?? ($header['printMode'] ?? $bom['printMode'])));
		if (!isset(CatalogRules::PRINT_MODES[$printMode])) $printMode = (string)$bom['printMode'];
		$credit = 0;
		if ($configChanged) {
			foreach ($bomItems as $id => $item) if ($item['itemType'] === 'OPTIONAL' && !isset($selected[$id])) $credit += (int)$item['creditCents'];
		}
		$headerSurcharge = $configChanged ? -$credit : (int)($header['surchargeCents'] ?? 0);

		$children = [];
		$defaults = [];
		foreach ($bomItems as $item) if ($item['itemType'] === 'ALTERNATIVE' && $item['isDefault']) $defaults[$item['altGroup']] = $item;
		foreach (array_keys($selected) as $bomItemId) {
			$existing = $existingChildren[$bomItemId] ?? null;
			if ($existing !== null) {
				$children[] = ['existing' => $existing, 'bomItemNo' => (int)($bomItems[$bomItemId]['itemNo'] ?? 9999), 'anchor' => ($bomItems[$bomItemId]['itemType'] ?? 'FIXED') === 'FIXED', 'line' => $this->line([
					'articleId' => (int)$existing['articleId'], 'articleNumber' => $existing['articleNumber'], 'title' => $existing['title'], 'longText' => $existing['longText'], 'note' => $existing['note'],
					'category' => $existing['category'], 'articleGroup' => $existing['articleGroup'], 'articleGroupKey' => $existing['articleGroupKey'], 'articleType' => $existing['articleType'],
					'costType' => $existing['costType'], 'taxClass' => $existing['taxClass'], 'quantityMilli' => (int)$existing['orderedQuantityMilli'], 'unitPriceCents' => (int)$existing['unitPriceCents'],
					'listPriceCents' => (int)$existing['listPriceCents'], 'surchargeCents' => (int)$existing['surchargeCents'], 'vatRate' => (int)$existing['vatRate'], 'unit' => $existing['unit'],
					'quantityDecimals' => (int)$existing['quantityDecimals'], 'positionType' => $existing['positionType'], 'origin' => (string)$existing['origin'],
					'itemCategory' => 'BOM_ITEM', 'bomItemId' => $bomItemId,
				])];
				continue;
			}
			$item = $bomItems[$bomItemId];
			$component = $articles[(int)$item['articleId']] ?? null;
			if ($component === null) continue;
			$componentList = $prices[(int)$component['id']]['cents'] ?? 0;
			$surcharge = 0;
			if ($pricing === 'HEADER' && $item['itemType'] === 'ALTERNATIVE' && isset($defaults[$item['altGroup']]) && (int)$defaults[$item['altGroup']]['id'] !== $bomItemId) {
				$default = $defaults[$item['altGroup']];
				$surcharge = (int)round($item['quantityMilli'] * $componentList / 1000) - (int)round($default['quantityMilli'] * ($prices[(int)$default['articleId']]['cents'] ?? 0) / 1000);
			}
			$taxClass = (string)$component['taxClass'];
			$positionType = CatalogRules::positionType((string)$component['articleType']);
			$children[] = ['existing' => null, 'bomItemNo' => (int)$item['itemNo'], 'anchor' => $item['itemType'] === 'FIXED', 'line' => $this->line([
				'articleId' => (int)$component['id'], 'articleNumber' => $component['articleNumber'], 'title' => $component['shortName'], 'longText' => $component['longText'], 'note' => '',
				'category' => $component['articleTypeLabel'], 'articleGroup' => (string)($groups[$component['articleGroup']]['name'] ?? $component['articleGroup']), 'articleGroupKey' => $component['articleGroup'],
				'articleType' => $component['articleType'], 'costType' => $component['costType'], 'taxClass' => $taxClass, 'quantityMilli' => (int)$item['quantityMilli'],
				'unitPriceCents' => $componentList, 'listPriceCents' => $componentList, 'surchargeCents' => $surcharge,
				'vatRate' => $positionType === 'DP' ? 0 : CatalogRules::taxRate($taxClass, $context['country']), 'unit' => (string)$component['unit'],
				'quantityDecimals' => (int)$component['quantityDecimals'], 'positionType' => $positionType, 'origin' => 'ORDER', 'itemCategory' => 'BOM_ITEM', 'bomItemId' => $bomItemId,
			])];
		}
		usort($children, static fn(array $left, array $right): int => $left['bomItemNo'] <=> $right['bomItemNo']);
		if ($pricing === 'HEADER') {
			$target = $packagePrice + $headerSurcharge + array_sum(array_map(static fn(array $child): int => (int)$child['line']['surchargeCents'], $children));
			$allocation = CatalogRules::allocate($target, array_combine(array_keys($children), array_map(static fn(array $child): array => [
				'listLineCents' => (int)round((int)$child['line']['quantityMilli'] * (int)$child['line']['listPriceCents'] / 1000),
				'quantityMilli' => (int)$child['line']['quantityMilli'], 'anchor' => (bool)$child['anchor'],
			], $children)) ?: []);
			foreach ($allocation as $index => $unitPrice) $children[$index]['line']['unitPriceCents'] = $unitPrice;
		}
		return ['existing' => $header, 'children' => $children, 'line' => $this->line([
			'articleId' => (int)$package['id'], 'articleNumber' => $header['articleNumber'] ?? $package['articleNumber'], 'title' => $header['title'] ?? $package['shortName'],
			'longText' => $header['longText'] ?? $package['longText'], 'note' => trim((string)($selection['note'] ?? ($header['note'] ?? ''))),
			'category' => $header['category'] ?? $package['articleTypeLabel'], 'articleGroup' => $header['articleGroup'] ?? (string)($groups[$package['articleGroup']]['name'] ?? $package['articleGroup']),
			'articleGroupKey' => $header['articleGroupKey'] ?? $package['articleGroup'], 'articleType' => 'PAKET', 'costType' => 'INTERNAL', 'taxClass' => 'V',
			'quantityMilli' => 1000, 'unitPriceCents' => 0, 'listPriceCents' => $listPrice, 'surchargeCents' => $headerSurcharge, 'vatRate' => 0, 'unit' => 'PAUSCHAL', 'quantityDecimals' => 0,
			'positionType' => 'EL', 'origin' => (string)($header['origin'] ?? 'ORDER'), 'itemCategory' => 'PACKAGE', 'packagePricing' => $pricing,
			'packagePriceCents' => $pricing === 'HEADER' ? $packagePrice : null, 'printMode' => $printMode,
		])];
	}

	private function line(array $values): array {
		return $values + [
			'note' => '', 'longText' => '', 'surchargeCents' => 0, 'itemCategory' => 'NORMAL', 'bomItemId' => null, 'packagePricing' => null, 'packagePriceCents' => null,
			'printMode' => null, 'priceOnEntry' => false, 'guidePriceCents' => null, 'sourcePackageId' => null, 'sourcePackageName' => '', 'listPriceCents' => 0,
		];
	}

	private function contractValuesDiffer(array $current, array $line): bool {
		return (int)$current['orderedQuantityMilli'] !== (int)$line['quantityMilli']
			|| (int)$current['unitPriceCents'] !== (int)$line['unitPriceCents']
			|| (string)($current['unit'] ?? 'STK') !== (string)$line['unit']
			|| (string)($current['positionType'] ?? 'EL') !== (string)$line['positionType']
			|| (string)($current['title'] ?? '') !== (string)$line['title']
			|| (int)($current['vatRate'] ?? 0) !== (int)$line['vatRate']
			|| (int)($current['packagePriceCents'] ?? 0) !== (int)($line['packagePriceCents'] ?? 0);
	}

	private function persistLine(int $caseId, ?int $sideOrderId, array $line, ?array $existing, int $position, int $subPosition, ?int $parentId, bool $markNew): int {
		$now = date('c');
		$values = [
			'article_id' => $line['articleId'] ?: null, 'source_package_id' => $line['sourcePackageId'], 'source_package_name' => $line['sourcePackageName'] ?: null,
			'article_number' => $line['articleNumber'], 'title' => $line['title'], 'long_text' => $line['longText'], 'article_category' => $line['category'] ?? '',
			'note' => $line['note'] ?? '', 'article_group' => (string)$line['articleGroup'], 'article_group_key' => $line['articleGroupKey'], 'article_type' => $line['articleType'],
			'cost_type' => $line['costType'], 'position_type' => $line['positionType'], 'tax_class' => $line['taxClass'],
			'quantity_milli' => $line['quantityMilli'], 'ordered_quantity_milli' => $line['quantityMilli'], 'unit_price_cents' => $line['unitPriceCents'],
			'list_price_cents' => (int)$line['listPriceCents'], 'surcharge_cents' => (int)$line['surchargeCents'], 'vat_rate' => $line['vatRate'], 'unit' => $line['unit'],
			'quantity_decimals' => $line['quantityDecimals'], 'sort_order' => $position, 'sub_position' => $subPosition, 'parent_service_id' => $parentId,
			'item_category' => $line['itemCategory'], 'bom_item_id' => $line['bomItemId'], 'package_pricing' => $line['packagePricing'],
			'package_price_cents' => $line['packagePriceCents'], 'print_mode' => $line['printMode'], 'guide_price_cents' => $line['guidePriceCents'],
		];
		$query = $this->db->getQueryBuilder();
		if ($existing !== null) {
			$query->update('bestatter_case_services');
			foreach ($values as $column => $value) $query->set($column, $query->createNamedParameter($value));
			$query->set('origin', $query->createNamedParameter($line['origin']))
				->set('price_on_entry', $query->createNamedParameter((bool)$line['priceOnEntry'], IQueryBuilder::PARAM_BOOL))
				->set('updated_at', $query->createNamedParameter($now))
				->where($query->expr()->eq('id', $query->createNamedParameter((int)$existing['id'])))->executeStatement();
			return (int)$existing['id'];
		}
		$insert = ['case_id' => $query->createNamedParameter($caseId), 'side_order_id' => $query->createNamedParameter($sideOrderId)];
		foreach ($values as $column => $value) $insert[$column] = $query->createNamedParameter($value);
		$insert += [
			'origin' => $query->createNamedParameter($markNew ? 'NACHTRAG' : $line['origin']),
			'price_on_entry' => $query->createNamedParameter((bool)$line['priceOnEntry'], IQueryBuilder::PARAM_BOOL),
			'performed_quantity_milli' => $query->createNamedParameter(0), 'invoiced_quantity_milli' => $query->createNamedParameter(0),
			'service_status' => $query->createNamedParameter('BEAUFTRAGT'),
			'billability' => $query->createNamedParameter($line['itemCategory'] === 'PACKAGE' ? 'NICHT_ABRECHENBAR' : 'ABRECHENBAR'),
			'classification_reason' => $query->createNamedParameter($line['itemCategory'] === 'PACKAGE' ? 'Paket-Kopfposition; abgerechnet werden die Unterpositionen.' : null),
			'created_at' => $query->createNamedParameter($now), 'updated_at' => $query->createNamedParameter($now),
		];
		$query->insert('bestatter_case_services')->values($insert)->executeStatement();
		return (int)$this->db->lastInsertId('bestatter_case_services');
	}

	private function contractProtection(int $caseId, ?int $sideOrderId = null): array {
		return $this->commercialState->state($caseId, $sideOrderId);
	}

	private function caseContext(int $caseId): array {
		$query = $this->db->getQueryBuilder();
		$branch = (string)($query->select('branch')->from('bestatter_cases')->where($query->expr()->eq('id', $query->createNamedParameter($caseId)))->executeQuery()->fetchOne() ?: '');
		$country = 'DE';
		if ($branch !== '') $country = (string)($this->configuration->branchByKey($branch)['countryCode'] ?? 'DE');
		return ['branch' => $branch, 'country' => $country ?: 'DE'];
	}

	private function mapArticle(array $row, array $groups, array $prices, array $boms): array {
		$id = (int)$row['id'];
		$type = (string)($row['article_type'] ?? '') ?: ((string)$row['item_type'] === 'PACKAGE' ? 'PAKET' : 'DIENSTLEISTUNG');
		$groupKey = (string)$row['article_group'];
		$group = $groups[$groupKey] ?? null;
		$taxClass = (string)($row['tax_class'] ?? 'V') ?: 'V';
		$bom = $boms[$id] ?? null;
		return [
			'id' => $id,
			'itemType' => $type === 'PAKET' ? 'PACKAGE' : 'SINGLE',
			'articleNumber' => (string)$row['article_number'],
			'searchTerm' => (string)($row['search_term'] ?? ''),
			'shortName' => (string)$row['short_name'],
			'longText' => (string)($row['long_text'] ?? ''),
			'internalNote' => (string)($row['internal_note'] ?? ''),
			'articleType' => $type,
			'articleTypeLabel' => CatalogRules::ARTICLE_TYPES[$type]['label'] ?? $type,
			'category' => CatalogRules::ARTICLE_TYPES[$type]['label'] ?? $type,
			'articleGroup' => $groupKey,
			'articleGroupName' => (string)($group['name'] ?? $groupKey),
			'areaKey' => (string)($group['areaKey'] ?? ''),
			'areaName' => (string)($group['areaName'] ?? ''),
			'areaSortOrder' => (int)($group['areaSortOrder'] ?? 9999),
			'groupSortOrder' => (int)($group['sortOrder'] ?? 9999),
			'selectionRule' => (string)($group['selectionRule'] ?? 'MANY'),
			'funeralScope' => (string)($group['funeralScope'] ?? 'ALL'),
			'exclusiveGroup' => ($group['selectionRule'] ?? 'MANY') !== 'MANY',
			'taxClass' => $taxClass,
			'vatRate' => CatalogRules::taxRate($taxClass),
			'costType' => CatalogRules::costType($type),
			'positionType' => CatalogRules::positionType($type),
			'unit' => (string)($row['unit'] ?? 'STK'),
			'quantityDecimals' => (int)($row['quantity_decimals'] ?? 0),
			'supplierContact' => (string)($row['supplier_contact'] ?? ''),
			'photoPath' => (string)($row['photo_path'] ?? ''),
			'purchasePriceCents' => (int)$row['purchase_price_cents'],
			'salesPriceCents' => $prices[$id]['cents'] ?? 0,
			'hasPrice' => isset($prices[$id]),
			'priceValidFrom' => $prices[$id]['validFrom'] ?? '',
			'priceOnEntry' => (bool)($row['price_on_entry'] ?? false),
			'guidePriceCents' => $row['guide_price_cents'] !== null ? (int)$row['guide_price_cents'] : null,
			'active' => (bool)$row['active'],
			'createdAt' => $row['created_at'],
			'updatedAt' => $row['updated_at'],
			'bom' => $bom,
			// Kompatibilität: frühere Paketkomponenten entsprechen den Stücklistenpositionen.
			'components' => array_map(static fn(array $item): array => ['articleId' => $item['articleId'], 'articleNumber' => $item['articleNumber'], 'shortName' => $item['shortName'], 'articleGroup' => $item['articleGroup'], 'quantityMilli' => $item['quantityMilli'], 'quantity' => $item['quantity'], 'itemType' => $item['itemType'], 'altGroup' => $item['altGroup'], 'isDefault' => $item['isDefault']], $bom['items'] ?? []),
		];
	}

	private function validateArticle(array $input, ?array $current): array {
		$articleNumber = trim((string)($input['articleNumber'] ?? $input['article_number'] ?? ''));
		$shortName = trim((string)($input['shortName'] ?? $input['short_name'] ?? ''));
		if ($articleNumber === '' || $shortName === '') throw new \InvalidArgumentException('Artikelnummer und Kurztext sind Pflichtfelder.');
		if (mb_strlen($articleNumber) > 50 || mb_strlen($shortName) > 180) throw new \InvalidArgumentException('Artikelnummer (höchstens 50) oder Kurztext (höchstens 180 Zeichen) ist zu lang.');
		if ($current !== null && $articleNumber !== $current['articleNumber']) throw new \InvalidArgumentException('Die Artikelnummer ist nach der Anlage schreibgeschützt. Für eine neue Nummer bitte einen neuen Artikel anlegen und den alten deaktivieren.');
		$groups = $this->structure->groupMap();
		$group = trim((string)($input['articleGroup'] ?? $input['article_group'] ?? ($current['articleGroup'] ?? '')));
		if (!isset($groups[$group])) {
			foreach ($groups as $key => $candidate) if (mb_strtolower($candidate['name']) === mb_strtolower($group)) { $group = (string)$key; break; }
		}
		// „210 · Särge“ bzw. „210 – Särge“ (Auswahlliste der Installations-Checkliste)
		if (!isset($groups[$group]) && preg_match('/^([0-9A-Z]{1,20})\s*[·\-–:]/u', strtoupper($group), $match) && isset($groups[$match[1]])) $group = $match[1];
		if (!isset($groups[$group])) throw new \InvalidArgumentException('Bitte eine gültige Warengruppe wählen.');
		$type = strtoupper(trim((string)($input['articleType'] ?? $input['article_type'] ?? '')));
		if ($type !== '' && !isset(CatalogRules::ARTICLE_TYPES[$type])) {
			foreach (CatalogRules::ARTICLE_TYPES as $code => $definition) if (mb_strtolower($definition['label']) === mb_strtolower(trim((string)($input['articleType'] ?? ''))) || ($code === 'PAKET' && $type === 'PAKET (STÜCKLISTE)')) { $type = $code; break; }
			if ($type === 'AUSLAGE/GEBÜHR' || $type === 'AUSLAGE / GEBÜHR') $type = 'AUSLAGE';
		}
		if ($type === '') {
			$legacyType = strtoupper(trim((string)($input['itemType'] ?? $input['item_type'] ?? '')));
			$legacyCost = strtoupper(trim((string)($input['costType'] ?? $input['cost_type'] ?? '')));
			$type = $legacyType === 'PACKAGE' ? 'PAKET' : match ($legacyCost) { 'THIRD_PARTY' => 'FREMDLEISTUNG', 'EXPENSE' => 'AUSLAGE', default => (string)($current['articleType'] ?? 'DIENSTLEISTUNG') };
		}
		if (!isset(CatalogRules::ARTICLE_TYPES[$type])) throw new \InvalidArgumentException('Unbekannte Artikelart.');
		$taxClass = strtoupper(trim((string)($input['taxClass'] ?? $input['tax_class'] ?? '')));
		if (mb_strlen($taxClass) > 1 && isset(CatalogRules::TAX_CLASSES[mb_substr($taxClass, 0, 1)]) && preg_match('/^[VEA](\s|–|-|$)/u', $taxClass)) $taxClass = mb_substr($taxClass, 0, 1);
		if ($taxClass === '') {
			$vat = $input['vatRate'] ?? $input['vat_rate'] ?? null;
			$taxClass = $vat !== null && $vat !== '' ? CatalogRules::taxClassForRate((int)$vat, $type === 'AUSLAGE' ? 'DP' : 'EL') : (string)($current['taxClass'] ?? ($type === 'AUSLAGE' ? 'A' : 'V'));
		}
		if (!isset(CatalogRules::TAX_CLASSES[$taxClass])) throw new \InvalidArgumentException('Unbekannte Steuerklasse.');
		if ($type === 'PAKET') $taxClass = 'V';
		$unit = $this->validatedUnit((string)($input['unit'] ?? ($current['unit'] ?? 'STK')));
		$searchTerm = strtoupper(trim((string)($input['searchTerm'] ?? $input['search_term'] ?? ($current['searchTerm'] ?? ''))));
		if (mb_strlen($searchTerm) > 60) throw new \InvalidArgumentException('Der Suchbegriff darf höchstens 60 Zeichen lang sein.');
		$priceOnEntry = CsvTools::flag($input['priceOnEntry'] ?? $input['price_on_entry'] ?? null, (bool)($current['priceOnEntry'] ?? false));
		$guide = $input['guidePrice'] ?? $input['guide_price'] ?? null;
		$guideCents = isset($input['guidePriceCents']) ? ($input['guidePriceCents'] === null || $input['guidePriceCents'] === '' ? null : max(0, (int)$input['guidePriceCents'])) : ($guide !== null && trim((string)$guide) !== '' ? $this->moneyToCents($guide) : ($current['guidePriceCents'] ?? null));
		$salesInput = $input['salesPrice'] ?? $input['sales_price'] ?? null;
		$salesCents = isset($input['salesPriceCents']) ? max(0, (int)$input['salesPriceCents']) : ($salesInput !== null && trim((string)$salesInput) !== '' ? $this->moneyToCents($salesInput) : null);
		$validFrom = CsvTools::date($input['priceValidFrom'] ?? $input['validFrom'] ?? '', 'Preis gültig ab');
		return [
			'articleNumber' => $articleNumber,
			'shortName' => $shortName,
			'longText' => trim((string)($input['longText'] ?? $input['long_text'] ?? ($current['longText'] ?? ''))),
			'internalNote' => trim((string)($input['internalNote'] ?? $input['internal_note'] ?? ($current['internalNote'] ?? ''))),
			'searchTerm' => $searchTerm !== '' ? $searchTerm : null,
			'articleGroup' => $group,
			'articleType' => $type,
			'taxClass' => $taxClass,
			'unit' => $unit,
			'quantityDecimals' => max(0, min(3, (int)($input['quantityDecimals'] ?? $input['quantity_decimals'] ?? ($current['quantityDecimals'] ?? 0)))),
			'supplierContact' => trim((string)($input['supplierContact'] ?? $input['supplier_contact'] ?? $input['supplier'] ?? ($current['supplierContact'] ?? ''))),
			'photoPath' => trim((string)($input['photoPath'] ?? $input['photo_path'] ?? ($current['photoPath'] ?? ''))),
			'purchasePriceCents' => isset($input['purchasePriceCents']) ? max(0, (int)$input['purchasePriceCents']) : (isset($input['purchasePrice']) || isset($input['purchase_price']) ? $this->moneyToCents($input['purchasePrice'] ?? $input['purchase_price']) : (int)($current['purchasePriceCents'] ?? 0)),
			'salesPriceCents' => $salesCents,
			'priceValidFrom' => $validFrom,
			'priceOnEntry' => $priceOnEntry,
			'guidePriceCents' => $guideCents,
			'active' => CsvTools::flag($input['active'] ?? null, (bool)($current['active'] ?? true)),
		];
	}

	/** Spiegelt den heute gültigen Standardpreis in die Altspalte (Exporte, ältere Auswertungen). */
	private function syncMirrorPrice(int $id): void {
		$price = $this->priceLists->prices()[$id]['cents'] ?? 0;
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_articles')->set('sales_price_cents', $query->createNamedParameter($price))->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeStatement();
	}

	private function linkPackages(array $items): array {
		$headers = [];
		foreach ($items as $item) if ($item['isPackageHeader']) $headers[(int)$item['id']] = $item;
		$childrenByHeader = [];
		foreach ($items as $index => $item) {
			$parent = $item['parentServiceId'] !== null ? ($headers[(int)$item['parentServiceId']] ?? null) : null;
			if ($parent === null) continue;
			$items[$index]['packageServiceId'] = (int)$parent['id'];
			$items[$index]['packageTitle'] = (string)$parent['title'];
			$items[$index]['packagePosition'] = (int)$parent['position'];
			$items[$index]['packagePricingOfParent'] = (string)($parent['packagePricing'] ?? 'ITEMS');
			$items[$index]['printMode'] = (string)($parent['printMode'] ?? 'ITEMS_NO_PRICES');
			if (($item['serviceStatus'] ?? '') !== 'STORNIERT') $childrenByHeader[(int)$parent['id']][] = $items[$index];
		}
		foreach ($items as $index => $item) {
			if (!$item['isPackageHeader']) continue;
			$children = $childrenByHeader[(int)$item['id']] ?? [];
			$sum = static fn(string $key): int => array_sum(array_map(static fn(array $child): int => (int)$child[$key], $children));
			$items[$index]['packageNetCents'] = $sum('netCents');
			$items[$index]['packageVatCents'] = $sum('vatCents');
			$items[$index]['packageGrossCents'] = $sum('grossCents');
			$items[$index]['childCount'] = count($children);
			if (($item['serviceStatus'] ?? '') !== 'STORNIERT') {
				$items[$index]['serviceStatus'] = CatalogRules::aggregateStatus(array_map(static fn(array $child): string => (string)$child['serviceStatus'], $children));
				$items[$index]['serviceStatusLabel'] = CatalogRules::SERVICE_STATUS_LABELS[$items[$index]['serviceStatus']] ?? $items[$index]['serviceStatus'];
			}
		}
		return $items;
	}

	private function mapCaseService(array $row): array {
		$quantityMilli = (int)$row['quantity_milli'];
		$category = (string)($row['item_category'] ?? 'NORMAL') ?: 'NORMAL';
		$isHeader = $category === 'PACKAGE';
		$netCents = $isHeader ? 0 : (int)round($quantityMilli * (int)$row['unit_price_cents'] / 1000);
		$vatCents = (int)round($netCents * (int)$row['vat_rate'] / 100);
		$performedQuantityMilli = (int)($row['performed_quantity_milli'] ?? 0);
		$performedNetCents = $isHeader ? 0 : (int)round($performedQuantityMilli * (int)$row['unit_price_cents'] / 1000);
		$performedVatCents = (int)round($performedNetCents * (int)$row['vat_rate'] / 100);
		$ordered = (int)($row['ordered_quantity_milli'] ?? $quantityMilli);
		$invoiced = (int)($row['invoiced_quantity_milli'] ?? 0);
		$status = CatalogRules::deriveStatus($ordered, $performedQuantityMilli, $invoiced, (string)($row['service_status'] ?? ''));
		$position = (int)($row['sort_order'] ?? 0);
		$sub = (int)($row['sub_position'] ?? 0);
		return [
			'id' => (int)$row['id'],
			'sideOrderId' => $row['side_order_id'] !== null ? (int)$row['side_order_id'] : null,
			'articleId' => (int)($row['article_id'] ?? 0),
			'sourcePackageId' => $row['source_package_id'] !== null ? (int)$row['source_package_id'] : null,
			'sourcePackageName' => (string)($row['source_package_name'] ?? ''),
			'itemCategory' => $category,
			'isPackageHeader' => $isHeader,
			'parentServiceId' => $row['parent_service_id'] !== null ? (int)$row['parent_service_id'] : null,
			'bomItemId' => $row['bom_item_id'] !== null ? (int)$row['bom_item_id'] : null,
			'packageServiceId' => null, 'packageTitle' => '', 'packagePosition' => null,
			'packagePricing' => $row['package_pricing'] !== null ? (string)$row['package_pricing'] : null,
			'packagePriceCents' => $row['package_price_cents'] !== null ? (int)$row['package_price_cents'] : null,
			'printMode' => $row['print_mode'] !== null ? (string)$row['print_mode'] : null,
			'articleNumber' => (string)$row['article_number'],
			'title' => (string)$row['title'],
			'longText' => (string)($row['long_text'] ?? ''),
			'category' => (string)($row['article_category'] ?? ''),
			'note' => (string)($row['note'] ?? ''),
			'articleGroup' => (string)$row['article_group'],
			'articleGroupKey' => $row['article_group_key'] !== null ? (string)$row['article_group_key'] : null,
			'articleType' => $row['article_type'] !== null ? (string)$row['article_type'] : null,
			'taxClass' => (string)($row['tax_class'] ?? ''),
			'costType' => (string)$row['cost_type'],
			'position' => $position,
			'subPosition' => $sub,
			'positionLabel' => $sub > 0 ? $position . '.' . $sub : (string)$position,
			'positionType' => (string)($row['position_type'] ?? CatalogRules::positionType('')),
			'quantityMilli' => $quantityMilli,
			'quantity' => $quantityMilli / 1000,
			'origin' => in_array((string)($row['origin'] ?? ''), ['NACHTRAG', 'INCOMING_INVOICE'], true) ? (string)$row['origin'] : ((int)($row['article_id'] ?? 0) === 0 ? 'FREE_TEXT' : (string)($row['origin'] ?? 'ORDER')),
			'serviceStatus' => $status,
			'serviceStatusLabel' => CatalogRules::SERVICE_STATUS_LABELS[$status] ?? $status,
			'orderedQuantityMilli' => $ordered,
			'performedQuantityMilli' => $performedQuantityMilli,
			'performedQuantity' => $performedQuantityMilli / 1000,
			'invoicedQuantityMilli' => $invoiced,
			'billability' => (string)($row['billability'] ?? 'ABRECHENBAR'),
			'classificationReason' => (string)($row['classification_reason'] ?? ''),
			'performedAt' => (string)($row['performed_at'] ?? ''),
			'performedBy' => (string)($row['performed_by'] ?? ''),
			'unitPriceCents' => (int)$row['unit_price_cents'],
			'listPriceCents' => (int)($row['list_price_cents'] ?? 0),
			'surchargeCents' => (int)($row['surcharge_cents'] ?? 0),
			'priceOnEntry' => (bool)($row['price_on_entry'] ?? false),
			'guidePriceCents' => $row['guide_price_cents'] !== null ? (int)$row['guide_price_cents'] : null,
			'unit' => (string)($row['unit'] ?? 'STK'), 'quantityDecimals' => (int)($row['quantity_decimals'] ?? 0),
			'vatRate' => (int)$row['vat_rate'],
			'netCents' => $netCents,
			'vatCents' => $vatCents,
			'grossCents' => $netCents + $vatCents,
			'performedNetCents' => $performedNetCents, 'performedVatCents' => $performedVatCents, 'performedGrossCents' => $performedNetCents + $performedVatCents,
		];
	}

	private function totalsForQuantity(array $items, string $quantityKey): array {
		$net = 0; $vat = 0;
		foreach ($items as $item) { $lineNet = (int)round((int)$item[$quantityKey] * (int)$item['unitPriceCents'] / 1000); $net += $lineNet; $vat += (int)round($lineNet * (int)$item['vatRate'] / 100); }
		return ['netCents' => $net, 'vatCents' => $vat, 'grossCents' => $net + $vat];
	}

	private function outstandingTotals(array $items): array {
		$net = 0; $vat = 0;
		foreach ($items as $item) { $quantity = max(0, (int)$item['performedQuantityMilli'] - (int)$item['invoicedQuantityMilli']); if ($item['billability'] !== 'ABRECHENBAR') $quantity = 0; $lineNet = (int)round($quantity * (int)$item['unitPriceCents'] / 1000); $net += $lineNet; $vat += (int)round($lineNet * (int)$item['vatRate'] / 100); }
		return ['netCents' => $net, 'vatCents' => $vat, 'grossCents' => $net + $vat];
	}

	private function validateQuantityPrecision(int $quantityMilli, int $decimals, string $unit): void {
		$decimals = max(0, min(3, $decimals));
		$increment = 10 ** (3 - $decimals);
		if ($quantityMilli % $increment !== 0) throw new \InvalidArgumentException(sprintf('Für die Einheit %s sind höchstens %d Nachkommastellen zulässig.', $unit, $decimals));
	}

	private function validatedUnit(string $unit): string {
		$unit = strtoupper(trim($unit)) ?: 'STK';
		$values = $this->customizing->valuesForKey('QUANTITY_UNIT');
		$allowed = array_column($values, 'value');
		if ($allowed === []) $allowed = self::FALLBACK_UNITS;
		// Klartext aus der Werteliste (z. B. „Pauschal“, „Stück“) wird auf den Schlüssel abgebildet.
		if (!in_array($unit, $allowed, true)) foreach ($values as $entry) if (mb_strtoupper(trim((string)($entry['label'] ?? ''))) === mb_strtoupper(trim($unit))) { $unit = (string)$entry['value']; break; }
		if (!in_array($unit, $allowed, true)) throw new \InvalidArgumentException('Unbekannte Mengeneinheit „' . $unit . '“. Einheiten werden in der Werteliste „Mengeneinheiten“ gepflegt.');
		return $unit;
	}

	private function ensureSeedData(): void {
		$query = $this->db->getQueryBuilder();
		$count = (int)$query->select($query->func()->count('*', 'count'))->from('bestatter_articles')->executeQuery()->fetchOne();
		$pending = $this->config->getAppValue(Application::APP_ID, 'catalog_sample_pending', '') !== '';
		if ($count > 0 && !$pending) return;
		if (!is_file(self::SEED_FILE)) return;
		$this->installSampleCatalog($count > 0);
	}

	private function seed(): array {
		if (!is_file(self::SEED_FILE)) return [];
		$data = json_decode((string)file_get_contents(self::SEED_FILE), true);
		return is_array($data) ? $data : [];
	}

	private function options(array $map): array {
		return array_map(static fn(string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys($map), $map);
	}

	private function findIdByNumber(string $number): int {
		if ($number === '') return 0;
		$query = $this->db->getQueryBuilder();
		return (int)$query->select('id')->from('bestatter_articles')->where($query->expr()->eq('article_number', $query->createNamedParameter($number)))->executeQuery()->fetchOne();
	}

	private function normalizeHeader(string $header): string {
		$key = CsvTools::headerKey($header);
		return [
			'artikelnummer' => 'articleNumber', 'article_number' => 'articleNumber', 'articlenumber' => 'articleNumber',
			'suchbegriff' => 'searchTerm', 'searchterm' => 'searchTerm', 'search_term' => 'searchTerm',
			'kurztext' => 'shortName', 'kurzbezeichnung' => 'shortName', 'short_name' => 'shortName', 'shortname' => 'shortName',
			'verkaufstext' => 'longText', 'langtext' => 'longText', 'long_text' => 'longText', 'longtext' => 'longText',
			'interner_hinweis' => 'internalNote', 'internalnote' => 'internalNote', 'internal_note' => 'internalNote',
			'warengruppe' => 'articleGroup', 'gruppe' => 'articleGroup', 'article_group' => 'articleGroup', 'articlegroup' => 'articleGroup',
			'artikelart' => 'articleType', 'article_type' => 'articleType', 'articletype' => 'articleType',
			'steuerklasse' => 'taxClass', 'tax_class' => 'taxClass', 'taxclass' => 'taxClass',
			'artikeltyp' => 'itemType', 'item_type' => 'itemType', 'itemtype' => 'itemType', 'kostenart' => 'costType', 'cost_type' => 'costType', 'costtype' => 'costType',
			'mwst' => 'vatRate', 'vat_rate' => 'vatRate', 'vatrate' => 'vatRate',
			'einheit' => 'unit', 'unit' => 'unit', 'nachkommastellen' => 'quantityDecimals', 'quantity_decimals' => 'quantityDecimals', 'quantitydecimals' => 'quantityDecimals',
			'vk_preis' => 'salesPrice', 'vk' => 'salesPrice', 'vk_netto' => 'salesPrice', 'verkaufspreis' => 'salesPrice', 'sales_price' => 'salesPrice', 'salesprice' => 'salesPrice', 'salespricecents' => 'salesPriceCents',
			'preis_gueltig_ab' => 'priceValidFrom', 'gueltig_ab' => 'priceValidFrom', 'pricevalidfrom' => 'priceValidFrom', 'validfrom' => 'priceValidFrom',
			'preis_im_fall' => 'priceOnEntry', 'priceonentry' => 'priceOnEntry', 'richtwert' => 'guidePrice', 'guideprice' => 'guidePrice',
			'ek_preis' => 'purchasePrice', 'ek' => 'purchasePrice', 'ek_netto' => 'purchasePrice', 'einkaufspreis' => 'purchasePrice', 'purchase_price' => 'purchasePrice', 'purchaseprice' => 'purchasePrice', 'purchasepricecents' => 'purchasePriceCents',
			'standardlieferant' => 'supplierContact', 'supplier_contact' => 'supplierContact', 'suppliercontact' => 'supplierContact', 'foto' => 'photoPath', 'photo_path' => 'photoPath',
			'aktiv' => 'active', 'active' => 'active', 'loeschen' => 'delete', 'delete' => 'delete',
			'bestandteile' => 'components', 'stueckliste' => 'components', 'components' => 'components', 'preisfindung' => 'packagePricing', 'packagepricing' => 'packagePricing',
		][$key] ?? $header;
	}

	private function moneyToCents(mixed $value): int {
		return max(0, CsvTools::cents($value));
	}

	private function quantityToMilli(mixed $value): int {
		return max(1, (int)round((float)str_replace(',', '.', (string)$value) * 1000));
	}
}
