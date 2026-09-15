<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version3100Date20260909000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bestatter_case_services')) {
			$table = $schema->getTable('bestatter_case_services');
			if (!$table->hasColumn('position_type')) $table->addColumn('position_type', 'string', ['length' => 2, 'default' => 'EL']);
		}
		return $schema;
	}
}
