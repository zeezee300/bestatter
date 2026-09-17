<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IDBConnection;
use OCP\IUserSession;

class RecordService {
	public function __construct(
		private IDBConnection $db,
		private IUserSession $userSession,
		private AuditService $audit,
	) {}

	public function list(string $type, int $caseId = 0, ?string $ownerUid = null): array {
		$ownerUid = $this->ownerUid($ownerUid);
		$query = $this->db->getQueryBuilder();
		$query->select('r.*')
			->selectAlias('c.case_number', 'case_number')
			->from('bestatter_records', 'r')
			->leftJoin('r', 'bestatter_cases', 'c', $query->expr()->eq('c.id', 'r.case_id'))
			->where($query->expr()->eq('r.record_type', $query->createNamedParameter($type)))
			->andWhere($this->accessCondition($query, $ownerUid, 'r'));
		if ($caseId > 0) {
			$query->andWhere($query->expr()->eq('r.case_id', $query->createNamedParameter($caseId)));
		}
		$query->orderBy('r.record_date', 'ASC');
		return array_map([$this, 'map'], $query->executeQuery()->fetchAllAssociative());
	}

	public function get(int $id, ?string $ownerUid = null): array {
		$ownerUid = $this->ownerUid($ownerUid);
		$query = $this->db->getQueryBuilder();
		$query->select('r.*')
			->selectAlias('c.case_number', 'case_number')
			->from('bestatter_records', 'r')
			->leftJoin('r', 'bestatter_cases', 'c', $query->expr()->eq('c.id', 'r.case_id'))
			->where($query->expr()->eq('r.id', $query->createNamedParameter($id)))
			->andWhere($this->accessCondition($query, $ownerUid, 'r'));
		$row = $query->executeQuery()->fetchAssociative();
		if ($row === false) {
			throw new \InvalidArgumentException('Eintrag nicht gefunden.');
		}
		return $this->map($row);
	}

	public function findByNextcloudUid(string $type, string $uid, ?string $ownerUid = null): ?array {
		if ($uid === '') {
			return null;
		}
		$ownerUid = $this->ownerUid($ownerUid);
		$query = $this->db->getQueryBuilder();
		$id = $query->select('id')
			->from('bestatter_records')
			->where($query->expr()->eq('record_type', $query->createNamedParameter($type)))
			->andWhere($query->expr()->eq('owner_uid', $query->createNamedParameter($ownerUid)))
			->andWhere($query->expr()->eq('nextcloud_uid', $query->createNamedParameter($uid)))
			->executeQuery()
			->fetchOne();
		return $id === false ? null : $this->get((int)$id, $ownerUid);
	}

	public function create(
		string $type,
		string $title,
		string $date = '',
		string $status = 'OFFEN',
		string $data = '{}',
		int $caseId = 0,
		?string $ownerUid = null,
		bool $allowPaperSignature = false,
	): array {
		$payload = $this->validate($title, $data);
		if ($type === 'document' && strtoupper($status) === 'UNTERSCHRIEBEN') throw new \InvalidArgumentException('Unterschriebene Papierdokumente müssen mit geprüftem Scan bestätigt werden.');
		if ($type === 'document' && isset($payload['paperSignature']) && !$allowPaperSignature) throw new \InvalidArgumentException('Papierverträge müssen über den vorgesehenen Scan-Upload erfasst werden.');
		$ownerUid = $this->ownerUid($ownerUid);
		$query = $this->db->getQueryBuilder();
		$now = date('c');
		$actor = $this->actorUid();
		$sync = $this->syncColumns($payload);
		$query->insert('bestatter_records')->values([
			'case_id' => $query->createNamedParameter($caseId ?: null),
			'owner_uid' => $query->createNamedParameter($ownerUid),
			'assignee_uid' => $query->createNamedParameter($sync['assigneeUid']),
			'assignee_name' => $query->createNamedParameter($sync['assigneeName']),
			'record_type' => $query->createNamedParameter($type),
			'title' => $query->createNamedParameter(trim($title)),
			'record_date' => $query->createNamedParameter($date),
			'status' => $query->createNamedParameter($status),
			'payload' => $query->createNamedParameter(json_encode($payload, JSON_THROW_ON_ERROR)),
			'nextcloud_uid' => $query->createNamedParameter($sync['uid']),
			'nextcloud_calendar_key' => $query->createNamedParameter($sync['calendarKey']),
			'nextcloud_uri' => $query->createNamedParameter($sync['uri']),
			'nextcloud_etag' => $query->createNamedParameter($sync['etag']),
			'nextcloud_last_modified' => $query->createNamedParameter($sync['lastModified']),
			'nextcloud_sync_hash' => $query->createNamedParameter($sync['syncHash']),
			'created_at' => $query->createNamedParameter($now),
			'updated_at' => $query->createNamedParameter($now),
			'created_by' => $query->createNamedParameter($actor),
			'updated_by' => $query->createNamedParameter($actor),
		])->executeStatement();
		$created = $this->get((int)$this->db->lastInsertId('bestatter_records'), $ownerUid);
		if ($type !== 'activity') $this->audit->log($caseId, $type, (int)$created['id'], 'CREATED', null, $created);
		return $created;
	}

	public function saveDocument(int $caseId, string $title, string $status, array $payload, bool $allowInvoiceReplacement = false): array {
		$key = (string)($payload['templateKey'] ?? '');
		if ($key === 'BESTATTUNGSAUFTRAG' && strtoupper($status) === 'FINAL') return $this->create('document', $title, date('c'), $status, json_encode($payload, JSON_THROW_ON_ERROR), $caseId);
		$contextKey = (string)($payload['documentContextKey'] ?? '');
		$invoiceId = (int)($payload['invoiceId'] ?? 0);
		foreach ($this->list('document', $caseId) as $existing) {
			$data = $existing['data'] ?? [];
			$same = $invoiceId > 0
				? (int)($data['invoiceId'] ?? 0) === $invoiceId
				: ($key !== '' && (string)($data['templateKey'] ?? '') === $key && (string)($data['documentContextKey'] ?? '') === $contextKey && (int)($data['invoiceId'] ?? 0) === 0);
			if (!$same) continue;
			if ($this->isImmutableDocumentStatus((string)$existing['status']) && !$allowInvoiceReplacement) throw new \InvalidArgumentException('Das Dokument ist final oder versendet und kann nicht ersetzt werden.');
			return $this->update((int)$existing['id'], $title, date('c'), $status, json_encode($payload, JSON_THROW_ON_ERROR), $caseId, null, $allowInvoiceReplacement);
		}
		return $this->create('document', $title, date('c'), $status, json_encode($payload, JSON_THROW_ON_ERROR), $caseId, null, isset($payload['paperSignature']));
	}

	public function update(
		int $id,
		string $title,
		string $date = '',
		string $status = 'OFFEN',
		string $data = '{}',
		int $caseId = 0,
		?string $ownerUid = null,
		bool $allowImmutableDocument = false,
		bool $confirmPaperSignature = false,
	): array {
		$payload = $this->validate($title, $data);
		$ownerUid = $this->ownerUid($ownerUid);
		$existing = $this->get($id, $ownerUid);
		if ($existing['type'] === 'document' && isset($payload['paperSignature']) && !isset($existing['data']['paperSignature']) && !$confirmPaperSignature) throw new \InvalidArgumentException('Papierverträge müssen über den vorgesehenen Scan-Upload erfasst werden.');
		if ($existing['type'] === 'document' && strtoupper($status) === 'UNTERSCHRIEBEN' && !$confirmPaperSignature) throw new \InvalidArgumentException('Der Status „handschriftlich unterschrieben“ erfordert einen geprüften Scan.');
		if (is_array($existing['data']['paperSignature'] ?? null) && !$confirmPaperSignature) throw new \InvalidArgumentException('Papierverträge können nur über die Unterschriftenprüfung bestätigt werden.');
		if ($existing['type'] === 'activity') throw new \InvalidArgumentException('Der Fall-Verlauf ist ein unveränderbares Protokoll.');
		if ($existing['type'] === 'document' && $this->isImmutableDocumentStatus((string)$existing['status']) && !$allowImmutableDocument) throw new \InvalidArgumentException('Finale oder versendete Dokumente können nicht bearbeitet werden.');
		$sync = $this->syncColumns($payload);
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_records')
			->set('title', $query->createNamedParameter(trim($title)))
			->set('record_date', $query->createNamedParameter($date))
			->set('status', $query->createNamedParameter($status))
			->set('payload', $query->createNamedParameter(json_encode($payload, JSON_THROW_ON_ERROR)))
			->set('case_id', $query->createNamedParameter($caseId ?: null))
			->set('assignee_uid', $query->createNamedParameter($sync['assigneeUid']))
			->set('assignee_name', $query->createNamedParameter($sync['assigneeName']))
			->set('nextcloud_uid', $query->createNamedParameter($sync['uid']))
			->set('nextcloud_calendar_key', $query->createNamedParameter($sync['calendarKey']))
			->set('nextcloud_uri', $query->createNamedParameter($sync['uri']))
			->set('nextcloud_etag', $query->createNamedParameter($sync['etag']))
			->set('nextcloud_last_modified', $query->createNamedParameter($sync['lastModified']))
			->set('nextcloud_sync_hash', $query->createNamedParameter($sync['syncHash']))
			->set('updated_at', $query->createNamedParameter(date('c')))
			->set('updated_by', $query->createNamedParameter($this->actorUid()))
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->andWhere($this->accessCondition($query, $ownerUid))
			->executeStatement();
		$updated = $this->get($id, (string)$existing['ownerUid']);
		$this->audit->log((int)($updated['caseId'] ?? $caseId), (string)$updated['type'], $id, 'UPDATED', $existing, $updated);
		return $updated;
	}

	public function confirmPaperContract(int $id, int $caseId, array $review): array {
		$existing = $this->get($id);
		$data = $existing['data'] ?? [];
		if ($existing['type'] !== 'document' || (int)$existing['caseId'] !== $caseId || $existing['status'] !== 'SIGNATURE_REVIEW' || !is_array($data['paperSignature'] ?? null)) throw new \InvalidArgumentException('Der Papiervertrag ist nicht zur Unterschriftenprüfung vorgemerkt.');
		$signers = trim((string)($review['signers'] ?? ''));
		if ($signers === '' || mb_strlen($signers) > 500) throw new \InvalidArgumentException('Bitte die tatsächlich unterschreibenden Parteien angeben.');
		$signatureDate = trim((string)($review['signatureDate'] ?? ''));
		if ($signatureDate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $signatureDate) || !checkdate((int)substr($signatureDate, 5, 2), (int)substr($signatureDate, 8, 2), (int)substr($signatureDate, 0, 4)))) throw new \InvalidArgumentException('Das Unterschriftsdatum ist ungültig.');
		$paperLocation = trim((string)($review['paperLocation'] ?? ''));
		if (mb_strlen($paperLocation) > 500) throw new \InvalidArgumentException('Der Ablageort des Papieroriginals ist zu lang.');
		if (empty($review['complete']) || empty($review['signaturesPresent']) || empty($review['contentMatches'])) throw new \InvalidArgumentException('Bitte Vollständigkeit, Unterschriften und Vertragsinhalt ausdrücklich bestätigen.');
		$data['paperSignature']['review'] = ['signers' => $signers, 'signatureDate' => $signatureDate, 'paperLocation' => $paperLocation, 'reviewedBy' => $this->actorUid(), 'reviewedAt' => date('c'), 'complete' => true, 'signaturesPresent' => true, 'contentMatches' => true];
		return $this->update($id, $existing['title'], (string)$existing['date'], 'UNTERSCHRIEBEN', json_encode($data, JSON_THROW_ON_ERROR), $caseId, null, false, true);
	}

	public function upsertFromNextcloud(
		string $type,
		string $uid,
		string $title,
		string $date,
		string $status,
		array $data,
		int $caseId = 0,
		?string $ownerUid = null,
	): array {
		$ownerUid = $this->ownerUid($ownerUid);
		$existing = $this->findByNextcloudUid($type, $uid, $ownerUid);
		$data['nextcloud']['uid'] = $uid;
		$data['nextcloud']['stored'] = true;
		unset($data['nextcloud']['syncError'], $data['nextcloud']['remoteDeletedAt']);
		$payload = json_encode($data, JSON_THROW_ON_ERROR);
		if ($existing !== null) {
			$resolvedCaseId = $caseId > 0 ? $caseId : (int)($existing['caseId'] ?? 0);
			return $this->update($existing['id'], $title, $date, $status, $payload, $resolvedCaseId, $ownerUid);
		}
		try {
			return $this->create($type, $title, $date, $status, $payload, $caseId, $ownerUid);
		} catch (\Throwable $error) {
			$existing = $this->findByNextcloudUid($type, $uid, $ownerUid);
			if ($existing === null) {
				throw $error;
			}
			return $this->update($existing['id'], $title, $date, $status, $payload, $caseId ?: (int)($existing['caseId'] ?? 0), $ownerUid);
		}
	}

	public function markRemoteDeleted(string $type, string $uid, ?string $ownerUid = null): ?array {
		$ownerUid = $this->ownerUid($ownerUid);
		$record = $this->findByNextcloudUid($type, $uid, $ownerUid);
		if ($record === null) {
			return null;
		}
		$data = $record['data'];
		if (($data['nextcloud']['remoteDeletedAt'] ?? '') !== '') return $record;
		$data['nextcloud']['stored'] = false;
		$data['nextcloud']['remoteDeletedAt'] = date('c');
		$data['nextcloud']['statusBeforeRemoteDeletion'] = (string)$record['status'];
		return $this->update(
			$record['id'],
			$record['title'],
			(string)($record['date'] ?? ''),
			(string)$record['status'],
			json_encode($data, JSON_THROW_ON_ERROR),
			(int)($record['caseId'] ?? 0),
			$ownerUid,
		);
	}

	public function markMissingFromCalendar(string $type, string $calendarKey, array $seenUids, ?string $ownerUid = null): int {
		$ownerUid = $this->ownerUid($ownerUid);
		$seen = array_fill_keys(array_map('strval', $seenUids), true);
		$marked = 0;
		foreach ($this->list($type, 0, $ownerUid) as $record) {
			$nextcloud = $record['data']['nextcloud'] ?? [];
			$uid = (string)($nextcloud['uid'] ?? '');
			if ($uid === ''
				|| (string)($nextcloud['calendarKey'] ?? '') !== $calendarKey
				|| isset($seen[$uid])
				|| !($nextcloud['stored'] ?? false)) {
				continue;
			}
			$this->markRemoteDeleted($type, $uid, $ownerUid);
			$marked++;
		}
		return $marked;
	}

	/**
	 * Removes local mirrors from a calendar that must never be imported.
	 * The remote CalDAV object is deliberately left untouched.
	 */
	public function removeImportedCalendarRecords(string $type, string $calendarKey, ?string $ownerUid = null): int {
		if (!in_array($type, ['task', 'schedule'], true) || trim($calendarKey) === '') {
			return 0;
		}
		$ownerUid = $this->ownerUid($ownerUid);
		$query = $this->db->getQueryBuilder();
		$ids = $query->select('id')
			->from('bestatter_records')
			->where($query->expr()->eq('record_type', $query->createNamedParameter($type)))
			->andWhere($query->expr()->eq('owner_uid', $query->createNamedParameter($ownerUid)))
			->andWhere($query->expr()->eq('nextcloud_calendar_key', $query->createNamedParameter($calendarKey)))
			->andWhere($query->expr()->isNull('case_id'))
			->executeQuery()
			->fetchFirstColumn();
		if ($ids === []) {
			return 0;
		}
		$query = $this->db->getQueryBuilder();
		$query->delete('bestatter_records')
			->where($query->expr()->eq('record_type', $query->createNamedParameter($type)))
			->andWhere($query->expr()->eq('owner_uid', $query->createNamedParameter($ownerUid)))
			->andWhere($query->expr()->eq('nextcloud_calendar_key', $query->createNamedParameter($calendarKey)))
			->andWhere($query->expr()->isNull('case_id'))
			->executeStatement();
		foreach ($ids as $id) {
			$this->audit->log(0, $type, (int)$id, 'DELETED', ['reason' => 'EXCLUDED_CALENDAR_MIRROR'], null);
		}
		return count($ids);
	}

	public function delete(int $id): void {
		$ownerUid = $this->ownerUid(null);
		$existing = $this->get($id, $ownerUid);
		if ($existing['type'] === 'activity') throw new \InvalidArgumentException('Der Fall-Verlauf ist ein unveränderbares Protokoll.');
		if ($existing['type'] === 'document' && $this->isImmutableDocumentStatus((string)$existing['status'])) throw new \InvalidArgumentException('Finale oder versendete Dokumente können nicht gelöscht werden.');
		$query = $this->db->getQueryBuilder();
		$query->delete('bestatter_records')
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->andWhere($this->accessCondition($query, $ownerUid))
			->executeStatement();
		$this->audit->log((int)($existing['caseId'] ?? 0), (string)$existing['type'], $id, 'DELETED', $existing, null);
	}

	private function isImmutableDocumentStatus(string $status): bool { return in_array(strtoupper($status), ['FINAL', 'UNTERSCHRIEBEN', 'VERSENDET'], true); }

	private function validate(string $title, string $data): array {
		if (trim($title) === '') {
			throw new \InvalidArgumentException('Bezeichnung ist ein Pflichtfeld.');
		}
		$payload = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
		return is_array($payload) ? $payload : [];
	}

	private function syncColumns(array $payload): array {
		$nextcloud = is_array($payload['nextcloud'] ?? null) ? $payload['nextcloud'] : [];
		return [
			'assigneeUid' => ($payload['assigneeUid'] ?? '') !== '' ? (string)$payload['assigneeUid'] : null,
			'assigneeName' => ($payload['assigneeName'] ?? '') !== '' ? (string)$payload['assigneeName'] : null,
			'uid' => ($nextcloud['uid'] ?? '') !== '' ? (string)$nextcloud['uid'] : null,
			'calendarKey' => ($nextcloud['calendarKey'] ?? '') !== '' ? (string)$nextcloud['calendarKey'] : null,
			'uri' => ($nextcloud['uri'] ?? '') !== '' ? (string)$nextcloud['uri'] : null,
			'etag' => ($nextcloud['etag'] ?? '') !== '' ? (string)$nextcloud['etag'] : null,
			'lastModified' => ($nextcloud['lastModified'] ?? '') !== '' ? (string)$nextcloud['lastModified'] : null,
			'syncHash' => ($nextcloud['syncHash'] ?? '') !== '' ? (string)$nextcloud['syncHash'] : null,
		];
	}

	private function accessCondition(mixed $query, string $uid, string $alias = ''): mixed {
		$prefix = $alias !== '' ? $alias . '.' : '';
		return $query->expr()->orX(
			$query->expr()->eq($prefix . 'owner_uid', $query->createNamedParameter($uid)),
			$query->expr()->eq($prefix . 'assignee_uid', $query->createNamedParameter($uid)),
			// Secondary assignees are stored in the JSON payload. Keep the regular
			// record list aligned with OperationalService::personalDay(), otherwise
			// dashboard counters and calendar entries disagree for team appointments.
			$query->expr()->like($prefix . 'payload', $query->createNamedParameter('%"' . $query->escapeLikeParameter($uid) . '"%')),
		);
	}

	private function ownerUid(?string $ownerUid): string {
		if ($ownerUid !== null && $ownerUid !== '') {
			return $ownerUid;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('Kein Nextcloud-Benutzer angemeldet.');
		}
		return $user->getUID();
	}

	private function actorUid(): string { return $this->userSession->getUser()?->getUID() ?? 'system'; }

	private function map(array $row): array {
		$data = json_decode((string)($row['payload'] ?? '{}'), true) ?: [];
		return [
			'id' => (int)$row['id'],
			'caseId' => $row['case_id'] ? (int)$row['case_id'] : null,
			'caseNumber' => (string)($row['case_number'] ?? $data['caseNumber'] ?? ''),
			'ownerUid' => (string)($row['owner_uid'] ?? ''),
			'assigneeUid' => (string)($row['assignee_uid'] ?? $data['assigneeUid'] ?? ''),
			'assigneeName' => (string)($row['assignee_name'] ?? $data['assigneeName'] ?? ''),
			'type' => (string)$row['record_type'],
			'title' => (string)$row['title'],
			'date' => (string)($row['record_date'] ?? ''),
			'status' => (string)$row['status'],
			'createdBy' => (string)($row['created_by'] ?? $row['owner_uid'] ?? ''),
			'updatedBy' => (string)($row['updated_by'] ?? $row['created_by'] ?? $row['owner_uid'] ?? ''),
			'data' => $data,
		];
	}
}
