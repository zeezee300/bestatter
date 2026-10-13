<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCA\Bestatter\Service\AuditService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.84.1 – Schreibsperre für das Audit-Protokoll: eine einzige Zeile (id = 1), die jeder Schreibvorgang bis zum Ende
 * seiner Transaktion sperrt. Dadurch werden gleichzeitige Einträge nacheinander verkettet (vorher konnten zwei
 * Einträge denselben Vorgänger erhalten). Die Tabelle enthält keine Fachdaten.
 */
class Version5620Date20261012000000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable(AuditService::LOCK_TABLE)) return null;
		$t = $schema->createTable(AuditService::LOCK_TABLE);
		$t->addColumn('id', Types::INTEGER, ['unsigned' => true]);
		$t->addColumn('counter', Types::BIGINT, ['unsigned' => true, 'default' => 0]);
		$t->setPrimaryKey(['id']);
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->db->insertIgnoreConflict(AuditService::LOCK_TABLE, ['id' => 1, 'counter' => 0]);
	}
}
