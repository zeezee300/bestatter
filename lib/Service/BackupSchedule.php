<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * 0.78.0 – Zeitplan der automatischen Datensicherung (reine Regeln ohne Nextcloud, damit sie ohne Server prüfbar sind).
 *
 * Ein Termin („Slot“) ist eine volle Stunde, täglich oder an einem Wochentag, in der Zeitzone der Nextcloud.
 * Fällig ist die Sicherung, sobald der letzte Slot nach dem letzten Versuch und nach der letzten Änderung des
 * Zeitplans liegt. Damit holt der Hintergrundjob eine verpasste Sicherung (Server aus, Cron gestoppt) genau einmal nach.
 */
final class BackupSchedule {
	public const FREQUENCIES = ['OFF' => 'Aus', 'DAILY' => 'Täglich', 'WEEKLY' => 'Wöchentlich'];
	public const KINDS = ['PACKAGE' => 'Vollständiges Sicherungspaket', 'SNAPSHOT' => 'Schneller App-Snapshot'];
	public const WEEKDAYS = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];
	public const DEFAULTS = ['frequency' => 'OFF', 'hour' => 1, 'weekday' => 7, 'kind' => 'PACKAGE', 'autoCleanup' => true];
	/** Spielraum, bis eine fällige Sicherung als „nicht gelaufen“ gilt (Cron-Takt, Dauer großer Pakete). */
	public const GRACE_SECONDS = 3 * 3600;

	/**
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $current
	 * @return array{frequency:string,hour:int,weekday:int,kind:string,autoCleanup:bool}
	 */
	public static function normalize(array $input, array $current = []): array {
		$value = static fn(string $key): mixed => array_key_exists($key, $input) ? $input[$key] : ($current[$key] ?? self::DEFAULTS[$key]);
		$frequency = strtoupper(trim((string)$value('frequency')));
		if (!isset(self::FREQUENCIES[$frequency])) throw new \InvalidArgumentException('Unbekannter Rhythmus der automatischen Sicherung: „' . $frequency . '“.');
		$kind = strtoupper(trim((string)$value('kind')));
		if (!isset(self::KINDS[$kind])) throw new \InvalidArgumentException('Unbekannte Art der automatischen Sicherung: „' . $kind . '“.');
		$hour = $value('hour');
		if (!is_numeric($hour) || (int)$hour != $hour || (int)$hour < 0 || (int)$hour > 23) throw new \InvalidArgumentException('Die Uhrzeit der automatischen Sicherung muss eine volle Stunde zwischen 0 und 23 sein.');
		$weekday = $value('weekday');
		if (!is_numeric($weekday) || (int)$weekday != $weekday || !isset(self::WEEKDAYS[(int)$weekday])) throw new \InvalidArgumentException('Der Wochentag der automatischen Sicherung muss zwischen 1 (Montag) und 7 (Sonntag) liegen.');
		$cleanup = $value('autoCleanup');
		$cleanup = is_bool($cleanup) ? $cleanup : in_array(strtolower(trim((string)$cleanup)), ['1', 'true', 'ja', 'yes', 'on'], true);
		return ['frequency' => $frequency, 'hour' => (int)$hour, 'weekday' => (int)$weekday, 'kind' => $kind, 'autoCleanup' => $cleanup];
	}

	/** Ändert sich der Termin (Rhythmus, Stunde, Wochentag), beginnt der Zeitplan neu – sonst liefe sofort eine „verpasste“ Sicherung. */
	public static function timingChanged(array $before, array $after): bool {
		foreach (['frequency', 'hour', 'weekday'] as $key) if (($before[$key] ?? null) !== ($after[$key] ?? null)) return true;
		return false;
	}

	/** Letzter Termin, der nicht nach $now liegt (in der Zeitzone von $now); null bei „Aus“. */
	public static function lastSlot(array $schedule, \DateTimeImmutable $now): ?\DateTimeImmutable {
		if (($schedule['frequency'] ?? 'OFF') === 'OFF') return null;
		$slot = $now->setTime((int)$schedule['hour'], 0, 0);
		if ($slot > $now) $slot = $slot->modify('-1 day');
		if ($schedule['frequency'] === 'WEEKLY') {
			// Rückwärts bis zum gewünschten Wochentag (höchstens 6 Tage); setTime nach modify hält die Stunde auch über Zeitumstellungen.
			while ((int)$slot->format('N') !== (int)$schedule['weekday']) $slot = $slot->modify('-1 day')->setTime((int)$schedule['hour'], 0, 0);
		}
		return $slot;
	}

	/** Nächster Termin nach $now; null bei „Aus“. */
	public static function nextSlot(array $schedule, \DateTimeImmutable $now): ?\DateTimeImmutable {
		$last = self::lastSlot($schedule, $now);
		if ($last === null) return null;
		return $last->modify($schedule['frequency'] === 'WEEKLY' ? '+7 days' : '+1 day')->setTime((int)$schedule['hour'], 0, 0);
	}

	/** Fällig, wenn der letzte Termin nach dem letzten Versuch und nach der letzten Änderung des Zeitplans liegt. */
	public static function isDue(array $schedule, \DateTimeImmutable $now, ?\DateTimeImmutable $lastAttempt, ?\DateTimeImmutable $since): bool {
		$slot = self::lastSlot($schedule, $now);
		if ($slot === null) return false;
		foreach ([$lastAttempt, $since] as $reference) if ($reference !== null && $reference >= $slot) return false;
		return true;
	}

	/** Überfällig: Der letzte Termin liegt mehr als den Spielraum zurück, ohne dass seither eine Sicherung gelungen ist. */
	public static function overdue(array $schedule, \DateTimeImmutable $now, ?\DateTimeImmutable $lastSuccess, ?\DateTimeImmutable $since): bool {
		$slot = self::lastSlot($schedule, $now);
		if ($slot === null || $now->getTimestamp() - $slot->getTimestamp() <= self::GRACE_SECONDS) return false;
		if ($since !== null && $since >= $slot) return false;
		return $lastSuccess === null || $lastSuccess < $slot;
	}

	/** Klartext für Oberfläche und Systemprüfung, z. B. „täglich um 01:00 Uhr“ oder „wöchentlich sonntags um 23:00 Uhr“. */
	public static function describe(array $schedule): string {
		$time = sprintf('%02d:00 Uhr', (int)($schedule['hour'] ?? 0));
		return match ($schedule['frequency'] ?? 'OFF') {
			'DAILY' => 'täglich um ' . $time,
			'WEEKLY' => 'wöchentlich ' . strtolower(self::WEEKDAYS[(int)($schedule['weekday'] ?? 7)] ?? 'Sonntag') . 's um ' . $time,
			default => 'aus',
		};
	}
}
