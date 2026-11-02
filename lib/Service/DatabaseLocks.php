<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * 0.89.7: Zeilensperren und Nummernzähler, die auf allen von Nextcloud unterstützten Datenbanken funktionieren.
 *
 * Bisher sperrte die App mit SELECT … FOR UPDATE. SQLite kennt FOR UPDATE nicht – dort scheiterten Fallanlage, Rechnungsnummer
 * und Eingangsrechnungen. Hier sperrt stattdessen ein UPDATE die Zeile: MariaDB/MySQL und PostgreSQL halten die Zeilensperre
 * bis zum Ende der Transaktion (wie FOR UPDATE), SQLite sperrt mit dem ersten Schreibzugriff die ganze Datenbank bis zum Ende
 * der Transaktion. Aufruf immer innerhalb der Transaktion, deren Ergebnis geschützt werden soll.
 *
 * Nextcloud verbindet MySQL mit FOUND_ROWS: executeStatement() liefert die Zahl der getroffenen (nicht nur geänderten) Zeilen.
 */
final class DatabaseLocks {
	private const SEQUENCE_TABLE = 'bestatter_invoice_sequences';

	private function __construct() {
	}

	/**
	 * Sperrt eine Zeile bis zum Ende der laufenden Transaktion.
	 *
	 * @return bool false, wenn es die Zeile nicht gibt
	 */
	public static function lockRow(IDBConnection $db, string $table, int $id): bool {
		$query = $db->getQueryBuilder();
		$query->update($table)
			->set('id', 'id')
			->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $query->executeStatement() > 0;
	}

	/**
	 * Zieht die nächste Nummer eines Zählers (Fall-, Auftrags-, Vorsorge- oder Rechnungsnummern). Der Zähler wird zuerst
	 * erhöht und dann gelesen; zwei gleichzeitige Anfragen erhalten so nie dieselbe Nummer. Fehlt der Zähler, wird er mit 1
	 * angelegt; legt eine gleichzeitige Anfrage ihn im selben Moment an, greift der eindeutige Schlüssel und der Zähler wird
	 * stattdessen erhöht.
	 */
	public static function nextSequence(IDBConnection $db, string $key): int {
		if (self::increment($db, $key) === 0) {
			try {
				$insert = $db->getQueryBuilder();
				$insert->insert(self::SEQUENCE_TABLE)->values([
					'sequence_key' => $insert->createNamedParameter($key),
					'last_number' => $insert->createNamedParameter(1, IQueryBuilder::PARAM_INT),
					'updated_at' => $insert->createNamedParameter(date('c')),
				])->executeStatement();
				return 1;
			} catch (\OCP\DB\Exception $error) {
				if ($error->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $error;
				}
				if (self::increment($db, $key) === 0) {
					throw new \RuntimeException('Der Nummernkreis „' . $key . '“ konnte nicht ermittelt werden.');
				}
			}
		}
		$query = $db->getQueryBuilder();
		$value = $query->select('last_number')->from(self::SEQUENCE_TABLE)
			->where($query->expr()->eq('sequence_key', $query->createNamedParameter($key)))
			->executeQuery()->fetchOne();
		if ($value === false) {
			throw new \RuntimeException('Der Nummernkreis „' . $key . '“ konnte nicht ermittelt werden.');
		}
		return (int)$value;
	}

	private static function increment(IDBConnection $db, string $key): int {
		$query = $db->getQueryBuilder();
		$query->update(self::SEQUENCE_TABLE)
			->set('last_number', $query->func()->add('last_number', $query->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->set('updated_at', $query->createNamedParameter(date('c')))
			->where($query->expr()->eq('sequence_key', $query->createNamedParameter($key)));
		return $query->executeStatement();
	}
}
