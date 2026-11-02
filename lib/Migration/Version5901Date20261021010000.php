<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.89.7 – Vorsorge (Schritte C und D).
 *
 * - bestatter_precare: Vertragsstand einer Vorsorge (1:1 zum Vorsorgefall): Status, Vertragsdatum, Preisart, Wiedervorlage,
 *   Einlösung (verknüpfter Sterbefall) und Ende. Die Wünsche stehen in den Stammdaten des Vorsorgefalls unter denselben
 *   Schlüsseln wie im Sterbefall.
 * - bestatter_precare_funding: Finanzierungen (Treuhand, Sterbegeldversicherung …) je Vorsorge, 0..n. provider_key,
 *   external_reference, external_status und last_checked_at sind für spätere Anbieter-Schnittstellen vorgesehen und bleiben
 *   bis dahin leer bzw. „MANUELL“.
 *
 * Eigene Migration neben 5900, damit Installationen mit dem Zwischenstand von Schritt A die Tabellen ebenfalls erhalten.
 */
class Version5901Date20261021010000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		if (!$schema->hasTable('bestatter_precare')) {
			$table = $schema->createTable('bestatter_precare');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$table->addColumn('case_id', 'integer', ['unsigned' => true]);
			$table->addColumn('precare_status', 'string', ['length' => 20]);
			$table->addColumn('contract_date', 'string', ['length' => 10, 'notnull' => false]);
			$table->addColumn('price_mode', 'string', ['length' => 20]);
			$table->addColumn('review_interval_months', 'integer', ['unsigned' => true, 'notnull' => false]);
			$table->addColumn('next_review_at', 'string', ['length' => 10, 'notnull' => false]);
			$table->addColumn('reviewed_at', 'string', ['length' => 10, 'notnull' => false]);
			$table->addColumn('redeemed_case_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			$table->addColumn('redeemed_at', 'string', ['length' => 40, 'notnull' => false]);
			$table->addColumn('ended_at', 'string', ['length' => 40, 'notnull' => false]);
			$table->addColumn('end_reason', 'string', ['length' => 500, 'notnull' => false]);
			$table->addColumn('created_at', 'string', ['length' => 40]);
			$table->addColumn('updated_at', 'string', ['length' => 40]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['case_id'], 'bestatter_precare_case');
			$table->addIndex(['precare_status', 'next_review_at'], 'bestatter_precare_status');
			$changed = true;
		}
		if (!$schema->hasTable('bestatter_precare_funding')) {
			$table = $schema->createTable('bestatter_precare_funding');
			$table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$table->addColumn('case_id', 'integer', ['unsigned' => true]);
			$table->addColumn('funding_type', 'string', ['length' => 20]);
			$table->addColumn('provider_name', 'string', ['length' => 200, 'notnull' => false]);
			$table->addColumn('provider_key', 'string', ['length' => 40]);
			$table->addColumn('contract_number', 'string', ['length' => 100, 'notnull' => false]);
			$table->addColumn('amount_cents', 'bigint', ['notnull' => false]);
			$table->addColumn('contracted_at', 'string', ['length' => 10, 'notnull' => false]);
			$table->addColumn('funding_status', 'string', ['length' => 20]);
			$table->addColumn('external_reference', 'string', ['length' => 200, 'notnull' => false]);
			$table->addColumn('external_status', 'string', ['length' => 100, 'notnull' => false]);
			$table->addColumn('last_checked_at', 'string', ['length' => 40, 'notnull' => false]);
			$table->addColumn('notes', 'text', ['notnull' => false]);
			$table->addColumn('created_at', 'string', ['length' => 40]);
			$table->addColumn('updated_at', 'string', ['length' => 40]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['case_id'], 'bestatter_precare_fund_case');
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
