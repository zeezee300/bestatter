<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Adds lookup indexes for BEST-115 without changing existing side-order data. */
class Version3600Date20260915010000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('bestatter_side_orders')) return $schema;
		$table = $schema->getTable('bestatter_side_orders');
		if (!$table->hasIndex('bestatter_side_order_name')) $table->addIndex(['last_name', 'first_name'], 'bestatter_side_order_name');
		if (!$table->hasIndex('bestatter_side_order_status_case')) $table->addIndex(['status', 'case_id'], 'bestatter_side_order_status_case');
		return $schema;
	}
}
