<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Adds the independent side-order circle without changing existing main-order rows. */
class Version3500Date20260915000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('bestatter_side_orders')) {
			$table = $schema->createTable('bestatter_side_orders');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$table->addColumn('case_id', 'integer', ['unsigned' => true]);
			$table->addColumn('side_order_number', 'string', ['length' => 80]);
			$table->addColumn('first_name', 'string', ['length' => 100, 'notnull' => false]);
			$table->addColumn('last_name', 'string', ['length' => 100]);
			$table->addColumn('street', 'string', ['length' => 255, 'notnull' => false]);
			$table->addColumn('postal_city', 'string', ['length' => 180, 'notnull' => false]);
			$table->addColumn('country', 'string', ['length' => 100, 'notnull' => false]);
			$table->addColumn('phone', 'string', ['length' => 80, 'notnull' => false]);
			$table->addColumn('email', 'string', ['length' => 255, 'notnull' => false]);
			$table->addColumn('relation', 'string', ['length' => 100, 'notnull' => false]);
			$table->addColumn('status', 'string', ['length' => 32, 'default' => 'ENTWURF']);
			$table->addColumn('required_by', 'string', ['length' => 40, 'notnull' => false]);
			$table->addColumn('created_at', 'string', ['length' => 40]);
			$table->addColumn('updated_at', 'string', ['length' => 40]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['side_order_number'], 'bestatter_side_order_number');
			$table->addIndex(['case_id', 'status'], 'bestatter_side_order_case_status');
		}

		foreach (['bestatter_case_services', 'bestatter_commercial_docs', 'bestatter_invoices'] as $tableName) {
			if (!$schema->hasTable($tableName)) continue;
			$table = $schema->getTable($tableName);
			if (!$table->hasColumn('side_order_id')) $table->addColumn('side_order_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			$indexName = 'bestatter_' . substr(str_replace('bestatter_', '', $tableName), 0, 18) . '_side_order';
			if (!$table->hasIndex($indexName)) $table->addIndex(['case_id', 'side_order_id'], $indexName);
		}
		return $schema;
	}
}
