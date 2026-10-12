<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Mail\IMailer;

/**
 * Befund (Review 2026-09-22, Kundenmeldung zum Prozess "Abmeldungen"): "Der Mailversand oder die
 * auch die Druckausgabe wird noch nicht angeboten." Der Statuswechsel nach VERSENDET dokumentierte
 * bislang ausschließlich einen bereits außerhalb der Anwendung erfolgten Versand (Notiz/Beleg,
 * ausdrücklich KEIN tatsächlicher E-Mail-Versand - siehe DeregistrationService::transition()). Für
 * Abmeldungen mit Übertragungsweg E-Mail wird hier nun ein echter Versand angeboten.
 *
 * Bewusst ein eigener, kleinerer Dienst statt Erweiterung von BusinessMailService: Letzterer ist
 * fest auf genau eine bereits final registrierte PDF-Datei vom Record-Typ 'document' zugeschnitten
 * (Auftrag, Rechnung usw.). Eine Abmeldung transportiert dagegen mehrere, im Formular frei
 * ausgewählte Anlagen unterschiedlichen Typs (Urkunde, Formular, Nachweis, Korrespondenz), die auch
 * einfache Nextcloud-Uploads ohne 'document'-Datensatz sein können. Die SMTP-Verfügbarkeitsprüfung
 * (BusinessMailService::availability()) wird bewusst wiederverwendet statt dupliziert, da sie
 * bereits fallunabhängig ist.
 *
 * Sicherheitsmechanismen identisch zu BusinessMailService: Versandkennung (request_key) als
 * Idempotenzschutz gegen Doppelklick/Doppel-Request, Protokollierung in der Outbox-Tabelle VOR dem
 * SMTP-Aufruf (damit ein Absturz zwischen SMTP-Zusage und Rückschreiben nie zu einem
 * stillschweigend wiederholten Versand führt), und ein Inhalts-Fingerabdruck der Anlagen
 * (attachments_fingerprint) gegen nachträgliche Drift zwischen Vorschau und Versand. Zusätzlich:
 * Versand ist ausschließlich aus dem Status VORBEREITET mit Übertragungsweg EMAIL möglich - ein
 * bereits versendeter/bestätigter/erledigter Vorgang kann über diesen Weg nicht erneut verschickt
 * werden (die Outbox-Historie schützt nur vor identischem Doppelversand, nicht vor einem bewusst
 * anderen zweiten Versand an dieselbe Abmeldung).
 */
class DeregistrationMailService {
	private const MAX_ATTACHMENT_BYTES = 15 * 1024 * 1024;
	private const MAX_TOTAL_BYTES = 20 * 1024 * 1024;

	public function __construct(
		private IDBConnection $db,
		private IUserSession $users,
		private IMailer $mailer,
		private CaseFileService $files,
		private RecordService $records,
		private DeregistrationService $deregistrations,
		private BusinessMailService $businessMail,
		private AuditService $audit,
	) {}

	public function preview(array $case, int $recordId): array {
		[$record, $recipientEmail] = $this->sendableRecord($case, $recordId);
		$availability = $this->businessMail->availability();
		$attachments = $this->resolveAttachmentContents($case, $record);
		return [
			'caseId' => (int)$case['id'], 'caseNumber' => (string)$case['caseNumber'],
			'recordId' => $recordId, 'title' => (string)$record['title'],
			'recipient' => $recipientEmail, 'subject' => (string)($record['data']['subject'] ?? ''),
			'body' => (string)($record['data']['body'] ?? ''),
			'attachments' => array_map(static fn(array $item): array => [
				'fileId' => $item['fileId'], 'title' => $item['title'], 'fileName' => $item['fileName'], 'size' => $item['size'],
			], $attachments),
			'attachmentsFingerprint' => $this->fingerprint($attachments),
			'sender' => $availability['sender'], 'replyTo' => $availability['replyTo'],
			'enabled' => $availability['enabled'] && $attachments !== [], 'reason' => $availability['enabled'] && $attachments === [] ? 'Es ist keine gültige Anlage ausgewählt.' : $availability['reason'],
		];
	}

	public function history(array $case): array {
		$q = $this->db->getQueryBuilder();
		$rows = $q->select('id', 'record_id', 'recipient', 'sender', 'reply_to', 'subject', 'attachments_fingerprint', 'status', 'repeat_reason', 'created_by', 'created_at', 'updated_at')
			->from('bestatter_dereg_mail_outbox')->where($q->expr()->eq('case_id', $q->createNamedParameter((int)$case['id'])))
			->orderBy('id', 'DESC')->setMaxResults(100)->executeQuery()->fetchAllAssociative();
		return array_map(static fn(array $row): array => [
			'id' => (int)$row['id'], 'recordId' => (int)$row['record_id'],
			'recipient' => (string)$row['recipient'], 'sender' => (string)$row['sender'], 'replyTo' => (string)($row['reply_to'] ?? ''), 'subject' => (string)$row['subject'],
			'attachmentsFingerprint' => (string)$row['attachments_fingerprint'], 'status' => (string)$row['status'],
			'repeatReason' => (string)($row['repeat_reason'] ?? ''), 'createdBy' => (string)$row['created_by'],
			'createdAt' => (string)$row['created_at'], 'updatedAt' => (string)$row['updated_at'],
		], $rows);
	}

	public function send(array $case, int $recordId, array $input): array {
		[$record, ] = $this->sendableRecord($case, $recordId);
		$availability = $this->businessMail->availability();
		if (!$availability['enabled']) throw new \InvalidArgumentException($availability['reason']);
		$uid = $this->users->getUser()?->getUID() ?? '';
		if ($uid === '') throw new \RuntimeException('Keine angemeldete Person.');
		$key = strtolower(trim((string)($input['requestKey'] ?? '')));
		if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/', $key)) throw new \InvalidArgumentException('Ungültige Versandkennung.');
		$recipient = strtolower(trim((string)($input['recipient'] ?? '')));
		$subject = trim((string)($input['subject'] ?? ''));
		$body = trim((string)($input['body'] ?? ''));
		$reason = trim((string)($input['repeatReason'] ?? ''));
		$attachments = $this->resolveAttachmentContents($case, $record);
		$fingerprint = $this->fingerprint($attachments);
		$existing = $this->byKey($key);
		if ($existing !== null) {
			if ((int)$existing['case_id'] !== (int)$case['id'] || (int)$existing['record_id'] !== $recordId || (string)$existing['created_by'] !== $uid) throw new \InvalidArgumentException('Die Versandkennung wurde bereits für einen anderen Vorgang benutzt.');
			if ((string)$existing['recipient'] !== $recipient || (string)$existing['subject'] !== $subject || (string)$existing['body'] !== $body || (string)$existing['attachments_fingerprint'] !== $fingerprint) throw new \InvalidArgumentException('Die Versandkennung gehört zu einem anderen Nachrichteninhalt. Bitte den Versandverlauf prüfen.');
			return ['id' => (int)$existing['id'], 'status' => (string)$existing['status'], 'idempotent' => true];
		}
		if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || strlen($recipient) > 320 || preg_match('/[\r\n,;]/', $recipient)) throw new \InvalidArgumentException('Bitte genau eine gültige Empfängeradresse angeben.');
		if ($subject === '' || mb_strlen($subject) > 250 || preg_match('/[\r\n]/', $subject)) throw new \InvalidArgumentException('Bitte einen Betreff ohne Zeilenumbruch angeben.');
		if ($body === '' || mb_strlen($body) > 10000) throw new \InvalidArgumentException('Bitte einen Nachrichtentext mit maximal 10.000 Zeichen eingeben.');
		if (mb_strlen($reason) > 500) throw new \InvalidArgumentException('Die Begründung für einen erneuten Versand ist zu lang.');
		if ($attachments === []) throw new \InvalidArgumentException('Es ist keine gültige Anlage ausgewählt.');
		if (!hash_equals($fingerprint, strtolower(trim((string)($input['attachmentsFingerprint'] ?? ''))))) throw new \InvalidArgumentException('Die Anlagen haben sich seit der Vorschau geändert. Bitte Vorschau erneut öffnen.');
		$prior = $this->priorAttempt((int)$case['id'], $recordId, $recipient);
		if ($prior !== null && mb_strlen($reason) < 5) throw new \InvalidArgumentException('Für einen weiteren Versand an dieselbe Adresse ist eine Begründung erforderlich. Prüfen Sie zuerst den Postausgang.');
		$dedupeKey = hash('sha256', implode('|', [(int)$case['id'], $recordId, $recipient, $prior === null ? 'FIRST' : $key]));
		$now = date('c');
		$q = $this->db->getQueryBuilder();
		try {
			$q->insert('bestatter_dereg_mail_outbox')->values([
				'case_id' => $q->createNamedParameter((int)$case['id']), 'record_id' => $q->createNamedParameter($recordId),
				'request_key' => $q->createNamedParameter($key), 'dedupe_key' => $q->createNamedParameter($dedupeKey),
				'recipient' => $q->createNamedParameter($recipient), 'sender' => $q->createNamedParameter($availability['sender']),
				'reply_to' => $q->createNamedParameter($availability['replyTo'] ?: null),
				'subject' => $q->createNamedParameter($subject), 'body' => $q->createNamedParameter($body),
				'attachments_json' => $q->createNamedParameter(json_encode(array_map(static fn(array $item): array => ['fileId' => $item['fileId'], 'fileName' => $item['fileName']], $attachments), JSON_THROW_ON_ERROR)),
				'attachments_fingerprint' => $q->createNamedParameter($fingerprint),
				'status' => $q->createNamedParameter('SENDING'), 'repeat_reason' => $q->createNamedParameter($reason !== '' ? $reason : null),
				'created_by' => $q->createNamedParameter($uid), 'created_at' => $q->createNamedParameter($now), 'updated_at' => $q->createNamedParameter($now),
			])->executeStatement();
		} catch (\Throwable $error) {
			$existing = $this->byKey($key);
			if ($existing === null && $this->priorAttempt((int)$case['id'], $recordId, $recipient) !== null) throw new \InvalidArgumentException('Ein paralleler Versandversuch für diese Abmeldung und diese Adresse wurde bereits erfasst. Bitte den Verlauf prüfen.', 0, $error);
			if ($existing === null || (int)$existing['case_id'] !== (int)$case['id'] || (int)$existing['record_id'] !== $recordId || (string)$existing['created_by'] !== $uid || (string)$existing['recipient'] !== $recipient || (string)$existing['subject'] !== $subject || (string)$existing['body'] !== $body || (string)$existing['attachments_fingerprint'] !== $fingerprint) throw $error;
			return ['id' => (int)$existing['id'], 'status' => (string)$existing['status'], 'idempotent' => true];
		}
		$id = (int)$this->db->lastInsertId('bestatter_dereg_mail_outbox');
		try {
			$message = $this->mailer->createMessage();
			$displayName = trim((string)($this->users->getUser()?->getDisplayName() ?? ''));
			$displayName = mb_substr(trim(preg_replace('/[\r\n]+/', ' ', $displayName)), 0, 120);
			$message->setFrom([$availability['sender'] => $displayName !== '' ? $displayName . ' via Bestatter' : 'Bestatter']);
			if ($availability['replyTo'] !== '') $message->setReplyTo([$availability['replyTo'] => $displayName ?: $availability['replyTo']]);
			$message->setTo([$recipient]);
			$message->setSubject($subject);
			$message->setPlainBody($body);
			foreach ($attachments as $attachment) $message->attach($this->mailer->createAttachment($attachment['content'], $attachment['fileName'], $attachment['mimeType']));
			$failed = $this->mailer->send($message);
			if ($failed !== []) throw new \RuntimeException('Der Mailserver hat den Empfänger nicht angenommen.');
			$this->setStatus($id, 'ACCEPTED');
			try {
				$this->deregistrations->transition($recordId, 'VERSENDET', [
					'sentAt' => $now,
					'evidenceNote' => 'Automatisch dokumentiert: Versand per E-Mail über die Bestatter-App an ' . $recipient . '.',
				]);
			} catch (\Throwable $transitionError) {
				// Die Mail wurde bereits erfolgreich verschickt; ein fehlgeschlagener Statuswechsel darf das
				// nicht verschleiern. Sichtbar im Audit-Trail vermerken statt einen falschen Fehlerzustand zu
				// melden - die Anwenderin kann den Status danach manuell nachtragen.
				try { $this->audit->log((int)$case['id'], 'DEREGISTRATION_MAIL', $id, 'STATUS_TRANSITION_FAILED', null, ['recordId' => $recordId, 'error' => $transitionError->getMessage()]); } catch (\Throwable) { /* nicht kritisch für den bereits erfolgten Versand */ }
			}
			try { $this->audit->log((int)$case['id'], 'DEREGISTRATION_MAIL', $id, 'SMTP_ACCEPTED', null, ['recordId' => $recordId, 'recipient' => $recipient, 'attachmentsFingerprint' => $fingerprint, 'replyTo' => $availability['replyTo']]); } catch (\Throwable) { /* Outbox bleibt maßgeblich, falls die Aktivitätsprotokollierung fehlschlägt */ }
			return ['id' => $id, 'status' => 'ACCEPTED', 'idempotent' => false];
		} catch (\Throwable $error) {
			try { $this->setStatus($id, 'UNCERTAIN'); } catch (\Throwable) {}
			throw new \RuntimeException('Der Versandstatus ist unklar. Bitte das Geschäfts-Postfach prüfen und nicht sofort erneut senden.', 0, $error);
		}
	}

	private function sendableRecord(array $case, int $recordId): array {
		$record = $this->records->get($recordId);
		if ($record['type'] !== 'deregistration' || (int)$record['caseId'] !== (int)$case['id']) throw new \InvalidArgumentException('Abmeldung wurde nicht gefunden.');
		if (strtoupper((string)$record['status']) !== 'VORBEREITET') throw new \InvalidArgumentException('Nur eine vorbereitete Abmeldung kann per E-Mail versendet werden.');
		$channel = strtoupper(trim((string)($record['data']['deliveryChannel'] ?? '')));
		if ($channel !== 'EMAIL') throw new \InvalidArgumentException('Für den gewählten Übertragungsweg ist kein E-Mail-Versand vorgesehen. Bitte den Versandnachweis manuell erfassen oder den Brief drucken.');
		$recipientEmail = strtolower(trim((string)($record['data']['recipient']['email'] ?? '')));
		if (filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) throw new \InvalidArgumentException('Für den gewählten Empfänger ist keine gültige E-Mail-Adresse hinterlegt.');
		return [$record, $recipientEmail];
	}

	private function resolveAttachmentContents(array $case, array $record): array {
		$selected = is_array($record['data']['attachments'] ?? null) ? $record['data']['attachments'] : [];
		$resolved = []; $total = 0;
		foreach ($selected as $item) {
			$fileId = (int)(is_array($item) ? ($item['fileId'] ?? $item['id'] ?? 0) : $item);
			if ($fileId <= 0) continue;
			try { $file = $this->files->file($case, $fileId); } catch (\Throwable) { continue; }
			$content = (string)$file->getContent();
			if (strlen($content) > self::MAX_ATTACHMENT_BYTES) throw new \InvalidArgumentException('Die Anlage "' . $file->getName() . '" ist für den E-Mail-Versand zu groß (maximal 15 MB je Datei).');
			$total += strlen($content);
			if ($total > self::MAX_TOTAL_BYTES) throw new \InvalidArgumentException('Die ausgewählten Anlagen sind zusammen zu groß für den E-Mail-Versand (maximal 20 MB).');
			$resolved[] = [
				'fileId' => $fileId, 'title' => (string)(is_array($item) ? ($item['title'] ?? $file->getName()) : $file->getName()),
				'fileName' => $file->getName(), 'mimeType' => $file->getMimeType() ?: 'application/octet-stream',
				'size' => strlen($content), 'content' => $content, 'sha256' => hash('sha256', $content),
			];
		}
		return $resolved;
	}

	private function fingerprint(array $attachments): string {
		$parts = array_map(static fn(array $item): string => $item['fileId'] . ':' . $item['sha256'], $attachments);
		sort($parts);
		return hash('sha256', implode('|', $parts));
	}

	private function priorAttempt(int $caseId, int $recordId, string $recipient): ?array {
		$q = $this->db->getQueryBuilder();
		$row = $q->select('id', 'status')->from('bestatter_dereg_mail_outbox')
			->where($q->expr()->eq('case_id', $q->createNamedParameter($caseId)))
			->andWhere($q->expr()->eq('record_id', $q->createNamedParameter($recordId)))
			->andWhere($q->expr()->eq('recipient', $q->createNamedParameter($recipient)))
			->orderBy('id', 'DESC')->setMaxResults(1)->executeQuery()->fetchAssociative();
		return $row === false ? null : $row;
	}

	private function byKey(string $key): ?array {
		$q = $this->db->getQueryBuilder();
		$row = $q->select('id', 'case_id', 'record_id', 'created_by', 'status', 'recipient', 'subject', 'body', 'attachments_fingerprint')->from('bestatter_dereg_mail_outbox')
			->where($q->expr()->eq('request_key', $q->createNamedParameter($key)))->executeQuery()->fetchAssociative();
		return $row === false ? null : $row;
	}

	private function setStatus(int $id, string $status): void {
		$q = $this->db->getQueryBuilder();
		$q->update('bestatter_dereg_mail_outbox')->set('status', $q->createNamedParameter($status))
			->set('updated_at', $q->createNamedParameter(date('c')))
			->where($q->expr()->eq('id', $q->createNamedParameter($id)))->executeStatement();
	}
}
