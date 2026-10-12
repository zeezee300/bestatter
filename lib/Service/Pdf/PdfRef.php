<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service\Pdf;

/** Indirect reference „n g R“. */
final class PdfRef {
	public function __construct(public readonly int $num, public readonly int $gen = 0) {}
}
