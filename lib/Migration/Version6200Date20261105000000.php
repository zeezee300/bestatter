<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.90.3 – Ressourcen je Terminart: Spalte „allowed_resource_keys“ (JSON-Liste der zulässigen Ressourcen).
 *
 * Im Terminformular werden nur noch die zulässigen Ressourcen der gewählten Terminart angeboten; ohne Zuordnung keine.
 * Bisher vorbelegte Ressourcen (default_resource_keys) gelten nach dem Update als zulässig, damit eine bereits gepflegte
 * Vorbelegung nicht verloren geht. Alles Weitere wird unter Customizing → Terminplanung in der Matrix gepflegt.
 */
class Version6200Date20261105000000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('bestatter_schedule_types')) {
			return null;
		}
		$table = $schema->getTable('bestatter_schedule_types');
		if ($table->hasColumn('allowed_resource_keys')) {
			return null;
		}
		$table->addColumn('allowed_resource_keys', Types::TEXT, ['notnull' => false]);
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->db->getQueryBuilder();
		$rows = $select->select('id', 'default_resource_keys', 'allowed_resource_keys')->from('bestatter_schedule_types')->executeQuery()->fetchAllAssociative();
		foreach ($rows as $row) {
			if ($row['allowed_resource_keys'] !== null && trim((string)$row['allowed_resource_keys']) !== '') {
				continue;
			}
			$defaults = json_decode((string)($row['default_resource_keys'] ?? '[]'), true);
			$keys = is_array($defaults) ? array_values(array_unique(array_filter(array_map(static fn ($key): string => strtoupper(trim((string)$key)), $defaults)))) : [];
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_schedule_types')
				->set('allowed_resource_keys', $update->createNamedParameter(json_encode($keys, JSON_THROW_ON_ERROR)))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'])))
				->executeStatement();
		}
	}
}
