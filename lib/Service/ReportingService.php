<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IDBConnection;

/** Datensparsame, qualitätsbewertete Auswertungen bis einschließlich Rechnungsstellung. */
class ReportingService {
	private const TYPES = ['cases', 'services', 'invoices', 'branches', 'workload', 'indicators'];
	private const CLOSED_CASE_STATUSES = ['ABGESCHLOSSEN', 'STORNIERT'];
	private const CLOSED_ACTIVITY_STATUSES = ['ERLEDIGT', 'DONE', 'ABGESCHLOSSEN', 'FINAL'];
	private const COMMITTED_INVOICE_STATUSES = ['FREIGEGEBEN', 'VERSENDET', 'TEILBEZAHLT', 'BEZAHLT'];

	public function __construct(private IDBConnection $db, private AuditService $audit) {}

	public function summary(string $from = '', string $to = '', string $branch = 'ALL'): array {
		$cases = $this->caseRows($from, $to, $branch);
		$services = $this->serviceRows($from, $to, $branch);
		$invoices = $this->invoiceRows($from, $to, $branch);
		$branches = $this->branchRows($from, $to, $branch);
		$workload = $this->workloadRows($from, $to, $branch);
		$kpis = $this->kpis($cases, $services, $invoices, $workload);
		$kpis['generatedIndicators'] = $this->indicatorRowsFromKpis($kpis);
		return [
			'generatedAt' => date('c'),
			'filters' => $this->filters($from, $to, $branch),
			'cases' => count($cases), 'services' => count($services), 'invoices' => count($invoices),
			'branches' => count($branches), 'workload' => count($workload),
			'kpis' => $kpis,
		];
	}

	public function csv(string $type, string $from = '', string $to = '', string $branch = 'ALL'): string {
		$type = strtolower(trim($type));
		if (!in_array($type, self::TYPES, true)) throw new \InvalidArgumentException('Die gewählte Auswertung ist nicht verfügbar.');
		$rows = match ($type) {
			'cases' => $this->caseRows($from, $to, $branch),
			'services' => $this->serviceRows($from, $to, $branch),
			'invoices' => $this->invoiceRows($from, $to, $branch),
			'branches' => $this->branchRows($from, $to, $branch),
			'workload' => $this->workloadRows($from, $to, $branch),
			'indicators' => $this->indicatorRows($from, $to, $branch),
		};
		$this->audit->logSystem('REPORT', 'EXPORTED', ['type' => $type, 'filters' => $this->filters($from, $to, $branch), 'rows' => count($rows)]);
		return $this->encode($rows);
	}

	private function caseRows(string $from, string $to, string $branch): array {
		$q = $this->db->getQueryBuilder();
		$q->select('case_number', 'first_name', 'last_name', 'date_of_death', 'funeral_type', 'status', 'branch', 'responsible_employee', 'created_at')->from('bestatter_cases');
		$this->where($q, 'created_at', 'branch', $from, $to, $branch);
		$rows = $q->orderBy('created_at', 'DESC')->executeQuery()->fetchAllAssociative();
		return array_map(static fn($r) => ['Fallnummer' => $r['case_number'], 'Vorname' => $r['first_name'], 'Nachname' => $r['last_name'], 'Sterbedatum' => $r['date_of_death'] ?? '', 'Bestattungsart' => $r['funeral_type'] ?? '', 'Fallstatus' => $r['status'], 'Niederlassung' => $r['branch'] ?? '', 'Verantwortlich' => $r['responsible_employee'] ?? '', 'Angelegt am' => $r['created_at']], $rows);
	}

	private function serviceRows(string $from, string $to, string $branch): array {
		$q = $this->db->getQueryBuilder();
		$q->select('c.case_number', 'c.branch', 's.side_order_id', 'so.side_order_number', 's.article_number', 's.title', 's.origin', 's.cost_type', 's.unit', 's.ordered_quantity_milli', 's.performed_quantity_milli', 's.invoiced_quantity_milli', 's.unit_price_cents', 's.vat_rate', 's.service_status', 's.billability', 's.performed_by', 's.updated_at')->from('bestatter_case_services', 's')->innerJoin('s', 'bestatter_cases', 'c', $q->expr()->eq('c.id', 's.case_id'))->leftJoin('s', 'bestatter_side_orders', 'so', $q->expr()->eq('so.id', 's.side_order_id'));
		$this->where($q, 's.updated_at', 'c.branch', $from, $to, $branch);
		$rows = $q->orderBy('c.case_number', 'ASC')->addOrderBy('s.sort_order', 'ASC')->executeQuery()->fetchAllAssociative();
		return array_map(static fn($r) => ['Fallnummer' => $r['case_number'], 'Nebenauftrag' => $r['side_order_number'] ?? '', 'Niederlassung' => $r['branch'] ?? '', 'Artikelnummer' => $r['article_number'], 'Leistung' => $r['title'], 'Herkunft' => ($r['origin'] ?? '') === 'NACHTRAG' ? 'NTR – Nachtrag' : ($r['origin'] ?? 'ORDER'), 'Kostenart' => $r['cost_type'], 'Einheit' => $r['unit'], 'Beauftragt' => (int)$r['ordered_quantity_milli'] / 1000, 'Erbracht' => (int)$r['performed_quantity_milli'] / 1000, 'Fakturiert' => (int)$r['invoiced_quantity_milli'] / 1000, 'Einzelpreis netto' => (int)$r['unit_price_cents'] / 100, 'MwSt. %' => (int)$r['vat_rate'], 'Leistungsstatus' => $r['service_status'], 'Abrechenbarkeit' => $r['billability'], 'Bearbeitet von' => $r['performed_by'] ?? '', 'Stand' => $r['updated_at']], $rows);
	}

	private function invoiceRows(string $from, string $to, string $branch): array {
		$q = $this->db->getQueryBuilder();
		$q->select('c.case_number', 'c.branch', 'i.side_order_id', 'so.side_order_number', 'i.invoice_number', 'i.status', 'i.net_cents', 'i.vat_cents', 'i.gross_cents', 'i.created_by', 'i.created_at')->from('bestatter_invoices', 'i')->innerJoin('i', 'bestatter_cases', 'c', $q->expr()->eq('c.id', 'i.case_id'))->leftJoin('i', 'bestatter_side_orders', 'so', $q->expr()->eq('so.id', 'i.side_order_id'));
		$this->where($q, 'i.created_at', 'c.branch', $from, $to, $branch);
		$rows = $q->orderBy('i.created_at', 'DESC')->executeQuery()->fetchAllAssociative();
		return array_map(static fn($r) => ['Rechnungsnummer' => $r['invoice_number'], 'Fallnummer' => $r['case_number'], 'Nebenauftrag' => $r['side_order_number'] ?? '', 'Niederlassung' => $r['branch'] ?? '', 'Status' => $r['status'], 'Netto EUR' => (int)$r['net_cents'] / 100, 'MwSt. EUR' => (int)$r['vat_cents'] / 100, 'Brutto EUR' => (int)$r['gross_cents'] / 100, 'Erstellt von' => $r['created_by'], 'Erstellt am' => $r['created_at']], $rows);
	}

	private function workloadRows(string $from, string $to, string $branch): array {
		$q = $this->db->getQueryBuilder();
		$q->select('c.case_number', 'c.branch', 'r.record_type', 'r.title', 'r.status', 'r.record_date', 'r.owner_uid', 'r.assignee_uid', 'r.assignee_name', 'r.created_by', 'r.updated_by', 'r.updated_at', 'r.payload')->from('bestatter_records', 'r')->leftJoin('r', 'bestatter_cases', 'c', $q->expr()->eq('c.id', 'r.case_id'))->where($q->expr()->orX(
			$q->expr()->eq('r.record_type', $q->createNamedParameter('task')),
			$q->expr()->eq('r.record_type', $q->createNamedParameter('schedule')),
		));
		$this->where($q, 'r.record_date', 'c.branch', $from, $to, $branch, true);
		$rows = $q->orderBy('r.record_date', 'ASC')->executeQuery()->fetchAllAssociative();
		return array_map(static function ($r): array {
			$payload = json_decode((string)($r['payload'] ?? ''), true);
			$payload = is_array($payload) ? $payload : [];
			return ['Art' => $r['record_type'] === 'task' ? 'Aufgabe' : 'Termin', 'Fallnummer' => $r['case_number'] ?? '', 'Niederlassung' => $r['branch'] ?? '', 'Bezeichnung' => $r['title'], 'Status' => $r['status'], 'Datum / Zeit' => $r['record_date'] ?? '', 'Zuständig' => $r['assignee_name'] ?: ($r['assignee_uid'] ?: $r['owner_uid']), 'Planzeit Minuten' => $r['record_type'] === 'schedule' ? (int)($payload['durationMinutes'] ?? 0) : (int)($payload['estimatedMinutes'] ?? 0), 'Istzeit Minuten' => (int)($payload['actualMinutes'] ?? 0), 'Angelegt von' => $r['created_by'], 'Zuletzt bearbeitet von' => $r['updated_by'] ?? '', 'Stand' => $r['updated_at']];
		}, $rows);
	}

	private function kpis(array $cases, array $services, array $invoices, array $workload): array {
		$employees = [];
		$openTasks = $completedTasks = $overdueTasks = $tasksWithDueDate = 0;
		$appointments = $completedAppointments = $cancelledAppointments = 0;
		$plannedMinutes = $actualMinutes = $plannedEntries = $actualEntries = $assignedEntries = 0;
		$invalidActivityValues = $extremeTimeDeviations = 0;
		$now = time();
		foreach ($workload as $row) {
			$name = trim((string)($row['Zuständig'] ?? '')) ?: 'Ohne Zuordnung';
			if ($name !== 'Ohne Zuordnung') $assignedEntries++;
			$employees[$name] ??= ['name' => $name, 'openTasks' => 0, 'completedTasks' => 0, 'overdueTasks' => 0, 'appointments' => 0, 'plannedMinutes' => 0, 'actualMinutes' => 0, 'actualTimeCoveragePercent' => 0, '_activities' => 0, '_actualEntries' => 0];
			$employees[$name]['_activities']++;
			$status = strtoupper((string)$row['Status']);
			$done = in_array($status, self::CLOSED_ACTIVITY_STATUSES, true);
			if ($row['Art'] === 'Aufgabe') {
				if ($done) { $completedTasks++; $employees[$name]['completedTasks']++; }
				else {
					$openTasks++; $employees[$name]['openTasks']++;
					$due = trim((string)($row['Datum / Zeit'] ?? ''));
					$dueTimestamp = $due === '' ? false : strtotime($due);
					if ($dueTimestamp !== false) { $tasksWithDueDate++; if ($dueTimestamp < $now) { $overdueTasks++; $employees[$name]['overdueTasks']++; } }
					elseif ($due !== '') $invalidActivityValues++;
				}
			} else {
				$appointments++; $employees[$name]['appointments']++;
				if ($done) $completedAppointments++;
				if ($status === 'ABGESAGT' || $status === 'CANCELLED') $cancelledAppointments++;
			}
			$plan = (int)($row['Planzeit Minuten'] ?? 0); $actual = (int)($row['Istzeit Minuten'] ?? 0);
			if ($plan < 0 || $actual < 0) { $invalidActivityValues++; $plan = max(0, $plan); $actual = max(0, $actual); }
			if ($plan > 0) $plannedEntries++;
			if ($actual > 0) { $actualEntries++; $employees[$name]['_actualEntries']++; }
			if ($plan > 0 && $actual > $plan * 3) $extremeTimeDeviations++;
			$employees[$name]['plannedMinutes'] += $plan; $employees[$name]['actualMinutes'] += $actual;
			$plannedMinutes += $plan; $actualMinutes += $actual;
		}
		foreach ($employees as &$employee) { $employee['actualTimeCoveragePercent'] = $this->percent($employee['_actualEntries'], $employee['_activities']); unset($employee['_activities'], $employee['_actualEntries']); }
		unset($employee); ksort($employees, SORT_NATURAL | SORT_FLAG_CASE);

		$orderedQuantity = $performedQuantity = $invoicedQuantity = 0.0;
		$performedServices = $fullyPerformedServices = $invalidServiceValues = $serviceQuantityDeviations = 0;
		$performedNetByCostType = ['INTERNAL' => 0.0, 'EXPENSE' => 0.0, 'THIRD_PARTY' => 0.0];
		foreach ($services as $row) {
			$ordered = (float)($row['Beauftragt'] ?? 0); $performed = (float)($row['Erbracht'] ?? 0); $invoiced = (float)($row['Fakturiert'] ?? 0);
			if ($ordered < 0 || $performed < 0 || $invoiced < 0 || (float)($row['Einzelpreis netto'] ?? 0) < 0) $invalidServiceValues++;
			if ($invoiced > $performed + 0.0005) $invalidServiceValues++;
			if ($ordered > 0 && $performed > $ordered + 0.0005) $serviceQuantityDeviations++;
			$orderedQuantity += max(0.0, $ordered); $performedQuantity += max(0.0, $performed); $invoicedQuantity += max(0.0, $invoiced);
			$costType = strtoupper((string)($row['Kostenart'] ?? 'INTERNAL'));
			$performedNetByCostType[$costType] = ($performedNetByCostType[$costType] ?? 0.0) + max(0.0, $performed) * max(0.0, (float)($row['Einzelpreis netto'] ?? 0));
			if ($performed > 0) $performedServices++;
			if ($ordered > 0 && $performed + 0.0005 >= $ordered) $fullyPerformedServices++;
		}

		$invoiceNet = $invoiceGross = $committedInvoiceGross = 0.0;
		$activeInvoices = $committedInvoices = $invalidInvoiceValues = 0;
		foreach ($invoices as $row) {
			$status = strtoupper((string)($row['Status'] ?? ''));
			if ($status === 'STORNIERT') continue;
			$net = (float)($row['Netto EUR'] ?? 0); $vat = (float)($row['MwSt. EUR'] ?? 0); $gross = (float)($row['Brutto EUR'] ?? 0);
			$activeInvoices++; $invoiceNet += $net; $invoiceGross += $gross;
			if (in_array($status, self::COMMITTED_INVOICE_STATUSES, true)) { $committedInvoices++; $committedInvoiceGross += $gross; }
			if ($net < 0 || $vat < 0 || $gross < 0 || abs(($net + $vat) - $gross) > 0.011) $invalidInvoiceValues++;
		}

		$qualityChecks = [
			$this->quality('assignment', 'Zuständigkeiten', count($workload) === 0 ? 'NOT_AVAILABLE' : ($assignedEntries === count($workload) ? 'OK' : 'WARN'), count($workload) === 0 ? 'Keine Aufgaben oder Termine im Filterzeitraum.' : ($assignedEntries === count($workload) ? 'Alle Aktivitäten sind einer Person zugeordnet.' : sprintf('%d von %d Aktivitäten haben keine eindeutige Zuständigkeit.', count($workload) - $assignedEntries, count($workload))), $this->percent($assignedEntries, count($workload))),
			$this->quality('planned_time', 'Planzeiten', count($workload) === 0 ? 'NOT_AVAILABLE' : ($plannedEntries === count($workload) ? 'OK' : 'WARN'), count($workload) === 0 ? 'Keine Aktivitäten im Filterzeitraum.' : sprintf('Für %d von %d Aktivitäten ist eine Planzeit vorhanden.', $plannedEntries, count($workload)), $this->percent($plannedEntries, count($workload))),
			$this->quality('actual_time', 'Istzeiten', count($workload) === 0 ? 'NOT_AVAILABLE' : ($actualEntries / max(1, count($workload)) >= 0.8 ? 'OK' : 'WARN'), count($workload) === 0 ? 'Keine Aktivitäten im Filterzeitraum.' : ($actualEntries === 0 ? 'Es sind keine Istzeiten erfasst. Eine Auslastungsquote wäre fachlich nicht belastbar.' : sprintf('Istzeiten liegen für %d von %d Aktivitäten vor.', $actualEntries, count($workload))), $this->percent($actualEntries, count($workload))),
			$this->quality('activities', 'Aufgaben- und Terminwerte', ($invalidActivityValues + $extremeTimeDeviations) === 0 ? 'OK' : 'WARN', ($invalidActivityValues + $extremeTimeDeviations) === 0 ? 'Datums- und Zeitwerte sind rechnerisch plausibel.' : sprintf('%d ungültige Werte und %d auffällige Plan-Ist-Abweichungen wurden gefunden.', $invalidActivityValues, $extremeTimeDeviations)),
			$this->quality('services', 'Leistungsmengen', $invalidServiceValues === 0 ? ($serviceQuantityDeviations === 0 ? 'OK' : 'WARN') : 'ERROR', $invalidServiceValues > 0 ? sprintf('%d Leistungspositionen enthalten negative Werte oder mehr fakturierte als erbrachte Menge.', $invalidServiceValues) : ($serviceQuantityDeviations > 0 ? sprintf('%d Positionen überschreiten die beauftragte Menge und sollten fachlich geprüft werden.', $serviceQuantityDeviations) : 'Beauftragte, erbrachte und fakturierte Mengen sind rechnerisch plausibel.')),
			$this->quality('invoices', 'Rechnungssummen', $invalidInvoiceValues === 0 ? 'OK' : 'ERROR', $invalidInvoiceValues === 0 ? 'Netto plus Umsatzsteuer stimmt bei allen nicht stornierten Rechnungen mit Brutto überein.' : sprintf('%d Rechnungen weisen negative oder rechnerisch abweichende Summen auf.', $invalidInvoiceValues)),
			$this->quality('capacity', 'Personalkapazität', 'NOT_AVAILABLE', 'Dienstpläne, Arbeitszeiten und Abwesenheiten sind noch nicht integriert. Deshalb wird bewusst keine Mitarbeiterauslastungsquote berechnet.'),
		];
		$statuses = array_column($qualityChecks, 'status');
		$overall = in_array('ERROR', $statuses, true) ? 'ERROR' : (in_array('WARN', $statuses, true) ? 'WARN' : 'OK');
		$performedNetTotal = array_sum($performedNetByCostType);
		return [
			'openCases' => count(array_filter($cases, static fn(array $row): bool => !in_array(strtoupper((string)$row['Fallstatus']), self::CLOSED_CASE_STATUSES, true))),
			'closedCases' => count(array_filter($cases, static fn(array $row): bool => in_array(strtoupper((string)$row['Fallstatus']), self::CLOSED_CASE_STATUSES, true))),
			'openTasks' => $openTasks, 'completedTasks' => $completedTasks,
			'taskCompletionRate' => $this->percent($completedTasks, $openTasks + $completedTasks),
			'overdueTasks' => $overdueTasks, 'overdueRate' => $this->percent($overdueTasks, $openTasks), 'tasksWithDueDate' => $tasksWithDueDate,
			'appointments' => $appointments, 'completedAppointments' => $completedAppointments, 'cancelledAppointments' => $cancelledAppointments,
			'appointmentCancellationRate' => $this->percent($cancelledAppointments, $appointments),
			'plannedMinutes' => $plannedMinutes, 'actualMinutes' => $actualMinutes,
			'actualTimeCoveragePercent' => $this->percent($actualEntries, count($workload)),
			'actualPlanRatioPercent' => $plannedMinutes > 0 && $actualEntries > 0 ? $this->percent($actualMinutes, $plannedMinutes) : null,
			'invoiceNet' => round($invoiceNet, 2), 'invoiceGross' => round($invoiceGross, 2),
			'committedInvoiceGross' => round($committedInvoiceGross, 2), 'committedInvoices' => $committedInvoices,
			'averageInvoiceGross' => $activeInvoices > 0 ? round($invoiceGross / $activeInvoices, 2) : null,
			'performedServices' => $performedServices, 'fullyPerformedServices' => $fullyPerformedServices,
			'serviceCompletionRate' => $orderedQuantity > 0 ? round($performedQuantity / $orderedQuantity * 100, 1) : null,
			'billingRate' => $performedQuantity > 0 ? round($invoicedQuantity / $performedQuantity * 100, 1) : null,
			'performedNetByCostType' => array_map(static fn(float $value): float => round($value, 2), $performedNetByCostType),
			'performedNetShareByCostType' => array_map(fn(float $value): float => $this->percent($value, $performedNetTotal), $performedNetByCostType),
			'employees' => array_values($employees), 'quality' => ['overall' => $overall, 'checks' => $qualityChecks],
		];
	}

	private function indicatorRows(string $from, string $to, string $branch): array {
		$kpis = $this->kpis($this->caseRows($from, $to, $branch), $this->serviceRows($from, $to, $branch), $this->invoiceRows($from, $to, $branch), $this->workloadRows($from, $to, $branch));
		return $this->indicatorRowsFromKpis($kpis);
	}

	private function indicatorRowsFromKpis(array $kpis): array {
		$costValues = $kpis['performedNetByCostType'] ?? [];
		$costShares = $kpis['performedNetShareByCostType'] ?? [];
		return [
			$this->indicator('Ist-Zeit-Abdeckung', $kpis['actualTimeCoveragePercent'] ?? 0, '%', 'Aufgaben und Termine mit erfasster Ist-Zeit', ($kpis['actualTimeCoveragePercent'] ?? 0) >= 80 ? 'BELASTBAR' : 'EINGESCHRAENKT'),
			$this->indicator('Plan-Ist-Verhältnis', $kpis['actualPlanRatioPercent'] ?? '', '%', 'Ist-Minuten im Verhältnis zu Plan-Minuten; nur bei vorhandenen Ist-Zeiten', $kpis['actualPlanRatioPercent'] === null ? 'NICHT_VERFUEGBAR' : 'BERECHNET'),
			$this->indicator('Aufgabenerledigungsquote', $kpis['taskCompletionRate'] ?? 0, '%', 'Erledigte Aufgaben im Verhältnis zu allen Aufgaben im Filterzeitraum', 'BERECHNET'),
			$this->indicator('Überfälligkeitsquote', $kpis['overdueRate'] ?? 0, '%', 'Überfällige Aufgaben im Verhältnis zu offenen Aufgaben', 'BERECHNET'),
			$this->indicator('Terminabsagequote', $kpis['appointmentCancellationRate'] ?? 0, '%', 'Abgesagte Termine im Verhältnis zu allen Terminen', 'BERECHNET'),
			$this->indicator('Eigenleistungsanteil', $costShares['INTERNAL'] ?? 0, '%', 'Netto-Wert erbrachter Eigenleistungen', 'BERECHNET', $costValues['INTERNAL'] ?? 0),
			$this->indicator('Auslagenanteil', $costShares['EXPENSE'] ?? 0, '%', 'Netto-Wert erbrachter Auslagen und Gebühren', 'BERECHNET', $costValues['EXPENSE'] ?? 0),
			$this->indicator('Fremdleistungsanteil', $costShares['THIRD_PARTY'] ?? 0, '%', 'Netto-Wert erbrachter Fremdleistungen', 'BERECHNET', $costValues['THIRD_PARTY'] ?? 0),
			$this->indicator('Dokumentvollständigkeit je Prozessphase', '', '%', 'Benötigt administrativ freigegebene Pflichtdokumente je Prozessphase; bis dahin keine scheinpräzise Quote', 'NOCH_NICHT_KONFIGURIERT'),
		];
	}

	private function indicator(string $name, float|int|string $value, string $unit, string $basis, string $status, ?float $netValue = null): array {
		return ['Kennzahl' => $name, 'Wert' => $value, 'Einheit' => $unit, 'Status' => $status, 'Datenbasis / Einschränkung' => $basis, 'Bezugswert netto EUR' => $netValue === null ? '' : round($netValue, 2)];
	}

	private function quality(string $key, string $label, string $status, string $message, ?float $coveragePercent = null): array { return ['key' => $key, 'label' => $label, 'status' => $status, 'message' => $message, 'coveragePercent' => $coveragePercent]; }
	private function percent(float|int $part, float|int $total): float { return $total > 0 ? round($part / $total * 100, 1) : 0.0; }

	private function branchRows(string $from, string $to, string $branch): array {
		$groups = [];
		foreach ($this->invoiceRows($from, $to, $branch) as $row) {
			if (strtoupper((string)$row['Status']) === 'STORNIERT') continue;
			$key = (string)($row['Niederlassung'] ?: 'Ohne Zuordnung');
			$groups[$key] ??= ['Niederlassung' => $key, 'Rechnungen' => 0, 'Netto EUR' => 0.0, 'MwSt. EUR' => 0.0, 'Brutto EUR' => 0.0];
			$groups[$key]['Rechnungen']++; $groups[$key]['Netto EUR'] += (float)$row['Netto EUR']; $groups[$key]['MwSt. EUR'] += (float)$row['MwSt. EUR']; $groups[$key]['Brutto EUR'] += (float)$row['Brutto EUR'];
		}
		ksort($groups, SORT_NATURAL | SORT_FLAG_CASE); return array_values($groups);
	}

	private function where($q, string $dateColumn, string $branchColumn, string $from, string $to, string $branch, bool $hasWhere = false): void {
		$filters = [];
		if ($from !== '') $filters[] = $q->expr()->gte($dateColumn, $q->createNamedParameter($this->date($from)));
		if ($to !== '') { $nextDay = (new \DateTimeImmutable($this->date($to)))->modify('+1 day')->format('Y-m-d'); $filters[] = $q->expr()->lt($dateColumn, $q->createNamedParameter($nextDay)); }
		if ($branch !== '' && strtoupper($branch) !== 'ALL') $filters[] = $q->expr()->eq($branchColumn, $q->createNamedParameter(mb_substr($branch, 0, 100)));
		foreach ($filters as $condition) { if ($hasWhere) $q->andWhere($condition); else { $q->where($condition); $hasWhere = true; } }
	}

	private function filters(string $from, string $to, string $branch): array { return ['from' => $from === '' ? '' : $this->date($from), 'to' => $to === '' ? '' : $this->date($to), 'branch' => $branch === '' ? 'ALL' : $branch]; }
	private function date(string $value): string { $value = trim($value); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) throw new \InvalidArgumentException('Der Zeitraum enthält ein ungültiges Datum.'); return $value; }
	private function encode(array $rows): string { if (!$rows) return "\xEF\xBB\xBFKeine Daten\r\n"; $out = fopen('php://temp', 'r+'); fputcsv($out, array_keys($rows[0]), ';', '"', '\\', "\r\n"); foreach ($rows as $row) fputcsv($out, $row, ';', '"', '\\', "\r\n"); rewind($out); return "\xEF\xBB\xBF" . (string)stream_get_contents($out); }
}
