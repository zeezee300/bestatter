<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.90.2 – Kennung der Niederlassung in Fallnummern (z. B. „HB“ oder „01“ → „HB-2026-0001“).
 *
 * Leer bedeutet: keine eigene Kennung, es gilt der Niederlassungsschlüssel, sofern er kurz genug ist. Aufbau und Zählweise
 * der Fallnummern stehen in der App-Konfiguration „case_number_settings“; ohne Einstellung bleibt alles wie bisher.
 */
class Version6100Date20261104000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('bestatter_branches')) {
			return null;
		}
		$t = $schema->getTable('bestatter_branches');
		if ($t->hasColumn('number_code')) {
			return null;
		}
		$t->addColumn('number_code', Types::STRING, ['length' => 10, 'notnull' => false]);
		return $schema;
	}
}
