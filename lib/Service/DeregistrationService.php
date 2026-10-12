<?php
declare(strict_types=1);

namespace OCA\Bestatter\Service;

class DeregistrationService {
	/**
	 * Befund (Review 2026-09-22, Kundenmeldung zum Prozess "Abmeldungen"): Die Anlagenauswahl
	 * bot bislang unterschiedslos ALLE Dateien der Fallakte an, einschließlich Rechnungen und
	 * Auftragsunterlagen. Beides hat bei einer Mitteilung an Dritte (Standesamt, Kranken-/
	 * Rentenkasse, Versicherung, Beitragsservice usw.) nichts verloren - weder sachlich noch aus
	 * Datenschutzsicht (Rechnungen enthalten u. a. Bankverbindungen). Diese Positivliste
	 * beschränkt die Vorschläge bewusst auf Dokumentenarten, die für eine externe Abmeldung
	 * plausibel sind: Urkunden (insbesondere die Sterbeurkunde), ausgefüllte Formulare, Nachweise,
	 * bereits geführte Korrespondenz sowie zuvor erzeugte Behörden-/Abmeldungsschreiben. Explizit
	 * ausgeschlossen bleiben Rechnungen/Abrechnungsbelege, Auftragsunterlagen, Fotos, interne
	 * Stammdatenausdrucke und Trauerfeier-Unterlagen. "Sonstiges" bleibt zugelassen, da es die
	 * bewusste Einordnung durch die hochladende Person ist und keiner der ausgeschlossenen
	 * Kategorien entspricht.
	 */
	private const ATTACHMENT_RELEVANT_DOCUMENT_TYPES = ['Urkunde', 'Formular', 'Nachweis', 'Korrespondenz', 'Behörde', 'Abmeldung', 'Sonstiges'];

	private const STATUS_FLOW = [
		'ENTWURF' => ['VORBEREITET'],
		'VORBEREITET' => ['ENTWURF', 'VERSENDET'],
		'VERSENDET' => ['BESTAETIGT', 'ERLEDIGT'],
		'BESTAETIGT' => ['ERLEDIGT'],
		'ERLEDIGT' => [],
	];

	public function __construct(
		private CaseService $cases,
		private RecordService $records,
		private CaseFileService $caseFiles,
		private ConfigurationService $configuration,
		private ContactService $contacts,
		private GroupwareService $groupware,
	) {}

	public function list(int $caseId): array { return $this->records->list('deregistration', $caseId); }

	public function preview(int $caseId, array $input): array {
		$case = $this->cases->getCase($caseId);
		$template = $this->template((string)($input['templateKey'] ?? ''));
		$contact = $this->contacts->find((int)($input['contactId'] ?? 0));
		$channels = $this->availableChannels($template, $contact);
		$channel = strtoupper(trim((string)($input['deliveryChannel'] ?? '')));
		$payload = $this->payload($case, $template, $contact, $input, $channels);
		$missing = $this->missing($case, $template, $contact, $channel, $payload);
		$documents = $this->attachmentDocuments($case); $attachments = $this->resolveAttachments($documents, $input['attachments'] ?? []);
		$hasDeathCertificate = count(array_filter($attachments, fn(array $item): bool => $this->isDeathCertificate($item))) > 0;
		$availableDeathCertificate = count(array_filter($documents, fn(array $item): bool => $this->isDeathCertificate($item))) > 0;
		$branch = $this->branch((string)($case['branch'] ?? $case['masterData']['branch'] ?? ''));
		$documentTemplate = null;
		foreach ($this->configuration->documents() as $candidate) if (($candidate['key'] ?? '') === ($template['documentTemplateKey'] ?? '')) { $documentTemplate = $candidate; break; }
		return ['template' => $template, 'recipient' => $contact, 'availableChannels' => $channels, 'subject' => $payload['subject'], 'body' => $payload['body'], 'missingFields' => $missing, 'ready' => $missing === [], 'documentTemplateKey' => $template['documentTemplateKey'], 'attachments' => $attachments, 'availableAttachments' => $documents, 'attachmentWarning' => !$hasDeathCertificate ? ($availableDeathCertificate ? 'Die vorhandene Sterbeurkunde ist noch nicht als Anlage ausgewählt.' : 'Im Fall ist noch keine Sterbeurkunde vorhanden.') : '', 'previewMode' => in_array($channel, ['POST', 'BRIEF'], true) ? 'LETTER' : 'MESSAGE', 'letter' => ['templateName' => (string)($documentTemplate['name'] ?? $template['documentTemplateKey'] ?? 'Briefvorlage'), 'sender' => trim((string)($branch['name'] ?? '') . "\n" . (string)($branch['street'] ?? '') . "\n" . trim((string)($branch['postalCode'] ?? '') . ' ' . (string)($branch['city'] ?? ''))), 'recipient' => trim((string)($contact['title'] ?? '') . "\n" . (string)($contact['data']['street'] ?? $contact['data']['address'] ?? '') . "\n" . trim((string)($contact['data']['postalCode'] ?? '') . ' ' . (string)($contact['data']['city'] ?? ''))), 'date' => date('d.m.Y')]];
	}

	public function save(int $caseId, array $input, int $id = 0): array {
		$preview = $this->preview($caseId, $input);
		$status = strtoupper((string)($input['status'] ?? 'ENTWURF'));
		if (!in_array($status, $preview['template']['allowedStatuses'], true)) throw new \InvalidArgumentException('Status ist für diese Abmeldung nicht zulässig.');
		if ($status !== 'ENTWURF' && $preview['missingFields'] !== []) throw new \InvalidArgumentException('Bitte ergänzen: ' . implode(', ', $preview['missingFields']));
		$case = $this->cases->getCase($caseId);
		$payload = $this->payload($case, $preview['template'], $preview['recipient'], $input, $preview['availableChannels']);
		$payload['attachments'] = $preview['attachments']; $payload['attachmentWarning'] = $preview['attachmentWarning'];
		$payload['missingFields'] = $preview['missingFields']; $payload['updatedAt'] = date('c');
		if ($id > 0) {
			$existing = $this->records->get($id);
			if ($existing['type'] !== 'deregistration' || (int)$existing['caseId'] !== $caseId) throw new \InvalidArgumentException('Abmeldung wurde nicht gefunden.');
			if (!in_array(strtoupper((string)$existing['status']), ['ENTWURF', 'VORBEREITET'], true)) throw new \InvalidArgumentException('Nach dem Versand kann die Abmeldung nicht mehr bearbeitet werden.');
			return $this->records->update($id, $preview['template']['name'], (string)($existing['date'] ?? ''), $status, json_encode($payload, JSON_THROW_ON_ERROR), $caseId);
		}
		return $this->records->create('deregistration', $preview['template']['name'], date('c'), $status, json_encode($payload, JSON_THROW_ON_ERROR), $caseId);
	}

	public function transition(int $id, string $targetStatus, array $input = []): array {
		$record = $this->records->get($id);
		if ($record['type'] !== 'deregistration') throw new \InvalidArgumentException('Eintrag ist keine Abmeldung.');
		$current = strtoupper((string)$record['status']); $target = strtoupper(trim($targetStatus));
		if (!in_array($target, self::STATUS_FLOW[$current] ?? [], true)) throw new \InvalidArgumentException('Dieser Statuswechsel ist nicht zulässig.');
		$data = array_merge($record['data'], $input);
		$preview = $this->preview((int)$record['caseId'], $data);
		if (!in_array($target, $preview['template']['allowedStatuses'], true)) throw new \InvalidArgumentException('Zielstatus ist für diese Vorlage nicht zulässig.');
		if ($preview['missingFields'] !== []) throw new \InvalidArgumentException('Bitte ergänzen: ' . implode(', ', $preview['missingFields']));
		if ($target === 'VERSENDET') {
			$data['sentAt'] = trim((string)($data['sentAt'] ?? '')) ?: date('c');
			if (trim((string)($data['evidenceNote'] ?? '')) === '' && trim((string)($data['evidenceDocumentPath'] ?? '')) === '') throw new \InvalidArgumentException('Für den Versand ist ein Nachweis oder eine Notiz erforderlich.');
		}
		if ($target === 'BESTAETIGT') $data['confirmedAt'] = trim((string)($data['confirmedAt'] ?? '')) ?: date('c');
		if ($target === 'ERLEDIGT') $data['completedAt'] = date('c');
		$updated = $this->records->update($id, $record['title'], (string)$record['date'], $target, json_encode($data, JSON_THROW_ON_ERROR), (int)$record['caseId']);
		$this->records->create('activity', 'Abmeldung: ' . $record['title'], date('c'), $target, json_encode(['deregistrationId' => $id, 'from' => $current, 'to' => $target], JSON_THROW_ON_ERROR), (int)$record['caseId']);
		if ($target === 'VERSENDET') $this->createFollowUp($updated);
		return $updated;
	}

	/**
	 * 0.80.0 (OP-058): Rückmeldung einer Abmeldestelle ist eingegangen (Belegeingang, Dokumentart „Rückmeldung
	 * Abmeldestelle“). Nur VERSENDET → BESTAETIGT; anders als transition() ohne erneute Prüfung von Empfänger und
	 * Versandweg – die waren beim Versand vollständig, die Bestätigung ist nur die Tatsache des Eingangs.
	 */
	public function confirmReceipt(int $id, array $evidence): array {
		$record = $this->records->get($id);
		if ($record['type'] !== 'deregistration') throw new \InvalidArgumentException('Eintrag ist keine Abmeldung.');
		if (strtoupper((string)$record['status']) !== 'VERSENDET') throw new \InvalidArgumentException('Nur versendete Abmeldungen lassen sich mit einer Rückmeldung bestätigen.');
		$data = array_merge($record['data'], array_intersect_key($evidence, array_flip(['confirmationRecordId', 'confirmationFileId', 'confirmationPath', 'confirmationSource'])));
		$data['confirmedAt'] = date('c');
		$updated = $this->records->update($id, $record['title'], (string)$record['date'], 'BESTAETIGT', json_encode($data, JSON_THROW_ON_ERROR), (int)$record['caseId']);
		$this->records->create('activity', 'Abmeldung: ' . $record['title'], date('c'), 'BESTAETIGT', json_encode(['deregistrationId' => $id, 'from' => 'VERSENDET', 'to' => 'BESTAETIGT', 'source' => (string)($evidence['confirmationSource'] ?? 'DOCUMENT')], JSON_THROW_ON_ERROR), (int)$record['caseId']);
		return $updated;
	}

	private function createFollowUp(array $record): void {
		$days = (int)($record['data']['followUpDays'] ?? 0);
		if ($days <= 0) return;
		foreach ($this->records->list('task', (int)$record['caseId']) as $task) if ((int)($task['data']['deregistrationId'] ?? 0) === (int)$record['id']) return;
		$due = (new \DateTimeImmutable('today'))->modify('+' . $days . ' days')->format('Y-m-d\T09:00');
		$this->groupware->createTask('Rückmeldung prüfen: ' . $record['title'], $due, 'OFFEN', json_encode(['deregistrationId' => $record['id'], 'priority' => 'NORMAL'], JSON_THROW_ON_ERROR), (int)$record['caseId']);
	}

	private function template(string $key): array {
		$key = strtoupper(trim($key));
		foreach ($this->configuration->deregistrations() as $template) if ($template['key'] === $key && $template['active']) return $template;
		throw new \InvalidArgumentException('Abmeldungsvorlage wurde nicht gefunden.');
	}

	private function availableChannels(array $template, ?array $contact): array {
		if ($contact === null) return [];
		$data = $contact['data'] ?? []; $available = [];
		if (trim((string)($data['email'] ?? '')) !== '') $available[] = 'EMAIL';
		if (trim((string)($data['address'] ?? $data['street'] ?? '')) !== '') $available[] = 'POST';
		if (trim((string)($data['url'] ?? '')) !== '') $available[] = 'PORTAL';
		if (trim((string)($data['phone'] ?? '')) !== '') $available[] = 'TELEFON';
		return array_values(array_intersect($template['deliveryChannels'], $available));
	}

	private function attachmentDocuments(array $case): array {
		$relevant = array_filter(
			$this->caseFiles->attachmentFiles($case),
			static fn(array $item): bool => in_array((string)($item['documentType'] ?? ''), self::ATTACHMENT_RELEVANT_DOCUMENT_TYPES, true),
		);
		return array_map(static fn(array $item): array => [
			'id' => (int)$item['fileId'],
			'fileId' => (int)$item['fileId'],
			'recordId' => (int)($item['recordId'] ?? 0),
			'title' => (string)$item['title'],
			'status' => (string)$item['status'],
			'documentType' => (string)$item['documentType'],
			'path' => (string)$item['path'],
			'mimeType' => (string)$item['mimeType'],
			'size' => (int)$item['size'],
			'source' => (string)$item['source'],
		], array_values($relevant));
	}
	private function resolveAttachments(array $documents, mixed $selected): array {
		$selectedIds = [];
		foreach (is_array($selected) ? $selected : [] as $value) {
			$selectedIds[] = is_array($value) ? (int)($value['fileId'] ?? $value['id'] ?? $value['recordId'] ?? 0) : (int)$value;
		}
		$ids = array_fill_keys(array_filter($selectedIds), true);
		return array_values(array_filter($documents, static fn(array $item): bool => isset($ids[(int)$item['fileId']]) || ((int)$item['recordId'] > 0 && isset($ids[(int)$item['recordId']]))));
	}
	private function isDeathCertificate(array $item): bool { $haystack=mb_strtolower((string)($item['title']??'').' '.(string)($item['documentType']??'').' '.(string)($item['path']??'')); return str_contains($haystack,'sterbeurkunde') || str_contains($haystack,'sterbeurk'); }
	private function branch(string $key): array { foreach($this->configuration->branches() as $branch) if((string)$branch['key']===$key)return $branch; return []; }

	/**
	 * Befund (Abmeldungen-Test 2026-09-22, Kundenentscheidung 2026-09-22 "bitte serverseitig
	 * erneut zusammenführen"): Betreff/Text wurden bislang nur dann aus der Vorlage gemergt, wenn
	 * das Feld vom Client komplett leer ankam ($subject/$body = trim(...) ?: strtr(...)). Das
	 * Formular belegt Betreff/Text im Frontend aber nur EINMALIG beim Auswählen des Vorgangs vor
	 * (siehe documents.js, refresh()) - zu diesem Zeitpunkt sind Formularfelder wie die
	 * Postrentennummer oder eine erst jetzt eingeführte Referenznummer (siehe unten) noch leer, der
	 * an den Server gesendete Text ist danach aber nie mehr leer und wurde deshalb nie erneut
	 * gemergt, selbst wenn er noch unaufgelöste "{{...}}"-Platzhalter enthielt. Fix: Der vom Client
	 * gesendete Text (falls vorhanden) wird jetzt IMMER zusätzlich durch strtr() geschickt, bevor er
	 * verwendet wird - das löst verbliebene bekannte Platzhalter zuverlässig auf, ohne bereits vom
	 * Nutzer frei formulierten Text zu verändern (strtr() lässt Text ohne passende Platzhalter
	 * unverändert). Wirkt bei jedem preview()/save()-Aufruf, insbesondere beim Klick auf "Vorschau"
	 * nachdem ein Feld nachträglich ausgefüllt wurde.
	 */
	private function payload(array $case, array $template, ?array $contact, array $input, array $channels): array {
		$m = $case['masterData'] ?? [];
		$values = ['{{case.number}}' => (string)$case['caseNumber'], '{{case.first_name}}' => (string)$case['firstName'], '{{case.last_name}}' => (string)$case['lastName'], '{{case.date_of_death}}' => (string)($m['date_of_death'] ?? $case['dateOfDeath'] ?? ''), '{{case.pension_number}}' => (string)($input['pensionNumber'] ?? $m['pension_insurance_number'] ?? ''), '{{advance.application}}' => (string)($input['advanceApplication'] ?? 'NEIN')];
		// Befund (Abmeldungen-Test 2026-09-22, Kundenwunsch "bitte Eingabefeld einbauen"): Vorlagen
		// können optional EIN generisches, je Abmeldung (nicht je Fall!) erfasstes Referenzfeld
		// deklarieren (resources/deregistration-templates.json, "referenceField"). Das erlaubt pro
		// Abmeldevorgang eine eigene Kenn-/Vertragsnummer (Beitragsnummer, Vertragsreferenz,
		// Versicherungsnummer) - bewusst NICHT als Fall-Stammdatenfeld wie bei der Postrentennummer,
		// da z. B. mehrere Versicherungen desselben Falls jeweils EIGENE, unterschiedliche Nummern
		// haben: da für jede externe Stelle ein eigener Abmeldedatensatz angelegt wird, hat jede
		// Abmeldung automatisch ihre eigene, unabhängige Referenznummer.
		$referenceFieldKey = (string)($template['referenceField']['field'] ?? '');
		$referenceNumber = trim((string)($input['referenceNumber'] ?? ''));
		if ($referenceFieldKey !== '') $values['{{' . $referenceFieldKey . '}}'] = $referenceNumber;
		$subjectInput = trim((string)($input['subject'] ?? ''));
		$subject = strtr($subjectInput !== '' ? $subjectInput : (string)$template['subjectTemplate'], $values);
		$bodyInput = trim((string)($input['body'] ?? ''));
		$body = strtr($bodyInput !== '' ? $bodyInput : (string)$template['bodyTemplate'], $values);
		return array_merge($input, ['templateKey' => $template['key'], 'formType' => $template['formType'], 'contactId' => $contact['id'] ?? 0, 'recipientName' => $contact['title'] ?? '', 'recipient' => $contact['data'] ?? [], 'contactCategory' => $template['contactCategory'], 'availableChannels' => $channels, 'subject' => $subject, 'body' => $body, 'referenceNumber' => $referenceNumber, 'followUpDays' => $template['followUpDays'], 'documentTemplateKey' => $template['documentTemplateKey']]);
	}

	private function missing(array $case, array $template, ?array $contact, string $channel, array $payload): array {
		$m = $case['masterData'] ?? []; $missing = [];
		// Befund (Abmeldungen-Test 2026-09-22): das generische Referenzfeld (siehe payload()) wird
		// unter dem festen Formularfeldnamen "referenceNumber" erfasst, nicht unter dem
		// vorlagenspezifischen Platzhalter-Feldnamen selbst (z. B. "case.broadcast_contribution_number") -
		// ohne diese Zuordnung würde der default-Zweig unten stets ins Leere greifen, da im Payload
		// nie ein Schlüssel mit dem Punkt-Namen selbst gesetzt wird.
		$referenceFieldKey = (string)($template['referenceField']['field'] ?? '');
		foreach ($template['requiredFields'] as $field) {
			$present = match ($field) {
				'case.first_name' => trim((string)$case['firstName']) !== '', 'case.last_name' => trim((string)$case['lastName']) !== '',
				'case.date_of_death' => trim((string)($m['date_of_death'] ?? $case['dateOfDeath'] ?? '')) !== '' || (trim((string)($m['death_time_from'] ?? '')) !== '' && trim((string)($m['death_time_to'] ?? '')) !== ''),
				'case.pension_number' => trim((string)($payload['pensionNumber'] ?? $m['pension_insurance_number'] ?? '')) !== '',
				'contact' => $contact !== null, 'delivery_channel' => $channel !== '' && in_array($channel, $payload['availableChannels'], true),
				default => ($referenceFieldKey !== '' && $field === $referenceFieldKey)
					? trim((string)($payload['referenceNumber'] ?? '')) !== ''
					: trim((string)($payload[$field] ?? '')) !== '',
			};
			if (!$present) $missing[] = $field;
		}
		if ($contact !== null && $template['contactCategory'] !== '' && !str_contains(mb_strtolower((string)($contact['data']['categories'] ?? '')), mb_strtolower((string)$template['contactCategory']))) $missing[] = 'Kontaktgruppe ' . $template['contactCategory'];
		return array_values(array_unique($missing));
	}
}
