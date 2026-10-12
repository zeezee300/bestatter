<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service\Pdf;

/**
 * Builds a compact ICC v2 display profile for sRGB IEC 61966-2.1 (D50-adapted
 * primaries, 1024-point tone curve). Generated in code so no third-party
 * profile file has to be shipped; used as PDF/A output intent.
 */
final class SrgbIccProfile {
	public static function bytes(): string {
		$curve = 'curv' . "\0\0\0\0" . pack('N', 1024);
		for ($i = 0; $i < 1024; $i++) {
			$v = $i / 1023;
			$linear = $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
			$curve .= pack('n', (int)round($linear * 65535));
		}
		$tags = [
			'desc' => self::desc('sRGB IEC61966-2.1'),
			'cprt' => 'text' . "\0\0\0\0" . "No copyright, use freely\0",
			'wtpt' => self::xyz(0.9642, 1.0, 0.8249),
			'rXYZ' => self::xyz(0.4360, 0.2225, 0.0139),
			'gXYZ' => self::xyz(0.3851, 0.7169, 0.0971),
			'bXYZ' => self::xyz(0.1431, 0.0606, 0.7141),
			'rTRC' => $curve,
			'gTRC' => $curve,
			'bTRC' => $curve,
		];
		$tableSize = 4 + 12 * count($tags);
		$offset = 128 + $tableSize;
		$table = pack('N', count($tags));
		$body = '';
		$shared = [];
		foreach ($tags as $signature => $data) {
			$hash = md5($data);
			if (!isset($shared[$hash])) {
				while (($offset + strlen($body)) % 4 !== 0) $body .= "\0";
				$shared[$hash] = $offset + strlen($body);
				$body .= $data;
			}
			$table .= $signature . pack('N', $shared[$hash]) . pack('N', strlen($data));
		}
		while ((128 + $tableSize + strlen($body)) % 4 !== 0) $body .= "\0";
		$size = 128 + $tableSize + strlen($body);
		$header = pack('N', $size)
			. "\0\0\0\0"                     // preferred CMM
			. pack('N', 0x02100000)           // version 2.1
			. 'mntr' . 'RGB ' . 'XYZ '
			. pack('n6', 2026, 1, 1, 0, 0, 0) // creation date
			. 'acsp'
			. "\0\0\0\0"                     // primary platform
			. pack('N', 0)                    // flags
			. "\0\0\0\0" . "\0\0\0\0"         // manufacturer, model
			. str_repeat("\0", 8)             // attributes
			. pack('N', 0)                    // rendering intent: perceptual
			. self::s15(0.9642) . self::s15(1.0) . self::s15(0.8249)
			. "\0\0\0\0"                     // creator
			. str_repeat("\0", 16)            // profile ID
			. str_repeat("\0", 28);           // reserved
		return $header . $table . $body;
	}

	private static function xyz(float $x, float $y, float $z): string {
		return 'XYZ ' . "\0\0\0\0" . self::s15($x) . self::s15($y) . self::s15($z);
	}

	private static function s15(float $value): string {
		return pack('N', (int)round($value * 65536) & 0xFFFFFFFF);
	}

	private static function desc(string $text): string {
		$ascii = $text . "\0";
		return 'desc' . "\0\0\0\0" . pack('N', strlen($ascii)) . $ascii
			. pack('N', 0) . pack('N', 0)       // Unicode language code, count
			. pack('n', 0) . "\0" . str_repeat("\0", 67); // ScriptCode code, count, data
	}
}
