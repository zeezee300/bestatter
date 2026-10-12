<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.69.2 – Je Preisliste, Artikel und „Gültig ab“ gibt es genau einen Preis. Eventuelle Dubletten
 * (gleichzeitiges Speichern vor dieser Version) werden vorher bereinigt: der zuletzt gespeicherte Satz bleibt.
 */
class Version4900Date20260930000000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {}

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('bestatter_price_list_items')) return;
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('id', 'price_list_id', 'article_id', 'valid_from')->from('bestatter_price_list_items')
			->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		$keep = [];
		$remove = [];
		foreach ($rows as $row) {
			$key = $row['price_list_id'] . '|' . $row['article_id'] . '|' . $row['valid_from'];
			if (isset($keep[$key])) $remove[] = $keep[$key];
			$keep[$key] = (int)$row['id'];
		}
		foreach (array_chunk($remove, 500) as $chunk) {
			$delete = $this->db->getQueryBuilder();
			$delete->delete('bestatter_price_list_items')->where($delete->expr()->in('id', $delete->createNamedParameter($chunk, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
		}
		if ($remove !== []) $output->info(count($remove) . ' doppelte Preissätze bereinigt.');
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('bestatter_price_list_items')) return null;
		$table = $schema->getTable('bestatter_price_list_items');
		if ($table->hasIndex('bestatter_pli_unique')) return null;
		$table->addUniqueIndex(['price_list_id', 'article_id', 'valid_from'], 'bestatter_pli_unique');
		return $schema;
	}
}
