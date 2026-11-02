<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * 0.90.0 – Buchhaltungsexport: Ausgangsrechnungen, Stornorechnungen und Gutschriften als DATEV-Buchungsstapel (OP-027 Stufe 3B).
 *
 * Ablauf (Lastenheft Abschnitt 9): Zeitraum oder Belege wählen → Vorschau mit Prüfliste (Summen, Warnungen, Fehler) → Export.
 * Der Export vergibt Debitorennummern, erzeugt die Datei, prüft sie mit dem eingebauten Validator, setzt den Exportstatus
 * der Belege und schreibt das Exportprotokoll (Zeitraum, Anzahl, Summen, Datei, SHA-256, Formatversion). Ein Wiederholungsexport
 * lädt dieselbe Datei erneut; ein Nachexport bereits exportierter Belege braucht eine Begründung.
 *
 * Exportfähig sind nur ausgestellte Belege (ab Freigabe). Eine durch Stornorechnung aufgehobene Rechnung bleibt exportfähig –
 * die Stornorechnung bucht sie mit eigenem Beleg zurück. Verworfene Entwürfe werden nie exportiert.
 */
class AccountingExportService {
	private const ISSUED = ['FREIGEGEBEN', 'VERSENDET', 'TEILBEZAHLT', 'BEZAHLT'];
	private const EXPORT_LOCK = 'ACCT-EXPORT';
	private const PREVIEW_LINES = 200;

	public function __construct(
		private IDBConnection $db,
		private IUserSession $userSession,
		private AccountingSettingsService $settings,
		private InstallationConfigService $installation,
		private CaseStorageService $storage,
		private AuditService $audit,
	) {
	}

	/** Startseite „Buchhaltung“: Einstellungsstand, offene Belege, letzte Exporte. */
	public function overview(): array {
		$settings = $this->settings->settings();
		$open = $this->openInvoices();
		$dates = array_column($open, 'date');
		return [
			'ready' => AccountingSettingsService::problems($settings) === [],
			'problems' => AccountingSettingsService::problems($settings),
			'open' => ['count' => count($open), 'from' => $dates === [] ? null : min($dates), 'to' => $dates === [] ? null : max($dates),
				'grossCents' => array_sum(array_map(static fn (array $invoice): int => (int)$invoice['totals']['gross'], $open))],
			'exports' => $this->exports(),
			'formatVersion' => (new DatevFormat())->formatVersion(),
		];
	}

	/** FA-19: Vorschau und Prüfliste – verändert nichts (Debitoren werden nur vorgemerkt). */
	public function preview(array $input): array {
		$prepared = $this->prepare($input, false);
		$prepared['lines'] = array_slice($prepared['lines'], 0, self::PREVIEW_LINES);
		$prepared['linesTruncated'] = $prepared['summary']['lineCount'] > self::PREVIEW_LINES;
		return $prepared;
	}

	/** Export ausführen. Läuft unter einer Sperre, damit zwei gleichzeitige Exporte keinen Beleg doppelt liefern (NFA-03). */
	public function export(array $input): array {
		$uid = $this->uid();
		$format = new DatevFormat();
		$this->db->beginTransaction();
		try {
			$sequence = DatabaseLocks::nextSequence($this->db, self::EXPORT_LOCK);
			$prepared = $this->prepare($input, true);
			if ($prepared['blocked']) {
				$errors = array_values(array_filter($prepared['messages'], static fn (array $m): bool => $m['severity'] === 'ERROR'));
				throw new \InvalidArgumentException('Export nicht möglich: ' . implode(' ', array_map(static fn (array $m): string => ($m['invoice'] !== '' ? $m['invoice'] . ': ' : '') . $m['message'], array_slice($errors, 0, 5))) . (count($errors) > 5 ? ' (und ' . (count($errors) - 5) . ' weitere)' : ''));
			}
			$settings = $prepared['settings'];
			$number = sprintf('DATEV-%05d', $sequence);
			$created = new \DateTimeImmutable('now');
			$period = $prepared['period'];
			$label = 'Rechnungen ' . date('d.m.', (int)strtotime($period['from'])) . '-' . date('d.m.Y', (int)strtotime($period['to']));
			$baseName = 'EXTF_Buchungsstapel_' . str_replace('-', '', $period['from']) . '-' . str_replace('-', '', $period['to']) . '_Rechnungen_' . $number;
			$rendered = $format->render([
				'created' => $created, 'origin' => $settings['origin'], 'exportedBy' => $uid, 'consultant' => $settings['consultantNumber'],
				'client' => $settings['clientNumber'], 'fiscalYearStart' => $period['fiscalYearStart'], 'accountLength' => (int)$settings['accountLength'],
				'from' => $period['from'], 'to' => $period['to'], 'label' => $label, 'lock' => (bool)$settings['lockBookings'], 'chart' => $settings['chart'],
			], $prepared['allLines'], $baseName);
			$validation = [];
			foreach ($rendered['files'] as $file) {
				foreach ($format->validate($file['bytes']) as $error) {
					$validation[] = $file['name'] . ': ' . $error;
				}
			}
			if ($validation !== []) {
				throw new \InvalidArgumentException('Die erzeugte Datei besteht die Formatprüfung nicht: ' . implode(' ', array_slice($validation, 0, 5)));
			}
			[$bytes, $fileName, $mime] = $this->package($rendered['files'], $baseName);
			$messages = array_merge($prepared['messages'], array_map(static fn (string $text): array => ['code' => 'V-09', 'severity' => 'WARNING', 'invoice' => '', 'message' => $text], $rendered['warnings']));
			$now = date('c');
			$insert = $this->db->getQueryBuilder();
			$insert->insert('bestatter_acct_exports')->values([
				'export_number' => $insert->createNamedParameter($number), 'export_kind' => $insert->createNamedParameter($prepared['kind']),
				'date_from' => $insert->createNamedParameter($period['from']), 'date_to' => $insert->createNamedParameter($period['to']),
				'fiscal_year_start' => $insert->createNamedParameter($period['fiscalYearStart']),
				'invoice_count' => $insert->createNamedParameter(count($prepared['invoices']), IQueryBuilder::PARAM_INT),
				'line_count' => $insert->createNamedParameter($prepared['summary']['lineCount'], IQueryBuilder::PARAM_INT),
				'debit_cents' => $insert->createNamedParameter($prepared['summary']['debitCents'], IQueryBuilder::PARAM_INT),
				'credit_cents' => $insert->createNamedParameter($prepared['summary']['creditCents'], IQueryBuilder::PARAM_INT),
				'file_name' => $insert->createNamedParameter($fileName), 'mime_type' => $insert->createNamedParameter($mime),
				'sha256' => $insert->createNamedParameter(hash('sha256', $bytes)), 'format_version' => $insert->createNamedParameter('EXTF 700 / 21 / ' . $format->formatVersion()),
				'content' => $insert->createNamedParameter(base64_encode($bytes)), 'file_path' => $insert->createNamedParameter(null),
				'settings_snapshot' => $insert->createNamedParameter(json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
				'messages' => $insert->createNamedParameter(json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
				'reason' => $insert->createNamedParameter($prepared['reason'] !== '' ? $prepared['reason'] : null),
				'created_by' => $insert->createNamedParameter($uid), 'created_at' => $insert->createNamedParameter($now),
			])->executeStatement();
			$exportId = (int)$this->db->lastInsertId('bestatter_acct_exports');
			$lineCounts = array_count_values(array_map(static fn (array $line): int => (int)$line['invoiceId'], $prepared['allLines']));
			foreach ($prepared['invoices'] as $invoice) {
				$item = $this->db->getQueryBuilder();
				$item->insert('bestatter_acct_export_items')->values([
					'export_id' => $item->createNamedParameter($exportId, IQueryBuilder::PARAM_INT), 'invoice_id' => $item->createNamedParameter((int)$invoice['id'], IQueryBuilder::PARAM_INT),
					'invoice_number' => $item->createNamedParameter((string)$invoice['number']), 'debtor_number' => $item->createNamedParameter((int)$invoice['debtor'], IQueryBuilder::PARAM_INT),
					'gross_cents' => $item->createNamedParameter((int)$invoice['totals']['gross'], IQueryBuilder::PARAM_INT),
					'line_count' => $item->createNamedParameter((int)($lineCounts[(int)$invoice['id']] ?? 0), IQueryBuilder::PARAM_INT),
					'created_at' => $item->createNamedParameter($now),
				])->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		$path = $this->storeFile($settings, $fileName, $bytes, $period['from']);
		if ($path !== null) {
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_acct_exports')->set('file_path', $update->createNamedParameter($path))
				->where($update->expr()->eq('id', $update->createNamedParameter($exportId, IQueryBuilder::PARAM_INT)))->executeStatement();
		}
		$export = $this->exportById($exportId);
		$this->audit->logSystem('ACCOUNTING_EXPORT', 'CREATED', [
			'exportNumber' => $number, 'kind' => $prepared['kind'], 'from' => $period['from'], 'to' => $period['to'], 'invoices' => count($prepared['invoices']),
			'lines' => $prepared['summary']['lineCount'], 'debitCents' => $prepared['summary']['debitCents'], 'creditCents' => $prepared['summary']['creditCents'],
			'fileName' => $fileName, 'sha256' => $export['sha256'], 'reason' => $prepared['reason'], 'invoiceNumbers' => array_column($prepared['invoices'], 'number'),
		]);
		return $export + ['storedAt' => $path, 'messages' => $messages];
	}

	/** NFA-04: Exportprotokoll (ohne Dateiinhalt). */
	public function exports(int $limit = 100): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('id', 'export_number', 'export_kind', 'date_from', 'date_to', 'invoice_count', 'line_count', 'debit_cents', 'credit_cents', 'file_name', 'sha256', 'format_version', 'file_path', 'messages', 'reason', 'created_by', 'created_at')
			->from('bestatter_acct_exports')->orderBy('id', 'DESC')->setMaxResults($limit)->executeQuery()->fetchAllAssociative();
		return array_map(fn (array $row): array => $this->mapExport($row), $rows);
	}

	public function exportById(int $id): array {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('*')->from('bestatter_acct_exports')->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchAssociative();
		if (!$row) {
			throw new \InvalidArgumentException('Export wurde nicht gefunden.');
		}
		$export = $this->mapExport($row);
		$items = $this->db->getQueryBuilder();
		$export['invoices'] = array_map(static fn (array $item): array => [
			'invoiceId' => (int)$item['invoice_id'], 'number' => (string)$item['invoice_number'], 'debtor' => $item['debtor_number'] !== null ? (int)$item['debtor_number'] : null,
			'grossCents' => (int)$item['gross_cents'], 'lines' => (int)$item['line_count'],
		], $items->select('*')->from('bestatter_acct_export_items')->where($items->expr()->eq('export_id', $items->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative());
		return $export;
	}

	/** FA-18: Wiederholungsexport – liefert genau die gespeicherte Datei (gleiche Prüfsumme). @return array{content:string, fileName:string, mime:string} */
	public function download(int $id): array {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('export_number', 'content', 'file_name', 'mime_type', 'sha256')->from('bestatter_acct_exports')
			->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchAssociative();
		if (!$row) {
			throw new \InvalidArgumentException('Export wurde nicht gefunden.');
		}
		$content = base64_decode((string)$row['content'], true);
		if ($content === false || !hash_equals((string)$row['sha256'], hash('sha256', $content))) {
			throw new \RuntimeException('Die gespeicherte Exportdatei ist beschädigt (Prüfsumme stimmt nicht).');
		}
		$this->audit->logSystem('ACCOUNTING_EXPORT', 'DOWNLOADED', ['exportNumber' => (string)$row['export_number'], 'sha256' => (string)$row['sha256']]);
		return ['content' => $content, 'fileName' => (string)$row['file_name'], 'mime' => (string)$row['mime_type']];
	}

	/** Debitoren mit Anzahl der exportierten Belege (ohne Namen). */
	public function debtors(): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('debtor_number', 'first_invoice', 'created_at')->from('bestatter_debtors')->orderBy('debtor_number', 'ASC')->executeQuery()->fetchAllAssociative();
		$counts = [];
		$items = $this->db->getQueryBuilder();
		foreach ($items->select('debtor_number')->from('bestatter_acct_export_items')->executeQuery()->fetchAllAssociative() as $item) {
			$counts[(int)$item['debtor_number']] = ($counts[(int)$item['debtor_number']] ?? 0) + 1;
		}
		return array_map(static fn (array $row): array => ['number' => (int)$row['debtor_number'], 'firstInvoice' => (string)($row['first_invoice'] ?? ''), 'createdAt' => (string)$row['created_at'], 'exportedDocuments' => $counts[(int)$row['debtor_number']] ?? 0], $rows);
	}

	/**
	 * Debitorenliste als CSV zum Anlegen der Personenkonten in DATEV: Nummer, Name und Anschrift aus dem jüngsten exportierten Beleg.
	 * Die Namen werden nur für diesen Download gelesen und nicht in der Debitorentabelle gespeichert.
	 */
	public function debtorsCsv(): string {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('x.debtor_number', 'i.recipient', 'i.invoice_number', 'i.id')->from('bestatter_acct_export_items', 'x')
			->innerJoin('x', 'bestatter_invoices', 'i', $query->expr()->eq('x.invoice_id', 'i.id'))
			->orderBy('i.id', 'ASC')->executeQuery()->fetchAllAssociative();
		$latest = [];
		foreach ($rows as $row) {
			$latest[(int)$row['debtor_number']] = $row;
		}
		ksort($latest);
		$lines = [];
		foreach ($latest as $number => $row) {
			$recipient = json_decode((string)$row['recipient'], true) ?: [];
			$lines[] = [$number, (string)($recipient['name'] ?? ''), (string)($recipient['attention'] ?? ''), (string)($recipient['street'] ?? ''), (string)($recipient['postalCode'] ?? ''), (string)($recipient['city'] ?? ''), (string)($recipient['vatId'] ?? ''), (string)$row['invoice_number']];
		}
		$this->audit->logSystem('ACCOUNTING_EXPORT', 'DEBTORS_DOWNLOADED', ['count' => count($lines)]);
		return CsvTools::write(['Debitor', 'Name', 'z. Hd.', 'Straße', 'PLZ', 'Ort', 'USt-IdNr.', 'Letzter Beleg'], $lines);
	}

	// --------------------------------------------------------------------------------------------- Vorbereitung

	/**
	 * Belege auswählen, Debitoren zuordnen, Buchungszeilen bilden und prüfen.
	 * $input: from, to (Y-m-d), invoiceIds (Auswahl statt Zeitraum), includeLate (Nachzügler einbeziehen), reason (Nachexport).
	 */
	private function prepare(array $input, bool $assignDebtors): array {
		$settings = $this->settings->settings();
		$messages = [];
		foreach (AccountingSettingsService::problems($settings) as $problem) {
			$messages[] = ['code' => 'V-01', 'severity' => 'ERROR', 'invoice' => '', 'message' => $problem];
		}
		$add = static function (string $code, string $severity, string $text, string $invoice = '') use (&$messages): void {
			$messages[] = ['code' => $code, 'severity' => $severity, 'invoice' => $invoice, 'message' => $text];
		};
		$ids = array_values(array_unique(array_filter(array_map('intval', is_array($input['invoiceIds'] ?? null) ? $input['invoiceIds'] : array_filter(explode(',', (string)($input['invoiceIds'] ?? '')))))));
		$reason = trim((string)($input['reason'] ?? ''));
		$selection = $ids !== [];
		$excluded = [];
		$late = [];
		$includeLate = true;
		$fiscalBegin = '';
		$fiscalStart = AccountingSettingsService::validate($settings) === [] ? $settings['fiscalYearStart'] : '01-01';

		if ($selection) {
			$rows = $this->invoiceRows(ids: $ids);
			$found = array_column($rows, 'id');
			foreach (array_diff($ids, array_map('intval', $found)) as $missing) {
				$add('V-10', 'ERROR', 'Beleg ' . $missing . ' wurde nicht gefunden.');
			}
			$candidates = [];
			foreach ($rows as $row) {
				if (!$this->isIssued($row)) {
					$add('V-10', 'ERROR', 'Nur freigegebene Belege können exportiert werden (Status ' . $row['status'] . ').', (string)$row['invoice_number']);
					continue;
				}
				$candidates[] = $row;
			}
			$dates = array_map(static fn (array $row): string => substr((string)$row['created_at'], 0, 10), $candidates);
			$from = $dates === [] ? (string)($input['from'] ?? date('Y-m-d')) : min($dates);
			$to = $dates === [] ? $from : max($dates);
			$kind = 'SELECTION';
		} else {
			$from = $this->date((string)($input['from'] ?? ''), 'Beginn des Zeitraums');
			$to = $this->date((string)($input['to'] ?? ''), 'Ende des Zeitraums');
			if ($from > $to) {
				throw new \InvalidArgumentException('Der Zeitraum endet vor seinem Beginn.');
			}
			$includeLate = CsvTools::flag($input['includeLate'] ?? true, true);
			[$fiscalBegin] = AccountingSettingsService::fiscalYear($from, $fiscalStart);
			$candidates = [];
			$late = [];
			foreach ($this->invoiceRows(before: date('Y-m-d', (int)strtotime($to . ' +1 day'))) as $row) {
				if (!$this->isIssued($row)) {
					continue;
				}
				$date = substr((string)$row['created_at'], 0, 10);
				if ($date >= $from) {
					$candidates[] = $row;
				} else {
					$late[] = $row;
				}
			}
			$kind = 'PERIOD';
		}
		$exported = $this->exportedStatus(array_map(static fn (array $row): int => (int)$row['id'], array_merge($candidates, $late)));

		if (!$selection) {
			$alreadyExported = 0;
			$candidates = array_values(array_filter($candidates, static function (array $row) use ($exported, &$alreadyExported): bool {
				if (isset($exported[(int)$row['id']])) {
					$alreadyExported++;
					return false;
				}
				return true;
			}));
			if ($alreadyExported > 0) {
				$add('V-10', 'INFO', $alreadyExported . ' Beleg(e) im Zeitraum wurden bereits exportiert und werden nicht erneut geliefert. Für einen Nachexport die Belege einzeln auswählen.');
			}
			foreach ($late as $row) {
				if (isset($exported[(int)$row['id']])) {
					continue;
				}
				$date = substr((string)$row['created_at'], 0, 10);
				if ($date < $fiscalBegin) {
					$excluded[] = ['number' => (string)$row['invoice_number'], 'date' => $date, 'reason' => 'früheres Wirtschaftsjahr'];
					$add('V-10', 'WARNING', 'Noch nicht exportiert, liegt aber in einem früheren Wirtschaftsjahr (' . date('d.m.Y', (int)strtotime($date)) . '). Bitte gesondert für diesen Zeitraum exportieren.', (string)$row['invoice_number']);
				} elseif (!$includeLate) {
					$excluded[] = ['number' => (string)$row['invoice_number'], 'date' => $date, 'reason' => 'Nachzügler nicht einbezogen'];
				} else {
					$row['late'] = true;
					$candidates[] = $row;
					$add('V-10', 'INFO', 'Nachzügler: Belegdatum ' . date('d.m.Y', (int)strtotime($date)) . ' liegt vor dem Zeitraum, der Beleg wurde aber noch nicht exportiert und wird mitgeliefert.', (string)$row['invoice_number']);
				}
			}
		} else {
			$again = array_filter($candidates, static fn (array $row): bool => isset($exported[(int)$row['id']]));
			if ($again !== []) {
				$kind = 'REEXPORT';
				if ($reason === '') {
					$add('V-10', 'ERROR', 'Bereits exportiert (' . implode(', ', array_map(static fn (array $row): string => $row['invoice_number'] . ' in ' . $exported[(int)$row['id']]['exportNumber'], $again)) . '). Für einen Nachexport bitte eine Begründung angeben.');
				} else {
					$add('V-10', 'WARNING', 'Nachexport bereits exportierter Belege (' . implode(', ', array_column($again, 'invoice_number')) . '). Die Steuerberatung muss doppelte Buchungen in DATEV vermeiden.');
				}
			}
		}
		$this->legacyCancellations($from, $to, $add);

		usort($candidates, static fn (array $a, array $b): int => [substr((string)$a['created_at'], 0, 10), (int)$a['id']] <=> [substr((string)$b['created_at'], 0, 10), (int)$b['id']]);
		$dates = array_map(static fn (array $row): string => substr((string)$row['created_at'], 0, 10), $candidates);
		$periodFrom = $dates === [] ? $from : min($from, min($dates));
		$periodTo = $dates === [] ? $to : max($to, max($dates));
		[$fyBegin, $fyEnd] = AccountingSettingsService::fiscalYear($periodFrom, $fiscalStart);
		if ($periodTo > $fyEnd) {
			$add('V-11', 'ERROR', 'Der Zeitraum ' . date('d.m.Y', (int)strtotime($periodFrom)) . ' – ' . date('d.m.Y', (int)strtotime($periodTo)) . ' reicht über das Ende des Wirtschaftsjahres (' . date('d.m.Y', (int)strtotime($fyEnd)) . '). Ein DATEV-Stapel darf nur ein Wirtschaftsjahr enthalten – bitte getrennt exportieren.');
		}

		$invoices = $this->hydrate($candidates, $settings);
		$debtors = $this->resolveDebtors($invoices, $settings, $assignDebtors, $add);
		$allLines = [];
		foreach ($invoices as &$invoice) {
			$invoice['debtor'] = $debtors[$invoice['id']]['number'] ?? 0;
			$invoice['debtorNew'] = $debtors[$invoice['id']]['new'] ?? false;
			$built = DatevPostingBuilder::build($settings, $invoice);
			$invoice['lineCount'] = count($built['lines']);
			array_push($allLines, ...$built['lines']);
			array_push($messages, ...$built['messages']);
		}
		unset($invoice);
		$this->numberGaps($invoices, $add);
		if ($invoices === []) {
			$add('V-10', 'ERROR', $selection ? 'Keine exportfähigen Belege ausgewählt.' : 'Im Zeitraum gibt es keine noch nicht exportierten, freigegebenen Belege.');
		}
		$debit = array_sum(array_map(static fn (array $line): int => $line['side'] === 'S' ? (int)$line['amountCents'] : 0, $allLines));
		$credit = array_sum(array_map(static fn (array $line): int => $line['side'] === 'H' ? (int)$line['amountCents'] : 0, $allLines));
		$gross = array_sum(array_map(static fn (array $invoice): int => (int)$invoice['totals']['gross'], $invoices));
		if ($debit - $credit !== $gross && !array_filter($messages, static fn (array $m): bool => $m['severity'] === 'ERROR')) {
			$add('V-06', 'ERROR', 'Summe der Buchungszeilen (' . DatevPostingBuilder::euro($debit - $credit) . ') weicht von der Summe der Belege (' . DatevPostingBuilder::euro($gross) . ') ab.');
		}
		$byKind = [];
		foreach ($invoices as $invoice) {
			$label = DatevPostingBuilder::KIND_LABELS[$invoice['kind']] ?? $invoice['kind'];
			$byKind[$label] ??= ['count' => 0, 'grossCents' => 0];
			$byKind[$label]['count']++;
			$byKind[$label]['grossCents'] += (int)$invoice['totals']['gross'];
		}
		$severityOrder = ['ERROR' => 0, 'WARNING' => 1, 'INFO' => 2];
		usort($messages, static fn (array $a, array $b): int => [$severityOrder[$a['severity']] ?? 3, $a['code']] <=> [$severityOrder[$b['severity']] ?? 3, $b['code']]);
		return [
			'kind' => $kind, 'reason' => $reason, 'settings' => $settings,
			'period' => ['from' => $periodFrom, 'to' => $periodTo, 'fiscalYearStart' => $fyBegin, 'fiscalYearEnd' => $fyEnd, 'requestedFrom' => $from, 'requestedTo' => $to],
			'invoices' => array_map(static fn (array $invoice): array => array_diff_key($invoice, ['items' => true]), $invoices),
			'allLines' => $allLines, 'lines' => $allLines, 'excluded' => $excluded,
			'messages' => $messages,
			'blocked' => (bool)array_filter($messages, static fn (array $m): bool => $m['severity'] === 'ERROR'),
			'summary' => ['invoiceCount' => count($invoices), 'lineCount' => count($allLines), 'debitCents' => $debit, 'creditCents' => $credit, 'grossCents' => $gross, 'byKind' => $byKind,
				'newDebtors' => count(array_filter($invoices, static fn (array $invoice): bool => (bool)$invoice['debtorNew']))],
		];
	}

	private function isIssued(array $row): bool {
		$status = (string)$row['status'];
		return in_array($status, self::ISSUED, true) || ($status === 'STORNIERT' && $row['cancelled_by_id'] !== null && ($row['document_kind'] ?? 'INVOICE') === 'INVOICE');
	}

	/** @return list<array> */
	private function invoiceRows(array $ids = [], string $before = ''): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')->from('bestatter_invoices');
		if ($ids !== []) {
			$query->where($query->expr()->in('id', $query->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		} else {
			$query->where($query->expr()->lt('created_at', $query->createNamedParameter($before)));
		}
		return $query->orderBy('created_at', 'ASC')->addOrderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
	}

	/** Ausgestellte, noch nicht exportierte Belege (für die Übersicht). */
	private function openInvoices(): array {
		$rows = array_values(array_filter($this->invoiceRows(before: '9999-12-31'), fn (array $row): bool => $this->isIssued($row)));
		$exported = $this->exportedStatus(array_map(static fn (array $row): int => (int)$row['id'], $rows));
		return array_map(static fn (array $row): array => ['id' => (int)$row['id'], 'number' => (string)$row['invoice_number'], 'date' => substr((string)$row['created_at'], 0, 10), 'totals' => ['gross' => (int)$row['gross_cents']]],
			array_values(array_filter($rows, static fn (array $row): bool => !isset($exported[(int)$row['id']]))));
	}

	/** Exportstatus je Beleg: letzter Export (Nummer, Datum). @param list<int> $ids @return array<int, array{exportNumber:string, exportedAt:string}> */
	public function exportedStatus(array $ids): array {
		$result = [];
		foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
			$query = $this->db->getQueryBuilder();
			$rows = $query->select('x.invoice_id', 'e.export_number', 'e.created_at')->from('bestatter_acct_export_items', 'x')
				->innerJoin('x', 'bestatter_acct_exports', 'e', $query->expr()->eq('x.export_id', 'e.id'))
				->where($query->expr()->in('x.invoice_id', $query->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('e.id', 'ASC')->executeQuery()->fetchAllAssociative();
			foreach ($rows as $row) {
				$result[(int)$row['invoice_id']] = ['exportNumber' => (string)$row['export_number'], 'exportedAt' => (string)$row['created_at']];
			}
		}
		return $result;
	}

	/** Positionen, Fall, Warengruppe/Bereich und Bezug in die Form bringen, die DatevPostingBuilder erwartet. */
	private function hydrate(array $rows, array $settings): array {
		if ($rows === []) {
			return [];
		}
		$invoiceIds = array_map(static fn (array $row): int => (int)$row['id'], $rows);
		$items = [];
		foreach (array_chunk($invoiceIds, 500) as $chunk) {
			$query = $this->db->getQueryBuilder();
			foreach ($query->select('*')->from('bestatter_invoice_items')->where($query->expr()->in('invoice_id', $query->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('position_no', 'ASC')->addOrderBy('sub_position', 'ASC')->addOrderBy('id', 'ASC')->executeQuery()->fetchAllAssociative() as $item) {
				$items[(int)$item['invoice_id']][] = $item;
			}
		}
		$serviceIds = [];
		foreach ($items as $list) {
			foreach ($list as $item) {
				$serviceIds[] = (int)$item['case_service_id'];
			}
		}
		$services = [];
		foreach (array_chunk(array_values(array_unique($serviceIds)), 500) as $chunk) {
			$query = $this->db->getQueryBuilder();
			foreach ($query->select('id', 'position_type', 'article_group_key', 'article_type')->from('bestatter_case_services')
				->where($query->expr()->in('id', $query->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))->executeQuery()->fetchAllAssociative() as $service) {
				$services[(int)$service['id']] = $service;
			}
		}
		$areas = [];
		$query = $this->db->getQueryBuilder();
		foreach ($query->select('group_key', 'area_key')->from('bestatter_article_groups')->executeQuery()->fetchAllAssociative() as $group) {
			if ($group['group_key'] !== null) {
				$areas[strtoupper((string)$group['group_key'])] = strtoupper((string)($group['area_key'] ?? ''));
			}
		}
		$cases = [];
		foreach (array_chunk(array_values(array_unique(array_map(static fn (array $row): int => (int)$row['case_id'], $rows))), 500) as $chunk) {
			$query = $this->db->getQueryBuilder();
			foreach ($query->select('id', 'case_number', 'branch')->from('bestatter_cases')->where($query->expr()->in('id', $query->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))->executeQuery()->fetchAllAssociative() as $case) {
				$cases[(int)$case['id']] = $case;
			}
		}
		$referenceIds = array_values(array_filter(array_map(static fn (array $row): int => (int)($row['reference_invoice_id'] ?? 0), $rows)));
		$references = [];
		if ($referenceIds !== []) {
			$query = $this->db->getQueryBuilder();
			foreach ($query->select('id', 'invoice_number')->from('bestatter_invoices')->where($query->expr()->in('id', $query->createNamedParameter($referenceIds, IQueryBuilder::PARAM_INT_ARRAY)))->executeQuery()->fetchAllAssociative() as $reference) {
				$references[(int)$reference['id']] = (string)$reference['invoice_number'];
			}
		}
		$result = [];
		foreach ($rows as $row) {
			$id = (int)$row['id'];
			$case = $cases[(int)$row['case_id']] ?? [];
			$date = substr((string)$row['created_at'], 0, 10);
			$payment = json_decode((string)($row['payment_snapshot'] ?? ''), true) ?: [];
			$kind = (string)($row['document_kind'] ?? 'INVOICE') ?: 'INVOICE';
			$result[] = [
				'id' => $id, 'number' => (string)$row['invoice_number'], 'kind' => $kind, 'type' => (string)($row['invoice_type'] ?? 'PARTIAL'),
				'status' => (string)$row['status'], 'date' => $date, 'caseId' => (int)$row['case_id'], 'caseNumber' => (string)($case['case_number'] ?? ''),
				'branch' => strtoupper((string)($case['branch'] ?? '')), 'referenceNumber' => $references[(int)($row['reference_invoice_id'] ?? 0)] ?? '',
				'servicePeriodFrom' => (string)($row['service_period_from'] ?? ''), 'servicePeriodTo' => (string)($row['service_period_to'] ?? ''),
				'dueDate' => $kind === 'INVOICE' ? date('Y-m-d', (int)strtotime($date . ' +' . max(0, (int)($payment['paymentTermDays'] ?? 14)) . ' days')) : '',
				'recipient' => json_decode((string)$row['recipient'], true) ?: [], 'late' => (bool)($row['late'] ?? false),
				'totals' => ['net' => (int)$row['net_cents'], 'vat' => (int)$row['vat_cents'], 'gross' => (int)$row['gross_cents']],
				'items' => array_map(static function (array $item) use ($services, $areas): array {
					$service = $services[(int)$item['case_service_id']] ?? [];
					$group = strtoupper((string)($service['article_group_key'] ?? ''));
					return [
						'position' => (int)($item['sub_position'] ?? 0) > 0 ? $item['position_no'] . '.' . $item['sub_position'] : (string)$item['position_no'],
						'net' => (int)$item['net_cents'], 'vat' => (int)$item['vat_cents'], 'gross' => (int)$item['gross_cents'], 'vatRate' => (int)$item['vat_rate'],
						'positionType' => strtoupper((string)($service['position_type'] ?? 'EL')), 'group' => $group, 'area' => $areas[$group] ?? '',
						'articleType' => strtoupper((string)($service['article_type'] ?? '')),
					];
				}, $items[$id] ?? []),
			];
		}
		return $result;
	}

	/**
	 * FA-06 / V-03: Debitorennummer je Rechnungsempfänger (oder Sammeldebitor). In der Vorschau werden neue Nummern nur vorgemerkt,
	 * beim Export angelegt. @return array<int, array{number:int, new:bool}>
	 */
	private function resolveDebtors(array $invoices, array $settings, bool $assign, callable $add): array {
		$result = [];
		if ($settings['debtorMode'] === 'COLLECTIVE') {
			foreach ($invoices as $invoice) {
				$result[$invoice['id']] = ['number' => (int)$settings['collectiveDebtor'], 'new' => false];
			}
			return $result;
		}
		[$min, $max] = AccountingSettingsService::debtorRange((int)$settings['accountLength']);
		$query = $this->db->getQueryBuilder();
		$known = [];
		$highest = 0;
		foreach ($query->select('debtor_number', 'recipient_key')->from('bestatter_debtors')->executeQuery()->fetchAllAssociative() as $row) {
			$known[(string)$row['recipient_key']] = (int)$row['debtor_number'];
			$highest = max($highest, (int)$row['debtor_number']);
		}
		$next = max((int)$settings['debtorStart'], $highest + 1, $min);
		$planned = [];
		foreach ($invoices as $invoice) {
			$key = self::recipientKey($invoice['recipient']);
			if (isset($known[$key])) {
				$result[$invoice['id']] = ['number' => $known[$key], 'new' => false];
				continue;
			}
			if (!isset($planned[$key])) {
				if ($next > $max) {
					$add('V-03', 'ERROR', 'Der Debitorennummernkreis ist erschöpft (höchstens ' . $max . ').', $invoice['number']);
					$planned[$key] = 0;
				} else {
					$planned[$key] = $next++;
					if ($assign) {
						$insert = $this->db->getQueryBuilder();
						$insert->insert('bestatter_debtors')->values([
							'debtor_number' => $insert->createNamedParameter($planned[$key], IQueryBuilder::PARAM_INT), 'recipient_key' => $insert->createNamedParameter($key),
							'first_invoice' => $insert->createNamedParameter($invoice['number']), 'created_by' => $insert->createNamedParameter($this->uid()),
							'created_at' => $insert->createNamedParameter(date('c')),
						])->executeStatement();
					}
				}
			}
			$result[$invoice['id']] = ['number' => $planned[$key], 'new' => true];
		}
		if ($planned !== []) {
			$add('V-03', 'INFO', count($planned) . ' neue(r) Debitor(en) ' . ($assign ? 'angelegt' : 'werden beim Export angelegt') . ' (' . implode(', ', array_filter($planned)) . '). Für Namen in DATEV die Debitorenliste herunterladen.');
		}
		return $result;
	}

	/** Schlüssel eines Rechnungsempfängers: USt-IdNr. oder normalisierte Name/Anschrift, als SHA-256 (kein Klartext gespeichert). */
	public static function recipientKey(array $recipient): string {
		$vatId = strtoupper(preg_replace('/\s+/', '', (string)($recipient['vatId'] ?? '')) ?? '');
		if ($vatId !== '') {
			return hash('sha256', 'VAT|' . $vatId);
		}
		$normalize = static fn (mixed $value): string => trim(preg_replace('/\s+/u', ' ', preg_replace('/[.,;:]/u', ' ', mb_strtolower((string)$value)) ?? '') ?? '');
		return hash('sha256', 'ADR|' . implode('|', array_map($normalize, [$recipient['name'] ?? '', $recipient['street'] ?? '', $recipient['postalCode'] ?? '', $recipient['city'] ?? ''])));
	}

	/** V-04: Lücken im Nummernkreis und verworfene Entwürfe als Hinweis (GoBD: Lücken müssen erklärbar sein). */
	private function numberGaps(array $invoices, callable $add): void {
		$prefixes = [];
		foreach ($invoices as $invoice) {
			if (preg_match('/^(.*?)(\d+)$/', (string)$invoice['number'], $m)) {
				$prefixes[$m[1]] = strlen($m[2]);
			}
		}
		foreach ($prefixes as $prefix => $digits) {
			if ($prefix === '') {
				continue;
			}
			$query = $this->db->getQueryBuilder();
			$rows = $query->select('invoice_number', 'status', 'cancelled_by_id', 'document_kind')->from('bestatter_invoices')
				->where($query->expr()->like('invoice_number', $query->createNamedParameter($this->db->escapeLikeParameter($prefix) . '%')))->executeQuery()->fetchAllAssociative();
			$present = [];
			$discarded = [];
			foreach ($rows as $row) {
				if (!preg_match('/^' . preg_quote($prefix, '/') . '(\d{' . $digits . '})$/', (string)$row['invoice_number'], $m)) {
					continue;
				}
				$present[(int)$m[1]] = true;
				if ((string)$row['status'] === 'STORNIERT' && $row['cancelled_by_id'] === null) {
					$discarded[] = (string)$row['invoice_number'];
				}
			}
			if ($present === []) {
				continue;
			}
			$missing = [];
			for ($n = min(array_keys($present)); $n <= max(array_keys($present)); $n++) {
				if (!isset($present[$n])) {
					$missing[] = $prefix . str_pad((string)$n, $digits, '0', STR_PAD_LEFT);
				}
			}
			if ($missing !== []) {
				$add('V-04', 'WARNING', 'Lücke im Nummernkreis „' . $prefix . '…“: ' . implode(', ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? ' (und ' . (count($missing) - 10) . ' weitere)' : '') . ' fehlen ganz. Bitte klären und dokumentieren.');
			}
			if ($discarded !== []) {
				$add('V-04', 'INFO', 'Verworfene Rechnungsentwürfe im Nummernkreis „' . $prefix . '…“ (werden nicht exportiert, Grund steht im Audit-Protokoll): ' . implode(', ', array_slice($discarded, 0, 10)) . (count($discarded) > 10 ? ' …' : '') . '.');
			}
		}
	}

	/** Vor 0.70.0 konnte eine freigegebene Rechnung ohne Stornobeleg storniert werden – nicht exportieren, Hinweis zur manuellen Klärung. */
	private function legacyCancellations(string $from, string $to, callable $add): void {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('id', 'invoice_number', 'document_kind')->from('bestatter_invoices')
			->where($query->expr()->eq('status', $query->createNamedParameter('STORNIERT')))
			->andWhere($query->expr()->isNull('cancelled_by_id'))
			->andWhere($query->expr()->gte('created_at', $query->createNamedParameter($from)))
			->andWhere($query->expr()->lt('created_at', $query->createNamedParameter(date('Y-m-d', (int)strtotime($to . ' +1 day')))))
			->executeQuery()->fetchAllAssociative();
		$rows = array_values(array_filter($rows, static fn (array $row): bool => ((string)($row['document_kind'] ?? 'INVOICE') ?: 'INVOICE') === 'INVOICE'));
		if ($rows === []) {
			return;
		}
		$audit = $this->db->getQueryBuilder();
		$released = array_map('intval', array_column($audit->select('object_id')->from('bestatter_audit_log')
			->where($audit->expr()->eq('object_type', $audit->createNamedParameter('INVOICE')))
			->andWhere($audit->expr()->eq('action', $audit->createNamedParameter('STATUS_FREIGEGEBEN')))
			->andWhere($audit->expr()->in('object_id', $audit->createNamedParameter(array_map(static fn (array $row): int => (int)$row['id'], $rows), IQueryBuilder::PARAM_INT_ARRAY)))
			->executeQuery()->fetchAllAssociative(), 'object_id'));
		foreach ($rows as $row) {
			if (in_array((int)$row['id'], $released, true)) {
				$add('V-10', 'WARNING', 'Wurde nach der Freigabe ohne Stornorechnung storniert (ältere App-Version) und wird nicht exportiert. Bitte mit der Steuerberatung manuell klären.', (string)$row['invoice_number']);
			}
		}
	}

	/** Eine Datei bleibt CSV; mehrere Teilstapel (über 99.999 Zeilen) werden zu einer ZIP-Datei. @return array{string,string,string} */
	private function package(array $files, string $baseName): array {
		if (count($files) === 1) {
			return [$files[0]['bytes'], $files[0]['name'], 'text/csv'];
		}
		$temp = tempnam(sys_get_temp_dir(), 'bestatter-datev');
		if ($temp === false) {
			throw new \RuntimeException('Temporäre Datei für den Export konnte nicht angelegt werden.');
		}
		try {
			$zip = new \ZipArchive();
			if ($zip->open($temp, \ZipArchive::OVERWRITE) !== true) {
				throw new \RuntimeException('ZIP-Datei für den Export konnte nicht angelegt werden.');
			}
			foreach ($files as $file) {
				$zip->addFromString($file['name'], $file['bytes']);
				$zip->setMtimeName($file['name'], 0);
			}
			$zip->close();
			return [(string)file_get_contents($temp), $baseName . '.zip', 'application/zip'];
		} finally {
			@unlink($temp);
		}
	}

	/** Ablage im eigenen Nextcloud-Ordner der exportierenden Person (nicht im gemeinsamen Fallordner – NFA-05). */
	private function storeFile(array $settings, string $fileName, string $bytes, string $from): ?string {
		if ((string)$settings['exportFolder'] === '') {
			return null;
		}
		try {
			$path = trim($settings['exportFolder'], '/') . '/' . substr($from, 0, 4);
			$folder = $this->installation->ensurePath($this->storage->personalFolder(), $path);
			$folder->newFile($fileName, $bytes);
			return $path . '/' . $fileName;
		} catch (\Throwable) {
			return null;
		}
	}

	private function mapExport(array $row): array {
		$messages = json_decode((string)($row['messages'] ?? ''), true) ?: [];
		return [
			'id' => (int)$row['id'], 'number' => (string)$row['export_number'], 'kind' => (string)$row['export_kind'],
			'kindLabel' => match ((string)$row['export_kind']) {
				'SELECTION' => 'Auswahl', 'REEXPORT' => 'Nachexport', default => 'Zeitraum'
			},
			'from' => (string)$row['date_from'], 'to' => (string)$row['date_to'], 'invoiceCount' => (int)$row['invoice_count'], 'lineCount' => (int)$row['line_count'],
			'debitCents' => (int)$row['debit_cents'], 'creditCents' => (int)$row['credit_cents'], 'fileName' => (string)$row['file_name'], 'sha256' => (string)$row['sha256'],
			'formatVersion' => (string)$row['format_version'], 'filePath' => (string)($row['file_path'] ?? ''), 'reason' => (string)($row['reason'] ?? ''),
			'warnings' => count(array_filter($messages, static fn (array $m): bool => ($m['severity'] ?? '') === 'WARNING')),
			'messages' => $messages, 'createdBy' => (string)$row['created_by'], 'createdAt' => (string)$row['created_at'],
		];
	}

	private function date(string $value, string $label): string {
		$value = trim($value);
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		if ($date === false || $date->format('Y-m-d') !== $value) {
			throw new \InvalidArgumentException($label . ' bitte als Datum angeben.');
		}
		return $value;
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? 'system';
	}
}
