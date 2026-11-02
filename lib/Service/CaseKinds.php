<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * 0.89.7 (OP-074/OP-075): Vorgangsarten der Fallakte.
 *
 * Eine Akte ist ein Sterbefall (bisheriger Normalfall), ein Auftrag ohne Sterbefall oder eine Vorsorge.
 * Alle Fachbausteine hängen weiterhin an bestatter_cases.id; die Vorgangsart bestimmt Nummernkreis,
 * Oberordner der Ablage, Listen und den Geltungsbereich der Abläufe.
 *
 * Nummern: Sterbefall „2026-0001“ (unverändert), Auftrag „A-2026-0001“, Vorsorge „V-2026-0001“.
 * Jede Art hat einen eigenen, fortlaufenden Zähler je Jahr (bestatter_invoice_sequences).
 */
final class CaseKinds {
	public const DEATH = 'STERBEFALL';
	public const ORDER = 'AUFTRAG';
	public const PRECARE = 'VORSORGE';

	public const ALL = [self::DEATH, self::ORDER, self::PRECARE];

	public const LABELS = [self::DEATH => 'Sterbefall', self::ORDER => 'Auftrag', self::PRECARE => 'Vorsorge'];
	public const PLURAL_LABELS = [self::DEATH => 'Sterbefälle', self::ORDER => 'Aufträge', self::PRECARE => 'Vorsorgen'];

	/** Präfix der Nummer (Sterbefall ohne Präfix, damit vorhandene Nummern gültig bleiben). */
	public const PREFIXES = [self::DEATH => '', self::ORDER => 'A-', self::PRECARE => 'V-'];

	/** Schlüssel des Zählers je Jahr; CASE:{Jahr} besteht seit 0.65.x und bleibt für Sterbefälle. */
	public const SEQUENCES = [self::DEATH => 'CASE', self::ORDER => 'ORDER', self::PRECARE => 'PRECARE'];

	/**
	 * Fallnummern im Fließtext (Belegeingang, Paperless-Feld „Fallnummer“).
	 * Gruppe 1: mit Präfix („V-2026-0001“, davor kein Buchstabe/keine Ziffer); Gruppe 2: ohne Präfix –
	 * aber nie direkt hinter „A-“/„V-“ (sonst würde in „V-2026-0001“ oder „XV-2026-0001“ der Sterbefall „2026-0001“ erkannt).
	 */
	public const NUMBER_PATTERN = '/(?<![0-9A-Za-z])([AV]-20[0-9]{2}-[0-9]{4})(?![0-9])|(?<![0-9])(?<!A-)(?<!V-)(20[0-9]{2}-[0-9]{4})(?![0-9])/';

	public static function normalize(?string $kind, string $default = self::DEATH): string {
		$value = strtoupper(trim((string)$kind));
		if ($value === '') {
			return $default;
		}
		if (!in_array($value, self::ALL, true)) {
			throw new \InvalidArgumentException('Die Vorgangsart „' . (string)$kind . '“ ist unbekannt.');
		}
		return $value;
	}

	public static function label(string $kind): string {
		return self::LABELS[$kind] ?? self::LABELS[self::DEATH];
	}

	public static function format(string $kind, string $year, int $sequence): string {
		return self::PREFIXES[self::normalize($kind)] . sprintf('%s-%04d', $year, $sequence);
	}

	public static function sequenceKey(string $kind, string $year): string {
		return self::SEQUENCES[self::normalize($kind)] . ':' . $year;
	}

	/** Vorgangsart aus der Nummer (für Ablagepfade, wenn nur die Nummer bekannt ist). */
	public static function kindOfNumber(string $caseNumber): string {
		$number = trim($caseNumber);
		if (str_starts_with($number, 'A-')) {
			return self::ORDER;
		}
		if (str_starts_with($number, 'V-')) {
			return self::PRECARE;
		}
		return self::DEATH;
	}

	/** Jahr der Nummer („2026“ aus „2026-0001“, „A-2026-0001“, „V-2026-0001“) oder null. */
	public static function yearOfNumber(string $caseNumber): ?string {
		return preg_match('/^(?:[AV]-)?(20\d{2})-/', trim($caseNumber), $match) === 1 ? $match[1] : null;
	}

	/** @return list<string> Fallnummern im Text (Reihenfolge des Auftretens, ohne Dubletten). */
	public static function numbersInText(string $text): array {
		preg_match_all(self::NUMBER_PATTERN, $text, $matches, PREG_SET_ORDER);
		$numbers = [];
		foreach ($matches as $match) {
			$number = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
			if ($number !== '' && !in_array($number, $numbers, true)) {
				$numbers[] = $number;
			}
		}
		return $numbers;
	}

	/**
	 * Vorbelegung der Auftrags- bzw. KVA-Nummer. Sterbefall wie bisher „A-2026-0001“ bzw. „KVA-2026-0001“; Aufträge und
	 * Vorsorgen tragen ihr Präfix schon in der Nummer („A-2026-0003“, Vorsorgevertrag „V-2026-0007“) – kein doppeltes „A-A-“.
	 */
	public static function orderDocumentNumber(string $caseNumber, bool $quote = false): string {
		$number = trim($caseNumber);
		if ($quote) {
			return 'KVA-' . $number;
		}
		return self::kindOfNumber($number) === self::DEATH ? 'A-' . $number : $number;
	}

	/**
	 * Bezeichnung der sieben Ablaufphasen je Vorgangsart (Konzept Abschnitt 8). Die Phasennummern bleiben technisch gleich;
	 * Aufträge und Vorsorgen benennen sie passend, leere Phasen blendet die Übersicht aus.
	 * @return array<int, string>
	 */
	public static function phaseLabels(string $kind): array {
		$labels = [
			self::ORDER => [1 => 'Anfrage', 2 => 'Beauftragung', 3 => 'Formalitäten', 4 => 'Durchführung', 5 => 'Nacharbeiten', 6 => 'Abrechnung', 7 => 'Abschluss'],
			self::PRECARE => [1 => 'Erstgespräch', 2 => 'Angebot & Vertrag', 3 => 'Finanzierung', 4 => 'Betreuung & Wiedervorlage', 5 => 'Änderungen', 6 => 'Abrechnung', 7 => 'Abschluss'],
		];
		return $labels[$kind] ?? ProcessRules::PHASES;
	}

	/** @return list<string> gültige Vorgangsarten aus einer Liste bzw. kommagetrennten Angabe; leer = Standard (nur Sterbefall). */
	public static function parseList(mixed $value): array {
		$items = is_array($value) ? $value : explode(',', (string)$value);
		$kinds = [];
		foreach ($items as $item) {
			$kind = strtoupper(trim((string)$item));
			if ($kind === '') {
				continue;
			}
			if (!in_array($kind, self::ALL, true)) {
				throw new \InvalidArgumentException('Die Vorgangsart „' . (string)$item . '“ ist unbekannt.');
			}
			if (!in_array($kind, $kinds, true)) {
				$kinds[] = $kind;
			}
		}
		return array_values(array_filter(self::ALL, static fn (string $kind): bool => in_array($kind, $kinds, true)));
	}
}
