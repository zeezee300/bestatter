<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\Service\Pdf\PdfDict;
use OCA\Bestatter\Service\Pdf\PdfDocument;
use OCA\Bestatter\Service\Pdf\PdfException;
use OCA\Bestatter\Service\Pdf\PdfName;
use OCA\Bestatter\Service\Pdf\PdfRaw;
use OCA\Bestatter\Service\Pdf\PdfRef;
use OCA\Bestatter\Service\Pdf\PdfString;
use OCA\Bestatter\Service\Pdf\PdfSyntax;
use OCA\Bestatter\Service\Pdf\SrgbIccProfile;

/**
 * Embeds the EN 16931 CII data record as „factur-x.xml“ into an existing
 * invoice PDF (ZUGFeRD 2.x / Factur-X hybrid invoice, PDF/A-3 attachment
 * rules). The change is written as an incremental update: the bytes of the
 * rendered invoice stay untouched, only the attachment, XMP metadata, output
 * intent and catalog entries are appended.
 *
 * PDF/A-3 is only declared when the source PDF already declares PDF/A (any
 * part): conformance of the visible content (embedded fonts, colour, no
 * transparency …) is decided by the converter and cannot be repaired here.
 */
class FacturXPdfService {
	public const ATTACHMENT = 'factur-x.xml';
	public const FX_NAMESPACE = 'urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#';

	/**
	 * @param array{title?:string,subject?:string,conformanceLevel?:string,documentType?:string,now?:\DateTimeImmutable} $meta
	 * @return array{pdf:string,pdfaPart:?int,pdfaConformance:?string,sourcePdfa:?array{part:int,conformance:string},attachment:string,xmlSha256:string,incremental:bool}
	 */
	public function embed(string $pdf, string $xml, array $meta = []): array {
		$doc = new PdfDocument($pdf);
		if ($doc->isEncrypted()) throw new PdfException('Verschlüsselte PDF-Dateien können nicht als ZUGFeRD-Rechnung ausgegeben werden.');
		$now = $meta['now'] ?? new \DateTimeImmutable('now');
		$catalog = new PdfDict($doc->catalog()->entries);
		$rootRef = $doc->trailer()->get('Root');
		if (!$rootRef instanceof PdfRef) throw new PdfException('Das PDF hat keinen Katalog (/Root).');
		$source = $this->pdfaIdentification($this->metadataOf($doc, $catalog));
		$claimPdfa = $source !== null;
		$conformance = $source !== null ? strtoupper($source['conformance']) : null;

		$next = $doc->size();
		$objects = [];
		$reserve = function () use (&$next): int { return $next++; };

		// Attachment: embedded file stream + file specification (PDF/A-3, 6.8).
		$compressed = (string)gzcompress($xml, 9);
		$date = new PdfString($this->pdfDate($now));
		$fileNum = $reserve();
		$objects[$fileNum] = PdfSyntax::serialize(new PdfDict([
			'Type' => new PdfName('EmbeddedFile'),
			'Subtype' => new PdfName('text/xml'),
			'Filter' => new PdfName('FlateDecode'),
			'Length' => strlen($compressed),
			'Params' => new PdfDict(['Size' => strlen($xml), 'CheckSum' => new PdfString(md5($xml, true), true), 'CreationDate' => $date, 'ModDate' => $date]),
		])) . "\nstream\n" . $compressed . "\nendstream";
		$specNum = $reserve();
		$objects[$specNum] = PdfSyntax::serialize(new PdfDict([
			'Type' => new PdfName('Filespec'),
			'F' => new PdfString(self::ATTACHMENT),
			'UF' => new PdfString(self::ATTACHMENT),
			'Desc' => new PdfString('Factur-X/ZUGFeRD invoice data (EN 16931)'),
			'AFRelationship' => new PdfName('Alternative'),
			'EF' => new PdfDict(['F' => new PdfRef($fileNum), 'UF' => new PdfRef($fileNum)]),
		]));
		$specRef = new PdfRef($specNum);

		// Name tree of embedded files, keeping other attachments.
		$pairs = [];
		$names = $doc->resolve($catalog->get('Names'));
		$names = $names instanceof PdfDict ? new PdfDict($names->entries) : new PdfDict();
		foreach ($this->nameTreeLeaves($doc, $names->get('EmbeddedFiles')) as [$key, $value]) {
			if (strcasecmp($key->text(), self::ATTACHMENT) !== 0) $pairs[] = [$key, $value];
		}
		$pairs[] = [new PdfString(self::ATTACHMENT), $specRef];
		usort($pairs, static fn(array $a, array $b): int => strcmp($a[0]->bytes, $b[0]->bytes));
		$treeNum = $reserve();
		$objects[$treeNum] = PdfSyntax::serialize(new PdfDict(['Names' => array_merge(...array_map(static fn(array $p): array => [$p[0], $p[1]], $pairs))]));
		$names->set('EmbeddedFiles', new PdfRef($treeNum));
		$catalog->set('Names', $names);

		// Associated files of the document (replace an earlier factur-x.xml).
		$associated = [];
		$existingAf = $doc->resolve($catalog->get('AF'));
		foreach (is_array($existingAf) ? $existingAf : [] as $entry) {
			$spec = $doc->resolve($entry);
			$name = $spec instanceof PdfDict ? ($doc->resolve($spec->get('UF')) ?? $doc->resolve($spec->get('F'))) : null;
			if ($name instanceof PdfString && strcasecmp($name->text(), self::ATTACHMENT) === 0) continue;
			$associated[] = $entry;
		}
		$associated[] = $specRef;
		$catalog->set('AF', $associated);

		// Document information dictionary and matching XMP metadata (PDF/A requires equivalence).
		$oldInfo = $doc->resolve($doc->trailer()->get('Info'));
		$oldInfo = $oldInfo instanceof PdfDict ? $oldInfo : new PdfDict();
		$text = function (string $key) use ($doc, $oldInfo): string {
			$value = $doc->resolve($oldInfo->get($key));
			return $value instanceof PdfString ? trim($value->text()) : '';
		};
		$info = [
			'Title' => trim((string)($meta['title'] ?? '')) ?: $text('Title'),
			'Author' => $text('Author'),
			'Subject' => trim((string)($meta['subject'] ?? '')) ?: $text('Subject'),
			'Keywords' => $text('Keywords'),
			'Creator' => $text('Creator'),
			'Producer' => $text('Producer'),
		];
		$created = $this->parsePdfDate($text('CreationDate')) ?? $now;
		$infoDict = new PdfDict();
		foreach ($info as $key => $value) if ($value !== '') $infoDict->set($key, PdfString::fromText($value));
		$infoDict->set('CreationDate', new PdfString($this->pdfDate($created)));
		$infoDict->set('ModDate', new PdfString($this->pdfDate($now)));
		$infoNum = $reserve();
		$objects[$infoNum] = PdfSyntax::serialize($infoDict);

		$xmp = $this->xmp($info, $created, $now, $claimPdfa ? $conformance : null, (string)($meta['documentType'] ?? 'INVOICE'), (string)($meta['conformanceLevel'] ?? 'EN 16931'));
		$metaNum = $reserve();
		$objects[$metaNum] = PdfSyntax::serialize(new PdfDict(['Type' => new PdfName('Metadata'), 'Subtype' => new PdfName('XML'), 'Length' => strlen($xmp)])) . "\nstream\n" . $xmp . "\nendstream";
		$catalog->set('Metadata', new PdfRef($metaNum));

		if ($claimPdfa && !$this->hasPdfaOutputIntent($doc, $catalog)) {
			$icc = (string)gzcompress(SrgbIccProfile::bytes(), 9);
			$iccNum = $reserve();
			$objects[$iccNum] = PdfSyntax::serialize(new PdfDict(['N' => 3, 'Filter' => new PdfName('FlateDecode'), 'Length' => strlen($icc)])) . "\nstream\n" . $icc . "\nendstream";
			$intents = $doc->resolve($catalog->get('OutputIntents'));
			$intents = is_array($intents) ? $intents : [];
			$intents[] = new PdfDict([
				'Type' => new PdfName('OutputIntent'), 'S' => new PdfName('GTS_PDFA1'),
				'OutputConditionIdentifier' => new PdfString('sRGB IEC61966-2.1'), 'Info' => new PdfString('sRGB IEC61966-2.1'),
				'RegistryName' => new PdfString('http://www.color.org'), 'DestOutputProfile' => new PdfRef($iccNum),
			]);
			$catalog->set('OutputIntents', $intents);
		}
		$version = $doc->resolve($catalog->get('Version'));
		$effective = max((float)$doc->headerVersion(), $version instanceof PdfName ? (float)$version->name : 0.0);
		if ($effective < 1.7) $catalog->set('Version', new PdfName('1.7'));
		$objects[$rootRef->num] = PdfSyntax::serialize($catalog);
		$generations = [$rootRef->num => $rootRef->gen];

		$out = $this->appendUpdate($doc, $pdf, $objects, $generations, $next, $rootRef, new PdfRef($infoNum));
		return [
			'pdf' => $out,
			'pdfaPart' => $claimPdfa ? 3 : null,
			'pdfaConformance' => $claimPdfa ? $conformance : null,
			'sourcePdfa' => $source,
			'attachment' => self::ATTACHMENT,
			'xmlSha256' => hash('sha256', $xml),
			'incremental' => true,
		];
	}

	/**
	 * Reads back what a receiving system would see: embedded XML, file
	 * specification, XMP identification and ZUGFeRD metadata.
	 *
	 * @return array{xml:?string,mimeType:string,afRelationship:string,inCatalogAf:bool,inNameTree:bool,pdfaPart:?int,pdfaConformance:?string,fx:array<string,string>,infoMatchesXmp:bool,problems:list<string>}
	 */
	public function inspect(string $pdf): array {
		$doc = new PdfDocument($pdf);
		$catalog = $doc->catalog();
		$problems = [];
		$spec = null;
		$inNameTree = false;
		$names = $doc->resolve($catalog->get('Names'));
		foreach ($this->nameTreeLeaves($doc, $names instanceof PdfDict ? $names->get('EmbeddedFiles') : null) as [$key, $value]) {
			if (strcasecmp($key->text(), self::ATTACHMENT) === 0) { $spec = $doc->resolve($value); $inNameTree = true; }
		}
		$inAf = false;
		$af = $doc->resolve($catalog->get('AF'));
		foreach (is_array($af) ? $af : [] as $entry) {
			$candidate = $doc->resolve($entry);
			$name = $candidate instanceof PdfDict ? $doc->resolve($candidate->get('UF') ?? $candidate->get('F')) : null;
			if ($name instanceof PdfString && strcasecmp($name->text(), self::ATTACHMENT) === 0) { $inAf = true; $spec ??= $candidate; }
		}
		$xml = null; $mime = ''; $relationship = '';
		if ($spec instanceof PdfDict) {
			$relationship = $spec->get('AFRelationship') instanceof PdfName ? $spec->get('AFRelationship')->name : '';
			$ef = $doc->resolve($spec->get('EF'));
			$fileRef = $ef instanceof PdfDict ? ($ef->get('UF') ?? $ef->get('F')) : null;
			if ($fileRef instanceof PdfRef) {
				$xml = $doc->streamOf($fileRef);
				$fileDict = $doc->resolve($fileRef);
				$mime = $fileDict instanceof PdfDict && $fileDict->get('Subtype') instanceof PdfName ? $fileDict->get('Subtype')->name : '';
				$params = $fileDict instanceof PdfDict ? $doc->resolve($fileDict->get('Params')) : null;
				if (!$params instanceof PdfDict || !$params->has('ModDate')) $problems[] = 'Änderungsdatum der Dateianlage fehlt.';
			}
		}
		if ($xml === null) $problems[] = 'Die Dateianlage factur-x.xml fehlt.';
		if (!$inAf) $problems[] = 'factur-x.xml ist nicht als zugehörige Datei (/AF) des Dokuments eingetragen.';
		if (!$inNameTree) $problems[] = 'factur-x.xml fehlt im Verzeichnis der Dateianlagen.';
		if ($xml !== null && !in_array($relationship, ['Alternative', 'Data', 'Source'], true)) $problems[] = 'AFRelationship der Dateianlage ist ungültig.';
		if ($xml !== null && $mime !== 'text/xml') $problems[] = 'MIME-Typ der Dateianlage ist nicht text/xml.';
		$metadata = $this->metadataOf($doc, $catalog);
		$identification = $this->pdfaIdentification($metadata);
		$fx = [];
		foreach (['DocumentType', 'DocumentFileName', 'Version', 'ConformanceLevel'] as $property) {
			if ($metadata !== null && preg_match('/<fx:' . $property . '>([^<]*)<\/fx:' . $property . '>|fx:' . $property . '="([^"]*)"/', $metadata, $m) === 1) $fx[$property] = html_entity_decode($m[1] !== '' ? $m[1] : ($m[2] ?? ''), ENT_XML1);
		}
		if (($fx['DocumentFileName'] ?? '') !== self::ATTACHMENT) $problems[] = 'ZUGFeRD-Metadaten (fx:DocumentFileName) fehlen.';
		$infoMatches = true;
		$info = $doc->resolve($doc->trailer()->get('Info'));
		if ($info instanceof PdfDict && $metadata !== null) {
			$producer = $doc->resolve($info->get('Producer'));
			if ($producer instanceof PdfString && !str_contains($metadata, htmlspecialchars($producer->text(), ENT_XML1))) $infoMatches = false;
			$modified = $doc->resolve($info->get('ModDate'));
			$parsed = $modified instanceof PdfString ? $this->parsePdfDate($modified->text()) : null;
			if ($parsed !== null && !str_contains($metadata, '<xmp:ModifyDate>' . $parsed->format('Y-m-d\TH:i:sP') . '</xmp:ModifyDate>')) $infoMatches = false;
		}
		if (!$infoMatches) $problems[] = 'Dokumentinformationen und XMP-Metadaten stimmen nicht überein.';
		return [
			'xml' => $xml, 'mimeType' => $mime, 'afRelationship' => $relationship, 'inCatalogAf' => $inAf, 'inNameTree' => $inNameTree,
			'pdfaPart' => $identification['part'] ?? null, 'pdfaConformance' => $identification['conformance'] ?? null,
			'fx' => $fx, 'infoMatchesXmp' => $infoMatches, 'problems' => $problems,
		];
	}

	/** PDF/A identification of an existing PDF (null if none is declared). */
	public function sourcePdfa(string $pdf): ?array {
		try {
			$doc = new PdfDocument($pdf);
			return $this->pdfaIdentification($this->metadataOf($doc, $doc->catalog()));
		} catch (PdfException) {
			return null;
		}
	}

	/** @param array<int,string> $objects @param array<int,int> $generations */
	private function appendUpdate(PdfDocument $doc, string $pdf, array $objects, array $generations, int $next, PdfRef $root, PdfRef $info): string {
		$out = $pdf;
		if (!str_ends_with($out, "\n") && !str_ends_with($out, "\r")) $out .= "\n";
		$offsets = [];
		ksort($objects);
		foreach ($objects as $num => $body) {
			$offsets[$num] = strlen($out);
			$out .= $num . ' ' . ($generations[$num] ?? 0) . " obj\n" . $body . "\nendobj\n";
		}
		$oldId = $doc->resolve($doc->trailer()->get('ID'));
		$firstId = is_array($oldId) && ($oldId[0] ?? null) instanceof PdfString ? $oldId[0] : new PdfString(md5($pdf, true), true);
		$ids = [new PdfString($firstId->bytes, true), new PdfString(md5($out . microtime(), true), true)];
		$trailer = new PdfDict(['Size' => $next, 'Root' => $root, 'Info' => $info, 'ID' => $ids, 'Prev' => $doc->startXref()]);

		if ($doc->usesXrefStream()) {
			$xrefNum = $next;
			$offsets[$xrefNum] = strlen($out);
			$generations[$xrefNum] = 0;
			$trailer->set('Size', $next + 1);
			$rows = '';
			$index = [];
			foreach ($this->ranges(array_keys($offsets)) as [$start, $count]) {
				$index[] = $start; $index[] = $count;
				for ($num = $start; $num < $start + $count; $num++) $rows .= chr(1) . pack('N', $offsets[$num]) . pack('n', $generations[$num] ?? 0);
			}
			$dict = new PdfDict(['Type' => new PdfName('XRef')] + $trailer->entries + ['W' => [1, 4, 2], 'Index' => $index, 'Length' => strlen($rows)]);
			$out .= $xrefNum . " 0 obj\n" . PdfSyntax::serialize($dict) . "\nstream\n" . $rows . "\nendstream\nendobj\n";
			return $out . "startxref\n" . $offsets[$xrefNum] . "\n%%EOF\n";
		}
		$xrefOffset = strlen($out);
		$out .= "xref\n";
		foreach ($this->ranges(array_keys($offsets)) as [$start, $count]) {
			$out .= $start . ' ' . $count . "\n";
			for ($num = $start; $num < $start + $count; $num++) $out .= sprintf("%010d %05d n\r\n", $offsets[$num], $generations[$num] ?? 0);
		}
		$out .= "trailer\n" . PdfSyntax::serialize($trailer) . "\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
		return $out;
	}

	/** @param list<int> $numbers @return list<array{0:int,1:int}> */
	private function ranges(array $numbers): array {
		sort($numbers);
		$ranges = [];
		foreach ($numbers as $num) {
			$last = count($ranges) - 1;
			if ($last >= 0 && $ranges[$last][0] + $ranges[$last][1] === $num) $ranges[$last][1]++;
			else $ranges[] = [$num, 1];
		}
		return $ranges;
	}

	/** @return list<array{0:PdfString,1:mixed}> */
	private function nameTreeLeaves(PdfDocument $doc, mixed $node, int $depth = 0): array {
		$node = $doc->resolve($node);
		if (!$node instanceof PdfDict || $depth > 32) return [];
		$leaves = [];
		$names = $doc->resolve($node->get('Names'));
		if (is_array($names)) {
			for ($i = 0; $i + 1 < count($names); $i += 2) {
				$key = $doc->resolve($names[$i]);
				if ($key instanceof PdfString) $leaves[] = [$key, $names[$i + 1]];
			}
		}
		$kids = $doc->resolve($node->get('Kids'));
		foreach (is_array($kids) ? $kids : [] as $kid) array_push($leaves, ...$this->nameTreeLeaves($doc, $kid, $depth + 1));
		return $leaves;
	}

	private function metadataOf(PdfDocument $doc, PdfDict $catalog): ?string {
		$ref = $catalog->get('Metadata');
		if (!$ref instanceof PdfRef) return null;
		try {
			return $doc->streamOf($ref);
		} catch (PdfException) {
			return null;
		}
	}

	/** @return ?array{part:int,conformance:string} */
	private function pdfaIdentification(?string $xmp): ?array {
		if ($xmp === null) return null;
		if (preg_match('/pdfaid:part\s*=\s*["\'](\d)["\']|<pdfaid:part>\s*(\d)\s*<\/pdfaid:part>/', $xmp, $part) !== 1) return null;
		$conformance = preg_match('/pdfaid:conformance\s*=\s*["\']([A-Za-z])["\']|<pdfaid:conformance>\s*([A-Za-z])\s*<\/pdfaid:conformance>/', $xmp, $c) === 1 ? ($c[1] !== '' ? $c[1] : ($c[2] ?? 'B')) : 'B';
		return ['part' => (int)($part[1] !== '' ? $part[1] : ($part[2] ?? 0)), 'conformance' => strtoupper($conformance)];
	}

	private function hasPdfaOutputIntent(PdfDocument $doc, PdfDict $catalog): bool {
		$intents = $doc->resolve($catalog->get('OutputIntents'));
		foreach (is_array($intents) ? $intents : [] as $intent) {
			$intent = $doc->resolve($intent);
			if ($intent instanceof PdfDict && $intent->get('S') instanceof PdfName && $intent->get('S')->name === 'GTS_PDFA1') return true;
		}
		return false;
	}

	/** @param array<string,string> $info */
	private function xmp(array $info, \DateTimeImmutable $created, \DateTimeImmutable $modified, ?string $pdfaConformance, string $documentType, string $conformanceLevel): string {
		$e = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
		$descriptions = [];
		if ($pdfaConformance !== null) {
			$descriptions[] = '<rdf:Description rdf:about="" xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/"><pdfaid:part>3</pdfaid:part><pdfaid:conformance>' . $e($pdfaConformance) . '</pdfaid:conformance></rdf:Description>';
		}
		$dc = '';
		if ($info['Title'] !== '') $dc .= '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . $e($info['Title']) . '</rdf:li></rdf:Alt></dc:title>';
		if ($info['Author'] !== '') $dc .= '<dc:creator><rdf:Seq><rdf:li>' . $e($info['Author']) . '</rdf:li></rdf:Seq></dc:creator>';
		if ($info['Subject'] !== '') $dc .= '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">' . $e($info['Subject']) . '</rdf:li></rdf:Alt></dc:description>';
		$descriptions[] = '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:format>application/pdf</dc:format>' . $dc . '</rdf:Description>';
		$xmpBasic = '<xmp:CreateDate>' . $created->format('Y-m-d\TH:i:sP') . '</xmp:CreateDate><xmp:ModifyDate>' . $modified->format('Y-m-d\TH:i:sP') . '</xmp:ModifyDate><xmp:MetadataDate>' . $modified->format('Y-m-d\TH:i:sP') . '</xmp:MetadataDate>';
		if ($info['Creator'] !== '') $xmpBasic .= '<xmp:CreatorTool>' . $e($info['Creator']) . '</xmp:CreatorTool>';
		$descriptions[] = '<rdf:Description rdf:about="" xmlns:xmp="http://ns.adobe.com/xap/1.0/">' . $xmpBasic . '</rdf:Description>';
		$pdf = ($info['Producer'] !== '' ? '<pdf:Producer>' . $e($info['Producer']) . '</pdf:Producer>' : '') . ($info['Keywords'] !== '' ? '<pdf:Keywords>' . $e($info['Keywords']) . '</pdf:Keywords>' : '');
		if ($pdf !== '') $descriptions[] = '<rdf:Description rdf:about="" xmlns:pdf="http://ns.adobe.com/pdf/1.3/">' . $pdf . '</rdf:Description>';
		$descriptions[] = '<rdf:Description rdf:about="" xmlns:fx="' . self::FX_NAMESPACE . '"><fx:DocumentType>' . $e($documentType) . '</fx:DocumentType><fx:DocumentFileName>' . self::ATTACHMENT . '</fx:DocumentFileName><fx:Version>1.0</fx:Version><fx:ConformanceLevel>' . $e($conformanceLevel) . '</fx:ConformanceLevel></rdf:Description>';
		$property = static fn(string $name, string $description): string => '<rdf:li rdf:parseType="Resource"><pdfaProperty:name>' . $name . '</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>' . $description . '</pdfaProperty:description></rdf:li>';
		$descriptions[] = '<rdf:Description rdf:about="" xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/" xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#" xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#">'
			. '<pdfaExtension:schemas><rdf:Bag><rdf:li rdf:parseType="Resource">'
			. '<pdfaSchema:schema>Factur-X PDFA Extension Schema</pdfaSchema:schema><pdfaSchema:namespaceURI>' . self::FX_NAMESPACE . '</pdfaSchema:namespaceURI><pdfaSchema:prefix>fx</pdfaSchema:prefix>'
			. '<pdfaSchema:property><rdf:Seq>'
			. $property('DocumentFileName', 'The name of the embedded XML document')
			. $property('DocumentType', 'The type of the hybrid document in capital letters, e.g. INVOICE or ORDER')
			. $property('Version', 'The actual version of the standard applying to the embedded XML document')
			. $property('ConformanceLevel', 'The conformance level of the embedded XML document')
			. '</rdf:Seq></pdfaSchema:property></rdf:li></rdf:Bag></pdfaExtension:schemas></rdf:Description>';
		return "<?xpacket begin=\"\xEF\xBB\xBF\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n"
			. '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">' . "\n"
			. implode("\n", $descriptions) . "\n"
			. '</rdf:RDF></x:xmpmeta>' . "\n" . str_repeat(str_repeat(' ', 99) . "\n", 20) . '<?xpacket end="w"?>';
	}

	private function pdfDate(\DateTimeImmutable $date): string {
		$offset = $date->format('P');
		return 'D:' . $date->format('YmdHis') . ($offset === '+00:00' ? 'Z' : substr($offset, 0, 3) . "'" . substr($offset, 4, 2) . "'");
	}

	private function parsePdfDate(string $value): ?\DateTimeImmutable {
		if (preg_match("/^(?:D:)?(\d{4})(\d{2})?(\d{2})?(\d{2})?(\d{2})?(\d{2})?(?:([Zz])|([+-])(\d{2})'?(\d{2})?'?)?/", trim($value), $m) !== 1) return null;
		$zone = ($m[8] ?? '') !== '' ? $m[8] . $m[9] . ':' . (($m[10] ?? '') !== '' ? $m[10] : '00') : '+00:00';
		$string = sprintf('%s-%s-%sT%s:%s:%s%s', $m[1], ($m[2] ?? '') ?: '01', ($m[3] ?? '') ?: '01', ($m[4] ?? '') ?: '00', ($m[5] ?? '') ?: '00', ($m[6] ?? '') ?: '00', $zone);
		$date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $string);
		return $date === false ? null : $date;
	}
}
