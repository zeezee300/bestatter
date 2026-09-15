<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

class TemplateFieldCatalogService {
	private const SCHEMA_FILE = __DIR__ . '/../../resources/case-field-schema.json';
	private const TEMPLATE_DIRECTORY = __DIR__ . '/../../resources/templates';
	private const PLACEHOLDER_SOURCES = [__DIR__ . '/DocumentService.php', __DIR__ . '/DeregistrationService.php', __DIR__ . '/WorkflowService.php'];

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
		$placeholders = array_values(array_unique(array_merge($placeholders, $matches[0] ?? [])));
		sort($placeholders, SORT_NATURAL | SORT_FLAG_CASE);
		$special = [
			'{{deceased_name}}' => 'Name der verstorbenen Person', '{{branch.name}}' => 'Niederlassung', '{{standesamt}}' => 'Standesamt',
			'{{case.pension_insurance_number}}' => 'Postrentennummer', '{{case.pension_insurance_number_2}}' => 'Weitere Postrentennummer',
			'{{case.invoice_number}}' => 'Rechnungsnummer', '{{case.invoice_date}}' => 'Rechnungsdatum', '{{case.invoice_due_date}}' => 'Fälligkeitsdatum der Rechnung', '{{case.invoice_service_period_from}}' => 'Leistungszeitraum von', '{{case.invoice_service_period_to}}' => 'Leistungszeitraum bis', '{{case.invoice_service_period}}' => 'Leistungszeitraum als Text',
			'{{case.invoice_type}}' => 'Rechnungsart (PARTIAL/FINAL)', '{{case.invoice_type_label}}' => 'Rechnungsart (Teilrechnung/Schlussrechnung)',
			'{{case.invoice_sequence}}' => 'Laufende Rechnungsfolge im Fall', '{{case.invoice_prior_gross}}' => 'Summe früherer, nicht stornierter Rechnungen', '{{case.invoice_current_gross}}' => 'Bruttobetrag dieser Rechnung',
			'{{case.invoice_recipient_name}}' => 'Name des Rechnungsempfängers', '{{case.invoice_recipient_address}}' => 'Anschrift des Rechnungsempfängers',
			'{{case.invoice_payment_reference}}' => 'Zahlungsreferenz', '{{case.invoice_qr_payload}}' => 'EPC-QR-Code-Zahlungsdaten',
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
		];
		$placeholders = array_values(array_unique(array_merge($placeholders, array_keys($special))));
		sort($placeholders, SORT_NATURAL | SORT_FLAG_CASE);
		return array_map(function(string $placeholder) use ($labels, $special): array {
			$key = trim($placeholder, '{}');
			$source = str_starts_with($key, 'case.') ? 'Fallstammdaten / berechnete Falldaten' : (str_starts_with($key, 'funeral_event.') ? 'Verknüpfter Trauerfeier-Termin' : (str_starts_with($key, 'service.') ? 'Leistungsposition' : (str_starts_with($key, 'order.') ? 'Auftrag / KVA' : (str_starts_with($key, 'recipient.') ? 'Empfänger der Abmeldung' : (str_starts_with($key, 'advance.') ? 'Rentenservice / Vorschussantrag' : (str_starts_with($key, 'source.') ? 'Workflow-Ausgangseintrag' : 'Berechnetes Feld'))))));
			$name = $labels[$placeholder] ?? $special[$placeholder] ?? ucfirst(str_replace(['case.', 'order.', 'service.', '_', '.'], ['', '', '', ' ', ' – '], $key));
			return ['placeholder' => $placeholder, 'name' => $name, 'source' => $source];
		}, $placeholders);
	}

	public function csv(): string {
		$stream = fopen('php://temp', 'w+');
		fputcsv($stream, ['Platzhalter', 'Sprechender Klarname', 'Datenquelle'], ';');
		foreach ($this->fields() as $field) fputcsv($stream, [$field['placeholder'], $field['name'], $field['source']], ';');
		rewind($stream);
		return "\xEF\xBB\xBF" . (string)stream_get_contents($stream);
	}
}
