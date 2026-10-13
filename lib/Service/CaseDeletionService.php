<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IDBConnection;

/**
 * 0.75.1: Vollständiges Löschen eines Falls – gemeinsam genutzt von „Fall löschen“ (Oberfläche) und der
 * Aufbewahrungsrichtlinie im Modus „Löschen“.
 *
 * Grundsatz: Die App unterstützt den operativen Ablauf; die gesetzliche Aufbewahrung von Rechnungen und
 * Belegen leistet die Buchhaltung (z. B. DATEV). Deshalb sperrt die App das Löschen nicht, sondern weist
 * vorher auf noch laufende Aufbewahrungsfristen hin (notices()); gelöscht wird nach ausdrücklicher Bestätigung.
 *
 * Gelöscht werden alle Falldaten, der Fallordner (am Papierkorb vorbei, inkl. Dateiversionen) sowie die von der
 * App angelegten Termine und Aufgaben in den Nextcloud-Kalendern. Die Zeilen des Audit-Logs bleiben als Nachweis
 * stehen, ihre Inhalte werden geleert (Hash-Kette bleibt gültig). Paperless-Dokumente werden nicht gelöscht.
 */
class CaseDeletionService {
	/** Gesetzliche Mindestfristen (Stand 2025, § 147 AO / § 257 HGB), gerechnet ab Ende des Entstehungsjahres. */
	public const INVOICE_YEARS = 8;
	public const COMMERCIAL_LETTER_YEARS = 6;

	public function __construct(
		private IDBConnection $db,
		private AuditService $audit,
		private InstallationConfigService $installationConfig,
		private CaseStorageService $storage,
		private PermanentDeletion $permanent,
		private AppCalendarObjects $calendar,
	) {}

	/**
	 * Hinweise vor dem Löschen. Sperren nichts; die Oberfläche und occ zeigen sie zur Bestätigung an.
	 * @return list<array{code:string,text:string}>
	 */
	public function notices(int $caseId, ?\DateTimeImmutable $today = null): array {
		$today ??= new \DateTimeImmutable('today');
		$notices = [];
		$rules = [
			['bestatter_invoices', 'invoice_number', 'created_at', self::INVOICE_YEARS, 'INVOICES', 'Rechnung', 'Rechnungen/Gutschriften', "status NOT IN ('ENTWURF', 'PRUEFUNG')"],
			['bestatter_incoming_invoices', 'supplier_invoice_no', 'invoice_date', self::INVOICE_YEARS, 'INCOMING_INVOICES', 'Eingangsrechnung', 'Eingangsrechnungen', null],
			['bestatter_commercial_docs', 'document_number', 'created_at', self::COMMERCIAL_LETTER_YEARS, 'COMMERCIAL_DOCUMENTS', 'Kostenvoranschlag/Auftrag', 'Kostenvoranschläge/Aufträge', null],
		];
		foreach ($rules as [$table, $numberColumn, $dateColumn, $years, $code, $singular, $plural, $condition]) {
			$query = $this->db->getQueryBuilder();
			$query->select($numberColumn, $dateColumn)->from($table)->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)));
			if ($condition !== null) $query->andWhere($condition);
			$open = []; $latest = null;
			foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
				$until = $this->retentionEnd((string)($row[$dateColumn] ?? ''), $years);
				if ($until === null || $until < $today) continue;
				$open[] = trim((string)($row[$numberColumn] ?? '')) ?: '(ohne Nummer)';
				if ($latest === null || $until > $latest) $latest = $until;
			}
			if ($open === [] || $latest === null) continue;
			$label = count($open) === 1 ? $singular . ' ' . $open[0] : count($open) . ' ' . $plural . ' (' . implode(', ', array_slice($open, 0, 5)) . (count($open) > 5 ? ', …' : '') . ')';
			$notices[] = ['code' => $code, 'text' => $label . ': gesetzliche Aufbewahrung (' . $years . ' Jahre) läuft bis ' . $latest->format('d.m.Y') . '. Bitte vor dem Löschen sicherstellen, dass die Belege in der Buchhaltung (z. B. DATEV) archiviert sind.'];
		}
		$paperless = $this->paperlessDocuments($caseId);
		$addressBook = $this->addressBookNotice($caseId);
		if ($addressBook !== null) $notices[] = $addressBook;
		if ($paperless > 0) $notices[] = ['code' => 'PAPERLESS', 'text' => $paperless . ' Dokument' . ($paperless === 1 ? '' : 'e') . ' in Paperless ' . ($paperless === 1 ? 'wird' : 'werden') . ' nicht mitgelöscht. Bitte bei Bedarf in Paperless entfernen.'];
		return $notices;
	}

	/**
	 * Löscht den Fall vollständig. Hinweise (notices) müssen vorher bestätigt worden sein – das prüft der Aufrufer.
	 * @param string $trigger CASE_DELETE (Oberfläche) oder RETENTION (Aufbewahrungsrichtlinie)
	 * @return array{caseId:int,action:string,folders:array,calendar:array,notices:list<array{code:string,text:string}>}
	 */
	public function delete(int $caseId, string $trigger): array {
		$caseNumber = $this->caseNumber($caseId);
		$notices = $this->notices($caseId);
		$calendarTargets = $this->calendar->targets($caseId);
		$this->db->beginTransaction();
		try {
			$this->deleteCaseData($caseId);
			$this->audit->redactCase($caseId);
			$query = $this->db->getQueryBuilder();
			$query->delete('bestatter_cases')->where($query->expr()->eq('id', $query->createNamedParameter($caseId)))->executeStatement();
			// Dateien innerhalb der offenen Transaktion: schlägt das Löschen fehl, bleibt der Fall unverändert.
			$folders = $this->deleteCaseFolders($caseNumber);
			$this->db->commit();
		} catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
		$calendar = $this->calendar->delete($calendarTargets);
		if ($calendar['errors'] !== []) $notices[] = ['code' => 'CALENDAR', 'text' => count($calendar['errors']) . ' Kalendereintrag/-einträge konnten nicht entfernt werden und müssen im Kalender gelöscht werden.'];
		$this->audit->logSystem($trigger === 'RETENTION' ? 'RETENTION' : 'CASE', 'DELETED', [
			'caseId' => $caseId, 'caseNumber' => $caseNumber, 'trigger' => $trigger,
			'folders' => count($folders['deleted']), 'calendarDeleted' => $calendar['deleted'], 'calendarErrors' => count($calendar['errors']),
			'acknowledgedNotices' => array_column($notices, 'code'),
		]);
		return ['caseId' => $caseId, 'action' => 'DELETED', 'folders' => $folders, 'calendar' => $calendar, 'notices' => $notices];
	}

	/** Ende der Aufbewahrung: 31.12. des Entstehungsjahres + $years. */
	public function retentionEnd(string $date, int $years): ?\DateTimeImmutable {
		if (!preg_match('/^(\d{4})-\d{2}-\d{2}/', trim($date), $match)) return null;
		return new \DateTimeImmutable(((int)$match[1] + $years) . '-12-31');
	}

	private function deleteCaseData(int $caseId): void {
		foreach ($this->ids('bestatter_external_documents', 'case_id', $caseId) as $id) $this->deleteWhere('bestatter_integration_jobs', 'object_id', $id);
		$recordIds = $this->ids('bestatter_records', 'case_id', $caseId);
		foreach ($recordIds as $recordId) {
			$this->deleteWhere('bestatter_action_runs', 'source_record_id', $recordId);
			$this->deleteWhere('bestatter_record_assignees', 'record_id', $recordId);
		}
		foreach ($this->ids('bestatter_invoices', 'case_id', $caseId) as $id) $this->deleteWhere('bestatter_invoice_items', 'invoice_id', $id);
		foreach ($this->ids('bestatter_incoming_invoices', 'case_id', $caseId) as $id) $this->deleteWhere('bestatter_incoming_items', 'incoming_invoice_id', $id);
		foreach ([
			'bestatter_capture_imports', 'bestatter_order_form_entries', 'bestatter_external_documents', 'bestatter_process_runs',
			'bestatter_schedule_history', 'bestatter_mail_outbox', 'bestatter_dereg_mail_outbox', 'bestatter_records',
			'bestatter_invoices', 'bestatter_commercial_docs', 'bestatter_incoming_invoices', 'bestatter_case_services', 'bestatter_side_orders',
			'bestatter_case_labels',
		] as $table) $this->deleteWhere($table, 'case_id', $caseId);
	}

	/** @return array{deleted:list<string>,missing:list<string>} */
	private function deleteCaseFolders(string $caseNumber): array {
		$year = substr($caseNumber, 0, 4);
		if (!preg_match('/^20\d{2}$/', $year)) throw new \RuntimeException('Fallordner konnte wegen ungültiger Fallnummer nicht sicher bestimmt werden.');
		$relative = $this->installationConfig->casesPath() . '/' . $year . '/' . $caseNumber;
		$result = ['deleted' => [], 'missing' => []];
		$entries = $this->storage->existingFolders($relative);
		if ($entries === []) { $result['missing'][] = $relative; return $result; }
		foreach ($entries as $entry) if (!$entry['folder']->isDeletable()) throw new \RuntimeException('Fallordner ist nicht löschbar: ' . $entry['label']);
		foreach ($entries as $entry) { $this->permanent->delete($entry['folder']); $result['deleted'][] = $entry['label']; }
		return $result;
	}

	/**
	 * 0.86.0: Personen des Falls, die auf Wunsch ins Nextcloud-Adressbuch übernommen wurden (Auftraggeber/in oder „nur für
	 * diesen Fall“ erfasste Personen). Löschen und Anonymisieren entfernen nur die Zuordnung im Fall, nicht den
	 * Adressbucheintrag – darauf weist dieser Hinweis hin. Gilt für beide Modi (Löschen und Anonymisieren).
	 * @return array{code:string,text:string}|null
	 */
	public function addressBookNotice(int $caseId): ?array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('title', 'payload')->from('bestatter_records')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->andWhere($query->expr()->eq('record_type', $query->createNamedParameter('case_contact')))
			->executeQuery()->fetchAllAssociative();
		$names = [];
		foreach ($rows as $row) {
			$data = json_decode((string)($row['payload'] ?? '{}'), true) ?: [];
			$source = (string)($data['source'] ?? '');
			$linked = ($source === 'order-client' && !empty($data['contactId'])) || ($source === 'case-person' && !empty($data['addressBook']['contactId']));
			if ($linked) $names[] = trim((string)$row['title']) ?: 'ohne Namen';
		}
		if ($names === []) return null;
		return ['code' => 'ADDRESS_BOOK', 'text' => (count($names) === 1 ? '1 Person dieses Falls steht' : count($names) . ' Personen dieses Falls stehen') . ' im Nextcloud-Adressbuch (' . implode(', ', array_slice($names, 0, 5)) . (count($names) > 5 ? ', …' : '') . '). Diese Einträge werden nicht mitgelöscht; bitte bei Bedarf in Nextcloud Kontakte entfernen.'];
	}

	private function paperlessDocuments(int $caseId): int {
		$query = $this->db->getQueryBuilder();
		return (int)$query->select($query->func()->count('*'))->from('bestatter_external_documents')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->andWhere($query->expr()->eq('provider', $query->createNamedParameter('PAPERLESS')))
			->andWhere($query->expr()->isNotNull('external_document_id'))
			->executeQuery()->fetchOne();
	}

	private function caseNumber(int $caseId): string {
		$query = $this->db->getQueryBuilder();
		$value = $query->select('case_number')->from('bestatter_cases')->where($query->expr()->eq('id', $query->createNamedParameter($caseId)))->executeQuery()->fetchOne();
		if ($value === false) throw new \InvalidArgumentException('Fall wurde nicht gefunden.');
		return (string)$value;
	}

	/** @return list<int> */
	private function ids(string $table, string $field, int $value): array {
		$query = $this->db->getQueryBuilder();
		return array_map('intval', $query->select('id')->from($table)->where($query->expr()->eq($field, $query->createNamedParameter($value)))->executeQuery()->fetchFirstColumn());
	}

	private function deleteWhere(string $table, string $field, int $value): void {
		$query = $this->db->getQueryBuilder();
		$query->delete($table)->where($query->expr()->eq($field, $query->createNamedParameter($value)))->executeStatement();
	}
}
