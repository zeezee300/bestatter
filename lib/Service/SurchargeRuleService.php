<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IDBConnection;

/** Configurable tiers and catalog links. No automatic price calculation is performed. */
class SurchargeRuleService {
	private const INITIAL = [
		['PICKUP_DAY', 'Abholung tagsüber', 'PICKUP', null, null],
		['PICKUP_NIGHT', 'Abholung nachts', 'PICKUP', null, null],
		['PICKUP_WEEKEND', 'Abholung am Wochenende', 'PICKUP', null, null],
		['PICKUP_HOLIDAY', 'Abholung am Feiertag', 'PICKUP', null, null],
		['HEIGHT_OVER_195', 'Körpergröße über 195 cm', 'HEIGHT_CM', 196, null],
		['WEIGHT_101_150', 'Körpergewicht 101–150 kg', 'WEIGHT_KG', 101, 150],
		['WEIGHT_151_200', 'Körpergewicht 151–200 kg', 'WEIGHT_KG', 151, 200],
		['WEIGHT_OVER_200', 'Körpergewicht ab 201 kg', 'WEIGHT_KG', 201, null],
	];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function all(): array {
		$this->ensureInitial();
		$q = $this->db->getQueryBuilder();
		$rows = $q->select('id', 'rule_key', 'label', 'dimension', 'threshold_from', 'threshold_to', 'article_id', 'active', 'sort_order')
			->from('bestatter_surcharge_rules')->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		return array_map(static fn (array $row): array => [
			'id' => (int)$row['id'], 'ruleKey' => (string)$row['rule_key'], 'label' => (string)$row['label'],
			'dimension' => (string)$row['dimension'],
			'thresholdFrom' => $row['threshold_from'] === null ? null : (int)$row['threshold_from'],
			'thresholdTo' => $row['threshold_to'] === null ? null : (int)$row['threshold_to'],
			'articleId' => $row['article_id'] === null ? null : (int)$row['article_id'],
			'active' => (bool)$row['active'], 'sortOrder' => (int)$row['sort_order'],
		], $rows);
	}

	public function save(array $data, ?int $id = null): array {
		$key = strtoupper(trim((string)($data['ruleKey'] ?? '')));
		$label = trim((string)($data['label'] ?? ''));
		$dimension = strtoupper(trim((string)($data['dimension'] ?? '')));
		if (!preg_match('/^[A-Z][A-Z0-9_]{2,99}$/', $key) || $label === '' || mb_strlen($label) > 180) {
			throw new \InvalidArgumentException('Bitte einen gültigen Schlüssel und eine Bezeichnung eingeben.');
		}
		if (!in_array($dimension, ['PICKUP', 'HEIGHT_CM', 'WEIGHT_KG', 'OTHER'], true)) {
			throw new \InvalidArgumentException('Die Zuschlagsdimension ist ungültig.');
		}
		$from = $this->bound($data['thresholdFrom'] ?? null);
		$to = $this->bound($data['thresholdTo'] ?? null);
		if ($from !== null && $to !== null && $from > $to) {
			throw new \InvalidArgumentException('Die Staffelgrenze von darf nicht größer als bis sein.');
		}
		if (in_array($dimension, ['HEIGHT_CM', 'WEIGHT_KG'], true) && $from === null) {
			throw new \InvalidArgumentException('Eine Größen- oder Gewichtsstaffel benötigt eine Untergrenze.');
		}
		$articleId = (int)($data['articleId'] ?? 0);
		$active = filter_var($data['active'] ?? false, FILTER_VALIDATE_BOOLEAN);
		if ($active && !$articleId) {
			throw new \InvalidArgumentException('Vor der Aktivierung muss eine Katalogleistung verknüpft werden.');
		}
		if ($articleId) {
			$article = $this->db->getQueryBuilder();
			$exists = $article->select('id', 'active')->from('bestatter_articles')
				->where($article->expr()->eq('id', $article->createNamedParameter($articleId)))->executeQuery()->fetchAssociative();
			if (!$exists || ($active && !(bool)$exists['active'])) {
				throw new \InvalidArgumentException('Die verknüpfte Katalogleistung fehlt oder ist deaktiviert.');
			}
		}
		if ($id !== null) {
			$lookup = $this->db->getQueryBuilder();
			$current = $lookup->select('rule_key')->from('bestatter_surcharge_rules')->where($lookup->expr()->eq('id', $lookup->createNamedParameter($id)))->executeQuery()->fetchOne();
			if ($current === false) {
				throw new \InvalidArgumentException('Die Zuschlagsstaffel wurde nicht gefunden.');
			}
			if ($current !== $key) {
				throw new \InvalidArgumentException('Der technische Schlüssel darf nach Anlage nicht geändert werden.');
			}
		}
		$q = $this->db->getQueryBuilder();
		$values = ['rule_key' => $key, 'label' => $label, 'dimension' => $dimension, 'threshold_from' => $from, 'threshold_to' => $to, 'article_id' => $articleId ?: null, 'active' => $active ? 1 : 0, 'sort_order' => max(0, min(9999, (int)($data['sortOrder'] ?? 0)))];
		if ($id === null) {
			$q->insert('bestatter_surcharge_rules')->values(array_map(fn ($value) => $q->createNamedParameter($value), $values));
		} else {
			$q->update('bestatter_surcharge_rules')->where($q->expr()->eq('id', $q->createNamedParameter($id)));
			foreach ($values as $column => $value) {
				$q->set($column, $q->createNamedParameter($value));
			}
		}
		$q->executeStatement();
		$savedId = $id ?? (int)$this->db->lastInsertId('bestatter_surcharge_rules');
		foreach ($this->all() as $row) {
			if ($row['id'] === $savedId) {
				return $row;
			}
		}
		throw new \RuntimeException('Die Zuschlagsstaffel konnte nicht gelesen werden.');
	}

	private function bound(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}
		if (!ctype_digit((string)$value) || (int)$value > 10000) {
			throw new \InvalidArgumentException('Staffelgrenzen müssen positive ganze Zahlen sein.');
		}
		return (int)$value;
	}

	private function ensureInitial(): void {
		foreach (self::INITIAL as $index => [$key, $label, $dimension, $from, $to]) {
			$q = $this->db->getQueryBuilder();
			if ($q->select('id')->from('bestatter_surcharge_rules')->where($q->expr()->eq('rule_key', $q->createNamedParameter($key)))->executeQuery()->fetchOne() !== false) {
				continue;
			}
			$insert = $this->db->getQueryBuilder();
			try {
				$insert->insert('bestatter_surcharge_rules')->values([
					'rule_key' => $insert->createNamedParameter($key), 'label' => $insert->createNamedParameter($label),
					'dimension' => $insert->createNamedParameter($dimension), 'threshold_from' => $insert->createNamedParameter($from),
					'threshold_to' => $insert->createNamedParameter($to), 'article_id' => $insert->createNamedParameter(null),
					'active' => $insert->createNamedParameter(0), 'sort_order' => $insert->createNamedParameter(($index + 1) * 10),
				])->executeStatement();
			} catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) { /* concurrent first read */
			}
		}
	}

	public const EXCHANGE_FORMAT = 'bestatter-surcharge-rules';

	/** 0.89.5 (OP-071): Staffeln für das Einrichtungspaket – verknüpfte Leistung über die Artikelnummer. */
	public function exportExchange(): string {
		$numbers = $this->articleNumbers();
		$rules = array_map(static fn (array $rule): array => array_diff_key($rule, ['id' => true, 'articleId' => true]) + ['article' => $rule['articleId'] !== null ? ($numbers[$rule['articleId']] ?? '') : ''], $this->all());
		return json_encode(['format' => self::EXCHANGE_FORMAT, 'formatVersion' => 1, 'appVersion' => \OCA\Bestatter\AppInfo\Application::VERSION, 'rules' => $rules], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	/** @return list<array> */
	public static function parseExchange(string $content): array {
		$data = json_decode($content, true);
		if (!is_array($data) || ($data['format'] ?? '') !== self::EXCHANGE_FORMAT) {
			throw new \InvalidArgumentException('Keine Zuschlagsstaffeln im erwarteten Format.');
		}
		return array_values(array_filter((array)($data['rules'] ?? []), 'is_array'));
	}

	/**
	 * Legt fehlende Staffeln an und aktualisiert vorhandene (Schlüssel bleibt). Fehlt die verknüpfte Leistung im Katalog, wird die
	 * Staffel ohne Leistung und inaktiv übernommen.
	 * @return array{created:int,updated:int,skipped:list<string>}
	 */
	public function importExchange(string $content): array {
		$rules = self::parseExchange($content);
		$ids = array_flip($this->articleNumbers());
		$current = array_column($this->all(), null, 'ruleKey');
		$created = 0;
		$updated = 0;
		$skipped = [];
		foreach ($rules as $rule) {
			$key = strtoupper(trim((string)($rule['ruleKey'] ?? '')));
			$number = (string)($rule['article'] ?? '');
			$articleId = $number !== '' ? ($ids[$number] ?? null) : null;
			$data = ['ruleKey' => $key, 'label' => $rule['label'] ?? '', 'dimension' => $rule['dimension'] ?? '', 'thresholdFrom' => $rule['thresholdFrom'] ?? null, 'thresholdTo' => $rule['thresholdTo'] ?? null,
				'articleId' => $articleId, 'active' => !empty($rule['active']) && $articleId !== null, 'sortOrder' => $rule['sortOrder'] ?? 0];
			try {
				$this->save($data, isset($current[$key]) ? (int)$current[$key]['id'] : null);
				isset($current[$key]) ? $updated++ : $created++;
				if ($number !== '' && $articleId === null) {
					$skipped[] = '„' . $data['label'] . '“: Artikel ' . $number . ' fehlt – ohne Leistung und inaktiv übernommen';
				}
			} catch (\InvalidArgumentException $error) {
				$skipped[] = '„' . (string)$data['label'] . '“: ' . $error->getMessage();
			}
		}
		return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
	}

	/** @return array<int,string> Artikelnummer je Artikel-ID */
	private function articleNumbers(): array {
		$numbers = [];
		foreach ($this->db->getQueryBuilder()->select('id', 'article_number')->from('bestatter_articles')->executeQuery()->fetchAllAssociative() as $row) {
			$numbers[(int)$row['id']] = (string)$row['article_number'];
		}
		return $numbers;
	}
}
