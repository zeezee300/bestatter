<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCA\Bestatter\Service\CaseKinds;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.89.7 – Vorgangsarten (Aufträge ohne Sterbefall, Vorsorge).
 *
 * - bestatter_cases: case_kind (STERBEFALL, AUFTRAG, VORSORGE), order_type (Auftragsart), origin_case_id (Sterbefall aus einer Vorsorge)
 * - bestatter_process_templates: case_kinds – für welche Vorgangsarten eine Ablaufvorlage gilt
 *
 * Alle vorhandenen Fälle werden Sterbefälle, alle vorhandenen Ablaufvorlagen gelten nur für Sterbefälle. Nummern, Ordner und
 * Dokumente bleiben unverändert. Spalten zunächst ohne NOT NULL, damit das Hinzufügen auf allen Datenbanken ohne Tabellenumbau
 * gelingt; leere Werte gelten im Code als Sterbefall.
 */
class Version5900Date20261021000000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		if ($schema->hasTable('bestatter_cases')) {
			$table = $schema->getTable('bestatter_cases');
			if (!$table->hasColumn('case_kind')) {
				$table->addColumn('case_kind', 'string', ['length' => 20, 'notnull' => false, 'default' => CaseKinds::DEATH]);
				$changed = true;
			}
			if (!$table->hasColumn('order_type')) {
				$table->addColumn('order_type', 'string', ['length' => 60, 'notnull' => false]);
				$changed = true;
			}
			if (!$table->hasColumn('origin_case_id')) {
				$table->addColumn('origin_case_id', 'integer', ['unsigned' => true, 'notnull' => false]);
				$changed = true;
			}
			if (!$table->hasIndex('bestatter_case_kind_status')) {
				$table->addIndex(['case_kind', 'status'], 'bestatter_case_kind_status');
				$changed = true;
			}
		}
		if ($schema->hasTable('bestatter_process_templates')) {
			$table = $schema->getTable('bestatter_process_templates');
			if (!$table->hasColumn('case_kinds')) {
				$table->addColumn('case_kinds', 'string', ['length' => 120, 'notnull' => false, 'default' => CaseKinds::DEATH]);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		foreach (['bestatter_cases' => 'case_kind', 'bestatter_process_templates' => 'case_kinds'] as $table => $column) {
			if (!$this->db->tableExists($table)) {
				continue;
			}
			$query = $this->db->getQueryBuilder();
			$updated = $query->update($table)->set($column, $query->createNamedParameter(CaseKinds::DEATH))
				->where($query->expr()->orX($query->expr()->isNull($column), $query->expr()->eq($column, $query->createNamedParameter(''))))
				->executeStatement();
			if ($updated > 0) {
				$output->info($table . ': ' . $updated . ' Einträge als Sterbefall gekennzeichnet.');
			}
		}
	}
}
