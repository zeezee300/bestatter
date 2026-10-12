<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;

/**
 * 0.75.2 – Bereinigung von Altlasten aus der Nextcloud-Anbindung (occ bestatter:groupware-repair):
 *  1. Doppelte Termine/Aufgaben: Bis 0.75.1 las der Abgleich die Kopie eines Termins im Kalender einer weiteren
 *     zuständigen Person als eigenen Termin ein („Spiegel“). Daraus entstanden zusätzlich doppelte Folgeaufgaben.
 *     Entfernt werden der Spiegel und seine Folgeaufgaben samt deren Kalendereinträgen; die Kopie im Kalender der
 *     Kollegin bleibt bestehen (sie gehört zum Original).
 *  2. Kontaktadressen: Über „Neu“ angelegte Kontakte trugen die ganze Anschrift maskiert im Postfach-Feld.
 *     Die Adresse wird in Straße, Ort, PLZ und Land aufgeteilt.
 *  3. Verlorene App-Daten: Eine Änderung in Nextcloud (z. B. Abhaken in Nextcloud Tasks) ersetzte bis 0.75.1 die
 *     gesamten App-Daten eines Eintrags. Bei Ablaufaufgaben (Verknüpfung über bestatter_process_runs) und Terminen
 *     mit Kopien werden die fehlenden Felder aus dem letzten vollständigen Stand im Audit-Log wiederhergestellt;
 *     ersatzweise die Ablaufzuordnung aus dem Ablaufschritt.
 */
class GroupwareRepairService {
	public function __construct(
		private IDBConnection $db,
		private AuditService $audit,
		private AppCalendarObjects $calendar,
		private RecordService $records,
	) {}

	/** @return array{mirrors:list<array<string,mixed>>,contacts:list<array<string,mixed>>,lostData:list<array<string,mixed>>} */
	public function preview(): array {
		return ['mirrors' => $this->mirrors(), 'contacts' => $this->brokenContacts(), 'lostData' => array_map(static fn(array $item): array => array_diff_key($item, ['restore' => true]), $this->lostData())];
	}

	/** @return array{mirrorsDeleted:int,followUpsDeleted:int,processRunsDeleted:int,calendar:array<string,mixed>,contactsRepaired:int,contactErrors:list<string>,dataRestored:int} */
	public function execute(): array {
		$result = ['mirrorsDeleted' => 0, 'followUpsDeleted' => 0, 'processRunsDeleted' => 0, 'calendar' => ['planned' => 0, 'deleted' => 0, 'missing' => 0, 'errors' => []], 'contactsRepaired' => 0, 'contactErrors' => [], 'dataRestored' => 0];
		foreach ($this->mirrors() as $mirror) {
			$followUpIds = array_map(static fn(array $task): int => (int)$task['id'], $mirror['followUps']);
			if ($followUpIds !== []) {
				$calendar = $this->calendar->delete($this->calendar->targetsForRecordIds($followUpIds));
				foreach (['planned', 'deleted', 'missing'] as $key) $result['calendar'][$key] += $calendar[$key];
				$result['calendar']['errors'] = array_merge($result['calendar']['errors'], $calendar['errors']);
			}
			$this->db->beginTransaction();
			try {
				foreach ($followUpIds as $id) { $this->deleteRecord($id, 'Doppelte Folgeaufgabe aus Terminkopie entfernt (Bereinigung 0.75.2)'); $result['followUpsDeleted']++; }
				$delete = $this->db->getQueryBuilder();
				$result['processRunsDeleted'] += $delete->delete('bestatter_process_runs')
					->where($delete->expr()->eq('context_key', $delete->createNamedParameter('schedule:' . $mirror['id'])))->executeStatement();
				$this->deleteRecord((int)$mirror['id'], 'Doppelter Eintrag aus der Kopie im Kalender von ' . $mirror['ownerUid'] . ' entfernt; Original #' . $mirror['originalId'] . ' (Bereinigung 0.75.2)');
				$result['mirrorsDeleted']++;
				$this->db->commit();
			} catch (\Throwable $error) {
				$this->db->rollBack();
				throw $error;
			}
		}
		$contacts = $this->brokenContacts(true);
		$backend = $contacts !== [] ? $this->cardBackend() : null;
		foreach ($contacts as $contact) {
			try {
				$backend?->updateCard((int)$contact['addressBookId'], (string)$contact['uri'], (string)$contact['repaired']);
				$result['contactsRepaired']++;
			} catch (\Throwable $error) {
				$result['contactErrors'][] = $contact['name'] . ': ' . $error->getMessage();
			}
		}
		foreach ($this->lostData() as $item) {
			$record = $this->records->get((int)$item['id'], (string)$item['ownerUid']);
			$data = is_array($record['data'] ?? null) ? $record['data'] : [];
			$data = array_replace($item['restore'], $data);
			$this->records->update((int)$record['id'], (string)$record['title'], (string)($record['date'] ?? ''), (string)($record['status'] ?? 'OFFEN'), json_encode($data, JSON_THROW_ON_ERROR), (int)($record['caseId'] ?? 0), (string)$item['ownerUid']);
			$result['dataRestored']++;
		}
		if ($result['mirrorsDeleted'] > 0 || $result['contactsRepaired'] > 0 || $result['dataRestored'] > 0) $this->audit->logSystem('groupware', 'REPAIRED', array_diff_key($result, ['contactErrors' => true]));
		return $result;
	}

	/** Spiegel = Datensatz mit derselben Nextcloud-UID wie ein älteres Original, dessen Zuständige/Kopie er ist. */
	private function mirrors(): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('id', 'record_type', 'owner_uid', 'case_id', 'title', 'status', 'nextcloud_uid', 'payload')->from('bestatter_records')
			->where($query->expr()->in('record_type', $query->createNamedParameter(['task', 'schedule'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($query->expr()->isNotNull('nextcloud_uid'))
			->andWhere($query->expr()->neq('nextcloud_uid', $query->createNamedParameter('')))
			->orderBy('id', 'ASC')
			->executeQuery()->fetchAllAssociative();
		$groups = [];
		foreach ($rows as $row) $groups[$row['record_type'] . '|' . $row['nextcloud_uid']][] = $row;
		$mirrors = [];
		foreach ($groups as $group) {
			if (count($group) < 2) continue;
			$original = $group[0];
			$data = json_decode((string)$original['payload'], true);
			if (!is_array($data)) continue;
			foreach (array_slice($group, 1) as $row) {
				if ((string)$row['owner_uid'] === (string)$original['owner_uid'] || !RecordService::isCopyOwner($data, (string)$row['owner_uid'])) continue;
				$mirrors[] = [
					'id' => (int)$row['id'], 'type' => (string)$row['record_type'], 'title' => (string)$row['title'], 'status' => (string)$row['status'],
					'caseId' => (int)($row['case_id'] ?? 0), 'ownerUid' => (string)$row['owner_uid'],
					'originalId' => (int)$original['id'], 'originalOwnerUid' => (string)$original['owner_uid'],
					'followUps' => $row['record_type'] === 'schedule' ? $this->followUps((int)$row['id']) : [],
				];
			}
		}
		return $mirrors;
	}

	/** Aufgaben, die der Ablauf aus dem Spiegeltermin erzeugt hat (sourceScheduleId bzw. Kontext schedule:ID). */
	private function followUps(int $scheduleId): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('id', 'owner_uid', 'title', 'status', 'payload')->from('bestatter_records')
			->where($query->expr()->eq('record_type', $query->createNamedParameter('task')))
			->andWhere($query->expr()->orX(
				$query->expr()->like('payload', $query->createNamedParameter('%"sourceScheduleId":' . $scheduleId . '%')),
				$query->expr()->like('payload', $query->createNamedParameter('%"schedule:' . $scheduleId . '"%')),
			))
			->executeQuery()->fetchAllAssociative();
		$result = [];
		foreach ($rows as $row) {
			$data = json_decode((string)$row['payload'], true);
			if (!is_array($data)) continue;
			$linked = (int)($data['sourceScheduleId'] ?? 0) === $scheduleId
				|| (int)($data['link']['sourceScheduleId'] ?? 0) === $scheduleId
				|| (string)($data['processContext'] ?? '') === 'schedule:' . $scheduleId;
			if ($linked) $result[] = ['id' => (int)$row['id'], 'title' => (string)$row['title'], 'status' => (string)$row['status'], 'ownerUid' => (string)$row['owner_uid']];
		}
		return $result;
	}

	/**
	 * Ablaufaufgaben ohne processStepId (trotz Zuordnung in bestatter_process_runs) und Termine mit weiteren Zuständigen
	 * ohne gespeicherte Kopien. „restore“ enthält nur Felder, die im aktuellen Stand fehlen.
	 * @return list<array{id:int,type:string,ownerUid:string,keys:list<string>,source:string,restore:array<string,mixed>}>
	 */
	private function lostData(): array {
		$candidates = [];
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('t.id', 't.owner_uid', 't.payload', 'r.id AS run_id', 'r.step_key', 'r.context_key', 's.id AS step_id', 's.phase', 's.role', 's.required', 'p.template_key')
			->from('bestatter_process_runs', 'r')
			->innerJoin('r', 'bestatter_records', 't', $query->expr()->eq('t.id', 'r.record_id'))
			->leftJoin('r', 'bestatter_process_steps', 's', $query->expr()->eq('s.id', 'r.step_id'))
			->leftJoin('s', 'bestatter_process_templates', 'p', $query->expr()->eq('p.id', 's.template_id'))
			->where($query->expr()->eq('t.record_type', $query->createNamedParameter('task')))
			->andWhere($query->expr()->notLike('t.payload', $query->createNamedParameter('%"processStepId"%')))
			->executeQuery()->fetchAllAssociative();
		foreach ($rows as $row) {
			$fallback = array_filter([
				'processStepId' => (int)($row['step_id'] ?? 0), 'processStepKey' => (string)($row['step_key'] ?? ''), 'processTemplateKey' => (string)($row['template_key'] ?? ''),
				'processPhase' => (int)($row['phase'] ?? 0), 'processRequired' => (bool)($row['required'] ?? false), 'processRunId' => (int)$row['run_id'],
				'processContext' => (string)($row['context_key'] ?? ''), 'assigneeRole' => (string)($row['role'] ?? ''),
			], static fn(mixed $value): bool => $value !== '' && $value !== 0 && $value !== false);
			$candidates[] = ['id' => (int)$row['id'], 'type' => 'task', 'ownerUid' => (string)$row['owner_uid'], 'payload' => (string)$row['payload'], 'marker' => 'processStepId', 'fallback' => $fallback];
		}
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('id', 'owner_uid', 'payload')->from('bestatter_records')
			->where($query->expr()->eq('record_type', $query->createNamedParameter('schedule')))
			->andWhere($query->expr()->notLike('payload', $query->createNamedParameter('%"nextcloudCopies"%')))
			->executeQuery()->fetchAllAssociative();
		foreach ($rows as $row) {
			$data = json_decode((string)$row['payload'], true);
			if (!is_array($data)) continue;
			$others = array_diff(array_map('strval', (array)($data['assigneeUids'] ?? [])), [(string)$row['owner_uid']]);
			if ($others !== []) $candidates[] = ['id' => (int)$row['id'], 'type' => 'schedule', 'ownerUid' => (string)$row['owner_uid'], 'payload' => (string)$row['payload'], 'marker' => 'nextcloudCopies', 'fallback' => []];
		}
		$result = [];
		foreach ($candidates as $candidate) {
			$current = json_decode($candidate['payload'], true);
			if (!is_array($current)) continue;
			$snapshot = $this->auditSnapshot($candidate['type'], $candidate['id'], $candidate['marker']);
			$source = $snapshot !== null ? 'Audit-Log' : 'Ablaufschritt';
			// Leere Rollen-Zuständige stammen aus dem Import (Nextcloud kennt das Feld bei Aufgaben nicht) – wie fehlend behandeln.
			if ($candidate['type'] === 'task' && ($current['assigneeUids'] ?? null) === [] && (array)($snapshot['assigneeUids'] ?? []) !== []) unset($current['assigneeUids']);
			$restore = array_diff_key(array_replace($candidate['fallback'], $snapshot ?? []), $current, ['nextcloud' => true, 'updatedAt' => true, 'updatedBy' => true]);
			if ($restore === []) continue;
			$result[] = ['id' => $candidate['id'], 'type' => $candidate['type'], 'ownerUid' => $candidate['ownerUid'], 'keys' => array_keys($restore), 'source' => $source, 'restore' => $restore];
		}
		return $result;
	}

	/** Daten des jüngsten Audit-Stands, der das Merkmal (z. B. processStepId) noch enthielt. */
	private function auditSnapshot(string $type, int $id, string $marker): ?array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('after_data')->from('bestatter_audit_log')
			->where($query->expr()->eq('object_type', $query->createNamedParameter(strtoupper($type))))
			->andWhere($query->expr()->eq('object_id', $query->createNamedParameter($id)))
			->andWhere($query->expr()->like('after_data', $query->createNamedParameter('%"' . $marker . '"%')))
			->orderBy('id', 'DESC')->setMaxResults(1)
			->executeQuery()->fetchAllAssociative();
		foreach ($rows as $row) {
			$after = json_decode((string)$row['after_data'], true);
			if (is_array($after) && is_array($after['data'] ?? null)) return $after['data'];
		}
		return null;
	}

	private function deleteRecord(int $id, string $reason): void {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('*')->from('bestatter_records')->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeQuery()->fetchAssociative();
		if ($row === false) return;
		foreach (['bestatter_record_assignees' => 'record_id', 'bestatter_schedule_history' => 'record_id', 'bestatter_action_runs' => 'source_record_id', 'bestatter_records' => 'id'] as $table => $column) {
			$delete = $this->db->getQueryBuilder();
			$delete->delete($table)->where($delete->expr()->eq($column, $delete->createNamedParameter($id)))->executeStatement();
		}
		$this->audit->log((int)($row['case_id'] ?? 0), (string)$row['record_type'], $id, 'DELETED', ['title' => (string)$row['title'], 'status' => (string)$row['status'], 'ownerUid' => (string)$row['owner_uid']], ['reason' => $reason]);
	}

	/**
	 * Karten mit einer ADR, deren gesamter Wert maskiert in einem Teilfeld steht (\;\;Straße\;Ort…).
	 * @return list<array<string,mixed>>
	 */
	private function brokenContacts(bool $withData = false): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('c.addressbookid', 'c.uri', 'c.carddata', 'a.displayname', 'a.principaluri')
			->from('cards', 'c')
			->innerJoin('c', 'addressbooks', 'a', $query->expr()->eq('a.id', 'c.addressbookid'))
			->where($query->expr()->like('c.carddata', $query->createNamedParameter('%ADR%')))
			->andWhere($query->expr()->like('c.carddata', $query->createNamedParameter('%' . $this->db->escapeLikeParameter('\;') . '%')))
			->executeQuery()->fetchAllAssociative();
		$result = [];
		foreach ($rows as $row) {
			$data = is_resource($row['carddata']) ? (string)stream_get_contents($row['carddata']) : (string)$row['carddata'];
			$repaired = self::repairVcard($data);
			if ($repaired === null) continue;
			$entry = ['addressBookId' => (int)$row['addressbookid'], 'addressBook' => (string)$row['displayname'], 'uri' => (string)$row['uri'], 'name' => self::vcardName($data)];
			if ($withData) $entry['repaired'] = $repaired;
			$result[] = $entry;
		}
		return $result;
	}

	/** Liefert die reparierte vCard oder null, wenn keine fehlerhafte Adresse enthalten ist. */
	public static function repairVcard(string $vcard): ?string {
		$unfolded = preg_replace("/\r?\n[ \t]/", '', $vcard) ?? $vcard;
		$lines = preg_split("/\r?\n/", $unfolded) ?: [];
		$changed = false;
		foreach ($lines as $index => $line) {
			if (!preg_match('/^((?:[A-Za-z0-9-]+\.)?ADR(?:;[^:]*)?):(.*)$/i', $line, $match)) continue;
			$value = $match[2];
			$components = preg_split('/(?<!\\\\);/', $value) ?: [];
			if (count($components) !== 1 || !str_contains($value, '\;')) continue;
			$lines[$index] = $match[1] . ':' . str_replace('\;', ';', $value);
			$changed = true;
		}
		return $changed ? implode("\r\n", $lines) : null;
	}

	private static function vcardName(string $vcard): string {
		return preg_match('/(?:^|\n)FN(?:;[^:]*)?:([^\r\n]*)/', $vcard, $match) ? str_replace(['\\,', '\\;', '\\\\'], [',', ';', '\\'], $match[1]) : 'Kontakt';
	}

	private function cardBackend(): object {
		$class = '\\OCA\\DAV\\CardDAV\\CardDavBackend';
		if (!class_exists($class)) throw new \RuntimeException('Das Nextcloud-CardDAV-Backend ist nicht verfügbar.');
		return Server::get($class);
	}
}
