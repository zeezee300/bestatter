<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCA\Bestatter\Service\LabelRules;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.77.0 – Notiz je Marker.
 *
 * bestatter_case_labels  note / note_by / note_at: interne Notiz zum gesetzten Marker (höchstens 500 Zeichen).
 * bestatter_labels       note_mode (NONE, OPTIONAL, REQUIRED) und note_hint (Platzhaltertext im Notizfeld).
 *
 * Mitgelieferte Marker einer 0.76-Installation erhalten Notizart und Platzhaltertext aus resources/labels.json,
 * aber nur, solange noch kein Platzhaltertext gesetzt ist (eigene Anpassungen bleiben unberührt).
 */
class Version5500Date20261006000000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		if ($schema->hasTable('bestatter_case_labels')) {
			$t = $schema->getTable('bestatter_case_labels');
			if (!$t->hasColumn('note')) { $t->addColumn('note', Types::TEXT, ['notnull' => false]); $changed = true; }
			if (!$t->hasColumn('note_by')) { $t->addColumn('note_by', Types::STRING, ['length' => 64, 'notnull' => false]); $changed = true; }
			if (!$t->hasColumn('note_at')) { $t->addColumn('note_at', Types::STRING, ['length' => 40, 'notnull' => false]); $changed = true; }
		}
		if ($schema->hasTable('bestatter_labels')) {
			$t = $schema->getTable('bestatter_labels');
			if (!$t->hasColumn('note_mode')) { $t->addColumn('note_mode', Types::STRING, ['length' => 10, 'default' => 'OPTIONAL']); $changed = true; }
			if (!$t->hasColumn('note_hint')) { $t->addColumn('note_hint', Types::STRING, ['length' => 200, 'notnull' => false]); $changed = true; }
		}
		return $changed ? $schema : null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$bundle = json_decode((string)@file_get_contents(LabelRules::BUNDLE_FILE), true);
		if (!is_array($bundle)) return;
		$updated = 0;
		foreach ((array)($bundle['labels'] ?? []) as $label) {
			$mode = strtoupper((string)($label['noteMode'] ?? 'OPTIONAL'));
			$hint = trim((string)($label['noteHint'] ?? ''));
			if (!isset(LabelRules::NOTE_MODES[$mode])) continue;
			$query = $this->db->getQueryBuilder();
			$updated += $query->update('bestatter_labels')
				->set('note_mode', $query->createNamedParameter($mode))
				->set('note_hint', $query->createNamedParameter($hint !== '' ? $hint : null))
				->where($query->expr()->eq('label_key', $query->createNamedParameter(strtoupper((string)($label['key'] ?? '')))))
				->andWhere($query->expr()->eq('origin', $query->createNamedParameter('BUNDLED')))
				->andWhere($query->expr()->isNull('note_hint'))
				->executeStatement();
		}
		if ($updated > 0) $output->info($updated . ' mitgelieferte Marker: Notizart und Platzhaltertext übernommen.');
	}
}
