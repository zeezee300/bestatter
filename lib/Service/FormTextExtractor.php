<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * 0.79.0 – Auswertung des erkannten Texts eines Auftrags- oder Erfassungsbogens (OCR, KI-Abschrift).
 *
 * Reine Regeln ohne Nextcloud (prüfbar mit tests/form-text-0790-fixture.php):
 *  - Spalten trennen, bevor Leerzeichen zusammengefasst werden (zweispaltige Bögen: „Geburtsdatum: … Geburtsort: …“).
 *  - Nur beschriftete Felder übernehmen („Etikett: Wert“); gedruckte Vordrucktexte (z. B. Positionsnamen) werden nie als Angabe gewertet.
 *  - Jeder Wert wird nach Feldart auf Plausibilität geprüft; Zeichensalat aus unlesbarer Handschrift wird verworfen statt vorgeschlagen.
 *  - Abschnitte: Angaben nach „Auftraggeber“ gehören zur beauftragenden Person (deren Geburtsdatum/Anschrift sind nicht die der verstorbenen Person).
 */
final class FormTextExtractor {
	public const CONFIDENCE = 0.8;
	public const CONFIDENCE_DATE = 0.85;
	/** Kleingeschriebene Wörter, die in Namen und Orten vorkommen dürfen. */
	private const PARTICLES = '/^(?:von|van|vom|zu|zum|zur|de|der|den|des|di|du|la|le|ten|ter|am|an|auf|bei|im|in|ob|a\\.|d\\.|i\\.|und)$/u';

	/** Feld → Bezeichnung für die Oberfläche. */
	public const FIELD_LABELS = [
		'first_name' => 'Vorname', 'last_name' => 'Nachname', 'birth_name' => 'Geburtsname', 'date_of_birth' => 'Geburtsdatum', 'birth_place' => 'Geburtsort',
		'date_of_death' => 'Sterbedatum', 'place_of_death' => 'Sterbeort / Einrichtung', 'birth_registry_office' => 'Geburtsstandesamt', 'profession' => 'Beruf',
		'pension_insurance_number' => 'Postrentennummer', 'civil_status' => 'Familienstand', 'religion' => 'Religion', 'last_residence' => 'Straße / Hausnummer',
		'last_residence_postal_code' => 'PLZ', 'last_residence_city' => 'Letzter Wohnort', 'funeral_type' => 'Bestattungsart', 'cemetery_contact' => 'Friedhof',
		'order_client_first_name' => 'Vorname Auftraggeber/in', 'order_client_name' => 'Nachname Auftraggeber/in', 'order_client_relation' => 'Beziehung der Auftraggeberin / des Auftraggebers',
		'order_client_mobile' => 'Telefon Auftraggeber/in', 'certificate_free_count' => 'Sterbeurkunden gebührenfrei', 'certificate_paid_count' => 'Sterbeurkunden gebührenpflichtig',
	];

	/**
	 * Etiketten in Prüfreihenfolge (längere zuerst). Ziel: Feld, Sonderart (FULLNAME, CLIENT_FULLNAME, ADDRESS) oder IGNORE
	 * (bekannte Etiketten ohne Feld – sie trennen Spalten und beenden den vorherigen Wert). „section“ wechselt den Abschnitt.
	 * @var list<array{0:string,1:string,2?:string}>
	 */
	private const LABELS = [
		['Name\s+(?:des|der)\s*\/\s*(?:der|des)\s+Verstorbenen', 'FULLNAME', 'DECEASED'], ['Name\s+(?:des|der)\s+Verstorbenen', 'FULLNAME', 'DECEASED'], ['Verstorbene[rn]?', 'FULLNAME', 'DECEASED'],
		['Vorname\s+(?:des\s+|der\s+)?Auftraggeber(?:s|in)?', 'order_client_first_name', 'CLIENT'], ['Nachname\s+(?:des\s+|der\s+)?Auftraggeber(?:s|in)?', 'order_client_name', 'CLIENT'],
		['Name\s+(?:des\s+|der\s+)?Auftraggeber(?:s|in)?', 'CLIENT_FULLNAME', 'CLIENT'], ['Auftraggeber(?:\s*\/\s*in|in)?', 'CLIENT_FULLNAME', 'CLIENT'],
		['Beziehung(?:\s+(?:zum|zur)\s+Verstorbenen|\s+Auftraggeber(?:in)?)?', 'order_client_relation'], ['Verwandtschafts(?:verhältnis|grad)', 'order_client_relation'], ['Verhältnis', 'order_client_relation'],
		['(?:Mobil(?:funknummer)?|Telefon|Handy)\s+Auftraggeber(?:in)?', 'order_client_mobile', 'CLIENT'], ['Mobil(?:funknummer)?|Handy', 'order_client_mobile'], ['Telefon|Tel\.', 'PHONE'],
		['E-?Mail', 'IGNORE'],
		['Vornamen?', 'first_name', 'DECEASED'], ['Nachname|Familienname', 'last_name', 'DECEASED'], ['Geburtsname|geb(?:\.|orene[rs]?)\s+Name', 'birth_name'],
		['Geburtsdatum|geboren\s+am', 'date_of_birth'], ['Geburtsort|geboren\s+in', 'birth_place'], ['Geburtsstandesamt', 'birth_registry_office'],
		['Sterbedatum|Todesdatum|Todestag|verstorben\s+am', 'date_of_death', 'DECEASED'], ['Sterbeort(?:\s*\/\s*Einrichtung)?|verstorben\s+in', 'place_of_death', 'DECEASED'],
		['Beruf', 'profession'], ['Postrentennummer|Renten(?:versicherungs)?nummer', 'pension_insurance_number'], ['Familienstand', 'civil_status'], ['Religion|Konfession', 'religion'],
		['Straße(?:\s+und\s+Hausnummer|\s*\/\s*Hausnummer|\s*,\s*Nr\.?)?|(?:letzte\s+|Wohn)?Anschrift|letzter\s+Wohnsitz', 'ADDRESS'], ['PLZ|Postleitzahl', 'last_residence_postal_code'], ['Wohnort|Ort', 'last_residence_city'],
		['Bestattungsart|Bestattungsform', 'funeral_type'], ['Privatkapelle\s*\/\s*Friedhof|Friedhof|Beisetzungsort', 'cemetery_contact'],
		['Sterbeurkunden\s+gebührenfrei', 'certificate_free_count'], ['Sterbeurkunden\s+gebührenpflichtig', 'certificate_paid_count'],
		['Trauerfeier\s+am|Einäscherung\s+am|Urnenbeisetzung\s+am|Beisetzung\s+am|Grabstelle|Unterschrift|Geburtsdatum\s+Auftraggeber(?:in)?', 'IGNORE'],
	];

	/**
	 * @return list<array{field:string,label:string,value:string,confidence:float,source:string,origin:string}>
	 */
	public static function extract(string $text, ?\DateTimeImmutable $today = null): array {
		$today ??= new \DateTimeImmutable('today');
		$suggestions = [];
		$add = static function (string $field, string $value, float $confidence, string $source) use (&$suggestions): void {
			if ($value === '' || isset($suggestions[$field])) return; // erster gültiger Wert gewinnt (Bögen nennen ein Feld einmal)
			$suggestions[$field] = ['field' => $field, 'label' => self::FIELD_LABELS[$field] ?? $field, 'value' => $value, 'confidence' => $confidence, 'source' => mb_substr(trim($source), 0, 200), 'origin' => 'FORM_LABEL'];
		};
		$section = 'DECEASED';
		foreach (self::lines($text) as $line) {
			$hit = self::matchLabel($line);
			if ($hit === null) continue;
			[$target, $value, $sectionChange] = $hit;
			if ($sectionChange !== null) $section = $sectionChange;
			$value = self::clean($value);
			switch ($target) {
				case 'IGNORE':
					break;
				case 'FULLNAME':
				case 'CLIENT_FULLNAME':
					$name = self::name($value);
					if ($name === null) break;
					[$first, $last] = self::splitName($name);
					$client = $target === 'CLIENT_FULLNAME';
					if ($first !== '') $add($client ? 'order_client_first_name' : 'first_name', $first, self::CONFIDENCE, $line);
					if ($last !== '') $add($client ? 'order_client_name' : 'last_name', $last, self::CONFIDENCE, $line);
					break;
				case 'ADDRESS':
					// Die Anschrift im Abschnitt „Auftraggeber“ ist die der beauftragenden Person, nicht der letzte Wohnsitz.
					if ($section === 'CLIENT') break;
					$address = self::address($value);
					if ($address === null) break;
					$add('last_residence', $address['street'], self::CONFIDENCE, $line);
					if ($address['postalCode'] !== '') $add('last_residence_postal_code', $address['postalCode'], self::CONFIDENCE, $line);
					if ($address['city'] !== '') $add('last_residence_city', $address['city'], self::CONFIDENCE, $line);
					break;
				case 'PHONE':
					if ($section !== 'CLIENT') break;
					$phone = self::phone($value);
					if ($phone !== null) $add('order_client_mobile', $phone, self::CONFIDENCE, $line);
					break;
				case 'date_of_birth':
					if ($section === 'CLIENT') break;
					$date = self::date($value);
					if ($date !== null && $date >= '1900-01-01' && $date <= $today->format('Y-m-d')) $add($target, $date, self::CONFIDENCE_DATE, $line);
					break;
				case 'date_of_death':
					$date = self::date($value);
					if ($date !== null && $date >= '1900-01-01' && $date <= $today->modify('+1 day')->format('Y-m-d')) $add($target, $date, self::CONFIDENCE_DATE, $line);
					break;
				default:
					$checked = self::validate($target, $value);
					if ($checked !== null) $add($target, $checked, self::CONFIDENCE, $line);
			}
		}
		// „Sterbeurkunden ____ Stück 3“ (Anzahl hinter „Stück“, Gebührenbereich des Papierauftrags)
		if (!isset($suggestions['certificate_paid_count']) && preg_match('/^\s*Sterbeurkunden\b(?!\s+gebührenfrei)[^\n\d]{0,40}?Stück\s*[:_\-.]*\s*(\d{1,2})\b/imu', $text, $match) && (int)$match[1] > 0) {
			$add('certificate_paid_count', $match[1], self::CONFIDENCE, $match[0]);
		}
		if (isset($suggestions['date_of_birth'], $suggestions['date_of_death']) && $suggestions['date_of_death']['value'] < $suggestions['date_of_birth']['value']) {
			unset($suggestions['date_of_birth'], $suggestions['date_of_death']); // widersprüchlich → beide prüfen lassen
		}
		return array_values($suggestions);
	}

	/** Zeilen mit getrennten Spalten: Tabulator/zwei Leerzeichen und eingebettete „Etikett:“ beginnen eine neue Zeile. */
	public static function lines(string $text): array {
		$text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", "  "], $text);
		$text = preg_replace('/[ \x{00A0}]{2,}/u', "\n", $text) ?? $text;
		$labels = implode('|', array_map(static fn(array $label): string => $label[0], self::LABELS));
		$text = preg_replace('/(?<=\S)[ \x{00A0}]+(?=(?:' . $labels . ')\s*:)/iu', "\n", $text) ?? $text;
		$lines = [];
		foreach (explode("\n", $text) as $line) {
			$line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line, " \t|");
			if ($line !== '') $lines[] = $line;
		}
		return $lines;
	}

	/** @return array{0:string,1:string,2:?string}|null Ziel, Wert, neuer Abschnitt – „Etikett: Wert“ oder ein Etikett allein (Abschnittswechsel). */
	private static function matchLabel(string $line): ?array {
		foreach (self::LABELS as $label) {
			if (preg_match('/^(?:' . $label[0] . ')\s*:\s*(.*)$/iu', $line, $match)) return [$label[1], $match[1], $label[2] ?? null];
			if (preg_match('/^(?:' . $label[0] . ')\s*$/iu', $line)) return [$label[1], '', $label[2] ?? null];
		}
		return null;
	}

	/** Formularlinien, Ankreuzzeichen und Satzzeichen am Rand entfernen. */
	public static function clean(string $value): string {
		$value = preg_replace('/[_…]{2,}|\.{3,}/u', ' ', $value) ?? $value;
		$value = preg_replace('/\s+/u', ' ', $value) ?? $value;
		return trim($value, " \t\n\r\0\x0B:;,-–—_|=~*");
	}

	/** Personenname: nur Buchstaben, Leerzeichen, Bindestrich, Apostroph, Punkt; keine einzelnen Buchstaben (außer Initialen mit Punkt). */
	public static function name(string $value): ?string {
		if (!preg_match('/^[\p{L}][\p{L}\'’.\- ,]{1,79}$/u', $value)) return null;
		$words = preg_split('/[\s,]+/u', trim($value)) ?: [];
		if ($words === [] || count($words) > 6) return null;
		$letters = 0;
		foreach ($words as $word) {
			$plain = preg_replace('/[^\p{L}]/u', '', $word) ?? '';
			if (mb_strlen($plain) < 2 && !preg_match('/^\p{L}\.$/u', $word)) return null;
			if (self::mixedCase($word)) return null; // „StriXerhältnis“
			// Namen beginnen groß (Zusätze wie „von“ ausgenommen); kleingeschriebene Fetzen stammen aus unlesbarer Handschrift.
			if (!preg_match('/^\p{Lu}/u', $word) && !preg_match(self::PARTICLES, $word)) return null;
			$letters += mb_strlen($plain);
		}
		return $letters >= 2 ? self::capitalize($value) : null;
	}

	/** @return array{0:string,1:string} Vorname(n), Nachname – „Müller, Gerd“ oder „Gerd Müller“, Namenszusätze gehören zum Nachnamen. */
	public static function splitName(string $name): array {
		$name = trim($name);
		if (str_contains($name, ',')) { [$last, $first] = array_map('trim', explode(',', $name, 2)); return [$first, $last]; }
		$parts = preg_split('/\s+/u', $name) ?: [];
		if (count($parts) === 1) return ['', $parts[0]];
		$lastStart = count($parts) - 1;
		while ($lastStart > 1 && preg_match('/^(?:von|van|vom|zu|zur|de|der|den|di|du|la|le|ten|ter)$/iu', $parts[$lastStart - 1])) $lastStart--;
		return [implode(' ', array_slice($parts, 0, $lastStart)), implode(' ', array_slice($parts, $lastStart))];
	}

	/** @return array{street:string,postalCode:string,city:string}|null „Stundenweg 2, 28357 Bremen“ */
	public static function address(string $value): ?array {
		if (!preg_match('/^([\p{L}][\p{L}.\'’\- ]{2,60}?)\s+(\d{1,4}\s?[a-zA-Z]?(?:\s?[-\/]\s?\d{1,4}[a-zA-Z]?)?)(?:\s*,\s*|\s+)?(?:(\d{4,5})\s+([\p{L}][\p{L}.\- ]{1,60}))?$/u', $value, $match)) return null;
		$street = trim($match[1]) . ' ' . preg_replace('/\s+/u', '', $match[2]);
		if (self::place(trim($match[1])) === null) return null;
		$city = isset($match[4]) ? self::place(trim($match[4])) : null;
		return ['street' => self::capitalize($street), 'postalCode' => (string)($match[3] ?? ''), 'city' => $city ?? ''];
	}

	public static function phone(string $value): ?string {
		$value = trim($value);
		if (!preg_match('/^\+?[0-9 ()\/\-]{6,25}$/', $value)) return null;
		return preg_match_all('/\d/', $value) >= 6 ? preg_replace('/\s+/', ' ', $value) : null;
	}

	/** Ort, Einrichtung, Friedhof: überwiegend Buchstaben, keine Sonderzeichen aus Zeichensalat. */
	public static function place(string $value): ?string {
		if (!preg_match('/^[\p{L}][\p{L}0-9.\'’\-\/() ]{1,79}$/u', $value)) return null;
		$letters = preg_match_all('/\p{L}/u', $value);
		$visible = mb_strlen(preg_replace('/\s/u', '', $value) ?? $value);
		if ($visible === 0 || $letters / $visible < 0.75) return null;
		foreach (preg_split('/\s+/u', $value) ?: [] as $word) {
			$plain = preg_replace('/[^\p{L}]/u', '', $word) ?? '';
			if ($plain !== '' && mb_strlen($plain) < 2 && !preg_match('/^\p{L}\.$/u', $word) && !in_array(mb_strtolower($word), ['a', 'i'], true)) return null;
			if (self::mixedCase($word)) return null;
			if ($plain !== '' && !preg_match('/^[\p{Lu}0-9(]/u', $word) && !preg_match(self::PARTICLES, $word)) return null;
		}
		return $value;
	}

	public static function date(string $value): ?string {
		if (!preg_match('/(\d{1,2})\s*[.]\s*(\d{1,2})\s*[.]\s*(\d{2,4})/u', $value, $match)) {
			$months = ['januar' => 1, 'februar' => 2, 'märz' => 3, 'maerz' => 3, 'april' => 4, 'mai' => 5, 'juni' => 6, 'juli' => 7, 'august' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'dezember' => 12];
			if (preg_match('/(\d{1,2})\.?\s*(\p{L}+)\s*(\d{4})/u', mb_strtolower($value), $m) && isset($months[$m[2]]) && checkdate($months[$m[2]], (int)$m[1], (int)$m[3])) return sprintf('%04d-%02d-%02d', $m[3], $months[$m[2]], $m[1]);
			return null;
		}
		$year = (int)$match[3]; if ($year < 100) $year += $year > 30 ? 1900 : 2000;
		return checkdate((int)$match[2], (int)$match[1], $year) ? sprintf('%04d-%02d-%02d', $year, $match[2], $match[1]) : null;
	}

	private static function validate(string $field, string $value): ?string {
		if ($value === '' || preg_match('/^(?:-|—|unbekannt|keine angabe|nicht bekannt|entfällt)$/iu', $value)) return null;
		switch ($field) {
			case 'first_name': case 'last_name': case 'birth_name': case 'order_client_first_name': case 'order_client_name':
				return self::name($value);
			case 'birth_place': case 'place_of_death': case 'last_residence_city': case 'birth_registry_office': case 'cemetery_contact': case 'profession':
				return self::place($value);
			case 'last_residence_postal_code':
				return preg_match('/^\d{4,5}$/', $value) ? $value : null;
			case 'order_client_mobile':
				return self::phone($value);
			case 'order_client_relation': case 'civil_status': case 'religion':
				return preg_match('/^\p{L}[\p{L}\- \/]{2,39}$/u', $value) && self::place($value) !== null ? self::capitalize($value) : null;
			case 'pension_insurance_number':
				return preg_match('/^[A-Z0-9][A-Z0-9 ]{5,24}$/i', $value) && preg_match('/\d/', $value) ? strtoupper($value) : null;
			case 'certificate_free_count': case 'certificate_paid_count':
				return preg_match('/^(\d{1,2})\b/', $value, $match) ? $match[1] : null;
			case 'funeral_type':
				$normalized = mb_strtolower($value);
				if (str_contains($normalized, 'feuer') || str_contains($normalized, 'urne') || str_contains($normalized, 'einäscher')) return 'FEUERBESTATTUNG';
				if (str_contains($normalized, 'erd') || str_contains($normalized, 'sarg')) return 'ERDBESTATTUNG';
				if (str_contains($normalized, 'see')) return 'SEEBESTATTUNG';
				return null;
		}
		return null;
	}

	/** Großbuchstabe mitten im Wort (je Teil eines Doppelnamens geprüft): typisch für Erkennungsfehler. */
	private static function mixedCase(string $word): bool {
		foreach (preg_split('/[-\/\'’.]/u', $word) ?: [] as $part) {
			if (preg_match('/\p{Ll}\p{Lu}/u', $part) && !preg_match('/^(?:Mc|Mac)\p{Lu}/u', $part)) return true;
		}
		return false;
	}

	private static function capitalize(string $value): string {
		$value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
		// Nur reine Groß- oder Kleinschreibung angleichen („MÜLLER“ → „Müller“); gemischte Schreibweise bleibt.
		if ($value === mb_strtoupper($value) || $value === mb_strtolower($value)) $value = mb_convert_case(mb_strtolower($value), MB_CASE_TITLE, 'UTF-8');
		return $value;
	}
}
