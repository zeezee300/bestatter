<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * 0.90.0 – Aus einer ausgestellten Rechnung, Stornorechnung oder Gutschrift die DATEV-Buchungszeilen bilden (ohne Datenbank).
 *
 * - Kontenfindung: Regeln aus den Einstellungen (Warengruppe vor Bereich vor Artikelart vor allgemeiner Regel; mit passendem
 *   Steuersatz vor „jeder Steuersatz“). Echte durchlaufende Posten (Positionsart DP) gehen auf ihr eigenes Konto.
 * - Je Konto, Steuerschlüssel und Kostenstelle eine Zeile mit dem Bruttobetrag, alle mit derselben Belegnummer (FA-10, FA-11).
 * - Beträge immer positiv; die Richtung ergibt sich aus dem Vorzeichen: Rechnung Soll, Storno und Gutschrift Haben (FA-12).
 * - Rundung: DATEV rechnet die Umsatzsteuer aus dem Bruttobetrag der Zeile. Die App rundet je Position. Ergibt die Zeile eine
 *   andere Steuer als die Rechnung, wird diese Rechnung je Position gebucht; bleibt eine Differenz, gibt es eine Warnung (V-05).
 */
final class DatevPostingBuilder {
	public const KIND_LABELS = ['INVOICE' => 'Rechnung', 'CANCELLATION' => 'Stornorechnung', 'CREDIT_NOTE' => 'Gutschrift'];

	/**
	 * @param array $settings normalisierte Einstellungen (AccountingSettingsService::normalize)
	 * @param array $invoice id, number, kind, type, date, caseNumber, branch, referenceNumber, servicePeriodFrom, servicePeriodTo,
	 *                       dueDate, debtor, totals{net,vat,gross}, items[{position, net, vat, gross, vatRate, positionType, group, area, articleType}]
	 * @return array{lines: list<array>, messages: list<array{code:string, severity:string, invoice:string, message:string}>}
	 */
	public static function build(array $settings, array $invoice): array {
		$messages = [];
		$number = (string)$invoice['number'];
		$message = static function (string $code, string $severity, string $text) use (&$messages, $number): void {
			$messages[] = ['code' => $code, 'severity' => $severity, 'invoice' => $number, 'message' => $text];
		};
		if (!preg_match(DatevFormat::DOCUMENT_NUMBER_PATTERN, $number)) {
			$message('V-04', 'ERROR', 'Belegnummer „' . $number . '“ ist für DATEV-Belegfeld 1 ungeeignet (höchstens 36 Zeichen; erlaubt sind Buchstaben, Ziffern und $ & % * + - /). Bitte das Nummernmuster unter Administration → Rechnungswesen anpassen; bereits ausgestellte Nummern bleiben unverändert.');
		}
		$kind = (string)$invoice['kind'];
		if ($kind !== 'INVOICE' && trim((string)($invoice['referenceNumber'] ?? '')) === '') {
			$message('V-07', 'ERROR', self::KIND_LABELS[$kind] . ' ohne Bezug auf die Ursprungsrechnung.');
		}
		$items = (array)$invoice['items'];
		$sum = ['net' => 0, 'vat' => 0, 'gross' => 0];
		foreach ($items as $item) {
			$sum['net'] += (int)$item['net'];
			$sum['vat'] += (int)$item['vat'];
			$sum['gross'] += (int)$item['gross'];
			if ((int)$item['net'] + (int)$item['vat'] !== (int)$item['gross']) {
				$message('V-05', 'ERROR', 'Position ' . $item['position'] . ': Brutto ' . self::euro((int)$item['gross']) . ' ≠ Netto + Steuer.');
			}
		}
		$totals = (array)$invoice['totals'];
		if ($sum['net'] !== (int)$totals['net'] || $sum['vat'] !== (int)$totals['vat'] || $sum['gross'] !== (int)$totals['gross']) {
			$message('V-06', 'ERROR', 'Summe der Positionen (' . self::euro($sum['gross']) . ') weicht vom Rechnungsbetrag (' . self::euro((int)$totals['gross']) . ') ab.');
		}

		$kost1 = $settings['kost1'] === 'BRANCH' ? self::costCenter((string)($settings['costCenters'][strtoupper((string)$invoice['branch'])] ?? $invoice['branch'])) : '';
		$kost2 = $settings['kost2'] === 'CASE' ? self::costCenter((string)$invoice['caseNumber']) : '';
		$groups = [];
		foreach ($items as $item) {
			if ((int)$item['gross'] === 0) {
				continue;
			}
			$target = self::account($settings, $item);
			if ($target === null) {
				$isDp = strtoupper((string)$item['positionType']) === 'DP';
				$message('V-02', 'ERROR', $isDp
					? 'Für durchlaufende Posten ist kein Konto hinterlegt (Position ' . $item['position'] . ').'
					: 'Keine Kontenzuordnung für ' . (int)$item['vatRate'] . ' % USt' . ($item['group'] !== '' ? ', Warengruppe ' . $item['group'] : '') . ($item['articleType'] !== '' ? ', Artikelart ' . $item['articleType'] : '') . ' (Position ' . $item['position'] . ').');
				continue;
			}
			$key = implode('|', [$target['account'], $target['taxKey'], (int)$item['vatRate'], $target['passThrough'] ? 1 : 0]);
			$groups[$key] ??= ['target' => $target, 'vatRate' => (int)$item['vatRate'], 'items' => []];
			$groups[$key]['items'][] = $item;
		}

		$base = [
			'account' => (string)$invoice['debtor'], 'date' => (string)$invoice['date'], 'documentNo' => $number,
			'documentNo2' => self::documentNo2((string)$invoice['caseNumber']), 'kost1' => $kost1, 'kost2' => $kost2,
			'serviceDate' => (string)(($invoice['servicePeriodTo'] ?? '') ?: ($invoice['servicePeriodFrom'] ?? '')),
			'dueDate' => $kind === 'INVOICE' ? (string)($invoice['dueDate'] ?? '') : '', 'invoiceId' => (int)$invoice['id'],
		];
		$lines = [];
		$textLimit = 60;
		foreach ($groups as $group) {
			$gross = array_sum(array_map(static fn (array $item): int => (int)$item['gross'], $group['items']));
			$vat = array_sum(array_map(static fn (array $item): int => (int)$item['vat'], $group['items']));
			$parts = [$group['items']];
			if (self::datevTax($gross, $group['vatRate']) !== abs($vat) && count($group['items']) > 1) {
				$parts = array_map(static fn (array $item): array => [$item], $group['items']);
				$message('V-05', 'INFO', 'Rundung: Die Umsatzsteuer der Zeile ' . $group['target']['account'] . ' wird je Position gebucht, damit sie der Rechnung entspricht.');
			}
			foreach ($parts as $part) {
				$partGross = array_sum(array_map(static fn (array $item): int => (int)$item['gross'], $part));
				$partVat = array_sum(array_map(static fn (array $item): int => (int)$item['vat'], $part));
				if ($partGross === 0) {
					continue;
				}
				$difference = self::datevTax($partGross, $group['vatRate']) - abs($partVat);
				if ($difference !== 0 && $group['vatRate'] > 0) {
					$message('V-05', 'WARNING', 'DATEV errechnet aus ' . self::euro(abs($partGross)) . ' eine Umsatzsteuer von ' . self::euro(self::datevTax($partGross, $group['vatRate'])) . ', die Rechnung weist ' . self::euro(abs($partVat)) . ' aus (Differenz ' . self::euro(abs($difference)) . '). Bitte mit der Steuerberatung abstimmen.');
				}
				$text = self::text($invoice, $group['target'], count($parts) > 1 ? (string)$part[0]['position'] : '');
				if (mb_strlen($text) > $textLimit) {
					$message('V-08', 'WARNING', 'Buchungstext auf 60 Zeichen gekürzt: „' . $text . '“.');
					$text = mb_substr($text, 0, $textLimit);
				}
				$lines[] = $base + [
					'amountCents' => abs($partGross), 'side' => $partGross > 0 ? 'S' : 'H', 'contraAccount' => $group['target']['account'],
					'taxKey' => $group['target']['taxKey'], 'text' => $text, 'grossSigned' => $partGross, 'vatCents' => $partVat, 'vatRate' => $group['vatRate'],
				];
			}
		}
		if ($lines === [] && $items !== [] && !array_filter($messages, static fn (array $m): bool => $m['severity'] === 'ERROR')) {
			$message('V-06', 'INFO', 'Beleg über 0,00 € – keine Buchungszeile.');
		}
		return ['lines' => $lines, 'messages' => $messages];
	}

	/** Kontenfindung für eine Position; null = keine Zuordnung (V-02). @return array{account:string, taxKey:string, label:string, passThrough:bool}|null */
	public static function account(array $settings, array $item): ?array {
		$isDp = strtoupper((string)$item['positionType']) === 'DP';
		$best = null;
		$bestScore = -1;
		foreach ($settings['rules'] as $rule) {
			if ((bool)$rule['passThrough'] !== $isDp) {
				continue;
			}
			$checks = [['group', 8], ['area', 4], ['articleType', 2]];
			$score = 0;
			foreach ($checks as [$field, $weight]) {
				if ($rule[$field] === '') {
					continue;
				}
				if ($rule[$field] !== strtoupper((string)($item[$field] ?? ''))) {
					continue 2;
				}
				$score += $weight;
			}
			if ($rule['vatRate'] !== null) {
				if ((int)$rule['vatRate'] !== (int)$item['vatRate']) {
					continue;
				}
				$score += 1;
			}
			if ($score > $bestScore) {
				$best = $rule;
				$bestScore = $score;
			}
		}
		if ($best !== null) {
			return ['account' => (string)$best['account'], 'taxKey' => (string)$best['taxKey'], 'label' => (string)$best['label'], 'passThrough' => $isDp];
		}
		if ($isDp && (string)$settings['passThroughAccount'] !== '') {
			return ['account' => (string)$settings['passThroughAccount'], 'taxKey' => '', 'label' => 'durchl. Posten', 'passThrough' => true];
		}
		return null;
	}

	/** Umsatzsteuer, die DATEV aus einem Bruttobetrag errechnet (kaufmännisch gerundet, in Cent). */
	public static function datevTax(int $grossCents, int $rate): int {
		if ($rate <= 0) {
			return 0;
		}
		return (int)round(abs($grossCents) * $rate / (100 + $rate));
	}

	/** Datensparsamer Buchungstext: Belegart, Fall- bzw. Auftragsnummer, Kontenbezeichnung – keine Namen (NFA-06). */
	public static function text(array $invoice, array $target, string $position = ''): string {
		$kind = (string)$invoice['kind'];
		if ($kind === 'CANCELLATION') {
			$text = 'Storno zu ' . $invoice['referenceNumber'];
		} elseif ($kind === 'CREDIT_NOTE') {
			$text = 'Gutschrift zu ' . $invoice['referenceNumber'];
		} else {
			$text = ((string)$invoice['type'] === 'FINAL' ? 'Schlussrechnung' : 'Teilrechnung') . ' ' . $invoice['caseNumber'];
		}
		if ($target['label'] !== '') {
			$text .= ' ' . $target['label'];
		}
		if ($position !== '') {
			$text .= ' Pos. ' . $position;
		}
		return trim($text);
	}

	/** Belegfeld 2 (max. 12 Zeichen): Fall- bzw. Auftragsnummer, sofern sie hineinpasst. */
	public static function documentNo2(string $caseNumber): string {
		$value = preg_replace('/[^A-Za-z0-9$&%*+\-\/]/', '', $caseNumber) ?? '';
		return strlen($value) <= 12 ? $value : '';
	}

	public static function costCenter(string $value): string {
		return mb_substr(preg_replace('/[^\p{L}\p{N}\-\/ ]/u', '', trim($value)) ?? '', 0, 36);
	}

	public static function euro(int $cents): string {
		return ($cents < 0 ? '-' : '') . number_format(abs($cents) / 100, 2, ',', '.') . ' €';
	}
}
