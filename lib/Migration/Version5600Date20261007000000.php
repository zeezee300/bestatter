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
 * 0.80.0 – Dokumentarten (OP-058): fachliche Art eines eingehenden Schriftstücks (z. B. Sterbeurkunde,
 * Rückmeldung einer Abmeldestelle). Je Art: Paperless-Dokumenttyp, Ablageordner (Schlüssel des Ordnerplans),
 * Kategorie der Dokumentliste, Wirkung im Fall (nur ablegen, Eingangsrechnung, Abmeldung bestätigen,
 * unterschriebener Vertrag) und optional die Frage nach einer Anzahl (z. B. Ausfertigungen).
 * Mitgelieferte Arten (resources/document-kinds.json) entstehen beim ersten Aufruf; Wahrheitswerte als SMALLINT.
 */
class Version5600Date20261007000000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bestatter_document_kinds')) return null;
		$t = $schema->createTable('bestatter_document_kinds');
		$t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'unsigned' => true]);
		$t->addColumn('kind_key', Types::STRING, ['length' => 60]);
		$t->addColumn('name', Types::STRING, ['length' => 100]);
		$t->addColumn('description', Types::STRING, ['length' => 500, 'notnull' => false]);
		$t->addColumn('hint', Types::STRING, ['length' => 500, 'notnull' => false]);
		$t->addColumn('paperless_type_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
		$t->addColumn('folder_key', Types::STRING, ['length' => 40]);
		$t->addColumn('category', Types::STRING, ['length' => 40]);
		$t->addColumn('effect', Types::STRING, ['length' => 30, 'default' => 'FILE']);
		$t->addColumn('classification', Types::STRING, ['length' => 40, 'notnull' => false]);
		$t->addColumn('count_label', Types::STRING, ['length' => 60, 'notnull' => false]);
		$t->addColumn('sort_order', Types::INTEGER, ['default' => 0]);
		$t->addColumn('active', Types::SMALLINT, ['default' => 0]);
		$t->addColumn('origin', Types::STRING, ['length' => 20, 'default' => 'CUSTOM']);
		$t->addColumn('created_at', Types::STRING, ['length' => 40]);
		$t->addColumn('updated_at', Types::STRING, ['length' => 40]);
		$t->setPrimaryKey(['id']);
		$t->addUniqueIndex(['kind_key'], 'bestatter_doc_kind_key');
		return $schema;
	}

	/**
	 * Mitgelieferter Standardablauf: „Rückmeldung Standesamt prüfen, Sterbeurkunden abholen“ erledigt sich jetzt,
	 * sobald eine Sterbeurkunde abgelegt ist – nur wenn der Schritt noch unverändert „von Hand abhaken“ hat.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('s.id', 's.complete_json')->from('bestatter_process_steps', 's')
			->innerJoin('s', 'bestatter_process_templates', 't', $query->expr()->eq('t.id', 's.template_id'))
			->where($query->expr()->eq('t.origin', $query->createNamedParameter('BUNDLED')))
			->andWhere($query->expr()->eq('s.step_key', $query->createNamedParameter('STERBEURKUNDEN')))
			->executeQuery()->fetchAllAssociative();
		$updated = 0;
		foreach ($rows as $row) {
			$complete = json_decode((string)$row['complete_json'], true);
			if (!is_array($complete) || strtoupper((string)($complete['type'] ?? 'NONE')) !== 'NONE') continue;
			$complete = ['type' => 'DOCUMENT_RECEIVED', 'templateKey' => '', 'presetKey' => '', 'field' => '', 'value' => '', 'status' => '', 'label' => '', 'documentKind' => 'STERBEURKUNDE'];
			$update = $this->db->getQueryBuilder();
			$updated += $update->update('bestatter_process_steps')->set('complete_json', $update->createNamedParameter(json_encode($complete, JSON_THROW_ON_ERROR)))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'])))->executeStatement();
		}
		if ($updated > 0) $output->info('Standardablauf: Schritt „Sterbeurkunden abholen“ erledigt sich bei eingegangener Sterbeurkunde.');
	}
}
