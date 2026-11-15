<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * 0.89.3: E-Mail-Vorlagen für den Versand von Auftrag, Kostenvoranschlag und Rechnung an die Angehörigen (Betreff, Text mit
 * Anrede, Zusatz zur Widerrufsbelehrung, Zahlungshinweis und Signatur der Niederlassung). Gepflegt unter Customizing →
 * E-Mail-Vorlagen; genutzt von der Versandvorschau des Geschäfts-Postfachs und von der Ausfertigung nach der Unterschrift am Tablet.
 */
class MailTemplateService {
	public const CONFIG_KEY = 'mail_templates';
	public const KEYS = ['ORDER' => 'Bestattungsauftrag', 'QUOTE' => 'Kostenvoranschlag', 'INVOICE' => 'Rechnung, Storno und Gutschrift'];
	public const WITHDRAWAL_ENCLOSURE = ' sowie die Widerrufsbelehrung mit Muster-Widerrufsformular';
	private const GREETING = "\n\nMit freundlichen Grüßen\n{{user.name}}\n\n{{mail.signature}}";
	private const DEFAULTS = [
		'ORDER' => ['Ihr Bestattungsauftrag {{document.number}} – {{deceased_name}}',
			"{{mail.salutation}},\n\nanbei erhalten Sie Ihre Ausfertigung des Bestattungsauftrags {{document.number}} vom {{document.date}} für {{deceased_name}}{{mail.withdrawal_enclosure}}.\n\nBitte bewahren Sie die Unterlagen gut auf. Wenn Sie Fragen haben oder sich etwas ändern soll, erreichen Sie uns jederzeit{{mail.contact}}."],
		'QUOTE' => ['Ihr Kostenvoranschlag {{document.number}} – {{deceased_name}}',
			"{{mail.salutation}},\n\nwie besprochen erhalten Sie anbei unseren Kostenvoranschlag {{document.number}} vom {{document.date}} für die Bestattung von {{deceased_name}}.{{quote.validity_sentence}}\n\nWenn Sie Fragen haben oder etwas ändern möchten, erreichen Sie uns jederzeit{{mail.contact}}. Für den Auftrag melden Sie sich gern bei uns."],
		'INVOICE' => ['{{document.title}} {{document.number}} – {{deceased_name}}',
			"{{mail.salutation}},\n\nanbei erhalten Sie unsere {{document.title}} {{document.number}} vom {{document.date}} über {{invoice.total}} für die Bestattung von {{deceased_name}}.{{invoice.payment_sentence}}\n\nBei Fragen erreichen Sie uns jederzeit{{mail.contact}}."],
	];
	/** Platzhalter für Betreff und Text (Bedeutung). */
	public const PLACEHOLDERS = [
		'{{mail.salutation}}' => 'Anrede: „Sehr geehrte Frau …“, „Sehr geehrter Herr …“, sonst „Guten Tag Vorname Name“',
		'{{mail.signature}}' => 'Signatur der Niederlassung des Falls mit Pflichtangaben und Signaturzusatz',
		'{{mail.contact}}' => '„ unter Telefon … oder per E-Mail an …“ aus der Niederlassung (leer, wenn nichts eingetragen ist)',
		'{{user.name}}' => 'Name der angemeldeten Person (Absender)',
		'{{deceased_name}}' => 'Vor- und Nachname der verstorbenen Person',
		'{{case.case_number}}' => 'Fallnummer',
		'{{document.title}}' => 'Bezeichnung des Dokuments (Bestattungsauftrag, Kostenvoranschlag, Schlussrechnung, Teilrechnung, Stornorechnung, Gutschrift)',
		'{{document.number}}' => 'Nummer des Auftrags, Kostenvoranschlags oder der Rechnung',
		'{{document.date}}' => 'Datum des Dokuments',
		'{{mail.withdrawal_enclosure}}' => 'Auftrag: „ sowie die Widerrufsbelehrung mit Muster-Widerrufsformular“, wenn sie beiliegt',
		'{{quote.validity_sentence}}' => 'Kostenvoranschlag: „ Er gilt bis zum …“, wenn ein Datum eingetragen ist',
		'{{invoice.total}}' => 'Rechnung: Gesamtbetrag brutto',
		'{{invoice.due_date}}' => 'Rechnung: Fälligkeit (Rechnungsdatum plus Zahlungsziel der Niederlassung)',
		'{{invoice.payment_sentence}}' => 'Rechnung: Zahlungshinweis (Überweisung bis …, SEPA-Lastschrift; leer bei Storno und Gutschrift)',
		'{{branch.name}}' => 'Name der Niederlassung',
		'{{branch.phone}}' => 'Telefon der Niederlassung',
		'{{branch.email}}' => 'E-Mail der Niederlassung',
	];

	public function __construct(
		private ?IConfig $config = null,
		private ?ConfigurationService $configuration = null,
		private ?IDBConnection $db = null,
		private ?IUserSession $users = null,
		private ?AuditService $audit = null,
		private ?TeamService $team = null,
	) {
	}

	/** Gespeicherte Vorlagen, ergänzt um die mitgelieferten Texte. */
	public function templates(): array {
		$stored = $this->stored();
		$result = [];
		foreach (self::KEYS as $key => $name) {
			[$subject, $body] = self::defaultTemplate($key);
			$own = is_array($stored['templates'][$key] ?? null) ? $stored['templates'][$key] : null;
			$result[] = ['key' => $key, 'name' => $name, 'subject' => (string)($own['subject'] ?? $subject), 'body' => (string)($own['body'] ?? $body),
				'isDefault' => $own === null, 'defaultSubject' => $subject, 'defaultBody' => $body];
		}
		return $result;
	}

	public function catalog(): array {
		$signatures = [];
		foreach ($this->configuration?->branches() ?? [] as $branch) {
			if (!empty($branch['active'])) {
				$signatures[] = ['key' => (string)$branch['key'], 'name' => (string)$branch['name'], 'signature' => $this->signature($branch)];
			}
		}
		return ['templates' => $this->templates(), 'placeholders' => self::PLACEHOLDERS, 'signatureExtra' => (string)($this->stored()['signatureExtra'] ?? ''),
			'signatures' => $signatures, 'canEdit' => $this->team?->isBestatterAdmin() ?? false];
	}

	/** Vorlage speichern (Bestatter-Administration). Leere Eingabe = mitgelieferter Text. */
	public function save(string $key, string $subject, string $body): array {
		$this->team?->requireBestatterAdmin();
		$key = strtoupper(trim($key));
		if (!isset(self::KEYS[$key])) {
			throw new \InvalidArgumentException('Unbekannte E-Mail-Vorlage.');
		}
		$subject = trim((string)preg_replace('/[\r\n]+/', ' ', $subject));
		$body = trim(str_replace("\r\n", "\n", $body));
		$stored = $this->stored();
		$before = $stored['templates'][$key] ?? null;
		if ($subject === '' && $body === '') {
			unset($stored['templates'][$key]);
		} else {
			if ($subject === '' || mb_strlen($subject) > 250) {
				throw new \InvalidArgumentException('Bitte einen Betreff mit höchstens 250 Zeichen angeben.');
			}
			if ($body === '' || mb_strlen($body) > 8000) {
				throw new \InvalidArgumentException('Bitte einen Text mit höchstens 8.000 Zeichen angeben.');
			}
			$this->assertPlaceholders($subject . "\n" . $body);
			$stored['templates'][$key] = ['subject' => $subject, 'body' => $body];
		}
		$this->store($stored);
		$this->audit?->logSystem('MAIL_TEMPLATE', 'UPDATED', ['key' => $key, 'before' => $before, 'after' => $stored['templates'][$key] ?? 'Standardtext']);
		return $this->catalog();
	}

	/** Zusatz unter der Signatur aller Niederlassungen (z. B. Erreichbarkeit, Internetseite). */
	public function saveSignatureExtra(string $text): array {
		$this->team?->requireBestatterAdmin();
		$text = trim(str_replace("\r\n", "\n", $text));
		if (mb_strlen($text) > 1000) {
			throw new \InvalidArgumentException('Der Signaturzusatz darf höchstens 1.000 Zeichen lang sein.');
		}
		$stored = $this->stored();
		$stored['signatureExtra'] = $text;
		$this->store($stored);
		$this->audit?->logSystem('MAIL_TEMPLATE', 'SIGNATURE_UPDATED', ['signatureExtra' => $text]);
		return $this->catalog();
	}

	/** Vorlage für ein finales Dokument der Fallakte: ORDER, QUOTE, INVOICE oder null (andere Dokumente). */
	public static function keyForRecord(array $record): ?string {
		$data = is_array($record['data'] ?? null) ? $record['data'] : [];
		if ((int)($data['invoiceId'] ?? 0) > 0) {
			return 'INVOICE';
		}
		$templateKey = strtoupper((string)($data['templateKey'] ?? ''));
		if ($templateKey !== 'BESTATTUNGSAUFTRAG') {
			return null;
		}
		if (strtoupper((string)($data['commercialDocumentType'] ?? '')) === 'QUOTE' || str_contains(mb_strtolower((string)($record['title'] ?? '')), 'kostenvoranschlag')) {
			return 'QUOTE';
		}
		return 'ORDER';
	}

	/**
	 * Betreff, Text und Empfänger für einen Fall. $context: invoiceId (Rechnung), withdrawalEnclosure (bool, Auftrag),
	 * documentNumber/documentDate (optional).
	 * @return array{key:string,subject:string,body:string,recipient:string}
	 */
	public function render(string $key, array $case, array $context = [], ?array $template = null): array {
		$key = strtoupper($key);
		if (!isset(self::KEYS[$key])) {
			throw new \InvalidArgumentException('Unbekannte E-Mail-Vorlage.');
		}
		$template ??= array_column($this->templates(), null, 'key')[$key];
		$values = $this->values($key, $case, $context);
		$replace = static fn (string $text): string => strtr($text, $values);
		$subject = trim((string)preg_replace('/\s+/', ' ', $replace((string)$template['subject'])));
		$body = trim((string)preg_replace("/\n{3,}/", "\n\n", $replace((string)$template['body'])));
		return ['key' => $key, 'subject' => $subject, 'body' => $body, 'recipient' => $this->recipient($key, (array)($case['masterData'] ?? []))];
	}

	/** Vorschau mit Beispieldaten (erste aktive Niederlassung) – auch für ungespeicherte Eingaben. */
	public function preview(string $key, string $subject, string $body): array {
		$key = strtoupper(trim($key));
		if (!isset(self::KEYS[$key])) {
			throw new \InvalidArgumentException('Unbekannte E-Mail-Vorlage.');
		}
		$this->assertPlaceholders($subject . "\n" . $body);
		$branch = '';
		foreach ($this->configuration?->branches() ?? [] as $candidate) {
			if (!empty($candidate['active'])) {
				$branch = (string)$candidate['key'];
				break;
			}
		}
		$today = date('Y-m-d');
		$case = ['id' => 0, 'caseNumber' => '2026-0001', 'firstName' => 'Margarete', 'lastName' => 'Beispiel', 'branch' => $branch, 'masterData' => [
			'order_client_salutation' => 'Frau', 'order_client_first_name' => 'Erika', 'order_client_name' => 'Muster', 'order_client_email' => 'erika.muster@example.org',
			'order_number' => 'A-2026-0001', 'order_date' => $today, 'kva_number' => 'KVA-2026-0001', 'kva_date' => $today, 'kva_valid_until' => date('Y-m-d', strtotime('+14 days')), 'payment_method' => 'TRANSFER',
		]];
		$sampleInvoice = ['invoice_number' => 'RE-2026-000001', 'document_kind' => 'INVOICE', 'invoice_type' => 'FINAL', 'gross_cents' => 452350, 'created_at' => $today, 'payment_snapshot' => ''];
		return $this->render($key, $case, ['withdrawalEnclosure' => true, 'sampleInvoice' => $sampleInvoice], ['subject' => $subject, 'body' => $body]);
	}

	/** Signatur einer Niederlassung (Firma, Anschrift, Kontakt, Zusatz, Pflichtangaben). */
	public function signature(array $branch): string {
		$lines = [
			trim((string)($branch['name'] ?? '')),
			trim((string)($branch['street'] ?? '')),
			trim(trim((string)($branch['postalCode'] ?? '')) . ' ' . trim((string)($branch['city'] ?? ''))),
			implode(' · ', array_filter([($branch['phone'] ?? '') !== '' ? 'Telefon ' . $branch['phone'] : '', ($branch['email'] ?? '') !== '' ? 'E-Mail ' . $branch['email'] : ''])),
		];
		$extra = trim((string)($this->stored()['signatureExtra'] ?? ''));
		$legal = array_filter([
			($branch['managingDirectors'] ?? '') !== '' ? 'Geschäftsführung: ' . $branch['managingDirectors'] : '',
			trim((string)($branch['registerCourt'] ?? '') . ' ' . (string)($branch['registerNumber'] ?? '')) !== '' ? 'Registergericht: ' . trim((string)($branch['registerCourt'] ?? '') . ' ' . (string)($branch['registerNumber'] ?? '')) : '',
			($branch['vatId'] ?? '') !== '' ? 'USt-IdNr.: ' . $branch['vatId'] : '',
		]);
		$text = implode("\n", array_filter($lines, static fn (string $line): bool => $line !== ''));
		if ($extra !== '') {
			$text .= "\n" . $extra;
		}
		if ($legal !== []) {
			$text .= "\n" . implode(' · ', $legal);
		}
		return trim($text);
	}

	/** „ unter Telefon … oder per E-Mail an …“ (für „erreichen Sie uns jederzeit{{mail.contact}}.“). */
	public static function contact(array $branch): string {
		$parts = array_filter([trim((string)($branch['phone'] ?? '')) !== '' ? 'Telefon ' . trim((string)$branch['phone']) : '', trim((string)($branch['email'] ?? '')) !== '' ? 'per E-Mail an ' . trim((string)$branch['email']) : '']);
		return $parts === [] ? '' : ' unter ' . implode(' oder ', $parts);
	}

	/** Anrede aus Anrede, Vor- und Nachname. */
	public static function salutation(string $title, string $firstName, string $lastName): string {
		$title = mb_strtolower(trim($title));
		$lastName = trim($lastName);
		if ($lastName !== '' && $title === 'frau') {
			return 'Sehr geehrte Frau ' . $lastName;
		}
		if ($lastName !== '' && $title === 'herr') {
			return 'Sehr geehrter Herr ' . $lastName;
		}
		$name = trim(trim($firstName) . ' ' . $lastName);
		return $name !== '' ? 'Guten Tag ' . $name : 'Guten Tag';
	}

	private function values(string $key, array $case, array $context): array {
		$m = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$value = static fn (string $field): string => trim((string)($m[$field] ?? ''));
		$branch = $this->configuration?->branchByKey((string)($case['branch'] ?? $m['branch'] ?? '')) ?? [];
		$invoiceRecipient = $key === 'INVOICE' && strtoupper($value('invoice_same_as_client') ?: 'JA') === 'NEIN';
		$prefix = $invoiceRecipient ? 'invoice_' : 'order_client_';
		$values = [
			'{{mail.salutation}}' => self::salutation($value($invoiceRecipient ? 'invoice_salutation' : 'order_client_salutation'), $value($prefix . 'first_name'), $value($invoiceRecipient ? 'invoice_name' : 'order_client_name')),
			'{{mail.signature}}' => $branch !== [] ? $this->signature($branch) : trim((string)($this->stored()['signatureExtra'] ?? '')),
			'{{user.name}}' => trim((string)($this->users?->getUser()?->getDisplayName() ?? '')),
			'{{deceased_name}}' => trim((string)($case['firstName'] ?? $value('first_name')) . ' ' . (string)($case['lastName'] ?? $value('last_name'))),
			'{{case.case_number}}' => (string)($case['caseNumber'] ?? ''),
			'{{mail.contact}}' => self::contact($branch),
			'{{branch.name}}' => (string)($branch['name'] ?? ''), '{{branch.phone}}' => (string)($branch['phone'] ?? ''), '{{branch.email}}' => (string)($branch['email'] ?? ''),
			'{{mail.withdrawal_enclosure}}' => '', '{{quote.validity_sentence}}' => '', '{{invoice.total}}' => '', '{{invoice.due_date}}' => '', '{{invoice.payment_sentence}}' => '',
			'{{document.title}}' => self::KEYS[$key] === 'Rechnung, Storno und Gutschrift' ? 'Rechnung' : self::KEYS[$key], '{{document.number}}' => '', '{{document.date}}' => '',
		];
		if ($key === 'ORDER') {
			$values['{{document.number}}'] = (string)($context['documentNumber'] ?? '') ?: ($value('order_number') ?: CaseKinds::orderDocumentNumber((string)($case['caseNumber'] ?? '')));
			$values['{{document.date}}'] = self::date((string)($context['documentDate'] ?? '') ?: ($value('order_date') ?: date('Y-m-d')));
			$values['{{mail.withdrawal_enclosure}}'] = !empty($context['withdrawalEnclosure']) ? self::WITHDRAWAL_ENCLOSURE : '';
		} elseif ($key === 'QUOTE') {
			$values['{{document.number}}'] = (string)($context['documentNumber'] ?? '') ?: ($value('kva_number') ?: $value('order_number'));
			$values['{{document.date}}'] = self::date((string)($context['documentDate'] ?? '') ?: ($value('kva_date') ?: date('Y-m-d')));
			$validUntil = self::date($value('kva_valid_until'));
			$values['{{quote.validity_sentence}}'] = $validUntil !== '' ? ' Er gilt bis zum ' . $validUntil . '.' : '';
		} else {
			$invoice = is_array($context['sampleInvoice'] ?? null) ? $context['sampleInvoice'] : $this->invoice((int)($context['invoiceId'] ?? 0), (int)($case['id'] ?? 0));
			if ($invoice !== null) {
				$values = $this->invoiceValues($invoice, $branch, $m) + $values;
			}
		}
		return $values;
	}

	private function invoiceValues(array $invoice, array $branch, array $m): array {
		$kind = (string)($invoice['document_kind'] ?? 'INVOICE') ?: 'INVOICE';
		$title = match ($kind) {
			'CANCELLATION' => 'Stornorechnung', 'CREDIT_NOTE' => 'Gutschrift', default => (string)($invoice['invoice_type'] ?? '') === 'FINAL' ? 'Schlussrechnung' : 'Teilrechnung'
		};
		$payment = json_decode((string)($invoice['payment_snapshot'] ?? ''), true);
		$payment = is_array($payment) ? $payment : [];
		$created = substr((string)($invoice['created_at'] ?? ''), 0, 10) ?: date('Y-m-d');
		$termDays = (int)($payment['paymentTermDays'] ?? $branch['paymentTermDays'] ?? 14);
		$due = (new \DateTimeImmutable($created))->modify('+' . max(0, $termDays) . ' days')->format('d.m.Y');
		$sentence = '';
		if ($kind === 'INVOICE' && (int)($invoice['gross_cents'] ?? 0) > 0) {
			$collection = self::date((string)($payment['collectionDate'] ?? ''));
			$sentence = strtoupper((string)($payment['method'] ?? $m['payment_method'] ?? 'TRANSFER')) === 'SEPA_DIRECT_DEBIT'
				? ' Den Betrag ziehen wir per SEPA-Lastschrift' . ($collection !== '' ? ' am ' . $collection : '') . ' von Ihrem Konto ein.'
				: ' Bitte überweisen Sie den Betrag bis zum ' . $due . ' unter Angabe der Rechnungsnummer.';
		}
		return [
			'{{document.title}}' => $title, '{{document.number}}' => (string)($invoice['invoice_number'] ?? ''), '{{document.date}}' => self::date($created),
			'{{invoice.total}}' => number_format(abs((int)($invoice['gross_cents'] ?? 0)) / 100, 2, ',', '.') . ' €', '{{invoice.due_date}}' => $kind === 'INVOICE' ? $due : '',
			'{{invoice.payment_sentence}}' => $sentence,
		];
	}

	private function recipient(string $key, array $m): string {
		$invoiceRecipient = $key === 'INVOICE' && strtoupper(trim((string)($m['invoice_same_as_client'] ?? 'JA'))) === 'NEIN';
		$email = trim((string)($m[$invoiceRecipient ? 'invoice_email' : 'order_client_email'] ?? ''));
		if ($email === '' && $invoiceRecipient) {
			$email = trim((string)($m['order_client_email'] ?? ''));
		}
		return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
	}

	private function invoice(int $invoiceId, int $caseId): ?array {
		if ($invoiceId <= 0 || $this->db === null) {
			return null;
		}
		$query = $this->db->getQueryBuilder();
		$row = $query->select('*')->from('bestatter_invoices')->where($query->expr()->eq('id', $query->createNamedParameter($invoiceId)))->executeQuery()->fetchAssociative();
		return $row !== false && (int)$row['case_id'] === $caseId ? $row : null;
	}

	private function assertPlaceholders(string $text): void {
		preg_match_all('/\{\{[^{}]+\}\}/u', $text, $matches);
		$unknown = array_values(array_unique(array_diff($matches[0] ?? [], array_keys(self::PLACEHOLDERS))));
		if ($unknown !== []) {
			throw new \InvalidArgumentException('Unbekannte Platzhalter: ' . implode(', ', $unknown) . '. Zulässig sind die unter der Vorlage aufgeführten Platzhalter.');
		}
		if (substr_count($text, '{{') !== count($matches[0] ?? []) || substr_count($text, '}}') !== count($matches[0] ?? [])) {
			throw new \InvalidArgumentException('Die Vorlage enthält einen unvollständigen Platzhalter.');
		}
	}

	/** @return array{0:string,1:string} */
	private static function defaultTemplate(string $key): array {
		return [self::DEFAULTS[$key][0], self::DEFAULTS[$key][1] . self::GREETING];
	}

	private static function date(string $value): string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
		return $date instanceof \DateTimeImmutable ? $date->format('d.m.Y') : $value;
	}

	private function stored(): array {
		$data = json_decode((string)($this->config?->getAppValue(Application::APP_ID, self::CONFIG_KEY, '') ?? ''), true);
		return is_array($data) ? $data + ['templates' => []] : ['templates' => []];
	}

	private function store(array $data): void {
		$this->config?->setAppValue(Application::APP_ID, self::CONFIG_KEY, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
	}
}
