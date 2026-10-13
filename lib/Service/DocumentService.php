<?php
declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\Files\Folder;
use OCP\Files\Conversion\IConversionManager;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserSession;

class DocumentService {
	/** Jurisdiction-dependent authority documents must not use a German fallback. */
	private const REGIONAL_TEMPLATE_REQUIRED = [
		'STERBEFALLANZEIGE_STANDESAMT',
	];
	private const STATUSES = ['ENTWURF', 'FINAL'];
	/** Only byte-identical former package defaults may be refreshed automatically. */
	public const REPLACEABLE_STANDARD_TEMPLATE_HASHES = [
		// 0.88.0: Die bisherige Standardvorlage (Kostenvoranschlag/Auftrag, 5b8c72…) wird durch „Bestattungsauftrag und Vollmacht“
		// ersetzt; ihr Layout lebt als KOSTENVORANSCHLAG.docx weiter.
		'BESTATTUNGSAUFTRAG.docx' => ['2aba502e61c9ee18af3be3da0926f903644cb1514e22f6fe614fea5f367792ad', '5b8c7241cecb29aad903d4342faaa18bf06a10139168e8015f75f2df899cab80'],
		// Package version 0.58.0. Individually changed invoice templates remain untouched.
		'RECHNUNG.docx' => ['94695c36a21897c5b60ac269830ceaad3af40f1dcd327b4bfe07540859582f41'],
		// 0.81.0: Abmelde- und Mitteilungsschreiben nennen „Unser Zeichen“ (Fallnummer). Unveränderte Vorgängerfassungen werden ersetzt.
		'RUNDFUNKBEITRAG_ABMELDUNG.docx' => ['cfdd239c992aa737db4428cc7d1179d7bcb6ddd42d7d55a5ee557b6bfceb6c7b'],
		'VERTRAGSABMELDUNG_STANDARD.docx' => ['791adeb37e5bdbb73d4a54420978f5937856eb37bc1d007af6979c6621f0de23'],
		'ARBEITGEBER_STERBEFALLMITTEILUNG.docx' => ['155ff57b6c65350bb54ff6ee288883c6b6ca784f86ddce1765ad4c7e0b64537c'],
		'NACHLASSMITTEILUNG_BANK.docx' => ['94881b87791c60ebfd98cbcbe9281f97f043b2f34d95693dc1d444936d64083e'],
		'ABMELDUNG_STANDARD_BRIEF.txt' => ['241f503dad223ed60c949df1fce1a8cd96038890ff3358bf1a817d36056bbd27'],
		'ABMELDUNG_STANDARD_EMAIL.txt' => ['0a9217aae6c9056744bcefc967cc7ef41c92c76d5172f20130053551f394c5a0'],
		'RENTENSERVICE_ABMELDUNG.txt' => ['06b466334c3401e91c29e4dcdf2d276d6609ad36336d13758eddf220218cb735'],
	];

	public function __construct(
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private FolderService $folders,
		private ArticleService $articles,
		private ConfigurationService $configuration,
		private IConversionManager $conversionManager,
		private EuroOfficeConversionService $euroOffice,
		private EInvoiceService $eInvoices,
		private EInvoiceComplianceService $eInvoiceCompliance,
		private IDBConnection $db,
		private InstallationConfigService $installationConfig,
		private CountryConfigurationService $countryConfiguration,
		private FacturXPdfService $facturX,
		private ?BranchLogoService $logos = null,
		private ?LabelService $labels = null,
		private ?CustomizingService $customizing = null,
	) {}

	/** 0.88.0: Platzhalter, an dem die zusammengefasste Vorlage „Bestattungsauftrag und Vollmacht“ erkannt wird. */
	public const COMBINED_ORDER_MARKER = '{{order.poa_registry}}';
	/** 0.88.0: Vertragsschluss im Auftrag (order_contract_conclusion); außerhalb und Fernabsatz → Widerrufsbelehrung. */
	public const CONTRACT_CONCLUSIONS = ['GESCHAEFTSRAEUME' => 'in den Geschäftsräumen', 'AUSSERHALB' => 'außerhalb der Geschäftsräume (z. B. Hausbesuch, Klinik, Heim)', 'FERNABSATZ' => 'im Fernabsatz (Telefon, E-Mail, online)'];
	private ?bool $combinedOrderTemplateCache = null;

	/** 0.73.0: set only while the binding invoice document is rendered; asks the converter for PDF/A first. */
	private bool $preferPdfA = false;

	public function generateOrderDocuments(array $case, string $status = 'ENTWURF', bool $createPdf = true): array {
		$keys = $this->orderDocumentKeys($case);
		return array_map(fn(string $key): array => $this->generateTemplate($case, $key, $status, $createPdf), $keys);
	}

	/** @return list<string> */
	public function orderDocumentKeys(array $case): array {
		if (strtoupper((string)($case['masterData']['order_mode'] ?? 'A')) === 'KVA') return ['BESTATTUNGSAUFTRAG'];
		// 0.88.0: Die Standardvorlage enthält die Vollmacht. Eine eigene, ältere Auftragsvorlage ohne Vollmachtsteil erhält
		// weiterhin die gesonderte Bestattungsvollmacht. Die Widerrufsbelehrung kommt nur bei Vertragsschluss außerhalb der
		// Geschäftsräume oder im Fernabsatz hinzu.
		$keys = ['BESTATTUNGSAUFTRAG'];
		if (!$this->combinedOrderTemplate() && $this->templateActive('BESTATTUNGSVOLLMACHT')) $keys[] = 'BESTATTUNGSVOLLMACHT';
		if (self::withdrawalApplies($case['masterData'] ?? []) && $this->templateActive('WIDERRUFSBELEHRUNG')) $keys[] = 'WIDERRUFSBELEHRUNG';
		return $keys;
	}

	/** 0.88.0: Dokumente des Auftragspakets mit Bezeichnung (für Vorschau und Entwurfspaket in der Oberfläche). */
	public function orderDocumentList(array $case): array {
		$isEstimate = strtoupper((string)($case['masterData']['order_mode'] ?? 'A')) === 'KVA';
		return array_map(fn(string $key): array => ['key' => $key, 'title' => match ($key) {
			'BESTATTUNGSAUFTRAG' => $isEstimate ? 'Kostenvoranschlag' : ($this->combinedOrderTemplate() ? 'Bestattungsauftrag und Vollmacht' : 'Bestattungsauftrag'),
			'BESTATTUNGSVOLLMACHT' => 'Bestattungsvollmacht',
			'WIDERRUFSBELEHRUNG' => 'Widerrufsbelehrung',
			default => $key,
		}], $this->orderDocumentKeys($case));
	}

	public static function withdrawalApplies(array $master): bool {
		return in_array(strtoupper(trim((string)($master['order_contract_conclusion'] ?? ''))), ['AUSSERHALB', 'FERNABSATZ'], true);
	}

	private function templateActive(string $key): bool {
		foreach ($this->configuration->documents() as $template) if ($template['key'] === $key) return (bool)$template['active'];
		return false;
	}

	/** Enthält die eingerichtete Auftragsvorlage den Vollmachtsteil (Standardvorlage ab 0.88.0)? */
	public function combinedOrderTemplate(): bool {
		if ($this->combinedOrderTemplateCache !== null) return $this->combinedOrderTemplateCache;
		try {
			$fileName = '';
			foreach ($this->configuration->documents() as $template) if ($template['key'] === 'BESTATTUNGSAUFTRAG') $fileName = $this->resolveConfiguredTemplateName((string)$template['fileName']);
			if ($fileName === '') return $this->combinedOrderTemplateCache = false;
			$folder = $this->installationConfig->ensurePath($this->folders->baseFolder(), $this->installationConfig->templatesPath());
			$content = (string)$this->template($folder, $fileName)->getContent();
			return $this->combinedOrderTemplateCache = self::docxContainsText($content, self::COMBINED_ORDER_MARKER);
		} catch (\Throwable) {
			return $this->combinedOrderTemplateCache = false;
		}
	}

	public static function docxContainsText(string $docx, string $needle): bool {
		$temp = tempnam(sys_get_temp_dir(), 'bestatter-docx-');
		if ($temp === false) return false;
		try {
			file_put_contents($temp, $docx);
			$zip = new \ZipArchive();
			if ($zip->open($temp) !== true) return false;
			$xml = (string)$zip->getFromName('word/document.xml');
			$zip->close();
			return str_contains(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'), $needle);
		} finally {
			@unlink($temp);
		}
	}

	/**
	 * Build a PDF entirely as a short-lived preview. No case-file output or
	 * document record is created; the temporary DOCX and converter output are
	 * removed before the response leaves the service.
	 *
	 * @return array{content:string,fileName:string,title:string,templateKey:string}
	 */
	public function previewOrderDocumentPdf(array $case, string $templateKey): array {
		$templateKey = strtoupper(trim($templateKey));
		if (!in_array($templateKey, $this->orderDocumentKeys($case), true)) {
			throw new \InvalidArgumentException('Die angeforderte Vorlage gehört nicht zum aktuellen KVA-/Auftragspaket.');
		}
		if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('Die PHP-Erweiterung ZipArchive wird für DOCX-Vorlagen benötigt.');
		$definition = $this->definition($templateKey, $case);
		if (!$definition['supportsPdf'] || !$this->pdfAvailable()) {
			throw new \RuntimeException('Für diese Dokumentvorschau ist derzeit keine PDF-Konvertierung verfügbar.');
		}
		$userFolder = $this->folders->baseFolder();
		$templateFolder = $this->installationConfig->ensurePath($userFolder, $this->installationConfig->templatesPath());
		$this->seedEstimateTemplate($templateFolder, $definition);
		$template = $this->template(
			$templateFolder,
			$definition['fileName'],
			(string)($definition['templateNamespace'] ?? ''),
			(bool)($definition['regionalTemplateRequired'] ?? false),
		);
		$costing = $this->articles->caseServices((int)$case['id']);
		$content = $this->replaceDocx((string)$template->getContent(), $this->placeholderData($case, $costing), $costing['items'] ?? [], null, $this->caseBranchKey($case));
		$tempName = '.bestatter-preview-' . bin2hex(random_bytes(12)) . '.docx';
		$temp = $templateFolder->newFile($tempName);
		$temp->putContent($content);
		$converted = null;
		try {
			$pdfContent = null;
			$providerError = null;
			if ($this->conversionManager->hasProviders()) {
				try {
					$path = $this->conversionManager->convert($temp, 'application/pdf');
					$converted = $this->rootFolder->get($path);
					if ($converted instanceof \OCP\Files\File) $pdfContent = (string)$converted->getContent();
				} catch (\Throwable $error) {
					$providerError = $error;
				}
			}
			if (($pdfContent === null || !str_starts_with($pdfContent, '%PDF-')) && $this->euroOffice->isAvailable()) {
				$pdfContent = $this->euroOffice->convert($temp);
			}
			if ($pdfContent === null || !str_starts_with($pdfContent, '%PDF-')) {
				if ($providerError !== null) throw new \RuntimeException('Die Nextcloud-PDF-Konvertierung ist fehlgeschlagen: ' . $providerError->getMessage(), 0, $providerError);
				throw new \RuntimeException('Weder Nextcloud noch EuroOffice stellen eine automatische PDF-Konvertierung bereit.');
			}
			return [
				'content' => $pdfContent,
				'fileName' => $this->safeOutputTitle((string)$definition['title']) . ' - ' . (string)$case['caseNumber'] . ' - Vorschau.pdf',
				'title' => (string)$definition['title'],
				'templateKey' => $templateKey,
			];
		} finally {
			if ($converted instanceof \OCP\Files\File && $converted->getId() !== $temp->getId()) {
				try { $converted->delete(); } catch (\Throwable) {}
			}
			try { $temp->delete(); } catch (\Throwable) {}
		}
	}

	/**
	 * Render an arbitrary configured DOCX template as a short-lived PDF
	 * preview, filled with realistic dummy content instead of real case data.
	 * Used by the Customizing "Dokumentenvorlagen" editor so an administrator
	 * can see how a template actually looks (layout, tables, wording) before
	 * assigning it to a case — independent of any real case, and independent
	 * of whether the template definition itself has already been saved (the
	 * editor lets an admin pick a fileName before saving the surrounding
	 * template entry). No case-file output, no document record, and no
	 * business-object side effects of any kind are created; the temporary
	 * DOCX and converter output are removed before the response leaves the
	 * service, exactly like previewOrderDocumentPdf().
	 *
	 * @return array{content:string,fileName:string,title:string,templateKey:string}
	 */
	public function previewTemplateFilePdf(string $fileName): array {
		$fileName = basename(trim($fileName));
		if ($fileName === '' || !preg_match('/\.docx$/i', $fileName)) throw new \InvalidArgumentException('Bitte eine DOCX-Datei angeben.');
		if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('Die PHP-Erweiterung ZipArchive wird für DOCX-Vorlagen benötigt.');
		if (!$this->pdfAvailable()) throw new \RuntimeException('Für diese Dokumentvorschau ist derzeit keine PDF-Konvertierung verfügbar.');
		$userFolder = $this->folders->baseFolder();
		$templateFolder = $this->installationConfig->ensurePath($userFolder, $this->installationConfig->templatesPath());
		$resolvedFileName = $this->resolveConfiguredTemplateName($fileName);
		if (!$templateFolder->nodeExists($resolvedFileName) && !is_file(__DIR__ . '/../../resources/templates/' . $resolvedFileName)) {
			throw new \InvalidArgumentException('Die ausgewählte Vorlagendatei wurde im Vorlagenordner nicht gefunden.');
		}
		$template = $this->template($templateFolder, $resolvedFileName);
		[$case, $costing] = $this->dummyPreviewData();
		$content = $this->replaceDocx((string)$template->getContent(), $this->placeholderData($case, $costing), $costing['items'], null, $this->caseBranchKey($case));
		$tempName = '.bestatter-preview-' . bin2hex(random_bytes(12)) . '.docx';
		$temp = $templateFolder->newFile($tempName);
		$temp->putContent($content);
		$converted = null;
		try {
			$pdfContent = null;
			$providerError = null;
			if ($this->conversionManager->hasProviders()) {
				try {
					$path = $this->conversionManager->convert($temp, 'application/pdf');
					$converted = $this->rootFolder->get($path);
					if ($converted instanceof \OCP\Files\File) $pdfContent = (string)$converted->getContent();
				} catch (\Throwable $error) {
					$providerError = $error;
				}
			}
			if (($pdfContent === null || !str_starts_with($pdfContent, '%PDF-')) && $this->euroOffice->isAvailable()) {
				$pdfContent = $this->euroOffice->convert($temp);
			}
			if ($pdfContent === null || !str_starts_with($pdfContent, '%PDF-')) {
				if ($providerError !== null) throw new \RuntimeException('Die Nextcloud-PDF-Konvertierung ist fehlgeschlagen: ' . $providerError->getMessage(), 0, $providerError);
				throw new \RuntimeException('Weder Nextcloud noch EuroOffice stellen eine automatische PDF-Konvertierung bereit.');
			}
			return [
				'content' => $pdfContent,
				'fileName' => pathinfo($resolvedFileName, PATHINFO_FILENAME) . ' - Vorschau mit Beispieldaten.pdf',
				'title' => pathinfo($resolvedFileName, PATHINFO_FILENAME),
				'templateKey' => '',
			];
		} finally {
			if ($converted instanceof \OCP\Files\File && $converted->getId() !== $temp->getId()) {
				try { $converted->delete(); } catch (\Throwable) {}
			}
			try { $temp->delete(); } catch (\Throwable) {}
		}
	}

	/**
	 * A self-contained, fictitious "Beispielfall" covering the full known
	 * placeholder catalog (resources/case-field-schema.json plus the
	 * business-specific Auftrags-, Trauerfeier- und Rechnungsfelder that are
	 * normally only computed for a real case), so that any active template —
	 * order/KVA, Rechnung, Sterbefallanzeige, Abmeldungen, etc. — renders
	 * with plausible, clearly-marked-as-example content rather than empty
	 * merge fields. Uses the first active branch, if one is configured, so
	 * that branch contact data (phone/e-mail/Ort) reflects the real
	 * installation; all other fields are deliberately fictitious.
	 *
	 * @return array{0:array,1:array}
	 */
	private function dummyPreviewData(): array {
		$branchKey = '';
		foreach ($this->configuration->branches() as $branch) {
			if (!empty($branch['active'])) { $branchKey = (string)$branch['key']; break; }
		}
		$masterData = [
			'additional_first_names' => 'Elisabeth Johanna',
			'birth_name' => 'Schmidt',
			'profession' => 'Bäckermeisterin i. R.',
			'birth_place' => 'Bremen',
			'birth_registry_office' => 'Standesamt Bremen',
			'branch' => 'Musterhausen',
			'case_number' => '2026-0000',
			'civil_status' => 'Verheiratet, jetzt verwitwet',
			'spouse_first_name' => 'Hans',
			'spouse_last_name' => 'Mustermann',
			'spouse_date_of_birth' => '1945-01-20',
			'spouse_birth_place' => 'Bremerhaven',
			'spouse_residence' => 'Musterstraße 12, 28195 Bremen',
			'spouse_date_of_death' => '2018-11-03',
			'spouse_death_place' => 'Bremen',
			'marriage_date' => '1970-06-17',
			'marriage_place' => 'Bremen',
			'partnership_date' => '',
			'divorce_date' => '',
			'close_reason' => 'Fall abgeschlossen',
			'closed_at' => '',
			'coffin_urn_size' => 'Sarggröße 200 x 60 cm',
			'current_body_location' => 'Kühlraum des Bestattungsinstituts',
			'date_of_birth' => '1948-03-12',
			'date_of_death' => '2026-09-08',
			'death_certificate_status' => 'Vorläufige Todesbescheinigung liegt vor',
			'death_time_mode' => 'Exakter Zeitpunkt bekannt',
			'death_determination' => 'Durch Notarzt festgestellt',
			'death_time_from' => '',
			'death_time_to' => '',
			'first_name' => 'Maria',
			'found_at' => '',
			'funeral_notes' => 'Die Verstorbene wünschte eine schlichte, würdevolle Feier im engsten Familienkreis.',
			'funeral_type' => 'Erdbestattung',
			'gender' => 'weiblich',
			'grave_depth' => '1,80 m',
			'grave_holder' => 'Peter Mustermann',
			'grave_inscription' => 'Maria Mustermann geb. Schmidt * 12.03.1948 † 08.09.2026',
			'grave_location' => 'Feld 7, Reihe 3',
			'grave_number' => '0142',
			'grave_type' => 'Wahlgrab (Einzelgrab)',
			'guardian_email' => 'betreuer@beispiel-kanzlei.de',
			'guardian_name' => 'Rechtsanwalt Thomas Berger',
			'guardian_phone' => '0421 555123',
			'guardian_postal_city' => '28203 Bremen',
			'guardian_street' => 'Kanzleiweg 4',
			'guardianship_court' => 'Amtsgericht Bremen',
			'guardianship_notes' => 'Betreuung umfasste Vermögenssorge und Gesundheitsfürsorge.',
			'guardianship_status' => 'Betreuung war eingerichtet',
			'identity_document_issue_date' => '2019-04-02',
			'identity_document_issuer' => 'Stadtamt Bremen',
			'identity_document_number' => 'L01X00T47',
			'identity_document_type' => 'Personalausweis',
			'last_name' => 'Mustermann',
			'last_partnership_name' => '',
			'last_partnership_place' => '',
			'last_residence' => 'Musterstraße 12',
			'manner_of_death' => 'Natürlicher Tod',
			'medical_examiner' => 'Dr. med. Julia Cordes',
			'nationality' => 'Deutsch',
			'newborn_lifetime_hours' => '',
			'notes' => 'Interne Testnotiz für die Dokumentenvorschau, keine echten Falldaten.',
			'other_previous_names' => '',
			'place_found' => '',
			'place_of_death' => 'Klinikum Bremen-Mitte',
			'registry_due_date' => '2026-09-11',
			'registry_office' => 'Standesamt Bremen',
			'registry_reference' => 'StA Bremen, Nr. 1234/2026',
			'registry_status' => 'Angemeldet',
			'release_status' => 'Freigegeben durch Bestattungsarzt',
			'religion' => 'Evangelisch-lutherisch',
			'religion_disclosure' => 'Wird auf Wunsch der Angehörigen genannt',
			'responsible_employee' => 'Sabine Kowalski',
			'salutation' => 'Frau',
			'status' => 'In Bearbeitung',
			'time_of_death' => '06:42',
			'title' => '',
			'transfer_from' => 'Klinikum Bremen-Mitte',
			'transfer_status' => 'Abgeschlossen',
			'transfer_to' => 'Trauerhalle Friedhof Riensberg',
			'urn_handover_date' => '2026-09-22',
			'urn_handover_type' => 'Persönliche Übergabe an Angehörige',
			'cemetery_contact' => 'Friedhof Riensberg',
			'last_residence_postal_code' => '28195',
			'last_residence_city' => 'Bremen',
			'last_residence_country' => 'Deutschland',
			'guardian_country' => 'Deutschland',
			'order_client_country' => 'Deutschland',
			'invoice_country' => 'Deutschland',
			'broadcast_contribution_number' => '123456789',
			'contract_reference' => 'K-2026-9911',
			'bank_customer_reference' => 'KD-445566',
			'employer_reference' => 'PNR-778899',
			'recipient_name' => 'Stadtamt Bremen',
			'recipient_address' => 'Contrescarpe 22, 28203 Bremen',
			'payment_method' => 'TRANSFER',
			'sepa_mandate_reference' => 'MANDAT-2026-0000',
			'sepa_mandate_date' => '2026-09-09',
			'sepa_collection_date' => '2026-09-25',
			'sepa_debtor_name' => 'Peter Mustermann',
			'sepa_debtor_iban' => 'DE02120300000000202051',
			'sepa_debtor_bic' => 'BYLADEM1001',
			'order_mode' => 'A',
			'order_status' => 'Freigegeben',
			'order_client_first_name' => 'Peter',
			'order_client_name' => 'Mustermann',
			'order_client_relation' => 'Sohn',
			'order_client_phone' => '0421 9988770',
			'order_client_mobile' => '0170 1234567',
			'order_client_email' => 'peter.mustermann@beispiel.de',
			'order_client_street' => 'Musterstraße 12',
			'order_client_postal_city' => '28195 Bremen',
			'order_number' => 'A-2026-0000',
			'order_date' => '2026-09-09',
			'commissioning_type' => 'SCHRIFTLICH',
			'order_signature_name' => 'Peter Mustermann',
			'order_signature_date' => '2026-09-09',
			'certificate_free_count' => '2',
			'urkunden_gebuehrenpflichtig' => '3',
			'kva_number' => 'K-2026-0000',
			'kva_date' => '2026-09-09',
			'kva_valid_until' => '2026-10-09',
			'funeral_event_date' => '2026-09-20',
			'funeral_event_date_iso' => '2026-09-20',
			'funeral_event_time' => '11:00',
			'funeral_event_end_time' => '12:00',
			'funeral_event_location' => 'Trauerhalle Friedhof Riensberg, Bremen',
			'funeral_event_category' => 'Trauerfeier',
			'funeral_event_external_participants' => 'Pastor Michael Weber',
			'invoice_number' => 'R-2026-0042',
			'invoice_type' => 'FINAL',
			'invoice_type_label' => 'Schlussrechnung',
			'invoice_sequence' => '2',
			'invoice_has_prior' => '1',
			'invoice_prior_gross' => '500,00 EUR',
			'invoice_current_gross' => '2.093,10 EUR',
			'invoice_date' => '2026-09-15',
			'invoice_due_date' => '2026-09-29',
			'invoice_service_period_from' => '2026-09-08',
			'invoice_service_period_to' => '2026-09-20',
			'invoice_service_period' => '08.09.2026 bis 20.09.2026',
			'invoice_recipient_name' => 'Peter Mustermann',
			'invoice_recipient_address' => 'Musterstraße 12, 28195 Bremen',
			'invoice_payment_reference' => 'Rechnung R-2026-0042',
			'invoice_qr_payload' => '',
			'invoice_payment_method' => 'Überweisung',
			'invoice_payment_instruction' => 'Bitte überweisen Sie den Rechnungsbetrag unter Angabe der Rechnungsnummer bis zum Fälligkeitsdatum auf unser Geschäftskonto.',
			'invoice_payment_qr_label' => 'KEIN ZAHLUNGS-QR',
			'invoice_reference_label' => 'R-2026-0041 vom 01.09.2026', 'invoice_reference_number' => 'R-2026-0041', 'invoice_reference_date' => '01.09.2026',
			'invoice_correction_reason' => 'Fehlerhafte Menge', 'invoice_correction_title' => 'Gutschrift (Rechnungskorrektur)', 'invoice_correction_amount' => '119,00 EUR',
			'branch_creditor_id' => 'DE98ZZZ09999999999',
			'branch_name' => 'Bestattungshaus Musterhausen',
			'branch_address' => 'Beispielallee 5, 28195 Bremen',
			'bank_account_holder' => 'Bestattungshaus Musterhausen GmbH',
			'bank_name' => 'Beispiel-Sparkasse Bremen',
			'bank_iban' => 'DE44500105175407324931',
			'bank_bic' => 'INGDDEFFXXX',
			'branch_vat_id' => 'DE123456789',
			'branch_tax_number' => '60/123/45678',
			'branch_register' => 'Amtsgericht Bremen HRB 12345',
			'branch_managing_directors' => 'Sabine Kowalski, Peter Nowak',
		];
		$case = [
			'id' => 0, 'caseNumber' => '2026-0000', 'firstName' => 'Maria', 'lastName' => 'Mustermann',
			'dateOfDeath' => '2026-09-08', 'branch' => $branchKey, 'masterData' => $masterData,
		];
		$items = [
			['articleNumber' => '100010', 'title' => 'Sarg "Eiche classic", inkl. Ausstattung', 'quantity' => 1.0, 'unit' => 'STK', 'unitPriceCents' => 89000, 'vatRate' => 19, 'netCents' => 89000, 'vatCents' => 16910, 'grossCents' => 105910, 'positionType' => 'EL', 'sourcePackageName' => ''],
			['articleNumber' => '100450', 'title' => 'Organisation und Durchführung der Trauerfeier', 'quantity' => 1.0, 'unit' => 'PAUSCHAL', 'unitPriceCents' => 45000, 'vatRate' => 19, 'netCents' => 45000, 'vatCents' => 8550, 'grossCents' => 53550, 'positionType' => 'EL', 'sourcePackageName' => ''],
			['articleNumber' => '200120', 'title' => 'Blumenschmuck (Fremdleistung Floristik Beispiel GmbH)', 'quantity' => 1.0, 'unit' => 'STK', 'unitPriceCents' => 15000, 'vatRate' => 19, 'netCents' => 15000, 'vatCents' => 2850, 'grossCents' => 17850, 'positionType' => 'FK', 'sourcePackageName' => ''],
			['articleNumber' => '300300', 'title' => 'Friedhofsgebühren (durchlaufender Posten)', 'quantity' => 1.0, 'unit' => 'PAUSCHAL', 'unitPriceCents' => 32000, 'vatRate' => 0, 'netCents' => 0, 'vatCents' => 0, 'grossCents' => 32000, 'positionType' => 'DP', 'sourcePackageName' => ''],
		];
		$costing = [
			'items' => $items,
			'totals' => ['netCents' => 149000, 'vatCents' => 28310, 'grossCents' => 209310],
			'invoiceTaxableNetCents' => 149000,
			'invoicePassThroughCents' => 32000,
		];
		return [$case, $costing];
	}

	/**
	 * Generate a complete draft package under unique staging names. Existing
	 * drafts are deliberately left untouched until the controller committed all
	 * document records.
	 *
	 * @return array{packageId:string,files:list<array>}
	 */
	public function generateOrderDocumentDraftPackage(array $case, bool $createPdf = true): array {
		$this->assertOrderDraftReplaceable($case);
		$packageId = date('YmdHis') . '-' . bin2hex(random_bytes(4));
		$files = [];
		try {
			foreach ($this->orderDocumentKeys($case) as $key) {
				$file = $this->generateTemplate($case, $key, 'ENTWURF', $createPdf, false, null, 0, null, 'Entwurf - Paket ' . $packageId, false);
				$file['packageId'] = $packageId;
				$file['packageDocumentCount'] = count($this->orderDocumentKeys($case));
				$files[] = $file;
			}
		} catch (\Throwable $error) {
			foreach ($files as $file) $this->discardGeneratedOutput($file);
			throw new \RuntimeException('Das Entwurfspaket konnte nicht vollständig erzeugt werden: ' . $error->getMessage(), 0, $error);
		}
		return ['packageId' => $packageId, 'files' => $files];
	}

	/** @return list<string> */
	public function cleanupSupersededOrderDrafts(array $case, array $keepFiles): array {
		$keepIds = [];
		foreach ($keepFiles as $file) {
			foreach ([(int)($file['fileId'] ?? 0), (int)($file['pdf']['fileId'] ?? 0)] as $id) if ($id > 0) $keepIds[$id] = true;
		}
		$warnings = [];
		foreach ($this->orderOutputNodes($case) as $node) {
			$name = $node->getName();
			if (isset($keepIds[(int)$node->getId()]) || !str_contains($name, ' - Entwurf')) continue;
			try { $node->delete(); } catch (\Throwable $error) { $warnings[] = $name . ': ' . $error->getMessage(); }
		}
		return $warnings;
	}

	private function assertOrderDraftReplaceable(array $case): void {
		foreach ($this->orderOutputNodes($case) as $node) {
			$name = $node->getName();
			if (str_contains($name, ' - Final.') || str_contains($name, ' - Unterschrieben.')) {
				throw new \InvalidArgumentException('Das aktuelle Dokument ist final und kann nicht durch einen Entwurf ersetzt werden.');
			}
		}
	}

	/** @return list<mixed> */
	private function orderOutputNodes(array $case): array {
		$nodes = [];
		foreach ($this->orderDocumentKeys($case) as $key) {
			$definition = $this->definition($key, $case);
			$folder = $this->folders->caseSubfolder((string)$case['caseNumber'], $definition['subfolder']);
			$prefix = sprintf('%s - %s - ', $this->safeOutputTitle((string)$definition['title']), $case['caseNumber']);
			foreach ($folder->getDirectoryListing() as $node) if (str_starts_with($node->getName(), $prefix)) $nodes[(int)$node->getId()] = $node;
		}
		return array_values($nodes);
	}

	public function invoicePaymentData(array $case, array $invoice, bool $includeInternal = false): array {
		$master = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$branch = $this->configuration->branchByKey((string)($case['branch'] ?? $master['branch'] ?? '')) ?? [];
		$settings = $this->configuration->invoiceSettings();
		$snapshot = is_array($invoice['paymentSnapshot'] ?? null) ? $invoice['paymentSnapshot'] : [];
		$hasSnapshot = ($snapshot['schema'] ?? '') === 'bestatter-invoice-payment/1';
		$branchStandard = strtoupper(trim((string)($branch['paymentQrStandard'] ?? 'EPC069-12')));
		$paymentQrStandard = $hasSnapshot
			? strtoupper(trim((string)($snapshot['qrStandard'] ?? 'NONE')))
			: (($settings['qrEnabled'] ?? false) && $branchStandard === 'EPC069-12' ? 'EPC069-12' : 'NONE');
		$paymentBranch = $branch;
		if ($hasSnapshot) foreach (['accountHolder', 'bankName', 'iban', 'bic', 'creditorId', 'paymentTermDays'] as $field) $paymentBranch[$field] = $snapshot[$field] ?? $paymentBranch[$field] ?? '';
		$invoiceDate = substr((string)($invoice['createdAt'] ?? date('c')), 0, 10);
		$dueDate = date('Y-m-d', strtotime($invoiceDate . ' +' . (int)($paymentBranch['paymentTermDays'] ?? 14) . ' days'));
		$method = strtoupper(trim((string)($hasSnapshot ? ($snapshot['method'] ?? 'TRANSFER') : ($master['payment_method'] ?? 'TRANSFER'))));
		if (!in_array($method, ['TRANSFER', 'SEPA_DIRECT_DEBIT'], true)) $method = 'TRANSFER';
		$reference = trim((string)($hasSnapshot ? ($snapshot['mandateReference'] ?? '') : ($master['sepa_mandate_reference'] ?? '')));
		$collectionDate = trim((string)($hasSnapshot ? ($snapshot['collectionDate'] ?? '') : ($master['sepa_collection_date'] ?? ''))) ?: $dueDate;
		$instruction = $method === 'SEPA_DIRECT_DEBIT'
			? sprintf(
				'Der Rechnungsbetrag wird aufgrund des SEPA-Lastschriftmandats %s zum %s eingezogen. Gläubiger-ID: %s. Bitte veranlassen Sie keine Überweisung.',
				$reference,
				$this->formatDocumentDate($collectionDate),
				trim((string)($paymentBranch['creditorId'] ?? '')),
			)
			: 'Bitte überweisen Sie den Rechnungsbetrag bis zum ' . $this->formatDocumentDate($dueDate) . ' unter Angabe der Rechnungsnummer.';
		// 0.70.0: Stornorechnung/Gutschrift – keine Zahlungsaufforderung und kein Zahlcode, sondern Erstattung bzw. Verrechnung.
		if (($invoice['documentKind'] ?? 'INVOICE') !== 'INVOICE') {
			$result = ['method' => 'REFUND', 'methodLabel' => 'Erstattung / Verrechnung', 'dueDate' => $invoiceDate, 'collectionDate' => '',
				'instruction' => (($invoice['documentKind'] ?? '') === 'CANCELLATION' ? 'Diese Stornorechnung hebt die Rechnung ' . (string)($invoice['referenceInvoiceNumber'] ?? '') . ' vollständig auf.' : 'Diese Gutschrift mindert die Rechnung ' . (string)($invoice['referenceInvoiceNumber'] ?? '') . ' (Rechnungskorrektur des leistenden Unternehmers, keine Gutschrift im Sinne des § 14 Abs. 2 Satz 2 UStG).') . ' Bereits gezahlte Beträge erstatten wir auf das uns bekannte Konto oder verrechnen sie mit offenen Forderungen.',
				'qrPayload' => '', 'qrRequired' => false, 'qrStandard' => 'NONE', 'qrReason' => 'CORRECTION', 'snapshotUsed' => $hasSnapshot];
			if ($includeInternal) $result['paymentBranch'] = $paymentBranch;
			return $result;
		}
		$qrPayload = $method === 'TRANSFER' && $paymentQrStandard === 'EPC069-12' ? $this->eInvoices->epcPayload($invoice + ['dueDate' => $dueDate], $paymentBranch) : '';
		if ($method === 'TRANSFER' && $paymentQrStandard === 'EPC069-12' && $qrPayload === '') throw new \InvalidArgumentException('Der aktivierte EPC-SEPA-Zahlcode kann ohne gültige Bankverbindung nicht erzeugt werden. Die Rechnung wurde nicht ohne QR-Code ausgegeben.');
		$qrReason = $qrPayload !== '' ? 'INCLUDED' : ($method === 'SEPA_DIRECT_DEBIT' ? 'DIRECT_DEBIT' : 'DISABLED');
		$result = [
			'method' => $method,
			'methodLabel' => $method === 'SEPA_DIRECT_DEBIT' ? 'SEPA-Lastschrift' : 'Überweisung',
			'dueDate' => $dueDate,
			'collectionDate' => $collectionDate,
			'instruction' => $instruction,
			'qrPayload' => $qrPayload,
			'qrRequired' => $qrPayload !== '',
			'qrStandard' => $paymentQrStandard,
			'qrReason' => $qrReason,
			'snapshotUsed' => $hasSnapshot,
		];
		if ($includeInternal) $result['paymentBranch'] = $paymentBranch;
		return $result;
	}

	/**
	 * Build the shared, non-persisting render inputs for an invoice document:
	 * branch/recipient/service-period validation, master-data merge, line
	 * items, and the payment QR. Reused by the binding generateInvoice()
	 * (which persists the closing document) and by the read-only
	 * previewInvoiceDocumentPdf() (which never writes anything), so both stay
	 * consistent without duplicating the validation and business rules.
	 *
	 * @return array{case:array,items:list<array>,taxableNet:int,passThrough:int,qrPayload:string,paymentQrPng:?string,documentStatus:string,isReviewDocument:bool,payment:array,branch:array,invoice:array}
	 */
	private function invoiceRenderContext(array $case, array $invoice): array {
		$recipient = $invoice['recipient'] ?? [];
		$settings = $this->configuration->invoiceSettings();
		$branch = $this->configuration->branchByKey((string)($case['branch'] ?? $case['masterData']['branch'] ?? '')) ?? [];
		$payment = $this->invoicePaymentData($case, $invoice, true);
		$paymentBranch = is_array($payment['paymentBranch'] ?? null) ? $payment['paymentBranch'] : $branch;
		$branch = array_merge($branch, array_intersect_key($paymentBranch, array_flip(['accountHolder', 'bankName', 'iban', 'bic', 'creditorId', 'paymentTermDays'])));
		$requiredBranch = ['name' => 'Niederlassungsname', 'street' => 'Absenderstraße', 'postalCode' => 'Absender-PLZ', 'city' => 'Absenderort', 'accountHolder' => 'Kontoinhaber', 'bankName' => 'Bankname', 'iban' => 'IBAN', 'bic' => 'BIC'];
		$missingBranch = [];
		foreach ($requiredBranch as $field => $label) if (trim((string)($branch[$field] ?? '')) === '') $missingBranch[] = $label;
		if (trim((string)($branch['taxNumber'] ?? '')) === '' && trim((string)($branch['vatId'] ?? '')) === '') $missingBranch[] = 'Steuernummer oder USt-IdNr.';
		if ($missingBranch !== []) throw new \InvalidArgumentException('Die Rechnung kann erst nach Pflege der Niederlassungs- und Bankdaten erzeugt werden: ' . implode(', ', $missingBranch));
		$missingRecipient = [];
		foreach (['name' => 'Name', 'street' => 'Straße', 'postalCode' => 'Postleitzahl', 'city' => 'Ort'] as $field => $label) if (trim((string)($recipient[$field] ?? '')) === '') $missingRecipient[] = $label;
		if ($missingRecipient !== []) throw new \InvalidArgumentException('Die Rechnung kann erst mit vollständigem Rechnungsempfänger erzeugt werden: ' . implode(', ', $missingRecipient));
		if (trim((string)($invoice['servicePeriodFrom'] ?? '')) === '' || trim((string)($invoice['servicePeriodTo'] ?? '')) === '') throw new \InvalidArgumentException('Für eine gesetzeskonforme Rechnung muss der vollständige Leistungszeitraum angegeben werden.');
		$invoiceDate = substr((string)$invoice['createdAt'], 0, 10);
		$dueDate = (string)$payment['dueDate'];
		$invoice['dueDate'] = $dueDate;
		$qrPayload = (string)$payment['qrPayload'];
		$paymentQrPng = $qrPayload !== '' ? $this->eInvoices->epcQrImage($invoice, $paymentBranch) : null;
		if (($payment['snapshotUsed'] ?? false) && is_array($invoice['paymentSnapshot'] ?? null)) {
			$snapshot = $invoice['paymentSnapshot'];
			$case['masterData'] = array_merge($case['masterData'] ?? [], [
				'payment_method' => (string)($snapshot['method'] ?? 'TRANSFER'), 'sepa_mandate_reference' => (string)($snapshot['mandateReference'] ?? ''),
				'sepa_collection_date' => (string)($snapshot['collectionDate'] ?? ''), 'sepa_debtor_name' => (string)($snapshot['debtorName'] ?? ''),
				'sepa_debtor_iban' => (string)($snapshot['debtorIban'] ?? ''), 'sepa_debtor_bic' => (string)($snapshot['debtorBic'] ?? ''),
			]);
		}
		$case['masterData'] = array_merge($case['masterData'] ?? [], [
			'invoice_number' => (string)$invoice['invoiceNumber'],
			'invoice_type' => (string)($invoice['invoiceType'] ?? 'PARTIAL'),
			'invoice_type_label' => (string)($invoice['invoiceTypeLabel'] ?? 'Teilrechnung'),
			'invoice_sequence' => (string)($invoice['invoiceSequence'] ?? 1),
			'invoice_prior_gross' => $this->money((int)($invoice['priorGrossCents'] ?? 0)),
			'invoice_has_prior' => (int)($invoice['invoiceSequence'] ?? 1) > 1 ? '1' : '0',
			'invoice_current_gross' => $this->money((int)($invoice['totals']['grossCents'] ?? 0)),
			'invoice_date' => $this->formatDocumentDate($invoiceDate),
			'invoice_service_period_from' => $this->formatDocumentDate((string)($invoice['servicePeriodFrom'] ?? '')),
			'invoice_service_period_to' => $this->formatDocumentDate((string)($invoice['servicePeriodTo'] ?? '')),
			'invoice_service_period' => $this->servicePeriodLabel((string)($invoice['servicePeriodFrom'] ?? ''), (string)($invoice['servicePeriodTo'] ?? '')),
			'invoice_recipient_name' => (string)($recipient['name'] ?? ''),
			'invoice_recipient_address' => trim(implode(', ', array_filter([(string)($recipient['street'] ?? ''), trim((string)($recipient['postalCode'] ?? '') . ' ' . (string)($recipient['city'] ?? ''))]))),
			'invoice_due_date' => $this->formatDocumentDate($dueDate), 'invoice_payment_reference' => 'Rechnung ' . (string)$invoice['invoiceNumber'], 'invoice_qr_payload' => $qrPayload,
			'invoice_payment_method' => (string)$payment['methodLabel'], 'invoice_payment_instruction' => (string)$payment['instruction'],
			'invoice_payment_qr_label' => $qrPayload !== '' ? 'SEPA-ZAHLCODE' : ($payment['method'] === 'SEPA_DIRECT_DEBIT' ? 'SEPA-LASTSCHRIFT' : 'KEIN ZAHLUNGS-QR'),
			'sepa_mandate_reference' => (string)($case['masterData']['sepa_mandate_reference'] ?? ''), 'sepa_collection_date' => $this->formatDocumentDate((string)$payment['collectionDate']),
			'branch_creditor_id' => (string)($branch['creditorId'] ?? ''),
			'branch_name' => (string)($branch['name'] ?? ''), 'branch_address' => trim(implode(', ', array_filter([(string)($branch['street'] ?? ''), trim((string)($branch['postalCode'] ?? '') . ' ' . (string)($branch['city'] ?? ''))]))),
			'bank_account_holder' => (string)($paymentBranch['accountHolder'] ?? ''), 'bank_name' => (string)($paymentBranch['bankName'] ?? ''), 'bank_iban' => (string)($paymentBranch['iban'] ?? ''), 'bank_bic' => (string)($paymentBranch['bic'] ?? ''),
			'branch_vat_id' => (string)($branch['vatId'] ?? ''), 'branch_tax_number' => (string)($branch['taxNumber'] ?? ''), 'branch_register' => trim((string)($branch['registerCourt'] ?? '') . ' ' . (string)($branch['registerNumber'] ?? '')),
			'branch_managing_directors' => (string)($branch['managingDirectors'] ?? ''),
			// 0.70.0: Korrekturbelege (Vorlage KORREKTUR.docx)
			'invoice_document_kind' => (string)($invoice['documentKind'] ?? 'INVOICE'),
			'invoice_reference_number' => (string)($invoice['referenceInvoiceNumber'] ?? ''),
			'invoice_reference_date' => $this->formatDocumentDate((string)($invoice['referenceInvoiceDate'] ?? '')),
			'invoice_reference_type' => (string)($invoice['referenceInvoiceTypeLabel'] ?? ''),
			'invoice_correction_reason' => (string)($invoice['correctionReason'] ?? ''),
			'invoice_correction_title' => ($invoice['documentKind'] ?? '') === 'CANCELLATION' ? 'Stornorechnung' : 'Gutschrift (Rechnungskorrektur)',
			'invoice_correction_amount' => $this->money(abs((int)($invoice['totals']['grossCents'] ?? 0))),
			'invoice_reference_label' => trim((string)($invoice['referenceInvoiceNumber'] ?? '') . (($invoice['referenceInvoiceDate'] ?? '') !== '' ? ' vom ' . $this->formatDocumentDate((string)$invoice['referenceInvoiceDate']) : '')),
		]);
		if (($invoice['documentKind'] ?? 'INVOICE') !== 'INVOICE') $case['masterData'] = array_merge($case['masterData'], [
			'invoice_payment_qr_label' => '', 'invoice_has_prior' => '0',
			'invoice_payment_reference' => (string)$invoice['invoiceNumber'] . ' zu Rechnung ' . (string)($invoice['referenceInvoiceNumber'] ?? ''),
		]);
		$items = array_map(static fn(array $item): array => [
			'articleNumber' => (string)$item['position'], 'title' => (string)$item['description'], 'quantity' => (float)$item['quantity'],
			'unit' => (string)($item['unit'] ?? 'STK'),
			'unitPriceCents' => (int)$item['unitPriceCents'], 'vatRate' => (int)$item['vatRate'], 'netCents' => (int)$item['netCents'],
			'vatCents' => (int)$item['vatCents'], 'grossCents' => (int)$item['grossCents'], 'positionType' => (string)($item['positionType'] ?? 'EL'), 'sourcePackageName' => '',
			'position' => (int)$item['position'], 'positionLabel' => (string)($item['positionLabel'] ?? $item['position']),
			'packageServiceId' => $item['packageServiceId'] ?? null, 'packageTitle' => (string)($item['packageTitle'] ?? ''), 'packagePosition' => $item['packagePosition'] ?? null, 'printMode' => (string)($item['printMode'] ?? ''),
		], $invoice['items'] ?? []);
		$taxableNet = array_sum(array_map(static fn(array $item): int => ($item['positionType'] ?? 'EL') === 'DP' ? 0 : (int)$item['netCents'], $items));
		$passThrough = array_sum(array_map(static fn(array $item): int => ($item['positionType'] ?? 'EL') === 'DP' ? (int)$item['grossCents'] : 0, $items));
		$documentStatus = in_array((string)($invoice['status'] ?? ''), ['PRUEFUNG', 'FREIGEGEBEN', 'VERSENDET', 'TEILBEZAHLT', 'BEZAHLT'], true)
			? 'FINAL'
			: 'ENTWURF';
		$isReviewDocument = (string)($invoice['status'] ?? '') === 'PRUEFUNG';
		return [
			'case' => $case, 'items' => $items, 'taxableNet' => $taxableNet, 'passThrough' => $passThrough,
			'qrPayload' => $qrPayload, 'paymentQrPng' => $paymentQrPng, 'documentStatus' => $documentStatus,
			'isReviewDocument' => $isReviewDocument, 'payment' => $payment, 'branch' => $branch, 'invoice' => $invoice,
		];
	}

	public function generateInvoice(array $case, array $invoice, bool $createPdf = true): array {
		$context = $this->invoiceRenderContext($case, $invoice);
		$case = $context['case'];
		$invoice = $context['invoice'];
		$items = $context['items'];
		$taxableNet = $context['taxableNet'];
		$passThrough = $context['passThrough'];
		$qrPayload = $context['qrPayload'];
		$paymentQrPng = $context['paymentQrPng'];
		$documentStatus = $context['documentStatus'];
		$isReviewDocument = $context['isReviewDocument'];
		$payment = $context['payment'];
		$branch = $context['branch'];
		$settings = $this->configuration->invoiceSettings();
		$invoiceProfile = strtoupper((string)($branch['invoiceProfile'] ?? (($settings['xrechnungEnabled'] ?? false) && !($settings['zugferdEnabled'] ?? false) ? 'XRECHNUNG' : (($settings['zugferdEnabled'] ?? false) ? 'ZUGFERD' : 'NONE'))));
		$isCorrection = ($invoice['documentKind'] ?? 'INVOICE') !== 'INVOICE';
		$this->preferPdfA = $invoiceProfile === 'ZUGFERD' && $createPdf;
		try {
		$result = $this->generateTemplate(
			$case,
			$isCorrection ? 'KORREKTUR' : 'RECHNUNG',
			$documentStatus,
			$createPdf,
			false,
			['items' => $items, 'totals' => $invoice['totals'], 'invoiceTaxableNetCents' => $taxableNet, 'invoicePassThroughCents' => $passThrough],
			0,
			$qrPayload !== '' ? $paymentQrPng : '',
			$isReviewDocument ? 'Prüfung' : null,
			$isReviewDocument,
			true,
			// 0.70.0: Belegnummer im Dateinamen, damit Teil-/Schlussrechnung, Storno und Gutschrift eines Falls
			// einander nicht überschreiben (bisher „Rechnung - <Fall> - Prüfung.pdf“ für alle).
			($isCorrection ? (($invoice['documentKind'] ?? '') === 'CANCELLATION' ? 'Stornorechnung ' : 'Gutschrift ') : 'Rechnung ') . (string)$invoice['invoiceNumber'],
		);
		} finally {
			$this->preferPdfA = false;
		}
		$result['documentStage'] = $isReviewDocument ? 'PRUEFUNG' : $documentStatus;
		$target = $this->folders->caseSubfolder((string)$case['caseNumber'], $this->installationConfig->billingFolder());
		$electronicOutputs = [];
		if ($invoiceProfile === 'ZUGFERD') {
			$xml = $this->eInvoices->createXml($case, $invoice, $branch, $settings);
			$validation = $this->eInvoices->validateXml($xml, 'ZUGFERD');
			if (!$validation['valid']) throw new \InvalidArgumentException('Die E-Rechnung (ZUGFeRD) kann so nicht erzeugt werden: ' . implode(' ', $validation['errors']));
			$result['eInvoice'] = $this->zugferdOutput($target, $result, $xml, $validation, $invoice, $settings);
			$electronicOutputs[] = ['standard' => 'ZUGFeRD', 'fileId' => $result['eInvoice']['fileId'], 'sha256' => hash('sha256', $xml), 'validation' => $result['eInvoice']['validationStatus'], 'embedded' => $result['eInvoice']['embedded'], 'pdfa' => $result['eInvoice']['pdfa']];
		}
		if ($invoiceProfile === 'XRECHNUNG') {
			$xInvoice = $invoice;
			$xInvoice['recipient']['electronicAddress'] = (string)($case['masterData']['invoice_electronic_address'] ?? $case['masterData']['order_client_email'] ?? '');
			$xml = $this->eInvoices->createXml($case, $xInvoice, $branch, $settings, 'XRECHNUNG');
			$validation = $this->eInvoices->validateXml($xml, 'XRECHNUNG');
			if (!$validation['valid']) throw new \InvalidArgumentException('Die XRechnung kann noch nicht erzeugt werden: ' . implode(' ', $validation['errors']));
			$name = pathinfo(basename((string)$result['path']), PATHINFO_FILENAME) . ' - XRechnung.xml';
			if ($target->nodeExists($name)) $target->get($name)->delete();
			$file = $target->newFile($name); $file->putContent($xml);
			$result['xInvoice'] = ['standard' => 'XRechnung', 'version' => '3.0.x', 'syntax' => 'CII', 'fileId' => $file->getId(), 'path' => dirname((string)$result['path']) . '/' . $name, 'validationStatus' => 'STRUCTURE_VALIDATED', 'complianceNote' => 'Vor Versand ist die Datei mit der jeweils aktuellen KoSIT-Konfiguration normativ zu validieren.'];
			$electronicOutputs[] = ['standard' => 'XRechnung', 'fileId' => $file->getId(), 'sha256' => hash('sha256', $xml), 'validation' => 'STRUCTURE_VALIDATED'];
		}
		$manifest = ['schema' => 'bestatter-invoice-closure/1', 'invoiceId' => (int)$invoice['id'], 'invoiceNumber' => (string)$invoice['invoiceNumber'], 'invoiceType' => (string)$invoice['invoiceType'], 'caseNumber' => (string)$case['caseNumber'], 'createdAt' => date('c'), 'createdBy' => $this->userSession->getUser()?->getUID() ?? 'system', 'totals' => $invoice['totals'], 'outputs' => $electronicOutputs];
		if (is_array($result['pdf'])) { $pdfNode = $target->get((string)$result['pdf']['fileName']); if (!$pdfNode instanceof \OCP\Files\File) throw new \RuntimeException('Die Rechnungs-PDF „' . $result['pdf']['fileName'] . '“ wurde nicht als Datei gefunden.'); $manifest['pdfSha256'] = hash('sha256', (string)$pdfNode->getContent()); $manifest['pdfFileId'] = $pdfNode->getId(); }
		$manifestName = pathinfo(basename((string)$result['path']), PATHINFO_FILENAME) . ' - Abschlussnachweis.json';
		if ($target->nodeExists($manifestName)) $target->get($manifestName)->delete();
		$manifestFile = $target->newFile($manifestName); $manifestFile->putContent(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		$result['closureManifest'] = $manifest + ['fileId' => $manifestFile->getId(), 'path' => dirname((string)$result['path']) . '/' . $manifestName];
		$result['payment'] = $payment;
		$result['invoiceProfile'] = $invoiceProfile;
		$result['paymentQr'] = ['format' => (string)$payment['qrStandard'], 'payload' => $qrPayload, 'structuredReference' => $qrPayload !== '' ? explode("\n", $qrPayload)[9] : '', 'printReady' => $qrPayload !== '' && $paymentQrPng !== null, 'snapshotUsed' => (bool)($payment['snapshotUsed'] ?? false), 'rendering' => $qrPayload !== '' ? 'SERVER_RENDERED_PNG' : ($payment['method'] === 'SEPA_DIRECT_DEBIT' ? 'SUPPRESSED_FOR_DIRECT_DEBIT' : 'DISABLED_BY_CONFIGURATION')];
		return $result;
	}

	/**
	 * 0.73.0 (OP-006): ZUGFeRD-Ausgabe. Mit PDF wird die factur-x.xml in das Rechnungs-PDF eingebettet
	 * (inkrementelle Ergänzung, sichtbarer Inhalt unverändert) und das Ergebnis zurückgelesen. Nur ohne PDF
	 * oder wenn die Einbettung scheitert, liegt die XML wie bisher als eigene Datei daneben. Ein eingerichteter
	 * Mustang-Validator prüft das fertige PDF anschließend normativ.
	 */
	private function zugferdOutput(Folder $target, array $result, string $xml, array $validation, array $invoice, array $settings): array {
		$baseName = pathinfo(basename((string)$result['path']), PATHINFO_FILENAME);
		$xmlName = $baseName . ' - factur-x.xml';
		$reportName = $baseName . ' - Prüfbericht.xml';
		$required = (bool)($settings['normativeValidationRequired'] ?? false);
		$compliance = $this->eInvoiceCompliance->status();
		$output = [
			'standard' => 'ZUGFeRD', 'version' => '2.5.2', 'profile' => 'EN16931', 'embedded' => false, 'hybridEmbedded' => false,
			'pdfa' => null, 'sourcePdfa' => null, 'attachmentName' => FacturXPdfService::ATTACHMENT, 'fileId' => null, 'path' => '',
			'validationStatus' => 'STRUCTURE_VALIDATED', 'validationLevel' => $validation['level'], 'validationReportFileId' => null,
			'normativeValidation' => $compliance['available'] ? 'PENDING' : 'NOT_CONFIGURED', 'embedError' => null, 'complianceNote' => '',
		];
		$pdfNode = null;
		// Pflicht zur normativen Prüfung nicht erfüllt: kein ungeprüftes Rechnungs-PDF im Fallordner zurücklassen.
		$fail = function (string $message, ?\Throwable $previous = null) use (&$pdfNode): never {
			if ($pdfNode !== null) { try { $pdfNode->delete(); } catch (\Throwable) {} }
			throw new \InvalidArgumentException($message, 0, $previous);
		};
		$candidate = is_array($result['pdf'] ?? null) && $target->nodeExists((string)$result['pdf']['fileName']) ? $target->get((string)$result['pdf']['fileName']) : null;
		if ($candidate instanceof \OCP\Files\File) {
			$pdfNode = $candidate;
			try {
				$title = (($invoice['documentKind'] ?? 'INVOICE') === 'CANCELLATION' ? 'Stornorechnung ' : ((($invoice['documentKind'] ?? 'INVOICE') === 'CREDIT_NOTE') ? 'Gutschrift ' : 'Rechnung ')) . (string)$invoice['invoiceNumber'];
				$embedded = $this->facturX->embed((string)$pdfNode->getContent(), $xml, ['title' => $title, 'subject' => 'E-Rechnung ZUGFeRD 2.5.2 (EN 16931)']);
				$check = $this->facturX->inspect($embedded['pdf']);
				if ($check['xml'] !== $xml || $check['problems'] !== []) throw new \RuntimeException('Kontrolle der eingebetteten Daten fehlgeschlagen: ' . implode(' ', $check['problems'] ?: ['XML weicht ab.']));
				$pdfNode->putContent($embedded['pdf']);
				$output['embedded'] = true; $output['hybridEmbedded'] = true;
				$output['pdfa'] = $embedded['pdfaPart'] !== null ? $embedded['pdfaPart'] . $embedded['pdfaConformance'] : null;
				$output['sourcePdfa'] = $embedded['sourcePdfa'] !== null ? $embedded['sourcePdfa']['part'] . $embedded['sourcePdfa']['conformance'] : null;
				$output['fileId'] = $pdfNode->getId(); $output['path'] = (string)$result['pdf']['path'];
				// A separate XML would be a second invoice document; remove leftovers from earlier review runs.
				if ($target->nodeExists($xmlName)) $target->get($xmlName)->delete();
			} catch (\Throwable $error) {
				$output['embedError'] = $error->getMessage();
				if ($required) $fail('Die ZUGFeRD-Rechnung konnte nicht erzeugt werden (Einbettung der XML in das PDF): ' . $error->getMessage(), $error);
			}
		} elseif ($required) {
			$fail('Für eine normativ geprüfte ZUGFeRD-Rechnung ist ein PDF erforderlich; die PDF-Konvertierung steht nicht zur Verfügung.');
		}
		if (!$output['embedded']) {
			if ($target->nodeExists($xmlName)) $target->get($xmlName)->delete();
			$file = $target->newFile($xmlName); $file->putContent($xml);
			$output['fileId'] = $file->getId(); $output['path'] = dirname((string)$result['path']) . '/' . $xmlName;
		}
		if ($target->nodeExists($reportName)) $target->get($reportName)->delete();
		if ($output['embedded'] && $compliance['available'] && $pdfNode !== null) {
			try {
				$check = $this->eInvoiceCompliance->validate((string)$pdfNode->getContent());
				$report = $target->newFile($reportName); $report->putContent($check['report']);
				$output['validationReportFileId'] = $report->getId();
				$output['normativeValidation'] = $check['valid'] ? 'VALID' : 'INVALID';
				if ($check['valid']) { $output['validationStatus'] = 'NORMATIV_VALIDATED'; $output['validationLevel'] = 'MUSTANG_EN16931_PDFA3'; }
			} catch (\RuntimeException $error) {
				$output['normativeValidation'] = 'ERROR';
				$output['normativeError'] = $error->getMessage();
			}
			if ($required && $output['normativeValidation'] === 'INVALID') $fail('Die normative E-Rechnungsprüfung (Mustang) hat Fehler gemeldet; die Rechnung wurde nicht ausgegeben. Details im Prüfbericht „' . $reportName . '“ im Abrechnungsordner.');
			if ($required && $output['normativeValidation'] === 'ERROR') $fail('Die verpflichtende normative E-Rechnungsprüfung konnte nicht ausgeführt werden: ' . (string)$output['normativeError']);
		} elseif ($required) {
			$fail('Die normative E-Rechnungsprüfung ist verpflichtend, aber der Mustang-Validator ist nicht verfügbar.');
		}
		if ($output['normativeValidation'] === 'PENDING') $output['normativeValidation'] = 'SKIPPED';
		$output['complianceNote'] = match (true) {
			$output['validationStatus'] === 'NORMATIV_VALIDATED' => 'ZUGFeRD-PDF (PDF/A-' . (string)$output['pdfa'] . ') mit eingebetteter factur-x.xml; normativ geprüft (Mustang).',
			$output['embedded'] && $output['pdfa'] !== null => 'ZUGFeRD-PDF (PDF/A-' . (string)$output['pdfa'] . ') mit eingebetteter factur-x.xml. XML intern geprüft (Pflichtangaben, Rechensummen, Steuergruppen)' . match ($output['normativeValidation']) {
				'INVALID' => '. Achtung: Die normative Prüfung (Mustang) hat Fehler gemeldet, siehe Prüfbericht.',
				'ERROR' => '. Die normative Prüfung konnte nicht ausgeführt werden: ' . (string)($output['normativeError'] ?? ''),
				default => '; eine normative Prüfung ist nicht eingerichtet.',
			},
			$output['embedded'] => 'factur-x.xml ist in das PDF eingebettet. Die PDF-Konvertierung liefert kein PDF/A, deshalb fehlt die PDF/A-3-Kennzeichnung, die ZUGFeRD formal verlangt. Abhilfe: Konverter mit PDF/A-Ausgabe verwenden (siehe Systemprüfung).',
			$output['embedError'] !== null => 'Die XML konnte nicht in das PDF eingebettet werden und liegt als eigene Datei daneben: ' . $output['embedError'],
			default => 'Ohne PDF liegt die XML als eigene Datei im Fallordner.',
		};
		if ($output['normativeValidation'] === 'INVALID' && !($output['embedded'] && $output['pdfa'] !== null)) $output['complianceNote'] .= ' Achtung: Die normative Prüfung hat Fehler gemeldet (siehe Prüfbericht).';
		if ($pdfNode !== null) $this->eInvoiceCompliance->recordOutput($output);
		return $output;
	}

	/**
	 * Build the RECHNUNG template as a short-lived preview PDF, mirroring
	 * previewOrderDocumentPdf(): no case-file output, no document record, no
	 * ZUGFeRD/XRechnung generation and no closure manifest are created. Those
	 * remain exclusive to the binding generateInvoice()/generateInvoiceDocument()
	 * closing action. Validation is identical to the closing document (via
	 * invoiceRenderContext()), so an incomplete invoice fails with the same
	 * clear message here as it would on final generation.
	 *
	 * @return array{content:string,fileName:string,title:string,templateKey:string}
	 */
	public function previewInvoiceDocumentPdf(array $case, array $invoice): array {
		if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('Die PHP-Erweiterung ZipArchive wird für DOCX-Vorlagen benötigt.');
		$context = $this->invoiceRenderContext($case, $invoice);
		$case = $context['case'];
		$definition = $this->definition(($invoice['documentKind'] ?? 'INVOICE') !== 'INVOICE' ? 'KORREKTUR' : 'RECHNUNG', $case);
		if (!$definition['supportsPdf'] || !$this->pdfAvailable()) {
			throw new \RuntimeException('Für diese Dokumentvorschau ist derzeit keine PDF-Konvertierung verfügbar.');
		}
		$userFolder = $this->folders->baseFolder();
		$templateFolder = $this->installationConfig->ensurePath($userFolder, $this->installationConfig->templatesPath());
		$this->seedEstimateTemplate($templateFolder, $definition);
		$template = $this->template(
			$templateFolder,
			$definition['fileName'],
			(string)($definition['templateNamespace'] ?? ''),
			(bool)($definition['regionalTemplateRequired'] ?? false),
		);
		$costing = ['items' => $context['items'], 'totals' => $context['invoice']['totals'] ?? [], 'invoiceTaxableNetCents' => $context['taxableNet'], 'invoicePassThroughCents' => $context['passThrough']];
		$embeddedPaymentQr = $context['qrPayload'] !== '' ? $context['paymentQrPng'] : null;
		$content = $this->replaceDocx((string)$template->getContent(), $this->placeholderData($case, $costing), $costing['items'], $embeddedPaymentQr, $this->caseBranchKey($case));
		$tempName = '.bestatter-preview-' . bin2hex(random_bytes(12)) . '.docx';
		$temp = $templateFolder->newFile($tempName);
		$temp->putContent($content);
		$converted = null;
		try {
			$pdfContent = null;
			$providerError = null;
			if ($this->conversionManager->hasProviders()) {
				try {
					$path = $this->conversionManager->convert($temp, 'application/pdf');
					$converted = $this->rootFolder->get($path);
					if ($converted instanceof \OCP\Files\File) $pdfContent = (string)$converted->getContent();
				} catch (\Throwable $error) {
					$providerError = $error;
				}
			}
			if (($pdfContent === null || !str_starts_with($pdfContent, '%PDF-')) && $this->euroOffice->isAvailable()) {
				$pdfContent = $this->euroOffice->convert($temp);
			}
			if ($pdfContent === null || !str_starts_with($pdfContent, '%PDF-')) {
				if ($providerError !== null) throw new \RuntimeException('Die Nextcloud-PDF-Konvertierung ist fehlgeschlagen: ' . $providerError->getMessage(), 0, $providerError);
				throw new \RuntimeException('Weder Nextcloud noch EuroOffice stellen eine automatische PDF-Konvertierung bereit.');
			}
			return [
				'content' => $pdfContent,
				'fileName' => $this->safeOutputTitle((string)$definition['title']) . ' - ' . (string)$case['caseNumber'] . ' - Vorschau.pdf',
				'title' => (string)$definition['title'],
				'templateKey' => 'RECHNUNG',
			];
		} finally {
			if ($converted instanceof \OCP\Files\File && $converted->getId() !== $temp->getId()) {
				try { $converted->delete(); } catch (\Throwable) {}
			}
			try { $temp->delete(); } catch (\Throwable) {}
		}
	}

	private function servicePeriodLabel(string $from, string $to): string {
		$from=$this->formatDocumentDate($from);$to=$this->formatDocumentDate($to);
		if($from!==''&&$to!=='')return $from.' bis '.$to;
		return $from!==''?$from:$to;
	}

	public function preview(array $case, string $templateKey, int $scheduleId = 0): array {
		$definition = $this->definition(strtoupper(trim($templateKey)), $case);
		[$case, $scheduleOptions, $selectedSchedule] = $this->withFuneralEventContext($case, $definition, $scheduleId);
		$costing = $this->articles->caseServices((int)$case['id']);
		$missing = $this->missingRequiredFields($case, $definition, $costing);
		$masterData = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$requiresFuneralEvent = in_array('funeral_event_date', $definition['requiredFields'], true);
		return [
			'templateKey' => $definition['key'],
			'title' => $definition['title'],
			'documentType' => $definition['category'],
			'fileName' => $definition['fileName'],
			'outputSubfolder' => $definition['subfolder'],
			'supportsPdf' => $definition['supportsPdf'] && $this->pdfAvailable(),
			'pdfConfigured' => $definition['supportsPdf'],
			'pdfMessage' => $definition['supportsPdf'] && !$this->pdfAvailable() ? 'PDF-Ausgabe ist derzeit nicht verfügbar; die DOCX-Ausgabe bleibt möglich.' : '',
			'missingFields' => $missing,
			'ready' => $missing === [],
			'requiresFuneralEvent' => $requiresFuneralEvent,
			'scheduleOptions' => $scheduleOptions,
			'selectedScheduleId' => (int)($selectedSchedule['id'] ?? 0),
			'summary' => [
				'caseNumber' => (string)$case['caseNumber'],
				'deceased' => trim((string)$case['firstName'] . ' ' . (string)$case['lastName']),
				'death' => $this->deathDisplay($case['masterData'] ?? [], (string)($case['dateOfDeath'] ?? '')),
				'grossTotal' => $this->money((int)($costing['totals']['grossCents'] ?? 0)),
				'funeralEvent' => $requiresFuneralEvent ? [
					'date' => (string)($masterData['funeral_event_date'] ?? ''),
					'time' => (string)($masterData['funeral_event_time'] ?? ''),
					'endTime' => (string)($masterData['funeral_event_end_time'] ?? ''),
					'location' => (string)($masterData['funeral_event_location'] ?? ''),
					'category' => (string)($masterData['funeral_event_category'] ?? 'Trauerfeier'),
				] : null,
			],
		];
	}

	public function generateTemplate(array $case, string $templateKey, string $status = 'ENTWURF', bool $createPdf = true, bool $allowIncomplete = false, ?array $costingOverride = null, int $scheduleId = 0, ?string $embeddedPaymentQr = null, ?string $outputStatusLabel = null, bool $allowImmutableOutputReplacement = false, bool $replaceExistingOutput = true, ?string $outputTitle = null): array {
		if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('Die PHP-Erweiterung ZipArchive wird für DOCX-Vorlagen benötigt.');
		$templateKey = strtoupper(trim($templateKey));
		$status = strtoupper(trim($status));
		if (!in_array($status, self::STATUSES, true)) throw new \InvalidArgumentException('Ungültiger Dokumentstatus.');
		$definition = $this->definition($templateKey, $case);
		[$case] = $this->withFuneralEventContext($case, $definition, $scheduleId);
		$userFolder = $this->folders->baseFolder();
		$templateFolder = $this->installationConfig->ensurePath($userFolder, $this->installationConfig->templatesPath());
		$targetFolder = $this->folders->caseSubfolder((string)$case['caseNumber'], $definition['subfolder']);
		$costing = $costingOverride ?? $this->articles->caseServices((int)$case['id']);
		$missing = $this->missingRequiredFields($case, $definition, $costing);
		if ($missing !== [] && (!$allowIncomplete || $status !== 'ENTWURF')) throw new \InvalidArgumentException('Dokument kann noch nicht erzeugt werden. Bitte ergänzen: ' . implode(', ', $missing));
		if (in_array($status, ['FINAL', 'UNTERSCHRIEBEN'], true) && (!$createPdf || !$definition['supportsPdf'])) throw new \InvalidArgumentException('Finale Dokumente müssen als nicht bearbeitbare PDF-Ausgabe erzeugt werden.');

		$this->seedEstimateTemplate($templateFolder, $definition);
		$template = $this->template(
			$templateFolder,
			$definition['fileName'],
			(string)($definition['templateNamespace'] ?? ''),
			(bool)($definition['regionalTemplateRequired'] ?? false),
		);
		$templateContent = (string)$template->getContent();
		$templateSource = $this->templateNodeFingerprint($template, $templateContent);
		$content = $this->replaceDocx($templateContent, $this->placeholderData($case, $costing), $costing['items'] ?? [], $embeddedPaymentQr, $this->caseBranchKey($case));
		// 0.70.1: Belegnummern wie „RE-2026-000012“ im Dateinamen unverändert lassen (nicht „RE - 2026 - 000012“).
		$safeTitle = $outputTitle !== null && trim($outputTitle) !== '' ? $this->safeOutputTitle($outputTitle, true) : $this->safeOutputTitle((string)$definition['title']);
		$masterData = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$scheduleContextId = (int)($masterData['funeral_event_schedule_id'] ?? 0);
		$contextLabel = $scheduleContextId > 0
			? $this->safeOutputTitle('Trauerfeier ' . (string)($masterData['funeral_event_date_iso'] ?? $masterData['funeral_event_date'] ?? $scheduleContextId) . ' ' . str_replace(':', '-', (string)($masterData['funeral_event_time'] ?? '')))
			: '';
		$documentContextKey = $scheduleContextId > 0 ? 'schedule:' . $scheduleContextId : '';
		$statusLabel = trim((string)$outputStatusLabel);
		if ($statusLabel === '') $statusLabel = match ($status) { 'FINAL' => 'Final', 'UNTERSCHRIEBEN' => 'Unterschrieben', default => 'Entwurf' };
		$statusLabel = $this->safeOutputTitle($statusLabel);
		$prefix = sprintf('%s - %s%s - ', $safeTitle, $case['caseNumber'], $contextLabel !== '' ? ' - ' . $contextLabel : '');
		$version = 1;
		if ($status === 'FINAL' && $templateKey === 'BESTATTUNGSAUFTRAG') {
			foreach ($targetFolder->getDirectoryListing() as $existing) {
				$name = $existing->getName();
				if (!str_starts_with($name, $prefix . 'Final')) continue;
				if (preg_match('/ - Final - R(\d+)\.pdf$/', $name, $match)) $version = max($version, (int)$match[1] + 1);
				elseif (str_ends_with($name, ' - Final.pdf')) $version = max($version, 2);
			}
			$statusLabel = sprintf('Final - R%02d', $version);
			$replaceExistingOutput = false;
		}
		if ($replaceExistingOutput) {
			$existingOutputs = array_values(array_filter($targetFolder->getDirectoryListing(), static fn($existing): bool => str_starts_with($existing->getName(), $prefix)));
			foreach ($existingOutputs as $existing) {
				$name = $existing->getName();
				if (!$allowImmutableOutputReplacement && (str_contains($name, ' - Final.') || str_contains($name, ' - Unterschrieben.'))) throw new \InvalidArgumentException('Das aktuelle Dokument ist final und kann nicht überschrieben werden.');
			}
			foreach ($existingOutputs as $existing) $existing->delete();
		}
		$baseName = sprintf('%s - %s%s - %s', $safeTitle, $case['caseNumber'], $contextLabel !== '' ? ' - ' . $contextLabel : '', $statusLabel);
		$docxName = $baseName . '.docx';
		// A previous interrupted workflow may have written the file before its
		// database record was committed. Draft outputs are current-state files,
		// so an orphan with the exact target name must be replaced safely.
		if ($targetFolder->nodeExists($docxName) && $replaceExistingOutput) $targetFolder->get($docxName)->delete();
		if ($targetFolder->nodeExists($docxName)) throw new \RuntimeException('Der temporäre Dokumentname ist bereits vorhanden. Bitte erneut versuchen.');
		$file = $targetFolder->newFile($docxName);
		$file->putContent($content);
		$basePath = $this->folders->caseRelativePath((string)$case['caseNumber']) . '/' . $definition['subfolder'] . '/';
		$result = [
			'title' => $definition['title'], 'path' => $basePath . $docxName, 'fileId' => $file->getId(),
			'template' => $definition['fileName'], 'templateKey' => $templateKey, 'documentType' => $definition['category'], 'status' => $status,
			'version' => $version, 'missingFields' => $missing, 'createdAt' => date('c'), 'pdf' => null,
			'documentContextKey' => $documentContextKey, 'sourceScheduleId' => $scheduleContextId,
			'templateSource' => $templateSource,
		];
		if ($createPdf && $definition['supportsPdf']) {
			try {
				$result['pdf'] = $this->createPdf($file, $targetFolder, $baseName . '.pdf', $basePath);
			} catch (\Throwable $error) {
				if (in_array($status, ['FINAL', 'UNTERSCHRIEBEN'], true)) { $file->delete(); throw $error; }
				$result['pdfWarning'] = 'Die DOCX-Datei wurde erzeugt; nur die PDF-Ausgabe ist fehlgeschlagen: ' . $error->getMessage();
			}
		}
		if (in_array($status, ['FINAL', 'UNTERSCHRIEBEN'], true) && is_array($result['pdf'])) { $file->delete(); $result['fileId'] = null; $result['path'] = $result['pdf']['path']; }
		return $result;
	}

	/**
	 * Fingerprint the effective Nextcloud template used for a workflow output.
	 * This makes a user-replaced template a deliberate new document revision
	 * without ever overwriting the individual source file.
	 */
	public function templateFingerprint(string $templateKey, ?string $userId = null): array {
		$templateKey = strtoupper(trim($templateKey));
		$definition = null;
		foreach ($this->configuration->documents() as $candidate) {
			if (strtoupper((string)($candidate['key'] ?? '')) === $templateKey) {
				$definition = $candidate;
				break;
			}
		}
		if ($definition === null) throw new \InvalidArgumentException('Dokumentenvorlage wurde nicht gefunden.');
		$uid = trim((string)$userId);
		if ($uid === '') $uid = (string)($this->userSession->getUser()?->getUID() ?? '');
		try { $userFolder = $this->folders->baseFolderFor($uid !== '' ? $uid : null); } catch (\RuntimeException) { throw new \RuntimeException('Kein Nextcloud-Benutzer für die Vorlagenprüfung verfügbar.'); }
		$templateFolder = $this->installationConfig->ensurePath($userFolder, $this->installationConfig->templatesPath());
		$template = $this->template($templateFolder, basename((string)($definition['fileName'] ?? '')));
		$content = (string)$template->getContent();
		return $this->templateNodeFingerprint($template, $content);
	}

	private function templateNodeFingerprint(mixed $template, string $content): array {
		return [
			'fileName' => (string)$template->getName(),
			'fileId' => (int)$template->getId(),
			'sha256' => hash('sha256', $content),
		];
	}

	/**
	 * Direct document generation must use the same confirmed funeral-event
	 * context as workflow-driven generation. If more than one suitable event
	 * exists, the UI must select one explicitly instead of silently guessing.
	 */
	private function withFuneralEventContext(array $case, array $definition, int $scheduleId = 0): array {
		if (!in_array('funeral_event_date', $definition['requiredFields'], true)) return [$case, [], null];
		$masterData = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		if ($scheduleId <= 0 && trim((string)($masterData['funeral_event_date'] ?? '')) !== '') return [$case, [], null];

		$options = $this->funeralEventOptions((int)$case['id']);
		$selected = null;
		if ($scheduleId > 0) {
			foreach ($options as $option) if ((int)$option['id'] === $scheduleId) { $selected = $option; break; }
			if ($selected === null) throw new \InvalidArgumentException('Die ausgewählte Trauerfeier gehört nicht zu diesem Fall oder ist nicht bestätigt.');
		} elseif (count($options) === 1) {
			$selected = $options[0];
		}
		if ($selected === null) return [$case, $options, null];

		$date = new \DateTimeImmutable((string)$selected['date'], new \DateTimeZone('Europe/Berlin'));
		$duration = max(15, (int)($selected['durationMinutes'] ?? 60));
		$case['masterData'] = array_replace($masterData, [
			'funeral_event_schedule_id' => (string)$selected['id'],
			'funeral_event_date' => $date->format('d.m.Y'),
			'funeral_event_date_iso' => $date->format('Y-m-d'),
			'funeral_event_time' => $date->format('H:i'),
			'funeral_event_end_time' => $date->modify('+' . $duration . ' minutes')->format('H:i'),
			'funeral_event_location' => (string)($selected['location'] ?? ''),
			'funeral_event_category' => (string)($selected['category'] ?? $selected['title'] ?? 'Trauerfeier'),
			'funeral_event_external_participants' => (string)($selected['externalParticipants'] ?? ''),
		]);
		return [$case, $options, $selected];
	}

	/** Confirmed or completed funeral ceremonies available for document context. */
	public function funeralEventOptions(int $caseId): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('id', 'title', 'record_date', 'status', 'payload')
			->from('bestatter_records')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->andWhere($query->expr()->eq('record_type', $query->createNamedParameter('schedule')))
			->andWhere($query->expr()->orX(
				$query->expr()->eq('status', $query->createNamedParameter('BESTAETIGT')),
				$query->expr()->eq('status', $query->createNamedParameter('ERLEDIGT')),
			))
			->orderBy('record_date', 'ASC')
			->executeQuery()->fetchAllAssociative();
		$options = [];
		foreach ($rows as $row) {
			$payload = json_decode((string)($row['payload'] ?? ''), true);
			$payload = is_array($payload) ? $payload : [];
			$presetKey = strtoupper(trim((string)($payload['schedulePresetKey'] ?? '')));
			$category = trim((string)($payload['appointmentCategory'] ?? ''));
			$title = trim((string)($row['title'] ?? ''));
			if (!str_contains($presetKey, 'TRAUERFEIER') && !str_contains(mb_strtolower($category . ' ' . $title), 'trauerfeier')) continue;
			$date = trim((string)($row['record_date'] ?? ''));
			if ($date === '') continue;
			try { $dateValue = new \DateTimeImmutable($date, new \DateTimeZone('Europe/Berlin')); } catch (\Throwable) { continue; }
			$options[] = [
				'id' => (int)$row['id'], 'title' => $title, 'date' => $date, 'status' => (string)$row['status'],
				'displayDate' => $dateValue->format('d.m.Y'), 'displayTime' => $dateValue->format('H:i'),
				'durationMinutes' => max(15, (int)($payload['durationMinutes'] ?? 60)),
				'location' => (string)($payload['location'] ?? ''), 'category' => $category !== '' ? $category : $title,
				'externalParticipants' => (string)($payload['externalParticipants'] ?? ''),
			];
		}
		return $options;
	}

	public function discardGeneratedOutput(array $result): void {
		try { $userFolder = $this->folders->baseFolder(); } catch (\RuntimeException) { return; }
		$allowedPrefix = rtrim($userFolder->getPath(), '/') . '/' . $this->installationConfig->casesPath() . '/';
		$fileIds = array_values(array_unique(array_filter([
			(int)($result['fileId'] ?? 0),
			(int)($result['pdf']['fileId'] ?? 0),
		])));
		foreach ($fileIds as $fileId) {
			foreach ($userFolder->getById($fileId) as $node) {
				if (!str_starts_with($node->getPath(), $allowedPrefix)) continue;
				try { $node->delete(); } catch (\Throwable) {}
			}
		}
	}

	private function safeOutputTitle(string $title, bool $keepInnerHyphens = false): string {
		$title = strtr($title, [
			"\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-',
			"\u{00A0}" => ' ', "\u{202F}" => ' ',
		]);
		$title = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $title);
		$title = preg_replace('/[\x00-\x1F\x7F]+/u', '', $title) ?? $title;
		$title = preg_replace('/\s+/u', ' ', $title) ?? $title;
		$title = preg_replace($keepInnerHyphens ? '/\s+-\s*|\s*-\s+/u' : '/\s*-\s*/u', ' - ', $title) ?? $title;
		$title = trim($title, " .-");
		if ($title === '') throw new \InvalidArgumentException('Die Dokumentbezeichnung ergibt keinen gültigen Dateinamen.');
		return $title;
	}

	private function definition(string $key, array $case): array {
		$isEstimate = strtoupper((string)($case['masterData']['order_mode'] ?? 'A')) === 'KVA';
		$branch = $this->configuration->branchByKey((string)($case['branch'] ?? $case['masterData']['branch'] ?? '')) ?? ['countryCode' => 'DE'];
		$templateNamespace = $this->countryConfiguration->templateNamespace($branch);
		if ($templateNamespace === 'DE') $templateNamespace = '';
		$templates = $this->configuration->documents();
		// 0.88.0: Der Kostenvoranschlag hat eine eigene Vorlage (KOSTENVORANSCHLAG); Schlüssel und Ablage bleiben BESTATTUNGSAUFTRAG.
		$lookup = $key;
		if ($key === 'BESTATTUNGSAUFTRAG' && $isEstimate) {
			foreach ($templates as $template) if ($template['key'] === 'KOSTENVORANSCHLAG' && $template['active'] && str_ends_with(strtolower((string)$template['fileName']), '.docx')) $lookup = 'KOSTENVORANSCHLAG';
		}
		foreach ($templates as $template) {
			if ($template['key'] !== $lookup || !$template['active'] || !str_ends_with(strtolower((string)$template['fileName']), '.docx')) continue;
			$required = (array)$template['requiredFields'];
			if ($key === 'BESTATTUNGSAUFTRAG' && !$isEstimate && !in_array('order_contract_conclusion', $required, true)) $required[] = 'order_contract_conclusion';
			return [
				'key' => $key,
				'fileName' => $this->resolveConfiguredTemplateName((string)$template['fileName']),
				'title' => $key === 'BESTATTUNGSAUFTRAG' ? ($isEstimate ? 'Kostenvoranschlag' : 'Bestattungsauftrag') : (string)$template['name'],
				// 0.72.0: Vorlagenziel über den Ordnerplan auflösen (bleibt nach Umbenennung gültig).
				'subfolder' => ($template['outputSubfolder'] !== '' ? $this->installationConfig->resolveSubfolder((string)$template['outputSubfolder']) : null) ?? ($template['category'] === 'Auftrag' ? $this->installationConfig->orderFolder() : $this->installationConfig->authoritiesFolder()),
				'supportsPdf' => (bool)$template['supportsPdf'], 'category' => (string)($template['category'] ?? 'Allgemein'),
				'requiredFields' => $required,
				'templateNamespace' => $templateNamespace,
				'seedFrom' => $lookup === 'KOSTENVORANSCHLAG' ? 'BESTATTUNGSAUFTRAG' : '',
				'regionalTemplateRequired' => $templateNamespace !== '' && in_array($key, self::REGIONAL_TEMPLATE_REQUIRED, true),
			];
		}
		throw new \InvalidArgumentException('Die gewählte Dokumentvorlage ist nicht als aktive DOCX-Vorlage eingerichtet.');
	}

	/**
	 * 0.88.0: Bisher erzeugte BESTATTUNGSAUFTRAG.docx auch den Kostenvoranschlag. Hat ein Betrieb diese Vorlage angepasst,
	 * übernimmt die neue KOSTENVORANSCHLAG.docx beim ersten Kostenvoranschlag sein Layout – statt der mitgelieferten Fassung.
	 * Unveränderte Standardfassungen und die neue Vorlage „Bestattungsauftrag und Vollmacht“ werden nicht übernommen.
	 */
	private function seedEstimateTemplate(Folder $folder, array $definition): void {
		if (($definition['seedFrom'] ?? '') === '' || $folder->nodeExists((string)$definition['fileName'])) return;
		$orderFile = '';
		foreach ($this->configuration->documents() as $template) if ($template['key'] === $definition['seedFrom']) $orderFile = $this->resolveConfiguredTemplateName((string)$template['fileName']);
		if ($orderFile === '' || !$folder->nodeExists($orderFile)) return;
		$node = $folder->get($orderFile);
		if (!$node instanceof \OCP\Files\File) return;
		$content = (string)$node->getContent();
		if (in_array(hash('sha256', $content), self::REPLACEABLE_STANDARD_TEMPLATE_HASHES['BESTATTUNGSAUFTRAG.docx'], true)) return;
		if (self::docxContainsText($content, self::COMBINED_ORDER_MARKER)) return;
		$folder->newFile((string)$definition['fileName'], $content);
	}

	private function resolveConfiguredTemplateName(string $configuredName): string {
		try { $folder = $this->installationConfig->ensurePath($this->folders->baseFolder(), $this->installationConfig->templatesPath()); } catch (\RuntimeException) { return $configuredName; }
		if ($folder->nodeExists($configuredName)) return $configuredName;
		$needle = $this->normalizedTemplateName($configuredName);
		foreach ($folder->getDirectoryListing() as $node) {
			if ($this->normalizedTemplateName($node->getName()) === $needle) return $node->getName();
		}
		return $configuredName;
	}

	private function normalizedTemplateName(string $name): string {
		$name = mb_strtolower(pathinfo($name, PATHINFO_FILENAME));
		return preg_replace('/[^a-z0-9äöüß]+/u', '', $name) ?? $name;
	}

	private function missingRequiredFields(array $case, array $definition, array $costing): array {
		$m = $case['masterData'] ?? [];
		$labels = [
			'first_name' => 'Vorname', 'last_name' => 'Nachname', 'date_of_death_or_interval' => 'Sterbedatum oder Todeszeitraum',
			'registry_office' => 'Standesamt', 'place_of_death' => 'Sterbeort', 'pension_insurance_number' => 'Postrentennummer',
			'order_client_name' => 'Auftraggeber', 'order_client_address' => 'Anschrift Auftraggeber',
			'order_number' => 'KVA-/Auftragsnummer', 'order_date' => 'KVA-/Auftragsdatum', 'services' => 'beauftragte Leistungen',
			'funeral_event_date' => 'Datum der verknüpften Trauerfeier',
			'order_contract_conclusion' => 'Vertragsschluss (Geschäftsräume, außerhalb oder Fernabsatz)',
		];
		$missing = [];
		foreach ($definition['requiredFields'] as $field) {
			$present = match ($field) {
				'first_name' => trim((string)$case['firstName']) !== '',
				'last_name' => trim((string)$case['lastName']) !== '',
				'date_of_death_or_interval' => trim((string)($m['date_of_death'] ?? $case['dateOfDeath'] ?? '')) !== '' || (trim((string)($m['death_time_from'] ?? '')) !== '' && trim((string)($m['death_time_to'] ?? '')) !== ''),
				'order_client_name' => trim((string)($m['order_client_name'] ?? '')) !== '',
				'order_client_address' => trim((string)($m['order_client_street'] ?? '')) !== '' && trim((string)($m['order_client_postal_city'] ?? '')) !== '',
				'order_date' => trim((string)($m[strtoupper((string)($m['order_mode'] ?? 'A')) === 'KVA' ? 'kva_date' : 'order_date'] ?? '')) !== '',
				'services' => count($costing['items'] ?? []) > 0,
				default => trim((string)($m[$field] ?? '')) !== '',
			};
			if (!$present) $missing[] = $labels[$field] ?? $field;
		}
		return $missing;
	}

	private function createPdf(mixed $docx, Folder $target, string $name, string $basePath): array {
		$content = null;
		$providerError = null;
		// 0.73.0: ZUGFeRD verlangt PDF/A-3. EuroOffice kann direkt PDF/A liefern; nur verwenden, wenn das Ergebnis
		// tatsächlich als PDF/A gekennzeichnet ist, sonst wie bisher konvertieren.
		if ($this->preferPdfA && $this->euroOffice->isAvailable()) {
			try {
				$candidate = $this->euroOffice->convert($docx, 'pdfa');
				if ($this->facturX->sourcePdfa($candidate) !== null) $content = $candidate;
			} catch (\Throwable) {
				$content = null;
			}
		}
		if ($content === null && $this->conversionManager->hasProviders()) {
			try {
				$path = $this->conversionManager->convert($docx, 'application/pdf');
				$converted = $this->rootFolder->get($path);
				if ($converted instanceof \OCP\Files\File) $content = (string)$converted->getContent();
			} catch (\Throwable $error) {
				$providerError = $error;
			}
		}
		if (($content === null || !str_starts_with($content, '%PDF-')) && $this->euroOffice->isAvailable()) {
			$content = $this->euroOffice->convert($docx);
		}
		if ($content === null || !str_starts_with($content, '%PDF-')) {
			if ($providerError !== null) throw new \RuntimeException('Die Nextcloud-PDF-Konvertierung ist fehlgeschlagen: ' . $providerError->getMessage(), 0, $providerError);
			throw new \RuntimeException('Weder Nextcloud noch EuroOffice stellen eine automatische PDF-Konvertierung bereit.');
		}
		if ($target->nodeExists($name)) $target->get($name)->delete();
		$pdf = $target->newFile($name);
		$pdf->putContent($content);
		return ['fileId' => $pdf->getId(), 'path' => $basePath . $name, 'fileName' => $name];
	}

	private function pdfAvailable(): bool {
		return $this->conversionManager->hasProviders() || $this->euroOffice->isAvailable();
	}

	private function folder(Folder $parent, string $name): Folder {
		$node = $parent->nodeExists($name) ? $parent->get($name) : $parent->newFolder($name);
		if (!$node instanceof Folder) throw new \RuntimeException('„' . $name . '“ ist eine Datei, erwartet wird ein Ordner. Bitte die Datei umbenennen oder entfernen.');
		return $node;
	}

	private function template(Folder $folder, string $name, string $namespace = '', bool $regionalTemplateRequired = false): mixed {
		$namespace = trim(str_replace('\\', '/', $namespace), '/');
		if ($namespace !== '') {
			$relative = $namespace . '/' . $name;
			if ($folder->nodeExists($relative)) return $folder->get($relative);
			$countrySource = __DIR__ . '/../../resources/templates/' . $relative;
			if (is_file($countrySource)) {
				$countryFolder = $this->installationConfig->ensurePath($folder, $namespace);
				if (!$countryFolder->nodeExists($name)) $countryFolder->newFile($name, (string)file_get_contents($countrySource));
				return $countryFolder->get($name);
			}
			if ($regionalTemplateRequired) {
				throw new \InvalidArgumentException('Für das Länder- oder Regionalprofil ' . str_replace('/', ' / ', $namespace) . ' liegt die fachlich erforderliche Vorlage „' . $name . '“ noch nicht vor. Bitte eine rechtlich geprüfte Vorlage im entsprechenden Vorlagen-Unterordner hinterlegen.');
			}
		}
		$source = __DIR__ . '/../../resources/templates/' . $name;
		if (!$folder->nodeExists($name)) {
			$fallback = $this->condolenceTemplateFallback($folder, $name);
			if ($fallback !== null) return $fallback;
		}
		if (!$folder->nodeExists($name)) {
			if (!is_file($source)) throw new \RuntimeException('Vorlage ' . $name . ' wurde weder im konfigurierten Vorlagenordner noch im App-Paket gefunden.');
			$folder->newFile($name, (string)file_get_contents($source));
		}
		$template = $folder->get($name);
		if (!$template instanceof \OCP\Files\File) throw new \RuntimeException('Vorlage ' . $name . ' ist im Vorlagenordner keine Datei, sondern ein Ordner. Bitte umbenennen oder entfernen.');
		$replaceableHashes = self::REPLACEABLE_STANDARD_TEMPLATE_HASHES[$name] ?? [];
		if (is_file($source) && in_array(hash('sha256', (string)$template->getContent()), $replaceableHashes, true)) {
			$template->putContent((string)file_get_contents($source));
		}
		// A file in the configured Nextcloud template directory always wins.
		// Only a byte-identical former package default listed above is refreshed;
		// every individually modified file remains untouched.
		return $template;
	}

	private function condolenceTemplateFallback(Folder $folder, string $expectedName): mixed {
		$deck = strtoupper($expectedName) === 'KONDOLENZLISTE_DECKBLATT.DOCX';
		$list = strtoupper($expectedName) === 'KONDOLENZLISTE.DOCX';
		if (!$deck && !$list) return null;
		foreach ($folder->getDirectoryListing() as $node) {
			$name = mb_strtolower($node->getName());
			if (!str_ends_with($name, '.docx') || !str_contains($name, 'kondolenz')) continue;
			$isDeck = str_contains($name, 'deck');
			if (($deck && $isDeck) || ($list && !$isDeck)) return $node;
		}
		return null;
	}

	/** 0.75.3: Niederlassung des Falls (für das Logo). */
	private function caseBranchKey(array $case): string {
		return strtoupper(trim((string)($case['branch'] ?? $case['masterData']['branch'] ?? '')));
	}

	/**
	 * 0.75.3: Logo-Platzhalterbild ermitteln. Liefert je XML-Teil die Beziehungs-IDs, die auf das Platzhalterbild zeigen,
	 * und die Namen der Bilddateien.
	 * @return array{media:list<string>,rels:array<string,list<string>>}
	 */
	private function logoPlaceholders(\ZipArchive $zip): array {
		$media = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			if (!str_starts_with($name, 'word/media/')) continue;
			$bytes = $zip->getFromIndex($i);
			if ($bytes !== false && BranchLogoService::isPlaceholder($bytes)) $media[] = $name;
		}
		$rels = [];
		if ($media === []) return ['media' => [], 'rels' => []];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			if (!preg_match('#^word/_rels/(.+)\.rels$#', $name, $match)) continue;
			$xml = (string)$zip->getFromIndex($i);
			preg_match_all('#<Relationship\b[^>]*>#', $xml, $tags);
			foreach ($tags[0] as $tag) {
				if (!preg_match('#\bId="([^"]+)"#', $tag, $id) || !preg_match('#\bTarget="([^"]+)"#', $tag, $target)) continue;
				if (in_array('word/' . ltrim(str_replace('../', '', $target[1]), '/'), $media, true)) $rels['word/' . $match[1]][] = $id[1];
			}
		}
		return ['media' => $media, 'rels' => $rels];
	}

	/** Passt die Größe der Bilder mit den Beziehungs-IDs an das Seitenverhältnis des Logos an (höchstens der Rahmen des Platzhalters). */
	public static function fitImageExtents(string $xml, array $relIds, int $width, int $height): string {
		if ($relIds === [] || $width <= 0 || $height <= 0) return $xml;
		$ratio = $width / $height;
		return (string)preg_replace_callback('#<w:drawing>.*?</w:drawing>#s', static function (array $match) use ($relIds, $ratio): string {
			$drawing = $match[0];
			$hit = false;
			foreach ($relIds as $id) if (str_contains($drawing, 'r:embed="' . $id . '"')) { $hit = true; break; }
			if (!$hit || !preg_match('#<wp:extent cx="(\d+)" cy="(\d+)"#', $drawing, $box)) return $drawing;
			[$cx, $cy] = [(int)$box[1], (int)$box[2]];
			if ($cx <= 0 || $cy <= 0) return $drawing;
			[$newCx, $newCy] = $cx / $cy > $ratio ? [(int)round($cy * $ratio), $cy] : [$cx, (int)round($cx / $ratio)];
			return str_replace(['cx="' . $cx . '" cy="' . $cy . '"'], ['cx="' . $newCx . '" cy="' . $newCy . '"'], $drawing);
		}, $xml);
	}

	private function replaceDocx(string $content, array $values, array $services, ?string $embeddedPaymentQr = null, string $branchKey = ''): string {
		$tmp = tempnam(sys_get_temp_dir(), 'bestatter-docx-');
		if ($tmp === false) throw new \RuntimeException('Temporäre DOCX-Datei konnte nicht erstellt werden.');
		file_put_contents($tmp, $content);
		$zip = new \ZipArchive();
		if ($zip->open($tmp) !== true) { @unlink($tmp); throw new \RuntimeException('DOCX-Vorlage konnte nicht geöffnet werden.'); }
		$paymentQrPlaceholderFound = false;
		$logoPlaceholders = $this->logoPlaceholders($zip);
		$logo = $logoPlaceholders['media'] !== [] && $branchKey !== '' ? $this->logos?->logo($branchKey) : null;
		$logoSize = $logo !== null ? (array)getimagesizefromstring($logo) : [];
		foreach ($logoPlaceholders['media'] as $mediaName) $zip->addFromString($mediaName, $logo ?? (string)file_get_contents(BranchLogoService::EMPTY_FILE));
		// Stornierte Positionen und Paket-Köpfe (Summenzeilen) gehören nicht in Beleg- und Steuerzeilen;
		// die Paketzeile entsteht beim Druck aus ihren Unterpositionen.
		$services = array_values(array_filter($services, static fn(array $service): bool => ($service['serviceStatus'] ?? '') !== 'STORNIERT' && empty($service['isPackageHeader'])));
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if (in_array($name, $logoPlaceholders['media'], true)) continue;
			if (str_starts_with($name, 'word/media/') && $embeddedPaymentQr !== null) {
				$media = $zip->getFromIndex($i);
				$placeholder = __DIR__ . '/../../resources/payment-qr-placeholder.png';
				if ($media !== false && is_file($placeholder) && hash_equals(hash('sha256', (string)file_get_contents($placeholder)), hash('sha256', $media))) {
					$paymentQrPlaceholderFound = true;
					$replacement = $embeddedPaymentQr !== '' ? $embeddedPaymentQr : (string)file_get_contents(__DIR__ . '/../../resources/payment-qr-empty.png');
					$zip->addFromString($name, $replacement);
				}
				continue;
			}
			if (!str_starts_with($name, 'word/') || !str_ends_with($name, '.xml')) continue;
			$xml = $zip->getFromIndex($i);
			if ($xml === false) continue;
			if ($name === 'word/document.xml') {
				$isEstimate = ($values['{{order.document_title}}'] ?? '') === 'Kostenvoranschlag';
				$xml = $this->replaceServiceRows($xml, $services, $isEstimate);
				$xml = $this->replaceInvoiceTaxRows($xml, $services);
				$xml = $this->removeEmptyPassThroughSummary($xml, $services);
				$xml = $this->removeEmptyPriorInvoiceSummary($xml, $values);
			}
			if ($logo !== null && isset($logoPlaceholders['rels'][$name])) $xml = self::fitImageExtents($xml, $logoPlaceholders['rels'][$name], (int)($logoSize[0] ?? 0), (int)($logoSize[1] ?? 0));
			$zip->addFromString($name, $this->replaceTextPlaceholders($xml, $values));
		}
		$zip->close();
		if ($embeddedPaymentQr !== null && $embeddedPaymentQr !== '' && !$paymentQrPlaceholderFound) {
			@unlink($tmp);
			throw new \InvalidArgumentException('Die ausgewählte Rechnungsvorlage enthält keinen kompatiblen QR-Code-Platzhalter. Bitte die aktuelle Standardvorlage RECHNUNG.docx verwenden oder den Platzhalter aus dieser Vorlage in die eigene Vorlage übernehmen.');
		}
		$result = (string)file_get_contents($tmp);
		@unlink($tmp);
		if ($embeddedPaymentQr !== null && $embeddedPaymentQr !== '' && !$this->docxContainsMedia($result, $embeddedPaymentQr)) {
			throw new \RuntimeException('Der Zahlungs-QR-Code konnte nicht verlässlich in das Rechnungsdokument eingebettet werden. Die Ausgabe wurde abgebrochen.');
		}
		return $result;
	}

	private function docxContainsMedia(string $content, string $expectedMedia): bool {
		$tmp = tempnam(sys_get_temp_dir(), 'bestatter-docx-check-');
		if ($tmp === false) return false;
		file_put_contents($tmp, $content);
		$zip = new \ZipArchive();
		if ($zip->open($tmp) !== true) { @unlink($tmp); return false; }
		$expectedHash = hash('sha256', $expectedMedia);
		$found = false;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if (!str_starts_with($name, 'word/media/')) continue;
			$media = $zip->getFromIndex($i);
			if ($media !== false && hash_equals($expectedHash, hash('sha256', $media))) { $found = true; break; }
		}
		$zip->close();
		@unlink($tmp);
		return $found;
	}

	private function replaceServiceRows(string $xml, array $services, bool $isEstimate = false): string {
		$document = new \DOMDocument();
		if (!@$document->loadXML($xml, LIBXML_NONET)) return $xml;
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
		$prototype = ['heading' => null, 'item' => null, 'subtotal' => null];
		foreach ($xpath->query('//w:tr') ?: [] as $row) {
			$text = '';
			foreach ($xpath->query('.//w:t', $row) ?: [] as $node) $text .= $node->nodeValue;
			if (str_contains($text, '{{service.group_heading}}')) $prototype['heading'] = $row;
			if (str_contains($text, '{{service.title}}')) $prototype['item'] = $row;
			if (str_contains($text, '{{service.group_subtotal}}')) $prototype['subtotal'] = $row;
		}
		if ($prototype['heading'] !== null && $prototype['item'] !== null && $prototype['subtotal'] !== null) {
			$labels = $isEstimate
				? ['EL' => 'Eigene Leistungen – verbindliche Angebotspreise', 'FK' => 'Voraussichtliche Fremdleistungen und Fremdkosten', 'DP' => 'Voraussichtliche Gebühren und durchlaufende Posten']
				: ['EL' => 'Eigene Leistungen', 'FK' => 'Fremdleistungen', 'DP' => 'Verauslagte Beträge – durchlaufende Posten ohne Umsatzsteuer'];
			$labels = ['PK' => $isEstimate ? 'Bestattungspakete – verbindliche Angebotspreise' : 'Bestattungspakete'] + $labels;
			$groups = ['PK' => [], 'EL' => [], 'FK' => [], 'DP' => []];
			foreach ($this->printableServiceRows($services) as $service) {
				$type = (string)($service['blockType'] ?? strtoupper((string)($service['positionType'] ?? 'EL')));
				$groups[isset($groups[$type]) ? $type : 'EL'][] = $service;
			}
			$anchor = $prototype['heading'];
			$parent = $anchor->parentNode;
			foreach ($groups as $type => $items) {
				$subtotal = array_sum(array_map(static fn(array $item): int => (int)($item['subtotalGrossCents'] ?? $item['grossCents'] ?? 0), $items));
				if ($items === [] || $subtotal === 0) continue;
				$heading = $prototype['heading']->cloneNode(true);
				$this->replacePlaceholdersInNode($xpath, $heading, ['{{service.group_heading}}' => $labels[$type]], false);
				$parent->insertBefore($heading, $anchor);
				foreach ($items as $position => $service) {
					$item = $prototype['item']->cloneNode(true);
					$this->replacePlaceholdersInNode($xpath, $item, $this->serviceReplacements($service, $position), false);
					$parent->insertBefore($item, $anchor);
				}
				$subtotalRow = $prototype['subtotal']->cloneNode(true);
				$this->replacePlaceholdersInNode($xpath, $subtotalRow, ['{{service.group_subtotal}}' => 'Zwischensumme ' . $labels[$type], '{{service.group_subtotal_amount}}' => $this->money($subtotal)], false);
				$parent->insertBefore($subtotalRow, $anchor);
			}
			foreach ($prototype as $row) $row->parentNode?->removeChild($row);
			return (string)$document->saveXML();
		}
		foreach ($xpath->query('//w:tr') ?: [] as $row) {
			$text = '';
			foreach ($xpath->query('.//w:t', $row) ?: [] as $node) $text .= $node->nodeValue;
			if (!str_contains($text, '{{service.title}}')) continue;
			$parent = $row->parentNode;
			if ($parent === null) continue;
			$sourceRows = $services !== [] ? $this->printableServiceRows($services) : [['articleNumber' => '', 'title' => 'Keine Leistungen beauftragt', 'quantity' => 0, 'unit' => 'STK', 'unitPriceCents' => 0, 'vatRate' => 0, 'netCents' => 0, 'grossCents' => 0, 'sourcePackageName' => '']];
			foreach ($sourceRows as $position => $service) {
				$clone = $row->cloneNode(true);
				$replacements = $this->serviceReplacements($service, $position);
				$this->replacePlaceholdersInNode($xpath, $clone, $replacements, false);
				$parent->insertBefore($clone, $row);
			}
			$parent->removeChild($row);
		}
		return (string)$document->saveXML();
	}

	private function serviceReplacements(array $service, int $position): array {
		$hide = !empty($service['hidePrices']);
		return [
			'{{service.position}}' => (string)($service['positionLabel'] ?? $service['position'] ?? $service['articleNumber'] ?? (($position + 1) * 10)), '{{service.article_number}}' => (string)($service['articleNumber'] ?? ''),
			'{{service.title}}' => (string)($service['title'] ?? ''), '{{service.quantity}}' => trim($this->number((float)($service['quantity'] ?? 0)) . ' ' . $this->unitLabel((string)($service['unit'] ?? 'STK'))),
			'{{service.unit_price}}' => $hide ? '' : $this->money((int)($service['unitPriceCents'] ?? 0)),
			'{{service.vat_rate}}' => $hide ? '' : (string)($service['vatLabel'] ?? ((string)($service['vatRate'] ?? 0) . ' %')),
			'{{service.net}}' => $hide ? '' : $this->money((int)($service['netCents'] ?? 0)), '{{service.gross}}' => $hide ? '' : $this->money((int)($service['grossCents'] ?? 0)),
			'{{service.package}}' => (string)(($service['packageTitle'] ?? '') !== '' ? $service['packageTitle'] : ($service['sourcePackageName'] ?? '')),
		];
	}

	/**
	 * Druckzeilen: Unterpositionen eines Pakets werden zu einer Paketzeile mit der Summe
	 * zusammengefasst. Je nach Druckart des Pakets folgen die Bestandteile ohne Preise
	 * (Standard), mit Preisen oder gar nicht. Steuer- und Summenzeilen rechnen weiterhin
	 * mit den einzelnen Positionen.
	 */
	private function printableServiceRows(array $services): array {
		$byPackage = [];
		foreach ($services as $service) {
			$package = (int)($service['packageServiceId'] ?? 0);
			if ($package > 0) $byPackage[$package][] = $service;
		}
		$rows = [];
		$done = [];
		foreach ($services as $service) {
			$package = (int)($service['packageServiceId'] ?? 0);
			if ($package === 0) {
				$rows[] = $service + ['blockType' => strtoupper((string)($service['positionType'] ?? 'EL')), 'subtotalGrossCents' => (int)($service['grossCents'] ?? 0)];
				continue;
			}
			if (isset($done[$package])) continue;
			$done[$package] = true;
			$children = $byPackage[$package];
			$mode = (string)($children[0]['printMode'] ?? '') ?: 'ITEMS_NO_PRICES';
			$sum = static fn(string $key): int => array_sum(array_map(static fn(array $child): int => (int)($child[$key] ?? 0), $children));
			$rates = array_values(array_unique(array_map(static fn(array $child): int => (int)($child['vatRate'] ?? 0), $children)));
			$net = $sum('netCents');
			$rows[] = [
				'position' => (int)($children[0]['packagePosition'] ?? 0), 'positionLabel' => (string)($children[0]['packagePosition'] ?? ''), 'articleNumber' => '',
				'title' => (string)($children[0]['packageTitle'] ?? 'Paket'), 'quantity' => 1.0, 'unit' => 'PAUSCHAL', 'unitPriceCents' => $net,
				'vatRate' => $rates[0] ?? 0, 'vatLabel' => count($rates) > 1 ? implode(' / ', array_map(static fn(int $rate): string => $rate . ' %', $rates)) : null,
				'netCents' => $net, 'vatCents' => $sum('vatCents'), 'grossCents' => $sum('grossCents'), 'positionType' => 'EL', 'sourcePackageName' => '',
				'blockType' => 'PK', 'subtotalGrossCents' => $sum('grossCents'), 'isPackageRow' => true,
			];
			if ($mode === 'HEADER_ONLY') continue;
			foreach ($children as $child) {
				$rows[] = $child + [
					'blockType' => 'PK', 'subtotalGrossCents' => 0, 'hidePrices' => $mode !== 'ITEMS_WITH_PRICES',
				];
				$rows[array_key_last($rows)]['title'] = '– ' . (string)($child['title'] ?? '');
			}
		}
		return $rows;
	}

	private function replaceInvoiceTaxRows(string $xml, array $services): string {
		$document = new \DOMDocument();
		if (!@$document->loadXML($xml, LIBXML_NONET)) return $xml;
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
		foreach ($xpath->query('//w:tr') ?: [] as $row) {
			$text = '';
			foreach ($xpath->query('.//w:t', $row) ?: [] as $node) $text .= $node->nodeValue;
			if (!str_contains($text, '{{tax.rate}}')) continue;
			$groups = [];
			foreach ($services as $service) {
				if (strtoupper((string)($service['positionType'] ?? 'EL')) === 'DP') continue;
				$rate = (int)($service['vatRate'] ?? 0);
				$groups[$rate] ??= ['net' => 0, 'vat' => 0];
				$groups[$rate]['net'] += (int)($service['netCents'] ?? 0);
				$groups[$rate]['vat'] += (int)($service['vatCents'] ?? round((int)($service['netCents'] ?? 0) * $rate / 100));
			}
			ksort($groups, SORT_NUMERIC);
			$parent = $row->parentNode;
			foreach ($groups as $rate => $amounts) {
				if ($amounts['net'] === 0 && $amounts['vat'] === 0) continue;
				$clone = $row->cloneNode(true);
				$this->replacePlaceholdersInNode($xpath, $clone, ['{{tax.rate}}' => $rate . ' %', '{{tax.net}}' => $this->money($amounts['net']), '{{tax.vat}}' => $this->money($amounts['vat'])], false);
				$parent->insertBefore($clone, $row);
			}
			$parent->removeChild($row);
		}
		return (string)$document->saveXML();
	}

	private function removeEmptyPassThroughSummary(string $xml, array $services): string {
		$passThrough = array_sum(array_map(static fn(array $item): int => strtoupper((string)($item['positionType'] ?? 'EL')) === 'DP' ? (int)($item['grossCents'] ?? 0) : 0, $services));
		if ($passThrough !== 0) return $xml;
		$document = new \DOMDocument();
		if (!@$document->loadXML($xml, LIBXML_NONET)) return $xml;
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
		foreach ($xpath->query('//w:tr') ?: [] as $row) {
			$text = '';
			foreach ($xpath->query('.//w:t', $row) ?: [] as $node) $text .= $node->nodeValue;
			if (str_contains($text, '{{order.pass_through_total}}')) $row->parentNode?->removeChild($row);
		}
		return (string)$document->saveXML();
	}

	private function removeEmptyPriorInvoiceSummary(string $xml, array $values): string {
		if (($values['{{case.invoice_has_prior}}'] ?? '0') === '1') return $xml;
		$document = new \DOMDocument();
		if (!@$document->loadXML($xml, LIBXML_NONET)) return $xml;
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
		foreach ($xpath->query('//w:tr') ?: [] as $row) {
			$text = '';
			foreach ($xpath->query('.//w:t', $row) ?: [] as $node) $text .= $node->nodeValue;
			if (str_contains($text, '{{case.invoice_prior_gross}}')) $row->parentNode?->removeChild($row);
		}
		return (string)$document->saveXML();
	}

	/**
	 * Word may split one placeholder over several formatted w:t runs. Replacing
	 * the raw XML therefore leaves visible field names behind. This routine
	 * resolves placeholders per paragraph across run boundaries and lets DOM
	 * perform the UTF-8/XML escaping. Any complete placeholder that has no
	 * value in the current document context is deliberately rendered empty.
	 */
	private function replaceTextPlaceholders(string $xml, array $values): string {
		$document = new \DOMDocument();
		$document->preserveWhiteSpace = true;
		if (!@$document->loadXML($xml, LIBXML_NONET)) return $xml;
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
		$this->replacePlaceholdersInNode($xpath, $document, $values, true);
		return (string)$document->saveXML();
	}

	private function replacePlaceholdersInNode(\DOMXPath $xpath, \DOMNode $context, array $values, bool $blankUnknown): void {
		$paragraphs = $xpath->query($context instanceof \DOMDocument ? '//w:p' : './/w:p', $context);
		foreach ($paragraphs ?: [] as $paragraph) {
			$textNodes = [];
			$text = '';
			foreach ($xpath->query('.//w:t', $paragraph) ?: [] as $node) {
				$textNodes[] = $node;
				$text .= (string)$node->nodeValue;
			}
			if ($textNodes === [] || !str_contains($text, '{{')) continue;
			preg_match_all('/\{\{[^{}]+\}\}/u', $text, $matches, PREG_OFFSET_CAPTURE);
			foreach (array_reverse($matches[0] ?? []) as [$placeholder, $offset]) {
				if (!array_key_exists($placeholder, $values) && !$blankUnknown) continue;
				$replacement = array_key_exists($placeholder, $values) ? (string)$values[$placeholder] : $this->formattedPlaceholder($placeholder, $values);
				$this->replaceTextRange($textNodes, (int)$offset, strlen((string)$placeholder), $replacement);
			}
		}
	}

	private function replaceTextRange(array $nodes, int $start, int $length, string $replacement): void {
		$end = $start + $length;
		$cursor = 0;
		$startIndex = null; $endIndex = null; $startLocal = 0; $endLocal = 0;
		foreach ($nodes as $index => $node) {
			$nodeLength = strlen((string)$node->nodeValue);
			$next = $cursor + $nodeLength;
			if ($startIndex === null && $start >= $cursor && $start < $next) {
				$startIndex = $index;
				$startLocal = $start - $cursor;
			}
			if ($end > $cursor && $end <= $next) {
				$endIndex = $index;
				$endLocal = $end - $cursor;
				break;
			}
			$cursor = $next;
		}
		if ($startIndex === null || $endIndex === null) return;
		$startValue = (string)$nodes[$startIndex]->nodeValue;
		if ($startIndex === $endIndex) {
			$this->setTextNodeContent($nodes[$startIndex], substr($startValue, 0, $startLocal) . $replacement . substr($startValue, $endLocal));
			$this->preserveTextWhitespace($nodes[$startIndex]);
			return;
		}
		$endValue = (string)$nodes[$endIndex]->nodeValue;
		$this->setTextNodeContent($nodes[$startIndex], substr($startValue, 0, $startLocal) . $replacement);
		for ($index = $startIndex + 1; $index < $endIndex; $index++) $this->setTextNodeContent($nodes[$index], '');
		$this->setTextNodeContent($nodes[$endIndex], substr($endValue, $endLocal));
		$this->preserveTextWhitespace($nodes[$startIndex]);
		$this->preserveTextWhitespace($nodes[$endIndex]);
	}

	private function setTextNodeContent(\DOMNode $node, string $value): void {
		while ($node->firstChild !== null) $node->removeChild($node->firstChild);
		$node->appendChild($node->ownerDocument->createTextNode($value));
	}

	private function preserveTextWhitespace(\DOMNode $node): void {
		$value = (string)$node->nodeValue;
		if ($node instanceof \DOMElement && $value !== trim($value)) $node->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
	}

	private function placeholderData(array $case, array $costing): array {
		$m = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$branch = $this->configuration->branchByKey((string)($case['branch'] ?? $m['branch'] ?? '')) ?? [];
		$values = [];
		foreach ($m as $key => $raw) if (is_scalar($raw) || $raw === null) $values['{{case.' . $key . '}}'] = $this->formatDocumentScalar((string)($raw ?? ''));
		$value = static fn(string $key, string $fallback = ''): string => (string)($m[$key] ?? $fallback);
		$isEstimate = strtoupper($value('order_mode', 'A')) === 'KVA';
		$taxableNetFromItems = 0;
		$passThroughFromItems = 0;
		foreach (($costing['items'] ?? []) as $item) {
			if (($item['serviceStatus'] ?? '') === 'STORNIERT' || !empty($item['isPackageHeader'])) continue;
			if (strtoupper((string)($item['positionType'] ?? 'EL')) === 'DP') $passThroughFromItems += (int)($item['grossCents'] ?? 0);
			else $taxableNetFromItems += (int)($item['netCents'] ?? 0);
		}
		if (($costing['items'] ?? []) === []) $taxableNetFromItems = (int)($costing['totals']['netCents'] ?? 0);
		$lastResidence = trim(implode(', ', array_filter([$value('last_residence_street', $value('last_residence')), trim($value('last_residence_postal_code') . ' ' . $value('last_residence_city')), $value('last_residence_country', 'Deutschland')])));
		$overrides = [
			'{{case.id}}' => (string)$case['caseNumber'], '{{case.case_number}}' => (string)$case['caseNumber'], '{{case.number}}' => (string)$case['caseNumber'],
			'{{case.first_name}}' => (string)$case['firstName'], '{{case.last_name}}' => (string)$case['lastName'],
			'{{case.date_of_death}}' => $this->deathDisplay($m, (string)($case['dateOfDeath'] ?? '')),
			'{{case.time_of_death}}' => $value('date_of_death') !== '' ? $value('time_of_death') : '',
			'{{case.last_residence}}' => $lastResidence, '{{case.cemetery}}' => $value('cemetery_contact', $value('cemetery')),
			'{{branch.name}}' => $value('branch'), '{{standesamt}}' => $value('registry_office'),
			'{{case.branch_name}}' => (string)($branch['name'] ?? $value('branch')), '{{case.branch_address}}' => trim(implode(', ', array_filter([(string)($branch['street'] ?? ''), trim((string)($branch['postalCode'] ?? '') . ' ' . (string)($branch['city'] ?? ''))]))),
			'{{case.branch_phone}}' => (string)($branch['phone'] ?? ''), '{{case.branch_email}}' => (string)($branch['email'] ?? ''), '{{case.branch_city}}' => (string)($branch['city'] ?? ''),
			'{{case.current_date}}' => date('d.m.Y'), '{{case.recipient_name}}' => $value('recipient_name'), '{{case.recipient_address}}' => $value('recipient_address'),
			'{{deceased_name}}' => trim((string)$case['firstName'] . ' ' . (string)$case['lastName']),
			'{{funeral_event.date}}' => $value('funeral_event_date'), '{{funeral_event.date_iso}}' => $value('funeral_event_date_iso'),
			'{{funeral_event.time}}' => $value('funeral_event_time'), '{{funeral_event.end_time}}' => $value('funeral_event_end_time'),
			'{{funeral_event.location}}' => $value('funeral_event_location'), '{{funeral_event.category}}' => $value('funeral_event_category'),
			'{{funeral_event.external_participants}}' => $value('funeral_event_external_participants'),
			'{{religion}}' => $value('religion'), '{{todesort_anschrift}}' => $value('place_of_death'),
			'{{minderjaehrige_kinder}}' => $value('minor_children_count'), '{{eingang_am}}' => '',
			'{{sterberegister_nr}}' => $value('registry_reference'), '{{geburtsregister_hinweis}}' => $value('birth_registry_reference'),
			'{{ehe_register_hinweis}}' => $value('marriage_registry_reference'), '{{lebenspartnerschaft_register_hinweis}}' => $value('partnership_registry_reference'),
			'{{nachweise}}' => $value('registry_documents'), '{{pruefvermerk}}' => '',
			'{{anzeigende_person}}' => trim($value('order_client_first_name') . ' ' . $value('order_client_name')),
			'{{anzeigende_anschrift}}' => trim(implode(', ', array_filter([$value('order_client_street'), $value('order_client_postal_city'), $value('order_client_country', 'Deutschland')]))),
			'{{auftrag_telefon}}' => $value('order_client_phone', $value('order_client_mobile')),
			'{{order.client_phone}}' => $value('order_client_phone', $value('order_client_mobile')),
			'{{urkunden_gebuehrenfrei}}' => $value('certificate_free_count', '2'),
			'{{urkunden_gebuehrenpflichtig}}' => $value('urkunden_gebuehrenpflichtig', '0'),
			'{{order.urkunden_gebuehrenpflichtig}}' => $value('urkunden_gebuehrenpflichtig', '0'),
			// 0.75.2: Ort der Niederlassung statt ihres internen Schlüssels (vorher „LINDENHOF, 01.10.2026“).
			'{{ort_datum}}' => trim((trim((string)($branch['city'] ?? '')) ?: $value('branch')) . ', ' . date('d.m.Y'), ', '),
			'{{order.document_title}}' => $isEstimate ? 'Kostenvoranschlag' : 'Bestattungsauftrag',
			'{{order.confirmation_text}}' => $isEstimate ? 'Dieser Kostenvoranschlag stellt die ausgewählten Bestattungsleistungen sowie die zum Erstellungsdatum voraussichtlich anfallenden Kosten dar.' : 'Ich beauftrage das Bestattungsunternehmen mit den abgestimmten Bestattungsleistungen und bestätige die Angaben dieses Auftrags.',
			'{{order.services_heading}}' => $isEstimate ? 'Voraussichtliche Leistungen und Kosten' : 'Beauftragte Leistungen und Kosten',
			'{{order.cost_note}}' => $isEstimate
				? 'Die Preise unserer eigenen Leistungen sind bis zum ausgewiesenen Gültigkeitsdatum verbindlich, sofern Art und Umfang der aufgeführten Leistungen unverändert bleiben. Beträge für Fremdleistungen und Fremdkosten sind Schätzbeträge, soweit sie nicht ausdrücklich als Festpreis bezeichnet sind; Preisänderungen Dritter können den Endbetrag verändern. Durchlaufende Posten werden in der tatsächlich verauslagten Höhe weitergegeben. Sobald eine wesentliche Kostenabweichung erkennbar wird, informieren wir den Auftraggeber unverzüglich.'
				: 'Die Einzelpreise verstehen sich netto. Die ausgewiesene Gesamtsumme enthält die gesetzliche Mehrwertsteuer. Fremdleistungen, Auslagen und Gebühren werden entsprechend ihrer vertraglichen und steuerlichen Behandlung dargestellt.',
			'{{order.tax_heading}}' => $isEstimate ? 'Voraussichtliche Umsatzsteuerübersicht' : 'Umsatzsteuerübersicht',
			'{{order.taxable_net_label}}' => $isEstimate ? 'Voraussichtliches steuerpflichtiges Entgelt netto' : 'Steuerpflichtiges Entgelt netto',
			'{{order.total_vat_label}}' => $isEstimate ? 'Voraussichtliche Mehrwertsteuer' : 'Mehrwertsteuer',
			'{{order.pass_through_label}}' => $isEstimate ? 'Voraussichtliche durchlaufende Posten' : 'Durchlaufende Posten',
			'{{order.total_gross_label}}' => $isEstimate ? 'Voraussichtlicher Gesamtbetrag' : 'Gesamtbetrag',
			'{{order.status}}' => $value('order_status', 'Entwurf'),
			'{{order.workflow_detail}}' => $isEstimate ? $value('kva_valid_until') : $value('commissioning_type'),
			'{{order.kva_number}}' => $value('order_number', $value('kva_number')),
			'{{order.kva_date}}' => $this->formatDocumentDate($isEstimate ? $value('kva_date') : $value('order_date')),
			'{{order.order_client_name}}' => trim($value('order_client_first_name') . ' ' . $value('order_client_name')),
			'{{order.order_client_relation}}' => $value('order_client_relation'),
			'{{order.order_client_phone}}' => $value('order_client_phone'), '{{order.order_client_mobile}}' => $value('order_client_mobile'),
			'{{order.order_client_email}}' => $value('order_client_email'),
			'{{order.invoice_recipient_address}}' => $value('invoice_recipient_address', trim(implode(', ', array_filter([$value('invoice_first_name', $value('order_client_first_name')), $value('invoice_name', $value('order_client_name')), $value('invoice_street', $value('order_client_street')), $value('invoice_postal_city', $value('order_client_postal_city')), $value('invoice_country', $value('order_client_country', 'Deutschland'))])))),
			'{{order.certificate_free_count}}' => $value('certificate_free_count', '2'),
			'{{order.order_signature_name}}' => $value('order_signature_name'), '{{order.order_signature_date}}' => $this->formatDocumentDate($value('order_signature_date')),
			'{{order.total_net}}' => $this->money((int)($costing['totals']['netCents'] ?? 0)),
			'{{order.total_vat}}' => $this->money((int)($costing['totals']['vatCents'] ?? 0)),
			'{{order.total_gross}}' => $this->money((int)($costing['totals']['grossCents'] ?? 0)),
			'{{order.taxable_net}}' => $this->money((int)($costing['invoiceTaxableNetCents'] ?? $taxableNetFromItems)),
			'{{order.pass_through_total}}' => $this->money((int)($costing['invoicePassThroughCents'] ?? $passThroughFromItems)),
		];
		$overrides += $this->orderPowerOfAttorneyValues($case, $m, $branch, $costing);
		// 0.76.0: {{marker}} und {{marker.SCHLUESSEL}}
		$labelValues = $this->labels !== null && (int)($case['id'] ?? 0) > 0 ? $this->labels->placeholderValues((int)$case['id']) : [];
		return array_merge($values, $overrides, $labelValues);
	}

	/**
	 * 0.88.0: Platzhalter der Vorlage „Bestattungsauftrag und Vollmacht“ und der Widerrufsbelehrung. Kästchen werden aus dem
	 * Fall vorbelegt (☒), alle übrigen bleiben zum Ankreuzen (☐). Die Erklärungen zum Leistungsbeginn kreuzt nur der/die
	 * Auftraggeber/in selbst an; sie erscheinen nur, wenn der Vertrag außerhalb der Geschäftsräume oder im Fernabsatz geschlossen wurde.
	 * @return array<string, string>
	 */
	private function orderPowerOfAttorneyValues(array $case, array $m, array $branch, array $costing): array {
		$value = static fn(string $key): string => trim((string)($m[$key] ?? ''));
		$box = static fn(bool $checked): string => $checked ? '☒' : '☐';
		$groups = [];
		foreach (($costing['items'] ?? []) as $item) {
			if (($item['serviceStatus'] ?? '') === 'STORNIERT') continue;
			$number = (string)($item['articleNumber'] ?? '');
			if ($number !== '') $groups[substr($number, 0, 2)] = true;
		}
		$variants = $this->customizing?->valuesForKey('BURIAL_VARIANT') ?? [];
		$facts = ProcessRules::funeralFacts((string)($case['burialVariantCode'] ?? $m['burial_variant_code'] ?? ''), (string)($case['funeralType'] ?? $m['funeral_type'] ?? ''), $variants);
		$ceremony = ($case['withFuneralCeremony'] ?? null) === true || isset($groups['14']);
		$formalities = isset($groups['15']);
		$conclusion = strtoupper($value('order_contract_conclusion'));
		$withdrawal = self::withdrawalApplies($m);
		$postalCity = $value('order_client_postal_city');
		$country = $value('order_client_country');
		$payment = ['TRANSFER' => 'Überweisung', 'SEPA_DIRECT_DEBIT' => 'SEPA-Lastschrift'][strtoupper($value('payment_method'))] ?? '';
		$register = trim((string)($branch['registerCourt'] ?? '') . ' ' . (string)($branch['registerNumber'] ?? ''));
		return [
			'{{order.client_address}}' => implode(', ', array_filter([$value('order_client_street'), $postalCity, $country !== '' && mb_strtolower($country) !== 'deutschland' ? $country : ''])),
			'{{order.client_phone_line}}' => implode(' / ', array_filter([$value('order_client_phone'), $value('order_client_mobile')])),
			'{{order.contractor_contact}}' => implode(' · ', array_filter([(string)($branch['phone'] ?? ''), (string)($branch['email'] ?? '')])),
			'{{order.contractor_legal}}' => implode(' · ', array_filter([
				($branch['vatId'] ?? '') !== '' ? 'USt-IdNr. ' . $branch['vatId'] : '',
				$register,
				($branch['managingDirectors'] ?? '') !== '' ? 'vertreten durch ' . $branch['managingDirectors'] : '',
			])),
			'{{order.scope_transfer}}' => $box(isset($groups['11']) || isset($groups['12'])),
			'{{order.scope_ceremony}}' => $box($ceremony),
			'{{order.scope_formalities}}' => $box($formalities),
			'{{order.payment_terms}}' => implode(' · ', array_filter([$payment, $value('order_payment_terms')])),
			'{{order.special_terms}}' => $value('order_special_terms'),
			'{{order.poa_registry}}' => $box(true),
			'{{order.poa_crematorium}}' => $box($facts['scope'] === 'CREMATION' || isset($groups['63'])),
			'{{order.poa_ceremony}}' => $box($ceremony),
			'{{order.poa_deregistration}}' => $box($formalities),
			'{{order.poa_other}}' => $box($value('order_poa_other') !== ''),
			'{{order.poa_other_text}}' => $value('order_poa_other'),
			'{{order.contract_conclusion}}' => self::CONTRACT_CONCLUSIONS[$conclusion] ?? '',
			'{{order.withdrawal_note}}' => match ($conclusion) {
				'AUSSERHALB', 'FERNABSATZ' => 'Dieser Vertrag wurde ' . self::CONTRACT_CONCLUSIONS[$conclusion] . ' geschlossen. Ihnen steht ein gesetzliches Widerrufsrecht zu; die Widerrufsbelehrung mit Muster-Widerrufsformular ist beigefügt. Die folgenden Erklärungen sind freiwillig; das Widerrufsrecht wird durch die Beauftragung nicht ausgeschlossen.',
				'GESCHAEFTSRAEUME' => 'Dieser Vertrag wurde in den Geschäftsräumen des Bestattungsunternehmens geschlossen. Eine Widerrufsbelehrung für außerhalb von Geschäftsräumen oder im Fernabsatz geschlossene Verträge ist daher nicht beigefügt.',
				default => 'Ort des Vertragsschlusses: bitte im Auftrag angeben (Geschäftsräume, außerhalb der Geschäftsräume oder Fernabsatz).',
			},
			'{{order.withdrawal_request_box}}' => $withdrawal ? '☐' : '',
			'{{order.withdrawal_request}}' => $withdrawal ? 'Ich verlange ausdrücklich, dass das Bestattungsunternehmen vor Ablauf der Widerrufsfrist mit den beauftragten Dienstleistungen beginnt.' : '',
			'{{order.withdrawal_ack_box}}' => $withdrawal ? '☐' : '',
			'{{order.withdrawal_ack}}' => $withdrawal ? 'Mir ist bekannt, dass ich bei einem Widerruf einen angemessenen Betrag für die bis dahin erbrachten Dienstleistungen zu zahlen habe und dass mein Widerrufsrecht bei vollständiger Erbringung der Dienstleistungen erlischt.' : '',
			'{{order.digital_signature_line}}' => $value('order_signature_name') !== '' ? 'Digital bestätigt durch ' . $value('order_signature_name') . ($value('order_signature_date') !== '' ? ' am ' . $this->formatDocumentDate($value('order_signature_date')) : '') . '.' : '',
			'{{order.withdrawal_enclosure}}' => $withdrawal ? ' sowie der Widerrufsbelehrung mit Muster-Widerrufsformular' : '',
		];
	}

	private function deathDisplay(array $m, string $fallback = ''): string {
		$exact = trim((string)($m['date_of_death'] ?? $fallback));
		if ($exact !== '') return $this->formatDocumentDate($exact) . (($m['time_of_death'] ?? '') !== '' ? ' ' . $m['time_of_death'] . ' Uhr' : '');
		$from = trim((string)($m['death_time_from'] ?? '')); $to = trim((string)($m['death_time_to'] ?? ''));
		return $from !== '' || $to !== '' ? 'zwischen ' . ($from !== '' ? $this->formatDocumentDate($from) : '?') . ' und ' . ($to !== '' ? $this->formatDocumentDate($to) : '?') : '';
	}

	private function formattedPlaceholder(string $placeholder, array $values): string {
		if (!preg_match('/^\{\{([^{}|]+)\|date:(DD\.MM\.YYYY|DD\.MM\.YY|YYYY-MM-DD)\}\}$/u', $placeholder, $match)) return '';
		$base = '{{' . $match[1] . '}}';
		if (!array_key_exists($base, $values)) return '';
		$format = ['DD.MM.YYYY' => 'd.m.Y', 'DD.MM.YY' => 'd.m.y', 'YYYY-MM-DD' => 'Y-m-d'][$match[2]];
		return $this->formatDocumentDate((string)$values[$base], $format);
	}

	private function formatDocumentScalar(string $value): string {
		return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) ? $this->formatDocumentDate($value) : $value;
	}

	private function formatDocumentDate(string $value, string $format = 'd.m.Y'): string {
		$value = trim($value);
		if ($value === '') return '';
		foreach (['!Y-m-d', '!d.m.Y', '!d.m.y'] as $inputFormat) {
			$date = \DateTimeImmutable::createFromFormat($inputFormat, substr($value, 0, 10));
			if ($date instanceof \DateTimeImmutable) return $date->format($format);
		}
		return $value;
	}

	private function money(int $cents): string { return number_format($cents / 100, 2, ',', '.') . ' EUR'; }
	private function number(float $value): string { return rtrim(rtrim(number_format($value, 3, ',', ''), '0'), ','); }
	/** Einheit in der Mengenspalte von KVA, Auftrag und Rechnung; Pauschalen erscheinen nur mit der Menge („1“). */
	private function unitLabel(string $unit): string { return ['STK' => 'Stk.', 'PAUSCHAL' => '', 'SATZ' => 'Satz', 'PERS' => 'Pers.', 'STD' => 'Std.', 'KM' => 'km', 'TAG' => 'Tage', 'KG' => 'kg', 'L' => 'l'][$unit] ?? $unit; }
}
