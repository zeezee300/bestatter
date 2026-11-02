<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Bestatter\Exception\BestatterConflictException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class CaseService {
	private const NUMBER_RETRIES = 8;

	public function __construct(
		private IDBConnection $db,
		private FolderService $folders,
		private CaseDeletionService $deletion,
		private AuditService $audit,
		private CommercialStateService $commercialState,
		private CustomizingService $customizing,
		private RecordService $records,
		private SurchargeRuleService $surcharges,
		private TeamService $team,
		private LabelService $labels,
		private ?OrderTypeService $orderTypes = null,
		private ?PrecareService $precare = null,
	) {
	}

	public function dashboard(): array {
		// 0.89.7: „Fälle“ bleiben Sterbefälle; Aufträge ohne Sterbefall und Vorsorgen werden getrennt gezählt.
		$open = [];
		foreach (CaseKinds::ALL as $kind) {
			$open[$kind] = $this->count('status NOT IN (:closed, :cancelled) AND ' . $this->kindCondition($kind), ['closed' => 'ABGESCHLOSSEN', 'cancelled' => 'STORNIERT', 'kind' => $kind]);
		}
		return [
			'openCases' => $this->count('status <> :closed AND ' . $this->kindCondition(CaseKinds::DEATH), ['closed' => 'ABGESCHLOSSEN', 'kind' => CaseKinds::DEATH]),
			'newCases' => $this->count('status = :status AND ' . $this->kindCondition(CaseKinds::DEATH), ['status' => 'NEU', 'kind' => CaseKinds::DEATH]),
			'openByKind' => $open,
			// 0.89.7 (Schritt C): Vorsorgen mit fälliger Wiedervorlage für die Arbeitsübersicht.
			'precareReviews' => $this->precare?->reviewsDue(10) ?? ['count' => 0, 'items' => []],
			'customizingReady' => true,
		];
	}

	/** SQL-Bedingung für die Vorgangsart (Parameter :kind); leere Werte älterer Zeilen gelten als Sterbefall. */
	private function kindCondition(string $kind): string {
		return $kind === CaseKinds::DEATH ? "(case_kind = :kind OR case_kind IS NULL OR case_kind = '')" : 'case_kind = :kind';
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

	public function searchCases(string $search = '', string $status = 'ALL', string $branch = 'ALL', string $responsible = 'ALL', int $limit = 25, int $offset = 0, string $sideOrders = 'ALL', string $labels = '', string $kind = 'ALL'): array {
		$status = strtoupper(trim($status));
		// 0.89.7: Vorgangsart (ALL = alle Arten, z. B. für die Nextcloud-Suche)
		$kind = strtoupper(trim($kind)) === 'ALL' || trim($kind) === '' ? 'ALL' : CaseKinds::normalize($kind);
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
		// 0.76.0: Markerfilter („KEY“ oder „KEY=WERT“, UND-verknüpft); null = kein Filter.
		$labelCaseIds = $this->labels->caseIdsForFilter(LabelRules::parseFilter($labels));
		// 0.77.0: Suchbegriffe finden auch Text in Marker-Notizen.
		$noteCaseIds = $search !== '' ? $this->labels->caseIdsForNoteTerms($this->searchTerms($search)) : [];

		$query = $this->db->getQueryBuilder();
		$query->select('*')->from('bestatter_cases');
		$this->applySearchConditions($query, $search, $status, $branch, $responsible, $searchSideOrderCaseIds, $sideOrders, $filterSideOrderCaseIds, $labelCaseIds, $noteCaseIds, $kind);
		$rows = $query->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')
			->setFirstResult($offset)->setMaxResults($limit)->executeQuery()->fetchAllAssociative();

		$count = $this->db->getQueryBuilder();
		$count->select($count->func()->count('*', 'count'))->from('bestatter_cases');
		$this->applySearchConditions($count, $search, $status, $branch, $responsible, $searchSideOrderCaseIds, $sideOrders, $filterSideOrderCaseIds, $labelCaseIds, $noteCaseIds, $kind);

		return [
			'items' => $this->withLabels($this->withSideOrderSummaries(array_map([$this, 'mapCase'], $rows), $search)),
			'total' => (int)$count->executeQuery()->fetchOne(),
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	public function createCase(array $values): array {
		$data = is_array($values['masterData'] ?? null) ? $values['masterData'] : [];
		// 0.89.7: Vorgangsart und Auftragsart; ohne Angabe ein Sterbefall wie bisher.
		$kind = CaseKinds::normalize((string)($values['caseKind'] ?? $data['case_kind'] ?? ''));
		$orderType = $kind === CaseKinds::ORDER ? $this->orderTypeKey((string)($values['orderType'] ?? $data['order_type'] ?? '')) : '';
		// 0.89.7 (Schritt B): Auftragsart muss eingerichtet und aktiv sein (Customizing → Auftragsarten).
		$orderTypeDefinition = null;
		if ($kind === CaseKinds::ORDER) {
			if ($orderType === '') {
				throw new \InvalidArgumentException('Bitte eine Auftragsart wählen.');
			}
			$orderTypeDefinition = $this->orderTypes?->requireActive($orderType);
		}
		unset($data['case_kind'], $data['order_type']);
		$firstName = trim((string)($data['first_name'] ?? $values['firstName'] ?? ''));
		$lastName = trim((string)($data['last_name'] ?? $values['lastName'] ?? ''));
		$this->assertNames($kind, $firstName, $lastName, $data);
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
			$transactionOpen = false;
			try {
				$this->db->beginTransaction();
				$transactionOpen = true;
				$number = $this->nextCaseNumber($year, $kind);
				$id = $this->insertCase($number, $creationToken, $firstName, $lastName, $data, $values + ['caseKind' => $kind, 'orderType' => $orderType]);
				$this->db->commit();
				$transactionOpen = false;
				$case = $this->getCase($id);
				$case['validationWarnings'] = $validationWarnings;
				$case['folder'] = $this->createFolderSafely($number, (array)($orderTypeDefinition['folders'] ?? []));
				$case['idempotent'] = false;
				// 0.89.7 (Schritt C): Vertragsstand der Vorsorge (Beratung, Wiedervorlage nach Customizing) gleich mit anlegen.
				if ($kind === CaseKinds::PRECARE && $this->precare !== null) {
					try {
						$this->precare->ensure($id);
					} catch (\Throwable) { /* wird beim Öffnen der Akte nachgeholt */
					}
				}
				$this->audit->log($id, 'CASE', $id, 'CREATED', null, $case);
				return $case;
			} catch (\Throwable $error) {
				if ($transactionOpen) {
					$this->db->rollBack();
				}
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

	/**
	 * 0.80.0 (OP-058): offene, nicht anonymisierte Fälle für den Abgleich eines eingehenden Dokuments über Name und Datum.
	 * @return list<array{id:int,caseNumber:string,firstName:string,lastName:string,dateOfDeath:string,dateOfBirth:string}>
	 */
	public function matchingCandidates(int $limit = 2000): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('id', 'case_number', 'first_name', 'last_name', 'date_of_death', 'master_data')->from('bestatter_cases')
			->where($query->expr()->notIn('status', $query->createNamedParameter(['ABGESCHLOSSEN', 'STORNIERT'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($query->expr()->isNull('anonymized_at'))
			->orderBy('id', 'DESC')->setMaxResults($limit)->executeQuery()->fetchAllAssociative();
		return array_map(static function (array $row): array {
			$master = json_decode((string)($row['master_data'] ?? '{}'), true) ?: [];
			return ['id' => (int)$row['id'], 'caseNumber' => (string)$row['case_number'], 'firstName' => (string)($row['first_name'] ?? ''), 'lastName' => (string)($row['last_name'] ?? ''),
				'dateOfDeath' => (string)($row['date_of_death'] ?? ($master['date_of_death'] ?? '')), 'dateOfBirth' => (string)($master['date_of_birth'] ?? '')];
		}, $rows);
	}

	/** 0.89.7: Fallnummer oder '' (Fall gelöscht) – für Verweise auf andere Akten, ohne Ausnahme. */
	public function existingCaseNumber(int $caseId): string {
		$query = $this->db->getQueryBuilder();
		$value = $query->select('c.case_number')->from('bestatter_cases', 'c')
			->where($query->expr()->eq('c.id', $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchOne();
		return $value === false ? '' : (string)$value;
	}

	public function caseNumber(int $caseId): string {
		return $caseId > 0 ? (string)$this->getCase($caseId)['caseNumber'] : '';
	}

	/**
	 * @param string|null $expectedUpdatedAt Optimistische Sperre: wenn angegeben, muss der
	 *                                       Wert exakt dem `updatedAt` entsprechen, das der Aufrufer zuletzt von getCase() erhalten
	 *                                       hat. Weicht er ab, wurde der Fall zwischenzeitlich von einer anderen Sitzung geändert;
	 *                                       die Aktualisierung wird dann abgelehnt statt die fremde Änderung stillschweigend zu
	 *                                       überschreiben. Ist der Parameter null (z.B. ältere Aufrufer), verhält sich die Methode
	 *                                       wie zuvor ("last write wins").
	 */
	public function updateMasterData(int $id, array $data, ?string $expectedUpdatedAt = null, bool $allowSignatureFields = false): array {
		$case = $this->getCase($id);
		if ($expectedUpdatedAt !== null && $expectedUpdatedAt !== '' && $expectedUpdatedAt !== $case['updatedAt']) {
			throw new BestatterConflictException('Der Fall wurde inzwischen von einer anderen Person geändert. Bitte die Fallansicht neu laden und die Änderung erneut vornehmen.');
		}
		// Befund #18: Zustand VOR der Änderung merken, um weiter unten den tatsächlichen Übergang
		// (noch nicht storniert/erledigt -> jetzt storniert/erledigt) zu erkennen. Dieser Weg deckt
		// alle drei bekannten Auslöser ab, da sie ausnahmslos über updateMasterData() laufen:
		// ProcessActionService::applyCaseStatus() (Fallstatus STORNIERT), die im Entwurf mögliche manuelle
		// Auftragsstornierung (masterData.order_status = "storniert") sowie
		// CommercialService::closeOrderWithoutInvoice() (masterData.order_closed_at gesetzt).
		$wasCancelled = $this->isCancelledState($case['status'], $case['masterData']);
		$wasClosedWithoutInvoice = trim((string)($case['masterData']['order_closed_at'] ?? '')) !== '';
		$responsibleUid = trim((string)($data['responsible_employee'] ?? $case['responsibleEmployee']));
		if ($responsibleUid !== (string)$case['responsibleEmployee']) {
			$this->team->requireBestatterAdmin();
			if ($responsibleUid !== '') {
				$this->team->assignee($responsibleUid);
			}
		}
		$data['responsible_employee'] = $responsibleUid;
		// 0.89.10: Die Zahlungsart steuert Zahlungs-QR und Lastschrift; nur bekannte Werte annehmen.
		if (array_key_exists('payment_method', $data)) {
			$paymentMethod = strtoupper(trim((string)$data['payment_method']));
			$storedMethod = strtoupper(trim((string)($case['masterData']['payment_method'] ?? '')));
			if (!in_array($paymentMethod, ['', 'TRANSFER', 'SEPA_DIRECT_DEBIT'], true) && $paymentMethod !== $storedMethod) {
				throw new \InvalidArgumentException('Die Zahlungsart ist ungültig (zulässig: Überweisung oder SEPA-Lastschrift).');
			}
			$data['payment_method'] = $paymentMethod;
		}
		$commercialState = $this->commercialState->state($id);
		$requestedOrderStatus = mb_strtolower(trim((string)($data['order_status'] ?? 'Entwurf')));
		if ($commercialState['editable'] && in_array($requestedOrderStatus, ['beauftragt', 'kva versendet'], true)) {
			throw new \InvalidArgumentException('Der Statuswechsel ist nur über die geprüfte Festschreibung des KVA beziehungsweise Auftrags zulässig.');
		}
		if (!$commercialState['editable']) {
			$data['order_status'] = $commercialState['effectiveOrderStatus'];
		}
		$data = $this->protectSignatureAndChoices($data, (array)($case['masterData'] ?? []), (bool)$commercialState['editable'], $allowSignatureFields);
		$firstName = trim((string)($data['first_name'] ?? $case['firstName']));
		$lastName = trim((string)($data['last_name'] ?? $case['lastName']));
		// 0.89.7: Vorgangsart und Auftragsart ändern sich nicht über die Stammdaten.
		unset($data['case_kind'], $data['order_type']);
		$this->assertNames((string)$case['caseKind'], $firstName, $lastName, $data);
		$data = $this->normalizeMasterDates($data);
		$data = $this->normalizeBurialChoices($data, $case['masterData']);
		if (!$commercialState['editable']) {
			foreach (['surcharge_pickup_rule_key', 'surcharge_height_rule_key', 'surcharge_weight_rule_key', 'surcharge_other_rule_key'] as $field) {
				if ($data[$field] !== (string)($case['masterData'][$field] ?? '')) {
					throw new \InvalidArgumentException('Zuschläge nach Beauftragung bitte als begründeten Vertragsnachtrag in den Positionen erfassen.');
				}
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
				->where($query->expr()->eq('id', $query->createNamedParameter($id)));
			// Zusätzlich zur Vorabprüfung oben: den alten updated_at-Wert auch in die WHERE-Klausel
			// des UPDATE selbst aufnehmen, damit eine Änderung, die genau zwischen der Vorabprüfung
			// und diesem Statement passiert, nicht unbemerkt überschrieben wird (0 betroffene Zeilen
			// statt eines stillen "last write wins").
			if ($expectedUpdatedAt !== null && $expectedUpdatedAt !== '') {
				$query->andWhere($query->expr()->eq('updated_at', $query->createNamedParameter($case['updatedAt'])));
			}
			$affected = $query->executeStatement();
			if ($expectedUpdatedAt !== null && $expectedUpdatedAt !== '' && $affected !== 1) {
				throw new BestatterConflictException('Der Fall wurde inzwischen von einer anderen Person geändert. Bitte die Fallansicht neu laden und die Änderung erneut vornehmen.');
			}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		$updated = $this->getCase($id);
		$updated['validationWarnings'] = $validationWarnings;
		$this->audit->log($id, 'CASE', $id, 'UPDATED', $case, $updated);
		// Befund #18: Kaskade erst NACH dem eigentlichen Speichern und nur beim tatsächlichen
		// Übergang auslösen (nicht bei jedem weiteren Speichern eines bereits stornierten/erledigten
		// Falls), damit bereits geschlossene Datensätze nicht bei jeder Folgeänderung erneut
		// anfasst/protokolliert werden.
		if (!$wasCancelled && $this->isCancelledState($updated['status'], $updated['masterData'])) {
			$this->records->closeFollowUpsForCase($id, 'STORNIERT');
		} elseif (!$wasClosedWithoutInvoice && trim((string)($updated['masterData']['order_closed_at'] ?? '')) !== '') {
			$this->records->closeFollowUpsForCase($id, 'ERLEDIGT');
		}
		return $updated;
	}

	/**
	 * 0.89.3: Unterschrift (order_signature_*), Erklärungen zum Leistungsbeginn und Kundenfelder (order_customer_*) entstehen nur
	 * bei der Unterschrift am Tablet (OrderSignatureService, $allowSignatureFields). Die Ankreuzfelder des Bestattungsunternehmens
	 * sind nach dem Festschreiben geschützt – wie die Positionen. Fehlende Schlüssel behalten ihren bisherigen Wert.
	 */
	private function protectSignatureAndChoices(array $data, array $previous, bool $editable, bool $allowSignatureFields): array {
		if ($allowSignatureFields) {
			return $data;
		}
		foreach (array_unique(array_merge(array_keys($previous), array_keys($data))) as $field) {
			$field = (string)$field;
			$signature = preg_match('/^order_(signature_[a-z_]+|withdrawal_(request|ack)|customer_[a-z0-9_]+)$/', $field) === 1;
			$choice = preg_match('/^order_(scope_(transfer|ceremony|formalities)|poa_(registry|crematorium|ceremony|deregistration)|choice_[a-z0-9_]+)$/', $field) === 1;
			if (!$signature && !$choice) {
				continue;
			}
			if (!array_key_exists($field, $data)) {
				$data[$field] = $previous[$field] ?? '';
				continue;
			}
			if (trim((string)$data[$field]) === trim((string)($previous[$field] ?? ''))) {
				continue;
			}
			if ($signature) {
				throw new \InvalidArgumentException('Unterschrift und Erklärungen des Auftraggebers entstehen nur bei der Unterschrift am Tablet und lassen sich nicht nachträglich ändern.');
			}
			if (!$editable) {
				throw new \InvalidArgumentException('Die Ankreuzfelder des Auftrags sind nach dem Festschreiben geschützt. Änderungen bitte als Vertragsnachtrag festhalten.');
			}
		}
		return $data;
	}

	/**
	 * Ein Fall gilt als storniert, wenn entweder der Fallstatus selbst STORNIERT ist (gesetzt über
	 * ProcessActionService::applyCaseStatus()) oder der noch im Entwurf befindliche Hauptauftrag manuell
	 * auf "storniert" gesetzt wurde (masterData.order_status) – dieselbe Definition wie in
	 * OperationalService::isCancelled(), hier lokal gehalten, um keine Service-Abhängigkeit in die
	 * andere Richtung einzuführen.
	 */
	private function isCancelledState(string $caseStatus, array $masterData): bool {
		return strtoupper(trim($caseStatus)) === 'STORNIERT'
			|| mb_strtolower(trim((string)($masterData['order_status'] ?? ''))) === 'storniert';
	}

	/** @throws \InvalidArgumentException when open side orders still block the case closure. */
	public function assertClosable(int $caseId): void {
		$query = $this->db->getQueryBuilder();
		$orders = $query->select('id', 'side_order_number', 'first_name', 'last_name', 'status')
			->from('bestatter_side_orders')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->andWhere($query->expr()->in('status', $query->createNamedParameter(['ENTWURF', 'BEAUFTRAGT'], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		if ($orders === []) {
			return;
		}

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

	/**
	 * 0.75.1: Hinweise vor dem Löschen (laufende Aufbewahrungsfristen, Paperless-Kopien). Sperren nichts.
	 * @return array{caseNumber:string,legalHold:bool,notices:list<array{code:string,text:string}>}
	 */
	public function deletionCheck(int $id): array {
		$case = $this->getCase($id);
		return ['caseNumber' => (string)$case['caseNumber'], 'legalHold' => !empty($case['retentionHold']), 'notices' => $this->deletion->notices($id)];
	}

	/**
	 * Löscht den Fall vollständig (Daten, Fallordner am Papierkorb vorbei, Kalendereinträge; Audit-Zeilen bleiben
	 * ohne Inhalt als Nachweis). 0.75.1: Rechnungen/Belege sperren nicht mehr – die gesetzliche Aufbewahrung leistet
	 * die Buchhaltung (z. B. DATEV). Bestehen Hinweise, muss der Aufrufer sie bestätigen ($confirmed).
	 * Ein Legal Hold bleibt eine bewusste Sperre und muss zuerst aufgehoben werden.
	 */
	public function deleteCase(int $id, bool $confirmed = false): array {
		$check = $this->deletionCheck($id);
		if ($check['legalHold']) {
			throw new \InvalidArgumentException('Für diesen Fall ist ein Legal Hold gesetzt. Er kann erst nach dem Aufheben der Löschsperre gelöscht werden.');
		}
		if ($check['notices'] !== [] && !$confirmed) {
			throw new \InvalidArgumentException('Bitte die Hinweise zum Löschen bestätigen: ' . implode(' ', array_column($check['notices'], 'text')));
		}
		return $this->deletion->delete($id, 'CASE_DELETE');
	}

	public function ensureFolder(int $id): array {
		$case = $this->getCase($id);
		return $this->folders->createCaseFolder($case['caseNumber'], $this->orderTypeFolders($case));
	}

	/** 0.89.7: Unterordner der Auftragsart (leer = Ordnerauswahl der Vorgangsart). @return list<string> */
	private function orderTypeFolders(array $case): array {
		if (($case['caseKind'] ?? '') !== CaseKinds::ORDER || ($case['orderType'] ?? '') === '') {
			return [];
		}
		return (array)($this->orderTypes?->byKey((string)$case['orderType'])['folders'] ?? []);
	}

	/**
	 * 0.89.7: Fallstatus setzen, ohne die übrigen Stammdaten zu verändern (Ende einer Vorsorge, Weiterführung eines Auftrags
	 * als Sterbefall). Läuft über updateMasterData() – mit denselben Prüfungen und demselben Protokoll.
	 */
	public function setStatus(int $id, string $status, string $reason = ''): array {
		$case = $this->getCase($id);
		$status = strtoupper(trim($status));
		if (!in_array($status, ['NEU', 'IN_BEARBEITUNG', 'ABGESCHLOSSEN', 'STORNIERT'], true)) {
			throw new \InvalidArgumentException('Der Fallstatus ist ungültig.');
		}
		if ($status === strtoupper((string)$case['status'])) {
			return $case;
		}
		$data = (array)$case['masterData'];
		$data['status'] = $status;
		if ($status === 'ABGESCHLOSSEN') {
			$data['close_reason'] = trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : (string)($data['close_reason'] ?? '');
			$data['closed_at'] = (string)($data['closed_at'] ?? '') !== '' ? (string)$data['closed_at'] : date('Y-m-d');
		}
		return $this->updateMasterData($id, $data);
	}

	/** 0.89.7: Verweis auf die Herkunftsakte (eingelöste Vorsorge, weitergeführter Auftrag) setzen – nur einmal je Akte. */
	public function linkOrigin(int $caseId, int $originCaseId): void {
		$case = $this->getCase($caseId);
		if ((int)$case['originCaseId'] > 0 && (int)$case['originCaseId'] !== $originCaseId) {
			throw new \InvalidArgumentException('Der Fall ist bereits mit einer anderen Akte verknüpft.');
		}
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_cases')->set('origin_case_id', $query->createNamedParameter($originCaseId, IQueryBuilder::PARAM_INT))->set('updated_at', $query->createNamedParameter($this->now()))
			->where($query->expr()->eq('id', $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))->executeStatement();
		$this->audit->log($caseId, 'CASE', $caseId, 'ORIGIN_LINKED', ['originCaseId' => (int)$case['originCaseId']], ['originCaseId' => $originCaseId]);
	}

	/** 0.89.7: Akten, die aus dieser Akte entstanden sind (Sterbefall aus Vorsorge bzw. Auftrag). @return list<array{id:int,caseNumber:string,caseKind:string}> */
	public function derivedCases(int $originCaseId): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('id', 'case_number', 'case_kind')->from('bestatter_cases')->where($query->expr()->eq('origin_case_id', $query->createNamedParameter($originCaseId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		return array_map(static fn (array $row): array => ['id' => (int)$row['id'], 'caseNumber' => (string)$row['case_number'], 'caseKind' => (string)($row['case_kind'] ?: CaseKinds::DEATH)], $rows);
	}

	/**
	 * 0.89.7 (Schritt D): aktive Vorsorgen, die zu einer verstorbenen Person passen – Nachname und Geburtsdatum gleich
	 * (sicher) bzw. Vor- und Nachname gleich, wenn ein Geburtsdatum fehlt (prüfen). Vergleich ohne Groß-/Kleinschreibung.
	 * @return list<array{id:int,caseNumber:string,firstName:string,lastName:string,dateOfBirth:string,match:string}>
	 */
	public function precareCandidates(string $firstName, string $lastName, string $dateOfBirth = '', int $excludeCaseId = 0): array {
		$norm = static fn (string $value): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
		$last = $norm($lastName);
		$first = $norm($firstName);
		$birth = trim($dateOfBirth);
		if ($last === '') {
			return [];
		}
		$query = $this->db->getQueryBuilder();
		$query->select('c.id', 'c.case_number', 'c.first_name', 'c.last_name', 'c.master_data')->from('bestatter_cases', 'c')
			->innerJoin('c', 'bestatter_precare', 'p', $query->expr()->eq('p.case_id', 'c.id'))
			->where($query->expr()->eq('c.case_kind', $query->createNamedParameter(CaseKinds::PRECARE)))
			->andWhere($query->expr()->in('p.precare_status', $query->createNamedParameter(PrecareService::ACTIVE_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($query->expr()->isNull('c.anonymized_at'));
		$result = [];
		foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
			if ((int)$row['id'] === $excludeCaseId || $norm((string)$row['last_name']) !== $last) {
				continue;
			}
			$master = json_decode((string)($row['master_data'] ?? '{}'), true) ?: [];
			$candidateBirth = trim((string)($master['date_of_birth'] ?? ''));
			$sameFirst = $first !== '' && $norm((string)$row['first_name']) === $first;
			$match = $birth !== '' && $candidateBirth !== '' ? ($birth === $candidateBirth ? 'SICHER' : '') : ($sameFirst ? 'PRUEFEN' : '');
			if ($match === '') {
				continue;
			}
			$result[] = ['id' => (int)$row['id'], 'caseNumber' => (string)$row['case_number'], 'firstName' => (string)$row['first_name'], 'lastName' => (string)$row['last_name'], 'dateOfBirth' => $candidateBirth, 'match' => $match];
		}
		usort($result, static fn (array $a, array $b): int => [$a['match'] === 'SICHER' ? 0 : 1, $a['caseNumber']] <=> [$b['match'] === 'SICHER' ? 0 : 1, $b['caseNumber']]);
		return $result;
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
			'case_kind' => $query->createNamedParameter(CaseKinds::normalize((string)($values['caseKind'] ?? ''))),
			'order_type' => $query->createNamedParameter(($values['orderType'] ?? '') !== '' ? (string)$values['orderType'] : null),
			'origin_case_id' => $query->createNamedParameter((int)($values['originCaseId'] ?? 0) > 0 ? (int)$values['originCaseId'] : null, IQueryBuilder::PARAM_INT),
			'created_at' => $query->createNamedParameter($now),
			'updated_at' => $query->createNamedParameter($now),
		])->executeStatement();
		return (int)$this->db->lastInsertId('bestatter_cases');
	}

	/**
	 * 0.89.7: Pflichtangaben zur Hauptperson je Vorgangsart. Sterbefall und Vorsorge: Vor- und Nachname (Vorsorge zusätzlich
	 * Geburtsdatum, damit sie im Sterbefall sicher gefunden wird). Auftrag: Nachname oder Firmenname genügt (z. B. Verkauf an
	 * einen anderen Bestatter); der Firmenname steht dann als Nachname in der Liste.
	 */
	private function assertNames(string $kind, string $firstName, string $lastName, array $data): void {
		if ($kind === CaseKinds::ORDER) {
			if ($lastName === '') {
				throw new \InvalidArgumentException('Bitte Nachname oder Firmenname angeben.');
			}
			return;
		}
		if ($firstName === '' || $lastName === '') {
			throw new \InvalidArgumentException('Vor- und Nachname sind Pflichtfelder.');
		}
		if ($kind === CaseKinds::PRECARE && trim((string)($data['date_of_birth'] ?? '')) === '') {
			throw new \InvalidArgumentException('Für eine Vorsorge ist das Geburtsdatum erforderlich.');
		}
	}

	/** Schlüssel der Auftragsart (Großbuchstaben, Ziffern, Unterstrich); die Liste der Arten folgt mit den Auftragsarten (Customizing). */
	private function orderTypeKey(string $value): string {
		$key = strtoupper(trim($value));
		if ($key === '') {
			return '';
		}
		if (!preg_match('/^[A-Z][A-Z0-9_]{1,59}$/', $key)) {
			throw new \InvalidArgumentException('Die Auftragsart ist ungültig.');
		}
		return $key;
	}

	// Kundenwunsch (2026-09-22, im Anschluss an den "Abmeldungen"-Test): Fallnummern sollen
	// grundsätzlich fortlaufend vergeben und nie zurückgesetzt/wiederverwendet werden. Bisher
	// ermittelte nextCaseNumber() die nächste Nummer ausschließlich aus dem höchsten noch
	// vorhandenen case_number-Wert in bestatter_cases (MAX-Scan). Wird ein Fall über den
	// admin-only "Fall löschen"-Button endgültig gelöscht, verschwindet damit auch seine Nummer
	// aus dieser Ermittlung - der nächste neu angelegte Fall bekam dieselbe Nummer erneut
	// zugewiesen und erbte dadurch den (damals nicht bereinigten; seit 0.75.1 löscht deleteCase() ihn mit) Nextcloud-Fallordner
	// des gelöschten Falls samt verwaister Altdateien. Analog zum bereits etablierten Muster für
	// Rechnungsnummern (CommercialService::nextInvoiceNumber(), eigene Sequenztabelle statt
	// MAX-Scan) wird die nächste Fallnummer jetzt aus einem eigenständigen, dauerhaften Zähler
	// gezogen, der von gelöschten Fällen unberührt bleibt: Die bereits vorhandene, generische
	// Zählertabelle bestatter_invoice_sequences wird unter dem Schlüssel "CASE:{Jahr}"
	// mitgenutzt (bewusst kein "GLOBAL"/"{Jahr}"-Schlüssel wie bei Rechnungsnummern, um mit deren
	// Sequenzen nicht zu kollidieren). Aufruf erfolgt - wie bei nextInvoiceNumber() - innerhalb
	// derselben Transaktion wie der anschließende Fall-Insert; das Erhöhen sperrt die Zählerzeile (DatabaseLocks)
	// bis zum Commit/Rollback und verhindert damit, dass zwei gleichzeitige Fallanlagen dieselbe
	// Nummer ziehen. Migration Version4400Date20260922010000 füllt den Zähler einmalig aus dem
	// bisherigen Höchststand pro Jahr vor, damit der Übergang keine bereits vergebene Nummer
	// erneut ausgibt.
	// 0.89.7: je Vorgangsart ein eigener Zähler (CASE:{Jahr} für Sterbefälle wie bisher, ORDER:{Jahr}, PRECARE:{Jahr}); Aufträge und
	// Vorsorgen erhalten das Präfix „A-“ bzw. „V-“ (CaseKinds::format()).
	private function nextCaseNumber(string $year, string $kind = CaseKinds::DEATH): string {
		// 0.89.7: Zähler über DatabaseLocks (auch auf SQLite; bisher SELECT … FOR UPDATE).
		return CaseKinds::format($kind, $year, DatabaseLocks::nextSequence($this->db, CaseKinds::sequenceKey($kind, $year)));
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

	private function createFolderSafely(string $number, array $folderKeys = []): array {
		try {
			return $this->folders->createCaseFolder($number, $folderKeys);
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
		// 0.71.0: Schnellerfassung und Assistent lieferten die gebührenpflichtigen Urkunden als „certificate_paid_count“;
		// Auftrag, Dokumente und Platzhalter verwenden „urkunden_gebuehrenpflichtig“.
		if (array_key_exists('certificate_paid_count', $data)) {
			if (trim((string)$data['certificate_paid_count']) !== '' && trim((string)($data['urkunden_gebuehrenpflichtig'] ?? '')) === '') {
				$data['urkunden_gebuehrenpflichtig'] = $data['certificate_paid_count'];
			}
			unset($data['certificate_paid_count']);
		}
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
			if (trim((string)($data[$field] ?? '')) !== '') {
				$hasFamilyDetails = true;
				break;
			}
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

	private function applySearchConditions(mixed $query, string $search, string $status, string $branch, string $responsible, array $searchSideOrderCaseIds = [], string $sideOrders = 'ALL', array $filterSideOrderCaseIds = [], ?array $labelCaseIds = null, array $noteCaseIdsByTerm = [], string $kind = 'ALL'): void {
		if ($kind !== 'ALL') {
			$condition = $query->expr()->eq('case_kind', $query->createNamedParameter($kind));
			$query->andWhere($kind === CaseKinds::DEATH ? $query->expr()->orX($condition, $query->expr()->isNull('case_kind'), $query->expr()->eq('case_kind', $query->createNamedParameter(''))) : $condition);
		}
		if ($labelCaseIds !== null) {
			$query->andWhere($labelCaseIds === [] ? $query->expr()->eq('id', $query->createNamedParameter(-1)) : $query->expr()->in('id', $query->createNamedParameter($labelCaseIds, IQueryBuilder::PARAM_INT_ARRAY)));
		}
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
				if ($searchSideOrderCaseIds !== []) {
					$conditions[] = $query->expr()->in('id', $query->createNamedParameter($searchSideOrderCaseIds, IQueryBuilder::PARAM_INT_ARRAY));
				}
				if (($noteCaseIdsByTerm[$searchTerm] ?? []) !== []) {
					$conditions[] = $query->expr()->in('id', $query->createNamedParameter($noteCaseIdsByTerm[$searchTerm], IQueryBuilder::PARAM_INT_ARRAY));
				}
				$query->andWhere($query->expr()->orX(...$conditions));
			}
		}
		if ($status === 'OPEN') {
			$query->andWhere($query->expr()->neq('status', $query->createNamedParameter('ABGESCHLOSSEN')));
			$query->andWhere($query->expr()->neq('status', $query->createNamedParameter('STORNIERT')));
		} elseif ($status !== 'ALL') {
			$query->andWhere($query->expr()->eq('status', $query->createNamedParameter($status)));
		}
		if ($branch !== '' && $branch !== 'ALL') {
			$query->andWhere($query->expr()->eq('branch', $query->createNamedParameter($branch)));
		}
		if ($responsible !== '' && $responsible !== 'ALL') {
			$query->andWhere($query->expr()->eq('responsible_employee', $query->createNamedParameter($responsible)));
		}
		if ($sideOrders !== 'ALL') {
			if ($sideOrders === 'NONE') {
				if ($filterSideOrderCaseIds !== []) {
					$query->andWhere($query->expr()->notIn('id', $query->createNamedParameter($filterSideOrderCaseIds, IQueryBuilder::PARAM_INT_ARRAY)));
				}
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
		return array_slice(array_values(array_filter($terms, static fn (string $term): bool => $term !== '')), 0, 10);
	}

	private function sideOrderCaseIdsForFilter(string $filter): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('case_id', 'id', 'status')->from('bestatter_side_orders')->executeQuery()->fetchAllAssociative();
		$byCase = [];
		foreach ($rows as $row) {
			$byCase[(int)$row['case_id']][] = $row;
		}
		$released = $filter === 'BILLING_OPEN' ? $this->releasedFinalInvoiceSideOrderIds() : [];
		$result = [];
		foreach ($byCase as $caseId => $orders) {
			$matches = match ($filter) {
				'HAS', 'NONE' => true,
				'OPEN' => count(array_filter($orders, static fn (array $row): bool => in_array((string)$row['status'], ['ENTWURF', 'BEAUFTRAGT'], true))) > 0,
				'BILLING_OPEN' => count(array_filter($orders, static fn (array $row): bool => (string)$row['status'] === 'BEAUFTRAGT' && !isset($released[(int)$row['id']]))) > 0,
				'DONE' => count(array_filter($orders, static fn (array $row): bool => in_array((string)$row['status'], ['ENTWURF', 'BEAUFTRAGT'], true))) === 0,
				default => false,
			};
			if ($matches) {
				$result[] = $caseId;
			}
		}
		return $result;
	}

	private function releasedFinalInvoiceSideOrderIds(?int $caseId = null): array {
		$query = $this->db->getQueryBuilder();
		$query->selectDistinct('side_order_id')->from('bestatter_invoices')
			->where($query->expr()->eq('invoice_type', $query->createNamedParameter('FINAL')))
			->andWhere($query->expr()->in('status', $query->createNamedParameter(['FREIGEGEBEN', 'VERSENDET', 'TEILBEZAHLT', 'BEZAHLT'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($query->expr()->isNotNull('side_order_id'));
		if ($caseId !== null) {
			$query->andWhere($query->expr()->eq('case_id', $query->createNamedParameter($caseId)));
		}
		$ids = $query->executeQuery()->fetchFirstColumn();
		return array_fill_keys(array_map('intval', $ids), true);
	}

	/** 0.76.0: Marker und automatische Hinweise für die Symbolspalte der Fallliste (gebündelt abgefragt). */
	private function withLabels(array $cases): array {
		$ids = array_map(static fn (array $case): int => (int)$case['id'], $cases);
		$labels = $this->labels->forCases($ids);
		$hints = $this->labels->hintsForCases($ids);
		foreach ($cases as &$case) {
			$case['labels'] = $labels[(int)$case['id']] ?? [];
			$case['hints'] = $hints[(int)$case['id']] ?? [];
		}
		unset($case);
		return $cases;
	}

	private function withSideOrderSummaries(array $cases, string $search = ''): array {
		$caseIds = array_values(array_filter(array_map(static fn (array $case): int => (int)$case['id'], $cases)));
		if ($caseIds === []) {
			return $cases;
		}
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
				'open' => count(array_filter($items, static fn (array $item): bool => in_array($item['status'], ['ENTWURF', 'BEAUFTRAGT'], true))),
				'billingOpen' => count(array_filter($items, static fn (array $item): bool => $item['status'] === 'BEAUFTRAGT' && !isset($released[$item['id']]))),
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
			// 0.89.7: Vorgangsart (leere Altwerte = Sterbefall), Auftragsart, Verweis auf die eingelöste Vorsorge
			'caseKind' => ($kind = (string)($row['case_kind'] ?? '')) !== '' ? $kind : CaseKinds::DEATH,
			'caseKindLabel' => CaseKinds::label($kind !== '' ? $kind : CaseKinds::DEATH),
			'orderType' => (string)($row['order_type'] ?? ''),
			'orderTypeLabel' => (string)($row['order_type'] ?? '') !== '' ? ($this->orderTypes?->label((string)$row['order_type']) ?? (string)$row['order_type']) : '',
			'originCaseId' => (int)($row['origin_case_id'] ?? 0),
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
			// Für optimistische Sperre bei updateMasterData(): der Aufrufer schickt diesen
			// Wert unverändert als expectedUpdatedAt zurück, um eine zwischenzeitliche
			// fremde Änderung zu erkennen.
			'updatedAt' => (string)($row['updated_at'] ?? ''),
			'masterData' => json_decode((string)($row['master_data'] ?? '{}'), true) ?: [],
		];
	}

	private function normalizeBurialChoices(array $data, array $previous = []): array {
		$code = trim((string)($data['burial_variant_code'] ?? $previous['burial_variant_code'] ?? ''));
		if ($code !== (string)($previous['burial_variant_code'] ?? '')) {
			$data['burial_deferred_rule_ids'] = [];
		}
		if ($code !== '') {
			$variants = $this->customizing->valuesForKey('BURIAL_VARIANT');
			$chosen = null;
			foreach ($variants as $variant) {
				if ($variant['value'] === $code) {
					$chosen = $variant;
					break;
				}
			}
			if ($chosen === null) {
				throw new \InvalidArgumentException('Die Bestattungsvariante ist unbekannt.');
			}
			$byId = array_column($variants, null, 'id');
			$root = $chosen;
			$visited = [];
			while ($root['parentItemId'] !== null) {
				if (isset($visited[$root['id']]) || !isset($byId[$root['parentItemId']])) {
					throw new \InvalidArgumentException('Die Bestattungsvarianten sind nicht korrekt verknüpft.');
				}
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
		if (!in_array($ceremony, ['', '0', '1'], true)) {
			throw new \InvalidArgumentException('Bitte Trauerfeier mit Ja, Nein oder noch offen angeben.');
		}
		$data['with_funeral_ceremony'] = $ceremony;
		$pickup = trim((string)($data['pickup_time'] ?? $previous['pickup_time'] ?? ''));
		$parsedPickup = $pickup === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $pickup);
		if ($pickup !== '' && (!$parsedPickup || $parsedPickup->format('Y-m-d\\TH:i') !== $pickup)) {
			throw new \InvalidArgumentException('Bitte eine gültige Abholzeit angeben.');
		}
		$data['pickup_time'] = $pickup;
		foreach (['body_height_cm' => 300, 'body_weight_kg' => 600] as $key => $maximum) {
			$value = (string)($data[$key] ?? $previous[$key] ?? '');
			if ($value !== '' && (!ctype_digit($value) || (int)$value < 1 || (int)$value > $maximum)) {
				throw new \InvalidArgumentException('Bitte einen plausiblen Wert für Größe und Gewicht eingeben.');
			}
			$data[$key] = $value;
		}
		$tiers = $this->surcharges->all();
		foreach (['PICKUP' => 'surcharge_pickup_rule_key', 'HEIGHT_CM' => 'surcharge_height_rule_key', 'WEIGHT_KG' => 'surcharge_weight_rule_key', 'OTHER' => 'surcharge_other_rule_key'] as $dimension => $field) {
			$value = trim((string)($data[$field] ?? $previous[$field] ?? ''));
			if ($value !== '' && !array_filter($tiers, static fn (array $tier): bool => $tier['ruleKey'] === $value && $tier['dimension'] === $dimension && ($tier['active'] || $value === ($previous[$field] ?? '')))) {
				throw new \InvalidArgumentException('Die gewählte Zuschlagsstaffel ist nicht aktiv oder passt nicht zum Merkmal.');
			}
			$data[$field] = $value;
		}
		return $data;
	}
}
