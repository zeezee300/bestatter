<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * 0.80.0 – Dokumentarten (OP-058): reine Regeln ohne Datenbank (Prüfung einer Definition, Erkennung von Fall
 * und Art eines eingehenden Paperless-Dokuments). Getestet in tests/document-kinds-0800-fixture.php.
 */
final class DocumentKindRules {
	public const BUNDLE_FILE = __DIR__ . '/../../resources/document-kinds.json';
	public const EXCHANGE_FORMAT = 'bestatter-document-kinds';
	public const EXCHANGE_VERSION = 1;
	/** Wirkung im Fall nach der Zuordnung. */
	public const EFFECTS = [
		'FILE' => 'Nur ablegen',
		'INCOMING_INVOICE' => 'Eingangsrechnung anlegen',
		'DEREGISTRATION' => 'Abmeldung bestätigen',
		'PAPER_CONTRACT' => 'Unterschriebener Vertrag (Prüfung)',
	];
	/** Art ohne Paperless-Dokumenttyp und ohne Treffer: Vorschlag im Belegeingang. */
	public const FALLBACK_KIND = 'SONSTIGES';
	/** Herkunft eines Fallvorschlags; nur FIELD erlaubt die automatische Ablage. */
	public const CASE_REASONS = [
		'FIELD' => 'Feld „Fallnummer“ in Paperless',
		'CONTENT_NUMBER' => 'Fallnummer im Text',
		'NAME_DATE' => 'Name und Sterbe- oder Geburtsdatum im Text',
	];
	public const CASE_NUMBER_PATTERN = '/(?<![0-9])(20[0-9]{2}-[0-9]{4})(?![0-9])/';

	public static function keyFromName(string $name): string {
		return LabelRules::keyFromName($name);
	}

	/**
	 * Prüft und normalisiert eine Definition.
	 * @param list<string> $folderKeys Schlüssel des aktuellen Ordnerplans
	 * @param array|null $existing gespeicherte Definition: Schlüssel bleibt unverändert.
	 */
	public static function normalizeDefinition(array $input, array $folderKeys, ?array $existing = null): array {
		$name = trim(preg_replace('/\s+/u', ' ', (string)($input['name'] ?? '')) ?? '');
		if ($name === '') throw new \InvalidArgumentException('Die Dokumentart braucht einen Namen.');
		if (mb_strlen($name) > 100) throw new \InvalidArgumentException('Der Name einer Dokumentart darf höchstens 100 Zeichen lang sein.');
		$key = $existing !== null ? (string)$existing['key'] : strtoupper(trim((string)($input['key'] ?? '')));
		if ($key === '') $key = self::keyFromName($name);
		if (!preg_match('/^[A-Z][A-Z0-9_]{1,59}$/', $key)) throw new \InvalidArgumentException('Der technische Schlüssel der Dokumentart ist ungültig (Großbuchstaben, Ziffern und _; beginnt mit einem Buchstaben).');
		$description = trim((string)($input['description'] ?? ''));
		$hint = trim((string)($input['hint'] ?? ''));
		if (mb_strlen($description) > 500 || mb_strlen($hint) > 500) throw new \InvalidArgumentException('Beschreibung und Hinweis dürfen höchstens 500 Zeichen lang sein.');
		$folderKey = strtoupper(trim((string)($input['folderKey'] ?? '')));
		if (!in_array($folderKey, $folderKeys, true)) throw new \InvalidArgumentException('Bitte einen Ablageordner aus dem Ordnerplan wählen.');
		$category = trim((string)($input['category'] ?? ''));
		if (!in_array($category, CaseFileService::DOCUMENT_TYPES, true)) throw new \InvalidArgumentException('Bitte eine Kategorie aus der Dokumentliste wählen.');
		$effect = strtoupper(trim((string)($input['effect'] ?? 'FILE')));
		if (!isset(self::EFFECTS[$effect])) throw new \InvalidArgumentException('Unbekannte Wirkung der Dokumentart.');
		$classification = strtoupper(trim((string)($input['classification'] ?? '')));
		if ($effect === 'INCOMING_INVOICE') {
			if ($classification === '') $classification = 'CLASSIFICATION_PENDING';
			if (!in_array($classification, IncomingInvoiceService::CLASSIFICATIONS, true)) throw new \InvalidArgumentException('Unbekannte Einordnung der Rechnungsposition.');
			if ($category !== 'Rechnung') throw new \InvalidArgumentException('Eine Dokumentart mit Eingangsrechnung gehört zur Kategorie „Rechnung“.');
		} else $classification = '';
		$countLabel = trim((string)($input['countLabel'] ?? ''));
		if (mb_strlen($countLabel) > 60) throw new \InvalidArgumentException('Die Bezeichnung der Anzahl darf höchstens 60 Zeichen lang sein.');
		if ($effect !== 'FILE') $countLabel = '';
		$typeId = $input['paperlessTypeId'] ?? null;
		$typeId = $typeId === null || $typeId === '' || (int)$typeId <= 0 ? null : (int)$typeId;
		return ['key' => $key, 'name' => $name, 'description' => $description, 'hint' => $hint, 'paperlessTypeId' => $typeId, 'folderKey' => $folderKey,
			'category' => $category, 'effect' => $effect, 'classification' => $classification, 'countLabel' => $countLabel, 'active' => self::truthy($input['active'] ?? true)];
	}

	/** Prüft, dass ein Paperless-Dokumenttyp höchstens einer aktiven Art zugeordnet ist. */
	public static function assertUniquePaperlessTypes(array $definitions): void {
		$seen = [];
		foreach ($definitions as $definition) {
			$typeId = (int)($definition['paperlessTypeId'] ?? 0);
			if ($typeId <= 0 || empty($definition['active'])) continue;
			if (isset($seen[$typeId])) throw new \InvalidArgumentException(sprintf('Der Paperless-Dokumenttyp %d ist bereits der Dokumentart „%s“ zugeordnet.', $typeId, $seen[$typeId]));
			$seen[$typeId] = (string)$definition['name'];
		}
	}

	/** Art zu einem Paperless-Dokumenttyp (nur aktive Arten); sonst null. */
	public static function kindForPaperlessType(?int $typeId, array $definitions): ?array {
		if ($typeId === null || $typeId <= 0) return null;
		foreach ($definitions as $definition) if (!empty($definition['active']) && (int)($definition['paperlessTypeId'] ?? 0) === $typeId) return $definition;
		return null;
	}

	/** Fallnummern im Text (in Reihenfolge, ohne Dubletten). */
	public static function caseNumbersInText(string $text): array {
		preg_match_all(self::CASE_NUMBER_PATTERN, $text, $matches);
		return array_values(array_unique($matches[1] ?? []));
	}

	/** Wert eines benutzerdefinierten Paperless-Felds als Fallnummer (z. B. „2026-0042“, „ 2026-0042 “). */
	public static function caseNumberFromField(mixed $value): string {
		if (!is_scalar($value)) return '';
		$numbers = self::caseNumbersInText(trim((string)$value));
		return count($numbers) === 1 ? $numbers[0] : '';
	}

	/**
	 * Fälle, deren Nachname im Text vorkommt und zusätzlich Vorname, Sterbedatum oder Geburtsdatum.
	 * @param list<array{id:int,lastName:string,firstName:string,dateOfDeath?:string,dateOfBirth?:string}> $cases offene Fälle
	 * @return list<int>
	 */
	public static function casesByNameAndDate(string $text, array $cases): array {
		$haystack = mb_strtolower($text);
		if (trim($haystack) === '') return [];
		$hits = [];
		foreach ($cases as $case) {
			$last = mb_strtolower(trim((string)($case['lastName'] ?? '')));
			if (mb_strlen($last) < 3 || !self::containsWord($haystack, $last)) continue;
			$first = mb_strtolower(trim((string)($case['firstName'] ?? '')));
			$confirm = ($first !== '' && mb_strlen($first) >= 2 && self::containsWord($haystack, $first));
			foreach (['dateOfDeath', 'dateOfBirth'] as $field) {
				if ($confirm) break;
				foreach (self::dateVariants((string)($case[$field] ?? '')) as $variant) if (str_contains($haystack, $variant)) { $confirm = true; break; }
			}
			if ($confirm) $hits[] = (int)$case['id'];
		}
		return array_values(array_unique($hits));
	}

	/**
	 * Vorschlag für ein eingehendes Dokument.
	 * @param array{documentTypeId:?int,caseNumberField:string,content:string} $document
	 * @param callable(string):?array $findCase Fall zu einer Fallnummer (exakt) oder null
	 * @param list<array> $openCases für den Abgleich über Name und Datum
	 * @return array{kindKey:string,kindSource:string,caseId:?int,caseNumber:string,caseReason:string,caseCandidates:int,autoFile:bool}
	 */
	public static function suggest(array $document, array $definitions, callable $findCase, array $openCases): array {
		$kind = self::kindForPaperlessType($document['documentTypeId'] ?? null, $definitions);
		$fallback = null;
		foreach ($definitions as $definition) if (!empty($definition['active']) && $definition['key'] === self::FALLBACK_KIND) $fallback = $definition;
		$result = ['kindKey' => (string)($kind['key'] ?? $fallback['key'] ?? ''), 'kindSource' => $kind !== null ? 'PAPERLESS_TYPE' : 'DEFAULT', 'caseId' => null, 'caseNumber' => '', 'caseReason' => '', 'caseCandidates' => 0, 'autoFile' => false];
		$fieldNumber = self::caseNumberFromField($document['caseNumberField'] ?? '');
		if ($fieldNumber !== '' && ($case = $findCase($fieldNumber)) !== null) {
			$result = array_replace($result, ['caseId' => (int)$case['id'], 'caseNumber' => (string)$case['caseNumber'], 'caseReason' => 'FIELD', 'caseCandidates' => 1]);
			// Automatisch nur, wenn auch die Art eindeutig aus Paperless kommt und keine weiteren Angaben braucht.
			$result['autoFile'] = $kind !== null && $kind['effect'] === 'FILE' && trim((string)($kind['countLabel'] ?? '')) === '';
			return $result;
		}
		$content = (string)($document['content'] ?? '');
		$found = [];
		foreach (self::caseNumbersInText($content) as $number) if (($case = $findCase($number)) !== null) $found[(int)$case['id']] = $case;
		if (count($found) === 1) {
			$case = array_values($found)[0];
			return array_replace($result, ['caseId' => (int)$case['id'], 'caseNumber' => (string)$case['caseNumber'], 'caseReason' => 'CONTENT_NUMBER', 'caseCandidates' => 1]);
		}
		if (count($found) > 1) return array_replace($result, ['caseCandidates' => count($found)]);
		$byName = self::casesByNameAndDate($content, $openCases);
		if (count($byName) === 1) {
			$case = array_values(array_filter($openCases, static fn(array $item): bool => (int)$item['id'] === $byName[0]))[0];
			return array_replace($result, ['caseId' => (int)$case['id'], 'caseNumber' => (string)($case['caseNumber'] ?? ''), 'caseReason' => 'NAME_DATE', 'caseCandidates' => 1]);
		}
		return array_replace($result, ['caseCandidates' => count($byName)]);
	}

	/** „2026-03-05“ → [„05.03.2026“, „5.3.2026“]; leer bei ungültigem Datum. */
	public static function dateVariants(string $isoDate): array {
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($isoDate), $m)) return [];
		return array_values(array_unique([sprintf('%s.%s.%s', $m[3], $m[2], $m[1]), sprintf('%d.%d.%s', (int)$m[3], (int)$m[2], $m[1])]));
	}

	private static function containsWord(string $haystack, string $word): bool {
		return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/u', $haystack) === 1;
	}

	private static function truthy(mixed $value): bool {
		return $value === true || $value === 1 || $value === '1' || $value === 'true';
	}
}
