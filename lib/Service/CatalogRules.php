<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * Reine Fachregeln des Leistungskatalogs (0.69.0) ohne Datenbankzugriff, damit sie überall
 * gleich angewendet und ohne Nextcloud getestet werden können.
 *
 * SAP-Vorbild: Materialart (MTART) → Artikelart, Steuerklassifikation (MLAN) → Steuerklasse,
 * Warengruppe (MATKL) mit Auswahlregel, Vertriebsstückliste (STPO) mit festen, optionalen und
 * alternativen Positionen, Positionsstatus aus Mengen (VBUP).
 */
final class CatalogRules {
	public const ARTICLE_TYPES = [
		'DIENSTLEISTUNG' => ['label' => 'Dienstleistung', 'costType' => 'INTERNAL', 'positionType' => 'EL'],
		'HANDELSWARE' => ['label' => 'Handelsware', 'costType' => 'INTERNAL', 'positionType' => 'EL'],
		'FREMDLEISTUNG' => ['label' => 'Fremdleistung', 'costType' => 'THIRD_PARTY', 'positionType' => 'FK'],
		'AUSLAGE' => ['label' => 'Auslage / Gebühr', 'costType' => 'EXPENSE', 'positionType' => 'DP'],
		'ZUSCHLAG' => ['label' => 'Zuschlag', 'costType' => 'INTERNAL', 'positionType' => 'EL'],
		'PAKET' => ['label' => 'Paket (Stückliste)', 'costType' => 'INTERNAL', 'positionType' => 'EL'],
	];

	public const TAX_CLASSES = [
		'V' => 'voller Steuersatz',
		'E' => 'ermäßigter Steuersatz',
		'A' => 'Auslage / durchlaufender Posten (ohne USt)',
	];

	/** Steuerklasse → Satz je Länderprofil. Ohne eigenes Profil gilt Deutschland. */
	private const TAX_RATES = [
		'DE' => ['V' => 19, 'E' => 7, 'A' => 0],
		'AT' => ['V' => 20, 'E' => 10, 'A' => 0],
	];

	public const SELECTION_RULES = [
		'MANY' => 'beliebig viele',
		'MAX_ONE' => 'höchstens eine Position',
		'EXACTLY_ONE' => 'genau eine Position (Pflicht bei Festschreibung)',
	];

	public const FUNERAL_SCOPES = ['ALL' => 'alle Bestattungsarten', 'BURIAL' => 'nur Erdbestattung', 'CREMATION' => 'nur Feuerbestattung'];

	public const BOM_PRICING = ['HEADER' => 'Festpreis am Paket', 'ITEMS' => 'Summe der Positionen'];

	public const BOM_ITEM_TYPES = ['FIXED' => 'fest', 'OPTIONAL' => 'optional', 'ALTERNATIVE' => 'Alternative'];

	public const PRINT_MODES = [
		'HEADER_ONLY' => 'nur Paketzeile',
		'ITEMS_NO_PRICES' => 'Paket mit Bestandteilen ohne Einzelpreise',
		'ITEMS_WITH_PRICES' => 'Paket mit Bestandteilen und Einzelpreisen',
	];

	public const SERVICE_STATUS_LABELS = [
		'BEAUFTRAGT' => 'beauftragt',
		'TEILWEISE_ERBRACHT' => 'teilweise erbracht',
		'ERBRACHT' => 'erbracht',
		'TEILWEISE_ABGERECHNET' => 'teilweise berechnet',
		'ABGERECHNET' => 'berechnet',
		'STORNIERT' => 'storniert',
	];

	public static function taxRate(string $taxClass, string $countryCode = 'DE'): int {
		$rates = self::TAX_RATES[strtoupper($countryCode)] ?? self::TAX_RATES['DE'];
		return $rates[strtoupper($taxClass)] ?? $rates['V'];
	}

	public static function taxClassForRate(int $rate, string $positionType = 'EL'): string {
		if (strtoupper($positionType) === 'DP' || $rate === 0) return 'A';
		return $rate <= 10 ? 'E' : 'V';
	}

	public static function costType(string $articleType): string {
		return self::ARTICLE_TYPES[$articleType]['costType'] ?? 'INTERNAL';
	}

	public static function positionType(string $articleType): string {
		return self::ARTICLE_TYPES[$articleType]['positionType'] ?? 'EL';
	}

	/**
	 * Positionsstatus aus beauftragter, erbrachter und berechneter Menge. „Storniert“ ist die
	 * einzige gespeicherte Ausnahme (Position wurde aus der Auswahl entfernt).
	 */
	public static function deriveStatus(int $ordered, int $performed, int $invoiced, string $stored = ''): string {
		if (strtoupper($stored) === 'STORNIERT') return 'STORNIERT';
		if ($invoiced > 0) return $invoiced >= max($ordered, $performed) ? 'ABGERECHNET' : 'TEILWEISE_ABGERECHNET';
		if ($performed <= 0) return 'BEAUFTRAGT';
		return $performed >= $ordered ? 'ERBRACHT' : 'TEILWEISE_ERBRACHT';
	}

	/** Paket-Kopf: Status aus den Unterpositionen (schwächster Fortschritt bestimmt). */
	public static function aggregateStatus(array $statuses): string {
		$statuses = array_values(array_filter($statuses, static fn(string $status): bool => $status !== 'STORNIERT'));
		if ($statuses === []) return 'STORNIERT';
		$order = ['BEAUFTRAGT' => 0, 'TEILWEISE_ERBRACHT' => 1, 'ERBRACHT' => 2, 'TEILWEISE_ABGERECHNET' => 3, 'ABGERECHNET' => 4];
		$min = min(array_map(static fn(string $status): int => $order[$status] ?? 0, $statuses));
		$max = max(array_map(static fn(string $status): int => $order[$status] ?? 0, $statuses));
		if ($min === $max) return array_search($min, $order, true) ?: 'BEAUFTRAGT';
		if ($max >= 3) return 'TEILWEISE_ABGERECHNET';
		return 'TEILWEISE_ERBRACHT';
	}

	/**
	 * Verteilt einen Paket-Festpreis (netto, Cent) anteilig nach Listenpreisen auf die
	 * Unterpositionen, damit jede Position ihre eigene Steuerklasse behält (anteilige
	 * Steuerverteilung). Rundungsdifferenzen trägt eine Position mit Menge 1 („Anker“).
	 *
	 * @param array<string, array{listLineCents:int, quantityMilli:int, anchor:bool}> $lines
	 * @return array<string, int> Einzelpreis je Schlüssel in Cent
	 */
	public static function allocate(int $targetCents, array $lines): array {
		if ($lines === []) return [];
		$targetCents = max(0, $targetCents);
		$sum = array_sum(array_map(static fn(array $line): int => max(0, (int)$line['listLineCents']), $lines));
		$anchor = null;
		foreach ($lines as $key => $line) if ($line['anchor'] && (int)$line['quantityMilli'] === 1000) { $anchor = $key; break; }
		if ($anchor === null) foreach ($lines as $key => $line) if ((int)$line['quantityMilli'] === 1000) { $anchor = $key; break; }
		$amounts = [];
		if ($sum <= 0) {
			foreach ($lines as $key => $line) $amounts[$key] = 0;
			$amounts[$anchor ?? array_key_first($lines)] = $targetCents;
		} else {
			$fractions = [];
			$assigned = 0;
			foreach ($lines as $key => $line) {
				$exact = $targetCents * max(0, (int)$line['listLineCents']) / $sum;
				$amounts[$key] = (int)floor($exact);
				$fractions[$key] = $exact - floor($exact);
				$assigned += $amounts[$key];
			}
			arsort($fractions);
			foreach (array_keys($fractions) as $key) {
				if ($assigned >= $targetCents) break;
				$amounts[$key]++;
				$assigned++;
			}
		}
		$prices = [];
		$actual = 0;
		foreach ($lines as $key => $line) {
			$quantity = max(1, (int)$line['quantityMilli']);
			$prices[$key] = $quantity === 1000 ? $amounts[$key] : (int)round($amounts[$key] * 1000 / $quantity);
			$actual += (int)round($quantity * $prices[$key] / 1000);
		}
		$difference = $targetCents - $actual;
		if ($difference !== 0 && $anchor !== null) $prices[$anchor] = max(0, $prices[$anchor] + $difference);
		return $prices;
	}
}
