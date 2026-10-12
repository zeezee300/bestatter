<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/** Format- und MOD-97-Prüfung für IBAN nach ISO 13616/7064. */
class IbanValidator {
	public function normalize(string $iban): string {
		return strtoupper((string)preg_replace('/\s+/', '', trim($iban)));
	}

	public function hasValidFormat(string $iban): bool {
		return preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $this->normalize($iban)) === 1;
	}

	public function isValid(string $iban): bool {
		$iban = $this->normalize($iban);
		if (!$this->hasValidFormat($iban)) return false;
		$rearranged = substr($iban, 4) . substr($iban, 0, 4);
		$remainder = 0;
		foreach (str_split($rearranged) as $character) {
			$digits = ctype_alpha($character) ? (string)(ord($character) - 55) : $character;
			foreach (str_split($digits) as $digit) $remainder = (($remainder * 10) + (int)$digit) % 97;
		}
		return $remainder === 1;
	}
}
