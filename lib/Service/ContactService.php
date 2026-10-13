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

	/**
	 * Mögliche Dubletten zu einem neuen Kontakt. 0.86.0: Vergleich über normalisierten Namen (Reihenfolge, Umlaute, ß,
	 * Anrede und Titel egal), Telefonnummer (nur Ziffern, +49/0049 = 0), E-Mail und Anschrift (Straße + PLZ). Ein
	 * einzelnes Namenswort reicht nicht mehr („Katze“ passt nicht auf „Monty Katze“). Es wird nie automatisch
	 * zusammengeführt; die Oberfläche bietet „Vorhandenen Kontakt verwenden“ oder „Trotzdem neu anlegen“.
	 * @return list<array{id:int,title:string,addressBook:string,sameAddress:bool,reasons:list<string>,reasonText:string,contact:array}>
	 */
	public function duplicates(string $title, array $data = []): array {
		$candidate = ['title' => $title] + $data;
		$result = [];
		foreach ($this->list() as $contact) {
			if (($contact['data']['source'] ?? '') === 'nextcloud-group') continue;
			$reasons = self::matchReasons($candidate, ['title' => $contact['title']] + $contact['data']);
			if ($reasons === []) continue;
			$result[] = [
				'id' => $contact['id'], 'title' => $contact['title'], 'addressBook' => $contact['data']['addressBook'] ?? '',
				'sameAddress' => in_array('ADDRESS', $reasons, true), 'reasons' => $reasons, 'reasonText' => self::reasonText($reasons),
				'contact' => $contact,
			];
		}
		// Stärkere Übereinstimmungen zuerst (mehr Gründe, gleicher Name vor ähnlichem Namen).
		usort($result, static fn(array $a, array $b): int => [count($b['reasons']), in_array('NAME', $b['reasons'], true)] <=> [count($a['reasons']), in_array('NAME', $a['reasons'], true)]);
		return array_slice($result, 0, 5);
	}

	/** @return list<string> NAME, NAME_SIMILAR, PHONE, EMAIL, ADDRESS */
	public static function matchReasons(array $candidate, array $existing): array {
		$reasons = [];
		$name = self::normalizeName(self::fullName($candidate));
		$other = self::normalizeName(self::fullName($existing));
		if ($name !== '' && $name === $other) $reasons[] = 'NAME';
		elseif ($name !== '' && $other !== '') {
			$a = explode(' ', $name); $b = explode(' ', $other);
			$shorter = count($a) <= count($b) ? $a : $b; $longer = count($a) <= count($b) ? $b : $a;
			if (count($shorter) >= 2 && array_diff($shorter, $longer) === []) $reasons[] = 'NAME_SIMILAR';
		}
		$phones = array_filter(array_map([self::class, 'normalizePhone'], [(string)($candidate['phone'] ?? ''), (string)($candidate['mobile'] ?? '')]));
		$otherPhones = array_filter(array_map([self::class, 'normalizePhone'], array_merge(explode(',', (string)($existing['phones'] ?? $existing['phone'] ?? '')), [(string)($existing['phone'] ?? ''), (string)($existing['mobile'] ?? '')])));
		if ($phones !== [] && array_intersect($phones, $otherPhones) !== []) $reasons[] = 'PHONE';
		$email = mb_strtolower(trim((string)($candidate['email'] ?? '')));
		if ($email !== '' && $email === mb_strtolower(trim((string)($existing['email'] ?? '')))) $reasons[] = 'EMAIL';
		$street = self::normalizeStreet((string)($candidate['street'] ?? ''));
		$postalCode = trim((string)($candidate['postalCode'] ?? ''));
		if ($street !== '' && $postalCode !== '' && $street === self::normalizeStreet((string)($existing['street'] ?? '')) && $postalCode === trim((string)($existing['postalCode'] ?? ''))) $reasons[] = 'ADDRESS';
		return $reasons;
	}

	public static function reasonText(array $reasons): string {
		$labels = ['NAME' => 'gleicher Name', 'NAME_SIMILAR' => 'ähnlicher Name', 'PHONE' => 'gleiche Telefonnummer', 'EMAIL' => 'gleiche E-Mail-Adresse', 'ADDRESS' => 'gleiche Anschrift'];
		return implode(', ', array_map(static fn(string $reason): string => $labels[$reason] ?? $reason, $reasons));
	}

	/** „Dr. Rainer  Müller“, „Müller, Rainer“ und „rainer mueller“ ergeben denselben Schlüssel „mueller rainer“. */
	public static function normalizeName(string $name): string {
		$name = mb_strtolower(trim($name));
		$name = strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'à' => 'a']);
		$name = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);
		$tokens = array_values(array_filter(explode(' ', $name), static fn(string $token): bool => $token !== '' && !in_array($token, ['herr', 'frau', 'dr', 'prof', 'med', 'dipl', 'ing', 'von', 'van', 'der', 'de'], true)));
		sort($tokens);
		return implode(' ', $tokens);
	}

	/** Nur Ziffern; +49/0049 wird zu 0. Kürzer als 6 Ziffern gilt nicht als Telefonnummer. */
	public static function normalizePhone(string $phone): string {
		$digits = (string)preg_replace('/\D+/', '', (string)preg_replace('/^\s*(?:\+|00)49\s*(?:\(0\))?/', '0', $phone));
		return strlen($digits) >= 6 ? $digits : '';
	}

	private static function normalizeStreet(string $street): string {
		$street = self::normalizeName(str_replace(['straße', 'Straße', 'str.', 'Str.'], ' strasse ', $street));
		return $street;
	}

	private static function fullName(array $values): string {
		$parts = trim((string)($values['firstName'] ?? '') . ' ' . (string)($values['lastName'] ?? ''));
		return $parts !== '' ? $parts : (string)($values['title'] ?? '');
	}

	/**
	 * 0.86.0: Legt eine Privatperson (Auftraggeber/in, Angehörige) im gemeinsamen Adressbuch an – nur auf ausdrücklichen
	 * Wunsch aus dem Reiter „Kontakte“. Mit $uri wird ein bereits übernommener Eintrag aktualisiert.
	 */
	public function savePerson(array $person, string $uri = ''): array {
		if (!$this->contacts->isEnabled()) throw new \RuntimeException('Nextcloud Contacts ist nicht verfuegbar.');
		$first = trim((string)($person['firstName'] ?? ''));
		$last = trim((string)($person['lastName'] ?? ''));
		if ($first === '' || $last === '') throw new \InvalidArgumentException('Für das Adressbuch werden Vor- und Nachname benötigt.');
		$book = $this->organizationAddressBook();
		$properties = ['FN' => $first . ' ' . $last, 'N' => str_replace(';', ',', $last) . ';' . str_replace(';', ',', $first) . ';;;', 'CATEGORIES' => 'Angehörige'];
		$phones = [];
		if (trim((string)($person['mobile'] ?? '')) !== '') $phones[] = ['value' => trim((string)$person['mobile']), 'type' => 'CELL'];
		if (trim((string)($person['phone'] ?? '')) !== '') $phones[] = ['value' => trim((string)$person['phone']), 'type' => 'VOICE'];
		$properties['TEL'] = $phones;
		$properties['EMAIL'] = trim((string)($person['email'] ?? '')) !== '' ? [['value' => trim((string)$person['email']), 'type' => 'HOME']] : [];
		$postalCode = trim((string)($person['postalCode'] ?? ''));
		$city = trim((string)($person['city'] ?? ''));
		$street = trim((string)($person['street'] ?? ''));
		$country = trim((string)($person['country'] ?? '')) ?: 'Deutschland';
		$properties['ADR'] = $street !== '' || $postalCode !== '' || $city !== ''
			? [['value' => implode(';', array_map(static fn(string $part): string => str_replace(';', ',', $part), ['', '', $street, $city, '', $postalCode, $country])), 'type' => 'HOME']]
			: [];
		if ($uri !== '') $properties['URI'] = $uri;
		$saved = $book->createOrUpdate($properties);
		return $this->map($saved, $book);
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
			// 0.75.2: als strukturierte Adresse übergeben. Ein einfacher Text wurde von Nextcloud maskiert und landete
			// vollständig im ersten Teilfeld („Postfach“) statt in Straße, Ort, PLZ und Land.
			$parts = array_map(static fn(string $part): string => str_replace(';', ',', trim($part)), ['', '', (string)($payload['street'] ?? ''), $city, '', $postalCode, $country]);
			$properties['ADR'] = [['value' => implode(';', $parts), 'type' => 'WORK']];
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
				'phones' => implode(',', $this->values($contact['TEL'] ?? '')),
				'address' => $this->value($contact['ADR'] ?? ''),
				'street' => $this->addressPart($contact['ADR'] ?? '', 2),
				'city' => $this->addressPart($contact['ADR'] ?? '', 3),
				'postalCode' => $this->addressPart($contact['ADR'] ?? '', 5),
				'country' => $this->addressPart($contact['ADR'] ?? '', 6),
				'addressText' => trim(implode(', ', array_filter([$this->addressPart($contact['ADR'] ?? '', 2), trim($this->addressPart($contact['ADR'] ?? '', 5) . ' ' . $this->addressPart($contact['ADR'] ?? '', 3))]))),
				'url' => $this->value($contact['URL'] ?? ''),
				'uri' => (string)($contact['URI'] ?? ''),
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

	/** @return list<string> alle Werte einer mehrfach vorkommenden Eigenschaft (z. B. mehrere Telefonnummern). */
	private function values(mixed $value): array {
		if (!is_array($value)) return trim((string)$value) !== '' ? [(string)$value] : [];
		return array_values(array_filter(array_map(static fn($entry): string => is_array($entry) ? (string)($entry['value'] ?? '') : (string)$entry, $value), static fn(string $entry): bool => trim($entry) !== ''));
	}

	private function addressPart(mixed $value, int $index): string {
		$address = $this->value($value);
		$parts = explode(';', $address);
		return trim((string)($parts[$index] ?? ''));
	}
}
