<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.90.0 – Buchhaltungsexport (DATEV-Buchungsstapel).
 *
 * - bestatter_acct_exports: Exportprotokoll je Lauf (Zeitraum, Anzahl, Summen Soll/Haben, Datei, SHA-256, Formatversion, Hinweise).
 *   Die erzeugte Datei liegt Base64-codiert in „content“, damit ein Wiederholungsexport genau dieselbe Datei liefert.
 * - bestatter_acct_export_items: welcher Beleg in welchem Export war (Exportstatus je Beleg, Idempotenz).
 * - bestatter_debtors: Debitorennummer je Rechnungsempfänger. Datensparsam: nur ein Schlüssel (SHA-256 der Empfängerdaten),
 *   kein Name.
 */
class Version6000Date20261101000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		if (!$schema->hasTable('bestatter_acct_exports')) {
			$t = $schema->createTable('bestatter_acct_exports');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('export_number', 'string', ['length' => 40]);
			$t->addColumn('export_kind', 'string', ['length' => 16, 'default' => 'PERIOD']);
			$t->addColumn('date_from', 'string', ['length' => 10]);
			$t->addColumn('date_to', 'string', ['length' => 10]);
			$t->addColumn('fiscal_year_start', 'string', ['length' => 10]);
			$t->addColumn('invoice_count', 'integer', ['default' => 0]);
			$t->addColumn('line_count', 'integer', ['default' => 0]);
			$t->addColumn('debit_cents', 'bigint', ['default' => 0]);
			$t->addColumn('credit_cents', 'bigint', ['default' => 0]);
			$t->addColumn('file_name', 'string', ['length' => 200]);
			$t->addColumn('mime_type', 'string', ['length' => 60, 'default' => 'text/csv']);
			$t->addColumn('sha256', 'string', ['length' => 64]);
			$t->addColumn('format_version', 'string', ['length' => 20]);
			$t->addColumn('content', 'text', ['notnull' => false]);
			$t->addColumn('file_path', 'string', ['length' => 500, 'notnull' => false]);
			$t->addColumn('settings_snapshot', 'text', ['notnull' => false]);
			$t->addColumn('messages', 'text', ['notnull' => false]);
			$t->addColumn('reason', 'text', ['notnull' => false]);
			$t->addColumn('created_by', 'string', ['length' => 64]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['export_number'], 'bestatter_acct_export_no');
			$changed = true;
		}
		if (!$schema->hasTable('bestatter_acct_export_items')) {
			$t = $schema->createTable('bestatter_acct_export_items');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('export_id', 'integer', ['unsigned' => true]);
			$t->addColumn('invoice_id', 'integer', ['unsigned' => true]);
			$t->addColumn('invoice_number', 'string', ['length' => 80]);
			$t->addColumn('debtor_number', 'integer', ['notnull' => false]);
			$t->addColumn('gross_cents', 'integer', ['default' => 0]);
			$t->addColumn('line_count', 'integer', ['default' => 0]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['export_id', 'invoice_id'], 'bestatter_acct_item_unique');
			$t->addIndex(['invoice_id'], 'bestatter_acct_item_invoice');
			$changed = true;
		}
		if (!$schema->hasTable('bestatter_debtors')) {
			$t = $schema->createTable('bestatter_debtors');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('debtor_number', 'integer', ['unsigned' => true]);
			$t->addColumn('recipient_key', 'string', ['length' => 64]);
			$t->addColumn('first_invoice', 'string', ['length' => 80, 'notnull' => false]);
			$t->addColumn('created_by', 'string', ['length' => 64]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['debtor_number'], 'bestatter_debtor_number');
			$t->addUniqueIndex(['recipient_key'], 'bestatter_debtor_key');
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
