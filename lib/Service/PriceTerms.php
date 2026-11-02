<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IConfig;

/**
 * 0.89.5: Schätzbeträge und Gebühren nach Bescheid im Bestattungsauftrag und Kostenvoranschlag.
 *
 * Jede Position hat eine Preisart (CatalogRules::PRICE_BASES). Im Dokument stehen Schätzbeträge mit „ca.“, Gebühren ohne
 * Betrag als „nach Bescheid“; Zwischensummen und Gesamtbetrag zeigen, was fest vereinbart und was voraussichtlich ist.
 * Reine Regeln ohne Datenbank (Tests ohne Nextcloud).
 */
final class PriceTerms {
	public const CONFIG_KEY = 'typical_costs';
	public const DEFAULT_THRESHOLD = 10;
	public const NOTICE = 'nach Bescheid';
	public const PLUS_NOTICE = 'zzgl. Gebühren nach Bescheid';

	/** Abweichungsschwelle in Prozent aus Customizing → Fremdkosten (Standard 10). */
	public static function threshold(?IConfig $config): int {
		if ($config === null) {
			return self::DEFAULT_THRESHOLD;
		}
		$stored = json_decode((string)$config->getAppValue(Application::APP_ID, self::CONFIG_KEY, ''), true);
		return is_array($stored) && isset($stored['deviationPercent']) ? max(0, min(100, (int)$stored['deviationPercent'])) : self::DEFAULT_THRESHOLD;
	}

	/**
	 * Kennzeichen einer Druckzeile: '' (fest), 'ESTIMATE' (ca.) oder 'NOTICE' (nach Bescheid, noch ohne Betrag).
	 * Bestandteile eines Festpreis-Pakets und Positionen ohne Preisart (z. B. Rechnungspositionen) gelten als fest.
	 */
	public static function marker(array $service): string {
		if (($service['packagePricingOfParent'] ?? '') === 'HEADER' || !empty($service['isPackageRow'])) {
			return '';
		}
		$basis = CatalogRules::priceBasis($service['priceBasis'] ?? null) ?? 'FIXED';
		if ($basis === 'FIXED') {
			return '';
		}
		if ($basis === 'ON_NOTICE' && (int)($service['unitPriceCents'] ?? 0) <= 0) {
			return 'NOTICE';
		}
		return 'ESTIMATE';
	}

	/** Betrag mit Kennzeichen: „ca. 320,00 €“ bzw. „nach Bescheid“. */
	public static function amount(string $money, string $marker): string {
		return match ($marker) {
			'ESTIMATE' => 'ca. ' . $money, 'NOTICE' => self::NOTICE, default => $money
		};
	}

	/**
	 * Übersicht über die gedruckten Positionen (ohne Stornos und Paketköpfe).
	 * @return array{fixedGrossCents: int, estimateGrossCents: int, estimateCount: int, noticeTitles: list<string>, open: bool, openByType: array<string, bool>, noticeByType: array<string, bool>}
	 */
	public static function summary(array $items): array {
		$result = ['fixedGrossCents' => 0, 'estimateGrossCents' => 0, 'estimateCount' => 0, 'noticeTitles' => [], 'open' => false, 'openByType' => ['EL' => false, 'FK' => false, 'DP' => false], 'noticeByType' => ['EL' => false, 'FK' => false, 'DP' => false]];
		foreach ($items as $item) {
			if (($item['serviceStatus'] ?? '') === 'STORNIERT' || !empty($item['isPackageHeader'])) {
				continue;
			}
			$type = strtoupper((string)($item['positionType'] ?? 'EL'));
			if (!isset($result['openByType'][$type])) {
				$type = 'EL';
			}
			$marker = self::marker($item);
			$gross = (int)($item['grossCents'] ?? 0);
			if ($marker === '') {
				$result['fixedGrossCents'] += $gross;
				continue;
			}
			$result['open'] = true;
			$result['openByType'][$type] = true;
			if ($marker === 'NOTICE') {
				$result['noticeByType'][$type] = true;
				$title = trim((string)($item['title'] ?? ''));
				if ($title !== '' && !in_array($title, $result['noticeTitles'], true)) {
					$result['noticeTitles'][] = $title;
				}
				continue;
			}
			$result['estimateGrossCents'] += $gross;
			$result['estimateCount']++;
		}
		return $result;
	}

	/** Betrag einer Summe mit offenem Anteil: „ca. 1.234,00 €“, „… zzgl. Gebühren nach Bescheid“ oder nur „nach Bescheid“. */
	public static function total(string $money, int $cents, bool $estimate, bool $notice): string {
		if ($notice && $cents === 0) {
			return self::NOTICE;
		}
		return ($estimate ? 'ca. ' : '') . $money . ($notice ? ' ' . self::PLUS_NOTICE : '');
	}

	/** Text für {{order.price_terms}} (Abschnitt 2 des Auftrags). */
	public static function priceTerms(array $summary, int $threshold): string {
		$estimate = $summary['estimateCount'] > 0;
		$notice = $summary['noticeTitles'] !== [];
		if (!$summary['open']) {
			return 'Die Beträge der Leistungs- und Preisaufstellung sind fest vereinbart; durchlaufende Posten werden in der tatsächlich verauslagten Höhe weitergegeben.';
		}
		$parts = [];
		if ($estimate) {
			$parts[] = 'Mit „ca.“ gekennzeichnete Beträge sind Schätzbeträge nach Erfahrungswerten.';
		}
		if ($notice) {
			$parts[] = 'Mit „nach Bescheid“ gekennzeichnete Gebühren stehen erst mit dem Bescheid der Behörde, des Friedhofs oder der Einrichtung fest.';
		}
		$parts[] = ($estimate && $notice ? 'Beide werden' : 'Sie werden') . ' in der tatsächlich angefallenen Höhe laut Rechnung oder Bescheid abgerechnet.';
		$parts[] = $threshold > 0
			? 'Zeichnet sich ab, dass ein Schätzbetrag um mehr als ' . $threshold . ' % überschritten wird, informiert das Bestattungsunternehmen den/die Auftraggeber/in vorab.'
			: 'Zeichnet sich ab, dass ein Schätzbetrag überschritten wird, informiert das Bestattungsunternehmen den/die Auftraggeber/in vorab.';
		return implode(' ', $parts);
	}

	/** Ergänzung zum Kostenhinweis unter der Aufstellung: fest vereinbarter Anteil, Schätzbeträge, Gebühren nach Bescheid. */
	public static function costNoteSuffix(array $summary, callable $money): string {
		if (!$summary['open']) {
			return '';
		}
		$parts = ['Davon fest vereinbart: ' . $money($summary['fixedGrossCents'])];
		if ($summary['estimateGrossCents'] > 0) {
			$parts[] = 'Schätzbeträge (ca.): ' . $money($summary['estimateGrossCents']);
		}
		$text = implode('; ', $parts);
		if ($summary['noticeTitles'] !== []) {
			$text .= '; zuzüglich Gebühren nach Bescheid (' . implode(', ', $summary['noticeTitles']) . ')';
		}
		return $text . '.';
	}
}
