<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IDBConnection;

/** Small, data-driven rule list; intentionally not a generic configuration engine. */
class BurialVariantRuleService {
	private const TYPES = ['MANDATORY_ARTICLE_GROUP', 'MANDATORY_ARTICLE_GROUP_WITH_DEFAULT', 'SUGGESTED_ARTICLE', 'EXCLUDED_ARTICLE_GROUP'];

	public function __construct(private IDBConnection $db, private CustomizingService $customizing) {}

	public function all(): array {
		$q = $this->db->getQueryBuilder();
		$rows = $q->select('id', 'variant_code', 'rule_type', 'article_group', 'article_id', 'default_article_id', 'note', 'active', 'sort_order')
			->from('bestatter_burial_variant_rules')->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		return array_map(static fn(array $row): array => [
			'id' => (int)$row['id'], 'variantCode' => (string)$row['variant_code'], 'ruleType' => (string)$row['rule_type'],
			'articleGroup' => (string)($row['article_group'] ?? ''), 'articleId' => $row['article_id'] === null ? null : (int)$row['article_id'],
			'defaultArticleId' => $row['default_article_id'] === null ? null : (int)$row['default_article_id'],
			'note' => (string)($row['note'] ?? ''), 'active' => (bool)$row['active'], 'sortOrder' => (int)$row['sort_order'],
		], $rows);
	}

	public function save(array $data, ?int $id = null): array {
		$code = trim((string)($data['variantCode'] ?? ''));
		$type = trim((string)($data['ruleType'] ?? ''));
		$group = trim((string)($data['articleGroup'] ?? ''));
		$articleId = (int)($data['articleId'] ?? 0);
		$defaultId = (int)($data['defaultArticleId'] ?? 0);
		$note = trim((string)($data['note'] ?? ''));
		$active = filter_var($data['active'] ?? false, FILTER_VALIDATE_BOOLEAN);
		$variant = null;
		foreach ($this->customizing->valuesForKey('BURIAL_VARIANT') as $item) if ($item['value'] === $code) { $variant = $item; break; }
		if ($variant === null) throw new \InvalidArgumentException('Die gewählte Bestattungsvariante ist unbekannt.');
		if ($active && !empty($variant['metadata']['classificationPending'])) throw new \InvalidArgumentException('Für eine fachlich ungeklärte Variante dürfen noch keine Leistungsregeln aktiviert werden.');
		if (!in_array($type, self::TYPES, true)) throw new \InvalidArgumentException('Der Regeltyp ist ungültig.');
		if (in_array($type, ['MANDATORY_ARTICLE_GROUP', 'MANDATORY_ARTICLE_GROUP_WITH_DEFAULT', 'EXCLUDED_ARTICLE_GROUP'], true) && $group === '') throw new \InvalidArgumentException('Für diese Regel ist die Artikelgruppe erforderlich.');
		if ($type === 'SUGGESTED_ARTICLE' && !$articleId) throw new \InvalidArgumentException('Für einen Leistungsvorschlag ist ein Artikel erforderlich.');
		if ($type === 'MANDATORY_ARTICLE_GROUP_WITH_DEFAULT' && !$defaultId) throw new \InvalidArgumentException('Für den Platzhalter ist ein Standardartikel erforderlich.');
		if (mb_strlen($group) > 100 || mb_strlen($note) > 2000) throw new \InvalidArgumentException('Der Gruppenname oder Hinweistext ist zu lang.');
		foreach ([$articleId, $defaultId] as $reference) {
			if (!$reference) continue;
			$q = $this->db->getQueryBuilder();
			$article = $q->select('article_group', 'active')->from('bestatter_articles')->where($q->expr()->eq('id', $q->createNamedParameter($reference)))->executeQuery()->fetchAssociative();
			if (!$article || ($active && !(bool)$article['active'])) throw new \InvalidArgumentException('Ein verknüpfter Artikel fehlt oder ist deaktiviert.');
			if ($reference === $defaultId && $group !== '' && $group !== $article['article_group']) throw new \InvalidArgumentException('Der Standardartikel gehört nicht zur gewählten Artikelgruppe.');
		}
		if ($id !== null) {
			$q = $this->db->getQueryBuilder();
			if ($q->select('id')->from('bestatter_burial_variant_rules')->where($q->expr()->eq('id', $q->createNamedParameter($id)))->executeQuery()->fetchOne() === false) throw new \InvalidArgumentException('Die Variantenregel wurde nicht gefunden.');
		}
		$q = $this->db->getQueryBuilder();
		$values = ['variant_code' => $code, 'rule_type' => $type, 'article_group' => $group ?: null, 'article_id' => $articleId ?: null, 'default_article_id' => $defaultId ?: null, 'note' => $note ?: null, 'active' => $active ? 1 : 0, 'sort_order' => max(0, min(9999, (int)($data['sortOrder'] ?? 0)))];
		if ($id === null) $q->insert('bestatter_burial_variant_rules')->values(array_map(fn($value) => $q->createNamedParameter($value), $values));
		else {
			$q->update('bestatter_burial_variant_rules')->where($q->expr()->eq('id', $q->createNamedParameter($id)));
			foreach ($values as $column => $value) $q->set($column, $q->createNamedParameter($value));
		}
		$q->executeStatement();
		$savedId = $id ?? (int)$this->db->lastInsertId('bestatter_burial_variant_rules');
		foreach ($this->all() as $row) if ($row['id'] === $savedId) return $row;
		throw new \RuntimeException('Die Variantenregel konnte nicht gelesen werden.');
	}
}
