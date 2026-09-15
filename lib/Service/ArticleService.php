<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserSession;

class ArticleService {
	private const SEED_FILE = __DIR__ . '/../../resources/article-catalog.json';
	private const ITEM_TYPES = ['SINGLE', 'PACKAGE'];
	private const COST_TYPES = ['INTERNAL', 'EXPENSE', 'THIRD_PARTY'];
	private const FUNERAL_SCOPES = ['ALL', 'BURIAL', 'CREMATION'];
	private const UNITS = ['STK', 'PAUSCHAL', 'STD', 'KM', 'TAG', 'KG', 'L'];

	public function __construct(
		private IDBConnection $db,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private AuditService $audit,
		private InstallationConfigService $installationConfig,
		private CountryConfigurationService $countryConfiguration,
		private CustomizingService $customizing,
		private CommercialStateService $commercialState,
	) {
	}

	public function catalog(): array {
		$this->customizing->ensureSeedData();
		$this->ensureSeedData();
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')
			->from('bestatter_articles')
			->orderBy('article_group', 'ASC')
			->addOrderBy('short_name', 'ASC')
			->executeQuery()
			->fetchAllAssociative();
		$this->ensureArticleGroupRules($rows);
		$groupRules = $this->articleGroupRules();
		$ruleMap = array_column($groupRules, 'exclusiveSelection', 'groupName');
		return [
			'articles' => array_map(fn(array $row): array => $this->mapArticle($row, $ruleMap), $rows),
			'groupRules' => $groupRules,
			'allowedVatRates' => $this->countryConfiguration->allowedVatRates(),
			'countryProfiles' => $this->countryConfiguration->profiles(),
			'positionTypes' => $this->customizing->valuesForKey('POSITION_TYPE'),
			'quantityUnits' => $this->customizing->valuesForKey('QUANTITY_UNIT'),
		];
	}

	public function saveArticleGroupRule(string $groupName, bool $exclusiveSelection): void {
		$groupName = trim($groupName);
		if ($groupName === '') throw new \InvalidArgumentException('Bitte eine Artikelgruppe auswählen.');
		$query = $this->db->getQueryBuilder();
		$id = $query->select('id')->from('bestatter_article_groups')
			->where($query->expr()->eq('group_name', $query->createNamedParameter($groupName)))
			->executeQuery()->fetchOne();
		$now = date('c');
		$query = $this->db->getQueryBuilder();
		if ($id) {
			$query->update('bestatter_article_groups')
				->set('exclusive_selection', $query->createNamedParameter($exclusiveSelection ? 1 : 0))
				->set('updated_at', $query->createNamedParameter($now))
				->where($query->expr()->eq('id', $query->createNamedParameter((int)$id)))
				->executeStatement();
		} else {
			$query->insert('bestatter_article_groups')->values([
				'group_name' => $query->createNamedParameter($groupName),
				'exclusive_selection' => $query->createNamedParameter($exclusiveSelection ? 1 : 0),
				'created_at' => $query->createNamedParameter($now),
				'updated_at' => $query->createNamedParameter($now),
			])->executeStatement();
		}
		// Keep the legacy article column synchronized for CSV exports and older clients.
		$update = $this->db->getQueryBuilder();
		$update->update('bestatter_articles')
			->set('exclusive_group', $update->createNamedParameter($exclusiveSelection ? 1 : 0))
			->where($update->expr()->eq('article_group', $update->createNamedParameter($groupName)))
			->executeStatement();
	}

	private function articleGroupRules(): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('group_name', 'exclusive_selection')
			->from('bestatter_article_groups')
			->orderBy('group_name', 'ASC')
			->executeQuery()->fetchAllAssociative();
		return array_map(static fn(array $row): array => [
			'groupName' => (string)$row['group_name'],
			'exclusiveSelection' => (bool)$row['exclusive_selection'],
		], $rows);
	}

	private function ensureArticleGroupRules(array $articleRows): void {
		$known = array_fill_keys(array_column($this->articleGroupRules(), 'groupName'), true);
		foreach ($articleRows as $row) {
			$name = trim((string)($row['article_group'] ?? ''));
			if ($name === '' || isset($known[$name])) continue;
			$this->saveArticleGroupRule($name, (bool)($row['exclusive_group'] ?? false));
			$known[$name] = true;
		}
	}

	public function save(array $input, array $components = [], ?int $id = null): array {
		$values = $this->validateArticle($input);
		if (!array_key_exists('exclusiveGroup', $input) && !array_key_exists('exclusive_group', $input)) {
			foreach ($this->articleGroupRules() as $rule) {
				if ($rule['groupName'] === $values['articleGroup']) $values['exclusiveGroup'] = $rule['exclusiveSelection'];
			}
		}
		$now = date('c');
		$query = $this->db->getQueryBuilder();
		if ($id === null) {
			$query->insert('bestatter_articles')->values([
				'item_type' => $query->createNamedParameter($values['itemType']),
				'article_number' => $query->createNamedParameter($values['articleNumber']),
				'short_name' => $query->createNamedParameter($values['shortName']),
				'long_text' => $query->createNamedParameter($values['longText']),
				'category' => $query->createNamedParameter($values['category']),
				'article_group' => $query->createNamedParameter($values['articleGroup']),
				'cost_type' => $query->createNamedParameter($values['costType']),
				'funeral_scope' => $query->createNamedParameter($values['funeralScope']),
				'vat_rate' => $query->createNamedParameter($values['vatRate']),
				'unit' => $query->createNamedParameter($values['unit']),
				'quantity_decimals' => $query->createNamedParameter($values['quantityDecimals']),
				'exclusive_group' => $query->createNamedParameter($values['exclusiveGroup'] ? 1 : 0),
				'supplier_contact' => $query->createNamedParameter($values['supplierContact']),
				'photo_path' => $query->createNamedParameter($values['photoPath']),
				'purchase_price_cents' => $query->createNamedParameter($values['purchasePriceCents']),
				'sales_price_cents' => $query->createNamedParameter($values['salesPriceCents']),
				'active' => $query->createNamedParameter($values['active']),
				'created_at' => $query->createNamedParameter($now),
				'updated_at' => $query->createNamedParameter($now),
			])->executeStatement();
			$id = (int)$this->db->lastInsertId('bestatter_articles');
		} else {
			$this->article($id);
			$query->update('bestatter_articles')
				->set('item_type', $query->createNamedParameter($values['itemType']))
				->set('article_number', $query->createNamedParameter($values['articleNumber']))
				->set('short_name', $query->createNamedParameter($values['shortName']))
				->set('long_text', $query->createNamedParameter($values['longText']))
				->set('category', $query->createNamedParameter($values['category']))
				->set('article_group', $query->createNamedParameter($values['articleGroup']))
				->set('cost_type', $query->createNamedParameter($values['costType']))
				->set('funeral_scope', $query->createNamedParameter($values['funeralScope']))
				->set('vat_rate', $query->createNamedParameter($values['vatRate']))
				->set('unit', $query->createNamedParameter($values['unit']))
				->set('quantity_decimals', $query->createNamedParameter($values['quantityDecimals']))
				->set('exclusive_group', $query->createNamedParameter($values['exclusiveGroup'] ? 1 : 0))
				->set('supplier_contact', $query->createNamedParameter($values['supplierContact']))
				->set('photo_path', $query->createNamedParameter($values['photoPath']))
				->set('purchase_price_cents', $query->createNamedParameter($values['purchasePriceCents']))
				->set('sales_price_cents', $query->createNamedParameter($values['salesPriceCents']))
				->set('active', $query->createNamedParameter($values['active']))
				->set('updated_at', $query->createNamedParameter($now))
				->where($query->expr()->eq('id', $query->createNamedParameter($id)))
				->executeStatement();
		}

		$this->saveComponents($id, $values['itemType'] === 'PACKAGE' ? $components : []);
		$this->saveArticleGroupRule($values['articleGroup'], $values['exclusiveGroup']);
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
		$componentQuery = $this->db->getQueryBuilder();
		$componentReferences = (int)$componentQuery->select($componentQuery->func()->count('*', 'count'))->from('bestatter_article_components')
			->where($componentQuery->expr()->eq('article_id', $componentQuery->createNamedParameter($id)))->executeQuery()->fetchOne();
		if ($caseReferences > 0 || $componentReferences > 0) {
			$query = $this->db->getQueryBuilder();
			$query->update('bestatter_articles')->set('active', $query->createNamedParameter(false))->set('updated_at', $query->createNamedParameter(date('c')))
				->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeStatement();
			return;
		}
		$query = $this->db->getQueryBuilder();
		$query->delete('bestatter_article_components')->where($query->expr()->eq('package_id', $query->createNamedParameter($id)))->executeStatement();
		$query = $this->db->getQueryBuilder();
		$query->delete('bestatter_articles')->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeStatement();
	}

	public function importCsv(string $content): array {
		$content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
		$firstLine = strtok($content, "\r\n") ?: '';
		$delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
		$stream = fopen('php://temp', 'w+');
		if ($stream === false) throw new \RuntimeException('CSV-Datei konnte nicht verarbeitet werden.');
		fwrite($stream, $content);
		rewind($stream);
		$headers = fgetcsv($stream, 0, $delimiter);
		if (!is_array($headers)) throw new \InvalidArgumentException('Die CSV-Datei enthält keine Kopfzeile.');
		$headers = array_map(fn(string $header): string => $this->normalizeHeader($header), $headers);
		$rows = [];
		while (($values = fgetcsv($stream, 0, $delimiter)) !== false) {
			if (count(array_filter($values, static fn($value): bool => trim((string)$value) !== '')) === 0) continue;
			$values = array_pad($values, count($headers), '');
			$rows[] = array_combine($headers, array_slice($values, 0, count($headers)));
		}
		fclose($stream);

		$imported = 0; $deleted = 0;
		$componentDefinitions = [];
		foreach ($rows as $row) {
			$articleNumber = trim((string)($row['articleNumber'] ?? ''));
			$id = $this->findIdByNumber($articleNumber);
			$delete = filter_var($row['delete'] ?? false, FILTER_VALIDATE_BOOLEAN);
			if ($delete) { if ($id) { $this->delete($id); $deleted++; } continue; }
			$article = $this->save($row, [], $id ?: null);
			$componentDefinitions[(int)$article['id']] = trim((string)($row['components'] ?? ''));
			$imported++;
		}

		foreach ($componentDefinitions as $packageId => $definition) {
			if ($definition === '') continue;
			$components = [];
			foreach (preg_split('/[|]/', $definition) ?: [] as $part) {
				[$number, $quantity] = array_pad(explode(':', $part, 2), 2, '1');
				$articleId = $this->findIdByNumber(trim($number));
				if ($articleId) $components[] = ['articleId' => $articleId, 'quantity' => $quantity];
			}
			$this->saveComponents($packageId, $components);
		}

		$catalog = $this->catalog();
		return ['imported' => $imported, 'deleted' => $deleted, 'articles' => $catalog['articles'], 'groupRules' => $catalog['groupRules'], 'allowedVatRates' => $catalog['allowedVatRates'], 'countryProfiles' => $catalog['countryProfiles']];
	}

	public function savePhoto(int $id, array $upload): array {
		$article = $this->article($id);
		if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string)($upload['tmp_name'] ?? ''))) {
			throw new \InvalidArgumentException('Es wurde kein gültiges Bild hochgeladen.');
		}
		if ((int)($upload['size'] ?? 0) > 5 * 1024 * 1024) throw new \InvalidArgumentException('Das Bild darf höchstens 5 MB groß sein.');
		$extension = strtolower(pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION));
		if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) throw new \InvalidArgumentException('Erlaubt sind JPG, PNG und WebP.');
		$user = $this->userSession->getUser();
		if ($user === null) throw new \RuntimeException('Kein angemeldeter Nextcloud-Benutzer.');
		$folder = $this->installationConfig->ensurePath(
			$this->rootFolder->getUserFolder($user->getUID()),
			$this->installationConfig->storageRoot() . '/' . $this->installationConfig->articleImagesFolder(),
		);
		$fileName = preg_replace('/[^A-Za-z0-9._-]/', '_', $article['articleNumber']) . '.' . $extension;
		$file = $folder->nodeExists($fileName) ? $folder->get($fileName) : $folder->newFile($fileName);
		$file->putContent((string)file_get_contents((string)$upload['tmp_name']));
		$article['photoPath'] = $this->installationConfig->storageRoot() . '/' . $this->installationConfig->articleImagesFolder() . '/' . $fileName;
		return $this->save($article, $article['components'] ?? [], $id);
	}

	public function caseServices(int $caseId, ?int $sideOrderId = null): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_case_services')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->andWhere($sideOrderId === null ? $query->expr()->isNull('side_order_id') : $query->expr()->eq('side_order_id', $query->createNamedParameter($sideOrderId)))
			->orderBy('sort_order', 'ASC')->executeQuery()->fetchAllAssociative();
		$items = array_map(fn(array $row): array => $this->mapCaseService($row), $rows);
		$activeItems = array_values(array_filter($items, static fn(array $item): bool => ($item['serviceStatus'] ?? '') !== 'STORNIERT'));
		return [
			'items' => $items,
			'totals' => $this->totalsForQuantity($activeItems, 'orderedQuantityMilli'),
			'performanceTotals' => $this->totalsForQuantity($activeItems, 'performedQuantityMilli'),
			'invoicedTotals' => $this->totalsForQuantity($activeItems, 'invoicedQuantityMilli'),
			'outstandingTotals' => $this->outstandingTotals($activeItems),
			'contractProtection' => $this->contractProtection($caseId, $sideOrderId),
		];
	}

	public function saveCaseServices(int $caseId, array $selections, string $amendmentReason = '', ?int $sideOrderId = null): array {
		$beforeSelection = $this->caseServices($caseId, $sideOrderId);
		$articles = [];
		foreach ($this->catalog()['articles'] as $article) $articles[(int)$article['id']] = $article;
		$lines = [];
		foreach ($selections as $selection) {
			$articleId = (int)($selection['articleId'] ?? 0);
			if ($articleId === 0) {
				$title = trim((string)($selection['title'] ?? ''));
				if ($title === '') throw new \InvalidArgumentException('Eine Freitextposition benötigt eine Bezeichnung.');
				$quantityMilli = $this->quantityToMilli($selection['quantity'] ?? 1);
				if ($quantityMilli <= 0) throw new \InvalidArgumentException('Die Positionsmenge muss größer als null sein.');
				$positionType = strtoupper(trim((string)($selection['positionType'] ?? 'EL')));
				if (!in_array($positionType, ['EL', 'FK', 'DP'], true)) throw new \InvalidArgumentException('Ungültiger Positionstyp.');
				$unitPriceCents = (int)($selection['unitPriceCents'] ?? 0);
				if ($unitPriceCents <= 0) throw new \InvalidArgumentException('Eine Freitextposition benötigt einen Preis größer als 0,00 Euro.');
				$vatRate = (int)($selection['vatRate'] ?? 19);
				if (!in_array($vatRate, $this->countryConfiguration->allowedVatRates(), true)) throw new \InvalidArgumentException('Der Mehrwertsteuersatz der Freitextposition ist nicht zulässig.');
				$lines[] = [
					'existingId' => (int)($selection['serviceId'] ?? 0), 'articleId' => 0, 'sourcePackageId' => null, 'sourcePackageName' => '',
					'articleNumber' => '', 'title' => $title, 'longText' => trim((string)($selection['longText'] ?? '')), 'note' => trim((string)($selection['note'] ?? '')),
					'category' => 'Freitext', 'articleGroup' => 'Freitext', 'costType' => $positionType === 'EL' ? 'INTERNAL' : ($positionType === 'FK' ? 'THIRD_PARTY' : 'EXPENSE'),
					'quantityMilli' => $quantityMilli, 'unitPriceCents' => $unitPriceCents,
					'vatRate' => $vatRate, 'unit' => $this->validatedUnit((string)($selection['unit'] ?? 'STK')),
					'quantityDecimals' => 3, 'positionType' => $positionType, 'origin' => 'FREE_TEXT', 'positionNo' => (int)($selection['positionNo'] ?? 0),
				];
				continue;
			}
			if (!isset($articles[$articleId])) continue;
			$article = $articles[$articleId];
			$quantityMilli = $this->quantityToMilli($selection['quantity'] ?? 1);
			if ($quantityMilli <= 0) throw new \InvalidArgumentException('Die beauftragte Menge muss größer als null sein.');
			$this->validateQuantityPrecision($quantityMilli, (int)($article['quantityDecimals'] ?? 0), (string)($article['unit'] ?? 'STK'));
			if ($article['itemType'] === 'PACKAGE' && count($article['components']) > 0) {
				foreach ($article['components'] as $component) {
					$componentArticle = $articles[(int)$component['articleId']] ?? null;
					if ($componentArticle === null) continue;
					$lines[] = $this->lineFromArticle($componentArticle, (int)round($quantityMilli * (int)$component['quantityMilli'] / 1000), $article);
				}
			} else {
				$line = $this->lineFromArticle($article, $quantityMilli, null);
				if (isset($selection['unitPriceCents'])) $line['unitPriceCents'] = max(0, (int)$selection['unitPriceCents']);
				$line['existingId'] = (int)($selection['serviceId'] ?? 0);
				$line['positionType'] = strtoupper(trim((string)($selection['positionType'] ?? $this->defaultPositionType($article['costType'] ?? 'INTERNAL'))));
				if (!in_array($line['positionType'], ['EL', 'FK', 'DP'], true)) throw new \InvalidArgumentException('Ungültiger Positionstyp. Zulässig sind EL, FK und DP.');
				$line['unit'] = $this->validatedUnit((string)($selection['unit'] ?? $article['unit'] ?? 'STK'));
				$line['note'] = trim((string)($selection['note'] ?? ''));
				$line['positionNo'] = (int)($selection['positionNo'] ?? 0);
				$lines[] = $line;
			}
		}
		$exclusiveGroups = [];
		foreach ($articles as $article) if ($article['exclusiveGroup'] ?? false) $exclusiveGroups[(string)$article['articleGroup']] = true;
		$exclusive = [];
		foreach ($lines as $line) {
			$article = $articles[(int)$line['articleId']] ?? null;
			if (!$article || !isset($exclusiveGroups[(string)$article['articleGroup']])) continue;
			$exclusive[(string)$article['articleGroup']][(int)$article['id']] = (string)$article['shortName'];
		}
		foreach ($exclusive as $group => $entries) {
			if (count($entries) > 1) throw new \InvalidArgumentException('In der exklusiven Artikelgruppe „' . $group . '“ ist nur eine Position zulässig. Enthalten: ' . implode(', ', array_values($entries)) . '.');
		}

		$existing = [];
		foreach ($this->caseServices($caseId, $sideOrderId)['items'] as $item) {
			// Zusatzpositionen aus freigegebenen Eingangsrechnungen gehören nicht zur
			// Auftragsauswahl und dürfen bei deren erneutem Speichern nicht entfallen.
			if (($item['origin'] ?? '') === 'INCOMING_INVOICE') continue;
			if (($item['serviceStatus'] ?? '') === 'STORNIERT') continue;
			$key = $item['articleId'] . ':' . ($item['sourcePackageId'] ?? 0);
			$existing[$key][] = $item;
		}
		$comparisonExisting = $existing;
		$matchedExisting = [];
		$contractChanged = false;
		foreach ($lines as &$line) {
			$key = $line['articleId'] . ':' . ($line['sourcePackageId'] ?? 0);
			$current = null;
			if (($line['existingId'] ?? 0) > 0) {
				foreach ($beforeSelection['items'] as $candidate) if ((int)$candidate['id'] === (int)$line['existingId']) { $current = $candidate; break; }
			} elseif (!empty($comparisonExisting[$key])) $current = array_shift($comparisonExisting[$key]);
			if ($current === null) {
				$contractChanged = true;
				$line['_newContractLine'] = true;
				continue;
			}
			$matchedExisting[(int)$current['id']] = true;
			// Bereits gewählte Leistungen sind eigenständige historische Positionen.
			// Katalogtexte, Steuersätze und Paket-Komponentenpreise dürfen sie nicht
			// bei einem späteren Speichern unbemerkt überschreiben.
			if (($line['origin'] ?? 'ORDER') !== 'FREE_TEXT') {
				foreach (['sourcePackageName', 'articleNumber', 'title', 'longText', 'articleGroup', 'costType', 'vatRate', 'quantityDecimals'] as $field) $line[$field] = $current[$field];
				if (trim((string)($current['category'] ?? '')) !== '') $line['category'] = $current['category'];
			}
			$line['origin'] = (string)($current['origin'] ?? $line['origin'] ?? 'ORDER');
			if ($current['sourcePackageId'] !== null) $line['unitPriceCents'] = $current['unitPriceCents'];
			if ($current['orderedQuantityMilli'] !== $line['quantityMilli'] || $current['unitPriceCents'] !== $line['unitPriceCents'] || ($current['unit'] ?? 'STK') !== ($line['unit'] ?? 'STK') || ($current['positionType'] ?? 'EL') !== ($line['positionType'] ?? 'EL') || ($current['title'] ?? '') !== ($line['title'] ?? '')) $contractChanged = true;
			$line['existingId'] = (int)$current['id'];
		}
		unset($line);
		foreach ($beforeSelection['items'] as $item) if (($item['origin'] ?? '') !== 'INCOMING_INVOICE' && empty($matchedExisting[(int)$item['id']]) && ($item['serviceStatus'] ?? '') !== 'STORNIERT') $contractChanged = true;
		$contractProtection = $this->contractProtection($caseId, $sideOrderId);
		$amendmentReason = trim($amendmentReason);
		if ($contractProtection['active'] && $contractChanged) {
			if (!$contractProtection['amendmentsAllowed']) throw new \InvalidArgumentException('Die Vertragspositionen können nicht mehr geändert werden, weil bereits eine aktive Schlussrechnung angelegt wurde. Korrekturen müssen über den vorgesehenen Rechnungsprozess erfolgen.');
			if (mb_strlen($amendmentReason) < 5) throw new \InvalidArgumentException('Nach Festschreibung des KVA oder Auftrags ist eine Änderung nur als begründeter Vertragsnachtrag möglich.');
			foreach ($lines as &$amendmentLine) if ($amendmentLine['_newContractLine'] ?? false) $amendmentLine['origin'] = 'NACHTRAG';
			unset($amendmentLine);
		}
		$this->db->beginTransaction();
		try {
		foreach ($lines as $position => $line) {
			$key = $line['articleId'] . ':' . ($line['sourcePackageId'] ?? 0);
			$current = null;
			if (($line['existingId'] ?? 0) > 0) {
				foreach ($beforeSelection['items'] as $candidate) if ((int)$candidate['id'] === (int)$line['existingId']) { $current = $candidate; break; }
			} elseif (!empty($existing[$key])) $current = array_shift($existing[$key]);
			if ($current) {
				foreach ($existing as $existingKey => &$existingItems) {
					$existingItems = array_values(array_filter($existingItems, static fn(array $item): bool => (int)$item['id'] !== (int)$current['id']));
					if ($existingItems === []) unset($existing[$existingKey]);
				}
				unset($existingItems);
					$invoicedContractChanged = $current['orderedQuantityMilli'] !== $line['quantityMilli'] || $current['unitPriceCents'] !== $line['unitPriceCents'] || ($current['unit'] ?? 'STK') !== ($line['unit'] ?? 'STK') || ($current['positionType'] ?? 'EL') !== ($line['positionType'] ?? 'EL') || ($current['title'] ?? '') !== ($line['title'] ?? '') || (int)($current['vatRate'] ?? 0) !== (int)($line['vatRate'] ?? 0);
					if ($current['invoicedQuantityMilli'] > 0 && $invoicedContractChanged) throw new \InvalidArgumentException('Bereits fakturierte Leistungen dürfen in Menge oder Preis sowie weiteren Vertragsdaten nicht mehr geändert werden.');
					$this->updateCaseServiceSnapshot($current['id'], $line, $this->positionNumber($line, $position, $current));
				} else {
					$this->insertCaseService($caseId, $line, $this->positionNumber($line, $position), $sideOrderId);
				}
			}
			$missing = [];
			foreach ($existing as $items) foreach ($items as $item) $missing[] = $item['id'];
			foreach ($missing as $id) {
				$current = null;
				foreach ($this->caseServices($caseId, $sideOrderId)['items'] as $candidate) if ($candidate['id'] === $id) { $current = $candidate; break; }
				if (($current['invoicedQuantityMilli'] ?? 0) > 0) throw new \InvalidArgumentException('Bereits fakturierte Leistungen dürfen nicht entfernt werden.');
				$query = $this->db->getQueryBuilder();
				$query->update('bestatter_case_services')->set('service_status', $query->createNamedParameter('STORNIERT'))->set('billability', $query->createNamedParameter('NICHT_ABRECHENBAR'))->set('classification_reason', $query->createNamedParameter('Aus der aktuellen Auswahl entfernt.'))->set('updated_at', $query->createNamedParameter(date('c')))->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}
		$afterSelection = $this->caseServices($caseId, $sideOrderId);
		$this->audit->log($caseId, 'SERVICE', null, 'SELECTION_UPDATED', $beforeSelection, $afterSelection);
		if ($contractProtection['active'] && $contractChanged) {
			$this->audit->log($caseId, 'CONTRACT', (int)$contractProtection['documentId'], 'CONTRACT_AMENDMENT', [
				'contract' => $contractProtection,
				'services' => $beforeSelection,
			], [
				'reason' => $amendmentReason,
				'services' => $afterSelection,
			]);
		}
		return $afterSelection;
	}

	private function contractProtection(int $caseId, ?int $sideOrderId = null): array {
		return $this->commercialState->state($caseId, $sideOrderId);
	}

	private function article(int $id): array {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('*')->from('bestatter_articles')
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->executeQuery()->fetchAssociative();
		if (!$row) throw new \InvalidArgumentException('Leistung wurde nicht gefunden.');
		$ruleMap = array_column($this->articleGroupRules(), 'exclusiveSelection', 'groupName');
		return $this->mapArticle($row, $ruleMap);
	}

	private function mapArticle(array $row, array $ruleMap = []): array {
		$id = (int)$row['id'];
		return [
			'id' => $id,
			'itemType' => $row['item_type'],
			'articleNumber' => $row['article_number'],
			'shortName' => $row['short_name'],
			'longText' => (string)($row['long_text'] ?? ''),
			'category' => $row['category'],
			'articleGroup' => $row['article_group'],
			'costType' => $row['cost_type'],
			'funeralScope' => $row['funeral_scope'],
			'vatRate' => (int)$row['vat_rate'],
			'unit' => (string)($row['unit'] ?? 'STK'),
			'quantityDecimals' => (int)($row['quantity_decimals'] ?? 0),
			'exclusiveGroup' => (bool)($ruleMap[(string)$row['article_group']] ?? $row['exclusive_group'] ?? false),
			'supplierContact' => (string)($row['supplier_contact'] ?? ''),
			'photoPath' => (string)($row['photo_path'] ?? ''),
			'purchasePriceCents' => (int)$row['purchase_price_cents'],
			'salesPriceCents' => (int)$row['sales_price_cents'],
			'active' => (bool)$row['active'],
			'createdAt' => $row['created_at'],
			'updatedAt' => $row['updated_at'],
			'components' => $this->components($id, $ruleMap),
		];
	}

	private function validateArticle(array $input): array {
		$itemType = strtoupper(trim((string)($input['itemType'] ?? $input['item_type'] ?? 'SINGLE')));
		$category = trim((string)($input['category'] ?? 'Dienstleistung'));
		$articleGroup = trim((string)($input['articleGroup'] ?? $input['article_group'] ?? 'Leistungen für externe')); 
		$costType = strtoupper(trim((string)($input['costType'] ?? $input['cost_type'] ?? 'INTERNAL')));
		$funeralScope = strtoupper(trim((string)($input['funeralScope'] ?? $input['funeral_scope'] ?? 'ALL')));
		$vatRate = (int)($input['vatRate'] ?? $input['vat_rate'] ?? 19);
		$unit = strtoupper(trim((string)($input['unit'] ?? 'STK')));
		$quantityDecimals = max(0, min(3, (int)($input['quantityDecimals'] ?? $input['quantity_decimals'] ?? 0)));
		$articleNumber = trim((string)($input['articleNumber'] ?? $input['article_number'] ?? ''));
		$shortName = trim((string)($input['shortName'] ?? $input['short_name'] ?? ''));
		if ($articleNumber === '' || $shortName === '') throw new \InvalidArgumentException('Artikelnummer und Kurzbezeichnung sind Pflichtfelder.');
		if ($category === '' || $articleGroup === '') throw new \InvalidArgumentException('Kategorie und Gruppe sind Pflichtfelder.');
		if (!in_array($itemType, self::ITEM_TYPES, true)) throw new \InvalidArgumentException('Unbekannter Artikeltyp.');
		if (!in_array($costType, self::COST_TYPES, true)) throw new \InvalidArgumentException('Unbekannte Kostenart.');
		if (!in_array($funeralScope, self::FUNERAL_SCOPES, true)) throw new \InvalidArgumentException('Unbekannte Bestattungszuordnung.');
		$allowedVatRates = $this->countryConfiguration->allowedVatRates();
		if (!in_array($vatRate, $allowedVatRates, true)) throw new \InvalidArgumentException('Die Mehrwertsteuer muss einem der freigegebenen Sätze entsprechen: ' . implode(', ', $allowedVatRates) . ' Prozent.');
		if (!in_array($unit, self::UNITS, true)) throw new \InvalidArgumentException('Unbekannte Mengeneinheit.');
		return [
			'itemType' => $itemType,
			'articleNumber' => $articleNumber,
			'shortName' => $shortName,
			'longText' => trim((string)($input['longText'] ?? $input['long_text'] ?? '')),
			'category' => $category,
			'articleGroup' => $articleGroup,
			'costType' => $costType,
			'funeralScope' => $funeralScope,
			'vatRate' => $vatRate,
			'unit' => $unit, 'quantityDecimals' => $quantityDecimals,
			'exclusiveGroup' => filter_var($input['exclusiveGroup'] ?? $input['exclusive_group'] ?? false, FILTER_VALIDATE_BOOLEAN),
			'supplierContact' => trim((string)($input['supplierContact'] ?? $input['supplier_contact'] ?? $input['supplier'] ?? '')),
			'photoPath' => trim((string)($input['photoPath'] ?? $input['photo_path'] ?? '')),
			'purchasePriceCents' => $this->moneyToCents($input['purchasePrice'] ?? $input['purchase_price'] ?? $input['purchasePriceCents'] ?? 0, isset($input['purchasePriceCents'])),
			'salesPriceCents' => $this->moneyToCents($input['salesPrice'] ?? $input['sales_price'] ?? $input['salesPriceCents'] ?? 0, isset($input['salesPriceCents'])),
			'active' => filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
		];
	}

	private function saveComponents(int $packageId, array $components): void {
		$query = $this->db->getQueryBuilder();
		$query->delete('bestatter_article_components')->where($query->expr()->eq('package_id', $query->createNamedParameter($packageId)))->executeStatement();
		foreach ($components as $component) {
			$articleId = (int)($component['articleId'] ?? 0);
			if ($articleId <= 0 || $articleId === $packageId) continue;
			$insert = $this->db->getQueryBuilder();
			$insert->insert('bestatter_article_components')->values([
				'package_id' => $insert->createNamedParameter($packageId),
				'article_id' => $insert->createNamedParameter($articleId),
				'quantity_milli' => $insert->createNamedParameter($this->quantityToMilli($component['quantity'] ?? $component['quantityMilli'] ?? 1, isset($component['quantityMilli']))),
			])->executeStatement();
		}
	}

	private function components(int $packageId, array $ruleMap = []): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('c.article_id', 'c.quantity_milli', 'a.article_number', 'a.short_name', 'a.article_group', 'a.exclusive_group')
			->from('bestatter_article_components', 'c')
			->innerJoin('c', 'bestatter_articles', 'a', $query->expr()->eq('c.article_id', 'a.id'))
			->where($query->expr()->eq('c.package_id', $query->createNamedParameter($packageId)))
			->orderBy('c.id', 'ASC')->executeQuery()->fetchAllAssociative();
		return array_map(static fn(array $row): array => [
			'articleId' => (int)$row['article_id'],
			'articleNumber' => $row['article_number'],
			'shortName' => $row['short_name'],
			'articleGroup' => $row['article_group'],
			'exclusiveGroup' => (bool)($ruleMap[(string)$row['article_group']] ?? $row['exclusive_group']),
			'quantityMilli' => (int)$row['quantity_milli'],
			'quantity' => (int)$row['quantity_milli'] / 1000,
		], $rows);
	}

	private function lineFromArticle(array $article, int $quantityMilli, ?array $package): array {
		return [
			'articleId' => (int)$article['id'],
			'sourcePackageId' => $package ? (int)$package['id'] : null,
			'sourcePackageName' => $package ? $package['shortName'] : '',
			'articleNumber' => $article['articleNumber'],
			'title' => $article['shortName'],
			'longText' => $article['longText'],
			'category' => $article['category'],
			'articleGroup' => $article['articleGroup'],
			'costType' => $article['costType'],
			'quantityMilli' => max(1, $quantityMilli),
			'unitPriceCents' => (int)$article['salesPriceCents'],
			'vatRate' => (int)$article['vatRate'],
			'unit' => (string)($article['unit'] ?? 'STK'), 'quantityDecimals' => (int)($article['quantityDecimals'] ?? 0),
			'positionType' => $this->defaultPositionType($article['costType'] ?? 'INTERNAL'), 'origin' => 'ORDER',
		];
	}

	private function defaultPositionType(string $costType): string {
		return match (strtoupper($costType)) { 'THIRD_PARTY' => 'FK', 'EXPENSE' => 'DP', default => 'EL' };
	}

	private function validatedUnit(string $unit): string {
		$unit = strtoupper(trim($unit)) ?: 'STK';
		$allowed = array_column($this->customizing->valuesForKey('QUANTITY_UNIT'), 'value');
		if ($allowed === []) $allowed = self::UNITS;
		if (!in_array($unit, $allowed, true)) throw new \InvalidArgumentException('Unbekannte Mengeneinheit.');
		return $unit;
	}

	private function positionNumber(array $line, int $index, ?array $current = null): int {
		return ($index + 1) * 10;
	}

	private function insertCaseService(int $caseId, array $line, int $position, ?int $sideOrderId = null): void {
		$query = $this->db->getQueryBuilder();
		$query->insert('bestatter_case_services')->values([
			'case_id' => $query->createNamedParameter($caseId),
			'side_order_id' => $query->createNamedParameter($sideOrderId),
			'article_id' => $query->createNamedParameter($line['articleId']),
			'source_package_id' => $query->createNamedParameter($line['sourcePackageId']),
			'source_package_name' => $query->createNamedParameter($line['sourcePackageName']),
			'article_number' => $query->createNamedParameter($line['articleNumber']),
			'title' => $query->createNamedParameter($line['title']),
			'long_text' => $query->createNamedParameter($line['longText']),
			'article_category' => $query->createNamedParameter($line['category'] ?? ''),
			'note' => $query->createNamedParameter($line['note'] ?? ''),
			'article_group' => $query->createNamedParameter($line['articleGroup']),
			'cost_type' => $query->createNamedParameter($line['costType']),
			'position_type' => $query->createNamedParameter($line['positionType'] ?? $this->defaultPositionType($line['costType'])),
			'quantity_milli' => $query->createNamedParameter($line['quantityMilli']),
			'ordered_quantity_milli' => $query->createNamedParameter($line['quantityMilli']),
			'performed_quantity_milli' => $query->createNamedParameter(0),
			'invoiced_quantity_milli' => $query->createNamedParameter(0),
			'origin' => $query->createNamedParameter($line['origin'] ?? 'ORDER'),
			'service_status' => $query->createNamedParameter('BEAUFTRAGT'),
			'billability' => $query->createNamedParameter('ABRECHENBAR'),
			'unit_price_cents' => $query->createNamedParameter($line['unitPriceCents']),
			'vat_rate' => $query->createNamedParameter($line['vatRate']),
			'unit' => $query->createNamedParameter($line['unit']), 'quantity_decimals' => $query->createNamedParameter($line['quantityDecimals']),
			'sort_order' => $query->createNamedParameter($position),
			'created_at' => $query->createNamedParameter(date('c')),
			'updated_at' => $query->createNamedParameter(date('c')),
		])->executeStatement();
	}

	private function updateCaseServiceSnapshot(int $id, array $line, int $position): void {
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_case_services')
			->set('source_package_name', $query->createNamedParameter($line['sourcePackageName']))
			->set('article_number', $query->createNamedParameter($line['articleNumber']))
			->set('title', $query->createNamedParameter($line['title']))
			->set('long_text', $query->createNamedParameter($line['longText']))
			->set('article_category', $query->createNamedParameter($line['category'] ?? ''))
			->set('note', $query->createNamedParameter($line['note'] ?? ''))
			->set('article_group', $query->createNamedParameter($line['articleGroup']))
			->set('cost_type', $query->createNamedParameter($line['costType']))
			->set('position_type', $query->createNamedParameter($line['positionType'] ?? $this->defaultPositionType($line['costType'])))
			->set('quantity_milli', $query->createNamedParameter($line['quantityMilli']))
			->set('ordered_quantity_milli', $query->createNamedParameter($line['quantityMilli']))
			->set('unit_price_cents', $query->createNamedParameter($line['unitPriceCents']))
			->set('vat_rate', $query->createNamedParameter($line['vatRate']))
			->set('unit', $query->createNamedParameter($line['unit']))
			->set('quantity_decimals', $query->createNamedParameter($line['quantityDecimals']))
			->set('origin', $query->createNamedParameter($line['origin'] ?? 'ORDER'))
			->set('sort_order', $query->createNamedParameter($position))
			->set('updated_at', $query->createNamedParameter(date('c')))
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->executeStatement();
	}

	private function mapCaseService(array $row): array {
		$quantityMilli = (int)$row['quantity_milli'];
		$netCents = (int)round($quantityMilli * (int)$row['unit_price_cents'] / 1000);
		$vatCents = (int)round($netCents * (int)$row['vat_rate'] / 100);
		$performedQuantityMilli = (int)($row['performed_quantity_milli'] ?? 0);
		$performedNetCents = (int)round($performedQuantityMilli * (int)$row['unit_price_cents'] / 1000);
		$performedVatCents = (int)round($performedNetCents * (int)$row['vat_rate'] / 100);
		return [
			'id' => (int)$row['id'],
			'sideOrderId' => $row['side_order_id'] !== null ? (int)$row['side_order_id'] : null,
			'articleId' => (int)($row['article_id'] ?? 0),
			'sourcePackageId' => $row['source_package_id'] !== null ? (int)$row['source_package_id'] : null,
			'sourcePackageName' => (string)($row['source_package_name'] ?? ''),
			'articleNumber' => $row['article_number'],
			'title' => $row['title'],
			'longText' => (string)($row['long_text'] ?? ''),
			'category' => (string)($row['article_category'] ?? ''),
			'note' => (string)($row['note'] ?? ''),
			'articleGroup' => $row['article_group'],
			'costType' => $row['cost_type'],
			'position' => (int)($row['sort_order'] ?? 0),
			'positionType' => (string)($row['position_type'] ?? $this->defaultPositionType((string)$row['cost_type'])),
			'quantityMilli' => $quantityMilli,
			'quantity' => $quantityMilli / 1000,
			'origin' => in_array((string)($row['origin'] ?? ''), ['NACHTRAG', 'INCOMING_INVOICE'], true) ? (string)$row['origin'] : ((int)($row['article_id'] ?? 0) === 0 ? 'FREE_TEXT' : (string)($row['origin'] ?? 'ORDER')),
			'serviceStatus' => (string)($row['service_status'] ?? 'BEAUFTRAGT'),
			'orderedQuantityMilli' => (int)($row['ordered_quantity_milli'] ?? $quantityMilli),
			'performedQuantityMilli' => (int)($row['performed_quantity_milli'] ?? 0),
			'performedQuantity' => (int)($row['performed_quantity_milli'] ?? 0) / 1000,
			'invoicedQuantityMilli' => (int)($row['invoiced_quantity_milli'] ?? 0),
			'billability' => (string)($row['billability'] ?? 'ABRECHENBAR'),
			'classificationReason' => (string)($row['classification_reason'] ?? ''),
			'performedAt' => (string)($row['performed_at'] ?? ''),
			'performedBy' => (string)($row['performed_by'] ?? ''),
			'unitPriceCents' => (int)$row['unit_price_cents'],
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

	private function ensureSeedData(): void {
		$query = $this->db->getQueryBuilder();
		if ((int)$query->select($query->func()->count('*', 'count'))->from('bestatter_articles')->executeQuery()->fetchOne() > 0) return;
		if (!is_file(self::SEED_FILE)) return;
		$data = json_decode((string)file_get_contents(self::SEED_FILE), true, 512, JSON_THROW_ON_ERROR);
		$components = [];
		foreach ($data['articles'] ?? [] as $entry) {
			$article = $this->save($entry);
			$components[(int)$article['id']] = $entry['components'] ?? [];
		}
		foreach ($components as $packageId => $entries) {
			$resolved = [];
			foreach ($entries as $entry) {
				$id = $this->findIdByNumber((string)($entry['articleNumber'] ?? ''));
				if ($id) $resolved[] = ['articleId' => $id, 'quantity' => $entry['quantity'] ?? 1];
			}
			$this->saveComponents($packageId, $resolved);
		}
	}

	private function findIdByNumber(string $number): int {
		if ($number === '') return 0;
		$query = $this->db->getQueryBuilder();
		return (int)$query->select('id')->from('bestatter_articles')
			->where($query->expr()->eq('article_number', $query->createNamedParameter($number)))
			->executeQuery()->fetchOne();
	}

	private function normalizeHeader(string $header): string {
		$key = strtolower(trim($header));
		$key = strtr($key, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', ' ' => '_', '-' => '_']);
		return [
			'artikeltyp' => 'itemType', 'item_type' => 'itemType', 'artikelnummer' => 'articleNumber', 'article_number' => 'articleNumber',
			'kurzbezeichnung' => 'shortName', 'short_name' => 'shortName', 'langtext' => 'longText', 'long_text' => 'longText',
			'kategorie' => 'category', 'gruppe' => 'articleGroup', 'article_group' => 'articleGroup', 'kostenart' => 'costType', 'cost_type' => 'costType',
			'bestattungsart' => 'funeralScope', 'funeral_scope' => 'funeralScope', 'mwst' => 'vatRate', 'vat_rate' => 'vatRate',
			'einheit' => 'unit', 'unit' => 'unit', 'nachkommastellen' => 'quantityDecimals', 'quantity_decimals' => 'quantityDecimals', 'exklusive_gruppe' => 'exclusiveGroup', 'exclusive_group' => 'exclusiveGroup',
			'loeschen' => 'delete', 'löschen' => 'delete', 'delete' => 'delete',
			'standardlieferant' => 'supplierContact', 'supplier_contact' => 'supplierContact', 'foto' => 'photoPath', 'photo_path' => 'photoPath',
			'ek_preis' => 'purchasePrice', 'purchase_price' => 'purchasePrice', 'vk_preis' => 'salesPrice', 'sales_price' => 'salesPrice',
			'aktiv' => 'active', 'bestandteile' => 'components',
		][$key] ?? $header;
	}

	private function moneyToCents(mixed $value, bool $alreadyCents = false): int {
		if ($alreadyCents) return max(0, (int)$value);
		$normalized = str_replace(['.', ','], ['', '.'], trim((string)$value));
		if (substr_count(trim((string)$value), '.') === 1 && !str_contains((string)$value, ',')) $normalized = trim((string)$value);
		return max(0, (int)round((float)$normalized * 100));
	}

	private function quantityToMilli(mixed $value, bool $alreadyMilli = false): int {
		if ($alreadyMilli) return max(1, (int)$value);
		return max(1, (int)round((float)str_replace(',', '.', (string)$value) * 1000));
	}

	private function ensureFolder(Folder $parent, string $name): Folder {
		return $parent->nodeExists($name) ? $parent->get($name) : $parent->newFolder($name);
	}
}
