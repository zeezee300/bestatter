<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * 0.92.8: Nextcloud reicht Datenbankfehler über den QueryBuilder als OCP\DB\Exception weiter (Grund
 * REASON_UNIQUE_CONSTRAINT_VIOLATION), nicht als Doctrine-Ausnahme. Ein reines
 * catch (UniqueConstraintViolationException) griff deshalb nie; doppelte Einträge endeten als
 * technischer Fehler (HTTP 500) statt mit der vorgesehenen Meldung bzw. stillen Übernahme.
 */
final class DbErrors {
	public static function isUniqueViolation(\Throwable $error): bool {
		for ($current = $error; $current !== null; $current = $current->getPrevious()) {
			if ($current instanceof \Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
				return true;
			}
			if ($current instanceof \OCP\DB\Exception && $current->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return true;
			}
		}
		return false;
	}
}
