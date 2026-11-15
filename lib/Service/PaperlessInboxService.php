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
 *
 * 0.90.1: Weitere Quellen ohne Paperless (InboxFolderService): Eingangsordner in Nextcloud und Direkt-Upload. Für diese
 * Eingänge liest die App die Textebene von PDFs, eingebettete bzw. einzelne E-Rechnungen (XRechnung, ZUGFeRD) und – für
 * Scans – den Text der Nextcloud-Texterkennung. E-Rechnungen belegen die Eingangsrechnung mit Kopf und Positionen vor.
 * Automatisch abgelegt wird aus diesen Quellen nie.
 */
class PaperlessInboxService {
	private const ENRICH_PER_REQUEST = 15;
	/** 0.90.1: Herkunft des Dokumentartvorschlags für Eingänge ohne Paperless-Dokumenttyp. */
	public const KIND_SOURCE_E_INVOICE = 'E_INVOICE';
	public const KIND_SOURCE_TEXT = 'TEXT';
	private ?array $candidates = null;

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
		private InboxFolderService $folderInbox,
	) {
	}

	public function inbox(): array {
		$inbox = $this->paperless->inbox();
		$autoFiled = [];
		if ($inbox['enabled']) {
			$enriched = 0;
			foreach ($inbox['items'] as $item) {
				if (!empty($item['metadata']['enrichedAt']) || $enriched >= self::ENRICH_PER_REQUEST) {
					continue;
				}
				$enriched++;
				$this->enrich($item);
			}
			if ($this->paperless->settings()['autoFile']) {
				foreach ($this->paperless->inbox()['items'] as $item) {
					$suggestion = (array)($item['metadata']['suggestion'] ?? []);
					if (empty($suggestion['autoFile']) || empty($suggestion['caseId']) || !empty($item['metadata']['autoFileTried'])) {
						continue;
					}
					$metadata = $item['metadata'];
					$metadata['autoFileTried'] = date('c');
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
		// 0.90.1: Eingangsordner und Direkt-Upload – Ordner lesen, Vorschläge ermitteln, Texterkennung abfragen.
		$folder = $this->userSession->getUser() !== null ? $this->folderInbox->scanCurrentUser() : ['path' => $this->folderInbox->relativePath(), 'found' => 0, 'folderId' => null, 'error' => ''];
		$this->enrichFolderItems(self::ENRICH_PER_REQUEST);
		$kinds = [];
		foreach ($this->kinds->definitions() as $kind) {
			$kinds[$kind['key']] = $kind;
		}
		$items = array_merge($inbox['items'], $this->folderInbox->items());
		usort($items, static fn (array $a, array $b): int => strcmp((string)$b['createdAt'], (string)$a['createdAt']));
		$inbox['items'] = array_map(fn (array $item): array => $this->decorate($item, $kinds), $items);
		$ocr = $this->folderInbox->ocrAvailability();
		$inbox['folder'] = ['path' => $folder['path'], 'error' => $folder['error'], 'ocrAvailable' => $ocr['available'], 'ocrReason' => $ocr['reason'], 'maxBytes' => InboxFolderService::MAX_BYTES, 'extensions' => InboxFolderService::ALLOWED_EXTENSIONS];
		$inbox['kinds'] = array_values(array_filter($kinds, static fn (array $kind): bool => $kind['active']));
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
		// 0.90.1: auch Eingangsordner und Direkt-Upload (gespeicherte Vorschläge, ohne den Ordner zu lesen)
		$inbox['items'] = array_merge($inbox['items'], $this->folderInbox->items());
		$kinds = [];
		foreach ($this->kinds->definitions() as $kind) {
			$kinds[$kind['key']] = $kind;
		}
		$items = [];
		$unrated = 0;
		foreach ($inbox['items'] as $item) {
			if (empty($item['metadata']['enrichedAt'])) {
				$unrated++;
			}
			$decorated = $this->decorate($item, $kinds);
			if ($caseId > 0 && (int)($decorated['suggestedCaseId'] ?? 0) !== $caseId) {
				continue;
			}
			$items[] = [
				'id' => (int)$decorated['id'], 'title' => (string)($decorated['title'] ?? ''), 'createdAt' => (string)($decorated['createdAt'] ?? ''),
				'kindKey' => $decorated['suggestedKind'], 'kindName' => $decorated['suggestedKind'] !== '' ? (string)$kinds[$decorated['suggestedKind']]['name'] : '',
				'caseId' => $decorated['suggestedCaseId'], 'caseNumber' => $decorated['suggestedCaseNumber'], 'caseName' => (string)($decorated['suggestedCaseName'] ?? ''),
				'caseReason' => $decorated['suggestedCaseReasonLabel'], 'candidates' => $decorated['caseCandidates'], 'rated' => !empty($item['metadata']['enrichedAt']),
			];
		}
		return ['enabled' => (bool)$inbox['enabled'] || $this->folderInbox->items() !== [], 'mode' => (string)$inbox['mode'], 'total' => count($inbox['items']), 'pending' => count($items), 'unrated' => $unrated, 'items' => $items];
	}

	/** Liest Dokumenttyp, Text und Fallnummer-Feld aus Paperless und speichert nur den Vorschlag (nicht den Text). */
	public function enrich(array $item): array {
		$metadata = $item['metadata'];
		try {
			$details = $this->paperless->documentDetails((string)$item['externalDocumentId']);
			$fieldName = mb_strtolower(trim($this->paperless->settings()['caseNumberField']));
			$fieldValue = '';
			foreach ($details['customFields'] as $name => $value) {
				if ($fieldName !== '' && mb_strtolower(trim((string)$name)) === $fieldName) {
					$fieldValue = is_scalar($value) ? (string)$value : '';
				}
			}
			$suggestion = DocumentKindRules::suggest(
				['documentTypeId' => $details['documentTypeId'], 'caseNumberField' => $fieldValue, 'content' => $details['content']],
				$this->kinds->definitions(),
				fn (string $number): ?array => $this->cases->findCaseByNumber($number),
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

	/**
	 * 0.90.1: Hintergrundjob – Eingangsordner aller Personen lesen und Vorschläge ermitteln (inkl. Texterkennung).
	 * @return array{found:int,enriched:int}
	 */
	public function backgroundRun(int $limit = 10): array {
		$found = $this->folderInbox->scanAll();
		return ['found' => $found, 'enriched' => $this->enrichFolderItems($limit)];
	}

	/**
	 * 0.90.1: Vorschlag für einen Eingang aus Eingangsordner oder Upload: E-Rechnung (einzeln oder im PDF), Textebene
	 * des PDFs oder – für Scans und Bilder – die Nextcloud-Texterkennung. Gespeichert werden nur Vorschlag und die
	 * Rechnungsangaben der E-Rechnung, nicht der Text.
	 */
	public function enrichFolder(array $item): array {
		$metadata = $item['metadata'];
		unset($metadata['enrichError']);
		$text = '';
		$eInvoice = null;
		try {
			$file = $this->folderInbox->content($item);
			if ($file['extension'] === 'xml') {
				$eInvoice = EInvoiceReader::read($file['content']);
				if ($eInvoice === null) {
					$metadata['enrichError'] = 'Die XML-Datei ist keine lesbare E-Rechnung (XRechnung oder ZUGFeRD/Factur-X).';
				}
			} elseif ($file['extension'] === 'pdf') {
				foreach (Pdf\PdfTextExtractor::embeddedXmlFiles($file['content']) as $xml) {
					$eInvoice = EInvoiceReader::read($xml);
					if ($eInvoice !== null) {
						break;
					}
				}
				$text = Pdf\PdfTextExtractor::extract($file['content']);
			}
			if ($eInvoice !== null) {
				$metadata['eInvoice'] = $eInvoice;
				$metadata['textSource'] = 'E_INVOICE';
				$text = trim(EInvoiceReader::searchText($eInvoice) . "\n" . $text);
			} elseif (preg_match_all('/\p{L}/u', $text) >= 20) {
				$metadata['textSource'] = 'PDF_TEXT';
			} elseif ($file['extension'] !== 'xml') {
				// Scan oder Bild ohne Textebene: Nextcloud-Texterkennung (falls eingerichtet und freigegeben).
				$text = '';
				$metadata['textSource'] = 'NONE';
				if (empty($metadata['ocr']['status'])) {
					$metadata['ocr'] = $this->folderInbox->startOcr($item) + ['startedAt' => date('c')];
				}
			}
		} catch (\Throwable $error) {
			$metadata['enrichError'] = mb_substr($error->getMessage(), 0, 300);
		}
		$metadata['suggestion'] = $this->folderSuggestion((string)($metadata['name'] ?? $item['title']), $text, $eInvoice);
		$metadata['enrichedAt'] = date('c');
		return $this->folderInbox->updateMetadata((int)$item['id'], $metadata);
	}

	/** Vorschlag erneut ermitteln (z. B. nachdem in Paperless Dokumenttyp oder Fallnummer gesetzt wurden). */
	public function refresh(int $externalId): array {
		if ($this->isFolderItem($externalId)) {
			$item = $this->folderInbox->inboxItem($externalId);
			$kinds = [];
			foreach ($this->kinds->definitions() as $kind) {
				$kinds[$kind['key']] = $kind;
			}
			if (in_array((string)($item['metadata']['ocr']['status'] ?? ''), ['QUEUED', 'RUNNING'], true)) {
				// Laufende Texterkennung nur abfragen, nicht ein zweites Mal starten.
				$this->applyOcr($item);
				return $this->decorate($this->folderInbox->inboxItem($externalId), $kinds);
			}
			$metadata = array_diff_key($item['metadata'], ['enrichedAt' => true, 'suggestion' => true, 'eInvoice' => true, 'ocr' => true, 'textSource' => true, 'enrichError' => true]);
			$item = $this->folderInbox->updateMetadata($externalId, $metadata);
			return $this->decorate($this->enrichFolder($item), $kinds);
		}
		$item = $this->paperless->inboxItem($externalId);
		$metadata = $item['metadata'];
		unset($metadata['enrichedAt'], $metadata['autoFileTried'], $metadata['autoFileError']);
		$item = $this->paperless->updateInboxMetadata($externalId, $metadata);
		$kinds = [];
		foreach ($this->kinds->definitions() as $kind) {
			$kinds[$kind['key']] = $kind;
		}
		return $this->decorate($this->enrich($item), $kinds);
	}

	/**
	 * Ordnet einen Eingang einem Fall zu. $data je nach Wirkung: Rechnungsfelder (INCOMING_INVOICE), title/count
	 * (FILE), deregistrationId (DEREGISTRATION), nichts weiter (PAPER_CONTRACT).
	 */
	public function assign(int $externalId, int $caseId, string $kindKey, array $data, bool $automatic = false): array {
		if ($this->isFolderItem($externalId)) {
			return $this->assignFolderItem($externalId, $caseId, $kindKey, $data);
		}
		$item = $this->paperless->inboxItem($externalId);
		$kind = $this->kinds->activeDefinition($kindKey !== '' ? $kindKey : 'EINGANGSRECHNUNG');
		$case = $this->cases->getCase($caseId);
		if ((string)($case['anonymizedAt'] ?? '') !== '') {
			throw new \InvalidArgumentException('Einem anonymisierten Fall können keine Dokumente zugeordnet werden.');
		}
		$deregistration = $kind['effect'] === 'DEREGISTRATION' ? $this->openDeregistration($caseId, (int)($data['deregistrationId'] ?? 0)) : null;
		$count = 0;
		if ($kind['countLabel'] !== '' && trim((string)($data['count'] ?? '')) !== '') {
			$count = (int)$data['count'];
			if ($count < 1 || $count > 99) {
				throw new \InvalidArgumentException('Bitte eine Anzahl ' . $kind['countLabel'] . ' zwischen 1 und 99 angeben.');
			}
		}
		$paperlessDocumentId = (string)$item['externalDocumentId'];
		$download = $this->paperless->downloadDocument($paperlessDocumentId);
		if ($kind['effect'] === 'PAPER_CONTRACT' && $download['extension'] !== 'pdf') {
			throw new \InvalidArgumentException('Ein unterschriebener Vertrag muss als PDF vorliegen.');
		}
		$temp = tempnam(sys_get_temp_dir(), 'bestatter-paperless-');
		if ($temp === false) {
			throw new \RuntimeException('Für den Paperless-Import konnte keine temporäre Datei angelegt werden.');
		}
		try {
			if (file_put_contents($temp, $download['content'], LOCK_EX) === false) {
				throw new \RuntimeException('Das Paperless-Dokument konnte nicht temporär gespeichert werden.');
			}
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
			if ($title === '') {
				$title = $kind['name'] . ($count > 0 ? sprintf(' (%d %s)', $count, $kind['countLabel']) : '') . ($item['title'] !== '' && mb_strtolower($item['title']) !== mb_strtolower($kind['name']) ? ' – ' . $item['title'] : '');
			}
			$title = mb_substr($title, 0, 200);
			$stored = $kind['effect'] === 'PAPER_CONTRACT'
				? $this->caseFiles->uploadPaperContract($case, $upload, 'EXTERNAL', 0, $folder, $extra)
				: $this->caseFiles->upload($case, $upload, $folder, $kind['category'], $title, [], $extra);
			$record = $stored['record'];
			$file = $stored['file'];
			$link = $this->paperless->linkInboxDocument($externalId, $caseId, (int)$record['id'], (int)$file['fileId'], hash('sha256', $download['content']), 'CASE_DOCUMENT', $kind['key'], $automatic);
			$messages = [sprintf('%s im Ordner „%s“ abgelegt.', $kind['name'], $file['subfolder'] ?? $folder)];
			if ($deregistration !== null) {
				$messages[] = $this->confirmDeregistration($deregistration, $record, $file);
			}
			if ($kind['effect'] === 'PAPER_CONTRACT') {
				$messages[] = 'Bitte im Reiter „Dokumente“ die Unterschriften prüfen und bestätigen.';
			}
			$this->process->reconcileQuietly($caseId);
			return ['kind' => $kind, 'record' => $record, 'file' => $file, 'externalDocument' => $link, 'openTab' => 'document', 'path' => (string)($file['path'] ?? ''), 'nextStep' => implode(' ', $messages)];
		} finally {
			if (is_file($temp)) {
				@unlink($temp);
			}
		}
	}

	public function discard(int $externalId, string $reason): array {
		if ($this->isFolderItem($externalId)) {
			return $this->folderInbox->discard($externalId, $reason);
		}
		return $this->paperless->discardInbox($externalId, $reason, (string)($this->userSession->getUser()?->getUID() ?? ''));
	}

	/** Offene Abmeldungen eines Falls, deren Rückmeldung aussteht (für die Auswahl im Zuordnungsdialog). */
	public function openDeregistrations(int $caseId): array {
		return array_values(array_map(static fn (array $item): array => ['id' => (int)$item['id'], 'title' => (string)$item['title'], 'status' => (string)$item['status'], 'sentAt' => (string)($item['data']['sentAt'] ?? '')],
			array_filter($this->deregistrations->list($caseId), static fn (array $item): bool => strtoupper((string)$item['status']) === 'VERSENDET')));
	}

	// ------------------------------------------------------------------ intern

	private function isFolderItem(int $externalId): bool {
		return ($this->folderInbox->find($externalId)['provider'] ?? '') === InboxFolderService::PROVIDER;
	}

	/** Noch nicht bewertete Eingänge aus Eingangsordner/Upload bewerten und laufende Texterkennungen abfragen. */
	private function enrichFolderItems(int $limit): int {
		$done = 0;
		foreach ($this->folderInbox->items() as $item) {
			$ocrStatus = (string)($item['metadata']['ocr']['status'] ?? '');
			if (in_array($ocrStatus, ['QUEUED', 'RUNNING'], true)) {
				$this->applyOcr($item);
				continue;
			}
			if (!empty($item['metadata']['enrichedAt']) || $done >= $limit) {
				continue;
			}
			$this->enrichFolder($item);
			$done++;
		}
		return $done;
	}

	/** Ergebnis der Nextcloud-Texterkennung übernehmen (nur der Vorschlag wird gespeichert). */
	private function applyOcr(array $item): void {
		$result = $this->folderInbox->pollOcr($item);
		$metadata = $item['metadata'];
		$metadata['ocr']['status'] = $result['status'];
		$metadata['ocr']['message'] = $result['message'];
		if ($result['status'] === 'DONE' && $result['text'] !== '') {
			$metadata['textSource'] = 'OCR';
			$metadata['suggestion'] = $this->folderSuggestion((string)($metadata['name'] ?? $item['title']), $result['text'], null);
		}
		if (($item['metadata']['ocr']['status'] ?? '') !== $result['status'] || $result['status'] === 'DONE') {
			$this->folderInbox->updateMetadata((int)$item['id'], $metadata);
		}
	}

	/** Vorschlag für Art und Fall aus Dateiname, Text und E-Rechnung (nie automatische Ablage). */
	private function folderSuggestion(string $fileName, string $text, ?array $eInvoice): array {
		$definitions = $this->kinds->definitions();
		$this->candidates ??= $this->cases->matchingCandidates();
		$suggestion = DocumentKindRules::suggest(['documentTypeId' => null, 'caseNumberField' => '', 'content' => $fileName . "\n" . $text], $definitions, fn (string $number): ?array => $this->cases->findCaseByNumber($number), $this->candidates);
		$kind = null;
		$source = '';
		if ($eInvoice !== null && !$eInvoice['creditNote']) {
			foreach ($definitions as $definition) {
				if ($definition['key'] === 'EINGANGSRECHNUNG' && !empty($definition['active'])) {
					$kind = $definition;
				}
			}
			$source = self::KIND_SOURCE_E_INVOICE;
		}
		if ($kind === null) {
			$kind = DocumentKindRules::kindFromText($fileName, $text, $definitions);
			$source = self::KIND_SOURCE_TEXT;
		}
		if ($kind !== null) {
			$suggestion['kindKey'] = (string)$kind['key'];
			$suggestion['kindSource'] = $source;
		}
		$suggestion['autoFile'] = false;
		return $suggestion;
	}

	/**
	 * 0.90.1: Eingang aus Eingangsordner oder Upload zuordnen. Die Datei wird in den Fallordner übernommen und danach
	 * aus dem Eingangsordner entfernt. Bei einer E-Rechnung kommen die Positionen auf Wunsch aus der XML (serverseitig
	 * neu gebildet, nicht aus dem Formular).
	 */
	private function assignFolderItem(int $externalId, int $caseId, string $kindKey, array $data): array {
		$item = $this->folderInbox->inboxItem($externalId);
		$kind = $this->kinds->activeDefinition($kindKey !== '' ? $kindKey : 'EINGANGSRECHNUNG');
		$case = $this->cases->getCase($caseId);
		if ((string)($case['anonymizedAt'] ?? '') !== '') {
			throw new \InvalidArgumentException('Einem anonymisierten Fall können keine Dokumente zugeordnet werden.');
		}
		$deregistration = $kind['effect'] === 'DEREGISTRATION' ? $this->openDeregistration($caseId, (int)($data['deregistrationId'] ?? 0)) : null;
		$count = 0;
		if ($kind['countLabel'] !== '' && trim((string)($data['count'] ?? '')) !== '') {
			$count = (int)$data['count'];
			if ($count < 1 || $count > 99) {
				throw new \InvalidArgumentException('Bitte eine Anzahl ' . $kind['countLabel'] . ' zwischen 1 und 99 angeben.');
			}
		}
		$download = $this->folderInbox->content($item);
		if ($kind['effect'] === 'PAPER_CONTRACT' && $download['extension'] !== 'pdf') {
			throw new \InvalidArgumentException('Ein unterschriebener Vertrag muss als PDF vorliegen.');
		}
		$channel = (string)($item['metadata']['channel'] ?? 'FOLDER');
		$temp = tempnam(sys_get_temp_dir(), 'bestatter-inbox-');
		if ($temp === false) {
			throw new \RuntimeException('Für die Übernahme konnte keine temporäre Datei angelegt werden.');
		}
		try {
			if (file_put_contents($temp, $download['content'], LOCK_EX) === false) {
				throw new \RuntimeException('Die Datei konnte nicht temporär gespeichert werden.');
			}
			$upload = ['name' => $download['name'], 'tmp_name' => $temp, 'size' => strlen($download['content']), 'error' => UPLOAD_ERR_OK, 'type' => $download['mimeType']];
			$extra = ['source' => 'BELEGEINGANG', 'documentKind' => $kind['key'], 'inboxChannel' => $channel, 'inboxFileName' => $download['name']] + ($count > 0 ? ['documentCount' => $count] : []);
			$folder = $this->kinds->folderName($kind);
			$sha = hash('sha256', $download['content']);
			if ($kind['effect'] === 'INCOMING_INVOICE') {
				$eInvoice = is_array($item['metadata']['eInvoice'] ?? null) ? $item['metadata']['eInvoice'] : null;
				if (!empty($data['useEInvoiceItems']) && $eInvoice !== null) {
					$draft = EInvoiceReader::toIncomingInvoice($eInvoice, $kind['classification'] !== '' ? $kind['classification'] : 'CLASSIFICATION_PENDING');
					$data['items'] = $draft['data']['items'];
				}
				unset($data['useEInvoiceItems']);
				$data = $this->withClassification($data, $kind);
				$invoice = $this->incomingInvoices->create($caseId, $data, $upload, 'BELEGEINGANG', 'nextcloud:' . (string)$item['externalDocumentId'], true, $kind['key'], $kind['name'], $folder, $extra);
				$link = $this->folderInbox->link($externalId, $caseId, (int)$invoice['id'], (int)($invoice['fileId'] ?? 0), $sha, 'INCOMING_INVOICE', $kind['key']);
				$removed = $this->folderInbox->removeOriginal($item);
				$this->process->reconcileQuietly($caseId);
				return ['kind' => $kind, 'invoice' => $invoice, 'externalDocument' => $link, 'openTab' => 'finances', 'path' => (string)($invoice['filePath'] ?? ''),
					'nextStep' => sprintf('%s im Ordner „%s“ gespeichert und als Eingangsrechnung erfasst. Bitte im Reiter „Finanzen“ Positionen prüfen und Leistungen zuordnen.', $kind['name'], $folder) . ' ' . $this->removalNote($removed)];
			}
			$title = trim((string)($data['title'] ?? ''));
			if ($title === '') {
				$base = pathinfo($download['name'], PATHINFO_FILENAME);
				$title = $kind['name'] . ($count > 0 ? sprintf(' (%d %s)', $count, $kind['countLabel']) : '') . ($base !== '' && mb_strtolower($base) !== mb_strtolower($kind['name']) ? ' – ' . $base : '');
			}
			$title = mb_substr($title, 0, 200);
			$stored = $kind['effect'] === 'PAPER_CONTRACT'
				? $this->caseFiles->uploadPaperContract($case, $upload, 'EXTERNAL', 0, $folder, $extra)
				: $this->caseFiles->upload($case, $upload, $folder, $kind['category'], $title, [], $extra);
			$record = $stored['record'];
			$file = $stored['file'];
			$link = $this->folderInbox->link($externalId, $caseId, (int)$record['id'], (int)$file['fileId'], $sha, 'CASE_DOCUMENT', $kind['key']);
			$removed = $this->folderInbox->removeOriginal($item);
			$messages = [sprintf('%s im Ordner „%s“ abgelegt.', $kind['name'], $file['subfolder'] ?? $folder)];
			if ($deregistration !== null) {
				$messages[] = $this->confirmDeregistration($deregistration, $record, $file, 'BELEGEINGANG');
			}
			if ($kind['effect'] === 'PAPER_CONTRACT') {
				$messages[] = 'Bitte im Reiter „Dokumente“ die Unterschriften prüfen und bestätigen.';
			}
			$messages[] = $this->removalNote($removed);
			$this->process->reconcileQuietly($caseId);
			return ['kind' => $kind, 'record' => $record, 'file' => $file, 'externalDocument' => $link, 'openTab' => 'document', 'path' => (string)($file['path'] ?? ''), 'nextStep' => implode(' ', $messages)];
		} finally {
			if (is_file($temp)) {
				@unlink($temp);
			}
		}
	}

	private function removalNote(bool $removed): string {
		return $removed
			? 'Die Datei wurde aus dem Eingangsordner entfernt (Nextcloud-Papierkorb).'
			: 'Die Datei konnte nicht aus dem Eingangsordner entfernt werden – bitte dort von Hand löschen.';
	}

	private function openDeregistration(int $caseId, int $deregistrationId): ?array {
		if ($deregistrationId <= 0) {
			return null;
		}
		$record = $this->records->get($deregistrationId);
		if ($record['type'] !== 'deregistration' || (int)$record['caseId'] !== $caseId) {
			throw new \InvalidArgumentException('Die gewählte Abmeldung gehört nicht zu diesem Fall.');
		}
		if (strtoupper((string)$record['status']) !== 'VERSENDET') {
			throw new \InvalidArgumentException('Die gewählte Abmeldung ist nicht versendet oder bereits bestätigt.');
		}
		return $record;
	}

	/** Abmeldung bestätigen, Rückmeldung verknüpfen und die Aufgabe „Rückmeldung prüfen“ erledigen. */
	private function confirmDeregistration(array $deregistration, array $record, array $file, string $source = 'PAPERLESS'): string {
		try {
			$this->deregistrations->confirmReceipt((int)$deregistration['id'], ['confirmationRecordId' => (int)$record['id'], 'confirmationFileId' => (int)$file['fileId'], 'confirmationPath' => (string)($file['path'] ?? ''), 'confirmationSource' => $source]);
		} catch (\Throwable $error) {
			return sprintf('Die Abmeldung „%s“ konnte nicht bestätigt werden (%s) – bitte im Reiter „Abmeldungen“ nachholen.', $deregistration['title'], $error->getMessage());
		}
		$closed = 0;
		foreach ($this->records->list('task', (int)$deregistration['caseId']) as $task) {
			if ((int)($task['data']['deregistrationId'] ?? 0) !== (int)$deregistration['id'] || in_array(strtoupper((string)$task['status']), ['ERLEDIGT', 'ABGEBROCHEN'], true)) {
				continue;
			}
			try {
				$this->groupware->update(array_replace($task, ['status' => 'ERLEDIGT', 'data' => $task['data'] + ['completedReason' => 'Rückmeldung der Abmeldestelle eingegangen']]), (string)($task['ownerUid'] ?? ''));
				$closed++;
			} catch (\Throwable) { /* Aufgabe bleibt offen; die Abmeldung ist bestätigt */
			}
		}
		return sprintf('Abmeldung „%s“ als bestätigt markiert%s.', $deregistration['title'], $closed > 0 ? ', Aufgabe „Rückmeldung prüfen“ erledigt' : '');
	}

	/** Positionen ohne eigene Einordnung erhalten die Einordnung der Dokumentart (z. B. Gebührenbescheid → durchlaufender Posten). */
	private function withClassification(array $data, array $kind): array {
		$classification = $kind['classification'] !== '' ? $kind['classification'] : 'CLASSIFICATION_PENDING';
		foreach ((array)($data['items'] ?? []) as $index => $line) {
			if (!is_array($line)) {
				continue;
			}
			if (trim((string)($line['classification'] ?? '')) === '') {
				$data['items'][$index]['classification'] = $classification;
			}
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
		if ($item['recordType'] === 'INCOMING_INVOICE' && ($suggestion['kindSource'] ?? '') !== 'PAPERLESS_TYPE') {
			$kindKey = 'EINGANGSRECHNUNG';
			$suggestion['kindSource'] = 'LEGACY';
		}
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
		// 0.90.1: Herkunft (Paperless, Eingangsordner, Upload), Datei, Textquelle, Texterkennung, E-Rechnung
		$item['channel'] = $item['provider'] === InboxFolderService::PROVIDER ? (string)($item['metadata']['channel'] ?? 'FOLDER') : 'PAPERLESS';
		$item['fileName'] = (string)($item['metadata']['name'] ?? '');
		$item['filePath'] = (string)($item['metadata']['path'] ?? '');
		$item['fileId'] = $item['provider'] === InboxFolderService::PROVIDER ? (int)$item['externalDocumentId'] : null;
		$item['textSource'] = (string)($item['metadata']['textSource'] ?? '');
		$item['ocrStatus'] = (string)($item['metadata']['ocr']['status'] ?? '');
		$item['ocrMessage'] = (string)($item['metadata']['ocr']['message'] ?? '');
		$item['eInvoice'] = null;
		$item['invoiceDraft'] = null;
		if (is_array($item['metadata']['eInvoice'] ?? null)) {
			$eInvoice = $item['metadata']['eInvoice'];
			$item['eInvoice'] = EInvoiceReader::summary($eInvoice);
			$kind = $item['suggestedKind'] !== '' ? ($kinds[$item['suggestedKind']] ?? null) : null;
			$item['invoiceDraft'] = EInvoiceReader::toIncomingInvoice($eInvoice, (string)($kind['classification'] ?? '') !== '' ? (string)$kind['classification'] : 'CLASSIFICATION_PENDING');
		}
		unset($item['metadata']['eInvoice']);
		if ($item['suggestedCaseId'] !== null) {
			try {
				$case = $this->cases->getCase($item['suggestedCaseId']);
				$item['suggestedCaseName'] = trim($case['firstName'] . ' ' . $case['lastName']);
			} catch (\Throwable) {
				$item['suggestedCaseId'] = null;
				$item['suggestedCaseNumber'] = '';
				$item['suggestedCaseReason'] = '';
				$item['suggestedCaseReasonLabel'] = '';
			}
		}
		return $item;
	}
}
