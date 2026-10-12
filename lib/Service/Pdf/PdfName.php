<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service\Pdf;

/** PDF name object (without the leading slash, #xx already decoded). */
final class PdfName {
	public function __construct(public readonly string $name) {}
}
