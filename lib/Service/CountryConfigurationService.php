<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IDBConnection;

/** Central source for country-dependent tax, invoice and payment capabilities. */
class CountryConfigurationService {
	private const DEFAULTS = [
		'DE' => ['name' => 'Deutschland', 'vatRates' => [0, 7, 19], 'invoiceProfile' => 'ZUGFERD', 'paymentQrStandard' => 'EPC069-12', 'templateNamespace' => 'DE', 'ready' => true],
		'AT' => ['name' => 'Österreich', 'vatRates' => [0, 10, 13, 20], 'invoiceProfile' => 'NONE', 'paymentQrStandard' => 'EPC069-12', 'templateNamespace' => 'AT', 'ready' => true],
		'CH' => ['name' => 'Schweiz', 'vatRates' => [0, 2.6, 3.8, 8.1], 'invoiceProfile' => 'NONE', 'paymentQrStandard' => 'NONE', 'templateNamespace' => 'CH', 'ready' => false],
		'FR' => ['name' => 'Frankreich', 'vatRates' => [0, 5.5, 10, 20], 'invoiceProfile' => 'NONE', 'paymentQrStandard' => 'NONE', 'templateNamespace' => 'FR', 'ready' => false],
	];
	private const INVOICE_PROFILES = ['ZUGFERD', 'XRECHNUNG', 'NONE'];
	private const PAYMENT_QR_STANDARDS = ['EPC069-12', 'NONE'];
	private const AT_STATES = ['WIEN', 'NIEDEROESTERREICH', 'OBEROESTERREICH', 'STEIERMARK', 'TIROL', 'KAERNTEN', 'SALZBURG', 'VORARLBERG', 'BURGENLAND'];
	private const STATE_LABELS = [
		'WIEN' => 'Wien', 'NIEDEROESTERREICH' => 'Niederösterreich', 'OBEROESTERREICH' => 'Oberösterreich',
		'STEIERMARK' => 'Steiermark', 'TIROL' => 'Tirol', 'KAERNTEN' => 'Kärnten', 'SALZBURG' => 'Salzburg',
		'VORARLBERG' => 'Vorarlberg', 'BURGENLAND' => 'Burgenland',
	];

	public function __construct(private IDBConnection $db) {}

	public function profiles(): array {
		$this->ensureSeedData();
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_country_profiles')->orderBy('country_code', 'ASC')->executeQuery()->fetchAllAssociative();
		return array_map(fn(array $row): array => $this->map($row), $rows);
	}

	public function profile(string $countryCode): array {
		$countryCode = strtoupper(trim($countryCode));
		foreach ($this->profiles() as $profile) if ($profile['countryCode'] === $countryCode) return $profile;
		throw new \InvalidArgumentException('Das ausgewählte Länderprofil wird nicht unterstützt.');
	}

	/** Rates accepted by the shared catalog. Country-specific mapping follows in a later schema step. */
	public function allowedVatRates(): array {
		$rates = [];
		foreach ($this->profiles() as $profile) {
			if (!$profile['ready']) continue;
			foreach ($profile['vatRates'] as $rate) if ((float)$rate === (float)(int)$rate) $rates[(string)(int)$rate] = (int)$rate;
		}
		sort($rates, SORT_NUMERIC);
		return array_values($rates);
	}

	public function save(string $countryCode, array $data): array {
		$current = $this->profile($countryCode);
		$rates = array_values(array_unique(array_map(static fn(mixed $value): float => (float)str_replace(',', '.', trim((string)$value)), (array)($data['vatRates'] ?? $current['vatRates']))));
		sort($rates, SORT_NUMERIC);
		if ($rates === [] || count($rates) > 12 || min($rates) < 0 || max($rates) > 100) throw new \InvalidArgumentException('Bitte gültige Mehrwertsteuersätze zwischen 0 und 100 Prozent angeben.');
		$invoiceProfile = strtoupper(trim((string)($data['invoiceProfile'] ?? $current['invoiceProfile'])));
		$paymentQrStandard = strtoupper(trim((string)($data['paymentQrStandard'] ?? $current['paymentQrStandard'])));
		if (!in_array($invoiceProfile, self::INVOICE_PROFILES, true)) throw new \InvalidArgumentException('Das Rechnungsprofil wird noch nicht unterstützt.');
		if (!in_array($paymentQrStandard, self::PAYMENT_QR_STANDARDS, true)) throw new \InvalidArgumentException('Der Zahlungs-QR-Standard wird noch nicht unterstützt.');
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_country_profiles')
			->set('vat_rates', $query->createNamedParameter(json_encode($rates, JSON_THROW_ON_ERROR)))
			->set('default_invoice_profile', $query->createNamedParameter($invoiceProfile))
			->set('default_payment_qr', $query->createNamedParameter($paymentQrStandard))
			->set('updated_at', $query->createNamedParameter(date('c')))
			->where($query->expr()->eq('country_code', $query->createNamedParameter(strtoupper($countryCode))))
			->executeStatement();
		return $this->profile($countryCode);
	}

	public function validateBranchProfile(array $data): array {
		$countryCode = strtoupper(trim((string)($data['countryCode'] ?? 'DE')));
		$profile = $this->profile($countryCode);
		if (!$profile['ready']) throw new \InvalidArgumentException('Das Länderprofil ' . $profile['name'] . ' ist vorbereitet, aber noch nicht für produktive Vorgänge freigegeben.');
		$invoiceProfile = strtoupper(trim((string)($data['invoiceProfile'] ?? $profile['invoiceProfile'])));
		$paymentQrStandard = strtoupper(trim((string)($data['paymentQrStandard'] ?? $profile['paymentQrStandard'])));
		if (!in_array($invoiceProfile, self::INVOICE_PROFILES, true)) throw new \InvalidArgumentException('Ungültiges Rechnungsprofil der Niederlassung.');
		if (!in_array($paymentQrStandard, self::PAYMENT_QR_STANDARDS, true)) throw new \InvalidArgumentException('Ungültiger Zahlungs-QR-Standard der Niederlassung.');
		$federalState = strtoupper(trim((string)($data['federalState'] ?? '')));
		if ($countryCode === 'AT' && !in_array($federalState, self::AT_STATES, true)) throw new \InvalidArgumentException('Für eine österreichische Niederlassung muss ein gültiges Bundesland gewählt werden.');
		if ($countryCode !== 'AT') $federalState = '';
		return compact('countryCode', 'invoiceProfile', 'paymentQrStandard', 'federalState');
	}

	public function federalStates(string $countryCode): array {
		if (strtoupper(trim($countryCode)) !== 'AT') return [];
		return array_map(static fn(string $key, string $label): array => ['key' => $key, 'label' => $label], array_keys(self::STATE_LABELS), array_values(self::STATE_LABELS));
	}

	public function templateNamespace(array $branch): string {
		$code = strtoupper((string)($branch['countryCode'] ?? 'DE'));
		$profile = $this->profile($code);
		$namespace = (string)$profile['templateNamespace'];
		if ($code === 'AT' && trim((string)($branch['federalState'] ?? '')) !== '') $namespace .= '/' . strtoupper((string)$branch['federalState']);
		return $namespace;
	}

	private function ensureSeedData(): void {
		foreach (self::DEFAULTS as $code => $default) {
			$query = $this->db->getQueryBuilder();
			$exists = $query->select('id')->from('bestatter_country_profiles')->where($query->expr()->eq('country_code', $query->createNamedParameter($code)))->executeQuery()->fetchOne();
			if ($exists !== false) continue;
			$query = $this->db->getQueryBuilder();
			$query->insert('bestatter_country_profiles')->values([
				'country_code' => $query->createNamedParameter($code), 'name' => $query->createNamedParameter($default['name']),
				'vat_rates' => $query->createNamedParameter(json_encode($default['vatRates'], JSON_THROW_ON_ERROR)),
				'default_invoice_profile' => $query->createNamedParameter($default['invoiceProfile']),
				'default_payment_qr' => $query->createNamedParameter($default['paymentQrStandard']),
				'template_namespace' => $query->createNamedParameter($default['templateNamespace']),
				'ready' => $query->createNamedParameter($default['ready'] ? 1 : 0), 'created_at' => $query->createNamedParameter(date('c')), 'updated_at' => $query->createNamedParameter(date('c')),
			])->executeStatement();
		}
	}

	private function map(array $row): array {
		$rates = json_decode((string)$row['vat_rates'], true);
		return [
			'id' => (int)$row['id'], 'countryCode' => (string)$row['country_code'], 'name' => (string)$row['name'],
			'vatRates' => is_array($rates) ? array_values($rates) : [], 'invoiceProfile' => (string)$row['default_invoice_profile'],
			'paymentQrStandard' => (string)$row['default_payment_qr'], 'templateNamespace' => (string)$row['template_namespace'],
			'ready' => (bool)$row['ready'], 'federalStates' => $this->federalStates((string)$row['country_code']),
		];
	}
}
