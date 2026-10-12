<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service\Pdf;

/** Ordered PDF dictionary; keys are names without slash. */
final class PdfDict {
	/** @param array<string,mixed> $entries */
	public function __construct(public array $entries = []) {}

	public function get(string $key): mixed {
		return $this->entries[$key] ?? null;
	}

	public function has(string $key): bool {
		return array_key_exists($key, $this->entries);
	}

	public function set(string $key, mixed $value): self {
		$this->entries[$key] = $value;
		return $this;
	}

	public function remove(string $key): self {
		unset($this->entries[$key]);
		return $this;
	}
}
