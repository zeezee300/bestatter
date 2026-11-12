<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * 0.89.7 (OP-074/OP-075, Schritte B–D): Übergänge zwischen den Vorgangsarten.
 *
 * - Auftrag → Sterbefall („Als Sterbefall weiterführen“, z. B. eine Überführung wird zur Bestattung): neuer, verknüpfter
 *   Sterbefall mit den Angaben und offenen Positionen; der Auftrag wird abgeschlossen oder storniert. Keine Umnummerierung.
 * - Vorsorge → Sterbefall („Vorsorge einlösen“, Konzept E1): Vorschau, dann neuer verknüpfter Sterbefall (oder ein schon
 *   angelegter Sterbefall) mit Person, Wünschen, Positionen des Vorsorgevertrags je Preisart, Kopien von Vertrag und
 *   Verfügungen, Fallkontakten, Markern und je Treuhand/Sterbegeldversicherung einer vorbereiteten Sterbefallmeldung.
 *   Die Vorsorge wird „eingelöst“, schließt ab und bleibt als Nachweis mit Verweis erhalten.
 * - Ende einer Vorsorge (Kündigung, Widerruf) schließt die Akte, damit die Aufbewahrungsfrist ab dem Ende läuft.
 */
class CaseKindTransitionService {
	/** Stammdaten, die beim Weiterführen bzw. Einlösen nicht übernommen werden (Auftrag, Rechnung, Unterschrift, Fallführung). */
	private const EXCLUDED_PREFIXES = ['order_', 'invoice_', 'kva_', 'sepa_', 'payment_', 'deposit_', 'certificate_', 'urkunden_', 'commissioning_', 'burial_deferred'];
	private const EXCLUDED_KEYS = ['status', 'close_reason', 'closed_at', 'notes', 'responsible_employee', 'branch', 'case_kind', 'order_type', 'date_of_death', 'time_of_death', 'death_time_mode', 'death_time_from', 'death_time_to', 'place_of_death'];
	/** Finanzierungsarten mit vorbereiteter Sterbefallmeldung (Abmeldungsvorlagen TREUHAND und STERBEGELD). */
	public const NOTIFICATION_TEMPLATES = ['TREUHAND' => 'TREUHAND', 'STERBEGELD' => 'STERBEGELD'];
	/** Wert des Markers „Kostenträger“ je Finanzierungsart. */
	private const PAYER_LABEL_VALUES = ['TREUHAND' => 'TREUHAND', 'VERPFAENDUNG' => 'TREUHAND', 'STERBEGELD' => 'VERSICHERUNG'];

	public function __construct(
		private CaseService $cases,
		private PrecareService $precare,
		private ArticleService $articles,
		private CaseFileService $files,
		private RecordService $records,
		private LabelService $labels,
		private DeregistrationService $deregistrations,
		private AuditService $audit,
		private InstallationConfigService $installation,
		private ?OrderTypeService $orderTypes = null,
	) {
	}

	/** Stammdaten zum Übernehmen: ohne Auftrags-, Rechnungs- und Fallführungsangaben. */
	public static function transferableMaster(array $master): array {
		$result = [];
		foreach ($master as $key => $value) {
			$key = (string)$key;
			if (in_array($key, self::EXCLUDED_KEYS, true)) {
				continue;
			}
			foreach (self::EXCLUDED_PREFIXES as $prefix) {
				if (str_starts_with($key, $prefix)) {
					continue 2;
				}
			}
			if (!is_scalar($value) && $value !== null) {
				continue;
			}
			if (trim((string)$value) === '') {
				continue;
			}
			$result[$key] = (string)$value;
		}
		return $result;
	}

	// ------------------------------------------------------------------------------------- B: Auftrag → Sterbefall

	public function continueOrderPreview(int $orderId): array {
		$order = $this->requireKind($orderId, CaseKinds::ORDER);
		$type = $this->orderTypes?->byKey((string)$order['orderType']);
		$derived = array_values(array_filter($this->cases->derivedCases($orderId), static fn (array $case): bool => $case['caseKind'] === CaseKinds::DEATH));
		return [
			'order' => ['id' => $orderId, 'caseNumber' => $order['caseNumber'], 'orderType' => $order['orderType'], 'orderTypeLabel' => $order['orderTypeLabel'] ?? '', 'status' => $order['status']],
			'requiresDeceased' => (bool)($type['requiresDeceased'] ?? false),
			'person' => ['firstName' => (string)$order['firstName'], 'lastName' => (string)$order['lastName'], 'dateOfDeath' => (string)($order['masterData']['date_of_death'] ?? '')],
			'positions' => $this->transferPlan($orderId, true, true)['positions'],
			'derived' => $derived,
		];
	}

	/**
	 * @param array{firstName?:string,lastName?:string,dateOfDeath?:string,transferPositions?:bool,closeOrder?:string,creationToken?:string} $input
	 */
	public function continueOrderAsDeath(int $orderId, array $input): array {
		$preview = $this->continueOrderPreview($orderId);
		if ($preview['derived'] !== []) {
			throw new \InvalidArgumentException('Der Auftrag wurde bereits als Sterbefall ' . $preview['derived'][0]['caseNumber'] . ' weitergeführt.');
		}
		$order = $this->cases->getCase($orderId);
		$master = self::transferableMaster((array)$order['masterData']);
		foreach ((array)$order['masterData'] as $key => $value) {
			if (str_starts_with((string)$key, 'order_client_') && is_scalar($value)) {
				$master[(string)$key] = (string)$value;
			}
		}
		$firstName = trim((string)($input['firstName'] ?? ''));
		$lastName = trim((string)($input['lastName'] ?? ''));
		if (!$preview['requiresDeceased'] || $firstName !== '' || $lastName !== '') {
			// Bei Verkauf/Trauerfeier ist die Hauptperson der Kunde – für den Sterbefall braucht es die verstorbene Person.
			if ($firstName === '' || $lastName === '') {
				throw new \InvalidArgumentException('Bitte Vor- und Nachname der verstorbenen Person angeben.');
			}
			foreach (['salutation', 'title', 'additional_first_names', 'birth_name', 'date_of_birth', 'birth_place'] as $key) {
				if (!$preview['requiresDeceased']) {
					unset($master[$key]);
				}
			}
			$master['first_name'] = $firstName;
			$master['last_name'] = $lastName;
		} else {
			$master['first_name'] = (string)$order['firstName'];
			$master['last_name'] = (string)$order['lastName'];
			if ($master['first_name'] === '') {
				throw new \InvalidArgumentException('Für den Sterbefall fehlt der Vorname der verstorbenen Person. Bitte angeben.');
			}
		}
		$dateOfDeath = trim((string)($input['dateOfDeath'] ?? ($order['masterData']['date_of_death'] ?? '')));
		if ($dateOfDeath !== '') {
			$master['date_of_death'] = $dateOfDeath;
		}
		$master['branch'] = (string)$order['branch'];
		$case = $this->cases->createCase(['caseKind' => CaseKinds::DEATH, 'masterData' => $master, 'originCaseId' => $orderId, 'creationToken' => (string)($input['creationToken'] ?? ''),
			'branch' => (string)$order['branch'], 'responsibleEmployee' => (string)$order['responsibleEmployee']]);
		$warnings = [];
		$positions = [];
		if (!empty($case['idempotent'])) {
			return ['case' => $case, 'positions' => [], 'warnings' => []];
		}
		// 0.91.1: Vermerk als Notiz „Intern“ statt im Stammdatenfeld „Interne Notizen“.
		$this->transitionNote((int)$case['id'], 'Aus Auftrag ' . $order['caseNumber'] . ' (' . ($order['orderTypeLabel'] ?: $order['orderType']) . ') weitergeführt.', $warnings);
		if (filter_var($input['transferPositions'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
			try {
				$positions = $this->transferPositions($orderId, (int)$case['id'], true, true);
			} catch (\Throwable $error) {
				$warnings[] = 'Die Positionen konnten nicht übernommen werden: ' . $error->getMessage();
			}
		}
		$close = strtoupper(trim((string)($input['closeOrder'] ?? '')));
		if (in_array($close, ['ABGESCHLOSSEN', 'STORNIERT'], true)) {
			try {
				$this->cases->setStatus($orderId, $close, 'Als Sterbefall ' . $case['caseNumber'] . ' weitergeführt.');
			} catch (\Throwable $error) {
				$warnings[] = 'Der Auftrag konnte nicht ' . ($close === 'STORNIERT' ? 'storniert' : 'abgeschlossen') . ' werden: ' . $error->getMessage();
			}
		}
		$this->audit->log($orderId, 'CASE', $orderId, 'CONTINUED_AS_DEATH_CASE', null, ['caseId' => (int)$case['id'], 'caseNumber' => $case['caseNumber']]);
		$this->audit->log((int)$case['id'], 'CASE', (int)$case['id'], 'CREATED_FROM_ORDER', null, ['orderId' => $orderId, 'orderNumber' => $order['caseNumber'], 'positions' => count($positions)]);
		return ['case' => $this->cases->getCase((int)$case['id']), 'positions' => $positions, 'warnings' => $warnings];
	}

	// ------------------------------------------------------------------------------------- C: Ende einer Vorsorge

	public function endPrecare(int $caseId, string $target, string $reason, string $date = ''): array {
		$this->requireKind($caseId, CaseKinds::PRECARE);
		$result = $this->precare->end($caseId, $target, $reason, $date);
		$warnings = [];
		try {
			$this->cases->setStatus($caseId, 'ABGESCHLOSSEN', ($result['statusLabel'] ?? $target) . ': ' . trim($reason));
		} catch (\Throwable $error) {
			$warnings[] = 'Die Akte konnte nicht abgeschlossen werden: ' . $error->getMessage();
		}
		return $result + ['warnings' => $warnings];
	}

	// ------------------------------------------------------------------------------------- D: Vorsorge → Sterbefall

	/** Vorschau der Übernahme – verändert nichts. $deathCaseId > 0: in einen schon angelegten Sterbefall übernehmen. */
	public function redeemPreview(int $precareId, int $deathCaseId = 0): array {
		$source = $this->requireKind($precareId, CaseKinds::PRECARE);
		$precare = $this->precare->forCase($precareId);
		if (!$precare['active']) {
			throw new \InvalidArgumentException('Die Vorsorge ist ' . mb_strtolower((string)$precare['statusLabel']) . ' und kann nicht mehr eingelöst werden.');
		}
		$target = $deathCaseId > 0 ? $this->requireKind($deathCaseId, CaseKinds::DEATH) : null;
		if ($target !== null && (int)$target['originCaseId'] > 0 && (int)$target['originCaseId'] !== $precareId) {
			throw new \InvalidArgumentException('Der Sterbefall ist bereits mit einer anderen Akte verknüpft.');
		}
		$master = self::transferableMaster((array)$source['masterData']);
		$fields = [];
		foreach ($master as $key => $value) {
			$current = $target !== null ? trim((string)($target['masterData'][$key] ?? '')) : '';
			$fields[] = ['key' => $key, 'value' => $value, 'current' => $current, 'action' => $current === '' ? 'SET' : ($current === $value ? 'SAME' : 'KEEP')];
		}
		$hasPositions = $target !== null && array_filter($this->articles->caseServices($deathCaseId)['items'] ?? [], static fn (array $item): bool => ($item['serviceStatus'] ?? '') !== 'STORNIERT') !== [];
		$plan = $this->transferPlan($precareId, $precare['priceMode'] === 'GUARANTEED', false);
		$files = [];
		$orderFolder = $this->installation->orderFolder();
		try {
			foreach ($this->files->list($source)['files'] as $file) {
				$haystack = mb_strtolower((string)$file['name'] . ' ' . (string)$file['title']);
				$selected = (string)$file['subfolder'] === $orderFolder || in_array((string)$file['documentType'], ['Auftrag', 'Urkunde', 'Nachweis'], true) || preg_match('/vorsorge|verfügung|verfuegung|vollmacht|testament/u', $haystack) === 1;
				$files[] = ['fileId' => (int)$file['fileId'], 'name' => (string)$file['name'], 'title' => (string)$file['title'], 'subfolder' => (string)$file['subfolder'], 'documentType' => (string)$file['documentType'], 'selected' => $selected];
			}
		} catch (\Throwable) {
			$files = [];
		}
		$contacts = array_values(array_filter($this->records->list('case_contact', $precareId), static fn (array $record): bool => ($record['data']['source'] ?? '') !== 'order-client'));
		return [
			'precare' => ['id' => $precareId, 'caseNumber' => $source['caseNumber'], 'name' => trim($source['firstName'] . ' ' . $source['lastName']), 'dateOfBirth' => (string)($source['masterData']['date_of_birth'] ?? ''),
				'status' => $precare['status'], 'statusLabel' => $precare['statusLabel'], 'priceMode' => $precare['priceMode'], 'priceModeLabel' => $precare['priceModeLabel'], 'contractDate' => $precare['contractDate']],
			'target' => $target !== null ? ['id' => $deathCaseId, 'caseNumber' => $target['caseNumber'], 'name' => trim($target['firstName'] . ' ' . $target['lastName']), 'hasPositions' => $hasPositions] : null,
			'fields' => $fields,
			'positions' => $plan['positions'], 'positionTotals' => $plan['totals'], 'positionsBlocked' => $hasPositions,
			'fundings' => array_map(static fn (array $funding): array => $funding + ['notification' => in_array($funding['status'], ['BEANTRAGT', 'AKTIV'], true) ? (self::NOTIFICATION_TEMPLATES[$funding['fundingType']] ?? '') : ''], $precare['fundings']),
			'files' => $files,
			'contacts' => array_map(static fn (array $record): array => ['id' => (int)$record['id'], 'title' => (string)$record['title'], 'role' => (string)($record['data']['role'] ?? '')], $contacts),
			'orderClient' => self::orderClientSuggestion($contacts),
			'labels' => array_map(static fn (array $label): array => ['key' => $label['key'], 'text' => $label['text']], $this->labels->forCase($precareId)['labels'] ?? []),
		];
	}

	/**
	 * @param array{deathCaseId?:int,dateOfDeath?:string,timeOfDeath?:string,placeOfDeath?:string,transferPositions?:bool,fileIds?:list<int>,copyContacts?:bool,createNotifications?:bool,creationToken?:string} $input
	 */
	public function redeem(int $precareId, array $input): array {
		$deathCaseId = (int)($input['deathCaseId'] ?? 0);
		$preview = $this->redeemPreview($precareId, $deathCaseId);
		$source = $this->cases->getCase($precareId);
		$warnings = [];
		$dateOfDeath = trim((string)($input['dateOfDeath'] ?? ''));
		if ($deathCaseId === 0) {
			if ($dateOfDeath === '') {
				throw new \InvalidArgumentException('Bitte das Sterbedatum angeben.');
			}
			$master = self::transferableMaster((array)$source['masterData']);
			$master['date_of_death'] = $dateOfDeath;
			foreach (['time_of_death' => 'timeOfDeath', 'place_of_death' => 'placeOfDeath'] as $key => $field) {
				if (trim((string)($input[$field] ?? '')) !== '') {
					$master[$key] = trim((string)$input[$field]);
				}
			}
			$master += $preview['orderClient'];
			$master['branch'] = (string)$source['branch'];
			$case = $this->cases->createCase(['caseKind' => CaseKinds::DEATH, 'masterData' => $master, 'originCaseId' => $precareId, 'creationToken' => (string)($input['creationToken'] ?? ''),
				'branch' => (string)$source['branch'], 'responsibleEmployee' => (string)($source['responsibleEmployee'] ?? '')]);
			if (!empty($case['idempotent'])) {
				return ['case' => $case, 'warnings' => [], 'positions' => [], 'files' => [], 'notifications' => []];
			}
			$deathCaseId = (int)$case['id'];
			// 0.91.1: Vermerk als Notiz „Intern“ statt im Stammdatenfeld „Interne Notizen“.
			$noteText = 'Aus Vorsorge ' . $source['caseNumber'] . ' übernommen (' . $preview['precare']['priceModeLabel'] . ').';
		} else {
			$target = $this->cases->getCase($deathCaseId);
			$data = (array)$target['masterData'];
			foreach ($preview['fields'] as $field) {
				if ($field['action'] === 'SET') {
					$data[$field['key']] = $field['value'];
				}
			}
			foreach ($preview['orderClient'] as $key => $value) {
				if (trim((string)($data[$key] ?? '')) === '') {
					$data[$key] = $value;
				}
			}
			if ($dateOfDeath !== '' && trim((string)($data['date_of_death'] ?? '')) === '') {
				$data['date_of_death'] = $dateOfDeath;
			}
			$this->cases->updateMasterData($deathCaseId, $data);
			$this->cases->linkOrigin($deathCaseId, $precareId);
			$noteText = 'Vorsorge ' . $source['caseNumber'] . ' eingelöst (' . $preview['precare']['priceModeLabel'] . ').';
		}
		$this->transitionNote($deathCaseId, $noteText, $warnings);
		$death = $this->cases->getCase($deathCaseId);
		$positions = [];
		if (filter_var($input['transferPositions'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
			if ($preview['positionsBlocked']) {
				$warnings[] = 'Im Sterbefall sind bereits Positionen erfasst. Die Positionen des Vorsorgevertrags bitte von Hand ergänzen.';
			} else {
				try {
					$positions = $this->transferPositions($precareId, $deathCaseId, $preview['precare']['priceMode'] === 'GUARANTEED', false);
				} catch (\Throwable $error) {
					$warnings[] = 'Die Positionen konnten nicht übernommen werden: ' . $error->getMessage();
				}
			}
		}
		$copied = [];
		$fileIds = array_values(array_filter(array_map('intval', (array)($input['fileIds'] ?? array_column(array_filter($preview['files'], static fn (array $file): bool => $file['selected']), 'fileId')))));
		if ($fileIds !== []) {
			try {
				$copied = $this->files->copyToCase($source, $death, $fileIds);
			} catch (\Throwable $error) {
				$warnings[] = 'Dokumente konnten nicht kopiert werden: ' . $error->getMessage();
			}
		}
		if (filter_var($input['copyContacts'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
			$warnings = array_merge($warnings, $this->copyContacts($precareId, $deathCaseId));
		}
		$labelInput = array_map(static fn (array $label): array => ['key' => $label['key'], 'value' => $label['value'] ?? '', 'note' => $label['note'] ?? null], $this->labels->forCase($precareId)['labels'] ?? []);
		// Marker „Kostenträger“ (Einrichtungspaket): Treuhand bzw. Versicherung aus der ersten laufenden Finanzierung
		foreach ($preview['fundings'] as $funding) {
			if (in_array($funding['status'], ['BEANTRAGT', 'AKTIV'], true) && isset(self::PAYER_LABEL_VALUES[$funding['fundingType']])) {
				$labelInput[] = ['key' => 'KOSTENTRAEGER', 'value' => self::PAYER_LABEL_VALUES[$funding['fundingType']]];
				break;
			}
		}
		if ($labelInput !== []) {
			$this->labels->setInitial($deathCaseId, $labelInput);
		} // nicht eingerichtete Marker werden übergangen
		$notifications = [];
		if (filter_var($input['createNotifications'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
			foreach ($preview['fundings'] as $funding) {
				if ($funding['notification'] === '') {
					continue;
				}
				try {
					$record = $this->deregistrations->save($deathCaseId, ['templateKey' => $funding['notification'], 'status' => 'ENTWURF', 'contactId' => 0, 'deliveryChannel' => '',
						'referenceNumber' => $funding['contractNumber'], 'precareCaseId' => $precareId, 'precareFundingId' => $funding['id'], 'providerName' => $funding['providerName']]);
					$notifications[] = ['id' => (int)$record['id'], 'title' => (string)$record['title'], 'provider' => $funding['providerName']];
				} catch (\Throwable $error) {
					$warnings[] = 'Sterbefallmeldung an ' . ($funding['providerName'] ?: $funding['fundingTypeLabel']) . ' nicht vorbereitet: ' . $error->getMessage();
				}
			}
		}
		$this->precare->markRedeemed($precareId, $deathCaseId);
		try {
			$this->cases->setStatus($precareId, 'ABGESCHLOSSEN', 'Vorsorge eingelöst: Sterbefall ' . $death['caseNumber']);
		} catch (\Throwable $error) {
			$warnings[] = 'Die Vorsorge konnte nicht abgeschlossen werden: ' . $error->getMessage();
		}
		$this->audit->log($precareId, 'PRECARE', $precareId, 'REDEEMED_INTO', null, ['caseId' => $deathCaseId, 'caseNumber' => $death['caseNumber']]);
		$this->audit->log($deathCaseId, 'CASE', $deathCaseId, 'CREATED_FROM_PRECARE', null, ['precareId' => $precareId, 'precareNumber' => $source['caseNumber'], 'positions' => count($positions), 'files' => count($copied), 'notifications' => count($notifications)]);
		return ['case' => $this->cases->getCase($deathCaseId), 'warnings' => $warnings, 'positions' => $positions, 'files' => $copied, 'notifications' => $notifications];
	}

	/**
	 * 0.91.1: Vermerk zum Übergang als Notiz (Kategorie „Intern“) im neuen bzw. gewählten Sterbefall. Ein Fehler hält den
	 * Übergang nicht auf, sondern wird als Hinweis gemeldet.
	 * @param list<string> $warnings
	 */
	private function transitionNote(int $caseId, string $text, array &$warnings): void {
		try {
			foreach (CaseNoteRules::systemPayloads($text, CaseNoteRules::CATEGORY_INTERNAL, CaseNoteRules::SOURCE_TRANSITION) as $payload) {
				$this->records->create(CaseNoteRules::RECORD_TYPE, CaseNoteRules::title((string)$payload['text']), date('c'), CaseNoteRules::STATUS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $caseId);
			}
		} catch (\Throwable $error) {
			$warnings[] = 'Der Vermerk „' . $text . '“ konnte nicht als Notiz gespeichert werden: ' . $error->getMessage();
		}
	}

	/** Für den Sterbefall: passende aktive Vorsorgen (Hinweis in der Akte, Schnellerfassung). */
	public function candidatesForCase(int $caseId): array {
		$case = $this->cases->getCase($caseId);
		if (($case['caseKind'] ?? '') !== CaseKinds::DEATH || (int)$case['originCaseId'] > 0) {
			return [];
		}
		return $this->cases->precareCandidates((string)$case['firstName'], (string)$case['lastName'], (string)($case['masterData']['date_of_birth'] ?? ''), $caseId);
	}

	/** Herkunft und Finanzierungen einer eingelösten Vorsorge für die Sterbefallakte. */
	public function originForCase(int $caseId): ?array {
		$case = $this->cases->getCase($caseId);
		$originId = (int)$case['originCaseId'];
		if ($originId <= 0) {
			return null;
		}
		try {
			$origin = $this->cases->getCase($originId);
		} catch (\InvalidArgumentException) {
			return null;
		}
		$result = ['id' => $originId, 'caseNumber' => (string)$origin['caseNumber'], 'caseKind' => (string)$origin['caseKind'], 'caseKindLabel' => (string)$origin['caseKindLabel'], 'fundings' => []];
		if ($origin['caseKind'] === CaseKinds::PRECARE) {
			$precare = $this->precare->row($originId);
			$result['priceMode'] = (string)($precare['priceMode'] ?? '');
			$result['priceModeLabel'] = PrecareService::PRICE_MODES[$result['priceMode']] ?? '';
			$result['fundings'] = $this->precare->fundings($originId);
		}
		return $result;
	}

	/** Treuhänder/Versicherer hat gezahlt: Finanzierung der eingelösten Vorsorge als „ausgezahlt“ markieren. */
	public function markFundingPaid(int $deathCaseId, int $fundingId): array {
		$origin = $this->originForCase($deathCaseId);
		if ($origin === null || $origin['caseKind'] !== CaseKinds::PRECARE) {
			throw new \InvalidArgumentException('Der Fall stammt nicht aus einer Vorsorge.');
		}
		$this->precare->setFundingStatus((int)$origin['id'], $fundingId, 'AUSGEZAHLT');
		$this->audit->log($deathCaseId, 'PRECARE_FUNDING', $fundingId, 'PAID_OUT', null, ['precareId' => $origin['id']]);
		return $this->originForCase($deathCaseId) ?? [];
	}

	// ------------------------------------------------------------------------------------- intern

	private function requireKind(int $caseId, string $kind): array {
		$case = $this->cases->getCase($caseId);
		if (($case['caseKind'] ?? CaseKinds::DEATH) !== $kind) {
			throw new \InvalidArgumentException('Diese Aktion gibt es nur für ' . CaseKinds::PLURAL_LABELS[$kind] . '.');
		}
		if (($case['anonymizedAt'] ?? '') !== '') {
			throw new \InvalidArgumentException('Die Akte ist anonymisiert.');
		}
		return $case;
	}

	/** Vertrauensperson (bzw. erste weitere Person) als Vorschlag für Auftraggeber/in im Sterbefall. @return array<string,string> */
	public static function orderClientSuggestion(array $contacts): array {
		$persons = array_values(array_filter($contacts, static fn (array $record): bool => ($record['data']['source'] ?? '') === 'case-person'));
		usort($persons, static fn (array $a, array $b): int => (preg_match('/vertrau|bevollm/i', (string)($a['data']['role'] ?? '')) === 1 ? 0 : 1) <=> (preg_match('/vertrau|bevollm/i', (string)($b['data']['role'] ?? '')) === 1 ? 0 : 1));
		$person = $persons[0]['data']['person'] ?? null;
		if (!is_array($person) || trim((string)($person['lastName'] ?? '')) === '') {
			return [];
		}
		$suggestion = [
			'order_client_salutation' => (string)($person['salutation'] ?? ''), 'order_client_first_name' => (string)($person['firstName'] ?? ''), 'order_client_name' => (string)($person['lastName'] ?? ''),
			'order_client_street' => (string)($person['street'] ?? ''), 'order_client_postal_city' => trim((string)($person['postalCode'] ?? '') . ' ' . (string)($person['city'] ?? '')),
			'order_client_phone' => (string)($person['phone'] ?? ''), 'order_client_mobile' => (string)($person['mobile'] ?? ''), 'order_client_email' => (string)($person['email'] ?? ''),
			'order_client_relation' => (string)($persons[0]['data']['role'] ?? ''),
		];
		return array_filter($suggestion, static fn (string $value): bool => trim($value) !== '');
	}

	/** @return list<string> Hinweise */
	private function copyContacts(int $sourceId, int $targetId): array {
		$warnings = [];
		$existing = array_map(static fn (array $record): string => mb_strtolower(trim((string)$record['title'])), $this->records->list('case_contact', $targetId));
		foreach ($this->records->list('case_contact', $sourceId) as $record) {
			if (($record['data']['source'] ?? '') === 'order-client' || in_array(mb_strtolower(trim((string)$record['title'])), $existing, true)) {
				continue;
			}
			$data = (array)($record['data'] ?? []);
			$data['copiedFrom'] = $sourceId;
			try {
				$this->records->create('case_contact', (string)$record['title'], date('c'), 'AKTIV', json_encode($data, JSON_THROW_ON_ERROR), $targetId);
			} catch (\Throwable $error) {
				$warnings[] = 'Kontakt „' . $record['title'] . '“ nicht übernommen: ' . $error->getMessage();
			}
		}
		return $warnings;
	}

	/**
	 * Positionen der Herkunftsakte als Auswahl für die neue Akte. $guaranteed: Preise der Herkunft beibehalten (Preisgarantie,
	 * Weiterführung eines Auftrags), sonst heutige Preise laut Preisliste. $onlyOpen: nur noch nicht berechnete Mengen.
	 * @return array{selections:list<array>,positions:list<array>,totals:array{contractCents:int,appliedCents:int}}
	 */
	private function transferPlan(int $sourceId, bool $guaranteed, bool $onlyOpen): array {
		$costing = $this->articles->caseServices($sourceId);
		$prices = (array)($costing['prices'] ?? []);
		$known = [];
		foreach ($this->articles->allArticles() as $article) {
			$known[(int)$article['id']] = $article;
		}
		$children = [];
		foreach ($costing['items'] ?? [] as $item) {
			if ($item['parentServiceId'] !== null && ($item['serviceStatus'] ?? '') !== 'STORNIERT' && $item['bomItemId'] !== null) {
				$children[(int)$item['parentServiceId']][] = (int)$item['bomItemId'];
			}
		}
		$selections = [];
		$positions = [];
		$contractTotal = 0;
		$appliedTotal = 0;
		foreach ($costing['items'] ?? [] as $item) {
			if ($item['parentServiceId'] !== null || ($item['serviceStatus'] ?? '') === 'STORNIERT' || ($item['origin'] ?? '') === 'INCOMING_INVOICE') {
				continue;
			}
			$articleId = (int)$item['articleId'];
			$article = $known[$articleId] ?? null;
			$quantityMilli = (int)$item['orderedQuantityMilli'] - ($onlyOpen ? (int)$item['invoicedQuantityMilli'] : 0);
			if (!$item['isPackageHeader'] && $quantityMilli <= 0) {
				continue;
			}
			$quantity = $quantityMilli / 1000;
			if ($item['isPackageHeader']) {
				if ($article === null) {
					$positions[] = ['title' => (string)$item['title'], 'quantity' => 1, 'contractCents' => (int)($item['packagePriceCents'] ?? 0), 'currentCents' => null, 'appliedCents' => null, 'skipped' => true, 'note' => 'Paket nicht mehr im Leistungskatalog'];
					continue;
				}
				$contract = (int)($item['packagePriceCents'] ?? 0);
				$current = isset($prices[$articleId]) ? (int)$prices[$articleId] : null;
				$applied = $guaranteed || $current === null ? $contract : $current;
				$selection = ['articleId' => $articleId, 'packageConfig' => ['selected' => $children[(int)$item['id']] ?? []], 'printMode' => (string)($item['printMode'] ?? '')];
				if ($guaranteed) {
					$selection['packagePriceCents'] = $contract;
				}
				$selections[] = $selection;
				$positions[] = ['title' => (string)$item['title'], 'quantity' => 1, 'contractCents' => $contract, 'currentCents' => $current, 'appliedCents' => $applied, 'skipped' => false, 'note' => 'Paket'];
				$contractTotal += $contract;
				$appliedTotal += $applied;
				continue;
			}
			$contract = (int)$item['unitPriceCents'];
			if ($articleId > 0 && $article !== null) {
				$current = !empty($item['priceOnEntry']) ? $contract : (isset($prices[$articleId]) ? (int)$prices[$articleId] : null);
				$keep = $guaranteed || !empty($item['priceOnEntry']) || $current === null;
				$selection = ['articleId' => $articleId, 'quantity' => $quantity, 'positionType' => (string)$item['positionType'], 'note' => (string)$item['note'], 'unit' => (string)$item['unit'], 'priceBasis' => (string)($item['priceBasis'] ?? '')];
				if ($keep) {
					$selection += ['priceEdited' => true, 'unitPriceCents' => $contract];
				}
				$applied = $keep ? $contract : (int)$current;
			} else {
				// Freitext bzw. nicht mehr im Katalog: als Freitextposition mit dem vereinbarten Preis
				$current = null;
				$selection = ['articleId' => 0, 'title' => (string)$item['title'], 'longText' => (string)$item['longText'], 'note' => trim((string)$item['note'] . ($articleId > 0 ? ' (Artikel ' . $item['articleNumber'] . ' nicht mehr im Katalog)' : '')),
					'quantity' => $quantity, 'positionType' => (string)$item['positionType'], 'unitPriceCents' => $contract, 'vatRate' => (int)$item['vatRate'], 'unit' => (string)$item['unit'], 'priceBasis' => (string)($item['priceBasis'] ?? '')];
				$applied = $contract;
			}
			$selections[] = $selection;
			$positions[] = ['title' => (string)$item['title'], 'quantity' => $quantity, 'contractCents' => $contract, 'currentCents' => $current, 'appliedCents' => $applied, 'skipped' => false, 'note' => $articleId > 0 && $article === null ? 'nicht mehr im Katalog – als Freitext' : ''];
			$contractTotal += (int)round($quantity * $contract);
			$appliedTotal += (int)round($quantity * $applied);
		}
		return ['selections' => $selections, 'positions' => $positions, 'totals' => ['contractCents' => $contractTotal, 'appliedCents' => $appliedTotal]];
	}

	/** @return list<array> übernommene Positionen mit Preisvergleich */
	private function transferPositions(int $sourceId, int $targetId, bool $guaranteed, bool $onlyOpen): array {
		$plan = $this->transferPlan($sourceId, $guaranteed, $onlyOpen);
		if ($plan['selections'] === []) {
			return [];
		}
		$this->articles->saveCaseServices($targetId, $plan['selections']);
		return $plan['positions'];
	}
}
