<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * 0.89.4: Bereitet die Einträge des Audit-Protokolls für den Fall-Verlauf auf.
 *
 * - Technische Angaben (Abgleich mit Nextcloud Aufgaben/Kalender, Rücksprung-Links, Ablaufkennungen, Dateikennungen)
 *   stehen getrennt in technicalChanges. Einträge, die nur daraus bestehen, sind als technical gekennzeichnet und
 *   erscheinen im Fall-Verlauf erst über „Technische Einträge anzeigen“.
 * - Feldbezeichnungen und Werte im Klartext (Auftrag, Rechnungsempfänger, Unterschrift, Statuswerte, Wertelisten).
 * - Leistungsauswahl, Leistungserfassung und Vertragsänderung zeigen, welche Leistungen betroffen waren.
 * - Für Vorgänge ohne Feldvergleich (Rechnung, Unterschrift, E-Mail, Ablauf) eine kurze Angabe zum Vorgang.
 *
 * Reine Aufbereitung für die Anzeige, ohne Datenbankzugriff: Das Audit-Protokoll selbst bleibt unverändert.
 */
class CaseHistoryFormatter {
	/** Technische Angaben bei allen Objekttypen. */
	public const TECHNICAL_KEYS = [
		'nextcloud', 'workflowUrl', 'workflow', 'workflowSourceRecordId', 'sourceScheduleId', 'sourceScheduleSnapshot', 'conflictOverride',
		'bufferBeforeMinutes', 'bufferAfterMinutes', 'assigneeRole', 'roleUnresolved', 'processStepId', 'processStepKey', 'processTemplateKey',
		'processPhase', 'processRequired', 'processRunId', 'processContext', 'deregistrationId', 'fileId', 'createdAt', 'pdf', 'path',
		'templateSource', 'eInvoice', 'payment', 'paymentQr', 'commercialDocumentId', 'commercialDocumentType', 'validationWarnings',
		'order_mode_switch', 'order_mode_choice', 'burial_variant_label', 'burial_deferred_rule_ids', 'order_folder',
		'payment_snapshot', 'invoice_qr_payload', 'kva_converted_order_id', 'order_id', 'invoice_id', 'side_order_id', 'order_choice_fields',
	];
	/** Zusätzlich technisch je Objekttyp (bei Aufgaben z. B. Ablaufvorgaben, die der Abgleich mitschreibt). */
	public const TECHNICAL_KEYS_BY_TYPE = [
		'TASK' => ['scheduleKind', 'durationMinutes', 'checklist', 'dueOffsetDays', 'shortNotice'],
		'DOCUMENT' => ['date', 'packageId'],
	];
	/** Vorgänge ohne Änderung am Fall (Abfragen des Assistenten). */
	public const TECHNICAL_ACTIONS = ['ASSISTANT/INTENT_PREVIEWED', 'ASSISTANT/INFORMATION_REQUESTED'];

	public const OBJECTS = [
		'CASE' => 'Fall', 'TASK' => 'Aufgabe', 'SCHEDULE' => 'Termin', 'DOCUMENT' => 'Dokument', 'CONTACT' => 'Kontakt', 'CASE_CONTACT' => 'Kontakt',
		'SERVICE' => 'Leistung', 'QUOTE' => 'Kostenvoranschlag', 'ORDER' => 'Auftrag', 'CONTRACT' => 'Auftrag', 'INVOICE' => 'Rechnung',
		'INCOMING_INVOICE' => 'Eingangsrechnung', 'DEREGISTRATION' => 'Abmeldung', 'DEREGISTRATION_MAIL' => 'Abmeldung per E-Mail',
		'MAIL_DISPATCH' => 'E-Mail', 'SIDE_ORDER' => 'Nebenauftrag', 'WORKFLOW' => 'Workflow', 'PROCESS' => 'Ablauf', 'PROCESS_ACTION' => 'Ablaufaktion',
		'ASSISTANT' => 'Assistent', 'RETENTION' => 'Aufbewahrung', 'CASE_EXPORT' => 'Fallakte', 'CAPTURE_IMPORT' => 'Schnellerfassung',
		'ARTICLE' => 'Artikel', 'ARTICLE_GROUP' => 'Warengruppe', 'ARTICLE_AREA' => 'Katalogbereich', 'ARTICLE_CATALOG' => 'Leistungskatalog',
		'ORDER_FORM' => 'Auftragsformular', 'PRICE_LIST' => 'Preisliste', 'PRICE_LIST_ITEM' => 'Preis', 'LABEL' => 'Marker', 'LABEL_DEFINITION' => 'Marker (Customizing)',
		'NOTE' => 'Notiz',
	];
	public const ACTIONS = [
		'CREATED' => 'angelegt', 'UPDATED' => 'geändert', 'DELETED' => 'gelöscht', 'VERSION_CREATED' => 'festgeschrieben',
		'QUOTE_CONVERTED' => 'in Auftrag überführt', 'SELECTION_UPDATED' => 'Auswahl geändert', 'LIFECYCLE_UPDATED' => 'erfasst',
		'EXECUTED' => 'ausgeführt', 'FAILED' => 'fehlgeschlagen', 'INTENT_PREVIEWED' => 'Aktionsvorschlag geprüft', 'INFORMATION_REQUESTED' => 'Auskunft abgerufen',
		'LEGAL_HOLD_SET' => 'Legal Hold gesetzt', 'LEGAL_HOLD_RELEASED' => 'Legal Hold aufgehoben', 'SET' => 'gesetzt', 'REMOVED' => 'entfernt',
		'CHANGED' => 'geändert', 'ACTIVATED' => 'aktiviert', 'SMTP_ACCEPTED' => 'per E-Mail versendet', 'EXPORTED' => 'exportiert',
		'CLOSURE_RECORDED' => 'abgeschlossen', 'CREATED_WITH_OVERRIDE' => 'trotz Prüfhinweisen angelegt', 'TRANSFERRED' => 'in die Leistungen übernommen',
		'DOCUMENTED' => 'dokumentiert', 'PAPERLESS_LINKED' => 'mit Paperless verknüpft', 'INBOX_LINKED' => 'aus dem Belegeingang übernommen', 'ADDRESS_BOOK_CREATED' => 'ins Adressbuch übernommen',
		'APPLIED' => 'übernommen',
	];
	/** Vollständige Titel, wo „Objekt + Tätigkeit“ nicht passt. */
	public const FULL_TITLES = [
		'ORDER/SIGNED_ON_TABLET' => 'Auftrag am Tablet unterschrieben',
		'MAIL_DISPATCH/SMTP_ACCEPTED' => 'E-Mail versendet',
		'DEREGISTRATION_MAIL/SMTP_ACCEPTED' => 'Abmeldung per E-Mail versendet',
		'SERVICE/SELECTION_UPDATED' => 'Leistungsauswahl geändert',
		'SERVICE/LIFECYCLE_UPDATED' => 'Leistung erfasst',
		'CONTRACT/CONTRACT_AMENDMENT' => 'Auftrag nachträglich geändert',
		'INVOICE/CANCELLATION_CREATED' => 'Stornorechnung erstellt',
		'INVOICE/CREDIT_NOTE_CREATED' => 'Gutschrift erstellt',
		'INVOICE/CANCELLED_BY_DOCUMENT' => 'Rechnung storniert',
		'CASE/FOLLOWUP_RECORDS_CLOSED' => 'Offene Folgeeinträge erledigt',
		'CASE/FOLLOWUP_RECORDS_CANCELLED' => 'Offene Folgeeinträge abgesagt',
		'PROCESS/STARTED' => 'Ablauf gestartet',
		'PROCESS/STEP_SKIPPED' => 'Ablaufschritt entfällt',
		'PROCESS/CASE_CLOSED' => 'Fall über den Ablauf abgeschlossen',
		'PROCESS/CEREMONY_FROM_SCHEDULE' => 'Fallmerkmal „Trauerfeier“ aus bestätigtem Termin auf „ja“ gesetzt',
		'DEREGISTRATION_MAIL/STATUS_TRANSITION_FAILED' => 'Abmeldung versendet, Status nicht umgestellt',
		'CAPTURE_IMPORT/CONFIRMED_AND_FILED' => 'Schnellerfassung übernommen und abgelegt',
		'DOCUMENT/PAPERLESS_LINKED' => 'Dokument mit Paperless verknüpft',
		'DOCUMENT/INBOX_LINKED' => 'Dokument aus dem Belegeingang übernommen',
		'INCOMING_INVOICE/INBOX_LINKED' => 'Eingangsrechnung aus dem Belegeingang übernommen',
		'SCHEDULE/DEPENDENT_ACTIONS_REACTIVATED' => 'Folgeaktionen des Termins wieder aktiviert',
		'ASSISTANT/TASK_BATCH_CREATED' => 'Aufgaben über den Assistenten angelegt',
		'ASSISTANT/MASTER_DATA_APPLIED' => 'Stammdaten über den Assistenten übernommen',
		'ASSISTANT/CONTACT_CREATED' => 'Kontakt über den Assistenten angelegt',
	];
	/** Statuswechsel (Aktion STATUS_<Wert>) als Tätigkeit. */
	public const STATUS_ACTIONS = [
		'ENTWURF' => 'zurück auf Entwurf gesetzt', 'PRUEFUNG' => 'zur Prüfung vorgelegt', 'FREIGEGEBEN' => 'freigegeben', 'STORNIERT' => 'storniert',
		'BEZAHLT' => 'als bezahlt erfasst', 'BEAUFTRAGT' => 'beauftragt', 'ZUGEORDNET' => 'zugeordnet', 'ABGESCHLOSSEN' => 'abgeschlossen',
		'ERLEDIGT' => 'erledigt', 'ABGELEHNT' => 'abgelehnt',
	];

	/** Allgemeine Feldbezeichnungen (Aufgaben, Termine, Dokumente, Fallkopf). */
	public const FIELD_LABELS = [
		'status' => 'Status', 'branch' => 'Niederlassung', 'responsibleEmployee' => 'Zuständige Person',
		'retentionHold' => 'Aufbewahrungssperre', 'retentionDueAt' => 'Aufbewahrung fällig am',
		'retentionHoldReason' => 'Grund der Aufbewahrungssperre', 'retentionHoldResponsible' => 'Aufbewahrungssperre gesetzt von',
		'retentionHoldSetAt' => 'Aufbewahrungssperre gesetzt am', 'retentionHoldReviewAt' => 'Aufbewahrungssperre zu prüfen am',
		'anonymizedAt' => 'Anonymisiert am',
		'title' => 'Titel', 'date' => 'Termin/Datum', 'assigneeUid' => 'Zuständig', 'assigneeName' => 'Zuständig (Name)',
		'priority' => 'Priorität', 'description' => 'Beschreibung', 'dueOffsetDays' => 'Frist (Tage)',
		'channel' => 'Kontaktweg', 'contact' => 'Kontakt', 'recipient' => 'Empfänger', 'recipientCategory' => 'Empfängergruppe',
		'location' => 'Ort', 'appointmentCategory' => 'Terminart', 'durationMinutes' => 'Dauer (Minuten)',
		'scheduleKind' => 'Terminkategorie', 'sourceStatusAfter' => 'Status danach', 'caseStatusAfter' => 'Fallstatus danach',
		'checklist' => 'Checkliste', 'categories' => 'Kategorien', 'documentType' => 'Dokumentart',
		'autoCompleted' => 'Automatisch erledigt', 'autoCompletedReason' => 'Grund der automatischen Erledigung', 'shortNotice' => 'Kurzfristig',
		'resourceKeys' => 'Ressourcen', 'assigneeUids' => 'Beteiligte', 'externalParticipants' => 'Externe Teilnehmende', 'changeReason' => 'Änderungsgrund',
		'kind' => 'Art', 'reason' => 'Begründung',
		'labelNoteBlock' => 'Notizen aus Markern', 'completedReason' => 'Erledigungsgrund', 'skipped' => 'Entfällt', 'skipReason' => 'Begründung für „Entfällt“',
		'skippedBy' => '„Entfällt“ gesetzt von', 'skippedAt' => '„Entfällt“ gesetzt am', 'manuallyReopened' => 'Von Hand wieder geöffnet',
		'performedQuantity' => 'Erbrachte Menge', 'billability' => 'Abrechnung', 'classificationReason' => 'Begründung', 'serviceStatus' => 'Status',
	];
	/** Fall-Stammdaten, die nicht im Feldkatalog (resources/case-field-schema.json) stehen: Auftrag, Rechnungsempfänger, Unterschrift. */
	public const MASTER_LABELS = [
		'order_mode' => 'Auftragsart', 'order_number' => 'Auftragsnummer', 'order_date' => 'Auftragsdatum', 'order_status' => 'Auftragsstatus',
		'commissioning_type' => 'Art der Beauftragung', 'order_notes' => 'Auftragsnotiz', 'order_special_terms' => 'Besondere Abreden / Kostenobergrenze',
		'order_payment_terms' => 'Zahlungsziel / Abschläge', 'order_contract_conclusion' => 'Vertragsschluss',
		'order_withdrawal_request' => 'Leistungsbeginn vor Ablauf der Widerrufsfrist verlangt', 'order_withdrawal_ack' => 'Kenntnis vom Erlöschen des Widerrufsrechts bestätigt',
		'order_signature_name' => 'Unterschrieben von', 'order_signature_date' => 'Datum der Unterschrift', 'order_signature_time' => 'Uhrzeit der Unterschrift',
		'order_signature_place' => 'Ort der Unterschrift', 'order_signature_method' => 'Art der Unterschrift', 'order_signature_sha256' => 'Prüfsumme der Unterschrift',
		'order_signature_accepted_by' => 'Unterschrift entgegengenommen von',
		'order_closed_at' => 'Auftrag abgeschlossen am', 'order_closed_by' => 'Auftrag abgeschlossen von', 'order_closed_reason' => 'Abschlussgrund des Auftrags',
		'order_client_salutation' => 'Auftraggeber: Anrede', 'order_client_first_name' => 'Auftraggeber: Vorname', 'order_client_name' => 'Auftraggeber: Nachname',
		'order_client_last_name' => 'Auftraggeber: Nachname', 'order_client_relation' => 'Auftraggeber: Verhältnis zur verstorbenen Person',
		'order_client_street' => 'Auftraggeber: Straße', 'order_client_postal_city' => 'Auftraggeber: PLZ und Ort', 'order_client_country' => 'Auftraggeber: Land',
		'order_client_phone' => 'Auftraggeber: Telefon', 'order_client_mobile' => 'Auftraggeber: Mobil', 'order_client_email' => 'Auftraggeber: E-Mail',
		'order_client_address' => 'Auftraggeber: Anschrift',
		'invoice_same_as_client' => 'Rechnungsempfänger wie Auftraggeber', 'invoice_salutation' => 'Rechnungsempfänger: Anrede',
		'invoice_first_name' => 'Rechnungsempfänger: Vorname', 'invoice_name' => 'Rechnungsempfänger: Nachname',
		'invoice_relation' => 'Rechnungsempfänger: Verhältnis zur verstorbenen Person', 'invoice_street' => 'Rechnungsempfänger: Straße',
		'invoice_postal_city' => 'Rechnungsempfänger: PLZ und Ort', 'invoice_country' => 'Rechnungsempfänger: Land', 'invoice_phone' => 'Rechnungsempfänger: Telefon',
		'invoice_mobile' => 'Rechnungsempfänger: Mobil', 'invoice_email' => 'Rechnungsempfänger: E-Mail', 'invoice_electronic_address' => 'Rechnungsempfänger: Adresse für E-Rechnungen',
		'invoice_payment_method' => 'Zahlungsart der Rechnung', 'payment_term_days' => 'Zahlungsziel (Tage)',
		'kva_number' => 'KVA-Nummer', 'kva_date' => 'KVA-Datum', 'kva_valid_until' => 'KVA gültig bis', 'kva_converted_at' => 'KVA in Auftrag überführt am',
		'certificate_free_count' => 'Sterbeurkunden gebührenfrei', 'certificate_paid_count' => 'Sterbeurkunden gebührenpflichtig',
		'urkunden_gebuehrenpflichtig' => 'Sterbeurkunden gebührenpflichtig (Auftrag)', 'burial_variant_code' => 'Bestattungsvariante',
		'process_enabled' => 'Ablaufsteuerung', 'side_order_number' => 'Nebenauftragsnummer',
		'order_poa_other' => 'Vollmacht für weitere Stellen', 'deposit_amount_cents' => 'Vereinbarte Anzahlung', 'deposit_due_date' => 'Anzahlung fällig am',
		'receives_pension' => 'Bezieht Rente', 'pension_insurance_number' => 'Postrentennummer', 'pension_insurance_number_2' => 'Weitere Postrentennummer',
		'pension_institution' => 'Rentenversicherungsträger', 'pension_notes' => 'Hinweise zur Rentenabmeldung', 'guardian_contact' => 'Betreuer aus Adressbuch',
		'with_funeral_ceremony' => 'Trauerfeier', 'pickup_time' => 'Abholzeit', 'body_height_cm' => 'Körpergröße (cm)', 'body_weight_kg' => 'Körpergewicht (kg)',
		'surcharge_pickup_rule_key' => 'Zuschlagsstaffel Abholung', 'surcharge_height_rule_key' => 'Zuschlagsstaffel Größe',
		'surcharge_weight_rule_key' => 'Zuschlagsstaffel Gewicht', 'surcharge_other_rule_key' => 'Weitere Zuschlagsstaffel',
	];
	/** Fall-Stammdaten mit Werteliste aus dem Customizing (wie in der Stammdatenmaske). */
	public const FIELD_VALUE_LISTS = [
		'salutation' => 'SALUTATION', 'title' => 'TITLE', 'gender' => 'GENDER', 'civil_status' => 'CIVIL_STATUS', 'religion' => 'RELIGION',
		'religion_disclosure' => 'YES_NO', 'identity_document_type' => 'IDENTITY_DOCUMENT_TYPE', 'death_determination' => 'DEATH_DETERMINATION',
		'manner_of_death' => 'MANNER_OF_DEATH', 'transfer_status' => 'TRANSFER_STATUS', 'death_certificate_status' => 'DEATH_CERTIFICATE_STATUS',
		'release_status' => 'RELEASE_STATUS', 'registry_status' => 'REGISTRY_STATUS', 'guardianship_status' => 'GUARDIANSHIP_STATUS',
		'grave_type' => 'GRAVE_TYPE', 'grave_depth' => 'GRAVE_DEPTH', 'grave_location' => 'GRAVE_LOCATION', 'coffin_urn_size' => 'COFFIN_URN_SIZE',
		'urn_handover_type' => 'URN_HANDOVER_TYPE', 'burial_variant_code' => 'BURIAL_VARIANT',
	];
	/** Feste Werte einzelner Felder. */
	public const FIELD_VALUES = [
		'order_mode' => ['A' => 'Bestattungsauftrag', 'KVA' => 'Kostenvoranschlag'],
		'payment_method' => ['TRANSFER' => 'Überweisung', 'SEPA_DIRECT_DEBIT' => 'SEPA-Lastschrift'],
		'invoice_payment_method' => ['TRANSFER' => 'Überweisung', 'SEPA_DIRECT_DEBIT' => 'SEPA-Lastschrift'],
		'order_contract_conclusion' => ['GESCHAEFTSRAEUME' => 'In den Geschäftsräumen', 'AUSSERHALB' => 'Außerhalb der Geschäftsräume', 'FERNABSATZ' => 'Fernabsatz'],
		'order_signature_method' => ['TABLET' => 'Am Tablet', 'PAPIER' => 'Auf Papier', 'PAPER' => 'Auf Papier'],
		'process_enabled' => ['1' => 'Ja', '0' => 'Nein'],
		'receives_pension' => ['1' => 'Ja', '0' => 'Nein'],
		'with_funeral_ceremony' => ['1' => 'Ja', '0' => 'Nein'],
		'death_time_mode' => ['EXAKT' => 'Exakter Zeitpunkt', 'ZEITRAUM' => 'Zeitraum von/bis'],
		'commissioning_type' => ['SCHRIFTLICH' => 'Schriftlich', 'MUENDLICH' => 'Mündlich', 'TELEFONISCH' => 'Telefonisch'],
	];
	/** Allgemeine Schlüsselwerte (Status, Priorität, Abrechnung). */
	public const VALUE_LABELS = [
		'JA' => 'Ja', 'NEIN' => 'Nein', 'OFFEN' => 'Offen', 'IN_BEARBEITUNG' => 'In Bearbeitung', 'ERLEDIGT' => 'Erledigt', 'BESTAETIGT' => 'Bestätigt',
		'ABGESAGT' => 'Abgesagt', 'ENTWURF' => 'Entwurf', 'GEAENDERT' => 'Geändert', 'DOKUMENTIERT' => 'Dokumentiert', 'NEU' => 'Neu',
		'ABGESCHLOSSEN' => 'Abgeschlossen', 'FINAL' => 'Endfassung', 'PRUEFUNG' => 'In Prüfung', 'FREIGEGEBEN' => 'Freigegeben', 'STORNIERT' => 'Storniert',
		'BEZAHLT' => 'Bezahlt', 'BEAUFTRAGT' => 'Beauftragt', 'ERBRACHT' => 'Erbracht', 'TEILWEISE_ERBRACHT' => 'Teilweise erbracht', 'ABGERECHNET' => 'Abgerechnet',
		'ZUGEORDNET' => 'Zugeordnet', 'ABRECHENBAR' => 'Abrechenbar', 'KULANZ' => 'Kulanz', 'NICHT_ABRECHENBAR' => 'Nicht abrechenbar', 'UNKLAR' => 'Unklar',
		'KRITISCH' => 'Kritisch', 'HOCH' => 'Hoch', 'NORMAL' => 'Normal', 'NIEDRIG' => 'Niedrig', 'VERSENDET' => 'Versendet', 'AKTIV' => 'Aktiv',
		'INTERNAL_ACTIVITY' => 'Interne Tätigkeit', 'EXTERNAL_APPOINTMENT' => 'Externer Fixtermin',
	];
	private const MAX_ROWS = 20;

	/** @var array<string, array<string, string>> */
	private array $choiceCache = [];

	/**
	 * @param array<string, string> $caseLabels Bezeichnungen aus dem Feldkatalog (Schlüssel => Bezeichnung)
	 * @param (\Closure(string): array<string, string>)|null $choiceLabels Werteliste aus dem Customizing (Wert => Bezeichnung)
	 * @param array<string, string> $orderChoiceLabels Ankreuzfelder des Auftrags (Stammdatum => Bezeichnung)
	 */
	public function __construct(
		private array $caseLabels = [],
		private ?\Closure $choiceLabels = null,
		private array $orderChoiceLabels = [],
	) {
	}

	/** Anzeigebezeichnung eines Feldes. */
	public function label(string $objectType, string $key): string {
		$known = $this->knownLabel($objectType, $key);
		if ($known !== null) {
			return $known;
		}
		$text = trim((string)preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace('_', ' ', $key)));
		return $text === '' ? $key : mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
	}

	/** Gepflegte Bezeichnung oder null (dann bleibt eine mitgelieferte Bezeichnung wie „Wert“ beim Marker stehen). */
	private function knownLabel(string $objectType, string $key): ?string {
		if (strtoupper($objectType) === 'CASE') {
			if (isset($this->orderChoiceLabels[$key])) {
				return 'Ankreuzfeld „' . $this->orderChoiceLabels[$key] . '“';
			}
			if (isset(self::MASTER_LABELS[$key])) {
				return self::MASTER_LABELS[$key];
			}
			if (isset($this->caseLabels[$key])) {
				return $this->caseLabels[$key];
			}
		}
		return self::FIELD_LABELS[$key] ?? null;
	}

	/** Anzeigewert eines Feldes; „–“ bleibt „–“. */
	public function value(string $objectType, string $key, string $value): string {
		if ($value === '' || $value === '–') {
			return '–';
		}
		if (preg_match('/sha256$/i', $key) && preg_match('/^[0-9a-f]{40,}$/i', $value)) {
			return substr($value, 0, 12) . '…';
		}
		if (str_ends_with($key, '_cents') && preg_match('/^-?\d+$/', $value)) {
			return self::money((int)$value);
		}
		if (strtoupper($objectType) === 'CASE' && isset($this->orderChoiceLabels[$key])) {
			return ['JA' => 'angekreuzt', 'NEIN' => 'nicht angekreuzt'][strtoupper($value)] ?? $value;
		}
		if (isset(self::FIELD_VALUES[$key][$value])) {
			return self::FIELD_VALUES[$key][$value];
		}
		if (strtoupper($objectType) === 'CASE' && isset(self::FIELD_VALUE_LISTS[$key])) {
			$labels = $this->choices(self::FIELD_VALUE_LISTS[$key]);
			if (isset($labels[$value]) && $labels[$value] !== '') {
				return $labels[$value];
			}
		}
		if (preg_match('/^[A-Z][A-Z_]*$/', $value) && isset(self::VALUE_LABELS[$value])) {
			return self::VALUE_LABELS[$value];
		}
		return $value;
	}

	public function isTechnicalKey(string $objectType, string $key): bool {
		return in_array($key, self::TECHNICAL_KEYS, true) || in_array($key, self::TECHNICAL_KEYS_BY_TYPE[strtoupper($objectType)] ?? [], true);
	}

	/**
	 * Bereitet einen Eintrag auf.
	 * @param list<array<string, mixed>> $changes feldweiser Vergleich bzw. Kontextzeile aus AuditService
	 * @return array{title: string, changes: list<array<string, mixed>>, technicalChanges: list<array<string, mixed>>, technical: bool}
	 */
	public function present(string $objectType, string $action, mixed $before, mixed $after, array $changes): array {
		$type = strtoupper($objectType);
		$action = strtoupper($action);
		$unchanged = false;
		if ($type === 'SERVICE' && $action === 'SELECTION_UPDATED') {
			$changes = $this->selectionChanges($before, $after);
			// Gespeichert ohne Änderung an den Positionen (z. B. erneutes Speichern): nur bei den technischen Einträgen.
			$unchanged = $changes === [] && is_array($before['items'] ?? null) && is_array($after['items'] ?? null);
		} elseif ($type === 'SERVICE' && $action === 'LIFECYCLE_UPDATED') {
			$changes = $this->lifecycleChanges($before, $after);
		} elseif ($type === 'CONTRACT' && $action === 'CONTRACT_AMENDMENT') {
			$changes = $this->selectionChanges(is_array($before) ? ($before['services'] ?? null) : null, is_array($after) ? ($after['services'] ?? null) : null);
			$reason = is_array($after) ? trim((string)($after['reason'] ?? '')) : '';
			if ($reason !== '') {
				$changes[] = self::line('reason', 'Begründung: ' . $reason);
			}
		} elseif ($changes === []) {
			$changes = $this->summary($type, $action, $before, $after);
		}

		$visible = [];
		$technical = [];
		foreach ($changes as $change) {
			$key = (string)($change['key'] ?? '');
			if (str_starts_with($key, '__') || isset($change['text'])) {
				$visible[] = $change;
				continue;
			}
			$given = trim((string)($change['label'] ?? ''));
			$change['label'] = $this->knownLabel($type, $key) ?? ($given !== '' ? $given : $this->label($type, $key));
			$change['before'] = $this->value($type, $key, (string)($change['before'] ?? '–'));
			$change['after'] = $this->value($type, $key, (string)($change['after'] ?? '–'));
			if ($this->isTechnicalKey($type, $key)) {
				$technical[] = $change;
			} else {
				$visible[] = $change;
			}
		}
		$fachlich = array_filter($visible, static fn (array $change): bool => ($change['key'] ?? '') !== '__context__');
		if ($type === 'DOCUMENT' && $action === 'UPDATED' && array_filter($technical, static fn (array $change): bool => $change['key'] === 'fileId') !== []) {
			$visible[] = self::line('file', 'Neue Dateifassung gespeichert');
			$fachlich = [true];
		}
		// Bei geänderten Aufgaben, Terminen, Dokumenten und Kontakten zeigt eine Kontextzeile, welcher Eintrag gemeint ist.
		if ($action === 'UPDATED' && in_array($type, ['TASK', 'SCHEDULE', 'DOCUMENT', 'CONTACT'], true) && $fachlich !== []
			&& array_filter($visible, static fn (array $change): bool => in_array($change['key'] ?? '', ['title', '__context__'], true)) === []) {
			$name = trim((string)(is_array($after) ? ($after['title'] ?? '') : ''));
			if ($name !== '') {
				array_unshift($visible, ['key' => '__context__', 'label' => '', 'before' => '', 'after' => 'Titel: ' . $name]);
			}
		}
		$technicalOnly = $unchanged || in_array($type . '/' . $action, self::TECHNICAL_ACTIONS, true) || ($fachlich === [] && $technical !== []);
		return [
			'title' => $unchanged ? 'Leistungsauswahl ohne Änderung gespeichert' : $this->title($type, $action, $visible, $technicalOnly),
			'changes' => array_values($visible),
			'technicalChanges' => array_values($technical),
			'technical' => $technicalOnly,
		];
	}

	/** Titel eines Eintrags; bei wenigen geänderten Feldern mit deren Bezeichnung, sonst mit der Anzahl. */
	public function title(string $type, string $action, array $changes = [], bool $technicalOnly = false): string {
		$type = strtoupper($type);
		$action = strtoupper($action);
		$object = self::OBJECTS[$type] ?? ucfirst(strtolower(str_replace('_', ' ', $type)));
		if ($technicalOnly && $action === 'UPDATED') {
			return $object . ' technisch abgeglichen';
		}
		if (isset(self::FULL_TITLES[$type . '/' . $action])) {
			$base = self::FULL_TITLES[$type . '/' . $action];
		} elseif (str_starts_with($action, 'STATUS_')) {
			$status = substr($action, 7);
			$base = $object . ' ' . (self::STATUS_ACTIONS[$status] ?? 'Status: ' . (self::VALUE_LABELS[$status] ?? ucfirst(strtolower(str_replace('_', ' ', $status)))));
		} else {
			$base = $object . ' ' . (self::ACTIONS[$action] ?? strtolower(str_replace('_', ' ', $action)));
		}
		if ($type === 'SERVICE' && $action === 'SELECTION_UPDATED') {
			$counts = [];
			foreach (['added' => 'hinzugefügt', 'removed' => 'entfernt', 'cancelled' => 'storniert', 'quantity' => 'Menge geändert', 'price' => 'Preis geändert', 'basis' => 'Preisart geändert'] as $key => $word) {
				$n = count(array_filter($changes, static fn (array $change): bool => ($change['key'] ?? '') === 'service_' . $key));
				if ($n > 0) {
					$counts[] = in_array($key, ['quantity', 'price', 'basis'], true) ? ($n === 1 ? $word : $n . '× ' . $word) : $n . ' ' . $word;
				}
			}
			return $counts === [] ? $base : $base . ' (' . implode(', ', $counts) . ')';
		}
		$labels = array_values(array_filter(array_map(
			static fn (array $change): ?string => str_starts_with((string)($change['key'] ?? ''), '__') || isset($change['text']) ? null : (string)($change['label'] ?? ''),
			$changes
		)));
		if ($labels === []) {
			return $base;
		}
		return $base . ' (' . (count($labels) <= 3 ? implode(', ', $labels) : count($labels) . ' Felder') . ')';
	}

	/**
	 * Leistungsauswahl: hinzugefügte, entfernte und stornierte Leistungen sowie geänderte Mengen und Preise.
	 * @return list<array<string, mixed>>
	 */
	public function selectionChanges(mixed $before, mixed $after): array {
		$items = static function (mixed $side): array {
			$list = is_array($side) ? ($side['items'] ?? null) : null;
			$byId = [];
			foreach (is_array($list) ? $list : [] as $item) {
				if (is_array($item) && isset($item['id'])) {
					$byId[(int)$item['id']] = $item;
				}
			}
			return $byId;
		};
		$old = $items($before);
		$new = $items($after);
		$added = array_diff_key($new, $old);
		$removed = array_diff_key($old, $new);
		$rows = [];
		foreach ($added as $item) {
			if (isset($added[(int)($item['parentServiceId'] ?? 0)])) {
				continue;
			} // Bestandteile eines neuen Pakets
			$rows[] = self::line('service_added', 'Hinzugefügt: ' . $this->serviceName($item));
		}
		foreach ($removed as $item) {
			if (isset($removed[(int)($item['parentServiceId'] ?? 0)])) {
				continue;
			}
			$rows[] = self::line('service_removed', 'Entfernt: ' . $this->serviceName($item));
		}
		foreach (array_intersect_key($new, $old) as $id => $item) {
			$previous = $old[$id];
			if (($item['serviceStatus'] ?? '') === 'STORNIERT' && ($previous['serviceStatus'] ?? '') !== 'STORNIERT') {
				if (isset($new[(int)($item['parentServiceId'] ?? 0)]) && ($new[(int)$item['parentServiceId']]['serviceStatus'] ?? '') === 'STORNIERT'
					&& ($old[(int)$item['parentServiceId']]['serviceStatus'] ?? '') !== 'STORNIERT') {
					continue;
				}
				$rows[] = self::line('service_cancelled', 'Storniert: ' . $this->serviceName($previous));
				continue;
			}
			if ((int)($item['quantityMilli'] ?? 0) !== (int)($previous['quantityMilli'] ?? 0)) {
				$unit = $this->unit((string)($item['unit'] ?? ''));
				$rows[] = self::line('service_quantity', 'Menge geändert: ' . (string)($item['title'] ?? 'Leistung') . ' ' . self::quantity($previous['quantity'] ?? 0) . ' → ' . self::quantity($item['quantity'] ?? 0) . ($unit !== '' ? ' ' . $unit : ''));
			}
			// 0.89.5: Preisart (Festpreis, Schätzbetrag, nach Bescheid)
			if (isset($item['priceBasis'], $previous['priceBasis']) && $item['priceBasis'] !== $previous['priceBasis']) {
				$rows[] = self::line('service_basis', 'Preisart geändert: ' . (string)($item['title'] ?? 'Leistung') . ' ' . (CatalogRules::PRICE_BASES[(string)$previous['priceBasis']] ?? (string)$previous['priceBasis']) . ' → ' . (CatalogRules::PRICE_BASES[(string)$item['priceBasis']] ?? (string)$item['priceBasis']));
			}
			if (isset($item['unitPriceCents'], $previous['unitPriceCents']) && (int)$item['unitPriceCents'] !== (int)$previous['unitPriceCents']) {
				$rows[] = self::line('service_price', 'Preis geändert: ' . (string)($item['title'] ?? 'Leistung') . ' ' . self::money((int)$previous['unitPriceCents']) . ' → ' . self::money((int)$item['unitPriceCents']));
			}
		}
		return self::limit($rows);
	}

	/**
	 * Leistungserfassung einer Position: erbrachte Menge, Abrechnung, Begründung, Status.
	 * @return list<array<string, mixed>>
	 */
	public function lifecycleChanges(mixed $before, mixed $after): array {
		if (!is_array($after)) {
			return [];
		}
		$before = is_array($before) ? $before : [];
		$rows = [['key' => '__context__', 'label' => '', 'before' => '', 'after' => 'Leistung: ' . (string)($after['title'] ?? $before['title'] ?? '')]];
		$unit = $this->unit((string)($after['unit'] ?? ''));
		if ((int)($before['performedQuantityMilli'] ?? 0) !== (int)($after['performedQuantityMilli'] ?? 0)) {
			$rows[] = ['key' => 'performedQuantity', 'label' => 'Erbrachte Menge', 'before' => self::quantity($before['performedQuantity'] ?? 0) . ($unit !== '' ? ' ' . $unit : ''), 'after' => self::quantity($after['performedQuantity'] ?? 0) . ($unit !== '' ? ' ' . $unit : '')];
		}
		foreach (['billability' => 'Abrechnung', 'classificationReason' => 'Begründung', 'serviceStatus' => 'Status'] as $key => $label) {
			$old = trim((string)($before[$key] ?? ''));
			$new = trim((string)($after[$key] ?? ''));
			if ($old !== $new) {
				$rows[] = ['key' => $key, 'label' => $label, 'before' => $old === '' ? '–' : $old, 'after' => $new === '' ? '–' : $new];
			}
		}
		return $rows;
	}

	/**
	 * Kurze Angabe zu Vorgängen ohne Feldvergleich.
	 * @return list<array<string, mixed>>
	 */
	public function summary(string $type, string $action, mixed $before, mixed $after): array {
		$a = is_array($after) ? $after : [];
		$b = is_array($before) ? $before : [];
		$text = static fn (string $key): string => trim((string)(is_scalar($a[$key] ?? null) ? $a[$key] : (is_scalar($b[$key] ?? null) ? $b[$key] : '')));
		$rows = [];
		switch ($type . '/' . $action) {
			case 'ORDER/SIGNED_ON_TABLET':
				if ($text('signerName') !== '') {
					$rows[] = self::line('signer', 'Unterschrieben von ' . $text('signerName') . ($text('place') !== '' ? ' in ' . $text('place') : ''));
				}
				if (!empty($a['declarations']['withdrawalRequest'])) {
					$rows[] = self::line('withdrawal', 'Leistungsbeginn vor Ablauf der Widerrufsfrist verlangt');
				}
				return $rows;
			case 'MAIL_DISPATCH/SMTP_ACCEPTED':
			case 'DEREGISTRATION_MAIL/SMTP_ACCEPTED':
			case 'DEREGISTRATION_MAIL/STATUS_TRANSITION_FAILED':
				return $text('recipient') !== '' ? [self::line('recipient', 'Empfänger: ' . $text('recipient'))] : [];
			case 'WORKFLOW/EXECUTED':
			case 'WORKFLOW/FAILED':
				return $text('workflow') !== '' ? [self::line('workflow', $text('workflow') . ($text('action') !== '' ? ': ' . $text('action') : ''))] : [];
			case 'PROCESS_ACTION/EXECUTED':
			case 'PROCESS_ACTION/FAILED':
				if ($text('action') !== '') {
					$rows[] = self::line('action', $text('action') . ($text('step') !== '' ? ' (Schritt „' . $text('step') . '“)' : ''));
				}
				$message = is_array($a['error'] ?? null) ? trim((string)($a['error']['message'] ?? '')) : $text('message');
				if ($message !== '') {
					$rows[] = self::line('error', 'Fehler: ' . $message);
				}
				return $rows;
			case 'PROCESS/STEP_SKIPPED':
				return $text('reason') !== '' ? [self::line('reason', 'Begründung: ' . $text('reason'))] : [];
			case 'PROCESS/CASE_CLOSED':
				return $text('note') !== '' ? [self::line('note', 'Abschlussgrund: ' . $text('note'))] : [];
			case 'PROCESS/CEREMONY_FROM_SCHEDULE':
				$when = strtotime($text('date'));
				return $text('title') !== '' ? [self::line('schedule', 'Termin „' . $text('title') . '“' . ($when !== false && $text('date') !== '' ? ' am ' . date('d.m.Y, H:i', $when) . ' Uhr' : ''))] : [];
			case 'CASE/FOLLOWUP_RECORDS_CLOSED':
			case 'CASE/FOLLOWUP_RECORDS_CANCELLED':
				return (int)($a['count'] ?? 0) > 0 ? [self::line('count', (int)$a['count'] === 1 ? '1 Eintrag' : (int)$a['count'] . ' Einträge')] : [];
			case 'CAPTURE_IMPORT/CONFIRMED_AND_FILED':
				return is_array($a['confirmedFields'] ?? null) ? [self::line('fields', count($a['confirmedFields']) . ' Angaben übernommen')] : [];
		}
		if (in_array($type, ['INVOICE'], true) && $text('invoiceNumber') !== '') {
			$rows[] = self::line('invoice', 'Rechnung ' . $text('invoiceNumber') . ($text('invoiceTypeLabel') !== '' ? ' (' . $text('invoiceTypeLabel') . ')' : '') . ($text('referenceInvoiceNumber') !== '' ? ' zu ' . $text('referenceInvoiceNumber') : ''));
		} elseif ($type === 'INCOMING_INVOICE' && $text('supplierName') !== '') {
			$rows[] = self::line('incoming', $text('supplierName') . ($text('supplierInvoiceNumber') !== '' ? ', Rechnungsnummer ' . $text('supplierInvoiceNumber') : ''));
		} elseif ($type === 'SIDE_ORDER' && $text('sideOrderNumber') !== '') {
			$rows[] = self::line('side_order', 'Nebenauftrag ' . $text('sideOrderNumber'));
		} elseif ($text('title') !== '' && !str_starts_with($action, 'STATUS_')) {
			$rows[] = ['key' => '__context__', 'label' => '', 'before' => '', 'after' => 'Titel: ' . $text('title')];
		}
		$old = is_scalar($b['status'] ?? null) ? (string)$b['status'] : '';
		$new = is_scalar($a['status'] ?? null) ? (string)$a['status'] : '';
		if ($old !== '' && $new !== '' && $old !== $new && !str_starts_with($action, 'STATUS_')) {
			$rows[] = ['key' => 'status', 'label' => 'Status', 'before' => $old, 'after' => $new];
		}
		return $rows;
	}

	/** @return array<string, string> */
	private function choices(string $listKey): array {
		if (!isset($this->choiceCache[$listKey])) {
			try {
				$this->choiceCache[$listKey] = $this->choiceLabels !== null ? ($this->choiceLabels)($listKey) : [];
			} catch (\Throwable) {
				$this->choiceCache[$listKey] = [];
			}
		}
		return $this->choiceCache[$listKey];
	}

	private function unit(string $code): string {
		if ($code === '') {
			return '';
		}
		$label = $this->choices('QUANTITY_UNIT')[$code] ?? '';
		return $label !== '' ? $label : ['STK' => 'Stück', 'PAUSCHAL' => 'Pauschale', 'STD' => 'Stunden', 'KM' => 'Kilometer', 'TAG' => 'Tage'][$code] ?? $code;
	}

	/** „Sarg Eiche“ oder „Kilometerpauschale (35 Kilometer)“: Menge nur, wenn sie nicht 1 Stück ist. */
	private function serviceName(array $item): string {
		$title = trim((string)($item['title'] ?? '')) ?: 'Leistung';
		$quantity = (float)($item['quantity'] ?? 1);
		$unit = (string)($item['unit'] ?? 'STK');
		if (abs($quantity - 1.0) < 0.0005 && in_array($unit, ['', 'STK', 'PAUSCHAL'], true)) {
			return $title;
		}
		return $title . ' (' . self::quantity($quantity) . ' ' . $this->unit($unit) . ')';
	}

	private static function quantity(mixed $value): string {
		$number = (float)$value;
		return rtrim(rtrim(number_format($number, 3, ',', '.'), '0'), ',');
	}

	private static function money(int $cents): string {
		return number_format($cents / 100, 2, ',', '.') . ' €';
	}

	/** Zeile ohne Vorher/Nachher (wird als Klartext angezeigt). */
	private static function line(string $key, string $text): array {
		return ['key' => $key, 'label' => '', 'before' => '', 'after' => '', 'text' => $text];
	}

	/** @param list<array<string, mixed>> $rows */
	private static function limit(array $rows): array {
		if (count($rows) <= self::MAX_ROWS) {
			return $rows;
		}
		$remaining = count($rows) - self::MAX_ROWS;
		return [...array_slice($rows, 0, self::MAX_ROWS), ['key' => '__truncated__', 'label' => '', 'before' => '', 'after' => "… und {$remaining} weitere Leistungen"]];
	}
}
