<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Stücklisten der Paketartikel (SAP: Vertriebsstückliste STKO/STPO). Positionen sind fest,
 * optional (im Fall abwählbar, bei Festpreis mit Minderpreis) oder Teil einer
 * Alternativgruppe (genau eine Position wird geliefert; eine davon ist Standard).
 */
class BomService {
	public function __construct(private IDBConnection $db) {}

	/** @return array<int, array> Stückliste je Paketartikel-ID */
	public function all(): array {
		$query = $this->db->getQueryBuilder();
		$boms = $query->select('*')->from('bestatter_boms')->executeQuery()->fetchAllAssociative();
		if ($boms === []) return [];
		$query = $this->db->getQueryBuilder();
		$items = $query->select('b.*', 'a.article_number', 'a.short_name', 'a.article_group', 'a.article_type', 'a.tax_class', 'a.unit', 'a.active')
			->from('bestatter_bom_items', 'b')->innerJoin('b', 'bestatter_articles', 'a', $query->expr()->eq('a.id', 'b.article_id'))
			->orderBy('b.item_no', 'ASC')->addOrderBy('b.id', 'ASC')->executeQuery()->fetchAllAssociative();
		$byBom = [];
		foreach ($items as $item) $byBom[(int)$item['bom_id']][] = $this->mapItem($item);
		$result = [];
		foreach ($boms as $bom) {
			$result[(int)$bom['package_article_id']] = [
				'id' => (int)$bom['id'], 'packageArticleId' => (int)$bom['package_article_id'],
				'pricing' => (string)$bom['pricing'], 'printMode' => (string)$bom['print_mode'],
				'items' => $byBom[(int)$bom['id']] ?? [],
			];
		}
		return $result;
	}

	public function forPackage(int $articleId): ?array {
		return $this->all()[$articleId] ?? null;
	}

	/**
	 * @param array $data {pricing, printMode, items: [{itemNo, articleId, quantity, itemType, altGroup, isDefault, credit}]}
	 * @param array<int, array> $articles Katalog je ID (für die Prüfung)
	 */
	public function save(int $packageId, array $data, array $articles): void {
		$pricing = strtoupper(trim((string)($data['pricing'] ?? 'HEADER')));
		$printMode = strtoupper(trim((string)($data['printMode'] ?? 'ITEMS_NO_PRICES')));
		if (!isset(CatalogRules::BOM_PRICING[$pricing])) throw new \InvalidArgumentException('Die Preisfindung der Stückliste ist ungültig.');
		if (!isset(CatalogRules::PRINT_MODES[$printMode])) throw new \InvalidArgumentException('Die Druckart der Stückliste ist ungültig.');
		$items = [];
		foreach (array_values((array)($data['items'] ?? [])) as $index => $entry) {
			$articleId = (int)($entry['articleId'] ?? 0);
			if ($articleId <= 0) continue;
			$article = $articles[$articleId] ?? null;
			$label = 'Stücklistenposition ' . ($index + 1);
			if ($article === null) throw new \InvalidArgumentException($label . ': Der Artikel wurde nicht gefunden.');
			if ($articleId === $packageId || ($article['articleType'] ?? '') === 'PAKET') throw new \InvalidArgumentException($label . ': Pakete können keine weiteren Pakete enthalten.');
			if (($article['articleType'] ?? '') === 'AUSLAGE' || ($article['taxClass'] ?? '') === 'A') throw new \InvalidArgumentException($label . ': Auslagen und durchlaufende Posten gehören nicht in ein Paket, sondern werden separat erfasst.');
			$quantity = (int)round((float)str_replace(',', '.', (string)($entry['quantity'] ?? 1)) * 1000);
			if ($quantity <= 0) throw new \InvalidArgumentException($label . ': Die Menge muss größer als null sein.');
			$type = strtoupper(trim((string)($entry['itemType'] ?? 'FIXED')));
			if (!isset(CatalogRules::BOM_ITEM_TYPES[$type])) throw new \InvalidArgumentException($label . ': Die Positionsart ist ungültig.');
			$group = $type === 'ALTERNATIVE' ? strtoupper(trim((string)($entry['altGroup'] ?? ''))) : '';
			if ($type === 'ALTERNATIVE' && !preg_match('/^[A-Z0-9_-]{1,40}$/', $group)) throw new \InvalidArgumentException($label . ': Für eine Alternative ist eine Alternativgruppe (z. B. URNE) erforderlich.');
			$credit = $type === 'OPTIONAL' ? (int)round((float)str_replace(',', '.', (string)($entry['credit'] ?? 0)) * 100) : 0;
			if (isset($entry['creditCents'])) $credit = $type === 'OPTIONAL' ? (int)$entry['creditCents'] : 0;
			if ($credit < 0) throw new \InvalidArgumentException($label . ': Der Minderpreis darf nicht negativ sein.');
			$items[] = [
				'itemNo' => max(1, (int)($entry['itemNo'] ?? (($index + 1) * 10))), 'articleId' => $articleId, 'quantityMilli' => $quantity,
				'itemType' => $type, 'altGroup' => $group !== '' ? $group : null,
				'isDefault' => $type === 'ALTERNATIVE' && filter_var($entry['isDefault'] ?? false, FILTER_VALIDATE_BOOLEAN), 'creditCents' => $credit,
			];
		}
		if ($items === []) throw new \InvalidArgumentException('Ein Paket benötigt mindestens eine Stücklistenposition.');
		$groups = [];
		foreach ($items as $item) if ($item['itemType'] === 'ALTERNATIVE') $groups[$item['altGroup']][] = $item;
		foreach ($groups as $group => $entries) {
			if (count($entries) < 2) throw new \InvalidArgumentException('Die Alternativgruppe ' . $group . ' benötigt mindestens zwei Artikel.');
			$defaults = count(array_filter($entries, static fn(array $item): bool => $item['isDefault']));
			if ($defaults !== 1) throw new \InvalidArgumentException('In der Alternativgruppe ' . $group . ' muss genau ein Artikel als Standard markiert sein.');
		}
		if ($pricing === 'HEADER' && !array_filter($items, static fn(array $item): bool => $item['itemType'] === 'FIXED' && $item['quantityMilli'] === 1000)) {
			throw new \InvalidArgumentException('Für einen Festpreis am Paket ist mindestens eine feste Position mit Menge 1 erforderlich (sie trägt Rundungsdifferenzen der Preisverteilung).');
		}
		usort($items, static fn(array $left, array $right): int => $left['itemNo'] <=> $right['itemNo']);
		$now = date('c');
		$existing = $this->forPackage($packageId);
		if ($existing === null) {
			$insert = $this->db->getQueryBuilder();
			$insert->insert('bestatter_boms')->values(['package_article_id' => $insert->createNamedParameter($packageId), 'pricing' => $insert->createNamedParameter($pricing), 'print_mode' => $insert->createNamedParameter($printMode), 'created_at' => $insert->createNamedParameter($now), 'updated_at' => $insert->createNamedParameter($now)])->executeStatement();
			$bomId = (int)$this->db->lastInsertId('bestatter_boms');
		} else {
			$bomId = (int)$existing['id'];
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_boms')->set('pricing', $update->createNamedParameter($pricing))->set('print_mode', $update->createNamedParameter($printMode))->set('updated_at', $update->createNamedParameter($now))
				->where($update->expr()->eq('id', $update->createNamedParameter($bomId)))->executeStatement();
		}
		// Positionen mit gleicher Nummer und gleichem Artikel behalten ihre ID, damit Fallpositionen
		// ihre Zuordnung zur Stücklistenposition nicht verlieren.
		$keep = [];
		foreach ($existing['items'] ?? [] as $old) $keep[$old['itemNo'] . ':' . $old['articleId']] = $old['id'];
		$used = [];
		foreach ($items as $item) {
			$oldId = $keep[$item['itemNo'] . ':' . $item['articleId']] ?? null;
			$values = ['item_no' => $item['itemNo'], 'article_id' => $item['articleId'], 'quantity_milli' => $item['quantityMilli'], 'item_type' => $item['itemType'], 'alt_group' => $item['altGroup'], 'credit_cents' => $item['creditCents']];
			if ($oldId !== null && !isset($used[$oldId])) {
				$update = $this->db->getQueryBuilder();
				$update->update('bestatter_bom_items')->where($update->expr()->eq('id', $update->createNamedParameter($oldId)));
				foreach ($values as $column => $value) $update->set($column, $update->createNamedParameter($value));
				$update->set('is_default', $update->createNamedParameter($item['isDefault'], IQueryBuilder::PARAM_BOOL))->executeStatement();
				$used[$oldId] = true;
				continue;
			}
			$insert = $this->db->getQueryBuilder();
			$insertValues = ['bom_id' => $insert->createNamedParameter($bomId), 'is_default' => $insert->createNamedParameter($item['isDefault'], IQueryBuilder::PARAM_BOOL)];
			foreach ($values as $column => $value) $insertValues[$column] = $insert->createNamedParameter($value);
			$insert->insert('bestatter_bom_items')->values($insertValues)->executeStatement();
		}
		foreach ($existing['items'] ?? [] as $old) {
			if (isset($used[$old['id']])) continue;
			$delete = $this->db->getQueryBuilder();
			$delete->delete('bestatter_bom_items')->where($delete->expr()->eq('id', $delete->createNamedParameter($old['id'])))->executeStatement();
		}
	}

	public function delete(int $packageId): void {
		$bom = $this->forPackage($packageId);
		if ($bom === null) return;
		$delete = $this->db->getQueryBuilder();
		$delete->delete('bestatter_bom_items')->where($delete->expr()->eq('bom_id', $delete->createNamedParameter($bom['id'])))->executeStatement();
		$delete = $this->db->getQueryBuilder();
		$delete->delete('bestatter_boms')->where($delete->expr()->eq('id', $delete->createNamedParameter($bom['id'])))->executeStatement();
	}

	public function isComponent(int $articleId): bool {
		$query = $this->db->getQueryBuilder();
		return (int)$query->select($query->func()->count('*', 'count'))->from('bestatter_bom_items')->where($query->expr()->eq('article_id', $query->createNamedParameter($articleId)))->executeQuery()->fetchOne() > 0;
	}

	private function mapItem(array $row): array {
		return [
			'id' => (int)$row['id'], 'itemNo' => (int)$row['item_no'], 'articleId' => (int)$row['article_id'],
			'articleNumber' => (string)$row['article_number'], 'shortName' => (string)$row['short_name'], 'articleGroup' => (string)$row['article_group'],
			'articleType' => (string)($row['article_type'] ?? ''), 'taxClass' => (string)($row['tax_class'] ?? 'V'), 'unit' => (string)($row['unit'] ?? 'STK'),
			'active' => $row['active'] === null ? true : (bool)$row['active'],
			'quantityMilli' => (int)$row['quantity_milli'], 'quantity' => (int)$row['quantity_milli'] / 1000,
			'itemType' => (string)$row['item_type'], 'altGroup' => (string)($row['alt_group'] ?? ''),
			'isDefault' => (bool)$row['is_default'], 'creditCents' => (int)$row['credit_cents'],
		];
	}
}
