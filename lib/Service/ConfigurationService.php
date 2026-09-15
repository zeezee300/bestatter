<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\Files\Folder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserSession;

class ConfigurationService {
	private const DOCUMENT_RESOURCE = __DIR__ . '/../../resources/document-templates.json';
	private const DEREGISTRATION_RESOURCE = __DIR__ . '/../../resources/deregistration-templates.json';

	public function __construct(
		private IDBConnection $db,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private TeamService $team,
		private FolderService $folders,
		private TemplateFieldCatalogService $templateFields,
		private CountryConfigurationService $countryConfiguration,
	) {}

	public function ensureSeedData(): void {
		$this->seedDocuments();
		$this->seedDeregistrations();
	}

	public function documents(): array {
		$this->ensureSeedData();
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_document_templates')->orderBy('sort_order', 'ASC')->addOrderBy('name', 'ASC')->executeQuery()->fetchAllAssociative();
		return array_map(static fn(array $row): array => [
			'id' => (int)$row['id'], 'key' => $row['template_key'], 'name' => $row['name'], 'category' => $row['category'],
			'description' => $row['description'] ?? '', 'fileName' => $row['file_name'] ?? '', 'active' => (bool)$row['active'],
			'requiredFields' => json_decode((string)($row['required_fields'] ?? '[]'), true) ?: [],
			'outputSubfolder' => (string)($row['output_subfolder'] ?? ''), 'supportsPdf' => (bool)($row['supports_pdf'] ?? true),
			'sortOrder' => (int)$row['sort_order'], 'updatedAt' => $row['updated_at'],
		], $rows);
	}

	public function saveDocument(array $data, int $id = 0): array {
		$key = strtoupper(trim((string)($data['key'] ?? '')));
		$name = trim((string)($data['name'] ?? ''));
		if ($key === '' || $name === '') throw new \InvalidArgumentException('Vorlagenschlüssel und Bezeichnung sind Pflichtfelder.');
		if (!preg_match('/^[A-Z0-9_-]+$/', $key)) throw new \InvalidArgumentException('Der Vorlagenschlüssel darf nur Buchstaben, Ziffern, Unterstriche und Bindestriche enthalten.');
		$this->assertUniqueKey('bestatter_document_templates', 'template_key', $key, $id, 'Vorlagenschlüssel');
		$fileName = basename(trim((string)($data['fileName'] ?? '')));
		if ($fileName === '' || !preg_match('/\.docx$/i', $fileName)) throw new \InvalidArgumentException('Bitte eine DOCX-Datei aus dem Bestatter-Vorlagenordner auswählen.');
		if (!in_array($fileName, $this->templateFileNames(), true)) throw new \InvalidArgumentException('Die ausgewählte DOCX-Datei ist im konfigurierten Vorlagenordner nicht vorhanden.');
		$this->validateTemplatePlaceholders($fileName);
		$outputSubfolder = trim((string)($data['outputSubfolder'] ?? ''));
		if (!in_array($outputSubfolder, $this->folders->allowedSubfolders(), true)) throw new \InvalidArgumentException('Bitte einen zulässigen Standard-Unterordner der Fallakte auswählen.');
		$requiredFields = array_values(array_unique(array_filter(array_map('trim', (array)($data['requiredFields'] ?? [])))));
		$allowedFields = $this->allowedRequiredFields();
		$invalidFields = array_values(array_diff($requiredFields, $allowedFields));
		if ($invalidFields !== []) throw new \InvalidArgumentException('Unbekannte Pflichtfelder: ' . implode(', ', $invalidFields) . '. Bitte den Feldkatalog verwenden.');
		$values = [
			'template_key' => $key, 'name' => $name, 'category' => trim((string)($data['category'] ?? 'Allgemein')),
			'description' => trim((string)($data['description'] ?? '')), 'file_name' => $fileName,
			'required_fields' => json_encode($requiredFields, JSON_THROW_ON_ERROR),
			'output_subfolder' => $outputSubfolder, 'supports_pdf' => (int)($data['supportsPdf'] ?? true),
			'active' => (int)($data['active'] ?? true), 'sort_order' => (int)($data['sortOrder'] ?? 0), 'updated_at' => date('c'),
		];
		$this->upsert('bestatter_document_templates', $values, $id);
		return $this->findByKey($this->documents(), $key);
	}

	public function documentTemplateOptions(): array {
		return [
			'files' => array_map(static fn(string $name): array => ['fileName' => $name, 'label' => $name], $this->templateFileNames()),
			'subfolders' => $this->folders->allowedSubfolders(),
			'requiredFields' => $this->allowedRequiredFields(),
			'templatePath' => $this->folders->configuredPaths()['templates'],
		];
	}

	public function duplicateDocument(int $id): array {
		$source = null;
		foreach ($this->documents() as $template) if ((int)$template['id'] === $id) { $source = $template; break; }
		if ($source === null) throw new \InvalidArgumentException('Dokumentvorlage wurde nicht gefunden.');
		$base = substr((string)$source['key'], 0, 88) . '_COPY'; $key = $base; $number = 2;
		while ($this->exists('bestatter_document_templates', 'template_key', $key)) $key = $base . '_' . $number++;
		$source['key'] = $key; $source['name'] = (string)$source['name'] . ' (Kopie)'; $source['active'] = false; $source['sortOrder'] = (int)$source['sortOrder'] + 1;
		return $this->saveDocument($source, 0);
	}

	public function deregistrations(): array {
		$this->ensureSeedData();
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_dereg_templates')->orderBy('sort_order', 'ASC')->addOrderBy('name', 'ASC')->executeQuery()->fetchAllAssociative();
		return array_map(static fn(array $row): array => [
			'id' => (int)$row['id'], 'key' => $row['template_key'], 'recipientType' => $row['recipient_type'], 'name' => $row['name'],
			'contactCategory' => $row['contact_category'], 'deliveryChannels' => json_decode((string)$row['delivery_channels'], true) ?: [],
			'defaultChannel' => $row['default_channel'], 'subjectTemplate' => $row['subject_template'], 'bodyTemplate' => $row['body_template'],
			'formType' => $row['form_type'], 'requiredFields' => json_decode((string)($row['required_fields'] ?? '[]'), true) ?: [],
			'allowedStatuses' => json_decode((string)($row['allowed_statuses'] ?? '[]'), true) ?: ['ENTWURF', 'VORBEREITET', 'VERSENDET', 'BESTAETIGT', 'ERLEDIGT'],
			'followUpDays' => (int)($row['follow_up_days'] ?? 0), 'documentTemplateKey' => (string)($row['document_template_key'] ?? ''),
			'active' => (bool)$row['active'], 'sortOrder' => (int)$row['sort_order'], 'updatedAt' => $row['updated_at'],
		], $rows);
	}

	public function saveDeregistration(array $data, int $id = 0): array {
		$key = strtoupper(trim((string)($data['key'] ?? '')));
		$name = trim((string)($data['name'] ?? ''));
		if ($key === '' || $name === '') throw new \InvalidArgumentException('Abmeldungsschlüssel und Bezeichnung sind Pflichtfelder.');
		if (!preg_match('/^[A-Z0-9_-]+$/', $key)) throw new \InvalidArgumentException('Der Abmeldungsschlüssel darf nur Buchstaben, Ziffern, Unterstriche und Bindestriche enthalten.');
		$this->assertUniqueKey('bestatter_dereg_templates', 'template_key', $key, $id, 'Abmeldungsschlüssel');
		$channels = array_values(array_intersect(array_map('strtoupper', (array)($data['deliveryChannels'] ?? [])), ['EMAIL', 'POST', 'PORTAL', 'TELEFON']));
		$default = strtoupper((string)($data['defaultChannel'] ?? ''));
		if ($default !== '' && !in_array($default, $channels, true)) $default = '';
		$values = [
			'template_key' => $key, 'recipient_type' => strtoupper(trim((string)($data['recipientType'] ?? $key))), 'name' => $name,
			'contact_category' => trim((string)($data['contactCategory'] ?? '')), 'delivery_channels' => json_encode($channels, JSON_THROW_ON_ERROR),
			'default_channel' => $default, 'subject_template' => trim((string)($data['subjectTemplate'] ?? '')),
			'body_template' => trim((string)($data['bodyTemplate'] ?? '')), 'form_type' => strtoupper(trim((string)($data['formType'] ?? 'STANDARD'))),
			'required_fields' => json_encode(array_values(array_unique(array_filter(array_map('trim', (array)($data['requiredFields'] ?? []))))), JSON_THROW_ON_ERROR),
			'allowed_statuses' => json_encode(array_values(array_intersect(array_map('strtoupper', (array)($data['allowedStatuses'] ?? ['ENTWURF', 'VORBEREITET', 'VERSENDET', 'BESTAETIGT', 'ERLEDIGT'])), ['ENTWURF', 'VORBEREITET', 'VERSENDET', 'BESTAETIGT', 'ERLEDIGT'])), JSON_THROW_ON_ERROR),
			'follow_up_days' => max(0, (int)($data['followUpDays'] ?? 0)), 'document_template_key' => strtoupper(trim((string)($data['documentTemplateKey'] ?? ''))),
			'active' => (int)($data['active'] ?? true), 'sort_order' => (int)($data['sortOrder'] ?? 0), 'updated_at' => date('c'),
		];
		$this->upsert('bestatter_dereg_templates', $values, $id);
		return $this->findByKey($this->deregistrations(), $key);
	}

	public function branches(): array {
		$this->countryConfiguration->profiles();
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_branches')->orderBy('sort_order', 'ASC')->addOrderBy('name', 'ASC')->executeQuery()->fetchAllAssociative();
		return array_map(static fn(array $row): array => [
			'id' => (int)$row['id'], 'key' => $row['branch_key'], 'name' => $row['name'], 'street' => $row['street'] ?? '',
			'postalCode' => $row['postal_code'] ?? '', 'city' => $row['city'] ?? '', 'country' => $row['country'] ?: 'Deutschland',
			'countryCode' => strtoupper((string)($row['country_code'] ?? 'DE')), 'federalState' => (string)($row['federal_state'] ?? ''),
			'invoiceProfile' => strtoupper((string)($row['invoice_profile'] ?? 'ZUGFERD')), 'paymentQrStandard' => strtoupper((string)($row['payment_qr_standard'] ?? 'EPC069-12')),
			'phone' => $row['phone'] ?? '', 'email' => $row['email'] ?? '',
			'accountHolder' => $row['account_holder'] ?? '', 'bankName' => $row['bank_name'] ?? '', 'iban' => $row['iban'] ?? '', 'bic' => $row['bic'] ?? '',
			'creditorId' => $row['creditor_id'] ?? '', 'paymentTermDays' => (int)($row['payment_term_days'] ?? 14),
			'vatId' => $row['vat_id'] ?? '', 'taxNumber' => $row['tax_number'] ?? '', 'registerCourt' => $row['register_court'] ?? '',
			'registerNumber' => $row['register_number'] ?? '', 'managingDirectors' => $row['managing_directors'] ?? '', 'electronicAddress' => $row['electronic_address'] ?? '',
			'memberUids' => json_decode((string)($row['member_uids'] ?? '[]'), true) ?: [], 'active' => (bool)$row['active'], 'sortOrder' => (int)$row['sort_order'],
		], $rows);
	}

	public function saveBranch(array $data, int $id = 0): array {
		$key = strtoupper(trim((string)($data['key'] ?? '')));
		$name = trim((string)($data['name'] ?? ''));
		if ($key === '' || $name === '') throw new \InvalidArgumentException('Niederlassungsschlüssel und Name sind Pflichtfelder.');
		if (!preg_match('/^[A-Z0-9_-]+$/', $key)) throw new \InvalidArgumentException('Der Niederlassungsschlüssel darf keine Leerzeichen enthalten. Erlaubt sind Buchstaben, Ziffern, Unterstriche und Bindestriche.');
		$this->assertUniqueKey('bestatter_branches', 'branch_key', $key, $id, 'Niederlassungsschlüssel');
		$memberUids = array_values(array_unique(array_filter(array_map('trim', (array)($data['memberUids'] ?? [])))));
		foreach ($memberUids as $uid) {
			if ($this->team->member($uid) === null) throw new \InvalidArgumentException('Der Benutzer ' . $uid . ' gehört nicht zur Nextcloud-Gruppe Bestatter.');
		}
		$country = $this->countryConfiguration->validateBranchProfile($data);
		$countryLabel = trim((string)($data['country'] ?? ''));
		if ($countryLabel === '') $countryLabel = $this->countryConfiguration->profile($country['countryCode'])['name'];
		$values = [
			'branch_key' => $key, 'name' => $name, 'street' => trim((string)($data['street'] ?? '')),
			'postal_code' => trim((string)($data['postalCode'] ?? '')), 'city' => trim((string)($data['city'] ?? '')),
			'country' => $countryLabel, 'country_code' => $country['countryCode'], 'federal_state' => $country['federalState'],
			'invoice_profile' => $country['invoiceProfile'], 'payment_qr_standard' => $country['paymentQrStandard'], 'phone' => trim((string)($data['phone'] ?? '')),
			'email' => trim((string)($data['email'] ?? '')), 'account_holder' => trim((string)($data['accountHolder'] ?? '')),
			'bank_name' => trim((string)($data['bankName'] ?? '')), 'iban' => strtoupper(str_replace(' ', '', trim((string)($data['iban'] ?? '')))),
			'bic' => strtoupper(str_replace(' ', '', trim((string)($data['bic'] ?? '')))), 'creditor_id' => trim((string)($data['creditorId'] ?? '')),
			'payment_term_days' => max(0, min(365, (int)($data['paymentTermDays'] ?? 14))), 'vat_id' => trim((string)($data['vatId'] ?? '')),
			'tax_number' => trim((string)($data['taxNumber'] ?? '')), 'register_court' => trim((string)($data['registerCourt'] ?? '')),
			'register_number' => trim((string)($data['registerNumber'] ?? '')), 'managing_directors' => trim((string)($data['managingDirectors'] ?? '')),
			'electronic_address' => trim((string)($data['electronicAddress'] ?? '')), 'member_uids' => json_encode($memberUids, JSON_THROW_ON_ERROR), 'active' => (int)($data['active'] ?? true),
			'sort_order' => (int)($data['sortOrder'] ?? 0), 'updated_at' => date('c'),
		];
		$this->upsert('bestatter_branches', $values, $id);
		return $this->findByKey($this->branches(), $key);
	}

	public function branchByKey(string $key): ?array {
		$key = strtoupper(trim($key));
		foreach ($this->branches() as $branch) if ($branch['key'] === $key) return $branch;
		return null;
	}

	public function countryProfiles(): array {
		return [
			'profiles' => $this->countryConfiguration->profiles(),
			'allowedVatRates' => $this->countryConfiguration->allowedVatRates(),
			'catalogModel' => 'SHARED_WITH_INSTALLATION_WIDE_RATE_SET',
		];
	}

	public function saveCountryProfile(string $countryCode, array $data): array {
		return $this->countryConfiguration->save($countryCode, $data);
	}

	public function saveBranchBankData(int $id, array $data): array {
		if ($id <= 0) throw new \InvalidArgumentException('Die Niederlassung muss vor den Bankdaten gespeichert werden.');
		$query = $this->db->getQueryBuilder();
		if ($query->select('id')->from('bestatter_branches')->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeQuery()->fetchOne() === false) {
			throw new \InvalidArgumentException('Niederlassung wurde nicht gefunden.');
		}
		$ibanValidator = new IbanValidator();
		$iban = $ibanValidator->normalize((string)($data['iban'] ?? ''));
		$bic = strtoupper(str_replace(' ', '', trim((string)($data['bic'] ?? ''))));
		if ($iban !== '' && !$ibanValidator->hasValidFormat($iban)) throw new \InvalidArgumentException('Die IBAN hat kein gültiges Format.');
		if ($iban !== '' && !$ibanValidator->isValid($iban)) throw new \InvalidArgumentException('Die IBAN ist ungültig (Prüfziffer stimmt nicht).');
		if ($bic !== '' && !preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', $bic)) throw new \InvalidArgumentException('Die BIC muss 8 oder 11 Zeichen enthalten.');
		$values = [
			'account_holder' => trim((string)($data['accountHolder'] ?? '')), 'bank_name' => trim((string)($data['bankName'] ?? '')),
			'iban' => $iban, 'bic' => $bic, 'creditor_id' => trim((string)($data['creditorId'] ?? '')),
			'payment_term_days' => max(0, min(365, (int)($data['paymentTermDays'] ?? 14))), 'vat_id' => trim((string)($data['vatId'] ?? '')),
			'tax_number' => trim((string)($data['taxNumber'] ?? '')), 'register_court' => trim((string)($data['registerCourt'] ?? '')),
			'register_number' => trim((string)($data['registerNumber'] ?? '')), 'managing_directors' => trim((string)($data['managingDirectors'] ?? '')),
			'electronic_address' => trim((string)($data['electronicAddress'] ?? '')), 'updated_at' => date('c'),
		];
		$update = $this->db->getQueryBuilder();
		$update->update('bestatter_branches');
		foreach ($values as $column => $value) $update->set($column, $update->createNamedParameter($value));
		$affected = $update->where($update->expr()->eq('id', $update->createNamedParameter($id)))->executeStatement();
		if ($affected > 1) throw new \RuntimeException('Die Bankdaten konnten nicht eindeutig gespeichert werden.');
		foreach ($this->branches() as $branch) if ($branch['id'] === $id) return $branch;
		throw new \RuntimeException('Die gespeicherten Bankdaten konnten nicht erneut geladen werden.');
	}

	public function invoiceSettings(): array {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('*')->from('bestatter_invoice_settings')->orderBy('id', 'ASC')->setMaxResults(1)->executeQuery()->fetchAssociative();
		if (!$row) {
			$this->saveInvoiceSettings([]);
			return $this->invoiceSettings();
		}
		return ['id' => (int)$row['id'], 'prefix' => $row['number_prefix'], 'pattern' => $row['number_pattern'], 'sequenceLength' => (int)$row['sequence_length'],
			'sequenceScope' => $row['sequence_scope'], 'caseReference' => (bool)$row['case_reference'], 'zugferdEnabled' => (bool)$row['zugferd_enabled'],
			'zugferdVersion' => $row['zugferd_version'], 'zugferdProfile' => $row['zugferd_profile'],
			'xrechnungEnabled' => (bool)($row['xrechnung_enabled'] ?? false), 'normativeValidationRequired' => (bool)($row['norm_validation_required'] ?? false),
			'qrEnabled' => (bool)$row['qr_enabled'],
			'preview' => $this->renderInvoiceNumber($row, 1, '2026-0006', 'STAMMHAUS')];
	}

	public function saveInvoiceSettings(array $data): array {
		$current = null;
		try { $q = $this->db->getQueryBuilder(); $current = $q->select('*')->from('bestatter_invoice_settings')->orderBy('id', 'ASC')->setMaxResults(1)->executeQuery()->fetchAssociative(); } catch (\Throwable) {}
		$pattern = strtoupper(trim((string)($data['pattern'] ?? $current['number_pattern'] ?? '{PREFIX}-{YYYY}-{SEQ}')));
		if (!str_contains($pattern, '{SEQ}') || preg_match('/\{(?!PREFIX\}|YYYY\}|YY\}|SEQ\}|CASE\}|BRANCH\})[^}]+\}/', $pattern)) throw new \InvalidArgumentException('Das Nummernmuster muss {SEQ} enthalten und darf nur die angebotenen Platzhalter verwenden.');
		$scope = strtoupper((string)($data['sequenceScope'] ?? $current['sequence_scope'] ?? 'YEAR_GLOBAL'));
		if (!in_array($scope, ['YEAR_GLOBAL', 'YEAR_BRANCH', 'GLOBAL'], true)) throw new \InvalidArgumentException('Ungültiger Gültigkeitsbereich des Nummernkreises.');
		$values = ['number_prefix' => strtoupper(trim((string)($data['prefix'] ?? $current['number_prefix'] ?? 'RE'))) ?: 'RE', 'number_pattern' => $pattern,
			'sequence_length' => max(3, min(12, (int)($data['sequenceLength'] ?? $current['sequence_length'] ?? 6))), 'sequence_scope' => $scope,
			'case_reference' => (int)($data['caseReference'] ?? $current['case_reference'] ?? true), 'zugferd_enabled' => (int)($data['zugferdEnabled'] ?? $current['zugferd_enabled'] ?? true),
			'zugferd_version' => '2.5.2', 'zugferd_profile' => 'EN16931',
			'xrechnung_enabled' => (int)($data['xrechnungEnabled'] ?? $current['xrechnung_enabled'] ?? false),
			'norm_validation_required' => (int)($data['normativeValidationRequired'] ?? $current['norm_validation_required'] ?? false),
			'qr_enabled' => (int)($data['qrEnabled'] ?? $current['qr_enabled'] ?? true), 'updated_at' => date('c')];
		$this->upsert('bestatter_invoice_settings', $values, (int)($current['id'] ?? 0));
		return $this->invoiceSettings();
	}

	public function renderInvoiceNumber(array $settings, int $sequence, string $caseNumber, string $branchKey): string {
		$length = (int)($settings['sequence_length'] ?? $settings['sequenceLength'] ?? 6);
		return strtr((string)($settings['number_pattern'] ?? $settings['pattern'] ?? '{PREFIX}-{YYYY}-{SEQ}'), [
			'{PREFIX}' => (string)($settings['number_prefix'] ?? $settings['prefix'] ?? 'RE'), '{YYYY}' => date('Y'), '{YY}' => date('y'),
			'{SEQ}' => str_pad((string)$sequence, $length, '0', STR_PAD_LEFT), '{CASE}' => $caseNumber, '{BRANCH}' => strtoupper($branchKey),
		]);
	}

	public function delete(string $type, int $id): void {
		$table = match ($type) { 'document' => 'bestatter_document_templates', 'deregistration' => 'bestatter_dereg_templates', 'branch' => 'bestatter_branches', default => throw new \InvalidArgumentException('Unbekannter Konfigurationstyp.') };
		$query = $this->db->getQueryBuilder();
		$query->delete($table)->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeStatement();
	}

	public function provisionTemplates(): array {
		$user = $this->userSession->getUser();
		if ($user === null) throw new \RuntimeException('Kein Nextcloud-Benutzer angemeldet.');
		$target = $this->folders->templatesFolder();
		$templatePath = $this->folders->configuredPaths()['templates'];
		$written = [];
		foreach ($this->documents() as $template) {
			$fileName = basename((string)$template['fileName']);
			$source = __DIR__ . '/../../resources/templates/' . $fileName;
			if ($fileName === '' || !is_file($source)) continue;
			if ($target->nodeExists($fileName)) {
				$file = $target->get($fileName);
				$written[] = [
					'fileName' => $fileName,
					'fileId' => $file->getId(),
					'path' => $templatePath . '/' . $fileName,
					'status' => 'PRESERVED',
				];
				continue;
			}
			$content = (string)file_get_contents($source);
			$file = $target->newFile($fileName);
			$file->putContent($content);
			$written[] = ['fileName' => $fileName, 'fileId' => $file->getId(), 'path' => $templatePath . '/' . $fileName, 'status' => 'CREATED'];
		}
		return $written;
	}

	private function seedDocuments(): void {
		$data = json_decode((string)file_get_contents(self::DOCUMENT_RESOURCE), true, 512, JSON_THROW_ON_ERROR);
		foreach ($data['templates'] ?? [] as $template) {
			$key = strtoupper((string)$template['key']);
			if ($this->exists('bestatter_document_templates', 'template_key', $key)) continue;
			$this->upsert('bestatter_document_templates', [
				'template_key' => $key, 'name' => (string)$template['name'], 'category' => (string)($template['category'] ?? 'Allgemein'),
				'description' => (string)($template['description'] ?? ''), 'file_name' => basename((string)($template['file'] ?? '')),
				'required_fields' => json_encode((array)($template['required_fields'] ?? []), JSON_THROW_ON_ERROR),
				'output_subfolder' => (string)($template['output_subfolder'] ?? ''), 'supports_pdf' => (int)($template['supports_pdf'] ?? true),
				'active' => (int)($template['active'] ?? true), 'sort_order' => (int)($template['sort_order'] ?? 0), 'updated_at' => date('c'),
			], 0);
		}
	}

	private function seedDeregistrations(): void {
		$data = json_decode((string)file_get_contents(self::DEREGISTRATION_RESOURCE), true, 512, JSON_THROW_ON_ERROR);
		foreach ($data['templates'] ?? [] as $template) {
			$key = strtoupper((string)($template['key'] ?? $template['recipient_type']));
			if ($this->exists('bestatter_dereg_templates', 'template_key', $key)) continue;
			$channels = array_values(array_intersect(array_map('strtoupper', (array)($template['deliveryChannels'] ?? [])), ['EMAIL', 'POST', 'PORTAL', 'TELEFON']));
			$this->upsert('bestatter_dereg_templates', [
				'template_key' => $key, 'recipient_type' => strtoupper((string)($template['recipientType'] ?? $key)), 'name' => (string)$template['name'],
				'contact_category' => (string)($template['contactCategory'] ?? ''), 'delivery_channels' => json_encode($channels, JSON_THROW_ON_ERROR),
				'default_channel' => (string)($template['defaultChannel'] ?? ''), 'subject_template' => (string)($template['subjectTemplate'] ?? ''),
				'body_template' => (string)($template['bodyTemplate'] ?? ''), 'form_type' => strtoupper((string)($template['formType'] ?? 'STANDARD')),
				'required_fields' => json_encode((array)($template['requiredFields'] ?? []), JSON_THROW_ON_ERROR),
				'allowed_statuses' => json_encode((array)($template['allowedStatuses'] ?? ['ENTWURF', 'VORBEREITET', 'VERSENDET', 'BESTAETIGT', 'ERLEDIGT']), JSON_THROW_ON_ERROR),
				'follow_up_days' => max(0, (int)($template['followUpDays'] ?? 0)), 'document_template_key' => strtoupper((string)($template['documentTemplateKey'] ?? '')),
				'active' => (int)($template['active'] ?? true), 'sort_order' => (int)($template['sortOrder'] ?? 0), 'updated_at' => date('c'),
			], 0);
		}
	}

	private function exists(string $table, string $column, string $value): bool {
		$query = $this->db->getQueryBuilder();
		return $query->select('id')->from($table)->where($query->expr()->eq($column, $query->createNamedParameter($value)))->executeQuery()->fetchOne() !== false;
	}

	private function templateFileNames(): array {
		$names = [];
		foreach ($this->templateFolder()->getDirectoryListing() as $node) if ($node instanceof File && preg_match('/\.docx$/i', $node->getName())) $names[] = $node->getName();
		sort($names, SORT_NATURAL | SORT_FLAG_CASE);
		return $names;
	}

	private function templateFolder(): Folder {
		return $this->folders->templatesFolder();
	}

	private function validateTemplatePlaceholders(string $fileName): void {
		$node = $this->templateFolder()->get($fileName);
		if (!$node instanceof File) throw new \InvalidArgumentException('Die ausgewählte Vorlage ist keine Datei.');
		if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('Die PHP-Erweiterung ZipArchive wird zur Prüfung von DOCX-Vorlagen benötigt.');
		$temp = tempnam(sys_get_temp_dir(), 'bestatter-docx-');
		if ($temp === false) throw new \RuntimeException('Temporäre Vorlagenprüfung konnte nicht vorbereitet werden.');
		try {
			file_put_contents($temp, $node->getContent());
			$zip = new \ZipArchive();
			if ($zip->open($temp) !== true) throw new \InvalidArgumentException('Die ausgewählte Datei ist kein gültiges DOCX-Dokument.');
			$text = '';
			for ($index = 0; $index < $zip->numFiles; $index++) {
				$name = (string)$zip->getNameIndex($index);
				if (!preg_match('#^word/(document|header[0-9]*|footer[0-9]*)\.xml$#i', $name)) continue;
				$xml = $zip->getFromIndex($index);
				if (is_string($xml)) $text .= html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8') . "\n";
			}
			$zip->close();
			preg_match_all('/\{\{[^{}]+\}\}/u', $text, $matches);
			$allPlaceholders = $matches[0] ?? [];
			$found = array_values(array_unique($allPlaceholders));
			$allowed = array_column($this->templateFields->fields(), 'placeholder');
			$invalid = array_values(array_diff($found, $allowed));
			if ($invalid !== []) throw new \InvalidArgumentException('Nicht unterstützte Platzhalter in der DOCX-Vorlage: ' . implode(', ', $invalid) . '.');
			if (substr_count($text, '{{') !== count($allPlaceholders) || substr_count($text, '}}') !== count($allPlaceholders)) throw new \InvalidArgumentException('Die DOCX-Vorlage enthält einen unvollständigen oder beschädigten Platzhalter.');
		} finally {
			if (is_file($temp)) unlink($temp);
		}
	}

	private function allowedRequiredFields(): array {
		$schemaFile = __DIR__ . '/../../resources/case-field-schema.json';
		$schema = is_file($schemaFile) ? json_decode((string)file_get_contents($schemaFile), true, 512, JSON_THROW_ON_ERROR) : [];
		$fields = array_map(static fn(array $field): string => (string)$field['key'], $schema);
		$fields = array_merge($fields, ['date_of_death_or_interval','pension_insurance_number','order_client_name','order_client_address','order_number','order_date','services','funeral_event_date']);
		return array_values(array_unique(array_filter($fields)));
	}

	private function assertUniqueKey(string $table, string $column, string $value, int $id, string $label): void {
		$query = $this->db->getQueryBuilder();
		$query->select('id')->from($table)->where($query->expr()->eq($column, $query->createNamedParameter($value)));
		if ($id > 0) $query->andWhere($query->expr()->neq('id', $query->createNamedParameter($id)));
		if ($query->executeQuery()->fetchOne() !== false) throw new \InvalidArgumentException($label . ' ist bereits vergeben.');
	}

	private function upsert(string $table, array $values, int $id): void {
		$query = $this->db->getQueryBuilder();
		if ($id > 0) {
			$query->update($table);
			foreach ($values as $column => $value) $query->set($column, $query->createNamedParameter($value));
			$query->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeStatement();
			return;
		}
		$values['created_at'] = date('c');
		$query->insert($table)->values(array_map(fn(mixed $value) => $query->createNamedParameter($value), $values))->executeStatement();
	}

	private function findByKey(array $items, string $key): array {
		foreach ($items as $item) if ((string)$item['key'] === $key) return $item;
		throw new \InvalidArgumentException('Gespeicherte Konfiguration wurde nicht gefunden.');
	}

	private function folder(Folder $parent, string $name): Folder {
		return $parent->nodeExists($name) ? $parent->get($name) : $parent->newFolder($name);
	}
}
