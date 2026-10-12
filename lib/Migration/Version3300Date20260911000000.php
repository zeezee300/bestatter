<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version3300Date20260911000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('bestatter_case_services')) {
			$table = $schema->getTable('bestatter_case_services');
			if (!$table->hasColumn('note')) $table->addColumn('note', 'text', ['notnull' => false]);
		}
		return $schema;
	}
}
