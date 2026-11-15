<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\Exception\BestatterConflictException;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IUserSession;

/**
 * 0.89.0: Unterschrift des Bestattungsauftrags vor Ort am Tablet. Der/die Auftraggeber/in sieht den Auftrag, kreuzt die
 * eigenen Erklärungen zum Leistungsbeginn selbst an und unterschreibt mit Finger oder Stift. Daraus entsteht eine nicht
 * bearbeitbare PDF mit Unterschriftsbild, Ort, Datum und Uhrzeit; der Nachweis (Prüfwerte von Unterschrift und PDF, Erklärungen,
 * annehmende Person) steht im Dokumentdatensatz und im Protokoll. Auf Wunsch geht eine Ausfertigung per E-Mail an den Kunden.
 * Rechtlich ist das eine einfache elektronische Unterschrift (keine qualifizierte elektronische Signatur).
 */
class OrderSignatureService {
	public const MAX_SIGNATURE_BYTES = 600 * 1024;

	public function __construct(
		private CaseService $cases,
		private CommercialService $commercial,
		private CommercialStateService $commercialState,
		private DocumentService $documents,
		private RecordService $records,
		private BusinessMailService $mail,
		private AuditService $audit,
		private ProcessService $process,
		private ConfigurationService $configuration,
		private ArticleService $articles,
		private MailTemplateService $mailTemplates,
		private IUserSession $users,
		private IDateTimeZone $timeZone,
		private ?IConfig $config = null,
	) {
	}

	/** Voraussetzungen und Vorschlagswerte für die Unterschriftsansicht. */
	public function status(int $caseId): array {
		$case = $this->cases->getCase($caseId);
		$master = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$value = static fn (string $key): string => trim((string)($master[$key] ?? ''));
		$state = $this->commercialState->state($caseId);
		$problems = [];
		if (strtoupper($value('order_mode') ?: 'A') !== 'A') {
			$problems[] = 'Bitte zuerst „Auftrag“ als Art des kaufmännischen Dokuments wählen.';
		}
		if (($state['documentType'] ?? null) === 'QUOTE') {
			$problems[] = 'Für diesen Fall ist ein Kostenvoranschlag festgeschrieben. Bitte ihn zuerst einmalig in den Auftrag übernehmen.';
		}
		if ($value('order_client_name') === '' && $value('order_client_company') === '') {
			$problems[] = 'Bitte den Namen des Auftraggebers eintragen.';
		}
		// 0.89.7: Auftrag ohne Sterbefall bzw. Vorsorgevertrag mit eigener Vorlage und Bezeichnung.
		$documentTitle = DocumentService::orderDocumentTitle((string)($case['caseKind'] ?? CaseKinds::DEATH), false);
		if (($case['caseKind'] ?? CaseKinds::DEATH) === CaseKinds::DEATH) {
			$documentTitle = 'Bestattungsauftrag und Vollmacht';
		}
		if ($value('order_contract_conclusion') === '') {
			$problems[] = 'Bitte unter „Vertragsschluss und Vollmacht“ angeben, wo der Vertrag geschlossen wird.';
		}
		$signed = $this->signedRecord($caseId);
		if ($signed !== null) {
			$problems[] = 'Der Auftrag ist bereits unterschrieben (' . (string)$signed['title'] . ').';
		}
		$pdf = $this->documents->pdfConversionAvailable();
		if (!$pdf) {
			$problems[] = 'In dieser Nextcloud ist keine automatische PDF-Umwandlung eingerichtet. Ohne sie kann keine unterschriebene PDF entstehen.';
		}
		$template = true;
		if ($problems === []) {
			try {
				$template = $this->documents->orderTemplateSupportsSignature($case);
			} catch (\Throwable) {
				$template = false;
			}
			if (!$template) {
				$problems[] = 'Die Vorlage „' . $documentTitle . '“ hat noch kein Feld für die Unterschrift am Tablet. Bitte unter Customizing → Dokumentvorlagen die neue Standardfassung übernehmen.';
			}
		}
		$mail = $this->mail->availability();
		// Übersicht für den Kunden (das vollständige Dokument zeigt die PDF-Vorschau)
		$costing = $this->articles->caseServices($caseId);
		$services = [];
		foreach ($costing['items'] ?? [] as $item) {
			if (($item['serviceStatus'] ?? '') === 'STORNIERT' || !empty($item['isPackageHeader'])) {
				continue;
			}
			$services[] = ['title' => (string)($item['title'] ?? ''), 'quantity' => (float)($item['quantity'] ?? 1), 'unit' => (string)($item['unit'] ?? ''), 'grossCents' => (int)($item['grossCents'] ?? 0), 'package' => (string)($item['sourcePackageName'] ?? ''),
				'marker' => PriceTerms::marker($item)];
		}
		// 0.89.5: Schätzbeträge und Gebühren nach Bescheid wie im Dokument kennzeichnen.
		$priceSummary = PriceTerms::summary($costing['items'] ?? []);
		// 0.89.3: nur Felder, die in der wirksamen Vorlage stehen; Felder des Kunden kreuzt er hier selbst an (nicht vorbelegt).
		$selections = [];
		$customerFields = [];
		try {
			foreach ($this->documents->orderSelections($case, $costing) as $item) {
				if (empty($item['inTemplate'])) {
					continue;
				}
				if ($item['actor'] === 'CUSTOMER') {
					$customerFields[] = ['field' => (string)$item['field'], 'label' => (string)$item['label'], 'section' => (string)$item['section'], 'sectionLabel' => (string)$item['sectionLabel']];
					continue;
				}
				$selections[] = ['group' => (string)$item['group'], 'section' => (string)$item['section'], 'sectionLabel' => (string)$item['sectionLabel'], 'label' => (string)$item['label'], 'checked' => (bool)$item['checked']];
			}
		} catch (\Throwable) {
		}
		return [
			'services' => $services,
			'totalGrossCents' => (int)($costing['totals']['grossCents'] ?? 0),
			'totalOpen' => $priceSummary['open'], 'totalNotice' => $priceSummary['noticeTitles'] !== [],
			'priceTerms' => $priceSummary['open'] ? PriceTerms::priceTerms($priceSummary, PriceTerms::threshold($this->config)) : '',
			'selections' => $selections,
			'customerFields' => $customerFields,
			'ready' => $problems === [],
			'problems' => $problems,
			'orderFrozen' => ($state['documentType'] ?? null) === 'ORDER',
			'documentTitle' => $documentTitle,
			'orderNumber' => (string)($state['documentNumber'] ?? '') ?: ($value('order_number') ?: CaseKinds::orderDocumentNumber((string)$case['caseNumber'])),
			'withdrawalApplies' => DocumentService::withdrawalApplies($master),
			'withdrawalTexts' => ['request' => DocumentService::WITHDRAWAL_REQUEST_TEXT, 'ack' => DocumentService::WITHDRAWAL_ACK_TEXT],
			'contractConclusion' => strtoupper($value('order_contract_conclusion')),
			'signerName' => trim($value('order_client_first_name') . ' ' . $value('order_client_name')),
			'place' => $this->defaultPlace($case, $master),
			'email' => $value('order_client_email'),
			'mailAvailable' => (bool)$mail['enabled'],
			'pdfAvailable' => $pdf,
			'templateReady' => $template,
			'staffName' => $this->staffName(),
			'signed' => $signed !== null ? ['recordId' => (int)$signed['id'], 'title' => (string)$signed['title'], 'signedAt' => (string)($signed['data']['digitalSignature']['signedAt'] ?? ''), 'signerName' => (string)($signed['data']['digitalSignature']['signerName'] ?? '')] : null,
		];
	}

	/**
	 * Unterschrift verbindlich übernehmen. Ein noch nicht festgeschriebener Auftrag wird dabei festgeschrieben (die Hinweise der
	 * Festschreibungsprüfung hat die Mitarbeiterin vorher bestätigt). Schlägt die PDF fehl, bleiben die Stammdaten unverändert.
	 */
	public function sign(int $caseId, array $input, string $userAgent = ''): array {
		$status = $this->status($caseId);
		if (!$status['ready']) {
			throw new \InvalidArgumentException((string)$status['problems'][0]);
		}
		$case = $this->cases->getCase($caseId);
		$expected = trim((string)($input['expectedUpdatedAt'] ?? ''));
		if ($expected !== '' && $expected !== (string)$case['updatedAt']) {
			throw new BestatterConflictException('Der Fall wurde inzwischen geändert. Bitte die Unterschriftsansicht schließen und neu öffnen.');
		}
		if (empty($input['readConfirmed'])) {
			throw new \InvalidArgumentException('Bitte bestätigen, dass der Auftrag gelesen wurde.');
		}
		$signer = $this->line((string)($input['signerName'] ?? ''), 200);
		if ($signer === '') {
			throw new \InvalidArgumentException('Bitte den Namen der unterschreibenden Person angeben.');
		}
		$place = $this->line((string)($input['place'] ?? ''), 120);
		if ($place === '') {
			throw new \InvalidArgumentException('Bitte den Ort der Unterschrift angeben.');
		}
		$png = $this->signatureImage((string)($input['signature'] ?? ''));
		$withdrawal = (bool)$status['withdrawalApplies'];
		$request = $withdrawal && !empty($input['withdrawalRequest']);
		$ack = $withdrawal && !empty($input['withdrawalAck']);
		$sendCopy = !empty($input['sendCopy']);
		$email = strtolower(trim((string)($input['email'] ?? '')));
		if ($sendCopy && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n,;]/', $email))) {
			throw new \InvalidArgumentException('Bitte eine gültige E-Mail-Adresse für die Ausfertigung angeben.');
		}
		$requestKey = strtolower(trim((string)($input['requestKey'] ?? '')));
		if ($sendCopy && !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/', $requestKey)) {
			throw new \InvalidArgumentException('Ungültige Versandkennung.');
		}

		$master = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$orderFrozenNow = false;
		if (!$status['orderFrozen']) {
			$this->commercial->freezeOrder($caseId, [
				'number' => trim((string)($master['order_number'] ?? '')) ?: CaseKinds::orderDocumentNumber((string)$case['caseNumber']),
				'date' => trim((string)($master['order_date'] ?? '')) ?: date('Y-m-d'),
				'commissioningType' => (string)($master['commissioning_type'] ?? ''),
				// Der Hinweis „noch keine Unterschrift“ erledigt sich mit genau dieser Unterschrift.
				'confirmedWarningCodes' => array_values(array_unique([...array_map('strval', (array)($input['confirmedWarningCodes'] ?? [])), 'NO_SIGNATURE'])),
			]);
			$orderFrozenNow = true;
		}
		$orderState = $this->commercialState->state($caseId);

		$now = new \DateTimeImmutable('now', $this->timeZone->getTimeZone());
		$signatureHash = hash('sha256', $png);
		$staffName = $this->staffName();
		$customerChoices = [];
		$chosen = is_array($input['customerChoices'] ?? null) ? $input['customerChoices'] : [];
		foreach ($status['customerFields'] as $customerField) {
			$customerChoices[$customerField['field']] = ['label' => $customerField['label'], 'checked' => !empty($chosen[$customerField['field']])];
		}
		$signatureFields = array_map(static fn (array $choice): string => $choice['checked'] ? 'JA' : 'NEIN', $customerChoices) + [
			'order_signature_name' => $signer, 'order_signature_date' => $now->format('Y-m-d'), 'order_signature_time' => $now->format('H:i'),
			'order_signature_place' => $place, 'order_signature_method' => 'TABLET', 'order_signature_sha256' => $signatureHash,
			'order_signature_accepted_by' => $staffName,
			// Auftragsnummer und -datum für das Dokument (sonst nur über das Auftragsformular gesetzt)
			'order_number' => trim((string)($master['order_number'] ?? '')) ?: (string)($orderState['documentNumber'] ?? '') ?: CaseKinds::orderDocumentNumber((string)$case['caseNumber']),
			'order_date' => trim((string)($master['order_date'] ?? '')) ?: $now->format('Y-m-d'),
			'order_withdrawal_request' => $request ? 'JA' : '', 'order_withdrawal_ack' => $ack ? 'JA' : '',
		];
		$before = $this->cases->getCase($caseId);
		$previousMaster = is_array($before['masterData'] ?? null) ? $before['masterData'] : [];
		$this->cases->updateMasterData($caseId, array_merge($previousMaster, $signatureFields), null, true);
		try {
			$file = $this->documents->generateSignedOrder($this->cases->getCase($caseId), $png);
		} catch (\Throwable $error) {
			// Ohne unterschriebene PDF gilt die Unterschrift nicht als geleistet.
			$restore = $previousMaster;
			foreach ($signatureFields as $key => $unused) {
				if (!array_key_exists($key, $restore)) {
					$restore[$key] = '';
				}
			}
			try {
				$this->cases->updateMasterData($caseId, $restore, null, true);
			} catch (\Throwable) {
			}
			if ($error instanceof \InvalidArgumentException) {
				throw $error;
			}
			throw new \RuntimeException('Die unterschriebene PDF konnte nicht erzeugt werden' . ($orderFrozenNow ? ' (der Auftrag ist bereits festgeschrieben)' : '') . ': ' . $error->getMessage(), 0, $error);
		}
		$pdfHash = (string)($file['pdf']['sha256'] ?? '');
		$evidence = [
			'method' => 'TABLET', 'kind' => 'EINFACHE_ELEKTRONISCHE_UNTERSCHRIFT',
			'signerName' => $signer, 'place' => $place, 'signedAt' => $now->format('c'),
			'staffUid' => (string)($this->users->getUser()?->getUID() ?? ''), 'staffName' => $staffName,
			'signatureSha256' => $signatureHash, 'pdfSha256' => $pdfHash,
			'declarations' => ['readConfirmed' => true, 'withdrawalApplies' => $withdrawal, 'withdrawalRequest' => $request, 'withdrawalAck' => $ack],
			'customerChoices' => array_values($customerChoices),
			'contractConclusion' => (string)$status['contractConclusion'],
			'userAgent' => mb_substr($this->line($userAgent, 300), 0, 300),
		];
		$record = $this->records->saveDigitallySignedDocument($caseId, (string)$file['title'], $file + [
			'commercialDocumentId' => (int)($orderState['documentId'] ?? 0), 'commercialDocumentType' => 'ORDER', 'digitalSignature' => $evidence,
		]);
		$this->audit->log($caseId, 'ORDER', (int)($orderState['documentId'] ?? 0), 'SIGNED_ON_TABLET', null, $evidence + ['recordId' => (int)$record['id']]);

		$result = ['record' => $record, 'pdf' => $file['pdf'] ?? null, 'orderFrozen' => $orderFrozenNow, 'mail' => null, 'mailWarning' => ''];
		if ($sendCopy) {
			try {
				$extra = $withdrawal ? $this->withdrawalNoticeRecordId($caseId) : 0;
				// 0.89.3: Text aus Customizing → E-Mail-Vorlagen („Bestattungsauftrag“)
				$signedCase = $this->cases->getCase($caseId);
				$mail = $this->mailTemplates->render('ORDER', $signedCase, ['documentNumber' => (string)($orderState['documentNumber'] ?? $status['orderNumber']), 'withdrawalEnclosure' => $withdrawal && $extra > 0]);
				$result['mail'] = $this->mail->send($signedCase, (int)$record['id'], [
					'requestKey' => $requestKey, 'recipient' => $email,
					'subject' => $mail['subject'],
					'body' => $mail['body'],
					'fileSha256' => $this->recordPdfHash($record),
				], $extra > 0 ? [$extra] : []);
			} catch (\Throwable $error) {
				$result['mailWarning'] = 'Der Auftrag ist unterschrieben. Die E-Mail mit der Ausfertigung konnte nicht gesendet werden: ' . $error->getMessage();
			}
		}
		$this->process->reconcileQuietly($caseId);
		return $result;
	}

	/** Bereits am Tablet (oder auf Papier, geprüft) unterschriebener Bestattungsauftrag. */
	private function signedRecord(int $caseId): ?array {
		foreach ($this->records->list('document', $caseId) as $record) {
			if (strtoupper((string)$record['status']) !== 'UNTERSCHRIEBEN') {
				continue;
			}
			$data = is_array($record['data'] ?? null) ? $record['data'] : [];
			if (is_array($data['digitalSignature'] ?? null) || is_array($data['paperSignature'] ?? null)) {
				return $record;
			}
		}
		return null;
	}

	/** Ort der Unterschrift: beim Hausbesuch der Wohnort des Auftraggebers, sonst der Ort der Niederlassung. */
	private function defaultPlace(array $case, array $master): string {
		if (strtoupper(trim((string)($master['order_contract_conclusion'] ?? ''))) === 'AUSSERHALB') {
			$city = trim((string)preg_replace('/^\d{4,5}\s+/', '', trim((string)($master['order_client_postal_city'] ?? ''))));
			if ($city !== '') {
				return $city;
			}
		}
		$branch = $this->configuration->branchByKey((string)($case['branch'] ?? $master['branch'] ?? '')) ?? [];
		return trim((string)($branch['city'] ?? ''));
	}

	private function signatureImage(string $dataUrl): string {
		if (!str_starts_with($dataUrl, 'data:image/png;base64,')) {
			throw new \InvalidArgumentException('Bitte im Feld unterschreiben.');
		}
		$png = base64_decode(substr($dataUrl, 22), true);
		if ($png === false || !str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
			throw new \InvalidArgumentException('Die Unterschrift wurde nicht als gültiges Bild übermittelt.');
		}
		if (strlen($png) > self::MAX_SIGNATURE_BYTES) {
			throw new \InvalidArgumentException('Das Unterschriftsbild ist zu groß.');
		}
		$size = @getimagesizefromstring($png);
		if (!is_array($size) || (int)$size[0] < 40 || (int)$size[1] < 15 || (int)$size[0] > 4000 || (int)$size[1] > 2000) {
			throw new \InvalidArgumentException('Die Unterschrift ist zu klein oder ungültig. Bitte erneut unterschreiben.');
		}
		return $png;
	}

	/** Widerrufsbelehrung als finale PDF (vorhandene verwenden, sonst erzeugen) – Anlage zur E-Mail. */
	public function withdrawalNoticeRecordId(int $caseId): int {
		foreach ($this->records->list('document', $caseId) as $record) {
			if (strtoupper((string)(($record['data'] ?? [])['templateKey'] ?? '')) === 'WIDERRUFSBELEHRUNG' && in_array(strtoupper((string)$record['status']), ['FINAL', 'VERSENDET'], true)) {
				return (int)$record['id'];
			}
		}
		try {
			$file = $this->documents->generateTemplate($this->cases->getCase($caseId), 'WIDERRUFSBELEHRUNG', 'FINAL', true);
			return (int)$this->records->saveDocument($caseId, (string)$file['title'], (string)$file['status'], $file)['id'];
		} catch (\Throwable) {
			return 0;
		}
	}

	private function recordPdfHash(array $record): string {
		$hash = (string)($record['data']['pdf']['sha256'] ?? '');
		return $hash !== '' ? $hash : (string)($record['data']['digitalSignature']['pdfSha256'] ?? '');
	}

	private function staffName(): string {
		$user = $this->users->getUser();
		return $user === null ? '' : trim((string)($user->getDisplayName() ?: $user->getUID()));
	}

	private function line(string $value, int $max): string {
		return mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)), 0, $max);
	}
}
