<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.75.3 – Logo je Niederlassung (Einrichtungspaket): eigenes Tabelle, damit die häufig gelesene
 * Niederlassungsliste die Bilddaten nicht mitlädt. Gespeichert wird immer ein normalisiertes PNG (Base64).
 */
class Version5300Date20261004000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bestatter_branch_logos')) return null;
		$t = $schema->createTable('bestatter_branch_logos');
		$t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'unsigned' => true]);
		$t->addColumn('branch_key', Types::STRING, ['length' => 100]);
		$t->addColumn('mime', Types::STRING, ['length' => 40, 'default' => 'image/png']);
		$t->addColumn('width', Types::INTEGER, ['default' => 0]);
		$t->addColumn('height', Types::INTEGER, ['default' => 0]);
		$t->addColumn('data', Types::TEXT);
		$t->addColumn('sha256', Types::STRING, ['length' => 64]);
		$t->addColumn('updated_at', Types::STRING, ['length' => 40]);
		$t->addColumn('updated_by', Types::STRING, ['length' => 64, 'notnull' => false]);
		$t->setPrimaryKey(['id']);
		$t->addUniqueIndex(['branch_key'], 'bestatter_branch_logo_key');
		return $schema;
	}
}
