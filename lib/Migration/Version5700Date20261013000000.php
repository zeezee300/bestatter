<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.88.0 – „Bestattungsauftrag und Vollmacht“: Bezeichnung und Beschreibung der Vorlagen BESTATTUNGSAUFTRAG und
 * BESTATTUNGSVOLLMACHT anpassen – nur, solange sie noch dem bisherigen Standard entsprechen. Die neuen Vorlagen
 * KOSTENVORANSCHLAG und WIDERRUFSBELEHRUNG legt ConfigurationService::ensureSeedData() wie üblich an.
 */
class Version5700Date20261013000000 extends SimpleMigrationStep {
	public const CHANGES = [
		'BESTATTUNGSAUFTRAG' => [
			'name' => ['Kostenvoranschlag / Bestattungsauftrag', 'Bestattungsauftrag und Vollmacht'],
			'description' => ['Dynamische DOCX-Vorlage mit Auftraggeber, Mobilfunknummer, detaillierten Leistungspositionen, Mehrwertsteuer, Gesamtsumme und Unterschriftsbereich.', 'Bestattungsauftrag mit Leistungs- und Preisaufstellung, auf den Bestattungsfall beschränkter Vollmacht, Erklärungen zum Leistungsbeginn und Unterschriften. Im Modus Kostenvoranschlag wird die Vorlage „Kostenvoranschlag“ verwendet.'],
		],
		'BESTATTUNGSVOLLMACHT' => [
			'description' => ['Unterschriftsfähige DOCX-Vorlage zur Bevollmächtigung des Bestattungsunternehmens.', 'Gesonderte Bestattungsvollmacht. Wird nur noch erzeugt, wenn die eingerichtete Auftragsvorlage keinen Vollmachtsteil enthält (eigene, ältere Auftragsvorlagen).'],
		],
	];

	public function __construct(private IDBConnection $db) {}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$this->db->tableExists('bestatter_document_templates')) return;
		foreach (self::CHANGES as $key => $columns) {
			foreach ($columns as $column => [$old, $new]) {
				$query = $this->db->getQueryBuilder();
				$query->update('bestatter_document_templates')->set($column, $query->createNamedParameter($new))
					->where($query->expr()->eq('template_key', $query->createNamedParameter($key)))
					->andWhere($query->expr()->eq($column, $query->createNamedParameter($old)))
					->executeStatement();
			}
		}
	}
}
