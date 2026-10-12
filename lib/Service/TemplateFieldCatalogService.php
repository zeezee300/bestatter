<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

class TemplateFieldCatalogService {
	public function __construct(private ?LabelService $labels = null) {}

	private const SCHEMA_FILE = __DIR__ . '/../../resources/case-field-schema.json';
	private const TEMPLATE_DIRECTORY = __DIR__ . '/../../resources/templates';
	private const PLACEHOLDER_SOURCES = [__DIR__ . '/DocumentService.php', __DIR__ . '/DeregistrationService.php', __DIR__ . '/ProcessActionService.php'];

	public function fields(): array {
		$labels = [];
		$schema = is_file(self::SCHEMA_FILE) ? json_decode((string)file_get_contents(self::SCHEMA_FILE), true, 512, JSON_THROW_ON_ERROR) : [];
		foreach ($schema as $field) $labels['{{case.' . $field['key'] . '}}'] = (string)$field['label'];
		$placeholders = array_keys($labels);
		$source = '';
		foreach (self::PLACEHOLDER_SOURCES as $file) if (is_file($file)) $source .= "\n" . (string)file_get_contents($file);
		foreach (glob(self::TEMPLATE_DIRECTORY . '/*.txt') ?: [] as $file) $source .= "\n" . (string)file_get_contents($file);
		foreach (glob(self::TEMPLATE_DIRECTORY . '/*.docx') ?: [] as $file) {
			$archive = new \ZipArchive();
			if ($archive->open($file) !== true) continue;
			for ($index = 0; $index < $archive->numFiles; $index++) {
				$name = (string)$archive->getNameIndex($index);
				if (!str_starts_with($name, 'word/') || !str_ends_with($name, '.xml')) continue;
				$content = $archive->getFromIndex($index);
				if (is_string($content)) $source .= "\n" . strip_tags($content);
			}
			$archive->close();
		}
		preg_match_all('/\{\{[A-Za-z0-9_.]+\}\}/', $source, $matches);
		// 0.75.2: Platzhalter brauchen einen Namen aus Buchstaben (kein „{{...}}“ aus Kommentaren).
		$found = array_filter($matches[0] ?? [], static fn(string $placeholder): bool => preg_match('/^\{\{[A-Za-z]/', $placeholder) === 1);
		$placeholders = array_values(array_unique(array_merge($placeholders, $found)));
		sort($placeholders, SORT_NATURAL | SORT_FLAG_CASE);
		$special = [
			'{{deceased_name}}' => 'Name der verstorbenen Person', '{{branch.name}}' => 'Niederlassung', '{{standesamt}}' => 'Standesamt',
			'{{case.pension_insurance_number}}' => 'Postrentennummer', '{{case.pension_insurance_number_2}}' => 'Weitere Postrentennummer',
			'{{case.invoice_number}}' => 'Rechnungsnummer', '{{case.invoice_date}}' => 'Rechnungsdatum', '{{case.invoice_due_date}}' => 'Fälligkeitsdatum der Rechnung', '{{case.invoice_service_period_from}}' => 'Leistungszeitraum von', '{{case.invoice_service_period_to}}' => 'Leistungszeitraum bis', '{{case.invoice_service_period}}' => 'Leistungszeitraum als Text',
			'{{case.invoice_type}}' => 'Rechnungsart (PARTIAL/FINAL)', '{{case.invoice_type_label}}' => 'Rechnungsart (Teilrechnung/Schlussrechnung)',
			'{{case.invoice_sequence}}' => 'Laufende Rechnungsfolge im Fall', '{{case.invoice_prior_gross}}' => 'Summe früherer, nicht stornierter Rechnungen', '{{case.invoice_current_gross}}' => 'Bruttobetrag dieser Rechnung',
			'{{case.invoice_recipient_name}}' => 'Name des Rechnungsempfängers', '{{case.invoice_recipient_address}}' => 'Anschrift des Rechnungsempfängers',
			'{{case.invoice_payment_reference}}' => 'Zahlungsreferenz', '{{case.invoice_qr_payload}}' => 'EPC-QR-Code-Zahlungsdaten',
			'{{case.invoice_reference_label}}' => 'Bezugsrechnung eines Korrekturbelegs (Nummer und Datum)', '{{case.invoice_reference_number}}' => 'Nummer der Bezugsrechnung', '{{case.invoice_reference_date}}' => 'Datum der Bezugsrechnung',
			'{{case.invoice_correction_reason}}' => 'Grund der Stornierung bzw. Gutschrift', '{{case.invoice_correction_title}}' => 'Belegart des Korrekturbelegs', '{{case.invoice_correction_amount}}' => 'Korrekturbetrag (brutto, ohne Vorzeichen)',
			'{{case.invoice_payment_method}}' => 'Zahlungsart der Rechnung', '{{case.invoice_payment_instruction}}' => 'Zahlungs- oder SEPA-Einzugshinweis', '{{case.invoice_payment_qr_label}}' => 'Beschriftung des Zahlungs-QR-Bereichs',
			'{{case.sepa_mandate_reference}}' => 'SEPA-Mandatsreferenz', '{{case.sepa_collection_date}}' => 'Geplantes SEPA-Einzugsdatum', '{{case.branch_creditor_id}}' => 'Gläubiger-ID der Niederlassung',
			'{{case.branch_name}}' => 'Name der Niederlassung', '{{case.branch_address}}' => 'Anschrift der Niederlassung',
			'{{case.bank_account_holder}}' => 'Kontoinhaber', '{{case.bank_name}}' => 'Bankname', '{{case.bank_iban}}' => 'IBAN', '{{case.bank_bic}}' => 'BIC',
			'{{case.branch_vat_id}}' => 'Umsatzsteuer-ID', '{{case.branch_tax_number}}' => 'Steuernummer', '{{case.branch_register}}' => 'Registerangaben', '{{case.branch_managing_directors}}' => 'Geschäftsführung',
			'{{funeral_event.date}}' => 'Datum der Trauerfeier', '{{funeral_event.date_iso}}' => 'Datum der Trauerfeier (ISO)',
			'{{funeral_event.time}}' => 'Beginn der Trauerfeier', '{{funeral_event.end_time}}' => 'Ende der Trauerfeier',
			'{{funeral_event.location}}' => 'Ort der Trauerfeier', '{{funeral_event.category}}' => 'Art der Trauerfeier',
			'{{funeral_event.external_participants}}' => 'Externe Beteiligte der Trauerfeier',
			'{{service.position}}' => 'Leistungsposition', '{{service.article_number}}' => 'Artikelnummer', '{{service.title}}' => 'Leistungsbezeichnung',
			'{{service.quantity}}' => 'Menge und Einheit', '{{service.unit_price}}' => 'Einzelpreis', '{{service.vat_rate}}' => 'Umsatzsteuersatz',
			'{{service.net}}' => 'Nettobetrag der Position', '{{service.gross}}' => 'Bruttobetrag der Position', '{{service.package}}' => 'Paketname',
			'{{order.total_net}}' => 'Gesamtsumme netto', '{{order.total_vat}}' => 'Umsatzsteuer gesamt', '{{order.total_gross}}' => 'Gesamtsumme brutto',
			'{{order.services_heading}}' => 'Überschrift des Leistungsbereichs', '{{order.cost_note}}' => 'Preis- und Schätzhinweis für Auftrag oder Kostenvoranschlag', '{{order.tax_heading}}' => 'Überschrift der Umsatzsteuerübersicht',
			'{{order.taxable_net_label}}' => 'Bezeichnung steuerpflichtiges Netto', '{{order.total_vat_label}}' => 'Bezeichnung Umsatzsteuer', '{{order.pass_through_label}}' => 'Bezeichnung durchlaufende Posten', '{{order.total_gross_label}}' => 'Bezeichnung Gesamtbetrag',
			'{{order.taxable_net}}' => 'Umsatzsteuerliches Entgelt netto', '{{order.pass_through_total}}' => 'Summe echter durchlaufender Posten',
			'{{service.group_heading}}' => 'Überschrift des Rechnungsblocks', '{{service.group_subtotal}}' => 'Bezeichnung der Blockzwischensumme', '{{service.group_subtotal_amount}}' => 'Blockzwischensumme',
			'{{tax.rate}}' => 'Umsatzsteuersatz der Steuergruppe', '{{tax.net}}' => 'Steuerpflichtiges Netto der Steuergruppe', '{{tax.vat}}' => 'Umsatzsteuer der Steuergruppe',
			'{{case.date_of_death|date:DD.MM.YYYY}}' => 'Beispiel: Datum in deutschem Format TT.MM.JJJJ',
			'{{case.date_of_death|date:YYYY-MM-DD}}' => 'Beispiel: Datum im ISO-Format JJJJ-MM-TT',
			'{{recipient.name}}' => 'Name des Empfängers', '{{recipient.address}}' => 'Anschrift des Empfängers',
			'{{advance.application}}' => 'Vorschussantrag Witwe/Witwer (Ja/Nein)', '{{advance.applicant_name}}' => 'Name Antragsteller/in Vorschuss',
			'{{advance.applicant_birth_date}}' => 'Geburtsdatum Antragsteller/in Vorschuss', '{{advance.applicant_address}}' => 'Anschrift Antragsteller/in Vorschuss',
			'{{advance.applicant_pension_number}}' => 'Renten-/Versicherungsnummer Antragsteller/in',
			// 0.75.2: verständliche Bezeichnungen für bisher nur technisch benannte Felder
			'{{anzeigende_anschrift}}' => 'Anschrift der anzeigenden Person (Sterbefallanzeige)',
			'{{anzeigende_person}}' => 'Anzeigende Person (Sterbefallanzeige)',
			'{{auftrag_telefon}}' => 'Telefon der Auftraggeberin / des Auftraggebers',
			'{{branch.account_holder}}' => 'Kontoinhaber der Niederlassung',
			'{{branch.address}}' => 'Anschrift der Niederlassung',
			'{{branch.bic}}' => 'BIC der Niederlassung',
			'{{branch.iban}}' => 'IBAN der Niederlassung',
			'{{case.branch_city}}' => 'Ort der Niederlassung',
			'{{case.branch_email}}' => 'E-Mail der Niederlassung',
			'{{case.branch_phone}}' => 'Telefon der Niederlassung',
			'{{case.cemetery}}' => 'Friedhof (wie Friedhof aus Nextcloud-Kontakten)',
			'{{case.current_date}}' => 'Heutiges Datum (TT.MM.JJJJ)',
			'{{case.id}}' => 'Fallnummer (wie case.number)',
			'{{case.invoice_has_prior}}' => 'Frühere Teilrechnungen vorhanden (1 = ja)',
			'{{case.number}}' => 'Fallnummer',
			'{{case.order_client_email}}' => 'E-Mail Auftraggeber/in',
			'{{case.order_client_first_name}}' => 'Vorname Auftraggeber/in',
			'{{case.order_client_mobile}}' => 'Mobilnummer Auftraggeber/in',
			'{{case.order_client_name}}' => 'Nachname Auftraggeber/in',
			'{{case.order_client_phone}}' => 'Telefon Auftraggeber/in',
			'{{case.order_client_postal_city}}' => 'PLZ / Ort Auftraggeber/in',
			'{{case.order_client_relation}}' => 'Beziehung Auftraggeber/in zur verstorbenen Person',
			'{{case.order_client_street}}' => 'Straße Auftraggeber/in',
			'{{case.pension_number}}' => 'Postrentennummer (Abmeldung)',
			'{{case.pension_number_2}}' => 'Weitere Postrentennummer (Abmeldung)',
			'{{case.religion}}' => 'Religion',
			'{{client.name}}' => 'Vor- und Nachname Auftraggeber/in',
			'{{deposit.amount}}' => 'Vereinbarte Anzahlung',
			'{{ehe_register_hinweis}}' => 'Hinweis zum Eheregister',
			'{{eingang_am}}' => 'Eingangsvermerk (leer, zum Ausfüllen)',
			'{{geburtsregister_hinweis}}' => 'Hinweis zum Geburtsregister',
			'{{invoice.amount}}' => 'Rechnungsbetrag brutto',
			'{{invoice.due_date}}' => 'Fälligkeit der Rechnung',
			'{{invoice.number}}' => 'Rechnungsnummer',
			'{{lebenspartnerschaft_register_hinweis}}' => 'Hinweis zum Lebenspartnerschaftsregister',
			'{{minderjaehrige_kinder}}' => 'Anzahl minderjähriger Kinder',
			'{{nachweise}}' => 'Vorgelegte Nachweise / Urkunden',
			'{{order.certificate_free_count}}' => 'Anzahl gebührenfreie Sterbeurkunden',
			'{{order.client_phone}}' => 'Telefon Auftraggeber/in',
			'{{order.confirmation_text}}' => 'Bestätigungstext (Kostenvoranschlag bzw. Auftrag)',
			'{{order.document_title}}' => 'Dokumenttitel (Kostenvoranschlag / Bestattungsauftrag)',
			'{{order.invoice_recipient_address}}' => 'Anschrift Rechnungsempfänger/in',
			'{{order.kva_date}}' => 'Datum des Kostenvoranschlags',
			'{{order.kva_number}}' => 'Nummer des Kostenvoranschlags',
			'{{order.order_client_email}}' => 'E-Mail Auftraggeber/in',
			'{{order.order_client_mobile}}' => 'Mobilnummer Auftraggeber/in',
			'{{order.order_client_name}}' => 'Name Auftraggeber/in',
			'{{order.order_client_phone}}' => 'Telefon Auftraggeber/in',
			'{{order.order_client_relation}}' => 'Beziehung Auftraggeber/in',
			'{{order.order_signature_date}}' => 'Datum der Unterschrift',
			'{{order.order_signature_name}}' => 'Name der unterschreibenden Person',
			'{{order.status}}' => 'Auftragsstatus',
			'{{order.urkunden_gebuehrenpflichtig}}' => 'Anzahl gebührenpflichtige Sterbeurkunden',
			'{{order.workflow_detail}}' => 'Gültig bis (Kostenvoranschlag) bzw. Beauftragungsart (Auftrag)',
			'{{ort_datum}}' => 'Ort und Datum (Niederlassung, heute)',
			'{{pruefvermerk}}' => 'Prüfvermerk (leer, zum Ausfüllen)',
			'{{religion}}' => 'Religion',
			'{{source.title}}' => 'Titel des auslösenden Eintrags (Abläufe)',
			'{{sterberegister_nr}}' => 'Sterberegisternummer',
			'{{todesort_anschrift}}' => 'Anschrift des Sterbeorts',
			'{{urkunden_gebuehrenfrei}}' => 'Anzahl gebührenfreie Sterbeurkunden',
			'{{urkunden_gebuehrenpflichtig}}' => 'Anzahl gebührenpflichtige Sterbeurkunden',
		];
		$placeholders = array_values(array_unique(array_merge($placeholders, array_keys($special))));
		sort($placeholders, SORT_NATURAL | SORT_FLAG_CASE);
		$result = array_map(function(string $placeholder) use ($labels, $special): array {
			$key = trim($placeholder, '{}');
			$source = str_starts_with($key, 'case.') ? 'Fallstammdaten / berechnete Falldaten' : (str_starts_with($key, 'funeral_event.') ? 'Verknüpfter Trauerfeier-Termin' : (str_starts_with($key, 'service.') ? 'Leistungsposition' : (str_starts_with($key, 'order.') ? 'Auftrag / KVA' : (str_starts_with($key, 'recipient.') ? 'Empfänger der Abmeldung' : (str_starts_with($key, 'advance.') ? 'Rentenservice / Vorschussantrag' : (str_starts_with($key, 'source.') ? 'Workflow-Ausgangseintrag' : 'Berechnetes Feld'))))));
			$name = $labels[$placeholder] ?? $special[$placeholder] ?? ucfirst(str_replace(['case.', 'order.', 'service.', '_', '.'], ['', '', '', ' ', ' – '], $key));
			return ['placeholder' => $placeholder, 'name' => $name, 'source' => $source];
		}, $placeholders);
		// 0.76.0: Marker-Platzhalter (ein Eintrag je Marker, auch für inaktive).
		if ($this->labels !== null) {
			$known = array_column($result, 'placeholder');
			foreach ($this->labels->placeholderCatalog() as $entry) if (!in_array($entry['placeholder'], $known, true)) $result[] = $entry;
		}
		return $result;
	}

	/** 0.77.1: frühere Platzhalternamen, die Vorlagen weiter verwenden dürfen (nicht im Feldkatalog). @return list<string> */
	public function legacyPlaceholders(): array {
		return $this->labels !== null ? $this->labels->legacyPlaceholders() : [];
	}

	public function csv(): string {
		$stream = fopen('php://temp', 'w+');
		fputcsv($stream, ['Platzhalter', 'Sprechender Klarname', 'Datenquelle'], ';');
		foreach ($this->fields() as $field) fputcsv($stream, [$field['placeholder'], $field['name'], $field['source']], ';');
		rewind($stream);
		return "\xEF\xBB\xBF" . (string)stream_get_contents($stream);
	}
}
