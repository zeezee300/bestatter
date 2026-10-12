<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service\Pdf;

/**
 * Minimal tokenizer, parser and serializer for PDF objects (ISO 32000-1, 7.2/7.3).
 * Only what is needed to read cross-reference data, the catalog, the document
 * information dictionary and name trees, and to write an incremental update.
 */
final class PdfSyntax {
	private const WHITESPACE = "\x00\x09\x0A\x0C\x0D\x20";
	private const DELIMITERS = '()<>[]{}/%';

	public static function skipWhitespace(string $data, int &$pos): void {
		$length = strlen($data);
		while ($pos < $length) {
			$char = $data[$pos];
			if (str_contains(self::WHITESPACE, $char)) {
				$pos++;
			} elseif ($char === '%') {
				while ($pos < $length && $data[$pos] !== "\n" && $data[$pos] !== "\r") $pos++;
			} else {
				return;
			}
		}
	}

	/** Parses one direct object; indirect references „n g R“ are recognised. */
	public static function parseValue(string $data, int &$pos, int $depth = 0): mixed {
		if ($depth > 64) throw new PdfException('PDF-Objekt ist zu tief verschachtelt.');
		self::skipWhitespace($data, $pos);
		if ($pos >= strlen($data)) throw new PdfException('Unerwartetes Dateiende im PDF.');
		$char = $data[$pos];
		if ($char === '/') return new PdfName(self::parseName($data, $pos));
		if ($char === '(') return new PdfString(self::parseLiteralString($data, $pos));
		if ($char === '<') {
			if (($data[$pos + 1] ?? '') === '<') return self::parseDict($data, $pos, $depth);
			return new PdfString(self::parseHexString($data, $pos), true);
		}
		if ($char === '[') {
			$pos++;
			$items = [];
			while (true) {
				self::skipWhitespace($data, $pos);
				if ($pos >= strlen($data)) throw new PdfException('Unvollständiges Array im PDF.');
				if ($data[$pos] === ']') { $pos++; return $items; }
				$items[] = self::parseValue($data, $pos, $depth + 1);
			}
		}
		$token = self::regularToken($data, $pos);
		if ($token === '') throw new PdfException('Unerwartetes Zeichen im PDF an Position ' . $pos . '.');
		if (preg_match('/^\d+$/', $token) === 1) {
			// Lookahead for „gen R“.
			$save = $pos;
			self::skipWhitespace($data, $pos);
			$gen = self::regularToken($data, $pos);
			if (preg_match('/^\d+$/', $gen) === 1) {
				self::skipWhitespace($data, $pos);
				if (self::regularToken($data, $pos) === 'R') return new PdfRef((int)$token, (int)$gen);
			}
			$pos = $save;
			return new PdfRaw($token);
		}
		if (in_array($token, ['true', 'false', 'null'], true) || preg_match('/^[+-]?(\d+\.?\d*|\.\d+)$/', $token) === 1) return new PdfRaw($token);
		throw new PdfException('Unbekanntes Schlüsselwort „' . substr($token, 0, 20) . '“ im PDF.');
	}

	public static function parseDict(string $data, int &$pos, int $depth = 0): PdfDict {
		$pos += 2;
		$dict = new PdfDict();
		while (true) {
			self::skipWhitespace($data, $pos);
			if ($pos >= strlen($data)) throw new PdfException('Unvollständiges Dictionary im PDF.');
			if ($data[$pos] === '>' && ($data[$pos + 1] ?? '') === '>') { $pos += 2; return $dict; }
			if ($data[$pos] !== '/') throw new PdfException('Ungültiger Dictionary-Schlüssel im PDF an Position ' . $pos . '.');
			$key = self::parseName($data, $pos);
			$dict->entries[$key] = self::parseValue($data, $pos, $depth + 1);
		}
	}

	/** Reads keywords, numbers and similar regular-character runs. */
	public static function regularToken(string $data, int &$pos): string {
		$start = $pos;
		$length = strlen($data);
		while ($pos < $length && !str_contains(self::WHITESPACE, $data[$pos]) && !str_contains(self::DELIMITERS, $data[$pos])) $pos++;
		return substr($data, $start, $pos - $start);
	}

	private static function parseName(string $data, int &$pos): string {
		$pos++;
		$raw = self::regularToken($data, $pos);
		return (string)preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn(array $m): string => chr((int)hexdec($m[1])), $raw);
	}

	private static function parseLiteralString(string $data, int &$pos): string {
		$pos++;
		$length = strlen($data);
		$out = '';
		$nesting = 1;
		while ($pos < $length) {
			$char = $data[$pos++];
			if ($char === '\\') {
				$next = $data[$pos++] ?? '';
				$map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C", '(' => '(', ')' => ')', '\\' => '\\'];
				if (isset($map[$next])) {
					$out .= $map[$next];
				} elseif ($next >= '0' && $next <= '7') {
					$octal = $next;
					for ($i = 0; $i < 2 && isset($data[$pos]) && $data[$pos] >= '0' && $data[$pos] <= '7'; $i++) $octal .= $data[$pos++];
					$out .= chr(octdec($octal) & 0xFF);
				} elseif ($next === "\r") {
					if (($data[$pos] ?? '') === "\n") $pos++;
				} elseif ($next !== "\n") {
					$out .= $next;
				}
			} elseif ($char === '(') {
				$nesting++;
				$out .= $char;
			} elseif ($char === ')') {
				if (--$nesting === 0) return $out;
				$out .= $char;
			} else {
				$out .= $char;
			}
		}
		throw new PdfException('Unvollständige Zeichenkette im PDF.');
	}

	private static function parseHexString(string $data, int &$pos): string {
		$end = strpos($data, '>', $pos);
		if ($end === false) throw new PdfException('Unvollständige Hex-Zeichenkette im PDF.');
		$hex = (string)preg_replace('/[^0-9A-Fa-f]/', '', substr($data, $pos + 1, $end - $pos - 1));
		$pos = $end + 1;
		if (strlen($hex) % 2 === 1) $hex .= '0';
		return (string)hex2bin($hex);
	}

	public static function serialize(mixed $value): string {
		if ($value instanceof PdfName) return '/' . self::escapeName($value->name);
		if ($value instanceof PdfRef) return $value->num . ' ' . $value->gen . ' R';
		if ($value instanceof PdfRaw) return $value->token;
		if ($value instanceof PdfString) {
			if ($value->hex) return '<' . strtoupper(bin2hex($value->bytes)) . '>';
			return '(' . strtr($value->bytes, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r', "\n" => '\\n']) . ')';
		}
		if ($value instanceof PdfDict) {
			$parts = [];
			foreach ($value->entries as $key => $entry) $parts[] = '/' . self::escapeName((string)$key) . ' ' . self::serialize($entry);
			return '<<' . implode(' ', $parts) . '>>';
		}
		if (is_array($value)) return '[' . implode(' ', array_map([self::class, 'serialize'], $value)) . ']';
		if (is_int($value)) return (string)$value;
		if (is_bool($value)) return $value ? 'true' : 'false';
		if ($value === null) return 'null';
		if (is_float($value)) return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
		if (is_string($value)) return self::serialize(new PdfString($value));
		throw new PdfException('Wert kann nicht als PDF-Objekt geschrieben werden.');
	}

	private static function escapeName(string $name): string {
		return (string)preg_replace_callback('/[^\x21-\x7E]|[#()<>\[\]{}\/%]/', static fn(array $m): string => sprintf('#%02X', ord($m[0])), $name);
	}
}
