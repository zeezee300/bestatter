<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.74.0 – Auftragsformular-Vorlagen: Zeilen eines Papier-Bestattungsauftrags mit Zuordnung zum
 * Leistungskatalog sowie die je Fall erfassten Formularwerte (Beträge brutto, Zuordnung Zeile → Position).
 */
class Version5200Date20261003000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		if (!$schema->hasTable('bestatter_order_forms')) {
			$t = $schema->createTable('bestatter_order_forms');
			$t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('form_key', Types::STRING, ['length' => 64]);
			$t->addColumn('name', Types::STRING, ['length' => 190]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('version_no', Types::INTEGER, ['default' => 1]);
			$t->addColumn('definition', Types::TEXT);
			$t->addColumn('created_at', Types::STRING, ['length' => 40]);
			$t->addColumn('updated_at', Types::STRING, ['length' => 40]);
			$t->addColumn('updated_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['form_key'], 'bestatter_order_form_key');
			$changed = true;
		}
		if (!$schema->hasTable('bestatter_order_form_entries')) {
			$t = $schema->createTable('bestatter_order_form_entries');
			$t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('case_id', Types::INTEGER, ['unsigned' => true]);
			$t->addColumn('form_id', Types::INTEGER, ['unsigned' => true]);
			$t->addColumn('form_version', Types::INTEGER, ['default' => 1]);
			$t->addColumn('values_json', Types::TEXT);
			$t->addColumn('line_services_json', Types::TEXT, ['notnull' => false]);
			$t->addColumn('declared_total_cents', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('applied_at', Types::STRING, ['length' => 40, 'notnull' => false]);
			$t->addColumn('applied_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('created_at', Types::STRING, ['length' => 40]);
			$t->addColumn('updated_at', Types::STRING, ['length' => 40]);
			$t->addColumn('updated_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['case_id', 'form_id'], 'bestatter_ofe_case_form');
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
