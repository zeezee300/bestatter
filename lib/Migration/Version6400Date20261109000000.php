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
 * 0.92.0 – Stammdaten → Ressourcen und Ressourcentypen.
 *
 * - bestatter_resources: Nummer/Kennung (z. B. Kennzeichen „HB-RL 333“ oder „Raum 2“), Beschreibung, Niederlassungen
 *   (JSON-Liste der Niederlassungsschlüssel; leer = alle Niederlassungen) und „Letzte Änderung“ (Zeitpunkt, Benutzer).
 * - bestatter_schedule_types: Pflichtbedarf je Ressourcentyp als JSON (required_resources, z. B. {"CHAPEL":1}), damit
 *   auch eigene Ressourcentypen aus der Werteliste „Ressourcentypen“ einen Pflichtbedarf haben können. Die bisherigen
 *   Spalten required_chapels/_rooms/_vehicles/_equipment werden übernommen und weiter mitgeschrieben.
 * - Ressourcen vom früheren Typ „Mitarbeiter“ (STAFF) werden deaktiviert; Mitarbeitende wählt man im Termin über
 *   „Mitarbeitende“.
 */
class Version6400Date20261109000000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		if ($schema->hasTable('bestatter_resources')) {
			$table = $schema->getTable('bestatter_resources');
			foreach ([
				'identifier' => [Types::STRING, ['length' => 60, 'notnull' => false]],
				'description' => [Types::TEXT, ['notnull' => false]],
				'branch_keys' => [Types::TEXT, ['notnull' => false]],
				'updated_at' => [Types::STRING, ['length' => 40, 'notnull' => false]],
				'updated_by' => [Types::STRING, ['length' => 64, 'notnull' => false]],
			] as $column => [$type, $columnOptions]) {
				if (!$table->hasColumn($column)) {
					$table->addColumn($column, $type, $columnOptions);
					$changed = true;
				}
			}
		}
		if ($schema->hasTable('bestatter_schedule_types')) {
			$table = $schema->getTable('bestatter_schedule_types');
			if (!$table->hasColumn('required_resources')) {
				$table->addColumn('required_resources', Types::TEXT, ['notnull' => false]);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->db->getQueryBuilder();
		$rows = $select->select('id', 'required_resources', 'required_chapels', 'required_rooms', 'required_vehicles', 'required_equipment')
			->from('bestatter_schedule_types')->executeQuery()->fetchAllAssociative();
		foreach ($rows as $row) {
			if ($row['required_resources'] !== null && trim((string)$row['required_resources']) !== '') {
				continue;
			}
			$required = array_filter([
				'CHAPEL' => (int)($row['required_chapels'] ?? 0),
				'ROOM' => (int)($row['required_rooms'] ?? 0),
				'VEHICLE' => (int)($row['required_vehicles'] ?? 0),
				'EQUIPMENT' => (int)($row['required_equipment'] ?? 0),
			], static fn (int $count): bool => $count > 0);
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_schedule_types')
				->set('required_resources', $update->createNamedParameter(json_encode((object)$required, JSON_THROW_ON_ERROR)))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'])))
				->executeStatement();
		}
		$staff = $this->db->getQueryBuilder();
		$deactivated = $staff->update('bestatter_resources')
			->set('active', $staff->createNamedParameter(0))
			->where($staff->expr()->eq('resource_type', $staff->createNamedParameter('STAFF')))
			->andWhere($staff->expr()->eq('active', $staff->createNamedParameter(1)))
			->executeStatement();
		if ($deactivated > 0) {
			$output->info($deactivated . ' Ressource(n) vom Typ „Mitarbeiter“ deaktiviert; Mitarbeitende werden im Termin über „Mitarbeitende“ gewählt.');
		}
	}
}
