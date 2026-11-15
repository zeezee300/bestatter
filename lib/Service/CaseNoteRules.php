<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * 0.91.0: Regeln für Fallnotizen ohne Datenbank (Text, Titel, Kategorie, Erwähnungen, Markdown-Export,
 * Übernahme aus der Nextcloud-App „Notizen“). Fallnotizen liegen als record_type „note“ in bestatter_records.
 */
final class CaseNoteRules {
	public const RECORD_TYPE = 'note';
	public const STATUS = 'AKTIV';
	public const MAX_TEXT = 5000;
	public const MAX_TITLE = 120;
	public const MAX_MENTIONS = 20;
	public const CATEGORY_LIST = 'NOTE_CATEGORY';
	public const SOURCE_APP = 'APP';
	public const SOURCE_NEXTCLOUD_NOTES = 'NEXTCLOUD_NOTES';
	/** 0.91.1: Gesprächsnotiz aus der Schnellerfassung (früher Stammdatenfeld „Interne Notizen“). */
	public const SOURCE_CAPTURE = 'CAPTURE';
	/** 0.91.1: Vermerk beim Weiterführen eines Auftrags bzw. Einlösen einer Vorsorge. */
	public const SOURCE_TRANSITION = 'TRANSITION';
	/** 0.91.1: Kategorie der Gesprächsnotiz aus der Schnellerfassung. */
	public const CATEGORY_CONVERSATION = 'Gesprächsnotiz';
	/** 0.91.1: Kategorie der Vermerke bei Vorgangsübergängen. */
	public const CATEGORY_INTERNAL = 'Intern';
	/** 0.91.1: Höchstlänge des Platzhalters {{case.notes}} in Dokumenten. */
	public const MAX_DOCUMENT_TEXT = 2000;
	/** Kategorie (Unterordner) in der Nextcloud-App „Notizen“, aus der Notizen übernommen werden. */
	public const NOTES_APP_CATEGORY = 'Bestatter';
	/** Unterordner, in den übernommene Notizen verschoben werden. */
	public const NOTES_APP_DONE_FOLDER = 'Übernommen';
	public const NOTES_APP_MAX_BYTES = 65536;

	/** Bereinigt den Notiztext: Zeilenumbrüche vereinheitlicht, Steuerzeichen entfernt, Pflicht, höchstens 5 000 Zeichen. */
	public static function normalizeText(string $text): string {
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		$text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
		$text = (string)preg_replace("/\n{4,}/", "\n\n\n", $text);
		$text = trim($text);
		if ($text === '') {
			throw new \InvalidArgumentException('Bitte einen Notiztext eingeben.');
		}
		if (mb_strlen($text) > self::MAX_TEXT) {
			throw new \InvalidArgumentException('Die Notiz ist zu lang (höchstens ' . self::MAX_TEXT . ' Zeichen).');
		}
		return $text;
	}

	/** Kurztitel aus der ersten Zeile (für Fall-Verlauf, Benachrichtigung und Suche), ohne Markdown-Zeichen. */
	public static function title(string $text): string {
		foreach (explode("\n", $text) as $line) {
			$line = trim((string)preg_replace('/^(?:#{1,6}\s*|[-*+]\s+|>\s*|\d+[.)]\s+)/u', '', trim($line)));
			$line = trim(str_replace(['**', '__', '`'], '', $line));
			if ($line === '') {
				continue;
			}
			return mb_strlen($line) > self::MAX_TITLE ? rtrim(mb_substr($line, 0, self::MAX_TITLE - 1)) . '…' : $line;
		}
		return 'Notiz';
	}

	/**
	 * Kategorie: leer oder ein Wert der Werteliste „Kategorie der Notiz“ (Customizing).
	 * @param list<string> $allowed
	 */
	public static function normalizeCategory(string $category, array $allowed): string {
		$category = trim($category);
		if ($category === '') {
			return '';
		}
		if (!in_array($category, $allowed, true)) {
			throw new \InvalidArgumentException('Die gewählte Kategorie ist nicht (mehr) vorhanden. Bitte eine Kategorie aus der Liste wählen.');
		}
		return $category;
	}

	/**
	 * Erwähnte Personen: eindeutige Benutzerkennungen, nur Mitglieder (Prüfung durch $isMember), höchstens 20.
	 * @param array<int|string, mixed> $uids
	 * @return list<string>
	 */
	public static function normalizeMentions(array $uids, callable $isMember): array {
		$result = [];
		foreach ($uids as $uid) {
			$uid = trim(is_scalar($uid) ? (string)$uid : '');
			if ($uid === '' || isset($result[$uid]) || !$isMember($uid)) {
				continue;
			}
			$result[$uid] = $uid;
			if (count($result) >= self::MAX_MENTIONS) {
				break;
			}
		}
		return array_values($result);
	}

	/**
	 * Liest die Liste der erwähnten Personen aus der Anfrage (JSON-Liste oder kommagetrennt).
	 * @return list<string>
	 */
	public static function parseUidList(string $value): array {
		$value = trim($value);
		if ($value === '') {
			return [];
		}
		if (str_starts_with($value, '[')) {
			$decoded = json_decode($value, true);
			return is_array($decoded) ? array_values(array_filter(array_map(static fn (mixed $uid): string => trim(is_scalar($uid) ? (string)$uid : ''), $decoded), static fn (string $uid): bool => $uid !== '')) : [];
		}
		return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $uid): bool => $uid !== ''));
	}

	/**
	 * Personen, die mit dieser Änderung neu erwähnt werden (nur sie werden benachrichtigt).
	 * @param list<string> $before
	 * @param list<string> $after
	 * @return list<string>
	 */
	public static function newMentions(array $before, array $after): array {
		return array_values(array_diff($after, $before));
	}

	/** Ja/Nein aus einem Formularwert. */
	public static function flag(mixed $value): bool {
		return in_array(strtolower(trim(is_scalar($value) ? (string)$value : '')), ['1', 'true', 'yes', 'ja', 'on'], true);
	}

	/**
	 * Enthält Text oder Dateiname die Fallnummer (Groß-/Kleinschreibung egal)? Für die Übernahme aus Nextcloud Notes.
	 */
	public static function mentionsCaseNumber(string $caseNumber, string ...$texts): bool {
		$caseNumber = trim($caseNumber);
		if ($caseNumber === '') {
			return false;
		}
		// Nicht Teil einer längeren Nummer (A-2026-0012 ist ein Auftrag, 2026-00123 ein anderer Fall).
		$pattern = '/(?<![A-Za-z0-9])(?<![A-Za-z0-9]-)' . preg_quote($caseNumber, '/') . '(?![0-9])/iu';
		foreach ($texts as $text) {
			if (preg_match($pattern, $text) === 1) {
				return true;
			}
		}
		return false;
	}

	/** Notiz aus einer Datei der Nextcloud-App „Notizen“: Text ohne BOM; der Dateiname wird Überschrift, wenn der Text nicht damit beginnt. */
	public static function textFromNotesFile(string $fileName, string $content): string {
		$content = (string)preg_replace('/^\xEF\xBB\xBF/', '', $content);
		if (!mb_check_encoding($content, 'UTF-8')) {
			$converted = @mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
			$content = is_string($converted) ? $converted : '';
		}
		$name = trim((string)preg_replace('/\.(md|txt|markdown)$/i', '', $fileName));
		$text = trim(str_replace(["\r\n", "\r"], "\n", $content));
		$first = trim((string)preg_replace('/^#{1,6}\s*/', '', explode("\n", $text)[0] ?? ''));
		if ($name !== '' && $first !== $name) {
			$text = $text === '' ? $name : $name . "\n\n" . $text;
		}
		return $text;
	}

	/**
	 * Markdown-Datei mit allen Notizen eines Falls (für „Falldaten exportieren“ und den Download im Reiter „Notizen“).
	 * Reihenfolge: zeitlich aufsteigend, angeheftete Notizen gekennzeichnet.
	 * @param array{caseNumber?: string, name?: string} $case
	 * @param list<array<string, mixed>> $notes Notizen wie CaseNoteService::list() (createdAt, createdByName, text …)
	 */
	public static function markdown(array $case, array $notes, string $exportedAt, string $exportedBy): string {
		$number = trim((string)($case['caseNumber'] ?? ''));
		$name = trim((string)($case['name'] ?? ''));
		$heading = '# Notizen – ' . ($number !== '' ? $number : 'Fall') . ($name !== '' ? ' (' . $name . ')' : '');
		usort($notes, static fn (array $left, array $right): int => strcmp((string)($left['createdAt'] ?? ''), (string)($right['createdAt'] ?? '')) ?: ((int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0)));
		$count = count($notes);
		$lines = [$heading, '', 'Exportiert am ' . self::formatDate($exportedAt) . ($exportedBy !== '' ? ' von ' . $exportedBy : '') . ' · ' . ($count === 1 ? '1 Notiz' : $count . ' Notizen'), ''];
		if ($count === 0) {
			$lines[] = 'Zu diesem Fall gibt es keine Notizen.';
			$lines[] = '';
		}
		foreach ($notes as $note) {
			$meta = [self::formatDate((string)($note['createdAt'] ?? '')), trim((string)($note['createdByName'] ?? $note['createdBy'] ?? ''))];
			$category = trim((string)($note['categoryLabel'] ?? $note['category'] ?? ''));
			if ($category !== '') {
				$meta[] = $category;
			}
			if (!empty($note['pinned'])) {
				$meta[] = 'angeheftet';
			}
			$lines[] = '---';
			$lines[] = '';
			$lines[] = '## ' . implode(' · ', array_values(array_filter($meta, static fn (string $part): bool => $part !== '')));
			$lines[] = '';
			if (!empty($note['edited'])) {
				$lines[] = '*Bearbeitet von ' . trim((string)($note['editedByName'] ?? $note['editedBy'] ?? '')) . ' am ' . self::formatDate((string)($note['editedAt'] ?? '')) . '*';
				$lines[] = '';
			}
			$origin = self::sourceLabel((string)($note['source'] ?? ''));
			if ($origin !== '') {
				$lines[] = '*' . $origin . '*';
				$lines[] = '';
			}
			$lines[] = (string)($note['text'] ?? '');
			$lines[] = '';
		}
		return implode("\n", $lines);
	}

	/** Dateiname „Notizen-<Fallnummer>.md“ (nur unbedenkliche Zeichen). */
	public static function markdownFileName(string $caseNumber): string {
		$safe = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $caseNumber), '-');
		return 'Notizen-' . ($safe !== '' ? $safe : 'Fall') . '.md';
	}

	/** Datum für Export und Anzeige (dd.mm.yyyy hh:mm), unbekannte Werte unverändert. */
	public static function formatDate(string $value): string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}
		try {
			$date = new \DateTimeImmutable($value);
			return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d.m.Y H:i');
		} catch (\Throwable) {
			return $value;
		}
	}

	/** 0.91.1: Herkunft einer Notiz für Anzeige und Export (leer bei Notizen, die im Reiter „Notizen“ angelegt wurden). */
	public static function sourceLabel(string $source): string {
		return match ($source) {
			self::SOURCE_NEXTCLOUD_NOTES => 'Aus Nextcloud Notizen übernommen',
			self::SOURCE_CAPTURE => 'Aus der Schnellerfassung',
			self::SOURCE_TRANSITION => 'Beim Weiterführen des Vorgangs angelegt',
			default => '',
		};
	}

	/**
	 * 0.91.1: Teilt einen langen Text (z. B. Gesprächsnotiz der Schnellerfassung) in Notizen zu höchstens $max Zeichen,
	 * bevorzugt an Absatz-, Zeilen- oder Wortgrenzen. Nichts geht verloren.
	 * @return list<string>
	 */
	public static function splitText(string $text, int $max = self::MAX_TEXT): array {
		$text = trim(str_replace(["\r\n", "\r"], "\n", $text));
		if ($text === '') {
			return [];
		}
		$reserve = 20; // Platz für „(Teil 2 von 3)“
		$limit = max(100, $max - $reserve);
		if (mb_strlen($text) <= $max) {
			return [$text];
		}
		$parts = [];
		while (mb_strlen($text) > $limit) {
			$chunk = mb_substr($text, 0, $limit);
			$cut = 0;
			foreach (["\n\n", "\n", '. ', ' '] as $separator) {
				$position = mb_strrpos($chunk, $separator);
				if ($position !== false && $position >= (int)($limit / 2)) {
					$cut = $position + mb_strlen($separator);
					break;
				}
			}
			$cut = $cut > 0 ? $cut : $limit;
			$parts[] = trim(mb_substr($text, 0, $cut));
			$text = trim(mb_substr($text, $cut));
		}
		if ($text !== '') {
			$parts[] = $text;
		}
		$count = count($parts);
		return array_map(static fn (string $part, int $index): string => $part . "\n\n(Teil " . ($index + 1) . ' von ' . $count . ')', $parts, array_keys($parts));
	}

	/**
	 * 0.91.1: Daten neuer Notizen, die die App selbst anlegt (Schnellerfassung, Vorgangsübergang). Die Kategorie wird nicht
	 * gegen die Werteliste geprüft, damit die Anlage nie an einer geänderten Werteliste scheitert.
	 * @return list<array<string, mixed>>
	 */
	public static function systemPayloads(string $text, string $category, string $source): array {
		return array_map(static fn (string $part): array => [
			'text' => self::normalizeText($part),
			'category' => trim($category),
			'pinned' => false,
			'mentions' => [],
			'source' => $source,
		], self::splitText($text));
	}

	/**
	 * 0.91.1: Text für den Platzhalter {{case.notes}} (Stammdatenblatt): Gesprächsnotizen und angeheftete Notizen,
	 * zeitlich aufsteigend, je „TT.MM.JJJJ: Text“ in einer Zeile, höchstens 2 000 Zeichen.
	 * @param list<array{text?: mixed, category?: mixed, pinned?: mixed, date?: mixed}> $notes
	 */
	public static function documentText(array $notes): string {
		$selected = array_values(array_filter($notes, static fn (array $note): bool => (string)($note['category'] ?? '') === self::CATEGORY_CONVERSATION || !empty($note['pinned'])));
		usort($selected, static fn (array $left, array $right): int => strcmp((string)($left['date'] ?? ''), (string)($right['date'] ?? '')));
		$entries = [];
		foreach ($selected as $note) {
			$text = trim((string)preg_replace('/\s+/u', ' ', (string)($note['text'] ?? '')));
			if ($text === '') {
				continue;
			}
			$date = self::formatDate((string)($note['date'] ?? ''));
			$entries[] = ($date !== '' ? substr($date, 0, 10) . ': ' : '') . $text;
		}
		$result = implode(' · ', $entries);
		return mb_strlen($result) > self::MAX_DOCUMENT_TEXT ? rtrim(mb_substr($result, 0, self::MAX_DOCUMENT_TEXT - 1)) . '…' : $result;
	}
}
