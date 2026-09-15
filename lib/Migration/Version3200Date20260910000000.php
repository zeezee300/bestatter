<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version3200Date20260910000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bestatter_case_services')) {
			$table = $schema->getTable('bestatter_case_services');
			// Existing service rows do not yet have a category snapshot. Keep the new
			// column nullable and omit an empty-string default for Nextcloud's schema check.
			if (!$table->hasColumn('article_category')) $table->addColumn('article_category', 'string', ['length' => 100, 'notnull' => false]);
		}
		return $schema;
	}
}
