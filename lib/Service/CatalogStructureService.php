<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Bereiche (Ebene 1) und Warengruppen (Ebene 2) des Leistungskatalogs. Die Warengruppe wird über
 * ihren festen Schlüssel referenziert; Bezeichnung, Sortierung und Regeln sind pflegbar, ohne dass
 * Artikel, Variantenregeln oder Fallpositionen angepasst werden müssen.
 */
class CatalogStructureService {
	private const SEED_FILE = __DIR__ . '/../../resources/article-catalog.json';

	public function __construct(private IDBConnection $db) {}

	public function ensureSeedData(): void {
		$query = $this->db->getQueryBuilder();
		if ((int)$query->select($query->func()->count('*', 'count'))->from('bestatter_article_areas')->executeQuery()->fetchOne() > 0) return;
		$seed = $this->seed();
		foreach ((array)($seed['areas'] ?? []) as $area) $this->insertArea((string)$area['key'], (string)$area['name'], (int)($area['sortOrder'] ?? 0));
		foreach ((array)($seed['groups'] ?? []) as $group) if ($this->group((string)$group['key']) === null) $this->insertGroup($group);
	}

	public function areas(): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('area_key', 'name', 'sort_order')->from('bestatter_article_areas')->orderBy('sort_order', 'ASC')->addOrderBy('area_key', 'ASC')->executeQuery()->fetchAllAssociative();
		return array_map(static fn(array $row): array => ['key' => (string)$row['area_key'], 'name' => (string)$row['name'], 'sortOrder' => (int)$row['sort_order']], $rows);
	}

	public function groups(): array {
		$areas = array_column($this->areas(), null, 'key');
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_article_groups')->where($query->expr()->isNotNull('group_key'))
			->orderBy('sort_order', 'ASC')->addOrderBy('group_key', 'ASC')->executeQuery()->fetchAllAssociative();
		$groups = array_map(fn(array $row): array => $this->mapGroup($row, $areas), $rows);
		usort($groups, static fn(array $left, array $right): int => [$left['areaSortOrder'], $left['sortOrder'], $left['key']] <=> [$right['areaSortOrder'], $right['sortOrder'], $right['key']]);
		return $groups;
	}

	/** @return array<string, array> */
	public function groupMap(): array {
		return array_column($this->groups(), null, 'key');
	}

	public function group(string $key): ?array {
		if (trim($key) === '') return null;
		$query = $this->db->getQueryBuilder();
		$row = $query->select('*')->from('bestatter_article_groups')->where($query->expr()->eq('group_key', $query->createNamedParameter($key)))->executeQuery()->fetchAssociative();
		return $row ? $this->mapGroup($row, array_column($this->areas(), null, 'key')) : null;
	}

	public function saveGroup(array $data, bool $create): array {
		$key = strtoupper(trim((string)($data['key'] ?? '')));
		$name = trim((string)($data['name'] ?? ''));
		$area = trim((string)($data['areaKey'] ?? ''));
		$rule = strtoupper(trim((string)($data['selectionRule'] ?? 'MANY')));
		$scope = strtoupper(trim((string)($data['funeralScope'] ?? 'ALL')));
		$sort = max(0, min(99999, (int)($data['sortOrder'] ?? 0)));
		$active = filter_var($data['active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
		if (!preg_match('/^[0-9A-Z]{1,20}$/', $key)) throw new \InvalidArgumentException('Der Warengruppen-Schlüssel besteht aus bis zu 20 Ziffern oder Großbuchstaben (z. B. 210).');
		if ($name === '' || mb_strlen($name) > 150) throw new \InvalidArgumentException('Bitte eine Bezeichnung der Warengruppe (höchstens 150 Zeichen) angeben.');
		if (!in_array($area, array_column($this->areas(), 'key'), true)) throw new \InvalidArgumentException('Bitte einen gültigen Bereich wählen.');
		if (!isset(CatalogRules::SELECTION_RULES[$rule])) throw new \InvalidArgumentException('Die Auswahlregel ist ungültig.');
		if (!isset(CatalogRules::FUNERAL_SCOPES[$scope])) throw new \InvalidArgumentException('Der Bestattungsbezug ist ungültig.');
		$existing = $this->group($key);
		if ($create && $existing !== null) throw new \InvalidArgumentException('Der Warengruppen-Schlüssel ' . $key . ' ist bereits vergeben.');
		if (!$create && $existing === null) throw new \InvalidArgumentException('Die Warengruppe wurde nicht gefunden.');
		$query = $this->db->getQueryBuilder();
		$duplicate = $query->select('group_key')->from('bestatter_article_groups')->where($query->expr()->eq('group_name', $query->createNamedParameter($name)))->executeQuery()->fetchOne();
		if ($duplicate !== false && (string)$duplicate !== $key) throw new \InvalidArgumentException('Eine Warengruppe mit dieser Bezeichnung besteht bereits.');
		$now = date('c');
		if ($create) {
			$this->insertGroup(['key' => $key, 'name' => $name, 'area' => $area, 'selectionRule' => $rule, 'funeralScope' => $scope, 'sortOrder' => $sort, 'active' => $active]);
		} else {
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_article_groups')
				->set('group_name', $update->createNamedParameter($name))->set('area_key', $update->createNamedParameter($area))
				->set('selection_rule', $update->createNamedParameter($rule))->set('funeral_scope', $update->createNamedParameter($scope))
				->set('sort_order', $update->createNamedParameter($sort))->set('active', $update->createNamedParameter($active, IQueryBuilder::PARAM_BOOL))
				->set('exclusive_selection', $update->createNamedParameter($rule !== 'MANY', IQueryBuilder::PARAM_BOOL))
				->set('updated_at', $update->createNamedParameter($now))
				->where($update->expr()->eq('group_key', $update->createNamedParameter($key)))->executeStatement();
		}
		// Abgeleitetes Kompatibilitätsfeld für ältere Exporte.
		$sync = $this->db->getQueryBuilder();
		$sync->update('bestatter_articles')->set('exclusive_group', $sync->createNamedParameter($rule !== 'MANY', IQueryBuilder::PARAM_BOOL))
			->where($sync->expr()->eq('article_group', $sync->createNamedParameter($key)))->executeStatement();
		return (array)$this->group($key);
	}

	public function deleteGroup(string $key): void {
		if ($this->group($key) === null) throw new \InvalidArgumentException('Die Warengruppe wurde nicht gefunden.');
		$query = $this->db->getQueryBuilder();
		$used = (int)$query->select($query->func()->count('*', 'count'))->from('bestatter_articles')->where($query->expr()->eq('article_group', $query->createNamedParameter($key)))->executeQuery()->fetchOne();
		$query = $this->db->getQueryBuilder();
		$rules = (int)$query->select($query->func()->count('*', 'count'))->from('bestatter_burial_variant_rules')->where($query->expr()->eq('article_group', $query->createNamedParameter($key)))->executeQuery()->fetchOne();
		if ($used > 0 || $rules > 0) throw new \InvalidArgumentException('Die Warengruppe wird noch von Artikeln oder Variantenregeln verwendet und kann nur deaktiviert werden.');
		$delete = $this->db->getQueryBuilder();
		$delete->delete('bestatter_article_groups')->where($delete->expr()->eq('group_key', $delete->createNamedParameter($key)))->executeStatement();
	}

	/** Warengruppen als CSV (Klartext für Excel; der Import erkennt Schlüssel und Klartext). */
	public function exportGroupsCsv(): string {
		$rows = array_map(static fn(array $group): array => [$group['key'], $group['name'], $group['areaKey'] . ' · ' . $group['areaName'], (string)$group['sortOrder'], CatalogRules::SELECTION_RULES[$group['selectionRule']] ?? $group['selectionRule'], CatalogRules::FUNERAL_SCOPES[$group['funeralScope']] ?? $group['funeralScope'], $group['active'] ? 'ja' : 'nein'], $this->groups());
		return CsvTools::write(['Schlüssel', 'Bezeichnung', 'Bereich', 'Sortierung', 'Auswahlregel', 'Bestattungsbezug', 'Aktiv'], $rows);
	}

	/** @return array{created: int, updated: int} */
	public function importGroupsCsv(string $content): array {
		$map = ['schluessel' => 'key', 'warengruppe' => 'key', 'key' => 'key', 'bezeichnung' => 'name', 'name' => 'name', 'bereich' => 'areaKey', 'area' => 'areaKey', 'areakey' => 'areaKey',
			'sortierung' => 'sortOrder', 'sortorder' => 'sortOrder', 'auswahlregel' => 'selectionRule', 'selectionrule' => 'selectionRule', 'bestattungsbezug' => 'funeralScope', 'funeralscope' => 'funeralScope', 'aktiv' => 'active', 'active' => 'active'];
		$rows = CsvTools::read($content, static fn(string $header): string => $map[CsvTools::headerKey($header)] ?? $header);
		if ($rows !== [] && !array_key_exists('key', $rows[0]['values'])) throw new \InvalidArgumentException('Die CSV-Datei enthält keine Spalte „Schlüssel“.');
		$areaKeys = array_column($this->areas(), 'key');
		$pick = static function (string $value, array $options, string $default): string {
			$raw = trim($value);
			if ($raw === '') return $default;
			if (isset($options[strtoupper($raw)])) return strtoupper($raw);
			foreach ($options as $code => $label) if (mb_strtolower($label) === mb_strtolower($raw) || str_starts_with(mb_strtolower($label), mb_strtolower($raw))) return (string)$code;
			return strtoupper($raw);
		};
		$created = 0; $updated = 0;
		$this->db->beginTransaction();
		try {
			foreach ($rows as $row) {
				$values = $row['values'];
				$key = strtoupper(trim($values['key'] ?? ''));
				$existing = $this->group($key);
				$area = trim($values['areaKey'] ?? '');
				if (preg_match('/^([0-9A-Z]{1,20})\s*[·\-–:]/u', strtoupper($area), $match)) $area = $match[1];
				if ($area === '' && $existing !== null) $area = $existing['areaKey'];
				if (!in_array($area, $areaKeys, true)) throw new \InvalidArgumentException('CSV-Zeile ' . $row['line'] . ' (' . $key . '): Bereich „' . trim($values['areaKey'] ?? '') . '“ ist unbekannt. Gültig sind: ' . implode(', ', $areaKeys) . '.');
				try {
					$this->saveGroup([
						'key' => $key, 'name' => trim($values['name'] ?? '') !== '' ? $values['name'] : ($existing['name'] ?? ''), 'areaKey' => $area,
						'sortOrder' => trim($values['sortOrder'] ?? '') !== '' ? $values['sortOrder'] : ($existing['sortOrder'] ?? 0),
						'selectionRule' => $pick($values['selectionRule'] ?? '', CatalogRules::SELECTION_RULES, (string)($existing['selectionRule'] ?? 'MANY')),
						'funeralScope' => $pick($values['funeralScope'] ?? '', CatalogRules::FUNERAL_SCOPES, (string)($existing['funeralScope'] ?? 'ALL')),
						'active' => CsvTools::flag($values['active'] ?? '', (bool)($existing['active'] ?? true)),
					], $existing === null);
				} catch (\InvalidArgumentException $error) {
					throw new \InvalidArgumentException('CSV-Zeile ' . $row['line'] . ' (' . $key . '): ' . $error->getMessage());
				}
				$existing === null ? $created++ : $updated++;
			}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		return ['created' => $created, 'updated' => $updated];
	}

	public function saveArea(array $data): array {
		$key = strtoupper(trim((string)($data['key'] ?? '')));
		$name = trim((string)($data['name'] ?? ''));
		if (!preg_match('/^[0-9A-Z]{1,10}$/', $key) || $name === '' || mb_strlen($name) > 120) throw new \InvalidArgumentException('Bitte Schlüssel (bis 10 Zeichen) und Bezeichnung des Bereichs angeben.');
		$sort = max(0, min(99999, (int)($data['sortOrder'] ?? 0)));
		$query = $this->db->getQueryBuilder();
		$exists = $query->select('id')->from('bestatter_article_areas')->where($query->expr()->eq('area_key', $query->createNamedParameter($key)))->executeQuery()->fetchOne();
		if ($exists === false) $this->insertArea($key, $name, $sort);
		else {
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_article_areas')->set('name', $update->createNamedParameter($name))->set('sort_order', $update->createNamedParameter($sort))
				->set('updated_at', $update->createNamedParameter(date('c')))->where($update->expr()->eq('area_key', $update->createNamedParameter($key)))->executeStatement();
		}
		foreach ($this->areas() as $area) if ($area['key'] === $key) return $area;
		throw new \RuntimeException('Der Bereich konnte nicht gelesen werden.');
	}

	/** Nächste freie Artikelnummer im Nummernkreis der Warengruppe (Schlüssel + zweistellige Folge in Zehnerschritten). */
	public function suggestArticleNumber(string $groupKey): string {
		if (!preg_match('/^[0-9]{3}$/', $groupKey)) return '';
		$query = $this->db->getQueryBuilder();
		$numbers = $query->select('article_number')->from('bestatter_articles')->where($query->expr()->like('article_number', $query->createNamedParameter($groupKey . '%')))->executeQuery()->fetchFirstColumn();
		$max = 0;
		$used = [];
		foreach ($numbers as $number) if (preg_match('/^' . $groupKey . '(\d{2})$/', (string)$number, $match)) { $max = max($max, (int)$match[1]); $used[(int)$match[1]] = true; }
		$next = (int)(floor($max / 10) * 10) + 10;
		if ($next <= 99) return $groupKey . sprintf('%02d', $next);
		// Zehnerschritte ausgeschöpft: erste freie Nummer der Warengruppe (z. B. 21015), damit auch große Sortimente Platz haben.
		for ($candidate = 10; $candidate <= 99; $candidate++) if (!isset($used[$candidate])) return $groupKey . sprintf('%02d', $candidate);
		for ($candidate = 1; $candidate <= 9; $candidate++) if (!isset($used[$candidate])) return $groupKey . sprintf('%02d', $candidate);
		return '';
	}

	private function mapGroup(array $row, array $areas): array {
		$areaKey = (string)($row['area_key'] ?? '');
		$rule = (string)($row['selection_rule'] ?? 'MANY');
		return [
			'key' => (string)$row['group_key'], 'name' => (string)$row['group_name'], 'areaKey' => $areaKey,
			'areaName' => (string)($areas[$areaKey]['name'] ?? ''), 'areaSortOrder' => (int)($areas[$areaKey]['sortOrder'] ?? 9999),
			'sortOrder' => (int)($row['sort_order'] ?? 0), 'selectionRule' => $rule, 'selectionRuleLabel' => CatalogRules::SELECTION_RULES[$rule] ?? $rule,
			'funeralScope' => (string)($row['funeral_scope'] ?? 'ALL'), 'active' => $row['active'] === null ? true : (bool)$row['active'],
		];
	}

	private function insertArea(string $key, string $name, int $sort): void {
		$now = date('c');
		$insert = $this->db->getQueryBuilder();
		$insert->insert('bestatter_article_areas')->values(['area_key' => $insert->createNamedParameter($key), 'name' => $insert->createNamedParameter($name), 'sort_order' => $insert->createNamedParameter($sort), 'created_at' => $insert->createNamedParameter($now), 'updated_at' => $insert->createNamedParameter($now)])->executeStatement();
	}

	private function insertGroup(array $group): void {
		$now = date('c');
		$rule = (string)($group['selectionRule'] ?? 'MANY');
		$insert = $this->db->getQueryBuilder();
		$insert->insert('bestatter_article_groups')->values([
			'group_key' => $insert->createNamedParameter((string)$group['key']), 'group_name' => $insert->createNamedParameter((string)$group['name']),
			'area_key' => $insert->createNamedParameter((string)($group['area'] ?? substr((string)$group['key'], 0, 1))),
			'sort_order' => $insert->createNamedParameter((int)($group['sortOrder'] ?? 0)), 'selection_rule' => $insert->createNamedParameter($rule),
			'funeral_scope' => $insert->createNamedParameter((string)($group['funeralScope'] ?? 'ALL')),
			'active' => $insert->createNamedParameter((bool)($group['active'] ?? true), IQueryBuilder::PARAM_BOOL),
			'exclusive_selection' => $insert->createNamedParameter($rule !== 'MANY', IQueryBuilder::PARAM_BOOL),
			'created_at' => $insert->createNamedParameter($now), 'updated_at' => $insert->createNamedParameter($now),
		])->executeStatement();
	}

	private function seed(): array {
		if (!is_file(self::SEED_FILE)) return [];
		$data = json_decode((string)file_get_contents(self::SEED_FILE), true);
		return is_array($data) ? $data : [];
	}
}
