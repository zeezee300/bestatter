<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use OCP\Util;

/** Explicit, single-recipient delivery of registered final PDF documents. */
class BusinessMailService {
	private const MAX_ATTACHMENT_BYTES = 15 * 1024 * 1024;

	public function __construct(
		private IConfig $config,
		private IDBConnection $db,
		private IUserSession $users,
		private IMailer $mailer,
		private CaseFileService $files,
		private RecordService $records,
		private AuditService $audit,
	) {}

	public function availability(): array {
		// Use the same system sender resolution as Nextcloud's own mailer.
		$sender = trim(Util::getDefaultEmailAddress('no-reply'));
		$mode = strtolower(trim((string)$this->config->getSystemValue('mail_smtpmode', '')));
		$ready = filter_var($sender, FILTER_VALIDATE_EMAIL) !== false && in_array($mode, ['smtp', 'sendmail', 'qmail'], true);
		return [
			'enabled' => $ready,
			'sender' => $ready ? $sender : '',
			'replyTo' => $ready ? $this->replyTo() : '',
			'reason' => $ready ? '' : 'Nextcloud-Mailversand ist nicht konfiguriert oder der Systemabsender ist ungültig.',
		];
	}

	public function preview(array $case, int $recordId): array {
		[$record, $file, $content] = $this->finalPdf($case, $recordId);
		$availability = $this->availability();
		return [
			'caseId' => (int)$case['id'], 'caseNumber' => (string)$case['caseNumber'],
			'recordId' => $recordId, 'title' => (string)$record['title'], 'documentStatus' => (string)$record['status'],
			'fileId' => $file->getId(), 'fileName' => $file->getName(), 'fileSize' => strlen($content),
			'fileSha256' => hash('sha256', $content), 'sender' => $availability['sender'], 'replyTo' => $availability['replyTo'],
			'enabled' => $availability['enabled'], 'reason' => $availability['reason'],
		];
	}

	public function history(array $case): array {
		$q = $this->db->getQueryBuilder();
		$rows = $q->select('id', 'record_id', 'file_id', 'recipient', 'sender', 'reply_to', 'subject', 'file_name', 'file_sha256', 'status', 'repeat_reason', 'created_by', 'created_at', 'updated_at')
			->from('bestatter_mail_outbox')->where($q->expr()->eq('case_id', $q->createNamedParameter((int)$case['id'])))
			->orderBy('id', 'DESC')->setMaxResults(100)->executeQuery()->fetchAllAssociative();
		return array_map(static fn(array $row): array => [
			'id' => (int)$row['id'], 'recordId' => (int)$row['record_id'], 'fileId' => (int)$row['file_id'],
			'recipient' => (string)$row['recipient'], 'sender' => (string)$row['sender'], 'replyTo' => (string)($row['reply_to'] ?? ''), 'subject' => (string)$row['subject'],
			'fileName' => (string)$row['file_name'], 'fileSha256' => (string)$row['file_sha256'], 'status' => (string)$row['status'],
			'repeatReason' => (string)($row['repeat_reason'] ?? ''), 'createdBy' => (string)$row['created_by'],
			'createdAt' => (string)$row['created_at'], 'updatedAt' => (string)$row['updated_at'],
		], $rows);
	}

	public function send(array $case, int $recordId, array $input): array {
		$availability = $this->availability();
		if (!$availability['enabled']) throw new \InvalidArgumentException($availability['reason']);
		$uid = $this->users->getUser()?->getUID() ?? '';
		if ($uid === '') throw new \RuntimeException('Keine angemeldete Person.');
		$key = strtolower(trim((string)($input['requestKey'] ?? '')));
		if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/', $key)) throw new \InvalidArgumentException('Ungültige Versandkennung.');
		$existing = $this->byKey($key);
		if ($existing !== null) {
			if ((int)$existing['case_id'] !== (int)$case['id'] || (int)$existing['record_id'] !== $recordId || (string)$existing['created_by'] !== $uid) throw new \InvalidArgumentException('Die Versandkennung wurde bereits für einen anderen Vorgang benutzt.');
			if ((string)$existing['recipient'] !== strtolower(trim((string)($input['recipient'] ?? ''))) || (string)$existing['subject'] !== trim((string)($input['subject'] ?? '')) || (string)$existing['body'] !== trim((string)($input['body'] ?? '')) || (string)$existing['file_sha256'] !== strtolower(trim((string)($input['fileSha256'] ?? '')))) throw new \InvalidArgumentException('Die Versandkennung gehört zu einem anderen Nachrichteninhalt. Bitte den Versandverlauf prüfen.');
			return ['id' => (int)$existing['id'], 'status' => (string)$existing['status'], 'idempotent' => true];
		}
		$recipient = strtolower(trim((string)($input['recipient'] ?? '')));
		$subject = trim((string)($input['subject'] ?? ''));
		$body = trim((string)($input['body'] ?? ''));
		$reason = trim((string)($input['repeatReason'] ?? ''));
		if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || strlen($recipient) > 320 || preg_match('/[\r\n,;]/', $recipient)) throw new \InvalidArgumentException('Bitte genau eine gültige Empfängeradresse angeben.');
		if ($subject === '' || mb_strlen($subject) > 250 || preg_match('/[\r\n]/', $subject)) throw new \InvalidArgumentException('Bitte einen Betreff ohne Zeilenumbruch angeben.');
		if ($body === '' || mb_strlen($body) > 10000) throw new \InvalidArgumentException('Bitte einen Nachrichtentext mit maximal 10.000 Zeichen eingeben.');
		if (mb_strlen($reason) > 500) throw new \InvalidArgumentException('Die Begründung für einen erneuten Versand ist zu lang.');
		[$record, $file, $content] = $this->finalPdf($case, $recordId);
		$hash = hash('sha256', $content);
		if (!hash_equals($hash, strtolower(trim((string)($input['fileSha256'] ?? ''))))) throw new \InvalidArgumentException('Die PDF hat sich seit der Vorschau geändert. Bitte Vorschau erneut öffnen.');
		$prior = $this->priorAttempt((int)$case['id'], $recordId, $recipient);
		if ($prior !== null && mb_strlen($reason) < 5) throw new \InvalidArgumentException('Für einen weiteren Versand an dieselbe Adresse ist eine Begründung erforderlich. Prüfen Sie zuerst den Postausgang.');
		$dedupeKey = hash('sha256', implode('|', [(int)$case['id'], $recordId, $recipient, $prior === null ? 'FIRST' : $key]));
		$now = date('c');
		$q = $this->db->getQueryBuilder();
		try {
			$q->insert('bestatter_mail_outbox')->values([
				'case_id' => $q->createNamedParameter((int)$case['id']), 'record_id' => $q->createNamedParameter($recordId),
				'file_id' => $q->createNamedParameter($file->getId()), 'request_key' => $q->createNamedParameter($key),
				'dedupe_key' => $q->createNamedParameter($dedupeKey),
				'recipient' => $q->createNamedParameter($recipient), 'sender' => $q->createNamedParameter($availability['sender']), 'reply_to' => $q->createNamedParameter($availability['replyTo'] ?: null),
				'subject' => $q->createNamedParameter($subject), 'body' => $q->createNamedParameter($body),
				'file_name' => $q->createNamedParameter($file->getName()), 'file_sha256' => $q->createNamedParameter($hash),
				'status' => $q->createNamedParameter('SENDING'), 'repeat_reason' => $q->createNamedParameter($reason !== '' ? $reason : null),
				'created_by' => $q->createNamedParameter($uid), 'created_at' => $q->createNamedParameter($now), 'updated_at' => $q->createNamedParameter($now),
			])->executeStatement();
		} catch (\Throwable $error) {
			$existing = $this->byKey($key);
			if ($existing === null && $this->priorAttempt((int)$case['id'], $recordId, $recipient) !== null) throw new \InvalidArgumentException('Ein paralleler Versandversuch für dieses Dokument und diese Adresse wurde bereits erfasst. Bitte den Verlauf prüfen.', 0, $error);
			if ($existing === null || (int)$existing['case_id'] !== (int)$case['id'] || (int)$existing['record_id'] !== $recordId || (string)$existing['created_by'] !== $uid || (string)$existing['recipient'] !== $recipient || (string)$existing['subject'] !== $subject || (string)$existing['body'] !== $body || (string)$existing['file_sha256'] !== $hash) throw $error;
			return ['id' => (int)$existing['id'], 'status' => (string)$existing['status'], 'idempotent' => true];
		}
		$id = (int)$this->db->lastInsertId('bestatter_mail_outbox');
		try {
			$message = $this->mailer->createMessage();
			$displayName = trim((string)($this->users->getUser()?->getDisplayName() ?? ''));
			$displayName = mb_substr(trim(preg_replace('/[\r\n]+/', ' ', $displayName)), 0, 120);
			$message->setFrom([$availability['sender'] => $displayName !== '' ? $displayName . ' via Bestatter' : 'Bestatter']);
			if ($availability['replyTo'] !== '') $message->setReplyTo([$availability['replyTo'] => $displayName ?: $availability['replyTo']]);
			$message->setTo([$recipient]);
			$message->setSubject($subject);
			$message->setPlainBody($body);
			$message->attach($this->mailer->createAttachment($content, $file->getName(), 'application/pdf'));
			$failed = $this->mailer->send($message);
			if ($failed !== []) throw new \RuntimeException('Der Mailserver hat den Empfänger nicht angenommen.');
			$this->setStatus($id, 'ACCEPTED');
			try { $this->audit->log((int)$case['id'], 'MAIL_DISPATCH', $id, 'SMTP_ACCEPTED', null, ['recipient' => $recipient, 'recordId' => $recordId, 'fileSha256' => $hash, 'replyTo' => $availability['replyTo']]); } catch (\Throwable) { /* The outbox remains authoritative if activity logging fails. */ }
			return ['id' => $id, 'status' => 'ACCEPTED', 'idempotent' => false];
		} catch (\Throwable $error) {
			try { $this->setStatus($id, 'UNCERTAIN'); } catch (\Throwable) {}
			throw new \RuntimeException('Der Versandstatus ist unklar. Bitte das Geschäfts-Postfach prüfen und nicht sofort erneut senden.', 0, $error);
		}
	}

	private function finalPdf(array $case, int $recordId): array {
		$record = $this->records->get($recordId);
		if ($record['type'] !== 'document' || (int)$record['caseId'] !== (int)$case['id'] || !in_array(strtoupper((string)$record['status']), ['FINAL', 'UNTERSCHRIEBEN', 'VERSENDET'], true)) throw new \InvalidArgumentException('Nur fallbezogene, finale Dokumente dürfen versendet werden.');
		$fileId = (int)($record['data']['pdf']['fileId'] ?? $record['data']['fileId'] ?? 0);
		$file = $this->files->pdf($case, $fileId);
		$content = (string)$file->getContent();
		if (!str_starts_with($content, '%PDF-') || strlen($content) > self::MAX_ATTACHMENT_BYTES) throw new \InvalidArgumentException('Die PDF ist ungültig oder für den E-Mail-Versand zu groß (maximal 15 MB).');
		return [$record, $file, $content];
	}

	private function replyTo(): string {
		$email = trim((string)($this->users->getUser()?->getEMailAddress() ?? ''));
		return strlen($email) <= 320 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\r\n,;]/', $email) ? $email : '';
	}

	private function priorAttempt(int $caseId, int $recordId, string $recipient): ?array {
		$q = $this->db->getQueryBuilder();
		$row = $q->select('id', 'status')->from('bestatter_mail_outbox')
			->where($q->expr()->eq('case_id', $q->createNamedParameter($caseId)))
			->andWhere($q->expr()->eq('record_id', $q->createNamedParameter($recordId)))
			->andWhere($q->expr()->eq('recipient', $q->createNamedParameter($recipient)))
			->orderBy('id', 'DESC')->setMaxResults(1)->executeQuery()->fetchAssociative();
		return $row === false ? null : $row;
	}

	private function byKey(string $key): ?array {
		$q = $this->db->getQueryBuilder();
		$row = $q->select('id', 'case_id', 'record_id', 'created_by', 'status', 'recipient', 'subject', 'body', 'file_sha256')->from('bestatter_mail_outbox')
			->where($q->expr()->eq('request_key', $q->createNamedParameter($key)))->executeQuery()->fetchAssociative();
		return $row === false ? null : $row;
	}

	private function setStatus(int $id, string $status): void {
		$q = $this->db->getQueryBuilder();
		$q->update('bestatter_mail_outbox')->set('status', $q->createNamedParameter($status))
			->set('updated_at', $q->createNamedParameter(date('c')))
			->where($q->expr()->eq('id', $q->createNamedParameter($id)))->executeStatement();
	}
}
