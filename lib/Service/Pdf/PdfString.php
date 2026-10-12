<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service\Pdf;

/** PDF string object; $bytes holds the decoded raw bytes. */
final class PdfString {
	public function __construct(public readonly string $bytes, public readonly bool $hex = false) {}

	/** Text string (UTF-16BE with BOM, UTF-8 with BOM or PDFDocEncoding) as UTF-8. */
	public function text(): string {
		if (str_starts_with($this->bytes, "\xFE\xFF")) return (string)mb_convert_encoding(substr($this->bytes, 2), 'UTF-8', 'UTF-16BE');
		if (str_starts_with($this->bytes, "\xEF\xBB\xBF")) return substr($this->bytes, 3);
		return (string)mb_convert_encoding($this->bytes, 'UTF-8', 'ISO-8859-1');
	}

	/** Creates a text string: plain bytes for ASCII, otherwise UTF-16BE with BOM. */
	public static function fromText(string $utf8): self {
		if (preg_match('/^[\x20-\x7E]*$/', $utf8) === 1) return new self($utf8);
		return new self("\xFE\xFF" . mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8'), true);
	}
}
