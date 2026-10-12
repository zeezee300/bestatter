<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;

/**
 * 0.75.1: Von der App in Nextcloud-Kalendern angelegte Termine und Aufgaben (inkl. Kopien in den Kalendern der
 * Zuständigen). Gemeinsam genutzt von Fall löschen, Aufbewahrung und dem vollständigen Entfernen der App.
 */
class AppCalendarObjects {
	public function __construct(private IDBConnection $db) {}

	/** @return list<array{calendarId:int,uri:string}> */
	public function targets(?int $caseId = null): array {
		$query = $this->db->getQueryBuilder();
		$query->select('payload', 'nextcloud_calendar_key', 'nextcloud_uri')->from('bestatter_records');
		if ($caseId !== null) $query->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)));
		return $this->collect($query);
	}

	/**
	 * 0.75.2: Kalenderobjekte einzelner Datensätze (z. B. doppelte Folgeaufgaben bei der Bereinigung).
	 * @param list<int> $recordIds
	 * @return list<array{calendarId:int,uri:string}>
	 */
	public function targetsForRecordIds(array $recordIds): array {
		if ($recordIds === []) return [];
		$query = $this->db->getQueryBuilder();
		$query->select('payload', 'nextcloud_calendar_key', 'nextcloud_uri')->from('bestatter_records')
			->where($query->expr()->in('id', $query->createNamedParameter($recordIds, IQueryBuilder::PARAM_INT_ARRAY)));
		return $this->collect($query);
	}

	/** @return list<array{calendarId:int,uri:string}> */
	private function collect(IQueryBuilder $query): array {
		$targets = [];
		foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
			$this->add($targets, (int)($row['nextcloud_calendar_key'] ?? 0), (string)($row['nextcloud_uri'] ?? ''));
			$payload = json_decode((string)($row['payload'] ?? '{}'), true) ?: [];
			$nextcloud = (array)($payload['nextcloud'] ?? []);
			$this->add($targets, (int)($nextcloud['calendarKey'] ?? 0), (string)($nextcloud['uri'] ?? ''));
			foreach ((array)($payload['nextcloudCopies'] ?? []) as $copy) $this->add($targets, (int)($copy['calendarKey'] ?? 0), (string)($copy['uri'] ?? ''));
		}
		return array_values($targets);
	}

	/**
	 * Fehlende Objekte (bereits vom Benutzer gelöscht) gelten als erledigt.
	 * @param list<array{calendarId:int,uri:string}> $targets
	 * @return array{planned:int,deleted:int,missing:int,errors:list<array{uri:string,message:string}>}
	 */
	public function delete(array $targets): array {
		$result = ['planned' => count($targets), 'deleted' => 0, 'missing' => 0, 'errors' => []];
		if ($targets === []) return $result;
		try {
			$backend = $this->backend();
		} catch (\Throwable $error) {
			foreach ($targets as $target) $result['errors'][] = ['uri' => $target['uri'], 'message' => $error->getMessage()];
			return $result;
		}
		foreach ($targets as $target) {
			try {
				// 0.75.2: endgültig löschen – sonst blieb der Eintrag 30 Tage im Kalender-Papierkorb der Person.
				$backend->deleteCalendarObject($target['calendarId'], $target['uri'], 0, true);
				$result['deleted']++;
			} catch (\Throwable $error) {
				if (str_contains(strtolower($error->getMessage()), 'not found') || str_contains($error->getMessage(), '404')) $result['missing']++;
				else $result['errors'][] = ['uri' => $target['uri'], 'message' => $error->getMessage()];
			}
		}
		return $result;
	}

	/** @param array<string,array{calendarId:int,uri:string}> $targets */
	private function add(array &$targets, int $calendarId, string $uri): void {
		if ($calendarId > 0 && trim($uri) !== '') $targets[$calendarId . ':' . $uri] = ['calendarId' => $calendarId, 'uri' => $uri];
	}

	private function backend(): object {
		$class = '\\OCA\\DAV\\CalDAV\\CalDavBackend';
		if (!class_exists($class)) throw new \RuntimeException('Das Nextcloud-DAV-Backend ist nicht verfügbar.');
		return Server::get($class);
	}
}
