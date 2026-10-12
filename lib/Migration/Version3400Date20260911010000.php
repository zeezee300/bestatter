<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version3400Date20260911010000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bestatter_invoices')) {
			$table = $schema->getTable('bestatter_invoices');
			if (!$table->hasColumn('payment_snapshot')) $table->addColumn('payment_snapshot', 'text', ['notnull' => false]);
		}
		return $schema;
	}
}
