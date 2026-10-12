<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version3000Date20260907000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('bestatter_country_profiles')) {
			$table = $schema->createTable('bestatter_country_profiles');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$table->addColumn('country_code', 'string', ['length' => 2]);
			$table->addColumn('name', 'string', ['length' => 100]);
			$table->addColumn('vat_rates', 'text');
			$table->addColumn('default_invoice_profile', 'string', ['length' => 20, 'default' => 'NONE']);
			$table->addColumn('default_payment_qr', 'string', ['length' => 20, 'default' => 'NONE']);
			$table->addColumn('template_namespace', 'string', ['length' => 100]);
			$table->addColumn('ready', 'boolean', ['default' => false]);
			$table->addColumn('created_at', 'string', ['length' => 40]);
			$table->addColumn('updated_at', 'string', ['length' => 40]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['country_code'], 'bestatter_country_code');
		}
		if ($schema->hasTable('bestatter_branches')) {
			$table = $schema->getTable('bestatter_branches');
			if (!$table->hasColumn('country_code')) $table->addColumn('country_code', 'string', ['length' => 2, 'default' => 'DE']);
			if (!$table->hasColumn('invoice_profile')) $table->addColumn('invoice_profile', 'string', ['length' => 20, 'default' => 'ZUGFERD']);
			if (!$table->hasColumn('payment_qr_standard')) $table->addColumn('payment_qr_standard', 'string', ['length' => 20, 'default' => 'EPC069-12']);
			if (!$table->hasColumn('federal_state')) $table->addColumn('federal_state', 'string', ['length' => 40, 'notnull' => false]);
			if (!$table->hasIndex('bestatter_branch_country')) $table->addIndex(['country_code', 'active'], 'bestatter_branch_country');
		}
		return $schema;
	}
}
