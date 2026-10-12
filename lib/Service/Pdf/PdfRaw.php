<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service\Pdf;

/** Numbers, booleans and null, kept exactly as written in the source file. */
final class PdfRaw {
	public function __construct(public readonly string $token) {}

	public function int(): int {
		return (int)$this->token;
	}
}
