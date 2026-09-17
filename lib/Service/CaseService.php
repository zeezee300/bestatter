<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

class CaseService {
	private const NUMBER_RETRIES = 8;

	public function __construct(
		private IDBConnection $db,
		private FolderService $folders,
		private AuditService $audit,
		private CommercialStateService $commercialState,
		private CustomizingService $customizing,
		private RecordService $records,
		private SurchargeRuleService $surcharges,
		private TeamService $team,
	) {}

	public function dashboard(): array {
		return [
			'openCases' => $this->count('status <> :closed', ['closed' => 'ABGESCHLOSSEN']),
			'newCases' => $this->count('status = :status', ['status' => 'NEU']),
			'customizingReady' => true,
		];
	}

	public function listCases(): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query
			->select('*')
			->from('bestatter_cases')
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(100)
			->executeQuery()
			->fetchAllAssociative();

		return $this->withSideOrderSummaries(array_map([$this, 'mapCase'], $rows));
	}

	public function searchCases(string $search = '', string $status = 'ALL', string $branch = 'ALL', string $responsible = 'ALL', int $limit = 25, int $offset = 0, string $sideOrders = 'ALL'): array {
		$status = strtoupper(trim($status));
		if (!in_array($status, ['ALL', 'OPEN', 'NEU', 'IN_BEARBEITUNG', 'ABGESCHLOSSEN', 'STORNIERT'], true)) {
			throw new \InvalidArgumentException('Der gewählte Fallstatus ist ungültig.');
		}
		$search = mb_substr(trim($search), 0, 100);
		$branch = mb_substr(trim($branch), 0, 100);
		$responsible = mb_substr(trim($responsible), 0, 100);
		$sideOrders = strtoupper(trim($sideOrders));
		if (!in_array($sideOrders, ['ALL', 'HAS', 'OPEN', 'BILLING_OPEN', 'DONE', 'NONE'], true)) {
			throw new \InvalidArgumentException('Der gewählte Nebenauftragsfilter ist ungültig.');
		}
		$limit = max(10, min(100, $limit));
		$offset = max(0, $offset);
		$searchSideOrderCaseIds = $search !== '' ? $this->sideOrderCaseIdsForSearch($search) : [];
		$filterSideOrderCaseIds = $sideOrders !== 'ALL' ? $this->sideOrderCaseIdsForFilter($sideOrders) : [];

		$query = $this->db->getQueryBuilder();
		$query->select('*')->from('bestatter_cases');
		$this->applySearchConditions($query, $search, $status, $branch, $responsible, $searchSideOrderCaseIds, $sideOrders, $filterSideOrderCaseIds);
		$rows = $query->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')
			->setFirstResult($offset)->setMaxResults($limit)->executeQuery()->fetchAllAssociative();

		$count = $this->db->getQueryBuilder();
		$count->select($count->func()->count('*', 'count'))->from('bestatter_cases');
		$this->applySearchConditions($count, $search, $status, $branch, $responsible, $searchSideOrderCaseIds, $sideOrders, $filterSideOrderCaseIds);

		return [
			'items' => $this->withSideOrderSummaries(array_map([$this, 'mapCase'], $rows), $search),
			'total' => (int)$count->executeQuery()->fetchOne(),
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	public function createCase(array $values): array {
		$data = is_array($values['masterData'] ?? null) ? $values['masterData'] : [];
		$firstName = trim((string)($data['first_name'] ?? $values['firstName'] ?? ''));
		$lastName = trim((string)($data['last_name'] ?? $values['lastName'] ?? ''));
		if ($firstName === '' || $lastName === '') {
			throw new \InvalidArgumentException('Vor- und Nachname sind Pflichtfelder.');
		}
		$data = $this->normalizeMasterDates($data);
		$data = $this->normalizeBurialChoices($data);
		$responsibleUid = trim((string)($data['responsible_employee'] ?? $values['responsibleEmployee'] ?? ''));
		$data['responsible_employee'] = $this->team->assignee($responsibleUid)['uid'];
		$validationWarnings = $this->validateFamilyData($data);

		$creationToken = trim((string)($values['creationToken'] ?? ''));
		if ($creationToken !== '' && !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $creationToken)) {
			throw new \InvalidArgumentException('Die technische Anlagekennung ist ungültig.');
		}
		if ($creationToken !== '') {
			$existing = $this->findByCreationToken($creationToken);
			if ($existing !== null) {
				return $existing + ['idempotent' => true];
			}
		}

		$data['first_name'] = $firstName;
		$data['last_name'] = $lastName;
		$year = date('Y');
		$lastError = null;

		for ($attempt = 0; $attempt < self::NUMBER_RETRIES; $attempt++) {
			$number = $this->nextCaseNumber($year);
			$transactionOpen = false;
			try {
				$this->db->beginTransaction();
				$transactionOpen = true;
				$id = $this->insertCase($number, $creationToken, $firstName, $lastName, $data, $values);
				if ($data['with_funeral_ceremony'] === '1') $this->ensureCeremonyTask($id);
				$this->db->commit();
				$transactionOpen = false;
				$case = $this->getCase($id);
				$case['validationWarnings'] = $validationWarnings;
				$case['folder'] = $this->createFolderSafely($number);
				$case['idempotent'] = false;
				$this->audit->log($id, 'CASE', $id, 'CREATED', null, $case);
				return $case;
			} catch (\Throwable $error) {
				if ($transactionOpen) $this->db->rollBack();
				$lastError = $error;
				if ($creationToken !== '') {
					$existing = $this->findByCreationToken($creationToken);
					if ($existing !== null) {
						return $existing + ['idempotent' => true];
					}
				}
			}
		}

		throw new \RuntimeException('Die nächste Fallnummer konnte nach mehreren Versuchen nicht reserviert werden.', 0, $lastError);
	}

	public function getCase(int $id): array {
		$query = $this->db->getQueryBuilder();
		$row = $query
			->select('*')
			->from('bestatter_cases')
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->executeQuery()
			->fetchAssociative();
		if ($row === false) {
			throw new \InvalidArgumentException('Fall wurde nicht gefunden.');
		}
		return $this->mapCase($row);
	}

	public function findCaseByNumber(string $caseNumber): ?array {
		if ($caseNumber === '') {
			return null;
		}
		$query = $this->db->getQueryBuilder();
		$row = $query
			->select('*')
			->from('bestatter_cases')
			->where($query->expr()->eq('case_number', $query->createNamedParameter($caseNumber)))
			->executeQuery()
			->fetchAssociative();
		return $row === false ? null : $this->mapCase($row);
	}

	public function caseNumber(int $caseId): string {
		return $caseId > 0 ? (string)$this->getCase($caseId)['caseNumber'] : '';
	}

	public function updateMasterData(int $id, array $data): array {
		$case = $this->getCase($id);
		$responsibleUid = trim((string)($data['responsible_employee'] ?? $case['responsibleEmployee']));
		if ($responsibleUid !== (string)$case['responsibleEmployee']) {
			$this->team->requireBestatterAdmin();
			if ($responsibleUid !== '') $this->team->assignee($responsibleUid);
		}
		$data['responsible_employee'] = $responsibleUid;
		$commercialState = $this->commercialState->state($id);
		$requestedOrderStatus = mb_strtolower(trim((string)($data['order_status'] ?? 'Entwurf')));
		if ($commercialState['editable'] && in_array($requestedOrderStatus, ['beauftragt', 'kva versendet'], true)) {
			throw new \InvalidArgumentException('Der Statuswechsel ist nur über die geprüfte Festschreibung des KVA beziehungsweise Auftrags zulässig.');
		}
		if (!$commercialState['editable']) $data['order_status'] = $commercialState['effectiveOrderStatus'];
		$firstName = trim((string)($data['first_name'] ?? $case['firstName']));
		$lastName = trim((string)($data['last_name'] ?? $case['lastName']));
		if ($firstName === '' || $lastName === '') {
			throw new \InvalidArgumentException('Vor- und Nachname sind Pflichtfelder.');
		}
		$data = $this->normalizeMasterDates($data);
		$data = $this->normalizeBurialChoices($data, $case['masterData']);
		if (!$commercialState['editable']) {
			foreach (['surcharge_pickup_rule_key', 'surcharge_height_rule_key', 'surcharge_weight_rule_key', 'surcharge_other_rule_key'] as $field) {
				if ($data[$field] !== (string)($case['masterData'][$field] ?? '')) throw new \InvalidArgumentException('Zuschläge nach Beauftragung bitte als begründeten Vertragsnachtrag in den Positionen erfassen.');
			}
		}
		$validationWarnings = $this->validateFamilyData($data);

		$data['first_name'] = $firstName;
		$data['last_name'] = $lastName;
		$dateOfDeath = $this->normalizeDateOfDeath((string)($data['date_of_death'] ?? $case['dateOfDeath'] ?? ''));
		$data['date_of_death'] = $dateOfDeath ?? '';
		$requestedCaseStatus = strtoupper(trim((string)($data['status'] ?? $case['status'])));
		if ($requestedCaseStatus === 'ABGESCHLOSSEN' && strtoupper((string)$case['status']) !== 'ABGESCHLOSSEN') {
			$this->assertClosable($id);
		}
		$activateCeremony = $data['with_funeral_ceremony'] === '1' && (string)($case['masterData']['with_funeral_ceremony'] ?? '') !== '1';
		$this->db->beginTransaction();
		try {
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_cases')
			->set('first_name', $query->createNamedParameter($firstName))
			->set('last_name', $query->createNamedParameter($lastName))
			->set('date_of_death', $query->createNamedParameter($dateOfDeath))
			->set('funeral_type', $query->createNamedParameter((string)($data['funeral_type'] ?? '')))
			->set('burial_variant_code', $query->createNamedParameter($data['burial_variant_code'] ?: null))
			->set('with_funeral_ceremony', $query->createNamedParameter($data['with_funeral_ceremony'] === '' ? null : (int)$data['with_funeral_ceremony']))
			->set('pickup_time', $query->createNamedParameter($data['pickup_time'] ?: null))
			->set('body_height_cm', $query->createNamedParameter($data['body_height_cm'] === '' ? null : (int)$data['body_height_cm']))
			->set('body_weight_kg', $query->createNamedParameter($data['body_weight_kg'] === '' ? null : (int)$data['body_weight_kg']))
			->set('status', $query->createNamedParameter((string)($data['status'] ?? $case['status'])))
			->set('branch', $query->createNamedParameter((string)($data['branch'] ?? '')))
			->set('responsible_employee', $query->createNamedParameter((string)($data['responsible_employee'] ?? '')))
			->set('master_data', $query->createNamedParameter(json_encode($data, JSON_THROW_ON_ERROR)))
			->set('updated_at', $query->createNamedParameter($this->now()))
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))
			->executeStatement();
		if ($activateCeremony) $this->ensureCeremonyTask($id);
		$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		$updated = $this->getCase($id);
		$updated['validationWarnings'] = $validationWarnings;
		$this->audit->log($id, 'CASE', $id, 'UPDATED', $case, $updated);
		return $updated;
	}

	/** @throws \InvalidArgumentException when open side orders still block the case closure. */
	public function assertClosable(int $caseId): void {
		$query = $this->db->getQueryBuilder();
		$orders = $query->select('id', 'side_order_number', 'first_name', 'last_name', 'status')
			->from('bestatter_side_orders')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->andWhere($query->expr()->in('status', $query->createNamedParameter(['ENTWURF', 'BEAUFTRAGT'], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		if ($orders === []) return;

		$released = $this->releasedFinalInvoiceSideOrderIds($caseId);
		$blockers = array_map(static function (array $order) use ($released): string {
			$name = trim((string)($order['first_name'] ?? '') . ' ' . (string)$order['last_name']);
			$status = (string)$order['status'];
			$reason = $status === 'ENTWURF'
				? 'noch im Entwurf'
				: (isset($released[(int)$order['id']]) ? 'noch nicht als abgeschlossen markiert' : 'Schlussrechnung noch nicht freigegeben');
			return trim((string)$order['side_order_number'] . ' · ' . $name, ' ·') . ' · ' . ($status === 'ENTWURF' ? 'Entwurf' : 'Beauftragt') . ' · ' . $reason;
		}, $orders);
		throw new \InvalidArgumentException('Der Fall kann noch nicht abgeschlossen werden. Offene Nebenaufträge: ' . implode('; ', $blockers) . '.');
	}

	public function deleteCase(int $id): void {
		$case = $this->getCase($id);
		$this->db->beginTransaction();
		try {
			$query = $this->db->getQueryBuilder();
			$query->delete('bestatter_capture_imports')->where($query->expr()->eq('case_id', $query->createNamedParameter($id)))->executeStatement();
			$externalQuery = $this->db->getQueryBuilder();
			$externalIds = $externalQuery->select('id')->from('bestatter_external_documents')->where($externalQuery->expr()->eq('case_id', $externalQuery->createNamedParameter($id)))->executeQuery()->fetchFirstColumn();
			foreach ($externalIds as $externalId) {
				$query = $this->db->getQueryBuilder();
				$query->delete('bestatter_integration_jobs')->where($query->expr()->eq('object_id', $query->createNamedParameter((int)$externalId)))->executeStatement();
			}
			$query = $this->db->getQueryBuilder();
			$query->delete('bestatter_external_documents')->where($query->expr()->eq('case_id', $query->createNamedParameter($id)))->executeStatement();
			$invoiceQuery = $this->db->getQueryBuilder();
			$invoiceIds = $invoiceQuery->select('id')->from('bestatter_invoices')->where($invoiceQuery->expr()->eq('case_id', $invoiceQuery->createNamedParameter($id)))->executeQuery()->fetchFirstColumn();
			foreach ($invoiceIds as $invoiceId) {
				$query = $this->db->getQueryBuilder();
				$query->delete('bestatter_invoice_items')->where($query->expr()->eq('invoice_id', $query->createNamedParameter((int)$invoiceId)))->executeStatement();
			}
			$incomingQuery = $this->db->getQueryBuilder();
			$incomingIds = $incomingQuery->select('id')->from('bestatter_incoming_invoices')->where($incomingQuery->expr()->eq('case_id', $incomingQuery->createNamedParameter($id)))->executeQuery()->fetchFirstColumn();
			foreach ($incomingIds as $incomingId) {
				$query = $this->db->getQueryBuilder();
				$query->delete('bestatter_incoming_items')->where($query->expr()->eq('incoming_invoice_id', $query->createNamedParameter((int)$incomingId)))->executeStatement();
			}
			foreach (['bestatter_case_automation', 'bestatter_audit_log', 'bestatter_mail_outbox', 'bestatter_invoices', 'bestatter_commercial_docs', 'bestatter_incoming_invoices', 'bestatter_case_services', 'bestatter_records'] as $table) {
				$query = $this->db->getQueryBuilder();
				$query->delete($table)
					->where($query->expr()->eq('case_id', $query->createNamedParameter($id)))
					->executeStatement();
			}
			$query = $this->db->getQueryBuilder();
			$query->delete('bestatter_cases')
				->where($query->expr()->eq('id', $query->createNamedParameter($id)))
				->executeStatement();
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		$this->audit->logSystem('CASE', 'DELETED', ['caseId' => $id, 'caseNumber' => $case['caseNumber']]);
	}

	public function ensureFolder(int $id): array {
		$case = $this->getCase($id);
		return $this->folders->createCaseFolder($case['caseNumber']);
	}

	private function insertCase(string $number, string $creationToken, string $firstName, string $lastName, array $data, array $values): int {
		$query = $this->db->getQueryBuilder();
		$now = $this->now();
		$dateOfDeath = $this->normalizeDateOfDeath((string)($data['date_of_death'] ?? $values['dateOfDeath'] ?? ''));
		$data['date_of_death'] = $dateOfDeath ?? '';
		$query->insert('bestatter_cases')->values([
			'case_number' => $query->createNamedParameter($number),
			'creation_token' => $query->createNamedParameter($creationToken !== '' ? $creationToken : null),
			'first_name' => $query->createNamedParameter($firstName),
			'last_name' => $query->createNamedParameter($lastName),
			'date_of_death' => $query->createNamedParameter($dateOfDeath),
			'funeral_type' => $query->createNamedParameter((string)($data['funeral_type'] ?? $values['funeralType'] ?? '')),
			'burial_variant_code' => $query->createNamedParameter($data['burial_variant_code'] ?: null),
			'with_funeral_ceremony' => $query->createNamedParameter($data['with_funeral_ceremony'] === '' ? null : (int)$data['with_funeral_ceremony']),
			'pickup_time' => $query->createNamedParameter($data['pickup_time'] ?: null),
			'body_height_cm' => $query->createNamedParameter($data['body_height_cm'] === '' ? null : (int)$data['body_height_cm']),
			'body_weight_kg' => $query->createNamedParameter($data['body_weight_kg'] === '' ? null : (int)$data['body_weight_kg']),
			'status' => $query->createNamedParameter((string)($data['status'] ?? 'NEU')),
			'branch' => $query->createNamedParameter((string)($data['branch'] ?? $values['branch'] ?? '')),
			'responsible_employee' => $query->createNamedParameter((string)($data['responsible_employee'] ?? $values['responsibleEmployee'] ?? '')),
			'master_data' => $query->createNamedParameter(json_encode($data, JSON_THROW_ON_ERROR)),
			'created_at' => $query->createNamedParameter($now),
			'updated_at' => $query->createNamedParameter($now),
		])->executeStatement();
		return (int)$this->db->lastInsertId('bestatter_cases');
	}

	private function nextCaseNumber(string $year): string {
		$query = $this->db->getQueryBuilder();
		$rows = $query
			->select('case_number')
			->from('bestatter_cases')
			->where($query->expr()->like('case_number', $query->createNamedParameter($year . '-%')))
			->executeQuery()
			->fetchAllAssociative();
		$highest = 0;
		foreach ($rows as $row) {
			$number = (string)($row['case_number'] ?? '');
			if (preg_match('/^' . preg_quote($year, '/') . '-(\d+)$/', $number, $match)) {
				$highest = max($highest, (int)$match[1]);
			}
		}
		return sprintf('%s-%04d', $year, $highest + 1);
	}

	private function findByCreationToken(string $token): ?array {
		$query = $this->db->getQueryBuilder();
		$id = $query
			->select('id')
			->from('bestatter_cases')
			->where($query->expr()->eq('creation_token', $query->createNamedParameter($token)))
			->executeQuery()
			->fetchOne();
		return $id === false ? null : $this->getCase((int)$id);
	}

	private function createFolderSafely(string $number): array {
		try {
			return $this->folders->createCaseFolder($number);
		} catch (\Throwable $error) {
			return ['status' => 'FEHLER', 'message' => $error->getMessage()];
		}
	}

	private function normalizeDateOfDeath(string $value): ?string {
		$value = trim($value);
		if ($value === '') {
			return null;
		}
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
		if ($date === false || $date->format('Y-m-d') !== $value) {
			throw new \InvalidArgumentException('Das Sterbedatum muss ein gültiges Datum im Format JJJJ-MM-TT sein.');
		}
		return $value;
	}

	private function normalizeMasterDates(array $data): array {
		$dateFields = [
			'spouse_date_of_birth' => 'Das Geburtsdatum des Ehepartners / der Ehepartnerin',
			'spouse_date_of_death' => 'Das Todesdatum des Ehepartners / der Ehepartnerin',
			'marriage_date' => 'Das Datum der Eheschließung',
			'partnership_date' => 'Das Datum der Begründung der Lebenspartnerschaft',
			'divorce_date' => 'Das Datum der Scheidung / Aufhebung',
		];
		foreach ($dateFields as $field => $label) {
			if (array_key_exists($field, $data)) {
				$data[$field] = $this->normalizeOptionalDate((string)$data[$field], $label);
			}
		}
		return $data;
	}

	private function normalizeOptionalDate(string $value, string $label): string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
		if ($date === false || $date->format('Y-m-d') !== $value) {
			throw new \InvalidArgumentException($label . ' muss ein gültiges Datum im Format JJJJ-MM-TT sein.');
		}
		return $value;
	}

	private function validateFamilyData(array $data): array {
		$birth = trim((string)($data['date_of_birth'] ?? ''));
		$spouseBirth = trim((string)($data['spouse_date_of_birth'] ?? ''));
		$spouseDeath = trim((string)($data['spouse_date_of_death'] ?? ''));
		$marriage = trim((string)($data['marriage_date'] ?? ''));
		$divorce = trim((string)($data['divorce_date'] ?? ''));
		if ($spouseBirth !== '' && $spouseDeath !== '' && $spouseDeath < $spouseBirth) {
			throw new \InvalidArgumentException('Das Todesdatum des Ehepartners / der Ehepartnerin darf nicht vor dem Geburtsdatum liegen.');
		}
		if ($marriage !== '' && $birth !== '' && $marriage < $birth) {
			throw new \InvalidArgumentException('Das Datum der Eheschließung darf nicht vor dem Geburtsdatum der verstorbenen Person liegen.');
		}
		if ($marriage !== '' && $spouseBirth !== '' && $marriage < $spouseBirth) {
			throw new \InvalidArgumentException('Das Datum der Eheschließung darf nicht vor dem Geburtsdatum des Ehepartners / der Ehepartnerin liegen.');
		}
		if ($divorce !== '' && $marriage !== '' && $divorce < $marriage) {
			throw new \InvalidArgumentException('Das Datum der Scheidung / Aufhebung darf nicht vor dem Datum der Eheschließung liegen.');
		}

		$status = mb_strtolower(trim((string)($data['civil_status'] ?? '')));
		$familyFields = ['spouse_first_name', 'spouse_last_name', 'spouse_date_of_birth', 'spouse_birth_place', 'spouse_residence', 'spouse_date_of_death', 'spouse_death_place', 'marriage_date', 'marriage_place', 'partnership_date', 'divorce_date'];
		$hasFamilyDetails = false;
		foreach ($familyFields as $field) {
			if (trim((string)($data[$field] ?? '')) !== '') { $hasFamilyDetails = true; break; }
		}
		$warnings = [];
		if ($status === 'ledig' && $hasFamilyDetails) {
			$warnings[] = 'Der Familienstand ist „ledig“, es sind aber Ehe-/Partnerschaftsangaben vorhanden.';
		}
		if ($status === 'verwitwet' && $spouseDeath === '') {
			$warnings[] = 'Bei „verwitwet“ ist noch kein Todesdatum des Ehepartners / der Ehepartnerin eingetragen.';
		}
		return $warnings;
	}

	private function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
	}

	private function count(string $where, array $params): int {
		$query = $this->db->getQueryBuilder();
		$query->select($query->func()->count('*', 'count'))->from('bestatter_cases')->where($where);
		foreach ($params as $key => $value) {
			$query->setParameter($key, $value);
		}
		return (int)$query->executeQuery()->fetchOne();
	}

	private function applySearchConditions(mixed $query, string $search, string $status, string $branch, string $responsible, array $searchSideOrderCaseIds = [], string $sideOrders = 'ALL', array $filterSideOrderCaseIds = []): void {
		if ($search !== '') {
			foreach ($this->searchTerms($search) as $searchTerm) {
				$term = $query->createNamedParameter('%' . $searchTerm . '%');
				$conditions = [
					$query->expr()->like('case_number', $term),
					$query->expr()->like('first_name', $term),
					$query->expr()->like('last_name', $term),
					$query->expr()->like('status', $term),
					$query->expr()->like('branch', $term),
				];
				if ($searchSideOrderCaseIds !== []) $conditions[] = $query->expr()->in('id', $query->createNamedParameter($searchSideOrderCaseIds, IQueryBuilder::PARAM_INT_ARRAY));
				$query->andWhere($query->expr()->orX(...$conditions));
			}
		}
		if ($status === 'OPEN') {
			$query->andWhere($query->expr()->neq('status', $query->createNamedParameter('ABGESCHLOSSEN')));
			$query->andWhere($query->expr()->neq('status', $query->createNamedParameter('STORNIERT')));
		} elseif ($status !== 'ALL') {
			$query->andWhere($query->expr()->eq('status', $query->createNamedParameter($status)));
		}
		if ($branch !== '' && $branch !== 'ALL') $query->andWhere($query->expr()->eq('branch', $query->createNamedParameter($branch)));
		if ($responsible !== '' && $responsible !== 'ALL') $query->andWhere($query->expr()->eq('responsible_employee', $query->createNamedParameter($responsible)));
		if ($sideOrders !== 'ALL') {
			if ($sideOrders === 'NONE') {
				if ($filterSideOrderCaseIds !== []) $query->andWhere($query->expr()->notIn('id', $query->createNamedParameter($filterSideOrderCaseIds, IQueryBuilder::PARAM_INT_ARRAY)));
			} elseif ($filterSideOrderCaseIds === []) {
				$query->andWhere($query->expr()->eq('id', $query->createNamedParameter(-1)));
			} else {
				$query->andWhere($query->expr()->in('id', $query->createNamedParameter($filterSideOrderCaseIds, IQueryBuilder::PARAM_INT_ARRAY)));
			}
		}
	}

	private function sideOrderCaseIdsForSearch(string $search): array {
		$query = $this->db->getQueryBuilder();
		$query->selectDistinct('case_id')->from('bestatter_side_orders');
		foreach ($this->searchTerms($search) as $searchTerm) {
			$term = $query->createNamedParameter('%' . $searchTerm . '%');
			$query->andWhere($query->expr()->orX(
				$query->expr()->like('side_order_number', $term),
				$query->expr()->like('first_name', $term),
				$query->expr()->like('last_name', $term),
				$query->expr()->like('status', $term),
			));
		}
		$rows = $query->executeQuery()->fetchFirstColumn();
		return array_values(array_unique(array_map('intval', $rows)));
	}

	private function searchTerms(string $search): array {
		$terms = preg_split('/\s+/u', trim($search)) ?: [];
		return array_slice(array_values(array_filter($terms, static fn(string $term): bool => $term !== '')), 0, 10);
	}

	private function sideOrderCaseIdsForFilter(string $filter): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('case_id', 'id', 'status')->from('bestatter_side_orders')->executeQuery()->fetchAllAssociative();
		$byCase = [];
		foreach ($rows as $row) $byCase[(int)$row['case_id']][] = $row;
		$released = $filter === 'BILLING_OPEN' ? $this->releasedFinalInvoiceSideOrderIds() : [];
		$result = [];
		foreach ($byCase as $caseId => $orders) {
			$matches = match ($filter) {
				'HAS', 'NONE' => true,
				'OPEN' => count(array_filter($orders, static fn(array $row): bool => in_array((string)$row['status'], ['ENTWURF', 'BEAUFTRAGT'], true))) > 0,
				'BILLING_OPEN' => count(array_filter($orders, static fn(array $row): bool => (string)$row['status'] === 'BEAUFTRAGT' && !isset($released[(int)$row['id']]))) > 0,
				'DONE' => count(array_filter($orders, static fn(array $row): bool => in_array((string)$row['status'], ['ENTWURF', 'BEAUFTRAGT'], true))) === 0,
				default => false,
			};
			if ($matches) $result[] = $caseId;
		}
		return $result;
	}

	private function releasedFinalInvoiceSideOrderIds(?int $caseId = null): array {
		$query = $this->db->getQueryBuilder();
		$query->selectDistinct('side_order_id')->from('bestatter_invoices')
			->where($query->expr()->eq('invoice_type', $query->createNamedParameter('FINAL')))
			->andWhere($query->expr()->in('status', $query->createNamedParameter(['FREIGEGEBEN', 'VERSENDET', 'TEILBEZAHLT', 'BEZAHLT'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($query->expr()->isNotNull('side_order_id'));
		if ($caseId !== null) $query->andWhere($query->expr()->eq('case_id', $query->createNamedParameter($caseId)));
		$ids = $query->executeQuery()->fetchFirstColumn();
		return array_fill_keys(array_map('intval', $ids), true);
	}

	private function withSideOrderSummaries(array $cases, string $search = ''): array {
		$caseIds = array_values(array_filter(array_map(static fn(array $case): int => (int)$case['id'], $cases)));
		if ($caseIds === []) return $cases;
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('id', 'case_id', 'side_order_number', 'first_name', 'last_name', 'status', 'required_by')
			->from('bestatter_side_orders')
			->where($query->expr()->in('case_id', $query->createNamedParameter($caseIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('case_id', 'ASC')->addOrderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		$released = $this->releasedFinalInvoiceSideOrderIds();
		$byCase = [];
		$needle = mb_strtolower(trim($search));
		foreach ($rows as $row) {
			$status = (string)$row['status'];
			$id = (int)$row['id'];
			$customer = trim((string)($row['first_name'] ?? '') . ' ' . (string)$row['last_name']);
			$haystack = mb_strtolower(implode(' ', [$row['side_order_number'], $customer, $status]));
			$byCase[(int)$row['case_id']][] = [
				'id' => $id, 'sideOrderNumber' => (string)$row['side_order_number'], 'customerName' => $customer,
				'status' => $status, 'requiredBy' => (string)($row['required_by'] ?? ''), 'matched' => $needle !== '' && str_contains($haystack, $needle),
				'nextAction' => $status === 'ENTWURF' ? 'Auftrag vervollständigen und beauftragen' : ($status === 'BEAUFTRAGT' ? (isset($released[$id]) ? 'Nebenauftrag abschließen' : 'Schlussrechnung freigeben') : ''),
			];
		}
		foreach ($cases as &$case) {
			$items = $byCase[(int)$case['id']] ?? [];
			$case['sideOrderSummary'] = [
				'total' => count($items),
				'open' => count(array_filter($items, static fn(array $item): bool => in_array($item['status'], ['ENTWURF', 'BEAUFTRAGT'], true))),
				'billingOpen' => count(array_filter($items, static fn(array $item): bool => $item['status'] === 'BEAUFTRAGT' && !isset($released[$item['id']]))),
				'items' => $items,
			];
		}
		unset($case);
		return $cases;
	}

	private function mapCase(array $row): array {
		return [
			'id' => (int)$row['id'],
			'caseNumber' => (string)$row['case_number'],
			'firstName' => (string)$row['first_name'],
			'lastName' => (string)$row['last_name'],
			'dateOfDeath' => (string)($row['date_of_death'] ?? ''),
			'funeralType' => (string)($row['funeral_type'] ?? ''),
			'burialVariantCode' => (string)($row['burial_variant_code'] ?? ''),
			'withFuneralCeremony' => ($row['with_funeral_ceremony'] ?? null) === null ? null : (bool)$row['with_funeral_ceremony'],
			'pickupTime' => (string)($row['pickup_time'] ?? ''),
			'bodyHeightCm' => ($row['body_height_cm'] ?? null) === null ? null : (int)$row['body_height_cm'],
			'bodyWeightKg' => ($row['body_weight_kg'] ?? null) === null ? null : (int)$row['body_weight_kg'],
			'status' => (string)$row['status'],
			'branch' => (string)($row['branch'] ?? ''),
			'responsibleEmployee' => (string)($row['responsible_employee'] ?? ''),
			'retentionHold' => (bool)($row['retention_hold'] ?? false),
			'retentionDueAt' => (string)($row['retention_due_at'] ?? ''),
			'retentionHoldReason' => (string)($row['retention_hold_reason'] ?? ''),
			'retentionHoldResponsible' => (string)($row['retention_hold_responsible'] ?? ''),
			'retentionHoldSetAt' => (string)($row['retention_hold_set_at'] ?? ''),
			'retentionHoldReviewAt' => (string)($row['retention_hold_review_at'] ?? ''),
			'anonymizedAt' => (string)($row['anonymized_at'] ?? ''),
			'masterData' => json_decode((string)($row['master_data'] ?? '{}'), true) ?: [],
		];
	}

	private function normalizeBurialChoices(array $data, array $previous = []): array {
		$code = trim((string)($data['burial_variant_code'] ?? $previous['burial_variant_code'] ?? ''));
		if ($code !== (string)($previous['burial_variant_code'] ?? '')) $data['burial_deferred_rule_ids'] = [];
		if ($code !== '') {
			$variants = $this->customizing->valuesForKey('BURIAL_VARIANT');
			$chosen = null;
			foreach ($variants as $variant) if ($variant['value'] === $code) { $chosen = $variant; break; }
			if ($chosen === null) throw new \InvalidArgumentException('Die Bestattungsvariante ist unbekannt.');
			$byId = array_column($variants, null, 'id');
			$root = $chosen;
			$visited = [];
			while ($root['parentItemId'] !== null) {
				if (isset($visited[$root['id']]) || !isset($byId[$root['parentItemId']])) throw new \InvalidArgumentException('Die Bestattungsvarianten sind nicht korrekt verknüpft.');
				$visited[$root['id']] = true;
				$root = $byId[$root['parentItemId']];
			}
			$data['funeral_type'] = $root['label'];
			$data['burial_variant_label'] = $chosen['label'];
		} elseif ((string)($previous['burial_variant_code'] ?? '') !== '') {
			$data['funeral_type'] = '';
			$data['burial_variant_label'] = '';
		}
		$data['burial_variant_code'] = $code;
		$ceremony = (string)($data['with_funeral_ceremony'] ?? $previous['with_funeral_ceremony'] ?? '');
		if (!in_array($ceremony, ['', '0', '1'], true)) throw new \InvalidArgumentException('Bitte Trauerfeier mit Ja, Nein oder noch offen angeben.');
		$data['with_funeral_ceremony'] = $ceremony;
		$pickup = trim((string)($data['pickup_time'] ?? $previous['pickup_time'] ?? ''));
		$parsedPickup = $pickup === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $pickup);
		if ($pickup !== '' && (!$parsedPickup || $parsedPickup->format('Y-m-d\\TH:i') !== $pickup)) throw new \InvalidArgumentException('Bitte eine gültige Abholzeit angeben.');
		$data['pickup_time'] = $pickup;
		foreach (['body_height_cm' => 300, 'body_weight_kg' => 600] as $key => $maximum) {
			$value = (string)($data[$key] ?? $previous[$key] ?? '');
			if ($value !== '' && (!ctype_digit($value) || (int)$value < 1 || (int)$value > $maximum)) throw new \InvalidArgumentException('Bitte einen plausiblen Wert für Größe und Gewicht eingeben.');
			$data[$key] = $value;
		}
		$tiers = $this->surcharges->all();
		foreach (['PICKUP' => 'surcharge_pickup_rule_key', 'HEIGHT_CM' => 'surcharge_height_rule_key', 'WEIGHT_KG' => 'surcharge_weight_rule_key', 'OTHER' => 'surcharge_other_rule_key'] as $dimension => $field) {
			$value = trim((string)($data[$field] ?? $previous[$field] ?? ''));
			if ($value !== '' && !array_filter($tiers, static fn(array $tier): bool => $tier['ruleKey'] === $value && $tier['dimension'] === $dimension && ($tier['active'] || $value === ($previous[$field] ?? '')))) {
				throw new \InvalidArgumentException('Die gewählte Zuschlagsstaffel ist nicht aktiv oder passt nicht zum Merkmal.');
			}
			$data[$field] = $value;
		}
		return $data;
	}

	/** Called inside the case transaction; repeated saves and concurrent activation cannot duplicate the task. */
	private function ensureCeremonyTask(int $caseId): void {
		$q = $this->db->getQueryBuilder();
		$records = $q->select('record_type', 'title', 'status', 'payload')->from('bestatter_records')
			->where($q->expr()->eq('case_id', $q->createNamedParameter($caseId)))
			->andWhere($q->expr()->in('record_type', $q->createNamedParameter(['schedule', 'task'], IQueryBuilder::PARAM_STR_ARRAY)))
			->executeQuery()->fetchAllAssociative();
		foreach ($records as $record) {
			if ($record['record_type'] === 'task' && $record['title'] === 'Termin für Trauerfeier abstimmen') return;
			if ($record['record_type'] !== 'schedule' || $record['status'] === 'ABGESAGT') continue;
			$payload = json_decode((string)$record['payload'], true) ?: [];
			if (in_array((string)($payload['scheduleTypeKey'] ?? $payload['schedulePresetKey'] ?? ''), ['TRAUERFEIER_1', 'TRAUERFEIER_2'], true)) return;
		}
		$marker = $this->db->getQueryBuilder();
		try {
			$marker->insert('bestatter_case_automation')->values([
				'case_id' => $marker->createNamedParameter($caseId),
				'trigger_key' => $marker->createNamedParameter('FUNERAL_CEREMONY_TASK'),
				'record_id' => $marker->createNamedParameter(null),
				'created_at' => $marker->createNamedParameter($this->now()),
			])->executeStatement();
		} catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) { return; }
		$created = $this->records->create('task', 'Termin für Trauerfeier abstimmen', '', 'OFFEN', json_encode(['description' => 'Mit Angehörigen den Termin der Trauerfeier abstimmen.', 'origin' => 'FUNERAL_CEREMONY'], JSON_THROW_ON_ERROR), $caseId);
		$update = $this->db->getQueryBuilder();
		$update->update('bestatter_case_automation')->set('record_id', $update->createNamedParameter((int)$created['id']))
			->where($update->expr()->eq('case_id', $update->createNamedParameter($caseId)))
			->andWhere($update->expr()->eq('trigger_key', $update->createNamedParameter('FUNERAL_CEREMONY_TASK')))->executeStatement();
	}
}
