<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.67.0 – Altbestand entfernt: Checklisten, klassische Workflows und die automatische
 * „Trauerfeier abstimmen“-Aufgabe sind vollständig durch die Abläufe in 7 Phasen ersetzt.
 *
 * - Neu: bestatter_action_runs – Ausführungsnachweis der Knöpfe („Aktionen“) an Ablaufschritten.
 *   Ein Lauf hängt nur noch an Aufgabe und Aktionsschlüssel, nicht mehr an einem Workflow-Datensatz.
 * - Entfernt: bestatter_workflows, bestatter_workflow_runs, bestatter_checklist_templates,
 *   bestatter_checklist_items, bestatter_case_automation.
 * - Entfernt: App-Einstellung process_enabled_since (Abläufe gelten für jeden Fall).
 *
 * Bereits angelegte Aufgaben bleiben als normale Aufgaben erhalten. Einträge im Audit-Log
 * (Hash-Kette) bleiben unverändert.
 */
class Version4600Date20260927000000 extends SimpleMigrationStep {
	private const DROPPED = ['bestatter_workflow_runs', 'bestatter_workflows', 'bestatter_checklist_items', 'bestatter_checklist_templates', 'bestatter_case_automation'];

	public function __construct(private IConfig $config) {}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('bestatter_action_runs')) {
			$t = $schema->createTable('bestatter_action_runs');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('source_record_id', 'integer', ['unsigned' => true]);
			$t->addColumn('action_key', 'string', ['length' => 100]);
			$t->addColumn('execution_key', 'string', ['length' => 180]);
			$t->addColumn('run_status', 'string', ['length' => 24, 'default' => 'COMPLETED']);
			$t->addColumn('result_json', 'text', ['notnull' => false]);
			$t->addColumn('executed_by', 'string', ['length' => 64]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->addColumn('updated_at', 'string', ['length' => 40, 'notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['source_record_id'], 'bestatter_action_source');
			$t->addUniqueIndex(['execution_key'], 'bestatter_action_execution');
		}
		foreach (self::DROPPED as $table) {
			if ($schema->hasTable($table)) $schema->dropTable($table);
		}
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->config->deleteAppValue('bestatter', 'process_enabled_since');
		$output->info('Checklisten, klassische Workflows und ihre Tabellen wurden entfernt; Knopf-Ausführungen laufen über bestatter_action_runs.');
	}
}
