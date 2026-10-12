<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * Verkaufspreise als Konditionssätze mit Gültigkeit (SAP: Konditionsart PR00, Zugriff
 * Niederlassung → Standard). Es gilt immer eine einheitliche Standardpreisliste; eine Preisliste
 * je Niederlassung enthält nur abweichende Preise und hat bei Fällen dieser Niederlassung Vorrang.
 */
class PriceListService {
	public const STANDARD = 'STANDARD';

	public function __construct(private IDBConnection $db, private IUserSession $userSession, private ConfigurationService $configuration) {}

	public function standardListId(): int {
		$query = $this->db->getQueryBuilder();
		$id = $query->select('id')->from('bestatter_price_lists')->where($query->expr()->eq('list_key', $query->createNamedParameter(self::STANDARD)))->executeQuery()->fetchOne();
		if ($id !== false) return (int)$id;
		return $this->insertList(self::STANDARD, 'Standardpreisliste', null);
	}

	public function lists(): array {
		$this->standardListId();
		$branches = [];
		foreach ($this->configuration->branches() as $branch) $branches[(string)$branch['key']] = (string)$branch['name'];
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_price_lists')->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		$counts = [];
		$count = $this->db->getQueryBuilder();
		foreach ($count->select('price_list_id')->selectAlias($count->func()->count('*'), 'items')->from('bestatter_price_list_items')->groupBy('price_list_id')->executeQuery()->fetchAllAssociative() as $row) $counts[(int)$row['price_list_id']] = (int)$row['items'];
		$lists = array_map(static fn(array $row): array => [
			'id' => (int)$row['id'], 'key' => (string)$row['list_key'], 'name' => (string)$row['name'],
			'branchKey' => $row['branch_key'] !== null ? (string)$row['branch_key'] : null,
			'branchName' => $row['branch_key'] !== null ? ($branches[(string)$row['branch_key']] ?? (string)$row['branch_key']) : '',
			'standard' => (string)$row['list_key'] === self::STANDARD, 'active' => $row['active'] === null ? true : (bool)$row['active'],
			'itemCount' => $counts[(int)$row['id']] ?? 0,
		], $rows);
		usort($lists, static fn(array $left, array $right): int => [(int)!$left['standard'], $left['name']] <=> [(int)!$right['standard'], $right['name']]);
		return $lists;
	}

	public function createBranchList(string $branchKey, string $name = ''): array {
		$branch = $this->configuration->branchByKey($branchKey);
		if ($branch === null) throw new \InvalidArgumentException('Die Niederlassung wurde nicht gefunden.');
		$key = 'NL-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$branch['key']) ?: (string)$branch['id']);
		$query = $this->db->getQueryBuilder();
		if ($query->select('id')->from('bestatter_price_lists')->where($query->expr()->eq('branch_key', $query->createNamedParameter((string)$branch['key'])))->executeQuery()->fetchOne() !== false) throw new \InvalidArgumentException('Für diese Niederlassung besteht bereits eine Preisliste.');
		$id = $this->insertList(mb_substr($key, 0, 40), trim($name) !== '' ? mb_substr(trim($name), 0, 150) : 'Preisliste ' . (string)$branch['name'], (string)$branch['key']);
		foreach ($this->lists() as $list) if ($list['id'] === $id) return $list;
		throw new \RuntimeException('Die Preisliste konnte nicht gelesen werden.');
	}

	public function updateList(int $id, array $data): array {
		$list = $this->list($id);
		$name = trim((string)($data['name'] ?? $list['name']));
		if ($name === '' || mb_strlen($name) > 150) throw new \InvalidArgumentException('Bitte eine Bezeichnung der Preisliste angeben.');
		$active = $list['list_key'] === self::STANDARD ? true : (filter_var($data['active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true);
		$update = $this->db->getQueryBuilder();
		$update->update('bestatter_price_lists')->set('name', $update->createNamedParameter($name))->set('active', $update->createNamedParameter($active, IQueryBuilder::PARAM_BOOL))
			->set('updated_at', $update->createNamedParameter(date('c')))->where($update->expr()->eq('id', $update->createNamedParameter($id)))->executeStatement();
		foreach ($this->lists() as $entry) if ($entry['id'] === $id) return $entry;
		throw new \RuntimeException('Die Preisliste konnte nicht gelesen werden.');
	}

	public function deleteList(int $id): void {
		$list = $this->list($id);
		if ((string)$list['list_key'] === self::STANDARD) throw new \InvalidArgumentException('Die Standardpreisliste kann nicht gelöscht werden.');
		$delete = $this->db->getQueryBuilder();
		$delete->delete('bestatter_price_list_items')->where($delete->expr()->eq('price_list_id', $delete->createNamedParameter($id)))->executeStatement();
		$delete = $this->db->getQueryBuilder();
		$delete->delete('bestatter_price_lists')->where($delete->expr()->eq('id', $delete->createNamedParameter($id)))->executeStatement();
	}

	public function items(int $listId): array {
		$this->list($listId);
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('p.*', 'a.article_number', 'a.short_name')->from('bestatter_price_list_items', 'p')
			->innerJoin('p', 'bestatter_articles', 'a', $query->expr()->eq('a.id', 'p.article_id'))
			->where($query->expr()->eq('p.price_list_id', $query->createNamedParameter($listId)))
			->orderBy('a.article_number', 'ASC')->addOrderBy('p.valid_from', 'DESC')->executeQuery()->fetchAllAssociative();
		$today = date('Y-m-d');
		return array_map(static fn(array $row): array => [
			'id' => (int)$row['id'], 'articleId' => (int)$row['article_id'], 'articleNumber' => (string)$row['article_number'], 'shortName' => (string)$row['short_name'],
			'priceCents' => (int)$row['price_cents'], 'validFrom' => (string)$row['valid_from'], 'validTo' => $row['valid_to'] !== null ? (string)$row['valid_to'] : null,
			'current' => (string)$row['valid_from'] <= $today && ($row['valid_to'] === null || (string)$row['valid_to'] >= $today),
			'createdBy' => (string)($row['created_by'] ?? ''), 'updatedAt' => (string)$row['updated_at'],
		], $rows);
	}

	/**
	 * Legt einen Preis ab einem Stichtag an. Ein laufender Konditionssatz endet am Vortag; ein
	 * Satz mit gleichem Beginn wird überschrieben. Spätere, bereits geplante Sätze bleiben erhalten.
	 */
	public function setPrice(int $listId, int $articleId, int $priceCents, string $validFrom = '', ?string $validTo = null): void {
		$this->list($listId);
		if ($priceCents < 0) throw new \InvalidArgumentException('Der Preis darf nicht negativ sein.');
		$validFrom = $this->date($validFrom !== '' ? $validFrom : date('Y-m-d'), 'Gültig ab');
		$validTo = $validTo !== null && trim($validTo) !== '' ? $this->date($validTo, 'Gültig bis') : null;
		if ($validTo !== null && $validTo < $validFrom) throw new \InvalidArgumentException('„Gültig bis“ liegt vor „Gültig ab“.');
		// Beenden des bisherigen und Anlegen des neuen Satzes gehören zusammen: entweder beides oder nichts.
		$this->db->beginTransaction();
		try {
			$this->writePrice($listId, $articleId, $priceCents, $validFrom, $validTo);
			$this->assertNoOverlap($listId, $articleId);
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			if ($error instanceof \OCP\DB\Exception && $error->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new \InvalidArgumentException('Für diesen Artikel wurde gerade ein Preis mit demselben „Gültig ab“ gespeichert. Bitte die Preisliste neu laden und erneut versuchen.');
			}
			throw $error;
		}
	}

	/**
	 * Regeln (Konditionssätze wie in SAP):
	 * - „Gültig bis“ darf leer bleiben; der Preis gilt dann bis zum Beginn des nächsten Preises.
	 * - Ein neuer Preis ab Stichtag beendet einen laufenden Preis mit leerem „Gültig bis“ am Vortag.
	 * - Ein ausdrücklich gepflegtes „Gültig bis“ wird nie stillschweigend verkürzt; überschneidet es
	 *   den neuen Preis, wird die Eingabe abgelehnt.
	 * - Ohne eigenes „Gültig bis“ endet der neue Preis am Vortag eines bereits geplanten späteren Preises.
	 */
	private function writePrice(int $listId, int $articleId, int $priceCents, string $validFrom, ?string $validTo): void {
		$rows = $this->itemRows($listId, $articleId);
		$now = date('c');
		$format = static fn(string $date): string => (new \DateTimeImmutable($date))->format('d.m.Y');
		$same = null; $previous = null; $next = null;
		foreach ($rows as $row) {
			$from = (string)$row['valid_from'];
			if ($from === $validFrom) $same = $row;
			elseif ($from < $validFrom) $previous = $row;
			elseif ($next === null) $next = $row;
		}
		if ($previous !== null) {
			$previousTo = $previous['valid_to'] !== null ? (string)$previous['valid_to'] : null;
			// Endet der bisherige Preis genau am Vortag des folgenden Satzes, war das Ende automatisch gesetzt
			// („bis zum nächsten Preis“) und zählt wie ein leeres „Gültig bis“.
			$implicitEnd = $previousTo !== null && $this->followsDirectly($rows, $previous);
			if ($previousTo === null || ($implicitEnd && $previousTo >= $validFrom)) {
				$this->updateItem((int)$previous['id'], ['valid_to' => $this->previousDay($validFrom)], $now);
			} elseif ($previousTo !== null && $previousTo >= $validFrom) {
				throw new \InvalidArgumentException('Der bisherige Preis gilt ausdrücklich bis ' . $format($previousTo) . ' und überschneidet sich mit dem neuen Preis ab ' . $format($validFrom) . '. Bitte beim bisherigen Preis „Gültig bis“ anpassen oder leeren.');
			}
		}
		if ($next !== null) {
			$lastDay = $this->previousDay((string)$next['valid_from']);
			if ($validTo === null) $validTo = $lastDay;
			elseif ($validTo > $lastDay) throw new \InvalidArgumentException('„Gültig bis“ ' . $format($validTo) . ' überschneidet sich mit dem geplanten Preis ab ' . $format((string)$next['valid_from']) . '. Bitte „Gültig bis“ leer lassen oder spätestens ' . $format($lastDay) . ' wählen.');
		}
		if ($same !== null) {
			if ($validTo === null && $same['valid_to'] !== null && !$this->followsDirectly($rows, $same)) $validTo = (string)$same['valid_to'];
			$this->updateItem((int)$same['id'], ['price_cents' => $priceCents, 'valid_to' => $validTo, 'created_by' => $this->uid()], $now);
			return;
		}
		$insert = $this->db->getQueryBuilder();
		$insert->insert('bestatter_price_list_items')->values([
			'price_list_id' => $insert->createNamedParameter($listId), 'article_id' => $insert->createNamedParameter($articleId),
			'price_cents' => $insert->createNamedParameter($priceCents), 'valid_from' => $insert->createNamedParameter($validFrom),
			'valid_to' => $insert->createNamedParameter($validTo), 'created_by' => $insert->createNamedParameter($this->uid()),
			'created_at' => $insert->createNamedParameter($now), 'updated_at' => $insert->createNamedParameter($now),
		])->executeStatement();
	}

	/** Endet der Satz genau am Vortag des nächsten Satzes derselben Liste (automatisch gesetztes Ende)? */
	private function followsDirectly(array $rows, array $item): bool {
		if ($item['valid_to'] === null) return false;
		foreach ($rows as $row) if ((string)$row['valid_from'] > (string)$item['valid_from']) return $this->previousDay((string)$row['valid_from']) === (string)$item['valid_to'];
		return false;
	}

	/** Serverseitige Schlussprüfung: je Preisliste und Artikel gilt an jedem Tag höchstens ein Preis. */
	private function assertNoOverlap(int $listId, int $articleId): void {
		$rows = $this->itemRows($listId, $articleId);
		for ($index = 1; $index < count($rows); $index++) {
			$before = $rows[$index - 1];
			if ($before['valid_to'] === null || (string)$before['valid_to'] >= (string)$rows[$index]['valid_from']) {
				throw new \InvalidArgumentException('Die Preise überschneiden sich (ab ' . (string)$before['valid_from'] . ' und ab ' . (string)$rows[$index]['valid_from'] . '). Bitte „Gültig bis“ prüfen.');
			}
		}
	}

	/** @return list<array<string, mixed>> */
	private function itemRows(int $listId, int $articleId): array {
		$query = $this->db->getQueryBuilder();
		return $query->select('*')->from('bestatter_price_list_items')->where($query->expr()->eq('price_list_id', $query->createNamedParameter($listId)))
			->andWhere($query->expr()->eq('article_id', $query->createNamedParameter($articleId)))->orderBy('valid_from', 'ASC')->executeQuery()->fetchAllAssociative();
	}

	private function updateItem(int $id, array $values, string $now): void {
		$update = $this->db->getQueryBuilder();
		$update->update('bestatter_price_list_items');
		foreach ($values as $column => $value) $update->set($column, $update->createNamedParameter($value));
		$update->set('updated_at', $update->createNamedParameter($now))->where($update->expr()->eq('id', $update->createNamedParameter($id)))->executeStatement();
	}

	/** Preise einer Preisliste als CSV (alle Konditionssätze inkl. geplanter und abgelaufener). */
	public function exportCsv(int $listId): string {
		$rows = array_map(static fn(array $item): array => [$item['articleNumber'], $item['shortName'], CsvTools::money($item['priceCents']), $item['validFrom'], $item['validTo'] ?? '', ''], $this->items($listId));
		return CsvTools::write(['Artikelnummer', 'Kurztext', 'Preis', 'Gültig ab', 'Gültig bis', 'Löschen'], $rows);
	}

	public function listName(int $listId): string { return (string)$this->list($listId)['name']; }

	/**
	 * Übernimmt Preise aus einer CSV-Datei. Zeilen ohne „Gültig ab“ gelten ab heute; unveränderte Sätze
	 * werden übersprungen, „Löschen = ja“ entfernt den Satz mit diesem Beginn.
	 * @return array{saved: int, unchanged: int, deleted: int}
	 */
	public function importCsv(int $listId, string $content): array {
		$this->list($listId);
		$map = ['artikelnummer' => 'articleNumber', 'articlenumber' => 'articleNumber', 'kurztext' => 'shortName', 'preis' => 'price', 'vk' => 'price', 'preis_netto' => 'price', 'price' => 'price',
			'gueltig_ab' => 'validFrom', 'validfrom' => 'validFrom', 'gueltig_bis' => 'validTo', 'validto' => 'validTo', 'loeschen' => 'delete', 'delete' => 'delete'];
		$rows = CsvTools::read($content, static fn(string $header): string => $map[CsvTools::headerKey($header)] ?? $header);
		if ($rows !== [] && (!array_key_exists('articleNumber', $rows[0]['values']) || !array_key_exists('price', $rows[0]['values']))) throw new \InvalidArgumentException('Die CSV-Datei benötigt die Spalten „Artikelnummer“ und „Preis“.');
		$articles = [];
		$query = $this->db->getQueryBuilder();
		foreach ($query->select('id', 'article_number')->from('bestatter_articles')->executeQuery()->fetchAllAssociative() as $article) $articles[(string)$article['article_number']] = (int)$article['id'];
		$existing = [];
		foreach ($this->items($listId) as $item) $existing[$item['articleId'] . '|' . $item['validFrom']] = $item;
		$today = date('Y-m-d');
		$saved = 0; $unchanged = 0; $deleted = 0;
		$this->db->beginTransaction();
		try {
			foreach ($rows as $row) {
				$values = $row['values'];
				$number = trim($values['articleNumber'] ?? '');
				try {
					if (!isset($articles[$number])) throw new \InvalidArgumentException('Artikelnummer „' . $number . '“ ist nicht im Leistungskatalog.');
					$articleId = $articles[$number];
					$validFrom = CsvTools::date($values['validFrom'] ?? '', 'Gültig ab') ?: $today;
					$validTo = CsvTools::date($values['validTo'] ?? '', 'Gültig bis');
					$current = $existing[$articleId . '|' . $validFrom] ?? null;
					if (CsvTools::flag($values['delete'] ?? '')) {
						if ($current !== null) { $this->deleteItem((int)$current['id']); $deleted++; }
						continue;
					}
					if (trim($values['price'] ?? '') === '') throw new \InvalidArgumentException('Bitte einen Preis angeben.');
					$cents = CsvTools::cents($values['price']);
					if ($current !== null && (int)$current['priceCents'] === $cents && (string)($current['validTo'] ?? '') === $validTo) { $unchanged++; continue; }
					$this->setPrice($listId, $articleId, $cents, $validFrom, $validTo !== '' ? $validTo : null);
					$saved++;
				} catch (\InvalidArgumentException $error) {
					throw new \InvalidArgumentException('CSV-Zeile ' . $row['line'] . ' (' . $number . '): ' . $error->getMessage());
				}
			}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		return ['saved' => $saved, 'unchanged' => $unchanged, 'deleted' => $deleted];
	}

	public function deleteItem(int $itemId): void {
		$query = $this->db->getQueryBuilder();
		$item = $query->select('*')->from('bestatter_price_list_items')->where($query->expr()->eq('id', $query->createNamedParameter($itemId)))->executeQuery()->fetchAssociative();
		if (!$item) throw new \InvalidArgumentException('Der Preis wurde nicht gefunden.');
		$this->db->beginTransaction();
		try {
			$rows = $this->itemRows((int)$item['price_list_id'], (int)$item['article_id']);
			$previous = null; $next = null;
			foreach ($rows as $row) {
				if ((string)$row['valid_from'] < (string)$item['valid_from']) $previous = $row;
				elseif ((string)$row['valid_from'] > (string)$item['valid_from'] && $next === null) $next = $row;
			}
			$delete = $this->db->getQueryBuilder();
			$delete->delete('bestatter_price_list_items')->where($delete->expr()->eq('id', $delete->createNamedParameter($itemId)))->executeStatement();
			// Ein automatisch am Vortag beendeter Vorgänger gilt wieder bis zum nächsten Preis bzw. unbefristet.
			if ($previous !== null && $previous['valid_to'] !== null && (string)$previous['valid_to'] === $this->previousDay((string)$item['valid_from'])) {
				$this->updateItem((int)$previous['id'], ['valid_to' => $next !== null ? $this->previousDay((string)$next['valid_from']) : null], date('c'));
			}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
	}


	public function deleteArticlePrices(int $articleId): void {
		$delete = $this->db->getQueryBuilder();
		$delete->delete('bestatter_price_list_items')->where($delete->expr()->eq('article_id', $delete->createNamedParameter($articleId)))->executeStatement();
	}

	/**
	 * Gültige Verkaufspreise zum Stichtag: zuerst die aktive Preisliste der Niederlassung, sonst
	 * die Standardpreisliste. Artikel ohne gültigen Konditionssatz fehlen im Ergebnis.
	 *
	 * @return array<int, array{cents:int, listKey:string, validFrom:string}>
	 */
	public function prices(string $branchKey = '', string $date = ''): array {
		$date = $date !== '' ? $date : date('Y-m-d');
		$standard = $this->standardListId();
		$branchList = null;
		if (trim($branchKey) !== '') {
			$query = $this->db->getQueryBuilder();
			$row = $query->select('id', 'active')->from('bestatter_price_lists')->where($query->expr()->eq('branch_key', $query->createNamedParameter($branchKey)))->executeQuery()->fetchAssociative();
			if ($row && ($row['active'] === null || (bool)$row['active'])) $branchList = (int)$row['id'];
		}
		$result = [];
		foreach (array_filter([$standard, $branchList]) as $listId) {
			$query = $this->db->getQueryBuilder();
			$rows = $query->select('i.article_id', 'i.price_cents', 'i.valid_from', 'l.list_key')->from('bestatter_price_list_items', 'i')
				->innerJoin('i', 'bestatter_price_lists', 'l', $query->expr()->eq('l.id', 'i.price_list_id'))
				->where($query->expr()->eq('i.price_list_id', $query->createNamedParameter($listId)))
				->andWhere($query->expr()->lte('i.valid_from', $query->createNamedParameter($date)))
				->andWhere($query->expr()->orX($query->expr()->isNull('i.valid_to'), $query->expr()->gte('i.valid_to', $query->createNamedParameter($date))))
				->orderBy('i.valid_from', 'ASC')->executeQuery()->fetchAllAssociative();
			foreach ($rows as $row) $result[(int)$row['article_id']] = ['cents' => (int)$row['price_cents'], 'listKey' => (string)$row['list_key'], 'validFrom' => (string)$row['valid_from']];
		}
		return $result;
	}

	public function priceFor(int $articleId, string $branchKey = '', string $date = ''): ?int {
		return $this->prices($branchKey, $date)[$articleId]['cents'] ?? null;
	}

	private function list(int $id): array {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('*')->from('bestatter_price_lists')->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeQuery()->fetchAssociative();
		if (!$row) throw new \InvalidArgumentException('Die Preisliste wurde nicht gefunden.');
		return $row;
	}

	private function insertList(string $key, string $name, ?string $branchKey): int {
		$now = date('c');
		$insert = $this->db->getQueryBuilder();
		$insert->insert('bestatter_price_lists')->values(['list_key' => $insert->createNamedParameter($key), 'name' => $insert->createNamedParameter($name), 'branch_key' => $insert->createNamedParameter($branchKey), 'active' => $insert->createNamedParameter(true, IQueryBuilder::PARAM_BOOL), 'created_at' => $insert->createNamedParameter($now), 'updated_at' => $insert->createNamedParameter($now)])->executeStatement();
		return (int)$this->db->lastInsertId('bestatter_price_lists');
	}

	private function date(string $value, string $label): string {
		$value = trim($value);
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		if (!$date || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException($label . ' ist kein gültiges Datum.');
		return $value;
	}

	private function previousDay(string $date): string {
		return (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
	}

	private function uid(): string { return $this->userSession->getUser()?->getUID() ?? 'system'; }
}
