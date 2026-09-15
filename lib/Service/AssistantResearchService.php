<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCA\Bestatter\AppInfo\Application;

/**
 * Privacy-conscious public organisation lookup for the assistant.
 *
 * Only the explicitly supplied organisation/location query leaves Nextcloud.
 * Case data is never added. Results are proposals and must be confirmed before
 * ContactService writes them to a user's address book.
 */
class AssistantResearchService {
	private const DEFAULT_ENDPOINT = 'https://nominatim.openstreetmap.org/search';

	public function __construct(
		private IClientService $clients,
		private IConfig $config,
		private ContactService $contacts,
	) {}

	public function searchOrganizations(string $query): array {
		if (!$this->enabled()) {
			throw new \InvalidArgumentException('Die Internetrecherche ist administrativ noch nicht freigegeben.');
		}
		$query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
		if (mb_strlen($query) < 3 || mb_strlen($query) > 180) {
			throw new \InvalidArgumentException('Bitte Organisation und Ort für die Recherche genauer angeben.');
		}
		$endpoint = trim($this->config->getAppValue(Application::APP_ID, 'assistant_research_endpoint', self::DEFAULT_ENDPOINT));
		if ($endpoint !== self::DEFAULT_ENDPOINT) {
			throw new \RuntimeException('Der konfigurierte Rechercheendpunkt ist nicht freigegeben.');
		}
		try {
			$response = $this->clients->newClient()->get($endpoint, [
				'query' => ['q' => $query, 'format' => 'jsonv2', 'addressdetails' => 1, 'limit' => 5, 'countrycodes' => 'de'],
				'headers' => ['Accept' => 'application/json', 'User-Agent' => 'Nextcloud-Bestatter/' . Application::VERSION],
				'timeout' => 12,
			]);
			$rows = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		} catch (\Throwable $error) {
			throw new \RuntimeException('Die Organisationsrecherche ist derzeit nicht erreichbar.', 0, $error);
		}
		if (!is_array($rows)) return [];
		$result = [];
		foreach ($rows as $row) {
			if (!is_array($row)) continue;
			$address = is_array($row['address'] ?? null) ? $row['address'] : [];
			$name = trim((string)($row['name'] ?? $address['office'] ?? $address['amenity'] ?? ''));
			if ($name === '') $name = trim(explode(',', (string)($row['display_name'] ?? ''), 2)[0]);
			if ($name === '') continue;
			$streetName = trim((string)($address['road'] ?? $address['pedestrian'] ?? $address['square'] ?? ''));
			$houseNumber = trim((string)($address['house_number'] ?? ''));
			$city = trim((string)($address['city'] ?? $address['town'] ?? $address['municipality'] ?? $address['village'] ?? ''));
			$osmType = strtolower((string)($row['osm_type'] ?? ''));
			$osmId = preg_replace('/\D/', '', (string)($row['osm_id'] ?? ''));
			$sourceUrl = in_array($osmType, ['node', 'way', 'relation'], true) && $osmId !== ''
				? 'https://www.openstreetmap.org/' . $osmType . '/' . $osmId
				: 'https://www.openstreetmap.org/search?query=' . rawurlencode($query);
			$candidate = [
				'title' => $name,
				'street' => trim($streetName . ' ' . $houseNumber),
				'postalCode' => trim((string)($address['postcode'] ?? '')),
				'city' => $city,
				'country' => trim((string)($address['country'] ?? 'Deutschland')) ?: 'Deutschland',
				'phone' => '', 'email' => '', 'url' => '',
				'categories' => $this->categories($query),
				'sourceName' => 'OpenStreetMap/Nominatim',
				'sourceUrl' => $sourceUrl,
				'retrievedAt' => date(DATE_ATOM),
				'displayName' => trim((string)($row['display_name'] ?? $name)),
				'needsOfficialVerification' => true,
			];
			$candidate['duplicates'] = $this->contacts->duplicates($candidate['title'], $candidate);
			$result[] = $candidate;
		}
		return $result;
	}

	public function enabled(): bool {
		return filter_var($this->config->getAppValue(Application::APP_ID, 'assistant_web_research_enabled', 'no'), FILTER_VALIDATE_BOOLEAN);
	}

	private function categories(string $query): string {
		if (preg_match('/standesamt/iu', $query)) return 'Standesamt,Behörde';
		if (preg_match('/friedhof/iu', $query)) return 'Friedhof';
		if (preg_match('/krematorium/iu', $query)) return 'Krematorium';
		return 'Organisation';
	}
}
