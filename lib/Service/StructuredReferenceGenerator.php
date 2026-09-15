<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/** Erzeugt ISO-11649-Creditor-References (RF-Referenzen). */
class StructuredReferenceGenerator {
	public function fromInvoiceNumber(string $invoiceNumber): string {
		$reference = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($invoiceNumber))) ?? '';
		if ($reference === '') throw new \InvalidArgumentException('Aus der Rechnungsnummer konnte keine strukturierte Zahlungsreferenz gebildet werden.');
		// ISO 11649 erlaubt höchstens 25 Zeichen einschließlich RF und Prüfziffer.
		if (strlen($reference) > 21) $reference = substr($reference, 0, 12) . strtoupper(substr(hash('sha256', $reference), 0, 9));
		return 'RF' . $this->checkDigits($reference) . $reference;
	}

	public function checkDigits(string $input): string {
		$normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper($input)) ?? '';
		if ($normalized === '') throw new \InvalidArgumentException('Für die Prüfziffer wird eine alphanumerische Referenz benötigt.');
		$remainder = $this->mod97($normalized . 'RF00');
		return str_pad((string)(98 - $remainder), 2, '0', STR_PAD_LEFT);
	}

	private function mod97(string $value): int {
		$remainder = 0;
		foreach (str_split($value) as $character) {
			$digits = ctype_alpha($character) ? (string)(ord($character) - 55) : $character;
			foreach (str_split($digits) as $digit) $remainder = (($remainder * 10) + (int)$digit) % 97;
		}
		return $remainder;
	}
}
