<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.70.0 – Stornorechnung und Gutschrift (Rechnungskorrektur) als eigene Belege mit Bezug zur Rechnung.
 * - bestatter_invoices.document_kind: INVOICE (bisher alle), CANCELLATION (Stornorechnung), CREDIT_NOTE (Gutschrift)
 * - reference_invoice_id / cancelled_by_id: Bezug Korrekturbeleg ↔ Rechnung
 * - correction_reason, release_quantities (Gutschrift: gutgeschriebene Mengen wieder abrechenbar)
 * - bestatter_invoice_items.reference_item_id: Position der ursprünglichen Rechnung
 * - bestatter_invoice_settings.correction_prefix: Nummernkreis-Präfix der Korrekturbelege (Standard GS)
 */
class Version5000Date20261001000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		if ($schema->hasTable('bestatter_invoices')) {
			$t = $schema->getTable('bestatter_invoices');
			if (!$t->hasColumn('document_kind')) { $t->addColumn('document_kind', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'INVOICE']); $changed = true; }
			if (!$t->hasColumn('reference_invoice_id')) { $t->addColumn('reference_invoice_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true]); $changed = true; }
			if (!$t->hasColumn('cancelled_by_id')) { $t->addColumn('cancelled_by_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true]); $changed = true; }
			if (!$t->hasColumn('correction_reason')) { $t->addColumn('correction_reason', Types::TEXT, ['notnull' => false]); $changed = true; }
			if (!$t->hasColumn('release_quantities')) { $t->addColumn('release_quantities', Types::BOOLEAN, ['notnull' => false, 'default' => false]); $changed = true; }
			if (!$t->hasIndex('bestatter_invoice_reference')) { $t->addIndex(['reference_invoice_id'], 'bestatter_invoice_reference'); $changed = true; }
		}
		if ($schema->hasTable('bestatter_invoice_items')) {
			$t = $schema->getTable('bestatter_invoice_items');
			if (!$t->hasColumn('reference_item_id')) { $t->addColumn('reference_item_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true]); $changed = true; }
		}
		if ($schema->hasTable('bestatter_invoice_settings')) {
			$t = $schema->getTable('bestatter_invoice_settings');
			if (!$t->hasColumn('correction_prefix')) { $t->addColumn('correction_prefix', Types::STRING, ['length' => 20, 'notnull' => false, 'default' => 'GS']); $changed = true; }
		}
		return $changed ? $schema : null;
	}
}
