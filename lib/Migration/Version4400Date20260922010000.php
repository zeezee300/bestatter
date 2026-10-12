<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Kundenwunsch (2026-09-22, im Anschluss an den "Abmeldungen"-Test): Fallnummern sollen
 * fortlaufend vergeben und nie wiederverwendet werden, auch nach dem endgültigen Löschen eines
 * Falls (siehe CaseService::nextCaseNumber(), jetzt ein eigenständiger, dauerhafter Zähler in
 * bestatter_invoice_sequences unter dem Schlüssel "CASE:{Jahr}" statt eines MAX-Scans über
 * bestatter_cases). Diese Migration legt keine neue Tabelle an (bestatter_invoice_sequences
 * existiert bereits seit Version1800), sondern füllt den neuen Zähler einmalig aus dem
 * bisherigen Höchststand jedes bereits vorhandenen Jahrgangs vor - sonst würde der erste nach
 * diesem Update angelegte Fall wieder bei "-0001" beginnen und ggf. mit einem noch existierenden
 * Fall kollidieren (harter Unique-Index auf case_number) oder, schlimmer, genau die bereits
 * beobachtete Kollision mit einer erst kürzlich gelöschten Fallnummer erneut auslösen. Auf einer
 * frischen Installation (keine Fälle vorhanden) ist dieser Schritt ein reines No-Op.
 */
class Version4400Date20260922010000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->db->getQueryBuilder();
		$result = $select->select('case_number')->from('bestatter_cases')->executeQuery();
		$highestByYear = [];
		while ($row = $result->fetchAssociative()) {
			$number = (string)($row['case_number'] ?? '');
			if (preg_match('/^(\d{4})-(\d+)$/', $number, $match)) {
				$year = $match[1];
				$sequence = (int)$match[2];
				$highestByYear[$year] = max($highestByYear[$year] ?? 0, $sequence);
			}
		}
		$result->closeCursor();

		foreach ($highestByYear as $year => $highest) {
			$key = 'CASE:' . $year;
			// Idempotent bei einem erneuten Migrationslauf nach einem Abbruch: existiert der
			// Zähler bereits (z. B. weil zwischenzeitlich bereits ein neuer Fall angelegt wurde),
			// wird er nicht überschrieben, sondern nur auf mindestens den beobachteten
			// Höchststand angehoben - niemals abgesenkt.
			$existingQuery = $this->db->getQueryBuilder();
			$existingQuery->select('id', 'last_number')->from('bestatter_invoice_sequences')->where($existingQuery->expr()->eq('sequence_key', $existingQuery->createNamedParameter($key)));
			$existing = $existingQuery->executeQuery()->fetchAssociative();
			if ($existing) {
				if ((int)$existing['last_number'] < $highest) {
					$update = $this->db->getQueryBuilder();
					$update->update('bestatter_invoice_sequences')->set('last_number', $update->createNamedParameter($highest))->set('updated_at', $update->createNamedParameter(date('c')))->where($update->expr()->eq('id', $update->createNamedParameter((int)$existing['id'])))->executeStatement();
				}
				continue;
			}
			try {
				$insert = $this->db->getQueryBuilder();
				$insert->insert('bestatter_invoice_sequences')->values([
					'sequence_key' => $insert->createNamedParameter($key),
					'last_number' => $insert->createNamedParameter($highest),
					'updated_at' => $insert->createNamedParameter(date('c')),
				])->executeStatement();
			} catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
				// Zwischen der SELECT-Prüfung oben und diesem Insert wurde die Zeile bereits von
				// anderer Seite angelegt (z. B. durch die erste reguläre Fallanlage nach dem
				// Schema-Update, siehe CaseService::nextCaseNumber()) - nichts weiter zu tun, da
				// nextCaseNumber() diesen Zähler dann bereits korrekt weiterführt.
			}
		}
	}
}
