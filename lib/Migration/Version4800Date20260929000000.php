<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.69.0 – Leistungskatalog nach SAP-Vorbild.
 *
 * - Bereiche (Ebene 1) und Warengruppen mit festem Schlüssel, Sortierung, Auswahlregel und
 *   Bestattungsbezug statt Gruppennamen als Freitext.
 * - Artikelart und Steuerklasse (V/E/A) statt Kategorie, Kostenart und festem Steuersatz; die
 *   bisherigen Spalten bleiben als abgeleitete Kompatibilitätswerte erhalten.
 * - Preislisten mit Gültigkeit (Standard und je Niederlassung) statt eines VK-Feldes.
 * - Stücklisten (Kopf und Positionen mit fest/optional/Alternative) statt Paketkomponenten.
 * - Fallpositionen: Paket-Kopf mit Unterpositionen, Snapshot von Warengruppe/Steuerklasse,
 *   Listenpreis und Mehrpreis; Rechnungspositionen kennen ihr Paket für den Druck.
 *
 * Bestehende Fälle, Verträge und Rechnungen behalten ihre Snapshots unverändert.
 * Die alte Tabelle bestatter_article_components entfernt Version4801 nach der Übernahme.
 */
class Version4800Date20260929000000 extends SimpleMigrationStep {
	private const SEED_FILE = __DIR__ . '/../../resources/article-catalog.json';

	/** Gruppennamen des bisherigen Beispielkatalogs → neue Warengruppe. */
	private const NAME_MAP = [
		'überführung' => '110', 'bekleidung' => '230', 'sarg' => '210', 'beerdigung/trauerfeier' => '140',
		'drucksachen' => '420', 'auslagen' => '710', 'gebühren' => '710', 'leistungen für externe' => '610',
		'freitext' => null,
	];

	/** Artikelnummern des bisherigen Beispielkatalogs → [Warengruppe, Artikelart, Steuerklasse]. */
	private const LEGACY_ARTICLES = [
		'DL-EINBETTEN' => ['120', 'DIENSTLEISTUNG', 'V'], 'LA-SARG-STANDARD' => ['210', 'HANDELSWARE', 'V'],
		'LA-URNE-STANDARD' => ['310', 'HANDELSWARE', 'V'], 'LA-DECKE-KISSEN' => ['220', 'HANDELSWARE', 'V'],
		'LA-TOTENBEKLEIDUNG' => ['230', 'HANDELSWARE', 'V'], 'DL-BEKLEIDUNG-EIGEN' => ['120', 'DIENSTLEISTUNG', 'V'],
		'DL-UEBERFUEHRUNG' => ['110', 'DIENSTLEISTUNG', 'V'], 'DL-AUFBAHRUNG-OFFEN' => ['130', 'DIENSTLEISTUNG', 'V'],
		'DL-AUFBAHRUNG-TRAUERFEIER' => ['130', 'DIENSTLEISTUNG', 'V'], 'DL-TRAUERFEIER-ORG' => ['140', 'DIENSTLEISTUNG', 'V'],
		'DL-KREMATORIUM-TRANSFER' => ['110', 'DIENSTLEISTUNG', 'V'], 'DL-URNE-FRIEDHOF' => ['110', 'DIENSTLEISTUNG', 'V'],
		'DL-URNENBEISETZUNG' => ['140', 'DIENSTLEISTUNG', 'V'], 'DL-KRANZTRANSPORT' => ['140', 'DIENSTLEISTUNG', 'V'],
		'DL-LAUTSPRECHER' => ['140', 'DIENSTLEISTUNG', 'V'], 'DL-FORMALITAETEN' => ['150', 'DIENSTLEISTUNG', 'V'],
		'DR-TRAUERANZEIGE' => ['410', 'DIENSTLEISTUNG', 'V'], 'DR-TRAUERBRIEF' => ['420', 'HANDELSWARE', 'V'],
		'ZU-AUSSERHALB' => ['810', 'ZUSCHLAG', 'V'], 'AU-ARZT-TOTENSCHEIN' => ['730', 'AUSLAGE', 'A'],
		'GE-MELDEAMT' => ['710', 'AUSLAGE', 'A'], 'GE-STANDESAMT-ABSCHRIFT' => ['720', 'AUSLAGE', 'A'],
		'GE-STANDESAMT-STERBEURKUNDE' => ['720', 'AUSLAGE', 'A'], 'GE-FRIEDHOF' => ['710', 'AUSLAGE', 'A'],
		// Weiterberechnung im eigenen Namen (Entscheidung 0.69.0): Fremdleistung mit vollem Steuersatz.
		'AU-ORGANIST' => ['610', 'FREMDLEISTUNG', 'V'], 'AU-TRAEGER' => ['620', 'FREMDLEISTUNG', 'V'],
		'AU-GRABARBEITEN' => ['640', 'FREMDLEISTUNG', 'V'], 'AU-RECHTSMEDIZIN' => ['730', 'AUSLAGE', 'A'],
		'FL-SARGSCHMUCK' => ['510', 'HANDELSWARE', 'E'], 'FL-URNENSCHMUCK' => ['510', 'HANDELSWARE', 'E'],
		'FL-KRANZ' => ['520', 'HANDELSWARE', 'E'], 'FL-NACHWERFBLUMEN' => ['510', 'HANDELSWARE', 'E'],
		'FL-GESTECK' => ['520', 'HANDELSWARE', 'E'], 'PK-FEUER-BASIS' => ['910', 'PAKET', 'V'], 'PK-ERDE-BASIS' => ['910', 'PAKET', 'V'],
	];

	public function __construct(private IDBConnection $db, private IConfig $config) {}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('bestatter_article_areas')) {
			$t = $schema->createTable('bestatter_article_areas');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('area_key', 'string', ['length' => 10]);
			$t->addColumn('name', 'string', ['length' => 120]);
			$t->addColumn('sort_order', 'integer', ['default' => 0]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->addColumn('updated_at', 'string', ['length' => 40]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['area_key'], 'bestatter_area_key');
		}
		$groups = $schema->getTable('bestatter_article_groups');
		$this->columns($groups, [
			'group_key' => ['string', ['length' => 20, 'notnull' => false]],
			'area_key' => ['string', ['length' => 10, 'notnull' => false]],
			'sort_order' => ['integer', ['default' => 0]],
			'selection_rule' => ['string', ['length' => 16, 'default' => 'MANY']],
			'funeral_scope' => ['string', ['length' => 20, 'default' => 'ALL']],
			'active' => ['boolean', ['notnull' => false, 'default' => true]],
		]);
		if (!$groups->hasIndex('bestatter_group_key')) $groups->addUniqueIndex(['group_key'], 'bestatter_group_key');

		$articles = $schema->getTable('bestatter_articles');
		$this->columns($articles, [
			'article_type' => ['string', ['length' => 20, 'default' => 'DIENSTLEISTUNG']],
			'tax_class' => ['string', ['length' => 2, 'default' => 'V']],
			'search_term' => ['string', ['length' => 60, 'notnull' => false]],
			'internal_note' => ['text', ['notnull' => false]],
			'price_on_entry' => ['boolean', ['notnull' => false, 'default' => false]],
			'guide_price_cents' => ['integer', ['notnull' => false]],
		]);

		if (!$schema->hasTable('bestatter_price_lists')) {
			$t = $schema->createTable('bestatter_price_lists');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('list_key', 'string', ['length' => 40]);
			$t->addColumn('name', 'string', ['length' => 150]);
			$t->addColumn('branch_key', 'string', ['length' => 100, 'notnull' => false]);
			$t->addColumn('active', 'boolean', ['notnull' => false, 'default' => true]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->addColumn('updated_at', 'string', ['length' => 40]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['list_key'], 'bestatter_price_list_key');
		}
		if (!$schema->hasTable('bestatter_price_list_items')) {
			$t = $schema->createTable('bestatter_price_list_items');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('price_list_id', 'integer', ['unsigned' => true]);
			$t->addColumn('article_id', 'integer', ['unsigned' => true]);
			$t->addColumn('price_cents', 'integer', ['default' => 0]);
			$t->addColumn('valid_from', 'string', ['length' => 10]);
			$t->addColumn('valid_to', 'string', ['length' => 10, 'notnull' => false]);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => false]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->addColumn('updated_at', 'string', ['length' => 40]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['price_list_id', 'article_id'], 'bestatter_pli_list_article');
			$t->addIndex(['article_id'], 'bestatter_pli_article');
		}
		if (!$schema->hasTable('bestatter_boms')) {
			$t = $schema->createTable('bestatter_boms');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('package_article_id', 'integer', ['unsigned' => true]);
			$t->addColumn('pricing', 'string', ['length' => 10, 'default' => 'ITEMS']);
			$t->addColumn('print_mode', 'string', ['length' => 24, 'default' => 'ITEMS_NO_PRICES']);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->addColumn('updated_at', 'string', ['length' => 40]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['package_article_id'], 'bestatter_bom_package');
		}
		if (!$schema->hasTable('bestatter_bom_items')) {
			$t = $schema->createTable('bestatter_bom_items');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('bom_id', 'integer', ['unsigned' => true]);
			$t->addColumn('item_no', 'integer', ['default' => 10]);
			$t->addColumn('article_id', 'integer', ['unsigned' => true]);
			$t->addColumn('quantity_milli', 'integer', ['default' => 1000]);
			$t->addColumn('item_type', 'string', ['length' => 12, 'default' => 'FIXED']);
			$t->addColumn('alt_group', 'string', ['length' => 40, 'notnull' => false]);
			$t->addColumn('is_default', 'boolean', ['notnull' => false, 'default' => false]);
			$t->addColumn('credit_cents', 'integer', ['default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['bom_id'], 'bestatter_bom_item_bom');
			$t->addIndex(['article_id'], 'bestatter_bom_item_article');
		}

		$services = $schema->getTable('bestatter_case_services');
		$this->columns($services, [
			'parent_service_id' => ['integer', ['unsigned' => true, 'notnull' => false]],
			'item_category' => ['string', ['length' => 12, 'default' => 'NORMAL']],
			'bom_item_id' => ['integer', ['unsigned' => true, 'notnull' => false]],
			'sub_position' => ['integer', ['default' => 0]],
			'package_pricing' => ['string', ['length' => 10, 'notnull' => false]],
			'package_price_cents' => ['integer', ['notnull' => false]],
			'print_mode' => ['string', ['length' => 24, 'notnull' => false]],
			'list_price_cents' => ['integer', ['default' => 0]],
			'surcharge_cents' => ['integer', ['default' => 0]],
			'article_group_key' => ['string', ['length' => 20, 'notnull' => false]],
			'tax_class' => ['string', ['length' => 2, 'notnull' => false]],
			'article_type' => ['string', ['length' => 20, 'notnull' => false]],
			'price_on_entry' => ['boolean', ['notnull' => false, 'default' => false]],
			'guide_price_cents' => ['integer', ['notnull' => false]],
		]);
		if (!$services->hasIndex('bestatter_service_parent')) $services->addIndex(['parent_service_id'], 'bestatter_service_parent');

		$invoiceItems = $schema->getTable('bestatter_invoice_items');
		$this->columns($invoiceItems, [
			'sub_position' => ['integer', ['default' => 0]],
			'package_service_id' => ['integer', ['unsigned' => true, 'notnull' => false]],
			'package_title' => ['string', ['length' => 220, 'notnull' => false]],
			'package_position' => ['integer', ['notnull' => false]],
			'print_mode' => ['string', ['length' => 24, 'notnull' => false]],
		]);
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$seed = is_file(self::SEED_FILE) ? json_decode((string)file_get_contents(self::SEED_FILE), true) : [];
		$seed = is_array($seed) ? $seed : [];
		$now = date('c');

		$seedKeys = [];
		$nameMap = self::NAME_MAP;
		foreach ((array)($seed['groups'] ?? []) as $group) {
			$seedKeys[(string)$group['key']] = true;
			$nameMap[mb_strtolower(trim((string)$group['name']))] = (string)$group['key'];
		}

		// Bisherige Gruppenzeilen (nur Name + Exklusiv-Kennzeichen): bekannte Namen gehen in der
		// neuen Warengruppe auf, unbekannte werden als Übernahmegruppe mit Schlüssel X01 … geführt.
		$legacyKeys = [];
		$tighten = [];
		$query = $this->db->getQueryBuilder();
		$legacy = $query->select('id', 'group_name', 'exclusive_selection')->from('bestatter_article_groups')
			->where($query->expr()->isNull('group_key'))->executeQuery()->fetchAllAssociative();
		$remaining = [];
		foreach ($legacy as $row) {
			$name = mb_strtolower(trim((string)$row['group_name']));
			$target = $nameMap[$name] ?? null;
			if ($target !== null && isset($seedKeys[$target])) {
				if ((bool)$row['exclusive_selection']) $tighten[$target] = true;
				$delete = $this->db->getQueryBuilder();
				$delete->delete('bestatter_article_groups')->where($delete->expr()->eq('id', $delete->createNamedParameter((int)$row['id'])))->executeStatement();
				$legacyKeys[$name] = $target;
				continue;
			}
			$remaining[] = $row;
		}
		foreach ((array)($seed['areas'] ?? []) as $area) $this->ensureArea((string)$area['key'], (string)$area['name'], (int)($area['sortOrder'] ?? 0), $now);
		foreach ((array)($seed['groups'] ?? []) as $group) $this->ensureGroup($group, $now);
		foreach (array_keys($tighten) as $target) $this->tightenRule((string)$target);
		foreach ($remaining as $row) {
			$name = (string)$row['group_name'];
			$key = $this->nextTransferKey();
			$this->ensureArea('0', 'Übernommene Gruppen (bitte zuordnen)', 0, $now);
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_article_groups')
				->set('group_key', $update->createNamedParameter($key))
				->set('area_key', $update->createNamedParameter('0'))
				->set('selection_rule', $update->createNamedParameter((bool)$row['exclusive_selection'] ? 'MAX_ONE' : 'MANY'))
				->set('funeral_scope', $update->createNamedParameter('ALL'))
				->set('updated_at', $update->createNamedParameter($now))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'])))->executeStatement();
			$legacyKeys[mb_strtolower(trim($name))] = $key;
		}

		// Artikel: Warengruppe, Artikelart, Steuerklasse, Standardpreisliste.
		$listId = $this->ensureStandardPriceList($now);
		$query = $this->db->getQueryBuilder();
		$articles = $query->select('*')->from('bestatter_articles')->executeQuery()->fetchAllAssociative();
		$articleGroupKeys = [];
		foreach ($articles as $row) {
			$number = (string)$row['article_number'];
			$groupName = trim((string)$row['article_group']);
			if ($this->groupExists($groupName)) {
				$groupKey = $groupName; // bereits migriert
			} else {
				$groupKey = self::LEGACY_ARTICLES[$number][0] ?? $legacyKeys[mb_strtolower($groupName)] ?? $nameMap[mb_strtolower($groupName)] ?? null;
				if ($groupKey === null) {
					$groupKey = $this->nextTransferKey();
					$this->ensureArea('0', 'Übernommene Gruppen (bitte zuordnen)', 0, $now);
					$this->ensureGroup(['key' => $groupKey, 'name' => $groupName !== '' ? $groupName : 'Ohne Gruppe', 'area' => '0', 'selectionRule' => (bool)$row['exclusive_group'] ? 'MAX_ONE' : 'MANY', 'funeralScope' => 'ALL', 'sortOrder' => 0], $now);
					$legacyKeys[mb_strtolower($groupName)] = $groupKey;
				}
			}
			[$type, $taxClass] = $this->legacyTypeAndTax($row);
			$vat = ['V' => 19, 'E' => 7, 'A' => 0][$taxClass];
			$cost = match ($type) { 'FREMDLEISTUNG' => 'THIRD_PARTY', 'AUSLAGE' => 'EXPENSE', default => 'INTERNAL' };
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_articles')
				->set('article_group', $update->createNamedParameter($groupKey))
				->set('article_type', $update->createNamedParameter($type))
				->set('tax_class', $update->createNamedParameter($taxClass))
				->set('item_type', $update->createNamedParameter($type === 'PAKET' ? 'PACKAGE' : 'SINGLE'))
				->set('cost_type', $update->createNamedParameter($cost))
				->set('vat_rate', $update->createNamedParameter($vat))
				->set('price_on_entry', $update->createNamedParameter($type === 'AUSLAGE' && (int)$row['sales_price_cents'] === 0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL))
				->set('updated_at', $update->createNamedParameter($now))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'])))->executeStatement();
			$articleGroupKeys[(int)$row['id']] = $groupKey;
			if ((int)$row['sales_price_cents'] > 0 && !$this->hasPrice($listId, (int)$row['id'])) {
				$insert = $this->db->getQueryBuilder();
				$insert->insert('bestatter_price_list_items')->values([
					'price_list_id' => $insert->createNamedParameter($listId), 'article_id' => $insert->createNamedParameter((int)$row['id']),
					'price_cents' => $insert->createNamedParameter((int)$row['sales_price_cents']), 'valid_from' => $insert->createNamedParameter('2000-01-01'),
					'valid_to' => $insert->createNamedParameter(null), 'created_by' => $insert->createNamedParameter('migration'),
					'created_at' => $insert->createNamedParameter($now), 'updated_at' => $insert->createNamedParameter($now),
				])->executeStatement();
			}
		}

		// Paketkomponenten → Stücklisten mit Preisfindung „Summe der Positionen“ (bisheriges Verhalten).
		if ($this->tableExists('bestatter_article_components')) {
			$query = $this->db->getQueryBuilder();
			$components = $query->select('*')->from('bestatter_article_components')->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
			$byPackage = [];
			foreach ($components as $component) $byPackage[(int)$component['package_id']][] = $component;
			foreach ($byPackage as $packageId => $entries) {
				$query = $this->db->getQueryBuilder();
				if ($query->select('id')->from('bestatter_boms')->where($query->expr()->eq('package_article_id', $query->createNamedParameter($packageId)))->executeQuery()->fetchOne() !== false) continue;
				$insert = $this->db->getQueryBuilder();
				$insert->insert('bestatter_boms')->values([
					'package_article_id' => $insert->createNamedParameter($packageId), 'pricing' => $insert->createNamedParameter('ITEMS'),
					'print_mode' => $insert->createNamedParameter('ITEMS_NO_PRICES'), 'created_at' => $insert->createNamedParameter($now), 'updated_at' => $insert->createNamedParameter($now),
				])->executeStatement();
				$bomId = (int)$this->db->lastInsertId('bestatter_boms');
				foreach ($entries as $index => $entry) {
					$item = $this->db->getQueryBuilder();
					$item->insert('bestatter_bom_items')->values([
						'bom_id' => $item->createNamedParameter($bomId), 'item_no' => $item->createNamedParameter(($index + 1) * 10),
						'article_id' => $item->createNamedParameter((int)$entry['article_id']), 'quantity_milli' => $item->createNamedParameter((int)$entry['quantity_milli']),
						'item_type' => $item->createNamedParameter('FIXED'), 'alt_group' => $item->createNamedParameter(null),
						'is_default' => $item->createNamedParameter(false, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL), 'credit_cents' => $item->createNamedParameter(0),
					])->executeStatement();
				}
			}
		}

		// Fallpositionen: Snapshot von Warengruppe und Steuerklasse; Leistungsstatus ist künftig abgeleitet.
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('id', 'article_id', 'article_group', 'vat_rate', 'position_type', 'service_status', 'billability', 'unit_price_cents')
			->from('bestatter_case_services')->where($query->expr()->isNull('tax_class'))->executeQuery()->fetchAllAssociative();
		foreach ($rows as $row) {
			$articleId = (int)($row['article_id'] ?? 0);
			$groupKey = $articleGroupKeys[$articleId] ?? $legacyKeys[mb_strtolower(trim((string)$row['article_group']))] ?? $nameMap[mb_strtolower(trim((string)$row['article_group']))] ?? null;
			$taxClass = strtoupper((string)$row['position_type']) === 'DP' || (int)$row['vat_rate'] === 0 ? 'A' : ((int)$row['vat_rate'] <= 10 ? 'E' : 'V');
			$status = (string)$row['service_status'];
			$billability = (string)$row['billability'];
			if (in_array($status, ['KULANZ', 'NICHT_ABRECHENBAR'], true) && $billability === 'ABRECHENBAR') $billability = $status;
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_case_services')
				->set('article_group_key', $update->createNamedParameter($groupKey))
				->set('tax_class', $update->createNamedParameter($taxClass))
				->set('item_category', $update->createNamedParameter('NORMAL'))
				->set('list_price_cents', $update->createNamedParameter((int)$row['unit_price_cents']))
				->set('billability', $update->createNamedParameter($billability))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'])))->executeStatement();
		}

		// Variantenregeln verweisen künftig auf den Warengruppen-Schlüssel.
		$query = $this->db->getQueryBuilder();
		foreach ($query->select('id', 'article_group')->from('bestatter_burial_variant_rules')->executeQuery()->fetchAllAssociative() as $rule) {
			$name = trim((string)($rule['article_group'] ?? ''));
			if ($name === '' || $this->groupExists($name)) continue;
			$key = $legacyKeys[mb_strtolower($name)] ?? $nameMap[mb_strtolower($name)] ?? null;
			if ($key === null) continue;
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_burial_variant_rules')->set('article_group', $update->createNamedParameter($key))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$rule['id'])))->executeStatement();
		}

		// Wertelisten: Gruppen und Kategorien sind jetzt Stammdaten; neue Einheiten Satz und Person.
		foreach (['ARTICLE_GROUP', 'ARTICLE_CATEGORY'] as $listKey) {
			$query = $this->db->getQueryBuilder();
			$id = $query->select('id')->from('bestatter_choice_lists')->where($query->expr()->eq('list_key', $query->createNamedParameter($listKey)))->executeQuery()->fetchOne();
			if ($id === false) continue;
			$delete = $this->db->getQueryBuilder();
			$delete->delete('bestatter_choice_items')->where($delete->expr()->eq('list_id', $delete->createNamedParameter((int)$id)))->executeStatement();
			$delete = $this->db->getQueryBuilder();
			$delete->delete('bestatter_choice_lists')->where($delete->expr()->eq('id', $delete->createNamedParameter((int)$id)))->executeStatement();
		}
		$query = $this->db->getQueryBuilder();
		$unitList = $query->select('id')->from('bestatter_choice_lists')->where($query->expr()->eq('list_key', $query->createNamedParameter('QUANTITY_UNIT')))->executeQuery()->fetchOne();
		if ($unitList !== false) {
			foreach ([['SATZ', 'Satz', 8], ['PERS', 'Person', 9]] as [$value, $label, $sort]) {
				$query = $this->db->getQueryBuilder();
				$exists = $query->select('id')->from('bestatter_choice_items')->where($query->expr()->eq('list_id', $query->createNamedParameter((int)$unitList)))
					->andWhere($query->expr()->eq('value', $query->createNamedParameter($value)))->executeQuery()->fetchOne();
				if ($exists !== false) continue;
				$insert = $this->db->getQueryBuilder();
				$insert->insert('bestatter_choice_items')->values(['list_id' => $insert->createNamedParameter((int)$unitList), 'value' => $insert->createNamedParameter($value), 'label' => $insert->createNamedParameter($label), 'sort_order' => $insert->createNamedParameter($sort)])->executeStatement();
			}
		}

		// Der neue Beispielkatalog wird beim nächsten Katalogaufruf angelegt (ArticleService).
		if ($articles !== []) $this->config->setAppValue('bestatter', 'catalog_sample_pending', '2026');
		$output->info('Leistungskatalog auf Warengruppen, Steuerklassen, Preislisten und Stücklisten umgestellt.');
	}

	private function legacyTypeAndTax(array $row): array {
		$number = (string)$row['article_number'];
		if (isset(self::LEGACY_ARTICLES[$number])) return [self::LEGACY_ARTICLES[$number][1], self::LEGACY_ARTICLES[$number][2]];
		$current = strtoupper((string)($row['article_type'] ?? ''));
		if ((string)$row['item_type'] === 'PACKAGE') return ['PAKET', 'V'];
		$type = match (strtoupper((string)$row['cost_type'])) {
			'THIRD_PARTY' => 'FREMDLEISTUNG',
			'EXPENSE' => 'AUSLAGE',
			default => in_array((string)$row['category'], ['Lagerartikel', 'Bestellartikel'], true) ? 'HANDELSWARE' : 'DIENSTLEISTUNG',
		};
		if ($current !== '' && $current !== 'DIENSTLEISTUNG') $type = $current;
		$vat = (int)$row['vat_rate'];
		$tax = $vat === 0 ? 'A' : ($vat <= 10 ? 'E' : 'V');
		return [$type, $tax];
	}

	private function ensureArea(string $key, string $name, int $sort, string $now): void {
		$query = $this->db->getQueryBuilder();
		if ($query->select('id')->from('bestatter_article_areas')->where($query->expr()->eq('area_key', $query->createNamedParameter($key)))->executeQuery()->fetchOne() !== false) return;
		$insert = $this->db->getQueryBuilder();
		$insert->insert('bestatter_article_areas')->values(['area_key' => $insert->createNamedParameter($key), 'name' => $insert->createNamedParameter($name), 'sort_order' => $insert->createNamedParameter($sort), 'created_at' => $insert->createNamedParameter($now), 'updated_at' => $insert->createNamedParameter($now)])->executeStatement();
	}

	private function ensureGroup(array $group, string $now): void {
		$key = (string)$group['key'];
		if ($this->groupExists($key)) return;
		$name = (string)$group['name'];
		// Ein gleichnamiger Altbestand (ohne Schlüssel) würde den Unique-Index auf group_name verletzen.
		$query = $this->db->getQueryBuilder();
		$sameName = $query->select('id')->from('bestatter_article_groups')->where($query->expr()->eq('group_name', $query->createNamedParameter($name)))->executeQuery()->fetchOne();
		if ($sameName !== false) {
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_article_groups')->set('group_name', $update->createNamedParameter(mb_substr($name . ' (alt)', 0, 150)))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$sameName)))->executeStatement();
		}
		$insert = $this->db->getQueryBuilder();
		$rule = (string)($group['selectionRule'] ?? 'MANY');
		$insert->insert('bestatter_article_groups')->values([
			'group_name' => $insert->createNamedParameter($name), 'group_key' => $insert->createNamedParameter($key),
			'area_key' => $insert->createNamedParameter((string)($group['area'] ?? substr($key, 0, 1))), 'sort_order' => $insert->createNamedParameter((int)($group['sortOrder'] ?? 0)),
			'selection_rule' => $insert->createNamedParameter($rule), 'funeral_scope' => $insert->createNamedParameter((string)($group['funeralScope'] ?? 'ALL')),
			'active' => $insert->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL),
			'exclusive_selection' => $insert->createNamedParameter($rule !== 'MANY', \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL),
			'created_at' => $insert->createNamedParameter($now), 'updated_at' => $insert->createNamedParameter($now),
		])->executeStatement();
	}

	private function groupExists(string $key): bool {
		if ($key === '') return false;
		$query = $this->db->getQueryBuilder();
		return $query->select('id')->from('bestatter_article_groups')->where($query->expr()->eq('group_key', $query->createNamedParameter($key)))->executeQuery()->fetchOne() !== false;
	}

	private function tightenRule(string $key): void {
		$update = $this->db->getQueryBuilder();
		$update->update('bestatter_article_groups')->set('selection_rule', $update->createNamedParameter('MAX_ONE'))->set('exclusive_selection', $update->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL))
			->where($update->expr()->eq('group_key', $update->createNamedParameter($key)))
			->andWhere($update->expr()->eq('selection_rule', $update->createNamedParameter('MANY')))->executeStatement();
	}

	private function nextTransferKey(): string {
		for ($i = 1; $i < 1000; $i++) {
			$key = sprintf('X%02d', $i);
			if (!$this->groupExists($key)) return $key;
		}
		throw new \RuntimeException('Keine freie Übernahmegruppe verfügbar.');
	}

	private function ensureStandardPriceList(string $now): int {
		$query = $this->db->getQueryBuilder();
		$id = $query->select('id')->from('bestatter_price_lists')->where($query->expr()->eq('list_key', $query->createNamedParameter('STANDARD')))->executeQuery()->fetchOne();
		if ($id !== false) return (int)$id;
		$insert = $this->db->getQueryBuilder();
		$insert->insert('bestatter_price_lists')->values(['list_key' => $insert->createNamedParameter('STANDARD'), 'name' => $insert->createNamedParameter('Standardpreisliste'), 'branch_key' => $insert->createNamedParameter(null), 'active' => $insert->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL), 'created_at' => $insert->createNamedParameter($now), 'updated_at' => $insert->createNamedParameter($now)])->executeStatement();
		return (int)$this->db->lastInsertId('bestatter_price_lists');
	}

	private function hasPrice(int $listId, int $articleId): bool {
		$query = $this->db->getQueryBuilder();
		return $query->select('id')->from('bestatter_price_list_items')->where($query->expr()->eq('price_list_id', $query->createNamedParameter($listId)))
			->andWhere($query->expr()->eq('article_id', $query->createNamedParameter($articleId)))->executeQuery()->fetchOne() !== false;
	}

	private function tableExists(string $table): bool {
		return $this->db->tableExists($table);
	}

	private function columns(mixed $table, array $definitions): void {
		foreach ($definitions as $name => [$type, $options]) if (!$table->hasColumn($name)) $table->addColumn($name, $type, $options);
	}
}
