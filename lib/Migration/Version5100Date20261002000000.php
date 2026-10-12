<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.71.0 – Schnellerfassung: Texterkennung wahlweise über Paperless oder die Nextcloud-Texterkennung
 * (TaskProcessing „core:image2text:ocr“).
 * - bestatter_capture_imports.ocr_engine: PAPERLESS | NEXTCLOUD (leer = Altbestand/Paperless)
 * - bestatter_capture_imports.ocr_task_id: Nextcloud-TaskProcessing-Auftrag
 */
class Version5100Date20261002000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('bestatter_capture_imports')) return null;
		$t = $schema->getTable('bestatter_capture_imports');
		$changed = false;
		if (!$t->hasColumn('ocr_engine')) { $t->addColumn('ocr_engine', Types::STRING, ['length' => 16, 'notnull' => false]); $changed = true; }
		if (!$t->hasColumn('ocr_task_id')) { $t->addColumn('ocr_task_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]); $changed = true; }
		return $changed ? $schema : null;
	}
}
