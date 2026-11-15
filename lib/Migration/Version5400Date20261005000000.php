<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.76.0 – Marker am Fall (Stufe 1).
 *
 * bestatter_labels        Definition (Ja/Nein oder Liste, Farbe aus fester Palette, Symbol aus fester Auswahl).
 * bestatter_label_values  Auswahlwerte eines Listen-Markers.
 * bestatter_case_labels   Zuordnung zum Fall. Entfernte Marker bleiben mit removed_at stehen, damit Abläufe
 *                         auf „Marker entfernt“ reagieren können; je Fall und Marker gibt es genau eine Zeile.
 * Wahrheitswerte als SMALLINT (0/1), weil Nextcloud BOOLEAN-Spalten nur nullable zulässt.
 */
class Version5400Date20261005000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		if (!$schema->hasTable('bestatter_labels')) {
			$t = $schema->createTable('bestatter_labels');
			$t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('label_key', Types::STRING, ['length' => 80]);
			$t->addColumn('name', Types::STRING, ['length' => 100]);
			$t->addColumn('description', Types::STRING, ['length' => 500, 'notnull' => false]);
			$t->addColumn('kind', Types::STRING, ['length' => 10, 'default' => 'BOOL']);
			$t->addColumn('color', Types::STRING, ['length' => 20, 'default' => 'SLATE']);
			$t->addColumn('icon', Types::STRING, ['length' => 60, 'default' => 'tag']);
			$t->addColumn('sort_order', Types::INTEGER, ['default' => 0]);
			$t->addColumn('active', Types::SMALLINT, ['default' => 0]);
			$t->addColumn('keep_on_anonymize', Types::SMALLINT, ['default' => 0]);
			$t->addColumn('origin', Types::STRING, ['length' => 20, 'default' => 'CUSTOM']);
			$t->addColumn('created_at', Types::STRING, ['length' => 40]);
			$t->addColumn('updated_at', Types::STRING, ['length' => 40]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['label_key'], 'bestatter_label_key');
			$changed = true;
		}
		if (!$schema->hasTable('bestatter_label_values')) {
			$t = $schema->createTable('bestatter_label_values');
			$t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('label_id', Types::INTEGER, ['unsigned' => true]);
			$t->addColumn('value_key', Types::STRING, ['length' => 80]);
			$t->addColumn('name', Types::STRING, ['length' => 100]);
			$t->addColumn('sort_order', Types::INTEGER, ['default' => 0]);
			$t->addColumn('active', Types::SMALLINT, ['default' => 1]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['label_id', 'value_key'], 'bestatter_label_value_key');
			$changed = true;
		}
		if (!$schema->hasTable('bestatter_case_labels')) {
			$t = $schema->createTable('bestatter_case_labels');
			$t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('case_id', Types::INTEGER, ['unsigned' => true]);
			$t->addColumn('label_id', Types::INTEGER, ['unsigned' => true]);
			$t->addColumn('value_key', Types::STRING, ['length' => 80, 'notnull' => false]);
			$t->addColumn('set_at', Types::STRING, ['length' => 40]);
			$t->addColumn('set_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('removed_at', Types::STRING, ['length' => 40, 'notnull' => false]);
			$t->addColumn('removed_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['case_id', 'label_id'], 'bestatter_case_label');
			$t->addIndex(['label_id', 'removed_at'], 'bestatter_case_label_filter');
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
