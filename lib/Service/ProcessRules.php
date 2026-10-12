<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * Reine, zustandslose Fachregeln der Ablaufvorlagen (0.66.0).
 *
 * Bewusst ohne Nextcloud-/Datenbankabhängigkeit, damit die Regeln (Auslöser, Bedingungen,
 * Fristen, Feiertage, Zuschläge, CSV-Austausch) isoliert und ohne laufende Nextcloud geprüft
 * werden können (tests/php-process-rules.php). ProcessService nutzt ausschließlich diese
 * Funktionen, um aus dem Fallzustand abzuleiten, welche Schritte anfallen.
 */
final class ProcessRules {
	public const PHASES = [
		1 => 'Erstkontakt & Überführung',
		2 => 'Beratung & Beauftragung',
		3 => 'Behörden & Formalitäten',
		4 => 'Trauerfeier & Beisetzung',
		5 => 'Abmeldungen',
		6 => 'Abrechnung',
		7 => 'Nachsorge & Abschluss',
	];

	/** Auslöser: wann ein Schritt im Fall entsteht. */
	public const TRIGGERS = [
		'CASE_CREATED' => 'Fall angelegt',
		'FUNERAL_TYPE_SET' => 'Bestattungsart festgelegt',
		'FIELD_SET' => 'Stammdatenfeld ausgefüllt',
		'CASE_STATUS' => 'Fallstatus gewechselt',
		'QUOTE_CREATED' => 'KVA erstellt',
		'ORDER_COMMISSIONED' => 'Auftrag erteilt',
		'ORDER_SIGNED' => 'Auftrag unterschrieben',
		'SIDE_ORDER_COMMISSIONED' => 'Nebenauftrag beauftragt',
		'SIDE_ORDER_CLOSED' => 'Nebenauftrag abgeschlossen',
		'SCHEDULE_CONFIRMED' => 'Termin bestätigt',
		'SCHEDULE_DONE' => 'Termin stattgefunden',
		'SCHEDULE_CHANGED' => 'Termin geändert',
		'SCHEDULE_CANCELLED' => 'Termin abgesagt',
		'DOCUMENT_CREATED' => 'Dokument erzeugt',
		'DOCUMENT_FINAL' => 'Dokument finalisiert',
		'DOCUMENT_SENT' => 'Dokument per E-Mail versendet',
		'DOCUMENT_RECEIVED' => 'Dokument eingegangen',
		'DEREGISTRATION_SENT' => 'Abmeldung versendet',
		'DEREGISTRATION_CONFIRMED' => 'Abmeldung bestätigt',
		'STEP_DONE' => 'anderer Schritt erledigt',
		'DEPOSIT_AGREED' => 'Anzahlung vereinbart',
		'INCOMING_INVOICE' => 'Eingangsrechnung erfasst',
		'FINAL_INVOICE_ISSUED' => 'Schlussrechnung freigegeben',
		'PAYMENT_RECEIVED' => 'Schlussrechnung bezahlt',
		'INVOICE_OVERDUE' => 'Rechnung überfällig',
		'DATE_REACHED' => 'Datum erreicht',
		'LABEL_SET' => 'Marker gesetzt',
		'LABEL_REMOVED' => 'Marker entfernt',
		'MANUAL' => 'nur von Hand',
	];

	/** Auslöser bzw. Erledigt-Bedingungen mit Marker (0.76.0). */
	public const LABEL_TRIGGERS = ['LABEL_SET', 'LABEL_REMOVED'];

	/** Auslöser mit Terminart(en). */
	public const SCHEDULE_TRIGGERS = ['SCHEDULE_CONFIRMED', 'SCHEDULE_DONE', 'SCHEDULE_CHANGED', 'SCHEDULE_CANCELLED'];
	/** Auslöser mit Dokumentvorlage (Pflicht) bzw. Abmeldevorlage (optional, leer = jede Stelle). */
	public const DOCUMENT_TRIGGERS = ['DOCUMENT_CREATED', 'DOCUMENT_FINAL', 'DOCUMENT_SENT'];
	public const DEREGISTRATION_TRIGGERS = ['DEREGISTRATION_SENT', 'DEREGISTRATION_CONFIRMED'];
	/** 0.80.0 (OP-058): Auslöser bzw. Erledigt-Bedingung mit Dokumentart (eingegangenes Schriftstück, Upload oder Paperless). */
	public const RECEIVED_TRIGGERS = ['DOCUMENT_RECEIVED'];
	/** Zeitgesteuerte Auslöser: brauchen den regelmäßigen Hintergrundabgleich (ProcessScheduleJob). */
	public const TIME_TRIGGERS = ['DATE_REACHED', 'INVOICE_OVERDUE'];

	/** Fallstatus, auf die „Fallstatus gewechselt“ reagieren kann (storniert beendet den Ablauf). */
	public const CASE_STATUSES = ['NEU' => 'Neu', 'IN_BEARBEITUNG' => 'In Bearbeitung', 'ABGESCHLOSSEN' => 'Abgeschlossen'];

	/** Bezugsdatum für „Datum erreicht“. */
	public const DATE_REFERENCES = [
		'DEATH_DATE' => 'Sterbedatum',
		'CEREMONY' => 'Trauerfeier',
		'BURIAL' => 'Beisetzung',
		'CASE_CREATED' => 'Fallanlage',
		'CASE_CLOSED' => 'Fallabschluss',
	];

	/** Bezugsdatum der Frist. */
	public const REFERENCES = [
		'TRIGGER' => 'Auslösung',
		'CASE_CREATED' => 'Fallanlage',
		'DEATH_DATE' => 'Sterbedatum',
		'CEREMONY' => 'Trauerfeier',
		'BURIAL' => 'Beisetzung',
	];

	public const ROLES = [
		'' => 'wie Auslöser / aktuelle Person',
		'FALLVERANTWORTLICH' => 'Fallverantwortliche/r',
		'BESTATTER' => 'Bestattung',
		'BUERO' => 'Büro',
		'FAHRER' => 'Fahrer',
		'BUCHHALTUNG' => 'Buchhaltung',
	];

	public const PRIORITIES = ['KRITISCH', 'HOCH', 'NORMAL', 'NIEDRIG'];
	public const PRIORITY_LABELS = ['KRITISCH' => 'kritisch', 'HOCH' => 'hoch', 'NORMAL' => 'normal', 'NIEDRIG' => 'niedrig'];

	public const SCOPES = [
		'ALL' => 'alle Fälle',
		'CREMATION' => 'Feuerbestattung (inkl. See/Wald)',
		'BURIAL' => 'Erdbestattung',
		'NATURE' => 'See-, Baum- und Waldbestattung',
		'GRAVE' => 'Erd- und Urnengrab mit Grabstelle',
	];

	/** Erledigt-Bedingungen: der Schritt hakt sich selbst ab bzw. entfällt, wenn sie erfüllt sind. */
	public const COMPLETIONS = [
		'NONE' => 'von Hand abhaken',
		'FUNERAL_TYPE_SET' => 'Bestattungsart festgelegt',
		'ORDER_COMMISSIONED' => 'Auftrag erteilt',
		'DOCUMENT_CREATED' => 'Dokument erzeugt',
		'DEREGISTRATIONS_CREATED' => 'Abmeldungen angelegt',
		'DEREGISTRATIONS_SENT' => 'alle Abmeldungen versendet',
		'SCHEDULE_CONFIRMED' => 'Termin bestätigt',
		'SCHEDULE_DONE' => 'Termin stattgefunden',
		'FIELD_SET' => 'Stammdatenfeld ausgefüllt',
		'CASE_STATUS' => 'Fallstatus erreicht',
		'QUOTE_CREATED' => 'KVA erstellt',
		'ORDER_SIGNED' => 'Auftrag unterschrieben',
		'SIDE_ORDER_CLOSED' => 'Nebenauftrag abgeschlossen',
		'DOCUMENT_FINAL' => 'Dokument finalisiert',
		'DOCUMENT_SENT' => 'Dokument per E-Mail versendet',
		'DOCUMENT_RECEIVED' => 'Dokument eingegangen',
		'DEREGISTRATION_SENT' => 'Abmeldung versendet',
		'DEREGISTRATION_CONFIRMED' => 'Abmeldung bestätigt',
		'DEPOSIT_RECEIVED' => 'Anzahlung eingegangen',
		'INCOMING_TRANSFERRED' => 'Eingangsrechnung übernommen',
		'FINAL_INVOICE_ISSUED' => 'Schlussrechnung freigegeben',
		'PAYMENT_RECEIVED' => 'Schlussrechnung bezahlt',
		'LABEL_SET' => 'Marker gesetzt',
		'LABEL_REMOVED' => 'Marker entfernt',
	];

	/** Wurzelvarianten, die als See-, Baum- oder Waldbestattung gelten. */
	public const NATURE_ROOTS = ['SEA', 'TREE', 'FOREST', 'ALPINE_MEADOW', 'MEADOW'];
	/** Varianten mit (Urnen-)Grabstelle für Angehörige, zusätzlich zu allen Erdbestattungen. */
	public const GRAVE_VARIANTS = ['FIRE_URN_FAMILY'];

	private function __construct() {}

	/**
	 * Normalisiert und prüft einen Schritt. $normalizeActions validiert die Ergebnis-Knöpfe mit
	 * ProcessActionService::normalizeActions().
	 */
	public static function normalizeStep(array $input, ?callable $normalizeActions = null): array {
		$title = trim((string)($input['title'] ?? ''));
		if ($title === '') throw new \InvalidArgumentException('Jeder Schritt benötigt eine Bezeichnung.');
		if (mb_strlen($title) > 200) throw new \InvalidArgumentException('Die Bezeichnung eines Schritts darf höchstens 200 Zeichen lang sein.');
		$phase = (int)($input['phase'] ?? 1);
		if (!isset(self::PHASES[$phase])) throw new \InvalidArgumentException('Ungültige Phase für den Schritt „' . $title . '“.');
		$key = strtoupper(trim((string)($input['key'] ?? '')));
		if ($key === '') $key = self::keyFromTitle($title);
		if (!preg_match('/^[A-Z0-9_-]{1,80}$/', $key)) throw new \InvalidArgumentException('Der technische Schlüssel des Schritts „' . $title . '“ ist ungültig.');

		$trigger = is_array($input['trigger'] ?? null) ? $input['trigger'] : ['type' => (string)($input['trigger'] ?? 'CASE_CREATED')];
		$triggerType = strtoupper(trim((string)($trigger['type'] ?? 'CASE_CREATED')));
		if (!isset(self::TRIGGERS[$triggerType])) throw new \InvalidArgumentException('Unbekannter Auslöser beim Schritt „' . $title . '“.');
		$presetKeys = array_values(array_unique(array_filter(array_map(static fn(mixed $value): string => strtoupper(trim((string)$value)), (array)($trigger['presetKeys'] ?? [])))));
		foreach ($presetKeys as $presetKey) if (!preg_match('/^[A-Z0-9_-]+$/', $presetKey)) throw new \InvalidArgumentException('Ungültige Terminart beim Schritt „' . $title . '“.');
		$triggerStep = strtoupper(trim((string)($trigger['stepKey'] ?? '')));
		if (in_array($triggerType, self::SCHEDULE_TRIGGERS, true) && $presetKeys === []) throw new \InvalidArgumentException('Beim Schritt „' . $title . '“ fehlt die Terminart, auf die er reagieren soll.');
		$triggerParams = self::triggerParams($triggerType, $trigger, $title);
		if ($triggerType === 'STEP_DONE' && $triggerStep === '') throw new \InvalidArgumentException('Beim Schritt „' . $title . '“ fehlt der vorherige Schritt.');
		if ($triggerType === 'STEP_DONE' && $triggerStep === $key) throw new \InvalidArgumentException('Der Schritt „' . $title . '“ kann nicht auf sich selbst warten.');

		$reference = strtoupper(trim((string)($input['dueReference'] ?? 'TRIGGER')));
		if (!isset(self::REFERENCES[$reference])) throw new \InvalidArgumentException('Unbekanntes Bezugsdatum beim Schritt „' . $title . '“.');
		$offset = (int)($input['dueOffsetDays'] ?? 0);
		if ($offset < -60 || $offset > 730) throw new \InvalidArgumentException('Die Frist beim Schritt „' . $title . '“ muss zwischen −60 und 730 Tagen liegen.');
		$role = strtoupper(trim((string)($input['role'] ?? '')));
		if (!array_key_exists($role, self::ROLES)) throw new \InvalidArgumentException('Unbekannte Rolle beim Schritt „' . $title . '“.');
		$priority = strtoupper(trim((string)($input['priority'] ?? 'NORMAL')));
		if (!in_array($priority, self::PRIORITIES, true)) throw new \InvalidArgumentException('Unbekannte Priorität beim Schritt „' . $title . '“.');

		$applies = is_array($input['applies'] ?? null) ? $input['applies'] : [];
		$scope = strtoupper(trim((string)($applies['scope'] ?? 'ALL')));
		if (!isset(self::SCOPES[$scope])) throw new \InvalidArgumentException('Unbekannte Geltung beim Schritt „' . $title . '“.');
		$ceremony = strtoupper(trim((string)($applies['ceremony'] ?? 'ANY')));
		if (!in_array($ceremony, ['ANY', 'YES', 'NO'], true)) throw new \InvalidArgumentException('Ungültige Angabe „Trauerfeier“ beim Schritt „' . $title . '“.');
		$pension = strtoupper(trim((string)($applies['pension'] ?? 'ANY')));
		if (!in_array($pension, ['ANY', 'YES'], true)) throw new \InvalidArgumentException('Ungültige Angabe „Rente“ beim Schritt „' . $title . '“.');
		$deposit = strtoupper(trim((string)($applies['deposit'] ?? 'ANY')));
		if (!in_array($deposit, ['ANY', 'YES'], true)) throw new \InvalidArgumentException('Ungültige Angabe „Anzahlung“ beim Schritt „' . $title . '“.');
		// 0.76.0: Bedingung „nur mit Marker …“ (optional mit Wert eines Listen-Markers).
		$appliesLabel = strtoupper(trim((string)($applies['label'] ?? '')));
		$appliesLabelValue = $appliesLabel === '' ? '' : strtoupper(trim((string)($applies['labelValue'] ?? '')));
		if ($appliesLabel !== '' && !self::isLabelKey($appliesLabel)) throw new \InvalidArgumentException('Ungültiger Marker in der Bedingung beim Schritt „' . $title . '“.');
		if ($appliesLabelValue !== '' && !self::isLabelValue($appliesLabelValue)) throw new \InvalidArgumentException('Ungültiger Markerwert in der Bedingung beim Schritt „' . $title . '“.');

		$complete = is_array($input['complete'] ?? null) ? $input['complete'] : ['type' => (string)($input['complete'] ?? 'NONE')];
		$completeType = strtoupper(trim((string)($complete['type'] ?? 'NONE')));
		if (!isset(self::COMPLETIONS[$completeType])) throw new \InvalidArgumentException('Unbekannte Erledigt-Bedingung beim Schritt „' . $title . '“.');
		$completeTemplate = strtoupper(trim((string)($complete['templateKey'] ?? '')));
		if (in_array($completeType, ['DOCUMENT_CREATED', 'DOCUMENT_FINAL', 'DOCUMENT_SENT'], true) && $completeTemplate === '' && !(in_array($triggerType, self::DOCUMENT_TRIGGERS, true) && $triggerParams['templateKey'] !== '')) throw new \InvalidArgumentException('Beim Schritt „' . $title . '“ fehlt die Dokumentvorlage der Erledigt-Bedingung.');
		$completeField = strtolower(trim((string)($complete['field'] ?? '')));
		if ($completeType === 'FIELD_SET' && !preg_match('/^[a-z0-9_]{1,80}$/', $completeField)) throw new \InvalidArgumentException('Beim Schritt „' . $title . '“ fehlt das Stammdatenfeld der Erledigt-Bedingung.');
		$completeValue = trim((string)($complete['value'] ?? ''));
		if (mb_strlen($completeValue) > 200) throw new \InvalidArgumentException('Der Vergleichswert beim Schritt „' . $title . '“ ist zu lang.');
		$completeStatus = strtoupper(trim((string)($complete['status'] ?? '')));
		if ($completeType === 'CASE_STATUS' && !isset(self::CASE_STATUSES[$completeStatus])) throw new \InvalidArgumentException('Beim Schritt „' . $title . '“ fehlt der Fallstatus der Erledigt-Bedingung.');
		$completeLabel = in_array($completeType, self::LABEL_TRIGGERS, true) ? strtoupper(trim((string)($complete['label'] ?? ''))) : '';
		if (in_array($completeType, self::LABEL_TRIGGERS, true) && $completeLabel === '' && in_array($triggerType, self::LABEL_TRIGGERS, true)) $completeLabel = $triggerParams['label'];
		if (in_array($completeType, self::LABEL_TRIGGERS, true) && !self::isLabelKey($completeLabel)) throw new \InvalidArgumentException('Beim Schritt „' . $title . '“ fehlt der Marker der Erledigt-Bedingung.');
		if ($completeType === 'LABEL_SET' && $completeValue !== '' && !self::isLabelValue(strtoupper($completeValue))) throw new \InvalidArgumentException('Ungültiger Markerwert der Erledigt-Bedingung beim Schritt „' . $title . '“.');
		$completeKind = $completeType === 'DOCUMENT_RECEIVED' ? strtoupper(trim((string)($complete['documentKind'] ?? ''))) : '';
		if ($completeType === 'DOCUMENT_RECEIVED' && $completeKind === '' && $triggerType === 'DOCUMENT_RECEIVED') $completeKind = $triggerParams['documentKind'];
		if ($completeType === 'DOCUMENT_RECEIVED' && !self::isLabelKey($completeKind)) throw new \InvalidArgumentException('Beim Schritt „' . $title . '“ fehlt die Dokumentart der Erledigt-Bedingung.');
		if (in_array($completeType, ['SCHEDULE_DONE', 'SCHEDULE_CONFIRMED'], true) && !in_array($triggerType, self::SCHEDULE_TRIGGERS, true) && trim((string)($complete['presetKey'] ?? '')) === '') throw new \InvalidArgumentException('Beim Schritt „' . $title . '“ fehlt die Terminart der Erledigt-Bedingung.');

		$actions = array_values((array)($input['actions'] ?? []));
		if ($normalizeActions !== null && $actions !== []) $actions = $normalizeActions($actions);

		return [
			'key' => $key,
			'phase' => $phase,
			'title' => $title,
			'description' => trim((string)($input['description'] ?? '')),
			// once: bei Terminauslösern nur einmal je Fall (erster passender Termin) statt je Termin.
			'trigger' => ['type' => $triggerType, 'presetKeys' => in_array($triggerType, self::SCHEDULE_TRIGGERS, true) ? $presetKeys : [], 'stepKey' => $triggerType === 'STEP_DONE' ? $triggerStep : '', 'once' => in_array($triggerType, self::SCHEDULE_TRIGGERS, true) && filter_var($trigger['once'] ?? false, FILTER_VALIDATE_BOOLEAN)] + $triggerParams,
			'dueOffsetDays' => $offset,
			'dueReference' => $reference,
			'role' => $role,
			'priority' => $priority,
			'required' => filter_var($input['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
			'applies' => ['scope' => $scope, 'ceremony' => $ceremony, 'pension' => $pension, 'deposit' => $deposit, 'label' => $appliesLabel, 'labelValue' => $appliesLabelValue],
			'complete' => ['type' => $completeType, 'templateKey' => $completeTemplate, 'presetKey' => strtoupper(trim((string)($complete['presetKey'] ?? ''))), 'field' => $completeType === 'FIELD_SET' ? $completeField : '', 'value' => $completeType === 'FIELD_SET' ? $completeValue : ($completeType === 'LABEL_SET' ? strtoupper($completeValue) : ''), 'status' => $completeType === 'CASE_STATUS' ? $completeStatus : '', 'label' => $completeLabel, 'documentKind' => $completeKind],
			'actions' => $actions,
			'active' => filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN),
		];
	}

	/**
	 * Zusatzangaben der Auslöser (0.68.0). Nicht benötigte Angaben werden leer gespeichert, damit
	 * ein Wechsel des Auslösers keine unsichtbaren Altwerte hinterlässt.
	 * @return array{templateKey:string,field:string,value:string,status:string,dateRef:string,days:int,leadDays:int,label:string,documentKind:string}
	 */
	public static function triggerParams(string $type, array $trigger, string $title = ''): array {
		$params = ['templateKey' => '', 'field' => '', 'value' => '', 'status' => '', 'dateRef' => '', 'days' => 0, 'leadDays' => 0, 'label' => '', 'documentKind' => ''];
		if (in_array($type, self::RECEIVED_TRIGGERS, true)) {
			$params['documentKind'] = strtoupper(trim((string)($trigger['documentKind'] ?? '')));
			if (!self::isLabelKey($params['documentKind'])) throw new \InvalidArgumentException('Beim Schritt „' . $title . '“ fehlt die Dokumentart, auf die er reagieren soll.');
		}
		if (in_array($type, self::LABEL_TRIGGERS, true)) {
			$params['label'] = strtoupper(trim((string)($trigger['label'] ?? '')));
			if (!self::isLabelKey($params['label'])) throw new \InvalidArgumentException('Beim Schritt „' . $title . '“ fehlt der Marker, auf den er reagieren soll.');
			if ($type === 'LABEL_SET') {
				$params['value'] = strtoupper(trim((string)($trigger['value'] ?? '')));
				if ($params['value'] !== '' && !self::isLabelValue($params['value'])) throw new \InvalidArgumentException('Ungültiger Markerwert beim Schritt „' . $title . '“.');
			}
		}
		if (in_array($type, self::DOCUMENT_TRIGGERS, true) || in_array($type, self::DEREGISTRATION_TRIGGERS, true)) {
			$params['templateKey'] = strtoupper(trim((string)($trigger['templateKey'] ?? '')));
			if ($params['templateKey'] !== '' && !preg_match('/^[A-Z0-9_-]{1,100}$/', $params['templateKey'])) throw new \InvalidArgumentException('Ungültige Vorlage beim Schritt „' . $title . '“.');
			if (in_array($type, self::DOCUMENT_TRIGGERS, true) && $params['templateKey'] === '') throw new \InvalidArgumentException('Beim Schritt „' . $title . '“ fehlt die Dokumentvorlage, auf die er reagieren soll.');
		}
		if ($type === 'FIELD_SET') {
			$params['field'] = strtolower(trim((string)($trigger['field'] ?? '')));
			if (!preg_match('/^[a-z0-9_]{1,80}$/', $params['field'])) throw new \InvalidArgumentException('Beim Schritt „' . $title . '“ fehlt das Stammdatenfeld, auf das er reagieren soll.');
			$params['value'] = trim((string)($trigger['value'] ?? ''));
			if (mb_strlen($params['value']) > 200) throw new \InvalidArgumentException('Der Vergleichswert beim Schritt „' . $title . '“ ist zu lang.');
		}
		if ($type === 'CASE_STATUS') {
			$params['status'] = strtoupper(trim((string)($trigger['status'] ?? '')));
			if (!isset(self::CASE_STATUSES[$params['status']])) throw new \InvalidArgumentException('Beim Schritt „' . $title . '“ fehlt der Fallstatus, auf den er reagieren soll.');
		}
		if ($type === 'DATE_REACHED') {
			$params['dateRef'] = strtoupper(trim((string)($trigger['dateRef'] ?? '')));
			if (!isset(self::DATE_REFERENCES[$params['dateRef']])) throw new \InvalidArgumentException('Beim Schritt „' . $title . '“ fehlt das Bezugsdatum für „Datum erreicht“.');
			$params['days'] = (int)($trigger['days'] ?? 0);
			if ($params['days'] < -365 || $params['days'] > 3650) throw new \InvalidArgumentException('Beim Schritt „' . $title . '“ muss „Datum erreicht“ zwischen −365 und 3650 Tagen liegen.');
			$params['leadDays'] = (int)($trigger['leadDays'] ?? 0);
			if ($params['leadDays'] < 0 || $params['leadDays'] > 365) throw new \InvalidArgumentException('Der Vorlauf beim Schritt „' . $title . '“ muss zwischen 0 und 365 Tagen liegen.');
		}
		if ($type === 'INVOICE_OVERDUE') {
			$params['days'] = (int)($trigger['days'] ?? 1);
			if ($params['days'] < 0 || $params['days'] > 365) throw new \InvalidArgumentException('Beim Schritt „' . $title . '“ müssen die Tage nach dem Zahlungsziel zwischen 0 und 365 liegen.');
		}
		return $params;
	}

	/**
	 * „Datum erreicht“: Stichtag = Bezugsdatum + Tage; der Schritt entsteht „Vorlauf“ Tage vorher.
	 * @return array{0:?string,1:bool} [Stichtag Y-m-d oder null (Bezugsdatum fehlt), entsteht heute?]
	 */
	public static function dateReached(?string $referenceDate, int $days, int $leadDays, string $today): array {
		if ($referenceDate === null || trim($referenceDate) === '') return [null, false];
		try {
			$zone = new \DateTimeZone('Europe/Berlin');
			$target = (new \DateTimeImmutable(substr(trim($referenceDate), 0, 10), $zone))->modify(sprintf('%+d days', $days));
			$create = $target->modify(sprintf('-%d days', max(0, $leadDays)));
			return [$target->format('Y-m-d'), $create->format('Y-m-d') <= substr($today, 0, 10)];
		} catch (\Throwable) {
			return [null, false];
		}
	}

	/**
	 * „Rechnung überfällig“: Zahlungsziel = Rechnungsdatum + Zahlungsfrist; fällig ab Zahlungsziel + Tage.
	 * @return array{0:string,1:bool} [Stichtag Y-m-d, überfällig?]
	 */
	public static function invoiceOverdue(string $invoiceDate, int $paymentTermDays, int $days, string $today): array {
		$zone = new \DateTimeZone('Europe/Berlin');
		try { $base = new \DateTimeImmutable(substr(trim($invoiceDate), 0, 10) ?: 'today', $zone); } catch (\Throwable) { $base = new \DateTimeImmutable('today', $zone); }
		$target = $base->modify(sprintf('%+d days', max(0, $paymentTermDays) + max(0, $days)));
		return [$target->format('Y-m-d'), $target->format('Y-m-d') <= substr($today, 0, 10)];
	}

	/** Gesetzter Marker (und ggf. Wert) vorhanden? $labels: [KEY => ['value' => WERT, ...]] */
	public static function labelMatches(array $labels, string $key, string $value = ''): bool {
		$key = strtoupper(trim($key));
		if ($key === '' || !isset($labels[$key])) return false;
		return trim($value) === '' || strtoupper(trim($value)) === strtoupper((string)($labels[$key]['value'] ?? ''));
	}

	public static function isLabelKey(string $key): bool { return preg_match('/^[A-Z][A-Z0-9_]{1,59}$/', $key) === 1; }
	public static function isLabelValue(string $value): bool { return preg_match('/^[A-Z][A-Z0-9_]{0,59}$/', $value) === 1; }

	/** Vergleich „Stammdatenfeld ausgefüllt (mit Wert)“: leerer Sollwert = jeder nicht leere Wert. */
	public static function fieldMatches(mixed $actual, string $expected): bool {
		if (is_array($actual)) $actual = implode(', ', array_map('strval', $actual));
		$actual = trim((string)$actual);
		if ($actual === '') return false;
		if (trim($expected) === '') return true;
		return mb_strtolower($actual) === mb_strtolower(trim($expected));
	}

	public static function keyFromTitle(string $title): string {
		$map = ['ä' => 'AE', 'ö' => 'OE', 'ü' => 'UE', 'Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE', 'ß' => 'SS'];
		$key = strtoupper(strtr($title, $map));
		$key = preg_replace('/[^A-Z0-9]+/', '_', $key) ?? 'SCHRITT';
		$key = trim($key, '_');
		return substr($key !== '' ? $key : 'SCHRITT', 0, 60);
	}

	/**
	 * Prüft, ob ein Schritt für den Fall gilt.
	 *
	 * @param array $facts ['scope' => 'CREMATION'|'BURIAL'|'' , 'nature' => bool, 'grave' => bool,
	 *                      'ceremony' => '1'|'0'|'', 'pension' => '1'|'0'|'', 'depositCents' => int]
	 * @return array{0:string,1:string} [YES|NO|PENDING, Begründung für PENDING/NO]
	 */
	public static function evaluateApplies(array $applies, array $facts): array {
		$scope = strtoupper((string)($applies['scope'] ?? 'ALL'));
		$caseScope = strtoupper((string)($facts['scope'] ?? ''));
		if ($scope !== 'ALL') {
			if ($caseScope === '') return ['PENDING', 'wartet auf die Bestattungsart'];
			$matches = match ($scope) {
				'CREMATION' => $caseScope === 'CREMATION',
				'BURIAL' => $caseScope === 'BURIAL',
				'NATURE' => (bool)($facts['nature'] ?? false),
				'GRAVE' => (bool)($facts['grave'] ?? false),
				default => false,
			};
			if (!$matches) return ['NO', 'gilt nicht für diese Bestattungsart'];
		}
		$ceremony = strtoupper((string)($applies['ceremony'] ?? 'ANY'));
		$caseCeremony = (string)($facts['ceremony'] ?? '');
		if ($ceremony !== 'ANY') {
			if ($caseCeremony === '') return ['PENDING', 'wartet auf die Angabe „Trauerfeier ja/nein“'];
			if (($ceremony === 'YES') !== ($caseCeremony === '1')) return ['NO', $ceremony === 'YES' ? 'nur mit Trauerfeier' : 'nur ohne Trauerfeier'];
		}
		if (strtoupper((string)($applies['pension'] ?? 'ANY')) === 'YES') {
			$casePension = (string)($facts['pension'] ?? '');
			if ($casePension === '') return ['PENDING', 'wartet auf die Angabe „Rente ja/nein“'];
			if ($casePension !== '1') return ['NO', 'nur bei Rentenbezug'];
		}
		if (strtoupper((string)($applies['deposit'] ?? 'ANY')) === 'YES' && (int)($facts['depositCents'] ?? 0) <= 0) return ['NO', 'nur bei vereinbarter Anzahlung'];
		$label = strtoupper((string)($applies['label'] ?? ''));
		if ($label !== '' && !self::labelMatches((array)($facts['labels'] ?? []), $label, (string)($applies['labelValue'] ?? ''))) return ['NO', 'nur mit Marker „' . (string)($facts['labelNames'][$label] ?? $label) . '“'];
		return ['YES', ''];
	}

	/**
	 * Fälligkeit aus Bezugsdatum und Versatz; nie in der Vergangenheit (Befund 7 der Analyse
	 * vom 2026-09-24). Liefert [Y-m-d\T09:00, kurzfristig?].
	 */
	public static function dueDate(?string $anchor, int $offsetDays, string $today): array {
		$zone = new \DateTimeZone('Europe/Berlin');
		$todayDate = new \DateTimeImmutable(substr($today, 0, 10), $zone);
		$base = $todayDate;
		if ($anchor !== null && trim($anchor) !== '') {
			try { $base = new \DateTimeImmutable(substr(trim($anchor), 0, 10), $zone); } catch (\Throwable) { $base = $todayDate; }
		}
		$due = $base->modify(sprintf('%+d days', $offsetDays));
		$shortNotice = false;
		if ($due < $todayDate) { $due = $todayDate; $shortNotice = true; }
		return [$due->format('Y-m-d') . 'T09:00', $shortNotice];
	}

	/** Bundesweite gesetzliche Feiertage in Deutschland (landesspezifische Feiertage bewusst nicht). */
	public static function isNationalHoliday(\DateTimeImmutable $date): bool {
		$year = (int)$date->format('Y');
		$fixed = ['01-01', '05-01', '10-03', '12-25', '12-26'];
		if (in_array($date->format('m-d'), $fixed, true)) return true;
		$easter = self::easterSunday($year);
		foreach ([-2, 1, 39, 50] as $offset) {
			if ($easter->modify(sprintf('%+d days', $offset))->format('Y-m-d') === $date->format('Y-m-d')) return true;
		}
		return false;
	}

	public static function easterSunday(int $year): \DateTimeImmutable {
		// Gaußsche Osterformel (gregorianisch), unabhängig von der ext-calendar-Erweiterung.
		$a = $year % 19; $b = intdiv($year, 100); $c = $year % 100; $d = intdiv($b, 4); $e = $b % 4;
		$f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
		$i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
		$month = intdiv($h + $l - 7 * $m + 114, 31); $day = (($h + $l - 7 * $m + 114) % 31) + 1;
		return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), new \DateTimeZone('Europe/Berlin'));
	}

	/** Einordnung der Abholzeit für die Zuschlagsstaffeln: HOLIDAY > WEEKEND > NIGHT > DAY. */
	public static function pickupClass(string $pickupTime, int $nightFrom = 20, int $nightTo = 6): string {
		$pickupTime = trim($pickupTime);
		if ($pickupTime === '') return '';
		try { $time = new \DateTimeImmutable($pickupTime, new \DateTimeZone('Europe/Berlin')); } catch (\Throwable) { return ''; }
		if (self::isNationalHoliday($time)) return 'HOLIDAY';
		if ((int)$time->format('N') >= 6) return 'WEEKEND';
		$hour = (int)$time->format('G');
		if ($hour >= $nightFrom || $hour < $nightTo) return 'NIGHT';
		return 'DAY';
	}

	/**
	 * Passende Zuschlagsstaffeln für die im Fall erfassten Werte.
	 * @param array $rules  SurchargeRuleService::all()
	 * @param array $values ['pickupTime'=>string,'heightCm'=>?int,'weightKg'=>?int]
	 */
	public static function matchingSurcharges(array $rules, array $values): array {
		$pickupClass = self::pickupClass((string)($values['pickupTime'] ?? ''));
		$result = [];
		foreach ($rules as $rule) {
			if (!($rule['active'] ?? false)) continue;
			$dimension = strtoupper((string)($rule['dimension'] ?? ''));
			$matches = false;
			if ($dimension === 'PICKUP') {
				$key = strtoupper((string)($rule['ruleKey'] ?? ''));
				$matches = $pickupClass !== '' && $key === 'PICKUP_' . $pickupClass;
			} elseif (in_array($dimension, ['HEIGHT_CM', 'WEIGHT_KG'], true)) {
				$value = $dimension === 'HEIGHT_CM' ? ($values['heightCm'] ?? null) : ($values['weightKg'] ?? null);
				if ($value !== null && $value !== '') {
					$value = (int)$value;
					$from = $rule['thresholdFrom'] ?? null; $to = $rule['thresholdTo'] ?? null;
					$matches = ($from === null || $value >= (int)$from) && ($to === null || $value <= (int)$to);
				}
			}
			if ($matches) $result[] = $rule;
		}
		return $result;
	}

	/** Bestattungsumfang aus der Variantenhierarchie (BURIAL_VARIANT) bzw. dem Freitext. */
	public static function funeralFacts(string $variantCode, string $funeralTypeLabel, array $variants): array {
		$variantCode = strtoupper(trim($variantCode));
		$scope = ''; $root = '';
		if ($variantCode !== '') {
			$byValue = []; $byId = [];
			foreach ($variants as $variant) { $byValue[strtoupper((string)$variant['value'])] = $variant; $byId[(int)$variant['id']] = $variant; }
			$chosen = $byValue[$variantCode] ?? null;
			if ($chosen !== null) {
				$scope = strtoupper((string)($chosen['metadata']['funeralScope'] ?? ''));
				$node = $chosen; $guard = 0;
				while (($node['parentItemId'] ?? null) !== null && isset($byId[(int)$node['parentItemId']]) && $guard++ < 20) $node = $byId[(int)$node['parentItemId']];
				$root = strtoupper((string)$node['value']);
				if ($scope === '') $scope = strtoupper((string)($node['metadata']['funeralScope'] ?? ''));
			}
		}
		if ($scope === '' && trim($funeralTypeLabel) !== '') {
			$label = mb_strtolower($funeralTypeLabel);
			if (str_contains($label, 'erd')) $scope = 'BURIAL';
			elseif (str_contains($label, 'feuer') || str_contains($label, 'urne') || str_contains($label, 'see') || str_contains($label, 'wald') || str_contains($label, 'baum')) $scope = 'CREMATION';
			if (str_contains($label, 'see')) $root = 'SEA'; elseif (str_contains($label, 'baum')) $root = 'TREE'; elseif (str_contains($label, 'wald')) $root = 'FOREST';
		}
		if (!in_array($scope, ['CREMATION', 'BURIAL'], true)) $scope = '';
		$anonymous = str_contains($variantCode, 'ANONYM');
		return [
			'scope' => $scope,
			'root' => $root,
			'nature' => in_array($root, self::NATURE_ROOTS, true),
			'grave' => $scope === 'BURIAL' || in_array($variantCode, self::GRAVE_VARIANTS, true),
			'anonymous' => $anonymous,
		];
	}

	/**
	 * Entdeckt Zyklen in STEP_DONE-Verkettungen und Verweise auf unbekannte Schritte.
	 * @return list<string> Klartext-Warnungen
	 */
	public static function chainWarnings(array $steps): array {
		$byKey = [];
		foreach ($steps as $step) $byKey[(string)$step['key']] = $step;
		$warnings = [];
		foreach ($steps as $step) {
			if (($step['trigger']['type'] ?? '') !== 'STEP_DONE') continue;
			$target = (string)($step['trigger']['stepKey'] ?? '');
			if (!isset($byKey[$target])) { $warnings[] = 'Der Schritt „' . $step['title'] . '“ wartet auf einen Schritt, den es in dieser Vorlage nicht gibt.'; continue; }
			if (!($byKey[$target]['active'] ?? true) && ($step['active'] ?? true)) $warnings[] = 'Der Schritt „' . $step['title'] . '“ wartet auf den deaktivierten Schritt „' . $byKey[$target]['title'] . '“ und entsteht daher nie.';
			$seen = [(string)$step['key'] => true]; $cursor = $target; $guard = 0;
			while (isset($byKey[$cursor]) && ($byKey[$cursor]['trigger']['type'] ?? '') === 'STEP_DONE' && $guard++ < 100) {
				if (isset($seen[$cursor])) { $warnings[] = 'Die Schritte rund um „' . $step['title'] . '“ warten gegenseitig aufeinander (Kreislauf).'; break; }
				$seen[$cursor] = true; $cursor = (string)($byKey[$cursor]['trigger']['stepKey'] ?? '');
			}
		}
		return array_values(array_unique($warnings));
	}

	public const CSV_COLUMNS = ['Phase', 'Nr', 'Schritt', 'Beschreibung', 'Gilt für', 'Trauerfeier', 'Rente', 'Anzahlung', 'Marker', 'Startet, wenn', 'Terminart(en)', 'Nach Schritt', 'Auslöser-Detail', 'Auslöser-Tage', 'Vorlauf (Tage)', 'Frist (Tage)', 'bezogen auf', 'Zuständig (Rolle)', 'Priorität', 'Pflicht', 'Erledigt, wenn', 'Erledigt-Detail', 'Nur einmal je Fall', 'Aktiv', 'Schlüssel'];

	/** Excel-kompatibler CSV-Export (Semikolon, UTF-8 mit BOM, Klartextwerte). */
	public static function toCsv(array $steps): string {
		$lines = [self::csvLine(self::CSV_COLUMNS)];
		$counter = [];
		foreach ($steps as $step) {
			$phase = (int)$step['phase']; $counter[$phase] = ($counter[$phase] ?? 0) + 1;
			$yesNoAny = static fn(string $value): string => $value === 'YES' ? 'ja' : ($value === 'NO' ? 'nein' : 'egal');
			$lines[] = self::csvLine([
				$phase . ' ' . self::PHASES[$phase], $phase . '.' . $counter[$phase], $step['title'], $step['description'] ?? '',
				self::SCOPES[$step['applies']['scope'] ?? 'ALL'] ?? 'alle Fälle', $yesNoAny((string)($step['applies']['ceremony'] ?? 'ANY')),
				$yesNoAny((string)($step['applies']['pension'] ?? 'ANY')), $yesNoAny((string)($step['applies']['deposit'] ?? 'ANY')),
				(string)($step['applies']['label'] ?? '') . (($step['applies']['labelValue'] ?? '') !== '' ? '=' . $step['applies']['labelValue'] : ''),
				self::TRIGGERS[$step['trigger']['type'] ?? 'CASE_CREATED'] ?? '', implode(', ', (array)($step['trigger']['presetKeys'] ?? [])),
				(string)($step['trigger']['stepKey'] ?? ''), self::triggerDetail($step['trigger'] ?? []),
				in_array($step['trigger']['type'] ?? '', self::TIME_TRIGGERS, true) ? (string)(int)($step['trigger']['days'] ?? 0) : '',
				($step['trigger']['type'] ?? '') === 'DATE_REACHED' ? (string)(int)($step['trigger']['leadDays'] ?? 0) : '',
				(string)(int)($step['dueOffsetDays'] ?? 0), self::REFERENCES[$step['dueReference'] ?? 'TRIGGER'] ?? '',
				self::ROLES[$step['role'] ?? ''] ?? '', self::PRIORITY_LABELS[$step['priority'] ?? 'NORMAL'] ?? 'normal', ($step['required'] ?? false) ? 'ja' : 'nein',
				self::COMPLETIONS[$step['complete']['type'] ?? 'NONE'] ?? '', self::completeDetail($step['complete'] ?? []),
				!empty($step['trigger']['once']) ? 'ja' : 'nein', ($step['active'] ?? true) ? 'ja' : 'nein', (string)$step['key'],
			]);
		}
		return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
	}

	/**
	 * Liest eine CSV-Datei (Semikolon oder Komma, Klartext- oder technische Werte). Unbekannte
	 * Werte führen zu einer Fehlermeldung mit Zeilennummer statt zu stillen Annahmen.
	 * Ergebnis: Schritte ohne Ergebnis-Knöpfe (diese bleiben beim Import erhalten, siehe ProcessService).
	 */
	public static function fromCsv(string $content): array {
		$content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
		$content = str_replace(["\r\n", "\r"], "\n", $content);
		$firstLine = strtok($content, "\n") ?: '';
		$delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
		$rows = [];
		$handle = fopen('php://temp', 'r+');
		if ($handle === false) throw new \RuntimeException('Die CSV-Datei konnte nicht gelesen werden.');
		fwrite($handle, $content); rewind($handle);
		while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) { if ($row !== [null]) $rows[] = $row; }
		fclose($handle);
		if (count($rows) < 2) throw new \InvalidArgumentException('Die CSV-Datei enthält keine Schritte.');
		$header = array_map(static fn(mixed $value): string => mb_strtolower(trim((string)$value)), array_shift($rows));
		$index = static function (string $name) use ($header): ?int { $position = array_search(mb_strtolower($name), $header, true); return $position === false ? null : (int)$position; };
		if ($index('Schritt') === null) throw new \InvalidArgumentException('Die CSV-Datei benötigt mindestens die Spalte „Schritt“.');
		$lookup = static function (array $map, string $value, string $label, int $line): string {
			$value = trim($value);
			if ($value === '') return '';
			foreach ($map as $key => $text) if (mb_strtolower((string)$key) === mb_strtolower($value) || mb_strtolower((string)$text) === mb_strtolower($value)) return (string)$key;
			throw new \InvalidArgumentException(sprintf('Zeile %d: Unbekannter Wert „%s“ in der Spalte „%s“.', $line, $value, $label));
		};
		$yesNo = static fn(string $value): string => in_array(mb_strtolower(trim($value)), ['ja', 'yes', 'x', '1', 'true'], true) ? 'YES' : (in_array(mb_strtolower(trim($value)), ['nein', 'no', '0', 'false'], true) ? 'NO' : 'ANY');
		$steps = [];
		foreach ($rows as $number => $row) {
			$line = $number + 2;
			// Beim Export gegen Formel-Injektion vorangestelltes Hochkomma wieder entfernen.
			$cell = static fn(string $name) => ($position = $index($name)) === null ? '' : (string)preg_replace("/^'(?=[=+\\-@])/", '', trim((string)($row[$position] ?? '')));
			if ($cell('Schritt') === '') continue;
			$phaseRaw = $cell('Phase');
			$phase = (int)preg_replace('/\D.*$/', '', $phaseRaw);
			if ($phase === 0) foreach (self::PHASES as $candidate => $label) if ($phaseRaw !== '' && str_contains(mb_strtolower($label), mb_strtolower($phaseRaw))) $phase = $candidate;
			if (!isset(self::PHASES[$phase])) throw new \InvalidArgumentException(sprintf('Zeile %d: Die Phase „%s“ ist unbekannt (erlaubt: 1 bis 7).', $line, $phaseRaw));
			$priority = $cell('Priorität');
			$steps[] = [
				'key' => $cell('Schlüssel'), 'phase' => $phase, 'title' => $cell('Schritt'), 'description' => $cell('Beschreibung'),
				'applies' => [
					'scope' => $lookup(self::SCOPES, $cell('Gilt für'), 'Gilt für', $line) ?: 'ALL',
					'ceremony' => $yesNo($cell('Trauerfeier')), 'pension' => $yesNo($cell('Rente')) === 'YES' ? 'YES' : 'ANY', 'deposit' => $yesNo($cell('Anzahlung')) === 'YES' ? 'YES' : 'ANY',
				] + (static function (string $label): array { [$key, $value] = array_pad(explode('=', $label, 2), 2, ''); return ['label' => strtoupper(trim($key)), 'labelValue' => strtoupper(trim($value))]; })($cell('Marker') !== '' ? $cell('Marker') : $cell('Etikett')), // „Etikett“: Spaltenname bis 0.77.0
				'trigger' => (static function (string $type, array $base, string $detail, string $days, string $lead): array {
					return $base + self::parseTriggerDetail($type, $detail) + ($days !== '' ? ['days' => (int)$days] : []) + ($lead !== '' ? ['leadDays' => (int)$lead] : []);
				})($triggerType = ($lookup(self::TRIGGERS, $cell('Startet, wenn'), 'Startet, wenn', $line) ?: 'CASE_CREATED'), ['type' => $triggerType, 'presetKeys' => array_filter(array_map('trim', explode(',', $cell('Terminart(en)')))), 'stepKey' => $cell('Nach Schritt'), 'once' => $index('Nur einmal je Fall') === null ? null : $yesNo($cell('Nur einmal je Fall')) === 'YES'], $cell('Auslöser-Detail'), $cell('Auslöser-Tage'), $cell('Vorlauf (Tage)')),
				'dueOffsetDays' => (int)$cell('Frist (Tage)'),
				'dueReference' => $lookup(self::REFERENCES, $cell('bezogen auf'), 'bezogen auf', $line) ?: 'TRIGGER',
				'role' => $lookup(self::ROLES, $cell('Zuständig (Rolle)'), 'Zuständig (Rolle)', $line),
				'priority' => $priority === '' ? 'NORMAL' : $lookup(array_combine(self::PRIORITIES, array_values(self::PRIORITY_LABELS)), $priority, 'Priorität', $line),
				'required' => $yesNo($cell('Pflicht')) === 'YES',
				'complete' => self::parseCompleteDetail($lookup(self::COMPLETIONS, $cell('Erledigt, wenn'), 'Erledigt, wenn', $line) ?: 'NONE', $cell('Erledigt-Detail')),
				'active' => $cell('Aktiv') === '' ? true : $yesNo($cell('Aktiv')) !== 'NO',
				'line' => $line,
			];
		}
		return $steps;
	}

	/** Klartext der Auslöser-Zusatzangabe für die CSV: Vorlage, Feld=Wert, Status bzw. Bezugsdatum. */
	public static function triggerDetail(array $trigger): string {
		$type = (string)($trigger['type'] ?? '');
		if (in_array($type, self::DOCUMENT_TRIGGERS, true) || in_array($type, self::DEREGISTRATION_TRIGGERS, true)) return (string)($trigger['templateKey'] ?? '');
		if ($type === 'FIELD_SET') return (string)($trigger['field'] ?? '') . (($trigger['value'] ?? '') !== '' ? '=' . $trigger['value'] : '');
		if ($type === 'CASE_STATUS') return (string)($trigger['status'] ?? '');
		if ($type === 'DATE_REACHED') return (string)($trigger['dateRef'] ?? '');
		if (in_array($type, self::LABEL_TRIGGERS, true)) return (string)($trigger['label'] ?? '') . (($trigger['value'] ?? '') !== '' ? '=' . $trigger['value'] : '');
		if (in_array($type, self::RECEIVED_TRIGGERS, true)) return (string)($trigger['documentKind'] ?? '');
		return '';
	}

	public static function parseTriggerDetail(string $type, string $detail): array {
		$detail = trim($detail);
		if (in_array($type, self::DOCUMENT_TRIGGERS, true) || in_array($type, self::DEREGISTRATION_TRIGGERS, true)) return ['templateKey' => strtoupper($detail)];
		if ($type === 'FIELD_SET') { [$field, $value] = array_pad(explode('=', $detail, 2), 2, ''); return ['field' => strtolower(trim($field)), 'value' => trim($value)]; }
		if ($type === 'CASE_STATUS') {
			foreach (self::CASE_STATUSES as $key => $label) if (mb_strtolower($label) === mb_strtolower($detail)) return ['status' => $key];
			return ['status' => strtoupper($detail)];
		}
		if ($type === 'DATE_REACHED') {
			foreach (self::DATE_REFERENCES as $key => $label) if (mb_strtolower($label) === mb_strtolower($detail)) return ['dateRef' => $key];
			return ['dateRef' => strtoupper($detail)];
		}
		if (in_array($type, self::LABEL_TRIGGERS, true)) { [$label, $value] = array_pad(explode('=', $detail, 2), 2, ''); return ['label' => strtoupper(trim($label)), 'value' => strtoupper(trim($value))]; }
		if (in_array($type, self::RECEIVED_TRIGGERS, true)) return ['documentKind' => strtoupper($detail)];
		return [];
	}

	public static function completeDetail(array $complete): string {
		$type = (string)($complete['type'] ?? 'NONE');
		if ($type === 'FIELD_SET') return (string)($complete['field'] ?? '') . (($complete['value'] ?? '') !== '' ? '=' . $complete['value'] : '');
		if ($type === 'CASE_STATUS') return (string)($complete['status'] ?? '');
		if (in_array($type, self::LABEL_TRIGGERS, true)) return (string)($complete['label'] ?? '') . (($complete['value'] ?? '') !== '' ? '=' . $complete['value'] : '');
		if ($type === 'DOCUMENT_RECEIVED') return (string)($complete['documentKind'] ?? '');
		return (string)(($complete['templateKey'] ?? '') ?: ($complete['presetKey'] ?? ''));
	}

	public static function parseCompleteDetail(string $type, string $detail): array {
		$detail = trim($detail);
		$result = ['type' => $type, 'templateKey' => '', 'presetKey' => '', 'field' => '', 'value' => '', 'status' => '', 'label' => '', 'documentKind' => ''];
		if ($type === 'DOCUMENT_RECEIVED') { $result['documentKind'] = strtoupper($detail); return $result; }
		if (in_array($type, ['DOCUMENT_CREATED', 'DOCUMENT_FINAL', 'DOCUMENT_SENT', 'DEREGISTRATION_SENT', 'DEREGISTRATION_CONFIRMED'], true)) $result['templateKey'] = strtoupper($detail);
		elseif (in_array($type, ['SCHEDULE_DONE', 'SCHEDULE_CONFIRMED'], true)) $result['presetKey'] = strtoupper($detail);
		elseif ($type === 'FIELD_SET') { [$field, $value] = array_pad(explode('=', $detail, 2), 2, ''); $result['field'] = strtolower(trim($field)); $result['value'] = trim($value); }
		elseif ($type === 'CASE_STATUS') $result['status'] = strtoupper($detail);
		elseif (in_array($type, self::LABEL_TRIGGERS, true)) { [$label, $value] = array_pad(explode('=', $detail, 2), 2, ''); $result['label'] = strtoupper(trim($label)); $result['value'] = strtoupper(trim($value)); }
		return $result;
	}

	private static function csvLine(array $values): string {
		return implode(';', array_map(static function (mixed $value): string {
			$value = (string)$value;
			// Schutz vor Formel-Injektion beim Öffnen in Tabellenkalkulationen.
			if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) && !preg_match('/^-?\d+$/', $value)) $value = "'" . $value;
			return '"' . str_replace('"', '""', $value) . '"';
		}, $values));
	}
}
