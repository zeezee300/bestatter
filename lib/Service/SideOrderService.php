<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IDBConnection;

/** CRUD and numbering for additional orders belonging to one existing case. */
class SideOrderService {
	private const STATUSES = ['ENTWURF', 'BEAUFTRAGT', 'ABGESCHLOSSEN', 'STORNIERT'];
	private const TRANSITIONS = [
		'ENTWURF' => ['BEAUFTRAGT', 'STORNIERT'],
		'BEAUFTRAGT' => ['ABGESCHLOSSEN', 'STORNIERT'],
		'ABGESCHLOSSEN' => [],
		'STORNIERT' => [],
	];

	public function __construct(private IDBConnection $db, private CaseService $cases, private AuditService $audit) {}

	public function all(int $caseId): array {
		$this->cases->getCase($caseId);
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_side_orders')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		return array_map(fn(array $row): array => $this->map($row), $rows);
	}

	public function get(int $id): array {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('*')->from('bestatter_side_orders')
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->executeQuery()->fetchAssociative();
		if ($row === false) throw new \InvalidArgumentException('Nebenauftrag wurde nicht gefunden.');
		return $this->map($row);
	}

	public function create(int $caseId, array $input = []): array {
		$case = $this->cases->getCase($caseId);
		if (in_array(strtoupper((string)$case['status']), ['ABGESCHLOSSEN', 'STORNIERT'], true)) {
			throw new \InvalidArgumentException('In einem abgeschlossenen oder stornierten Fall kann kein Nebenauftrag angelegt werden. Bitte öffnen Sie den Fall zuvor kontrolliert wieder.');
		}
		$values = $this->values($case, $input);
		$now = date('c');
		$query = $this->db->getQueryBuilder();
		$query->insert('bestatter_side_orders')->values([
			'case_id' => $query->createNamedParameter($caseId),
			'side_order_number' => $query->createNamedParameter($this->nextNumber($case['caseNumber'])),
			'first_name' => $query->createNamedParameter($values['firstName']),
			'last_name' => $query->createNamedParameter($values['lastName']),
			'street' => $query->createNamedParameter($values['street']),
			'postal_city' => $query->createNamedParameter($values['postalCity']),
			'country' => $query->createNamedParameter($values['country']),
			'phone' => $query->createNamedParameter($values['phone']),
			'email' => $query->createNamedParameter($values['email']),
			'relation' => $query->createNamedParameter($values['relation']),
			'status' => $query->createNamedParameter('ENTWURF'),
			'required_by' => $query->createNamedParameter($values['requiredBy']),
			'created_at' => $query->createNamedParameter($now),
			'updated_at' => $query->createNamedParameter($now),
		])->executeStatement();
		$id = (int)$this->db->lastInsertId('bestatter_side_orders');
		$created = $this->get($id);
		$this->audit->log($caseId, 'SIDE_ORDER', $id, 'CREATED', null, $created);
		return $created;
	}

	public function update(int $id, array $input = []): array {
		$before = $this->get($id);
		if ($before['status'] !== 'ENTWURF') throw new \InvalidArgumentException('Stammdaten eines Nebenauftrags können nur im Status ENTWURF geändert werden.');
		$case = $this->cases->getCase($before['caseId']);
		$values = $this->values($case, $input, $before);
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_side_orders')
			->set('first_name', $query->createNamedParameter($values['firstName']))
			->set('last_name', $query->createNamedParameter($values['lastName']))
			->set('street', $query->createNamedParameter($values['street']))
			->set('postal_city', $query->createNamedParameter($values['postalCity']))
			->set('country', $query->createNamedParameter($values['country']))
			->set('phone', $query->createNamedParameter($values['phone']))
			->set('email', $query->createNamedParameter($values['email']))
			->set('relation', $query->createNamedParameter($values['relation']))
			->set('required_by', $query->createNamedParameter($values['requiredBy']))
			->set('updated_at', $query->createNamedParameter(date('c')))
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeStatement();
		$after = $this->get($id);
		$this->audit->log($before['caseId'], 'SIDE_ORDER', $id, 'UPDATED', $before, $after);
		return $after;
	}

	public function cancel(int $id): array {
		return $this->transition($id, 'STORNIERT');
	}

	public function transition(int $id, string $target): array {
		$before = $this->get($id);
		$target = strtoupper(trim($target));
		if (!in_array($target, self::STATUSES, true) || !in_array($target, self::TRANSITIONS[$before['status']] ?? [], true)) {
			throw new \InvalidArgumentException('Dieser Statuswechsel ist für den Nebenauftrag nicht zulässig.');
		}
		if ($target === 'BEAUFTRAGT') $this->assertCommissionable($before);
		if ($target === 'ABGESCHLOSSEN') $this->assertClosable($before);
		if ($target === 'STORNIERT') $this->assertCancellable($before);
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_side_orders')
			->set('status', $query->createNamedParameter($target))
			->set('updated_at', $query->createNamedParameter(date('c')))
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->andWhere($query->expr()->eq('status', $query->createNamedParameter($before['status'])))
			->executeStatement();
		$after = $this->get($id);
		$this->audit->log($before['caseId'], 'SIDE_ORDER', $id, 'STATUS_' . $target, $before, $after);
		return $after;
	}

	private function assertCommissionable(array $order): void {
		$missing = [];
		foreach (['firstName' => 'Vorname', 'lastName' => 'Nachname', 'street' => 'Straße', 'postalCity' => 'PLZ / Ort', 'country' => 'Land'] as $field => $label) {
			if (trim((string)($order[$field] ?? '')) === '') $missing[] = $label;
		}
		if ($missing !== []) throw new \InvalidArgumentException('Vor der Beauftragung fehlen: ' . implode(', ', $missing) . '.');
		$query = $this->db->getQueryBuilder();
		$positions = (int)$query->select($query->func()->count('*', 'count'))->from('bestatter_case_services')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($order['caseId'])))
			->andWhere($query->expr()->eq('side_order_id', $query->createNamedParameter($order['id'])))
			->andWhere($query->expr()->neq('service_status', $query->createNamedParameter('STORNIERT')))
			->executeQuery()->fetchOne();
		if ($positions === 0) throw new \InvalidArgumentException('Vor der Beauftragung muss mindestens eine fehlerfreie Position erfasst werden.');
	}

	private function assertClosable(array $order): void {
		$query = $this->db->getQueryBuilder();
		$completedFinalInvoices = (int)$query->select($query->func()->count('*', 'count'))->from('bestatter_invoices')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($order['caseId'])))
			->andWhere($query->expr()->eq('side_order_id', $query->createNamedParameter($order['id'])))
			->andWhere($query->expr()->eq('invoice_type', $query->createNamedParameter('FINAL')))
			->andWhere($query->expr()->orX(
				$query->expr()->eq('status', $query->createNamedParameter('FREIGEGEBEN')),
				$query->expr()->eq('status', $query->createNamedParameter('VERSENDET')),
				$query->expr()->eq('status', $query->createNamedParameter('TEILBEZAHLT')),
				$query->expr()->eq('status', $query->createNamedParameter('BEZAHLT')),
			))
			->executeQuery()->fetchOne();
		if ($completedFinalInvoices === 0) throw new \InvalidArgumentException('Der Nebenauftrag kann erst nach Freigabe seiner Schlussrechnung abgeschlossen werden.');
	}

	private function assertCancellable(array $order): void {
		$query = $this->db->getQueryBuilder();
		$activeInvoices = (int)$query->select($query->func()->count('*', 'count'))->from('bestatter_invoices')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($order['caseId'])))
			->andWhere($query->expr()->eq('side_order_id', $query->createNamedParameter($order['id'])))
			->andWhere($query->expr()->neq('status', $query->createNamedParameter('STORNIERT')))
			->executeQuery()->fetchOne();
		if ($activeInvoices > 0) throw new \InvalidArgumentException('Vor der Stornierung müssen alle Rechnungen dieses Nebenauftrags storniert sein.');
	}

	private function values(array $case, array $input, array $fallback = []): array {
		$master = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$requiredBy = array_key_exists('requiredBy', $input) ? trim((string)$input['requiredBy']) : (string)($fallback['requiredBy'] ?? $this->funeralEvent($master));
		$lastName = trim((string)($input['lastName'] ?? $fallback['lastName'] ?? ''));
		if ($lastName === '') throw new \InvalidArgumentException('Der Nachname des Nebenauftraggebers ist erforderlich.');
		return [
			'firstName' => trim((string)($input['firstName'] ?? $fallback['firstName'] ?? '')),
			'lastName' => $lastName,
			'street' => trim((string)($input['street'] ?? $fallback['street'] ?? '')),
			'postalCity' => trim((string)($input['postalCity'] ?? $fallback['postalCity'] ?? '')),
			'country' => trim((string)($input['country'] ?? $fallback['country'] ?? 'Deutschland')),
			'phone' => trim((string)($input['phone'] ?? $fallback['phone'] ?? '')),
			'email' => trim((string)($input['email'] ?? $fallback['email'] ?? '')),
			'relation' => trim((string)($input['relation'] ?? $fallback['relation'] ?? 'Sonstige')),
			'requiredBy' => $requiredBy !== '' ? $requiredBy : null,
		];
	}

	private function nextNumber(string $caseNumber): string {
		$prefix = $caseNumber . '-N';
		$query = $this->db->getQueryBuilder();
		$numbers = $query->select('side_order_number')->from('bestatter_side_orders')
			->where($query->expr()->like('side_order_number', $query->createNamedParameter($prefix . '%')))
			->executeQuery()->fetchFirstColumn();
		$highest = 0;
		foreach ($numbers as $number) if (preg_match('/-N(\d+)$/', (string)$number, $match)) $highest = max($highest, (int)$match[1]);
		return $prefix . ($highest + 1);
	}

	private function funeralEvent(array $master): ?string {
		$date = trim((string)($master['funeral_event_date'] ?? $master['funeral_event']['date'] ?? ''));
		$time = trim((string)($master['funeral_event_time'] ?? $master['funeral_event']['time'] ?? ''));
		return $date === '' ? null : $date . ($time !== '' ? 'T' . $time : '');
	}

	private function map(array $row): array {
		return ['id' => (int)$row['id'], 'caseId' => (int)$row['case_id'], 'sideOrderNumber' => (string)$row['side_order_number'], 'firstName' => (string)($row['first_name'] ?? ''), 'lastName' => (string)$row['last_name'], 'street' => (string)($row['street'] ?? ''), 'postalCity' => (string)($row['postal_city'] ?? ''), 'country' => (string)($row['country'] ?? ''), 'phone' => (string)($row['phone'] ?? ''), 'email' => (string)($row['email'] ?? ''), 'relation' => (string)($row['relation'] ?? ''), 'status' => (string)$row['status'], 'requiredBy' => $row['required_by'] !== null ? (string)$row['required_by'] : '', 'createdAt' => (string)$row['created_at'], 'updatedAt' => (string)$row['updated_at']];
	}
}
