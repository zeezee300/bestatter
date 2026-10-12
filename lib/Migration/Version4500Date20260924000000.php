<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.66.0 – Ablaufvorlagen in 7 Phasen (Kundenentscheidung vom 2026-09-24, Pakete A–F).
 *
 * Neue Tabellen:
 * - bestatter_process_templates: Ablaufvorlagen (Standardablauf plus Zusatzabläufe)
 * - bestatter_process_steps: Schritte einer Vorlage (Phase, Auslöser, Bedingungen, Frist, Rolle,
 *   Priorität, Pflicht, Erledigt-Bedingung, Ergebnis-Knöpfe)
 * - bestatter_process_runs: welcher Schritt in welchem Fall (und ggf. für welchen Termin bzw.
 *   welche Eingangsrechnung) bereits entstanden ist; eindeutiger Index verhindert Dubletten auch
 *   bei gleichzeitigen Anfragen
 *
 * Außerdem Spalte bestatter_workflows.superseded: Die bisher mitgelieferten Workflows werden durch
 * den Standardablauf ersetzt. Sie laufen nicht mehr automatisch und werden neuen Aufgaben nicht mehr
 * angeboten, bleiben aber für bereits bestehende Aufgaben (Altbestand) bedienbar. Die mitgelieferten
 * Checklisten werden deaktiviert (ihre Aufgaben sind vollständig im Standardablauf enthalten); vom
 * Kunden selbst angelegte Checklisten und Workflows bleiben unverändert aktiv.
 */
class Version4500Date20260924000000 extends SimpleMigrationStep {
	private const BUNDLED_WORKFLOWS = ['BESTATTUNGSART_TERMIN', 'AUFTRAG_DOKUMENTE', 'STANDESAMT_ANZEIGE', 'ABMELDUNGEN_VORBEREITEN', 'TRAUERFEIER_VORBEREITEN', 'KONDOLENZLISTEN_ERSTELLEN'];
	private const BUNDLED_CHECKLISTS = ['STERBEFALL_STANDARD', 'FEUERBESTATTUNG', 'ERDBESTATTUNG', 'ERSTBESTATTUNG', 'ABMELDUNGEN'];

	public function __construct(private IDBConnection $db, private IConfig $config) {}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('bestatter_process_templates')) {
			$t = $schema->createTable('bestatter_process_templates');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('template_key', 'string', ['length' => 80]);
			$t->addColumn('name', 'string', ['length' => 200]);
			$t->addColumn('description', 'text', ['notnull' => false]);
			$t->addColumn('active', 'boolean', ['notnull' => false, 'default' => true]);
			$t->addColumn('origin', 'string', ['length' => 16, 'default' => 'CUSTOM']);
			$t->addColumn('sort_order', 'integer', ['default' => 0]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->addColumn('updated_at', 'string', ['length' => 40]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['template_key'], 'bestatter_proc_tpl_key');
		}

		if (!$schema->hasTable('bestatter_process_steps')) {
			$t = $schema->createTable('bestatter_process_steps');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('template_id', 'integer', ['unsigned' => true]);
			$t->addColumn('step_key', 'string', ['length' => 80]);
			$t->addColumn('phase', 'smallint', ['default' => 1]);
			$t->addColumn('title', 'string', ['length' => 200]);
			$t->addColumn('description', 'text', ['notnull' => false]);
			$t->addColumn('sort_order', 'integer', ['default' => 0]);
			$t->addColumn('active', 'boolean', ['notnull' => false, 'default' => true]);
			// Aus dem Editor entfernte Schritte, zu denen es bereits Aufgaben gibt, werden nur archiviert,
			// damit bestehende Aufgaben ihre Ergebnis-Knöpfe und ihre Phase behalten.
			$t->addColumn('archived', 'boolean', ['notnull' => false, 'default' => false]);
			$t->addColumn('trigger_json', 'text');
			$t->addColumn('applies_json', 'text');
			$t->addColumn('complete_json', 'text');
			$t->addColumn('actions_json', 'text');
			$t->addColumn('due_offset_days', 'integer', ['default' => 0]);
			$t->addColumn('due_reference', 'string', ['length' => 32, 'default' => 'TRIGGER']);
			$t->addColumn('role', 'string', ['length' => 32, 'notnull' => false]);
			$t->addColumn('priority', 'string', ['length' => 16, 'default' => 'NORMAL']);
			$t->addColumn('required', 'boolean', ['notnull' => false, 'default' => false]);
			$t->addColumn('updated_at', 'string', ['length' => 40]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['template_id', 'sort_order'], 'bestatter_proc_step_tpl');
			$t->addUniqueIndex(['template_id', 'step_key'], 'bestatter_proc_step_key');
		}

		if (!$schema->hasTable('bestatter_process_runs')) {
			$t = $schema->createTable('bestatter_process_runs');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('case_id', 'integer', ['unsigned' => true]);
			$t->addColumn('step_id', 'integer', ['unsigned' => true]);
			$t->addColumn('step_key', 'string', ['length' => 80]);
			$t->addColumn('context_key', 'string', ['length' => 64, 'default' => 'case']);
			$t->addColumn('record_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			$t->addColumn('run_status', 'string', ['length' => 24, 'default' => 'CREATED']);
			$t->addColumn('anchor_date', 'string', ['length' => 40, 'notnull' => false]);
			$t->addColumn('due_date', 'string', ['length' => 40, 'notnull' => false]);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => false]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->addColumn('updated_at', 'string', ['length' => 40]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['case_id', 'step_id', 'context_key'], 'bestatter_proc_run_unique');
			$t->addIndex(['record_id'], 'bestatter_proc_run_record');
		}

		if ($schema->hasTable('bestatter_workflows')) {
			$t = $schema->getTable('bestatter_workflows');
			if (!$t->hasColumn('superseded')) $t->addColumn('superseded', 'boolean', ['notnull' => false, 'default' => false]);
		}
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Idempotent: erneuter Lauf setzt dieselben Werte; eigene Kunden-Workflows/-Checklisten bleiben unberührt.
		$workflows = $this->db->getQueryBuilder();
		$workflows->update('bestatter_workflows')
			->set('superseded', $workflows->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where($workflows->expr()->in('workflow_key', $workflows->createNamedParameter(self::BUNDLED_WORKFLOWS, IQueryBuilder::PARAM_STR_ARRAY)))
			->executeStatement();
		$checklists = $this->db->getQueryBuilder();
		$checklists->update('bestatter_checklist_templates')
			->set('active', $checklists->createNamedParameter(0))
			->where($checklists->expr()->in('template_key', $checklists->createNamedParameter(self::BUNDLED_CHECKLISTS, IQueryBuilder::PARAM_STR_ARRAY)))
			->executeStatement();
		// Stichtag der Einführung: Nur ab jetzt angelegte Fälle erhalten ihren Ablauf automatisch
		// (gleiches Format wie bestatter_cases.created_at: UTC, Y-m-d H:i:s).
		if ($this->config->getAppValue('bestatter', 'process_enabled_since', '') === '') {
			$this->config->setAppValue('bestatter', 'process_enabled_since', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'));
		}
		$output->info('Ablaufvorlagen eingerichtet; mitgelieferte Checklisten und Workflows sind durch den Standardablauf ersetzt.');
	}
}
