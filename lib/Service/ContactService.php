<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\Contacts\IManager;
use OCP\IAddressBook;
use OCP\IGroupManager;

class ContactService {
	public function __construct(
		private IManager $contacts,
		private IGroupManager $groups,
		private InstallationConfigService $installationConfig,
	) {}

	public function list(): array {
		$result = [];
		if ($this->contacts->isEnabled()) {
			foreach ($this->contacts->getUserAddressBooks() as $book) {
				if ($book->isSystemAddressBook()) {
					continue;
				}
				foreach ($book->search('', ['FN', 'ORG', 'CATEGORIES', 'ADR', 'TEL', 'EMAIL', 'URL'], ['types' => true, 'limit' => 5000]) as $contact) {
					$result[] = $this->map($contact, $book);
				}
			}
		}
		$group = $this->groups->get($this->installationConfig->memberGroup());
		if ($group !== null) {
			foreach ($group->getUsers() as $user) {
				$result[] = [
					'id' => -abs((int)crc32('user:' . $user->getUID())),
					'caseId' => null,
					'type' => 'contact',
					'title' => $user->getDisplayName() ?: $user->getUID(),
					'date' => '',
					'status' => 'AKTIV',
					'data' => ['uid' => $user->getUID(), 'categories' => 'Bestatter', 'source' => 'nextcloud-group'],
				];
			}
		}
		return $result;
	}

	public function find(int $id): ?array {
		if ($id === 0) return null;
		foreach ($this->list() as $contact) if ((int)$contact['id'] === $id) return $contact;
		return null;
	}

	public function duplicates(string $title, array $data = []): array {
		$title = mb_strtolower(trim($title));
		$street = mb_strtolower(trim((string)($data['street'] ?? '')));
		$postalCode = trim((string)($data['postalCode'] ?? ''));
		$result = [];
		foreach ($this->list() as $contact) {
			$contactTitle = mb_strtolower(trim((string)$contact['title']));
			$contactStreet = mb_strtolower(trim((string)($contact['data']['street'] ?? '')));
			$contactPostalCode = trim((string)($contact['data']['postalCode'] ?? ''));
			$sameName = $title !== '' && ($contactTitle === $title || str_contains($contactTitle, $title) || str_contains($title, $contactTitle));
			$sameAddress = $street !== '' && $postalCode !== '' && $contactStreet === $street && $contactPostalCode === $postalCode;
			if ($sameName || $sameAddress) $result[] = ['id' => $contact['id'], 'title' => $contact['title'], 'addressBook' => $contact['data']['addressBook'] ?? '', 'sameAddress' => $sameAddress];
		}
		return array_slice($result, 0, 5);
	}

	public function create(string $title, string $data): array {
		if (!$this->contacts->isEnabled()) {
			throw new \RuntimeException('Nextcloud Contacts ist nicht verfuegbar.');
		}
		$payload = json_decode($data, true, 512, JSON_THROW_ON_ERROR) ?: [];
		$book = $this->organizationAddressBook();
		$properties = [
			'FN' => trim($title),
			'ORG' => trim($title),
			'CATEGORIES' => (string)($payload['categories'] ?? 'Bestatter'),
		];
		if (($payload['email'] ?? '') !== '') {
			$properties['EMAIL'] = (string)$payload['email'];
		}
		if (($payload['phone'] ?? '') !== '') {
			$properties['TEL'] = (string)$payload['phone'];
		}
		$postalCode = (string)($payload['postalCode'] ?? '');
		$city = (string)($payload['city'] ?? $payload['postalCity'] ?? '');
		$country = trim((string)($payload['country'] ?? 'Deutschland')) ?: 'Deutschland';
		if (($payload['street'] ?? '') !== '' || $postalCode !== '' || $city !== '') {
			$properties['ADR'] = ';;' . (string)($payload['street'] ?? '') . ';' . $city . ';;' . $postalCode . ';' . $country;
		}
		if (($payload['url'] ?? '') !== '') $properties['URL'] = (string)$payload['url'];
		$created = $book->createOrUpdate($properties);
		return $this->map($created, $book);
	}

	private function organizationAddressBook(): IAddressBook {
		$fallback = null;
		foreach ($this->contacts->getUserAddressBooks() as $book) {
			if ($book->isSystemAddressBook()) {
				continue;
			}
			$fallback ??= $book;
			$name = strtolower((string)$book->getDisplayName());
			if (str_contains($name, 'bestatter') && str_contains($name, 'organisationen')) {
				return $book;
			}
		}
		if ($fallback !== null) {
			return $fallback;
		}
		throw new \RuntimeException('Das Adressbuch Bestatter - Organisationen wurde nicht gefunden.');
	}

	private function map(array $contact, IAddressBook $book): array {
		$title = $contact['FN'] ?? $contact['ORG'] ?? 'Kontakt';
		if (is_array($title)) {
			$title = implode(' ', array_map('strval', $title));
		}
		$category = $contact['CATEGORIES'] ?? '';
		if (is_array($category)) {
			$category = implode(',', array_map(static fn($value) => is_array($value) ? (string)($value['value'] ?? '') : (string)$value, $category));
		}
		return [
			'id' => abs((int)crc32($book->getKey() . ':' . (string)($contact['id'] ?? $title))),
			'caseId' => null,
			'type' => 'contact',
			'title' => (string)$title,
			'date' => '',
			'status' => 'AKTIV',
			'data' => [
				'contactId' => $contact['id'] ?? null,
				'addressBookKey' => $book->getKey(),
				'addressBook' => $book->getDisplayName(),
				'categories' => (string)$category,
				'email' => $this->value($contact['EMAIL'] ?? ''),
				'phone' => $this->value($contact['TEL'] ?? ''),
				'address' => $this->value($contact['ADR'] ?? ''),
				'street' => $this->addressPart($contact['ADR'] ?? '', 2),
				'city' => $this->addressPart($contact['ADR'] ?? '', 3),
				'postalCode' => $this->addressPart($contact['ADR'] ?? '', 5),
				'country' => $this->addressPart($contact['ADR'] ?? '', 6),
				'url' => $this->value($contact['URL'] ?? ''),
				'source' => 'nextcloud-contacts',
			],
		];
	}

	private function value(mixed $value): string {
		if (!is_array($value)) {
			return (string)$value;
		}
		$first = reset($value);
		return is_array($first) ? (string)($first['value'] ?? '') : (string)$first;
	}

	private function addressPart(mixed $value, int $index): string {
		$address = $this->value($value);
		$parts = explode(';', $address);
		return trim((string)($parts[$index] ?? ''));
	}
}
