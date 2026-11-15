<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service\Pdf;

/**
 * 0.90.1 – Text und Dateianlagen aus PDFs lesen (Belegeingang ohne Paperless).
 *
 * Liest die Textebene digital erzeugter PDFs (Rechnungen aus Büroprogrammen, Behördenschreiben als PDF) über die
 * Seiteninhalte: Textoperatoren Tj/TJ/'/", Schriftwechsel Tf, Zeilenwechsel (Td, TD, T-Stern, Tm) und Formular-XObjects.
 * Zeichen werden über die ToUnicode-Tabelle der Schrift übersetzt, sonst über WinAnsi bzw. eine Differences-Liste.
 * Gescannte PDFs enthalten nur Bilder – dann ist das Ergebnis leer und die Texterkennung (OCR) ist zuständig.
 * Verschlüsselte PDFs werden nicht gelesen. Grenzen: Seiten, Zeichen und Verschachtelungstiefe (siehe Konstanten).
 *
 * embeddedXmlFiles() liefert eingebettete XML-Dateianlagen (z. B. factur-x.xml, xrechnung.xml in ZUGFeRD-PDFs).
 */
final class PdfTextExtractor {
	public const MAX_PAGES = 30;
	public const MAX_CHARS = 200000;
	private const MAX_DEPTH = 4;
	private const MAX_ATTACHMENTS = 5;
	private const MAX_ATTACHMENT_BYTES = 5 * 1024 * 1024;
	/** Häufige Glyphennamen aus Differences-Listen (Adobe Glyph List, Auszug für Deutsch). */
	private const GLYPHS = [
		'space' => ' ', 'adieresis' => 'ä', 'odieresis' => 'ö', 'udieresis' => 'ü', 'Adieresis' => 'Ä', 'Odieresis' => 'Ö', 'Udieresis' => 'Ü',
		'germandbls' => 'ß', 'eacute' => 'é', 'egrave' => 'è', 'Eacute' => 'É', 'agrave' => 'à', 'ccedilla' => 'ç', 'euro' => '€', 'Euro' => '€',
		'endash' => '–', 'emdash' => '—', 'quotedblleft' => '“', 'quotedblright' => '”', 'quotedblbase' => '„', 'quoteleft' => '‘', 'quoteright' => '’',
		'quotesinglbase' => '‚', 'bullet' => '•', 'section' => '§', 'degree' => '°', 'periodcentered' => '·', 'hyphen' => '-', 'minus' => '−',
		'period' => '.', 'comma' => ',', 'colon' => ':', 'semicolon' => ';', 'slash' => '/', 'parenleft' => '(', 'parenright' => ')', 'at' => '@',
		'ampersand' => '&', 'percent' => '%', 'plus' => '+', 'equal' => '=', 'underscore' => '_', 'numbersign' => '#', 'asterisk' => '*', 'quotesingle' => "'",
		'quotedbl' => '"', 'exclam' => '!', 'question' => '?', 'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5',
		'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'fi' => 'fi', 'fl' => 'fl', 'ff' => 'ff',
	];

	/** Text aller Seiten (höchstens MAX_PAGES), Zeilen durch Zeilenumbrüche getrennt; leer, wenn keine Textebene vorhanden ist. */
	public static function extract(string $pdf): string {
		try {
			$doc = new PdfDocument($pdf);
			if ($doc->isEncrypted()) {
				return '';
			}
			$extractor = new self($doc);
			return $extractor->run();
		} catch (\Throwable) {
			return '';
		}
	}

	/** @return array<string,string> Dateiname → Inhalt der eingebetteten XML-Dateien */
	public static function embeddedXmlFiles(string $pdf): array {
		try {
			$doc = new PdfDocument($pdf);
		} catch (\Throwable) {
			return [];
		}
		$found = [];
		$specs = [];
		try {
			$catalog = $doc->catalog();
			$names = $doc->resolve($catalog->get('Names'));
			if ($names instanceof PdfDict) {
				foreach (self::nameTreeValues($doc, $names->get('EmbeddedFiles'), 0) as $spec) {
					$specs[] = $spec;
				}
			}
			$af = $doc->resolve($catalog->get('AF'));
			foreach (is_array($af) ? $af : [] as $entry) {
				$specs[] = $entry;
			}
		} catch (\Throwable) {
			return [];
		}
		foreach ($specs as $entry) {
			if (count($found) >= self::MAX_ATTACHMENTS) {
				break;
			}
			try {
				$spec = $doc->resolve($entry);
				if (!$spec instanceof PdfDict) {
					continue;
				}
				$nameValue = $doc->resolve($spec->get('UF') ?? $spec->get('F'));
				$name = $nameValue instanceof PdfString ? trim($nameValue->text()) : '';
				if ($name === '' || isset($found[$name]) || !str_ends_with(strtolower($name), '.xml')) {
					continue;
				}
				$ef = $doc->resolve($spec->get('EF'));
				$ref = $ef instanceof PdfDict ? ($ef->get('UF') ?? $ef->get('F')) : null;
				$content = $ref instanceof PdfRef ? $doc->streamOf($ref) : null;
				if ($content !== null && $content !== '' && strlen($content) <= self::MAX_ATTACHMENT_BYTES) {
					$found[$name] = $content;
				}
			} catch (\Throwable) {
				continue;
			}
		}
		return $found;
	}

	// ------------------------------------------------------------------ intern

	private string $out = '';
	/** @var array<string,array{map:array<string,string>,bytes:int,simple:array<int,string>}> */
	private array $fontCache = [];

	private function __construct(
		private PdfDocument $doc,
	) {
	}

	private function run(): string {
		$pages = [];
		$this->collectPages($this->doc->resolve($this->doc->catalog()->get('Pages')), null, $pages, 0, []);
		foreach (array_slice($pages, 0, self::MAX_PAGES) as [$page, $resources]) {
			$contents = $this->doc->resolve($page->get('Contents'));
			$refs = is_array($contents) ? $contents : [$page->get('Contents')];
			$stream = '';
			foreach ($refs as $ref) {
				if ($ref instanceof PdfRef) {
					try {
						$stream .= ($this->doc->streamOf($ref) ?? '') . "\n";
					} catch (\Throwable) {
					}
				}
			}
			$this->interpret($stream, $resources instanceof PdfDict ? $resources : new PdfDict(), 0);
			$this->out .= "\n\n";
			if (strlen($this->out) > self::MAX_CHARS) {
				break;
			}
		}
		$text = mb_substr($this->out, 0, self::MAX_CHARS);
		if (!mb_check_encoding($text, 'UTF-8')) {
			$text = (string)mb_convert_encoding($text, 'UTF-8', 'UTF-8');
		}
		$text = (string)preg_replace('/[^\S\n]+/u', ' ', $text);
		$text = (string)preg_replace('/ *\n */u', "\n", $text);
		return trim((string)preg_replace('/\n{3,}/', "\n\n", $text));
	}

	/** @param list<array{0:PdfDict,1:mixed}> $pages */
	private function collectPages(mixed $node, mixed $inherited, array &$pages, int $depth, array $seen): void {
		if (!$node instanceof PdfDict || $depth > 32 || count($pages) >= self::MAX_PAGES) {
			return;
		}
		$resources = $node->has('Resources') ? $this->doc->resolve($node->get('Resources')) : $inherited;
		$kids = $this->doc->resolve($node->get('Kids'));
		if (is_array($kids)) {
			foreach ($kids as $kid) {
				if ($kid instanceof PdfRef) {
					if (isset($seen[$kid->num])) {
						continue;
					}
					$seen[$kid->num] = true;
				}
				$this->collectPages($this->doc->resolve($kid), $resources, $pages, $depth + 1, $seen);
			}
			return;
		}
		$type = $node->get('Type');
		if (!$type instanceof PdfName || $type->name === 'Page') {
			$pages[] = [$node, $resources];
		}
	}

	private function interpret(string $content, PdfDict $resources, int $depth): void {
		if ($depth > self::MAX_DEPTH) {
			return;
		}
		$fonts = $this->doc->resolve($resources->get('Font'));
		$xobjects = $this->doc->resolve($resources->get('XObject'));
		$font = null;
		$operands = [];
		$lastY = null;
		$pos = 0;
		$length = strlen($content);
		while ($pos < $length) {
			PdfSyntax::skipWhitespace($content, $pos);
			if ($pos >= $length) {
				break;
			}
			$char = $content[$pos];
			try {
				if ($char === '/' || $char === '(' || $char === '[' || ($char === '<')) {
					$operands[] = PdfSyntax::parseValue($content, $pos);
					continue;
				}
			} catch (\Throwable) {
				$pos++;
				$operands = [];
				continue;
			}
			if ($char === ']' || $char === ')' || $char === '>' || $char === '{' || $char === '}') {
				$pos++;
				continue;
			}
			$token = PdfSyntax::regularToken($content, $pos);
			if ($token === '') {
				$pos++;
				continue;
			}
			if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)$/', $token) === 1) {
				$operands[] = (float)$token;
				continue;
			}
			switch ($token) {
				case 'BT':
					// Neue Textzeile entscheidet erst Tm/Td (gleiche Höhe = gleiche Zeile, z. B. Tabellenspalten).
					break;
				case 'Tf':
					$name = $operands[0] ?? null;
					$font = $name instanceof PdfName && $fonts instanceof PdfDict ? $this->font($name->name, $fonts->get($name->name)) : null;
					break;
				case 'Tj':
				case "'":
				case '"':
					if ($token !== 'Tj') {
						$this->newline();
					}
					$string = end($operands);
					if ($string instanceof PdfString) {
						$this->out .= $this->decode($string->bytes, $font);
					}
					break;
				case 'TJ':
					$array = end($operands);
					foreach (is_array($array) ? $array : [] as $part) {
						if ($part instanceof PdfString) {
							$this->out .= $this->decode($part->bytes, $font);
						} elseif ($part instanceof PdfRaw && (float)$part->token < -180) {
							$this->out .= ' ';
						}
					}
					break;
				case 'Td':
				case 'TD':
					// Td versetzt relativ zum Zeilenanfang; ohne Glyphenbreiten ist ein waagerechter Versatz kein sicheres
					// Wortende (Unterschneidung). Leerzeichen stehen in der Regel als eigenes Zeichen im Text.
					$ty = (float)($operands[1] ?? 0);
					if (abs($ty) > 0.01) {
						$this->newline();
						$lastY = $lastY !== null ? $lastY + $ty : null;
					}
					break;
				case 'T*':
					$this->newline();
					break;
				case 'Tm':
					$y = (float)($operands[5] ?? 0);
					if ($lastY === null || abs($y - $lastY) > 0.5) {
						$this->newline();
					} else {
						$this->out .= ' ';
					}
					$lastY = $y;
					break;
				case 'Do':
					$name = $operands[0] ?? null;
					if ($name instanceof PdfName && $xobjects instanceof PdfDict) {
						$this->formXObject($xobjects->get($name->name), $resources, $depth);
					}
					break;
				case 'BI':
					// Inline-Bild: Daten bis „EI“ überspringen.
					$end = preg_match('/\sEI(?=\s|$)/', $content, $match, PREG_OFFSET_CAPTURE, $pos) === 1 ? (int)$match[0][1] + 3 : $length;
					$pos = $end;
					break;
			}
			$operands = [];
			if (strlen($this->out) > self::MAX_CHARS) {
				return;
			}
		}
	}

	private function formXObject(mixed $ref, PdfDict $parentResources, int $depth): void {
		if (!$ref instanceof PdfRef) {
			return;
		}
		$dict = $this->doc->resolve($ref);
		if (!$dict instanceof PdfDict || !($dict->get('Subtype') instanceof PdfName) || $dict->get('Subtype')->name !== 'Form') {
			return;
		}
		try {
			$stream = $this->doc->streamOf($ref) ?? '';
		} catch (\Throwable) {
			return;
		}
		$resources = $this->doc->resolve($dict->get('Resources'));
		$this->interpret($stream, $resources instanceof PdfDict ? $resources : $parentResources, $depth + 1);
	}

	private function newline(): void {
		if ($this->out !== '' && !str_ends_with($this->out, "\n")) {
			$this->out .= "\n";
		}
	}

	/** @return array{map:array<string,string>,bytes:int,simple:array<int,string>}|null */
	private function font(string $name, mixed $ref): ?array {
		$key = $ref instanceof PdfRef ? 'ref' . $ref->num : 'name' . $name;
		if (array_key_exists($key, $this->fontCache)) {
			return $this->fontCache[$key];
		}
		$dict = $this->doc->resolve($ref);
		if (!$dict instanceof PdfDict) {
			return $this->fontCache[$key] = null;
		}
		$subtype = $dict->get('Subtype') instanceof PdfName ? $dict->get('Subtype')->name : '';
		$font = ['map' => [], 'bytes' => $subtype === 'Type0' ? 2 : 1, 'simple' => []];
		$toUnicode = $dict->get('ToUnicode');
		if ($toUnicode instanceof PdfRef) {
			try {
				[$font['map'], $bytes] = self::parseCmap($this->doc->streamOf($toUnicode) ?? '');
				if ($bytes > 0) {
					$font['bytes'] = $bytes;
				}
			} catch (\Throwable) {
			}
		}
		if ($subtype !== 'Type0') {
			$font['simple'] = $this->simpleEncoding($this->doc->resolve($dict->get('Encoding')));
		}
		return $this->fontCache[$key] = $font;
	}

	/** @return array<int,string> Byte → Zeichen aus einer Differences-Liste (Rest: WinAnsi) */
	private function simpleEncoding(mixed $encoding): array {
		$map = [];
		if ($encoding instanceof PdfDict) {
			$differences = $this->doc->resolve($encoding->get('Differences'));
			$code = 0;
			foreach (is_array($differences) ? $differences : [] as $entry) {
				if ($entry instanceof PdfRaw) {
					$code = $entry->int();
				} elseif ($entry instanceof PdfName) {
					$glyph = $entry->name;
					if (isset(self::GLYPHS[$glyph])) {
						$map[$code] = self::GLYPHS[$glyph];
					} elseif (preg_match('/^uni([0-9A-Fa-f]{4})$/', $glyph, $m) === 1) {
						$map[$code] = (string)mb_chr((int)hexdec($m[1]), 'UTF-8');
					} elseif (strlen($glyph) === 1) {
						$map[$code] = $glyph;
					}
					$code++;
				}
			}
		}
		return $map;
	}

	private function decode(string $bytes, ?array $font): string {
		if ($font === null) {
			return self::winAnsi($bytes);
		}
		$width = max(1, (int)$font['bytes']);
		$out = '';
		$length = strlen($bytes);
		for ($i = 0; $i < $length; $i += $width) {
			$code = substr($bytes, $i, $width);
			if (isset($font['map'][$code])) {
				$out .= $font['map'][$code];
			} elseif ($width === 1 && isset($font['simple'][ord($code)])) {
				$out .= $font['simple'][ord($code)];
			} elseif ($width === 1 && $font['map'] === []) {
				$out .= self::winAnsi($code);
			}
			// Ohne ToUnicode bei 2-Byte-Schriften (CID) ist der Text nicht verlässlich lesbar und wird ausgelassen.
		}
		return $out;
	}

	private static function winAnsi(string $bytes): string {
		$converted = @mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
		$converted = is_string($converted) ? $converted : '';
		return (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $converted);
	}

	/**
	 * ToUnicode-CMap: bfchar/bfrange → Zuordnung Code (Bytes) → UTF-8; dazu die Codelänge aus codespacerange.
	 * @return array{0:array<string,string>,1:int}
	 */
	public static function parseCmap(string $cmap): array {
		$map = [];
		$bytes = 0;
		if (preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cmap, $m) === 1) {
			$bytes = intdiv(strlen($m[1]) + 1, 2);
		}
		$utf16 = static function (string $hex): string {
			$hex = (string)preg_replace('/[^0-9A-Fa-f]/', '', $hex);
			if ($hex === '' || strlen($hex) % 4 !== 0) {
				$hex = str_pad($hex, (int)(ceil(strlen($hex) / 4) * 4), '0', STR_PAD_LEFT);
			}
			$converted = @mb_convert_encoding((string)hex2bin($hex), 'UTF-8', 'UTF-16BE');
			return is_string($converted) ? $converted : '';
		};
		if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks) > 0) {
			foreach ($blocks[1] as $block) {
				if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $block, $pairs, PREG_SET_ORDER) > 0) {
					foreach ($pairs as $pair) {
						$map[(string)hex2bin(strlen($pair[1]) % 2 ? '0' . $pair[1] : $pair[1])] = $utf16($pair[2]);
					}
				}
			}
		}
		if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks) > 0) {
			foreach ($blocks[1] as $block) {
				if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])/', $block, $ranges, PREG_SET_ORDER) > 0) {
					foreach ($ranges as $range) {
						$width = strlen($range[1]);
						$low = (int)hexdec($range[1]);
						$high = (int)hexdec($range[2]);
						if ($high < $low || $high - $low > 65535) {
							continue;
						}
						if ($range[3][0] === '[') {
							preg_match_all('/<([0-9A-Fa-f]*)>/', $range[3], $targets);
							foreach ($targets[1] as $offset => $target) {
								if ($low + $offset > $high) {
									break;
								}
								$map[(string)hex2bin(str_pad(dechex($low + $offset), $width, '0', STR_PAD_LEFT))] = $utf16($target);
							}
							continue;
						}
						$start = trim($range[3], '<>');
						$base = (int)hexdec($start !== '' ? $start : '0');
						$targetWidth = max(4, strlen($start));
						for ($code = $low; $code <= $high; $code++) {
							$map[(string)hex2bin(str_pad(dechex($code), $width, '0', STR_PAD_LEFT))] = $utf16(str_pad(dechex($base + $code - $low), $targetWidth, '0', STR_PAD_LEFT));
						}
					}
				}
			}
		}
		return [$map, $bytes];
	}

	/** Werte eines Namensbaums (EmbeddedFiles), begrenzt in Tiefe und Anzahl. @return list<mixed> */
	private static function nameTreeValues(PdfDocument $doc, mixed $node, int $depth): array {
		$node = $doc->resolve($node);
		if (!$node instanceof PdfDict || $depth > 8) {
			return [];
		}
		$values = [];
		$names = $doc->resolve($node->get('Names'));
		if (is_array($names)) {
			for ($i = 1; $i < count($names); $i += 2) {
				$values[] = $names[$i];
			}
		}
		$kids = $doc->resolve($node->get('Kids'));
		foreach (is_array($kids) ? $kids : [] as $kid) {
			array_push($values, ...self::nameTreeValues($doc, $kid, $depth + 1));
			if (count($values) > 50) {
				break;
			}
		}
		return $values;
	}
}
