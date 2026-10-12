<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service\Pdf;

/**
 * Read-only access to an existing PDF: cross-reference sections (tables and
 * streams, including /Prev chains and hybrid files), object streams and
 * FlateDecode streams. Used to prepare an incremental update without touching
 * the existing bytes.
 */
final class PdfDocument {
	/** @var array<int,array{type:int,offset:int,gen:int,stream?:int,index?:int}> newest entry per object number */
	private array $xref = [];
	private PdfDict $trailer;
	private int $startXref;
	private bool $usesXrefStream;
	/** @var array<int,array<int,string>> decoded object streams: stream number → object number → source */
	private array $objectStreams = [];
	/** @var array<int,mixed> */
	private array $cache = [];

	public function __construct(private readonly string $data) {
		if (!str_starts_with($data, '%PDF-')) throw new PdfException('Die Datei ist kein PDF.');
		$this->startXref = $this->findStartXref();
		$this->trailer = new PdfDict();
		$this->usesXrefStream = false;
		$visited = [];
		$offset = $this->startXref;
		$first = true;
		while ($offset !== null) {
			if (isset($visited[$offset]) || count($visited) > 512) throw new PdfException('Die Querverweistabelle des PDFs ist zyklisch.');
			$visited[$offset] = true;
			[$trailer, $isStream] = $this->readXrefSection($offset);
			if ($first) { $this->usesXrefStream = $isStream; $first = false; }
			foreach ($trailer->entries as $key => $value) if (!$this->trailer->has($key) && !in_array($key, ['Prev', 'XRefStm', 'Type', 'W', 'Index', 'Filter', 'DecodeParms', 'Length'], true)) $this->trailer->set($key, $value);
			// Hybrid-reference files: the stream section completes the table of the same revision.
			$hybrid = $trailer->get('XRefStm');
			if ($hybrid instanceof PdfRaw && !isset($visited[$hybrid->int()])) { $visited[$hybrid->int()] = true; $this->readXrefSection($hybrid->int()); }
			$prev = $trailer->get('Prev');
			$offset = $prev instanceof PdfRaw ? $prev->int() : null;
		}
		if (!$this->trailer->get('Root') instanceof PdfRef) throw new PdfException('Das PDF hat keinen Katalog (/Root).');
	}

	public function data(): string { return $this->data; }
	public function trailer(): PdfDict { return $this->trailer; }
	public function startXref(): int { return $this->startXref; }
	public function usesXrefStream(): bool { return $this->usesXrefStream; }
	public function isEncrypted(): bool { return $this->trailer->has('Encrypt'); }

	/** Highest object number + 1 over all revisions. */
	public function size(): int {
		$size = $this->trailer->get('Size');
		$max = $this->xref === [] ? 0 : max(array_keys($this->xref)) + 1;
		return max($size instanceof PdfRaw ? $size->int() : 0, $max);
	}

	public function headerVersion(): string {
		return preg_match('/^%PDF-(\d\.\d)/', $this->data, $m) === 1 ? $m[1] : '1.4';
	}

	public function catalog(): PdfDict {
		$root = $this->resolve($this->trailer->get('Root'));
		if (!$root instanceof PdfDict) throw new PdfException('Der PDF-Katalog ist ungültig.');
		return $root;
	}

	/** Follows indirect references (bounded). */
	public function resolve(mixed $value): mixed {
		for ($i = 0; $i < 16 && $value instanceof PdfRef; $i++) $value = $this->object($value->num);
		return $value;
	}

	/** Object value (dictionary of a stream object: see streamOf()). */
	public function object(int $num): mixed {
		if (array_key_exists($num, $this->cache)) return $this->cache[$num];
		$entry = $this->xref[$num] ?? null;
		if ($entry === null || $entry['type'] === 0) return $this->cache[$num] = null;
		if ($entry['type'] === 2) {
			$source = $this->objectStreamMember((int)$entry['stream'], $num);
			$pos = 0;
			return $this->cache[$num] = PdfSyntax::parseValue($source, $pos);
		}
		[$value] = $this->readIndirect($entry['offset'], $num);
		return $this->cache[$num] = $value;
	}

	/** Decoded stream data of an indirect stream object. */
	public function streamOf(PdfRef $ref): ?string {
		$entry = $this->xref[$ref->num] ?? null;
		if ($entry === null || $entry['type'] !== 1) return null;
		[$dict, $stream] = $this->readIndirect($entry['offset'], $ref->num);
		if (!$dict instanceof PdfDict || $stream === null) return null;
		return $this->decode($dict, $stream);
	}

	private function findStartXref(): int {
		$tail = substr($this->data, -2048);
		$pos = strrpos($tail, 'startxref');
		if ($pos === false) throw new PdfException('Im PDF fehlt „startxref“.');
		if (preg_match('/startxref\s+(\d+)/', substr($tail, $pos), $m) !== 1) throw new PdfException('„startxref“ im PDF ist ungültig.');
		$offset = (int)$m[1];
		if ($offset <= 0 || $offset >= strlen($this->data)) throw new PdfException('„startxref“ zeigt außerhalb der Datei.');
		return $offset;
	}

	/** @return array{0:PdfDict,1:bool} trailer dictionary, true for a cross-reference stream */
	private function readXrefSection(int $offset): array {
		$pos = $offset;
		PdfSyntax::skipWhitespace($this->data, $pos);
		if (substr($this->data, $pos, 4) === 'xref') {
			$pos += 4;
			while (true) {
				PdfSyntax::skipWhitespace($this->data, $pos);
				if (substr($this->data, $pos, 7) === 'trailer') { $pos += 7; break; }
				if (preg_match('/\G(\d+)\s+(\d+)/', $this->data, $m, 0, $pos) !== 1) throw new PdfException('Querverweistabelle des PDFs ist ungültig.');
				$pos += strlen($m[0]);
				$start = (int)$m[1];
				$count = (int)$m[2];
				for ($i = 0; $i < $count; $i++) {
					PdfSyntax::skipWhitespace($this->data, $pos);
					if (preg_match('/\G(\d{1,10})\s+(\d{1,5})\s+([nf])/', $this->data, $e, 0, $pos) !== 1) throw new PdfException('Eintrag der Querverweistabelle ist ungültig.');
					$pos += strlen($e[0]);
					$num = $start + $i;
					if (!isset($this->xref[$num])) $this->xref[$num] = ['type' => $e[3] === 'n' ? 1 : 0, 'offset' => (int)$e[1], 'gen' => (int)$e[2]];
				}
			}
			PdfSyntax::skipWhitespace($this->data, $pos);
			$trailer = PdfSyntax::parseValue($this->data, $pos);
			if (!$trailer instanceof PdfDict) throw new PdfException('Trailer des PDFs ist ungültig.');
			return [$trailer, false];
		}
		[$dict, $stream] = $this->readIndirect($offset, null);
		if (!$dict instanceof PdfDict || $stream === null || !($dict->get('Type') instanceof PdfName && $dict->get('Type')->name === 'XRef')) throw new PdfException('Querverweis des PDFs wurde nicht gefunden.');
		$raw = $this->decode($dict, $stream);
		$widths = array_map(fn($w): int => $this->resolve($w) instanceof PdfRaw ? $this->resolve($w)->int() : 0, (array)$dict->get('W'));
		if (count($widths) !== 3) throw new PdfException('Querverweis-Stream des PDFs ist ungültig (/W).');
		$size = $dict->get('Size') instanceof PdfRaw ? $dict->get('Size')->int() : 0;
		$index = $dict->get('Index');
		$ranges = is_array($index) ? array_map(static fn($v): int => $v instanceof PdfRaw ? $v->int() : 0, $index) : [0, $size];
		$rowLength = array_sum($widths);
		if ($rowLength <= 0) throw new PdfException('Querverweis-Stream des PDFs ist ungültig.');
		$cursor = 0;
		for ($r = 0; $r + 1 < count($ranges); $r += 2) {
			for ($i = 0; $i < $ranges[$r + 1]; $i++) {
				if ($cursor + $rowLength > strlen($raw)) break 2;
				$fields = [];
				$fieldPos = $cursor;
				foreach ($widths as $width) {
					$value = 0;
					for ($b = 0; $b < $width; $b++) $value = ($value << 8) | ord($raw[$fieldPos++]);
					$fields[] = $value;
				}
				$cursor += $rowLength;
				$type = $widths[0] === 0 ? 1 : $fields[0];
				$num = $ranges[$r] + $i;
				if (isset($this->xref[$num])) continue;
				if ($type === 1) $this->xref[$num] = ['type' => 1, 'offset' => $fields[1], 'gen' => $fields[2]];
				elseif ($type === 2) $this->xref[$num] = ['type' => 2, 'offset' => 0, 'gen' => 0, 'stream' => $fields[1], 'index' => $fields[2]];
				else $this->xref[$num] = ['type' => 0, 'offset' => 0, 'gen' => 0];
			}
		}
		return [$dict, true];
	}

	/** @return array{0:mixed,1:?string} value and raw (still encoded) stream bytes */
	private function readIndirect(int $offset, ?int $expected): array {
		if ($offset <= 0 || $offset >= strlen($this->data)) throw new PdfException('Objektverweis zeigt außerhalb des PDFs.');
		if (preg_match('/\G\s*(\d+)\s+(\d+)\s+obj/', $this->data, $m, 0, $offset) !== 1) throw new PdfException('PDF-Objekt an Position ' . $offset . ' wurde nicht gefunden.');
		if ($expected !== null && (int)$m[1] !== $expected) throw new PdfException('Querverweis des PDFs zeigt auf ein falsches Objekt.');
		$pos = $offset + strlen($m[0]);
		$value = PdfSyntax::parseValue($this->data, $pos);
		PdfSyntax::skipWhitespace($this->data, $pos);
		if (!$value instanceof PdfDict || substr($this->data, $pos, 6) !== 'stream') return [$value, null];
		$pos += 6;
		if (($this->data[$pos] ?? '') === "\r") $pos++;
		if (($this->data[$pos] ?? '') === "\n") $pos++;
		$lengthValue = $value->get('Length');
		$length = $lengthValue instanceof PdfRef ? $this->resolve($lengthValue) : $lengthValue;
		$length = $length instanceof PdfRaw ? $length->int() : -1;
		if ($length < 0 || substr($this->data, $pos + $length, 30) === '' || !preg_match('/^\s*endstream/', substr($this->data, $pos + $length, 30))) {
			$end = strpos($this->data, 'endstream', $pos);
			if ($end === false) throw new PdfException('Stream im PDF ist nicht abgeschlossen.');
			$length = $end - $pos;
			while ($length > 0 && in_array($this->data[$pos + $length - 1], ["\r", "\n"], true)) $length--;
		}
		return [$value, substr($this->data, $pos, $length)];
	}

	private function objectStreamMember(int $streamNum, int $num): string {
		if (!isset($this->objectStreams[$streamNum])) {
			$entry = $this->xref[$streamNum] ?? null;
			if ($entry === null || $entry['type'] !== 1) throw new PdfException('Objektstream ' . $streamNum . ' des PDFs fehlt.');
			[$dict, $stream] = $this->readIndirect($entry['offset'], $streamNum);
			if (!$dict instanceof PdfDict || $stream === null) throw new PdfException('Objektstream des PDFs ist ungültig.');
			$raw = $this->decode($dict, $stream);
			$count = $dict->get('N') instanceof PdfRaw ? $dict->get('N')->int() : 0;
			$first = $dict->get('First') instanceof PdfRaw ? $dict->get('First')->int() : 0;
			preg_match_all('/(\d+)\s+(\d+)/', substr($raw, 0, $first), $pairs, PREG_SET_ORDER);
			$pairs = array_slice($pairs, 0, $count);
			$members = [];
			foreach ($pairs as $i => $pair) {
				$start = $first + (int)$pair[2];
				$end = isset($pairs[$i + 1]) ? $first + (int)$pairs[$i + 1][2] : strlen($raw);
				$members[(int)$pair[1]] = substr($raw, $start, max(0, $end - $start));
			}
			$this->objectStreams[$streamNum] = $members;
		}
		if (!isset($this->objectStreams[$streamNum][$num])) throw new PdfException('Objekt ' . $num . ' fehlt im Objektstream.');
		return $this->objectStreams[$streamNum][$num];
	}

	private function decode(PdfDict $dict, string $stream): string {
		$filters = $this->resolve($dict->get('Filter'));
		$params = $this->resolve($dict->get('DecodeParms'));
		$filters = $filters === null ? [] : (is_array($filters) ? $filters : [$filters]);
		$params = is_array($params) ? $params : [$params];
		foreach (array_values($filters) as $i => $filter) {
			$name = $filter instanceof PdfName ? $filter->name : '';
			if ($name !== 'FlateDecode' && $name !== 'Fl') throw new PdfException('PDF-Filter „' . $name . '“ wird nicht unterstützt.');
			$decoded = @gzuncompress($stream);
			if ($decoded === false) $decoded = @gzinflate($stream);
			if ($decoded === false) $decoded = @gzinflate(substr($stream, 2));
			if ($decoded === false) throw new PdfException('Komprimierter Stream im PDF ist beschädigt.');
			$stream = $this->unpredict($decoded, $this->resolve($params[$i] ?? null));
		}
		return $stream;
	}

	private function unpredict(string $data, mixed $params): string {
		if (!$params instanceof PdfDict) return $data;
		$predictor = $params->get('Predictor') instanceof PdfRaw ? $params->get('Predictor')->int() : 1;
		if ($predictor < 10) {
			if ($predictor === 1) return $data;
			throw new PdfException('TIFF-Prädiktor im PDF wird nicht unterstützt.');
		}
		$columns = $params->get('Columns') instanceof PdfRaw ? $params->get('Columns')->int() : 1;
		$colors = $params->get('Colors') instanceof PdfRaw ? $params->get('Colors')->int() : 1;
		$bits = $params->get('BitsPerComponent') instanceof PdfRaw ? $params->get('BitsPerComponent')->int() : 8;
		$bpp = max(1, intdiv($colors * $bits, 8));
		$rowLength = intdiv($columns * $colors * $bits + 7, 8);
		$out = '';
		$previous = str_repeat("\0", $rowLength);
		for ($pos = 0; $pos + 1 + $rowLength <= strlen($data); $pos += 1 + $rowLength) {
			$type = ord($data[$pos]);
			$row = substr($data, $pos + 1, $rowLength);
			$decoded = '';
			for ($i = 0; $i < $rowLength; $i++) {
				$left = $i >= $bpp ? ord($decoded[$i - $bpp]) : 0;
				$up = ord($previous[$i]);
				$upLeft = $i >= $bpp ? ord($previous[$i - $bpp]) : 0;
				$x = ord($row[$i]);
				$value = match ($type) {
					0 => $x,
					1 => $x + $left,
					2 => $x + $up,
					3 => $x + intdiv($left + $up, 2),
					4 => $x + self::paeth($left, $up, $upLeft),
					default => throw new PdfException('Ungültiger PNG-Prädiktor im PDF.'),
				};
				$decoded .= chr($value & 0xFF);
			}
			$out .= $decoded;
			$previous = $decoded;
		}
		return $out;
	}

	private static function paeth(int $a, int $b, int $c): int {
		$p = $a + $b - $c;
		$pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
		if ($pa <= $pb && $pa <= $pc) return $a;
		return $pb <= $pc ? $b : $c;
	}
}
