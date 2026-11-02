<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCA\Bestatter\Service\CatalogRules;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.89.5 – Preisart je Artikel und Fallposition (Festpreis, Schätzbetrag, nach Bescheid). Bestehende Artikel und Positionen
 * erhalten die Vorbelegung aus Artikelart bzw. Positionstyp: eigene Leistungen Festpreis, Fremdleistungen Schätzbetrag,
 * Gebühren ohne Betrag „nach Bescheid“. Bereits gesetzte Werte bleiben unverändert.
 */
class Version5800Date20261015000000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		foreach (['bestatter_articles', 'bestatter_case_services'] as $tableName) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}
			$table = $schema->getTable($tableName);
			if ($table->hasColumn('price_basis')) {
				continue;
			}
			$table->addColumn('price_basis', 'string', ['length' => 12, 'notnull' => false]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if ($this->db->tableExists('bestatter_articles')) {
			$query = $this->db->getQueryBuilder();
			$rows = $query->select('id', 'article_type', 'price_on_entry', 'guide_price_cents')->from('bestatter_articles')
				->where($query->expr()->isNull('price_basis'))->executeQuery()->fetchAllAssociative();
			foreach ($rows as $row) {
				$basis = CatalogRules::defaultPriceBasis((string)($row['article_type'] ?? ''), (bool)($row['price_on_entry'] ?? false), $row['guide_price_cents'] !== null ? (int)$row['guide_price_cents'] : null);
				$update = $this->db->getQueryBuilder();
				$update->update('bestatter_articles')->set('price_basis', $update->createNamedParameter($basis))
					->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'])))->executeStatement();
			}
		}
		if ($this->db->tableExists('bestatter_case_services')) {
			$query = $this->db->getQueryBuilder();
			$rows = $query->select('id', 'position_type', 'price_on_entry', 'unit_price_cents')->from('bestatter_case_services')
				->where($query->expr()->isNull('price_basis'))->executeQuery()->fetchAllAssociative();
			foreach ($rows as $row) {
				$basis = CatalogRules::defaultPriceBasisForPosition((string)($row['position_type'] ?? 'EL'), (bool)($row['price_on_entry'] ?? false), (int)($row['unit_price_cents'] ?? 0));
				$update = $this->db->getQueryBuilder();
				$update->update('bestatter_case_services')->set('price_basis', $update->createNamedParameter($basis))
					->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'])))->executeStatement();
			}
		}
	}
}
