<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IUserSession;

/**
 * Überführt einen Paperless-Eingang kontrolliert in den bestehenden Fachprozess.
 *
 * 0.80.0 (OP-058): Jedes gemeldete Dokument erscheint im Belegeingang. Die App liest Dokumenttyp, Text und das
 * benutzerdefinierte Feld „Fallnummer“ aus Paperless und schlägt Dokumentart und Fall vor (DocumentKindRules::suggest).
 * Die Wirkung der Dokumentart entscheidet, was bei der Zuordnung entsteht: abgelegtes Dokument, Eingangsrechnung,
 * bestätigte Abmeldung oder unterschriebener Vertrag zur Prüfung. Automatisch abgelegt wird nur bei eindeutiger
 * Fallnummer aus dem Paperless-Feld und einer Art aus dem Paperless-Dokumenttyp, die keine weiteren Angaben braucht –
 * und erst, wenn ein Mitglied den Belegeingang öffnet (Ablage im Namen dieser Person, auch bei persönlicher Fallablage).
 */
class PaperlessInboxService {
	private const ENRICH_PER_REQUEST = 15;

	public function __construct(
		private PaperlessService $paperless,
		private IncomingInvoiceService $incomingInvoices,
		private DocumentKindService $kinds,
		private CaseService $cases,
		private CaseFileService $caseFiles,
		private RecordService $records,
		private DeregistrationService $deregistrations,
		private GroupwareService $groupware,
		private ProcessService $process,
		private IUserSession $userSession,
	) {}

	public function inbox(): array {
		$inbox = $this->paperless->inbox();
		$autoFiled = [];
		if ($inbox['enabled']) {
			$enriched = 0;
			foreach ($inbox['items'] as $item) {
				if (!empty($item['metadata']['enrichedAt']) || $enriched >= self::ENRICH_PER_REQUEST) continue;
				$enriched++;
				$this->enrich($item);
			}
			if ($this->paperless->settings()['autoFile']) {
				foreach ($this->paperless->inbox()['items'] as $item) {
					$suggestion = (array)($item['metadata']['suggestion'] ?? []);
					if (empty($suggestion['autoFile']) || empty($suggestion['caseId']) || !empty($item['metadata']['autoFileTried'])) continue;
					$metadata = $item['metadata']; $metadata['autoFileTried'] = date('c');
					$this->paperless->updateInboxMetadata((int)$item['id'], $metadata);
					try {
						$result = $this->assign((int)$item['id'], (int)$suggestion['caseId'], (string)$suggestion['kindKey'], [], true);
						$autoFiled[] = ['title' => $item['title'], 'caseId' => (int)$suggestion['caseId'], 'caseNumber' => (string)($suggestion['caseNumber'] ?? ''), 'kind' => $result['kind']['name'], 'path' => $result['path'] ?? ''];
					} catch (\Throwable $error) {
						$metadata['autoFileError'] = mb_substr($error->getMessage(), 0, 300);
						$this->paperless->updateInboxMetadata((int)$item['id'], $metadata);
					}
				}
			}
			$inbox = $this->paperless->inbox();
		}
		$kinds = [];
		foreach ($this->kinds->definitions() as $kind) $kinds[$kind['key']] = $kind;
		$inbox['items'] = array_map(fn(array $item): array => $this->decorate($item, $kinds), $inbox['items']);
		$inbox['kinds'] = array_values(array_filter($kinds, static fn(array $kind): bool => $kind['active']));
		$inbox['caseNumberField'] = $this->paperless->settings()['caseNumberField'];
		$inbox['autoFiled'] = $autoFiled;
		return $inbox;
	}

	/**
	 * 0.81.0: Übersicht des Belegeingangs für den Bestatter-Assistenten – rein lesend: keine Abfrage bei Paperless,
	 * keine automatische Ablage. Verwendet die beim letzten Öffnen des Belegeingangs gespeicherten Vorschläge.
	 * Mit $caseId nur die Dokumente, die diesem Fall vorgeschlagen sind.
	 * @return array{enabled:bool,mode:string,total:int,pending:int,unrated:int,items:list<array<string,mixed>>}
	 */
	public function overview(int $caseId = 0): array {
		$inbox = $this->paperless->inbox();
		$kinds = [];
		foreach ($this->kinds->definitions() as $kind) $kinds[$kind['key']] = $kind;
		$items = [];
		$unrated = 0;
		foreach ($inbox['items'] as $item) {
			if (empty($item['metadata']['enrichedAt'])) $unrated++;
			$decorated = $this->decorate($item, $kinds);
			if ($caseId > 0 && (int)($decorated['suggestedCaseId'] ?? 0) !== $caseId) continue;
			$items[] = [
				'id' => (int)$decorated['id'], 'title' => (string)($decorated['title'] ?? ''), 'createdAt' => (string)($decorated['createdAt'] ?? ''),
				'kindKey' => $decorated['suggestedKind'], 'kindName' => $decorated['suggestedKind'] !== '' ? (string)$kinds[$decorated['suggestedKind']]['name'] : '',
				'caseId' => $decorated['suggestedCaseId'], 'caseNumber' => $decorated['suggestedCaseNumber'], 'caseName' => (string)($decorated['suggestedCaseName'] ?? ''),
				'caseReason' => $decorated['suggestedCaseReasonLabel'], 'candidates' => $decorated['caseCandidates'], 'rated' => !empty($item['metadata']['enrichedAt']),
			];
		}
		return ['enabled' => (bool)$inbox['enabled'], 'mode' => (string)$inbox['mode'], 'total' => count($inbox['items']), 'pending' => count($items), 'unrated' => $unrated, 'items' => $items];
	}

	/** Liest Dokumenttyp, Text und Fallnummer-Feld aus Paperless und speichert nur den Vorschlag (nicht den Text). */
	public function enrich(array $item): array {
		$metadata = $item['metadata'];
		try {
			$details = $this->paperless->documentDetails((string)$item['externalDocumentId']);
			$fieldName = mb_strtolower(trim($this->paperless->settings()['caseNumberField']));
			$fieldValue = '';
			foreach ($details['customFields'] as $name => $value) if ($fieldName !== '' && mb_strtolower(trim((string)$name)) === $fieldName) $fieldValue = is_scalar($value) ? (string)$value : '';
			$suggestion = DocumentKindRules::suggest(
				['documentTypeId' => $details['documentTypeId'], 'caseNumberField' => $fieldValue, 'content' => $details['content']],
				$this->kinds->definitions(),
				fn(string $number): ?array => $this->cases->findCaseByNumber($number),
				$this->cases->matchingCandidates(),
			);
			$metadata['paperless'] = ['documentTypeId' => $details['documentTypeId'], 'caseNumberField' => $fieldValue, 'tags' => $details['tags']];
			$metadata['suggestion'] = $suggestion;
			unset($metadata['enrichError']);
			$title = $details['title'];
		} catch (\Throwable $error) {
			$metadata['enrichError'] = mb_substr($error->getMessage(), 0, 300);
			$title = null;
		}
		$metadata['enrichedAt'] = date('c');
		return $this->paperless->updateInboxMetadata((int)$item['id'], $metadata, $title);
	}

	/** Vorschlag erneut ermitteln (z. B. nachdem in Paperless Dokumenttyp oder Fallnummer gesetzt wurden). */
	public function refresh(int $externalId): array {
		$item = $this->paperless->inboxItem($externalId);
		$metadata = $item['metadata']; unset($metadata['enrichedAt'], $metadata['autoFileTried'], $metadata['autoFileError']);
		$item = $this->paperless->updateInboxMetadata($externalId, $metadata);
		$kinds = [];
		foreach ($this->kinds->definitions() as $kind) $kinds[$kind['key']] = $kind;
		return $this->decorate($this->enrich($item), $kinds);
	}

	/**
	 * Ordnet einen Eingang einem Fall zu. $data je nach Wirkung: Rechnungsfelder (INCOMING_INVOICE), title/count
	 * (FILE), deregistrationId (DEREGISTRATION), nichts weiter (PAPER_CONTRACT).
	 */
	public function assign(int $externalId, int $caseId, string $kindKey, array $data, bool $automatic = false): array {
		$item = $this->paperless->inboxItem($externalId);
		$kind = $this->kinds->activeDefinition($kindKey !== '' ? $kindKey : 'EINGANGSRECHNUNG');
		$case = $this->cases->getCase($caseId);
		if ((string)($case['anonymizedAt'] ?? '') !== '') throw new \InvalidArgumentException('Einem anonymisierten Fall können keine Dokumente zugeordnet werden.');
		$deregistration = $kind['effect'] === 'DEREGISTRATION' ? $this->openDeregistration($caseId, (int)($data['deregistrationId'] ?? 0)) : null;
		$count = 0;
		if ($kind['countLabel'] !== '' && trim((string)($data['count'] ?? '')) !== '') {
			$count = (int)$data['count'];
			if ($count < 1 || $count > 99) throw new \InvalidArgumentException('Bitte eine Anzahl ' . $kind['countLabel'] . ' zwischen 1 und 99 angeben.');
		}
		$paperlessDocumentId = (string)$item['externalDocumentId'];
		$download = $this->paperless->downloadDocument($paperlessDocumentId);
		if ($kind['effect'] === 'PAPER_CONTRACT' && $download['extension'] !== 'pdf') throw new \InvalidArgumentException('Ein unterschriebener Vertrag muss als PDF vorliegen.');
		$temp = tempnam(sys_get_temp_dir(), 'bestatter-paperless-');
		if ($temp === false) throw new \RuntimeException('Für den Paperless-Import konnte keine temporäre Datei angelegt werden.');
		try {
			if (file_put_contents($temp, $download['content'], LOCK_EX) === false) throw new \RuntimeException('Das Paperless-Dokument konnte nicht temporär gespeichert werden.');
			$upload = ['name' => $this->fileName($kind, $paperlessDocumentId, $download['extension']), 'tmp_name' => $temp, 'size' => strlen($download['content']), 'error' => UPLOAD_ERR_OK, 'type' => $download['mimeType']];
			$extra = ['source' => 'PAPERLESS', 'documentKind' => $kind['key'], 'paperlessDocumentId' => $paperlessDocumentId] + ($count > 0 ? ['documentCount' => $count] : []);
			$folder = $this->kinds->folderName($kind);
			if ($kind['effect'] === 'INCOMING_INVOICE') {
				$data = $this->withClassification($data, $kind);
				$invoice = $this->incomingInvoices->create($caseId, $data, $upload, 'PAPERLESS', $paperlessDocumentId, false, $kind['key'], $kind['name'], $folder, $extra);
				$link = $this->paperless->linkImported($externalId, $invoice, $kind['key']);
				$this->process->reconcileQuietly($caseId);
				return ['kind' => $kind, 'invoice' => $invoice, 'externalDocument' => $link, 'openTab' => 'finances', 'path' => (string)($invoice['filePath'] ?? ''),
					'nextStep' => sprintf('%s im Ordner „%s“ gespeichert und als Eingangsrechnung erfasst. Bitte im Reiter „Finanzen“ Positionen prüfen und Leistungen zuordnen.', $kind['name'], $folder)];
			}
			$title = trim((string)($data['title'] ?? ''));
			if ($title === '') $title = $kind['name'] . ($count > 0 ? sprintf(' (%d %s)', $count, $kind['countLabel']) : '') . ($item['title'] !== '' && mb_strtolower($item['title']) !== mb_strtolower($kind['name']) ? ' – ' . $item['title'] : '');
			$title = mb_substr($title, 0, 200);
			$stored = $kind['effect'] === 'PAPER_CONTRACT'
				? $this->caseFiles->uploadPaperContract($case, $upload, 'EXTERNAL', 0, $folder, $extra)
				: $this->caseFiles->upload($case, $upload, $folder, $kind['category'], $title, [], $extra);
			$record = $stored['record']; $file = $stored['file'];
			$link = $this->paperless->linkInboxDocument($externalId, $caseId, (int)$record['id'], (int)$file['fileId'], hash('sha256', $download['content']), 'CASE_DOCUMENT', $kind['key'], $automatic);
			$messages = [sprintf('%s im Ordner „%s“ abgelegt.', $kind['name'], $file['subfolder'] ?? $folder)];
			if ($deregistration !== null) $messages[] = $this->confirmDeregistration($deregistration, $record, $file);
			if ($kind['effect'] === 'PAPER_CONTRACT') $messages[] = 'Bitte im Reiter „Dokumente“ die Unterschriften prüfen und bestätigen.';
			$this->process->reconcileQuietly($caseId);
			return ['kind' => $kind, 'record' => $record, 'file' => $file, 'externalDocument' => $link, 'openTab' => 'document', 'path' => (string)($file['path'] ?? ''), 'nextStep' => implode(' ', $messages)];
		} finally {
			if (is_file($temp)) @unlink($temp);
		}
	}

	public function discard(int $externalId, string $reason): array {
		return $this->paperless->discardInbox($externalId, $reason, (string)($this->userSession->getUser()?->getUID() ?? ''));
	}

	/** Offene Abmeldungen eines Falls, deren Rückmeldung aussteht (für die Auswahl im Zuordnungsdialog). */
	public function openDeregistrations(int $caseId): array {
		return array_values(array_map(static fn(array $item): array => ['id' => (int)$item['id'], 'title' => (string)$item['title'], 'status' => (string)$item['status'], 'sentAt' => (string)($item['data']['sentAt'] ?? '')],
			array_filter($this->deregistrations->list($caseId), static fn(array $item): bool => strtoupper((string)$item['status']) === 'VERSENDET')));
	}

	// ------------------------------------------------------------------ intern

	private function openDeregistration(int $caseId, int $deregistrationId): ?array {
		if ($deregistrationId <= 0) return null;
		$record = $this->records->get($deregistrationId);
		if ($record['type'] !== 'deregistration' || (int)$record['caseId'] !== $caseId) throw new \InvalidArgumentException('Die gewählte Abmeldung gehört nicht zu diesem Fall.');
		if (strtoupper((string)$record['status']) !== 'VERSENDET') throw new \InvalidArgumentException('Die gewählte Abmeldung ist nicht versendet oder bereits bestätigt.');
		return $record;
	}

	/** Abmeldung bestätigen, Rückmeldung verknüpfen und die Aufgabe „Rückmeldung prüfen“ erledigen. */
	private function confirmDeregistration(array $deregistration, array $record, array $file): string {
		try {
			$this->deregistrations->confirmReceipt((int)$deregistration['id'], ['confirmationRecordId' => (int)$record['id'], 'confirmationFileId' => (int)$file['fileId'], 'confirmationPath' => (string)($file['path'] ?? ''), 'confirmationSource' => 'PAPERLESS']);
		} catch (\Throwable $error) {
			return sprintf('Die Abmeldung „%s“ konnte nicht bestätigt werden (%s) – bitte im Reiter „Abmeldungen“ nachholen.', $deregistration['title'], $error->getMessage());
		}
		$closed = 0;
		foreach ($this->records->list('task', (int)$deregistration['caseId']) as $task) {
			if ((int)($task['data']['deregistrationId'] ?? 0) !== (int)$deregistration['id'] || in_array(strtoupper((string)$task['status']), ['ERLEDIGT', 'ABGEBROCHEN'], true)) continue;
			try { $this->groupware->update(array_replace($task, ['status' => 'ERLEDIGT', 'data' => $task['data'] + ['completedReason' => 'Rückmeldung der Abmeldestelle eingegangen']]), (string)($task['ownerUid'] ?? '')); $closed++; }
			catch (\Throwable) { /* Aufgabe bleibt offen; die Abmeldung ist bestätigt */ }
		}
		return sprintf('Abmeldung „%s“ als bestätigt markiert%s.', $deregistration['title'], $closed > 0 ? ', Aufgabe „Rückmeldung prüfen“ erledigt' : '');
	}

	/** Positionen ohne eigene Einordnung erhalten die Einordnung der Dokumentart (z. B. Gebührenbescheid → durchlaufender Posten). */
	private function withClassification(array $data, array $kind): array {
		$classification = $kind['classification'] !== '' ? $kind['classification'] : 'CLASSIFICATION_PENDING';
		foreach ((array)($data['items'] ?? []) as $index => $line) {
			if (!is_array($line)) continue;
			if (trim((string)($line['classification'] ?? '')) === '') $data['items'][$index]['classification'] = $classification;
		}
		return $data;
	}

	private function fileName(array $kind, string $paperlessDocumentId, string $extension): string {
		$base = preg_replace('/[\\\\\/:*?"<>|]+/u', '-', $kind['name']) ?? 'Dokument';
		return sprintf('%s (Paperless %s).%s', trim($base), $paperlessDocumentId, $extension);
	}

	private function decorate(array $item, array $kinds): array {
		$suggestion = (array)($item['metadata']['suggestion'] ?? []);
		$kindKey = (string)($suggestion['kindKey'] ?? '');
		// Eingänge aus der Zeit vor 0.80.0 waren immer Eingangsrechnungen – solange Paperless keinen anderen Typ nennt.
		if ($item['recordType'] === 'INCOMING_INVOICE' && ($suggestion['kindSource'] ?? '') !== 'PAPERLESS_TYPE') { $kindKey = 'EINGANGSRECHNUNG'; $suggestion['kindSource'] = 'LEGACY'; }
		$item['suggestedKind'] = $kindKey !== '' && isset($kinds[$kindKey]) && $kinds[$kindKey]['active'] ? $kindKey : '';
		$item['suggestedKindSource'] = (string)($suggestion['kindSource'] ?? '');
		$item['suggestedCaseId'] = isset($suggestion['caseId']) && $suggestion['caseId'] ? (int)$suggestion['caseId'] : null;
		$item['suggestedCaseNumber'] = (string)($suggestion['caseNumber'] ?? '');
		$item['suggestedCaseReason'] = (string)($suggestion['caseReason'] ?? '');
		$item['suggestedCaseReasonLabel'] = DocumentKindRules::CASE_REASONS[$item['suggestedCaseReason']] ?? '';
		$item['caseCandidates'] = (int)($suggestion['caseCandidates'] ?? 0);
		$item['paperlessDocumentTypeId'] = $item['metadata']['paperless']['documentTypeId'] ?? null;
		$item['enrichError'] = (string)($item['metadata']['enrichError'] ?? '');
		$item['autoFileError'] = (string)($item['metadata']['autoFileError'] ?? '');
		if ($item['suggestedCaseId'] !== null) {
			try { $case = $this->cases->getCase($item['suggestedCaseId']); $item['suggestedCaseName'] = trim($case['firstName'] . ' ' . $case['lastName']); }
			catch (\Throwable) { $item['suggestedCaseId'] = null; $item['suggestedCaseNumber'] = ''; $item['suggestedCaseReason'] = ''; $item['suggestedCaseReasonLabel'] = ''; }
		}
		return $item;
	}
}
