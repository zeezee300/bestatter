<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\IDBConnection;

class CustomizingService {
	private const RESOURCE = __DIR__ . '/../../resources/initial-customizing.json';
	private const FIXED_VALUE_LISTS = [
		'POSITION_TYPE' => ['EL', 'FK', 'DP'],
		'QUANTITY_UNIT' => ['STK', 'PAUSCHAL', 'STD', 'KM', 'TAG', 'KG', 'L', 'SATZ', 'PERS'],
		'BURIAL_VARIANT' => [],
	];
	/** 0.92.0: Werteliste „Ressourcentypen“ – Standardtypen sind nicht löschbar, eigene Typen nur, solange unbenutzt. */
	private const RESOURCE_TYPE_LIST = SchedulingRules::RESOURCE_TYPE_LIST;
	/** 0.92.0: Format der Wertelisten im Einrichtungspaket. */
	public const EXCHANGE_FORMAT = 'bestatter-value-lists';
	/** Nicht im Paket: Bestattungsarten und -varianten werden im Variantenbaum mit ihren Leistungsregeln gepflegt. */
	public const EXCHANGE_EXCLUDED_LISTS = ['FUNERAL_TYPE', 'BURIAL_VARIANT'];
	public function __construct(
		private IDBConnection $db,
	) {
	}
	public function ensureSeedData(): void {
		if (!is_file(self::RESOURCE)) {
			return;
		}
		$payload = json_decode((string)file_get_contents(self::RESOURCE), true, 512, JSON_THROW_ON_ERROR);
		foreach ($payload['lists'] as $position => $list) {
			$lookup = $this->db->getQueryBuilder();
			$existing = (int)$lookup->select('id')->from('bestatter_choice_lists')->where($lookup->expr()->eq('list_key', $lookup->createNamedParameter($list['key'])))->executeQuery()->fetchOne();
			if ($existing > 0) {
				continue;
			}
			$this->db->beginTransaction();
			try {
				$insert = $this->db->getQueryBuilder();
				$insert->insert('bestatter_choice_lists')->values(['list_key' => $insert->createNamedParameter($list['key']),'name' => $insert->createNamedParameter($list['name']),'sort_order' => $insert->createNamedParameter($position + 1)])->executeStatement();
				$listId = (int)$this->db->lastInsertId('bestatter_choice_lists');
				$parentIds = [];
				foreach ($list['items'] as $itemPosition => $item) {
					$value = is_array($item) && isset($item['value']) ? (string)$item['value'] : (string)$item[0];
					$label = is_array($item) && isset($item['label']) ? (string)$item['label'] : (string)$item[1];
					$parent = is_array($item) ? (string)($item['parent'] ?? '') : '';
					if ($parent !== '' && !isset($parentIds[$parent])) {
						throw new \RuntimeException('Die Bestattungsvariante verweist auf einen unbekannten Elternwert.');
					}
					$entry = $this->db->getQueryBuilder();
					$entry->insert('bestatter_choice_items')->values([
						'list_id' => $entry->createNamedParameter($listId),
						'value' => $entry->createNamedParameter($value),
						'label' => $entry->createNamedParameter($label),
						'sort_order' => $entry->createNamedParameter($itemPosition + 1),
						'parent_item_id' => $entry->createNamedParameter($parent !== '' ? $parentIds[$parent] : null),
						'metadata' => $entry->createNamedParameter(isset($item['metadata']) ? json_encode($item['metadata'], JSON_THROW_ON_ERROR) : null),
					])->executeStatement();
					$parentIds[$value] = (int)$this->db->lastInsertId('bestatter_choice_items');
				}
				$this->db->commit();
			} catch (UniqueConstraintViolationException) {
				$this->db->rollBack();
			} catch (\Throwable $error) {
				$this->db->rollBack();
				throw $error;
			}
		}
	}
	public function caseFieldSchema(): array {
		$path = __DIR__ . '/../../resources/case-field-schema.json';
		return is_file($path) ? json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
	}
	public function overview(): array {
		$q = $this->db->getQueryBuilder();
		$rows = $q->select('l.id', 'l.list_key', 'l.name', 'l.sort_order', $q->func()->count('i.id', 'item_count'))->from('bestatter_choice_lists', 'l')->leftJoin('l', 'bestatter_choice_items', 'i', $q->expr()->eq('l.id', 'i.list_id'))->groupBy('l.id', 'l.list_key', 'l.name', 'l.sort_order')->orderBy('l.sort_order', 'ASC')->executeQuery()->fetchAllAssociative();
		$rows = array_values(array_filter($rows, static fn ($row) => $row['list_key'] !== 'FUNERAL_TYPE'));
		return ['lists' => array_map(fn ($row) => ['id' => (int)$row['id'],'key' => $row['list_key'],'name' => $row['name'],'itemCount' => (int)$row['item_count'],'technicalValuesLocked' => isset(self::FIXED_VALUE_LISTS[(string)$row['list_key']]),'protectedValues' => $row['list_key'] === self::RESOURCE_TYPE_LIST ? SchedulingRules::SYSTEM_RESOURCE_TYPES : [],'items' => $this->itemsForList((int)$row['id']),'tree' => $row['list_key'] === 'BURIAL_VARIANT'?$this->treeForKey('BURIAL_VARIANT'):[]], $rows)];
	}

	public function valuesForKey(string $key): array {
		$this->ensureSeedData();
		$lookup = $this->db->getQueryBuilder();
		$listId = (int)$lookup->select('id')->from('bestatter_choice_lists')->where($lookup->expr()->eq('list_key', $lookup->createNamedParameter(trim($key))))->executeQuery()->fetchOne();
		return $listId > 0 ? $this->itemsForList($listId) : [];
	}

	/** Existing flat lists stay flat; consumers of variants opt into this tree. */
	public function treeForKey(string $key): array {
		$items = $this->valuesForKey($key);
		$children = [];
		foreach ($items as $item) {
			$children[(int)($item['parentItemId'] ?? 0)][] = $item;
		}
		$walk = static function (int $parent) use (&$walk, $children): array {
			return array_map(static function (array $item) use (&$walk): array {
				$item['children'] = $walk($item['id']);
				return $item;
			}, $children[$parent] ?? []);
		};
		return $walk(0);
	}

	/** @param array{plural?:string,icon?:string} $extra 0.92.0: Mehrzahl und Symbol eines Ressourcentyps */
	public function addItem(string $key, string $value, string $label, string $parentValue = '', string $funeralScope = '', array $extra = []): array {
		if ($key === 'FUNERAL_TYPE') {
			throw new \InvalidArgumentException('Bestattungsarten werden im Variantenbaum gepflegt.');
		}
		if (isset(self::FIXED_VALUE_LISTS[$key]) && $key !== 'BURIAL_VARIANT') {
			throw new \InvalidArgumentException('Die technischen Werte dieser Systemwerteliste sind fest vorgegeben; Bezeichnungen und Reihenfolge können gepflegt werden.');
		}
		[$value,$label] = $this->validateItem($value, $label);
		if ($key === 'BURIAL_VARIANT' && !preg_match('/^[A-Z][A-Z0-9_]*$/', $value)) {
			throw new \InvalidArgumentException('Der Variantenschlüssel muss mit einem Großbuchstaben beginnen und darf nur Großbuchstaben, Ziffern und Unterstriche enthalten.');
		}
		if ($key === self::RESOURCE_TYPE_LIST) {
			$value = $this->resourceTypeValue($value);
		}
		$list = $this->listId($key);
		$this->assertUniqueValue($list, $value);
		$variant = $key === 'BURIAL_VARIANT' ? $this->variantFields($list, $parentValue, $funeralScope) : ['parent' => null, 'metadata' => $key === self::RESOURCE_TYPE_LIST ? $this->resourceTypeMetadata($value, $extra) : null];
		$countQuery = $this->db->getQueryBuilder();
		$count = (int)$countQuery->select($countQuery->func()->count('*', 'count'))->from('bestatter_choice_items')->where($countQuery->expr()->eq('list_id', $countQuery->createNamedParameter($list)))->executeQuery()->fetchOne();
		$insert = $this->db->getQueryBuilder();
		$insert->insert('bestatter_choice_items')->values(['list_id' => $insert->createNamedParameter($list),'value' => $insert->createNamedParameter($value),'label' => $insert->createNamedParameter($label),'sort_order' => $insert->createNamedParameter($count + 1),'parent_item_id' => $insert->createNamedParameter($variant['parent']),'metadata' => $insert->createNamedParameter($variant['metadata'])])->executeStatement();
		return $this->itemById((int)$this->db->lastInsertId('bestatter_choice_items'));
	}

	/** @param array{plural?:string,icon?:string} $extra 0.92.0: Mehrzahl und Symbol eines Ressourcentyps */
	public function updateItem(int $id, string $value, string $label, string $parentValue = '', string $funeralScope = '', array $extra = []): array {
		[$value,$label] = $this->validateItem($value, $label);
		$lookup = $this->db->getQueryBuilder();
		$row = $lookup->select('list_id', 'value', 'sort_order')->from('bestatter_choice_items')->where($lookup->expr()->eq('id', $lookup->createNamedParameter($id)))->executeQuery()->fetchAssociative();
		if (!$row) {
			throw new \InvalidArgumentException('Der Listenwert wurde nicht gefunden.');
		}
		$listKey = $this->listKey((int)$row['list_id']);
		if ($listKey === 'FUNERAL_TYPE') {
			throw new \InvalidArgumentException('Bestattungsarten werden im Variantenbaum gepflegt.');
		}
		if (isset(self::FIXED_VALUE_LISTS[$listKey]) && $value !== (string)$row['value']) {
			throw new \InvalidArgumentException('Der technische Schlüssel dieser Systemwerteliste darf nicht geändert werden.');
		}
		if ($listKey === self::RESOURCE_TYPE_LIST && $value !== (string)$row['value']) {
			throw new \InvalidArgumentException('Der technische Wert eines Ressourcentyps kann nach dem Anlegen nicht mehr geändert werden, weil Ressourcen und Terminarten darauf verweisen.');
		}
		$this->assertUniqueValue((int)$row['list_id'], $value, $id);
		$variant = $listKey === 'BURIAL_VARIANT' ? $this->variantFields((int)$row['list_id'], $parentValue, $funeralScope, $id) : null;
		if ($listKey === self::RESOURCE_TYPE_LIST) {
			$variant = ['parent' => null, 'metadata' => $this->resourceTypeMetadata($value, $extra)];
		}
		$q = $this->db->getQueryBuilder();
		$q->update('bestatter_choice_items')->set('value', $q->createNamedParameter($value))->set('label', $q->createNamedParameter($label));
		if ($variant !== null) {
			$q->set('parent_item_id', $q->createNamedParameter($variant['parent']))->set('metadata', $q->createNamedParameter($variant['metadata']));
		}
		$q->where($q->expr()->eq('id', $q->createNamedParameter($id)))->executeStatement();
		return $this->itemById($id);
	}

	public function deleteItem(int $id): void {
		$lookup = $this->db->getQueryBuilder();
		$row = $lookup->select('list_id', 'value')->from('bestatter_choice_items')->where($lookup->expr()->eq('id', $lookup->createNamedParameter($id)))->executeQuery()->fetchAssociative();
		$listId = (int)($row['list_id'] ?? 0);
		$listKey = $listId > 0 ? $this->listKey($listId) : '';
		if ($listKey === 'FUNERAL_TYPE') {
			throw new \InvalidArgumentException('Bestattungsarten werden im Variantenbaum gepflegt.');
		}
		if ($listKey !== 'BURIAL_VARIANT' && isset(self::FIXED_VALUE_LISTS[$listKey])) {
			throw new \InvalidArgumentException('Ein verbindlicher Systemwert darf nicht gelöscht werden.');
		}
		if ($listKey === self::RESOURCE_TYPE_LIST) {
			$this->assertResourceTypeDeletable((string)($row['value'] ?? ''));
		}
		if ($listKey === 'BURIAL_VARIANT') {
			$children = $this->db->getQueryBuilder();
			if ($children->select('id')->from('bestatter_choice_items')->where($children->expr()->eq('parent_item_id', $children->createNamedParameter($id)))->executeQuery()->fetchOne() !== false) {
				throw new \InvalidArgumentException('Untervarianten zuerst entfernen oder umhängen.');
			}
			$rules = $this->db->getQueryBuilder();
			if ($rules->select('id')->from('bestatter_burial_variant_rules')->where($rules->expr()->eq('variant_code', $rules->createNamedParameter($row['value'])))->executeQuery()->fetchOne() !== false) {
				throw new \InvalidArgumentException('Die Variante wird noch von Leistungsregeln verwendet.');
			}
			$cases = $this->db->getQueryBuilder();
			if ($cases->select('id')->from('bestatter_cases')->where($cases->expr()->eq('burial_variant_code', $cases->createNamedParameter($row['value'])))->executeQuery()->fetchOne() !== false) {
				throw new \InvalidArgumentException('Die Variante wird noch von Fällen verwendet.');
			}
		}
		$q = $this->db->getQueryBuilder();
		$affected = $q->delete('bestatter_choice_items')->where($q->expr()->eq('id', $q->createNamedParameter($id)))->executeStatement();
		if ($affected === 0) {
			throw new \InvalidArgumentException('Der Listenwert wurde nicht gefunden.');
		}
	}

	/** @param list<int> $itemIds */
	public function reorderItems(string $key, array $itemIds): array {
		if ($key === 'FUNERAL_TYPE') {
			throw new \InvalidArgumentException('Bestattungsarten werden im Variantenbaum gepflegt.');
		}
		$listId = $this->listId($key);
		$ids = array_values(array_map('intval', $itemIds));
		if ($ids === [] || count($ids) !== count(array_unique($ids))) {
			throw new \InvalidArgumentException('Die neue Reihenfolge ist unvollständig oder enthält doppelte Einträge.');
		}
		$query = $this->db->getQueryBuilder();
		$stored = array_map('intval', $query->select('id')->from('bestatter_choice_items')->where($query->expr()->eq('list_id', $query->createNamedParameter($listId)))->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC')->executeQuery()->fetchFirstColumn());
		$expected = $stored;
		$provided = $ids;
		sort($expected);
		sort($provided);
		if ($expected !== $provided) {
			throw new \InvalidArgumentException('Die Reihenfolge muss genau alle gespeicherten Einträge dieser Werteliste enthalten. Bitte laden Sie die Liste neu.');
		}
		$this->db->beginTransaction();
		try {
			foreach ($ids as $position => $id) {
				$update = $this->db->getQueryBuilder();
				$update->update('bestatter_choice_items')->set('sort_order', $update->createNamedParameter(($position + 1) * 10))->where($update->expr()->eq('id', $update->createNamedParameter($id)))->andWhere($update->expr()->eq('list_id', $update->createNamedParameter($listId)))->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		return $this->itemsForList($listId);
	}

	private function validateItem(string $value, string $label): array {
		$value = trim($value);
		$label = trim($label);
		if ($value === '' || $label === '') {
			throw new \InvalidArgumentException('Technischer Wert und Bezeichnung sind Pflichtfelder.');
		}
		if (mb_strlen($value) > 120 || mb_strlen($label) > 180) {
			throw new \InvalidArgumentException('Der technische Wert oder die Bezeichnung ist zu lang.');
		}
		return [$value,$label];
	}

	private function variantFields(int $listId, string $parentValue, string $funeralScope, ?int $itemId = null): array {
		$parentValue = trim($parentValue);
		$funeralScope = strtoupper(trim($funeralScope));
		if (!in_array($funeralScope, ['', 'BURIAL', 'CREMATION'], true)) {
			throw new \InvalidArgumentException('Die Bestattungszuordnung ist ungültig.');
		}
		$parentId = null;
		if ($parentValue !== '') {
			$parent = null;
			foreach ($this->itemsForList($listId) as $candidate) {
				if ($candidate['value'] === $parentValue) {
					$parent = $candidate;
					break;
				}
			}
			if ($parent === null) {
				throw new \InvalidArgumentException('Die übergeordnete Variante wurde nicht gefunden.');
			}
			$parentId = $parent['id'];
			$byId = array_column($this->itemsForList($listId), null, 'id');
			for ($ancestor = $parentId; $ancestor !== null; $ancestor = $byId[$ancestor]['parentItemId'] ?? null) {
				if ($ancestor === $itemId) {
					throw new \InvalidArgumentException('Eine Variante darf nicht sich selbst oder eine Untervariante als übergeordneten Eintrag haben.');
				}
			}
			$parentScope = (string)($parent['metadata']['funeralScope'] ?? '');
			if ($parentScope !== '' && $funeralScope !== $parentScope) {
				throw new \InvalidArgumentException('Die Bestattungszuordnung muss zur übergeordneten Variante passen.');
			}
		}
		$metadata = $funeralScope === '' ? ['classificationPending' => true] : ['funeralScope' => $funeralScope];
		return ['parent' => $parentId, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)];
	}

	/** 0.92.0: Technischer Wert eines Ressourcentyps (wie die Standardtypen: Großbuchstaben, Ziffern, Unterstrich, max. 30 Zeichen). */
	private function resourceTypeValue(string $value): string {
		$value = strtoupper(trim($value));
		if (!preg_match('/^[A-Z][A-Z0-9_]{0,29}$/', $value)) {
			throw new \InvalidArgumentException('Der technische Wert eines Ressourcentyps beginnt mit einem Buchstaben und enthält nur Großbuchstaben, Ziffern und Unterstriche (höchstens 30 Zeichen), z. B. KUEHLZELLE.');
		}
		if ($value === 'STAFF') {
			throw new \InvalidArgumentException('Mitarbeitende sind kein Ressourcentyp; sie werden im Termin über „Mitarbeitende“ gewählt.');
		}
		return $value;
	}

	/** @param array{plural?:string,icon?:string} $extra */
	private function resourceTypeMetadata(string $value, array $extra): string {
		$plural = trim((string)($extra['plural'] ?? ''));
		if (mb_strlen($plural) > 60) {
			throw new \InvalidArgumentException('Die Mehrzahl darf höchstens 60 Zeichen lang sein.');
		}
		$icon = trim((string)($extra['icon'] ?? ''));
		if ($icon === '') {
			$icon = SchedulingRules::defaultIcon($value);
		}
		if (!in_array($icon, SchedulingRules::RESOURCE_ICONS, true)) {
			throw new \InvalidArgumentException('Bitte ein Symbol aus der Auswahl wählen.');
		}
		return json_encode(array_filter(['plural' => $plural, 'icon' => $icon], static fn (string $entry): bool => $entry !== ''), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
	}

	private function assertResourceTypeDeletable(string $value): void {
		if (in_array($value, SchedulingRules::SYSTEM_RESOURCE_TYPES, true)) {
			throw new \InvalidArgumentException('Die Standard-Ressourcentypen Kapelle, Raum, Fahrzeug und Ausstattung können nicht gelöscht werden. Bezeichnung, Mehrzahl und Symbol lassen sich ändern.');
		}
		$q = $this->db->getQueryBuilder();
		$names = $q->select('name')->from('bestatter_resources')->where($q->expr()->eq('resource_type', $q->createNamedParameter($value)))->orderBy('name', 'ASC')->executeQuery()->fetchFirstColumn();
		if ($names !== []) {
			throw new \InvalidArgumentException('Der Ressourcentyp wird noch verwendet von: ' . implode(', ', array_map('strval', $names)) . '. Bitte diese Ressourcen zuerst einem anderen Typ zuordnen.');
		}
	}

	private function itemById(int $id): array {
		$q = $this->db->getQueryBuilder();
		$row = $q->select('list_id')->from('bestatter_choice_items')->where($q->expr()->eq('id', $q->createNamedParameter($id)))->executeQuery()->fetchAssociative();
		foreach ($this->itemsForList((int)$row['list_id']) as $item) {
			if ($item['id'] === $id) {
				return $item;
			}
		}
		throw new \RuntimeException('Der Listenwert konnte nicht gelesen werden.');
	}

	private function listId(string $key): int {
		$lookup = $this->db->getQueryBuilder();
		$list = (int)$lookup->select('id')->from('bestatter_choice_lists')->where($lookup->expr()->eq('list_key', $lookup->createNamedParameter(trim($key))))->executeQuery()->fetchOne();
		if (!$list) {
			throw new \InvalidArgumentException('Werteliste wurde nicht gefunden.');
		}
		return $list;
	}

	private function listKey(int $id): string {
		$lookup = $this->db->getQueryBuilder();
		return (string)$lookup->select('list_key')->from('bestatter_choice_lists')->where($lookup->expr()->eq('id', $lookup->createNamedParameter($id)))->executeQuery()->fetchOne();
	}

	private function assertUniqueValue(int $listId, string $value, ?int $exceptId = null): void {
		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('bestatter_choice_items')->where($q->expr()->eq('list_id', $q->createNamedParameter($listId)))->andWhere($q->expr()->eq('value', $q->createNamedParameter($value)));
		if ($exceptId !== null) {
			$q->andWhere($q->expr()->neq('id', $q->createNamedParameter($exceptId)));
		}
		if ($q->executeQuery()->fetchOne() !== false) {
			throw new \InvalidArgumentException('Dieser technische Wert ist in der Werteliste bereits vorhanden.');
		}
	}

	private function itemsForList(int $listId): array {
		$q = $this->db->getQueryBuilder();
		$rows = $q->select('id', 'value', 'label', 'sort_order', 'parent_item_id', 'metadata')->from('bestatter_choice_items')->where($q->expr()->eq('list_id', $q->createNamedParameter($listId)))->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		return array_map(static fn ($row) => ['id' => (int)$row['id'],'value' => $row['value'],'label' => $row['label'],'sortOrder' => (int)$row['sort_order'],'parentItemId' => $row['parent_item_id'] === null ? null : (int)$row['parent_item_id'],'metadata' => json_decode((string)($row['metadata'] ?? ''), true) ?: []], $rows);
	}

	// ---------------------------------------------------------------------------------------------- Einrichtungspaket

	/**
	 * 0.92.0: Wertelisten für das Einrichtungspaket – je Liste die Werte mit Bezeichnung in der gepflegten Reihenfolge,
	 * bei den Ressourcentypen mit Mehrzahl und Symbol. Ohne Bestattungsarten/-varianten (Variantenbaum).
	 */
	public function exportExchange(): string {
		$this->ensureSeedData();
		$lists = [];
		foreach ($this->installedLists() as $list) {
			if (in_array($list['key'], self::EXCHANGE_EXCLUDED_LISTS, true)) {
				continue;
			}
			$lists[] = ['key' => $list['key'], 'name' => $list['name'], 'items' => array_map(static function (array $item) use ($list): array {
				$entry = ['value' => $item['value'], 'label' => $item['label']];
				if ($list['key'] === self::RESOURCE_TYPE_LIST && $item['metadata'] !== []) {
					$entry['metadata'] = array_intersect_key($item['metadata'], ['plural' => true, 'icon' => true]);
				}
				return $entry;
			}, $list['items'])];
		}
		return json_encode(['format' => self::EXCHANGE_FORMAT, 'formatVersion' => 1, 'lists' => $lists], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	/** @return list<array{key:string,name:string,items:list<array{value:string,label:string,metadata:array}>}> */
	public static function parseExchange(string $content): array {
		$data = json_decode($content, true);
		if (!is_array($data) || ($data['format'] ?? '') !== self::EXCHANGE_FORMAT || !is_array($data['lists'] ?? null)) {
			throw new \InvalidArgumentException('Keine Wertelisten im erwarteten Format.');
		}
		if ((int)($data['formatVersion'] ?? 1) > 1) {
			throw new \InvalidArgumentException('Die Wertelisten stammen aus einer neueren Version der App.');
		}
		$lists = [];
		foreach ($data['lists'] as $list) {
			$key = strtoupper(trim((string)($list['key'] ?? '')));
			if (!preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $key)) {
				throw new \InvalidArgumentException('Werteliste mit ungültigem Schlüssel „' . $key . '“.');
			}
			if (in_array($key, self::EXCHANGE_EXCLUDED_LISTS, true)) {
				continue;
			}
			$items = [];
			foreach ((array)($list['items'] ?? []) as $item) {
				$value = trim((string)(is_array($item) ? ($item['value'] ?? '') : $item));
				$label = trim((string)(is_array($item) ? ($item['label'] ?? $value) : $item));
				if ($value === '' || $label === '' || mb_strlen($value) > 120 || mb_strlen($label) > 180) {
					throw new \InvalidArgumentException('Werteliste ' . $key . ': Wert oder Bezeichnung fehlt oder ist zu lang.');
				}
				if ($key === self::RESOURCE_TYPE_LIST) {
					$value = strtoupper($value);
				}
				$items[$value] = ['value' => $value, 'label' => $label, 'metadata' => is_array($item) && is_array($item['metadata'] ?? null) ? $item['metadata'] : []];
			}
			$lists[] = ['key' => $key, 'name' => trim((string)($list['name'] ?? $key)), 'items' => array_values($items)];
		}
		return $lists;
	}

	/**
	 * Vorschau: neue Werte, geänderte Bezeichnungen (bzw. Mehrzahl/Symbol) und Listen, die diese Installation nicht kennt.
	 *
	 * @return array{lists:int,new:int,changed:int,same:int,unknownLists:list<string>,fixed:list<string>}
	 */
	public function exchangePreview(array $lists): array {
		$this->ensureSeedData();
		$installed = array_column($this->installedLists(), null, 'key');
		$result = ['lists' => count($lists), 'new' => 0, 'changed' => 0, 'same' => 0, 'unknownLists' => [], 'fixed' => []];
		foreach ($lists as $list) {
			if (!isset($installed[$list['key']])) {
				$result['unknownLists'][] = $list['name'] . ' (' . $list['key'] . ')';
				continue;
			}
			$items = array_column($installed[$list['key']]['items'], null, 'value');
			foreach ($list['items'] as $item) {
				$existing = $items[$item['value']] ?? null;
				if ($existing === null) {
					if (isset(self::FIXED_VALUE_LISTS[$list['key']])) {
						$result['fixed'][] = $list['name'] . ': ' . $item['value'];
						continue;
					}
					$result['new']++;
				} elseif (self::sameExchangeItem($list['key'], $existing, $item)) {
					$result['same']++;
				} else {
					$result['changed']++;
				}
			}
		}
		return $result;
	}

	/**
	 * Einspielen: fehlende Werte werden angehängt, Bezeichnungen (bei Ressourcentypen auch Mehrzahl und Symbol) vorhandener
	 * Werte aktualisiert. Nichts wird gelöscht oder umsortiert; Listen, die diese Version nicht kennt, und neue Werte in festen
	 * Systemlisten werden übersprungen.
	 *
	 * @return array{created:int,updated:int,skipped:list<string>}
	 */
	public function importExchange(string $content): array {
		$lists = self::parseExchange($content);
		$this->ensureSeedData();
		$installed = array_column($this->installedLists(), null, 'key');
		$result = ['created' => 0, 'updated' => 0, 'skipped' => []];
		$this->db->beginTransaction();
		try {
			foreach ($lists as $list) {
				if (!isset($installed[$list['key']])) {
					$result['skipped'][] = 'Werteliste ' . $list['key'] . ' gibt es in dieser Version nicht';
					continue;
				}
				$items = array_column($installed[$list['key']]['items'], null, 'value');
				foreach ($list['items'] as $item) {
					$extra = $list['key'] === self::RESOURCE_TYPE_LIST ? ['plural' => (string)($item['metadata']['plural'] ?? ''), 'icon' => (string)($item['metadata']['icon'] ?? '')] : [];
					$existing = $items[$item['value']] ?? null;
					if ($existing === null) {
						if (isset(self::FIXED_VALUE_LISTS[$list['key']])) {
							$result['skipped'][] = $list['name'] . ': fester Systemwert ' . $item['value'] . ' nicht vorgesehen';
							continue;
						}
						$this->addItem($list['key'], $item['value'], $item['label'], '', '', $extra);
						$result['created']++;
					} elseif (!self::sameExchangeItem($list['key'], $existing, $item)) {
						if ($list['key'] === self::RESOURCE_TYPE_LIST) {
							$extra = ['plural' => $extra['plural'] !== '' ? $extra['plural'] : (string)($existing['metadata']['plural'] ?? ''), 'icon' => $extra['icon'] !== '' ? $extra['icon'] : (string)($existing['metadata']['icon'] ?? '')];
						}
						$this->updateItem((int)$existing['id'], (string)$existing['value'], $item['label'], '', '', $extra);
						$result['updated']++;
					}
				}
			}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		return $result;
	}

	private static function sameExchangeItem(string $listKey, array $existing, array $incoming): bool {
		if ((string)$existing['label'] !== $incoming['label']) {
			return false;
		}
		if ($listKey !== self::RESOURCE_TYPE_LIST) {
			return true;
		}
		foreach (['plural', 'icon'] as $field) {
			$value = (string)($incoming['metadata'][$field] ?? '');
			if ($value !== '' && $value !== (string)($existing['metadata'][$field] ?? '')) {
				return false;
			}
		}
		return true;
	}

	/** Alle Wertelisten mit ihren Werten (ohne Zählabfrage, für Export und Abgleich). @return list<array{key:string,name:string,items:list<array>}> */
	private function installedLists(): array {
		$q = $this->db->getQueryBuilder();
		$rows = $q->select('id', 'list_key', 'name')->from('bestatter_choice_lists')->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		return array_map(fn (array $row): array => ['key' => (string)$row['list_key'], 'name' => (string)$row['name'], 'items' => $this->itemsForList((int)$row['id'])], $rows);
	}
}
