<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Befund #11 (Review 0.65.1): OperationalService::recordsForUser() und
 * RecordService::list()/get() ermittelten Mehrfachzuweisungen (mehrere Mitarbeitende an einem
 * Termin/einer Aufgabe) bislang über `LIKE '%"uid"%'` auf der Textspalte
 * `bestatter_records.payload` - fachlich richtig, aber nicht indexierbar: die Datenbank musste
 * dafür bei jedem Aufruf den Payload-Text aller Zeilen durchsuchen (Vollscan).
 *
 * Diese Migration führt eine eigene, indexierte Zuordnungstabelle ein (record_id, uid; eindeutig
 * je Kombination) und befüllt sie rückwirkend aus den bereits vorhandenen Datensätzen - sowohl
 * aus der einzelnen `assignee_uid`-Spalte als auch aus einem etwaigen "assigneeUids"-Array im
 * JSON-Payload (z. B. bei Terminen mit mehreren Mitarbeitenden, siehe SchedulingService). Der
 * Payload selbst bleibt unverändert und weiterhin die alleinige Quelle der Wahrheit -
 * bestatter_record_assignees ist ausschließlich ein Lese-Index. Ab dieser Version halten
 * RecordService::create()/update()/delete()/removeImportedCalendarRecords() die Tabelle bei
 * jeder Änderung synchron.
 */
class Version4200Date20260921000000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('bestatter_record_assignees')) {
			$t = $schema->createTable('bestatter_record_assignees');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('record_id', 'integer', ['unsigned' => true]);
			$t->addColumn('uid', 'string', ['length' => 64]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['record_id', 'uid'], 'bestatter_record_assignee_uniq');
			$t->addIndex(['uid'], 'bestatter_record_assignee_uid');
		}
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Auf einer frischen Installation liefert die folgende SELECT-Schleife einfach keine
		// Zeilen (bestatter_records ist leer) - kein gesonderter Guard nötig.

		// Bereits indexierte Kombinationen einmalig laden, damit der Backfill bei einem
		// erneuten Migrationslauf nach einem Abbruch idempotent bleibt (keine Duplikate,
		// keine Unique-Constraint-Verletzung).
		$existingQuery = $this->db->getQueryBuilder();
		$existing = [];
		foreach ($existingQuery->select('record_id', 'uid')->from('bestatter_record_assignees')->executeQuery()->fetchAllAssociative() as $row) {
			$existing[$row['record_id'] . '|' . $row['uid']] = true;
		}

		$select = $this->db->getQueryBuilder();
		$result = $select->select('id', 'assignee_uid', 'payload')->from('bestatter_records')->executeQuery();
		$toInsert = [];
		while ($row = $result->fetchAssociative()) {
			$recordId = (int)$row['id'];
			$uids = [];
			$directUid = trim((string)($row['assignee_uid'] ?? ''));
			if ($directUid !== '') $uids[] = $directUid;
			$payload = json_decode((string)($row['payload'] ?? ''), true);
			if (is_array($payload)) {
				$payloadUid = trim((string)($payload['assigneeUid'] ?? ''));
				if ($payloadUid !== '') $uids[] = $payloadUid;
				foreach ((array)($payload['assigneeUids'] ?? []) as $candidate) {
					$candidate = trim((string)$candidate);
					if ($candidate !== '') $uids[] = $candidate;
				}
			}
			foreach (array_unique($uids) as $uid) {
				$key = $recordId . '|' . $uid;
				if (isset($existing[$key])) continue;
				$existing[$key] = true;
				$toInsert[] = [$recordId, $uid];
			}
		}
		$result->closeCursor();

		foreach ($toInsert as [$recordId, $uid]) {
			$insert = $this->db->getQueryBuilder();
			$insert->insert('bestatter_record_assignees')->values([
				'record_id' => $insert->createNamedParameter($recordId),
				'uid' => $insert->createNamedParameter($uid),
			])->executeStatement();
		}
	}
}
