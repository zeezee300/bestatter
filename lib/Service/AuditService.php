<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IDBConnection;
use OCP\IUserSession;

/** Append-only audit log for every case-related business operation. */
class AuditService {
	public function __construct(
		private IDBConnection $db,
		private IUserSession $userSession,
		private BestatterActivityService $activity,
	) {}

	public function log(int $caseId, string $objectType, ?int $objectId, string $action, mixed $before = null, mixed $after = null): void {
		if ($caseId <= 0) return;
		$this->insert($caseId, $objectType, $objectId, $action, $before, $after);
	}

	public function logSystem(string $objectType, string $action, mixed $after = null): void {
		$this->insert(0, $objectType, null, $action, null, $after);
	}

	private function insert(int $caseId, string $objectType, ?int $objectId, string $action, mixed $before, mixed $after): void {
		$objectType = strtoupper($objectType);
		$action = strtoupper($action);
		$createdAt = date('c');
		$userUid = $this->uid();
		$before = $this->minimize($before);
		$after = $this->minimize($after);
		$previousHash = $this->lastHash();
		$eventHash = hash('sha256', implode('|', [$previousHash, $caseId, $objectType, $objectId ?? '', $action, $userUid, $createdAt]));
		$query = $this->db->getQueryBuilder();
		$query->insert('bestatter_audit_log')->values([
			'case_id' => $query->createNamedParameter($caseId),
			'object_type' => $query->createNamedParameter($objectType),
			'object_id' => $query->createNamedParameter($objectId),
			'action' => $query->createNamedParameter($action),
			'before_data' => $query->createNamedParameter($before === null ? null : json_encode($before, JSON_THROW_ON_ERROR)),
			'after_data' => $query->createNamedParameter($after === null ? null : json_encode($after, JSON_THROW_ON_ERROR)),
			'user_uid' => $query->createNamedParameter($userUid),
			'created_at' => $query->createNamedParameter($createdAt),
			'previous_hash' => $query->createNamedParameter($previousHash !== '' ? $previousHash : null),
			'event_hash' => $query->createNamedParameter($eventHash),
		])->executeStatement();
		$this->activity->publishAuditEvent($caseId, $objectType, $objectId, $action, $before, $after);
	}

	public function verifyIntegrity(): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('id', 'case_id', 'object_type', 'object_id', 'action', 'user_uid', 'created_at', 'previous_hash', 'event_hash')
			->from('bestatter_audit_log')->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		$previous = ''; $broken = [];
		foreach ($rows as $row) {
			$stored = (string)($row['event_hash'] ?? '');
			if ($stored === '') { $previous = ''; continue; } // Altbestand beginnt vor Einführung der Verkettung.
			$expectedPrevious = (string)($row['previous_hash'] ?? '');
			$expected = hash('sha256', implode('|', [$expectedPrevious, (int)$row['case_id'], (string)$row['object_type'], $row['object_id'] ?? '', (string)$row['action'], (string)$row['user_uid'], (string)$row['created_at']]));
			if ($expectedPrevious !== $previous || !hash_equals($expected, $stored)) $broken[] = (int)$row['id'];
			$previous = $stored;
		}
		return ['status' => $broken === [] ? 'OK' : 'ERROR', 'checked' => count($rows), 'brokenIds' => $broken];
	}

	private function lastHash(): string {
		$query = $this->db->getQueryBuilder();
		$value = $query->select('event_hash')->from('bestatter_audit_log')->where($query->expr()->isNotNull('event_hash'))->orderBy('id', 'DESC')->setMaxResults(1)->executeQuery()->fetchOne();
		return $value === false ? '' : (string)$value;
	}

	private function minimize(mixed $value, string $key = ''): mixed {
		if ($value === null) return null;
		if (preg_match('/password|secret|token|audio|content|iban|bic|creditor|account/i', $key)) return '[geschützt]';
		if (is_array($value)) {
			$result = [];
			foreach ($value as $name => $item) $result[$name] = $this->minimize($item, (string)$name);
			return $result;
		}
		if (is_string($value) && mb_strlen($value) > 2000) return mb_substr($value, 0, 2000) . '… [gekürzt]';
		return $value;
	}

	public function caseHistory(int $caseId): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_audit_log')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')
			->executeQuery()->fetchAllAssociative();
		$history = array_map(fn(array $row): array => [
			'id' => (int)$row['id'],
			'caseId' => (int)$row['case_id'],
			'type' => 'activity',
			'objectType' => (string)$row['object_type'],
			'objectId' => $row['object_id'] !== null ? (int)$row['object_id'] : null,
			'action' => (string)$row['action'],
			'title' => $this->title((string)$row['object_type'], (string)$row['action']),
			'userUid' => (string)$row['user_uid'],
			'date' => (string)$row['created_at'],
			'status' => 'PROTOKOLLIERT',
			'before' => $this->decode($row['before_data'] ?? null),
			'after' => $this->decode($row['after_data'] ?? null),
			'immutable' => true,
		], $rows);
		$legacyQuery = $this->db->getQueryBuilder();
		$legacy = $legacyQuery->select('*')->from('bestatter_records')
			->where($legacyQuery->expr()->eq('case_id', $legacyQuery->createNamedParameter($caseId)))
			->andWhere($legacyQuery->expr()->eq('record_type', $legacyQuery->createNamedParameter('activity')))
			->executeQuery()->fetchAllAssociative();
		foreach ($legacy as $row) $history[] = [
			'id' => 'legacy-' . (int)$row['id'], 'caseId' => $caseId, 'type' => 'activity',
			'objectType' => 'LEGACY_ACTIVITY', 'objectId' => (int)$row['id'], 'action' => 'DOCUMENTED',
			'title' => (string)$row['title'], 'userUid' => (string)($row['created_by'] ?? $row['owner_uid'] ?? 'system'),
			'date' => (string)($row['record_date'] ?: $row['created_at']), 'status' => 'PROTOKOLLIERT', 'immutable' => true,
		];
		usort($history, static fn(array $left, array $right): int => strcmp((string)$right['date'], (string)$left['date']));
		return $history;
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? 'system';
	}

	private function decode(mixed $value): mixed {
		if ($value === null || $value === '') return null;
		try { return json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR); }
		catch (\Throwable) { return (string)$value; }
	}

	private function title(string $type, string $action): string {
		$objects = ['CASE' => 'Fall', 'TASK' => 'Aufgabe', 'SCHEDULE' => 'Termin', 'DOCUMENT' => 'Dokument', 'SERVICE' => 'Leistung', 'QUOTE' => 'Kostenvoranschlag', 'ORDER' => 'Auftrag', 'INVOICE' => 'Rechnung', 'INCOMING_INVOICE' => 'Eingangsrechnung', 'DEREGISTRATION' => 'Abmeldung', 'WORKFLOW' => 'Workflow', 'ASSISTANT' => 'Assistent', 'RETENTION' => 'Aufbewahrung'];
		$actions = ['CREATED' => 'angelegt', 'UPDATED' => 'geändert', 'DELETED' => 'gelöscht', 'VERSION_CREATED' => 'festgeschrieben', 'QUOTE_CONVERTED' => 'in Auftrag überführt', 'SELECTION_UPDATED' => 'Auswahl geändert', 'LIFECYCLE_UPDATED' => 'Status geändert', 'EXECUTED' => 'ausgeführt', 'FAILED' => 'fehlgeschlagen', 'INTENT_PREVIEWED' => 'Aktionsvorschlag geprüft', 'INFORMATION_REQUESTED' => 'Auskunft abgerufen', 'LEGAL_HOLD_SET' => 'Legal Hold gesetzt', 'LEGAL_HOLD_RELEASED' => 'Legal Hold aufgehoben'];
		return ($objects[strtoupper($type)] ?? ucfirst(strtolower($type))) . ' ' . ($actions[strtoupper($action)] ?? strtolower(str_replace('_', ' ', $action)));
	}
}
