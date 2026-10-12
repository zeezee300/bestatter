<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IDBConnection;
use OCP\IUserSession;

class CommercialService {
	private const BILLABILITIES = ['ABRECHENBAR', 'KULANZ', 'NICHT_ABRECHENBAR', 'UNKLAR'];
	/**
	 * 0.70.0: Eine ausgestellte Rechnung (ab FREIGEGEBEN) wird nicht mehr per Status storniert, sondern durch
	 * eine Stornorechnung mit eigener Nummer aufgehoben; „STORNIERT“ aus ENTWURF/PRUEFUNG verwirft nur Entwürfe.
	 */
	private const INVOICE_TRANSITIONS = [
		'ENTWURF' => ['PRUEFUNG', 'STORNIERT'],
		'PRUEFUNG' => ['FREIGEGEBEN', 'ENTWURF', 'STORNIERT'],
		'FREIGEGEBEN' => ['VERSENDET'],
		'VERSENDET' => ['TEILBEZAHLT', 'BEZAHLT'],
		'TEILBEZAHLT' => ['BEZAHLT'],
	];
	/** Stornorechnung und Gutschrift: BEZAHLT bedeutet hier „erstattet bzw. verrechnet“. */
	private const CORRECTION_TRANSITIONS = [
		'ENTWURF' => ['PRUEFUNG', 'STORNIERT'],
		'PRUEFUNG' => ['FREIGEGEBEN', 'ENTWURF', 'STORNIERT'],
		'FREIGEGEBEN' => ['VERSENDET', 'BEZAHLT'],
		'VERSENDET' => ['BEZAHLT'],
	];
	private const ISSUED_STATUSES = ['FREIGEGEBEN', 'VERSENDET', 'TEILBEZAHLT', 'BEZAHLT'];
	public const DOCUMENT_KINDS = ['INVOICE' => 'Rechnung', 'CANCELLATION' => 'Stornorechnung', 'CREDIT_NOTE' => 'Gutschrift'];

	public function __construct(
		private IDBConnection $db,
		private IUserSession $userSession,
		private CaseService $cases,
		private ArticleService $articles,
		private ConfigurationService $configuration,
		private IncomingInvoiceService $incomingInvoices,
		private AuditService $audit,
		private CommercialStateService $commercialState,
		private SideOrderService $sideOrders,
	) {}

	public function overview(int $caseId, ?int $sideOrderId = null): array {
		if ($sideOrderId === null) $this->synchronizeOrderStatus($caseId);
		$sideOrder = $sideOrderId !== null ? $this->sideOrders->get($sideOrderId) : null;
		if ($sideOrder !== null && $sideOrder['caseId'] !== $caseId) throw new \InvalidArgumentException('Der Nebenauftrag gehört nicht zu diesem Fall.');
		return [
			'documents' => $this->documents($caseId, $sideOrderId),
			'services' => $this->articles->caseServices($caseId, $sideOrderId),
			'billingCheck' => $this->billingCheckForScope($caseId, 'FINAL', $sideOrderId),
			'invoices' => $this->invoices($caseId, $sideOrderId),
			'incomingInvoices' => $this->incomingInvoices->overview($caseId),
			'sideOrderId' => $sideOrderId,
			'sideOrder' => $sideOrder,
			'statuses' => ['service' => array_keys(CatalogRules::SERVICE_STATUS_LABELS), 'serviceLabels' => CatalogRules::SERVICE_STATUS_LABELS, 'billability' => self::BILLABILITIES],
		];
	}

	public function finalizationCheck(int $caseId, string $documentType = 'ORDER', array $input = []): array {
		$type = strtoupper(trim($documentType));
		if (!in_array($type, ['ORDER', 'QUOTE'], true)) throw new \InvalidArgumentException('Unbekannte Art der Festschreibung.');
		$case = $this->cases->getCase($caseId);
		$master = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$services = $this->articles->caseServices($caseId);
		$errors = [];
		$warnings = [];
		$issue = static fn(string $code, string $label, string $message, string $field = '', int $serviceId = 0): array => compact('code', 'label', 'message', 'field', 'serviceId');
		if (trim((string)($case['caseNumber'] ?? '')) === '') $errors[] = $issue('CASE_NUMBER', 'Fallnummer', 'Die Fallnummer fehlt.', 'case_number');
		if (trim((string)($case['lastName'] ?? $master['last_name'] ?? '')) === '') $errors[] = $issue('DECEASED_NAME', 'Verstorbene Person', 'Der Nachname der verstorbenen Person fehlt.', 'last_name');
		if (trim((string)($case['firstName'] ?? $master['first_name'] ?? '')) === '') $errors[] = $issue('DECEASED_FIRST_NAME', 'Verstorbene Person', 'Der Vorname der verstorbenen Person fehlt.', 'first_name');

		$clientFields = [
			'order_client_name' => (string)($master['order_client_name'] ?? ''),
			'order_client_first_name' => (string)($master['order_client_first_name'] ?? ''),
			'order_client_street' => (string)($master['order_client_street'] ?? ''),
			'order_client_postal_city' => (string)($master['order_client_postal_city'] ?? ''),
			'order_client_country' => (string)($master['order_client_country'] ?? ''),
		];
		$labels = ['order_client_name' => 'Auftraggeber: Name', 'order_client_first_name' => 'Auftraggeber: Vorname', 'order_client_street' => 'Auftraggeber: Straße', 'order_client_postal_city' => 'Auftraggeber: PLZ / Ort', 'order_client_country' => 'Auftraggeber: Land'];
		foreach ($clientFields as $field => $value) if (trim($value) === '') $errors[] = $issue('CLIENT_' . strtoupper(substr($field, 13)), $labels[$field], $labels[$field] . ' fehlt.', $field);

		if (strtoupper((string)($master['invoice_same_as_client'] ?? 'JA')) === 'NEIN') {
			foreach (['invoice_name' => 'Rechnungsempfänger: Name', 'invoice_first_name' => 'Rechnungsempfänger: Vorname', 'invoice_street' => 'Rechnungsempfänger: Straße', 'invoice_postal_city' => 'Rechnungsempfänger: PLZ / Ort', 'invoice_country' => 'Rechnungsempfänger: Land'] as $field => $label) {
				if (trim((string)($master[$field] ?? '')) === '') $errors[] = $issue('INVOICE_' . strtoupper(substr($field, 8)), $label, $label . ' fehlt.', $field);
			}
		}

		$number = trim((string)($input['number'] ?? $master['order_number'] ?? ''));
		if ($number === '') $errors[] = $issue('DOCUMENT_NUMBER', $type === 'QUOTE' ? 'KVA-Nummer' : 'Auftragsnummer', 'Die Dokumentnummer fehlt.', 'order_number');
		$dateField = $type === 'QUOTE' ? 'kva_date' : 'order_date';
		$date = trim((string)($input['date'] ?? $master[$dateField] ?? ''));
		if ($date === '') $errors[] = $issue('DOCUMENT_DATE', $type === 'QUOTE' ? 'KVA-Datum' : 'Auftragsdatum', 'Das Dokumentdatum fehlt.', $dateField);
		elseif (!\DateTimeImmutable::createFromFormat('!Y-m-d', $date) || \DateTimeImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date) $errors[] = $issue('DOCUMENT_DATE_INVALID', 'Dokumentdatum', 'Das Dokumentdatum ist ungültig.', $dateField);
		if ($type === 'ORDER' && trim((string)($input['commissioningType'] ?? $master['commissioning_type'] ?? '')) === '') $errors[] = $issue('COMMISSIONING_TYPE', 'Art der Beauftragung', 'Die Art der Beauftragung fehlt.', 'commissioning_type');
		if ($type === 'ORDER' && strtoupper((string)($master['order_mode'] ?? 'A')) !== 'A') $errors[] = $issue('ORDER_MODE', 'Dokumentart', 'Ein direkter Auftrag kann nur im Modus Auftrag festgeschrieben werden.', 'order_mode');
		if ($type === 'QUOTE' && $this->latestOrderId($caseId) !== 0) $errors[] = $issue('ORDER_EXISTS', 'Vertragsstand', 'Es besteht bereits ein festgeschriebener Auftrag.');
		if ($type === 'QUOTE' && $this->latestQuoteId($caseId) !== 0) $errors[] = $issue('QUOTE_EXISTS', 'Vertragsstand', 'Es besteht bereits ein festgeschriebener Kostenvoranschlag.');
		if ($type === 'ORDER' && $this->latestOrderId($caseId) !== 0) $errors[] = $issue('ORDER_EXISTS', 'Vertragsstand', 'Es besteht bereits ein festgeschriebener Auftrag.');
		if ($type === 'ORDER' && $this->latestQuoteId($caseId) !== 0) $errors[] = $issue('QUOTE_EXISTS', 'Vertragsstand', 'Ein vorhandener KVA muss über „Einmalig in Auftrag übernehmen“ übernommen werden.');

		$items = $services['items'] ?? [];
		if ($items === []) $errors[] = $issue('NO_POSITIONS', 'Positionen', 'Mindestens eine fehlerfrei erfasste Position ist erforderlich.', '', 0);
		$allowedVatRates = array_map('intval', (array)($this->configuration->countryProfiles()['allowedVatRates'] ?? [0, 7, 19]));
		$numbers = [];
		foreach ($items as $index => $item) {
			if (!empty($item['isPackageHeader'])) {
				if (($item['serviceStatus'] ?? '') !== 'STORNIERT' && (int)($item['childCount'] ?? 0) === 0) $errors[] = $issue('PACKAGE_EMPTY_' . (int)$item['id'], 'Position ' . (int)$item['position'], 'Das Paket enthält keine Unterpositionen.', '', (int)$item['id']);
				continue;
			}
			if (($item['serviceStatus'] ?? '') === 'STORNIERT') continue;
			$id = (int)($item['id'] ?? 0); $position = (int)($item['position'] ?? (($index + 1) * 10)); $positionLabel = 'Position ' . (string)($item['positionLabel'] ?? $position);
			$inFixedPricePackage = ($item['packagePricingOfParent'] ?? '') === 'HEADER';
			if ($position <= 0 || $position % 10 !== 0) $errors[] = $issue('POSITION_NUMBER_' . $id, $positionLabel, 'Die Positionsnummer muss in 10er-Schritten geführt werden.', '', $id);
			if (trim((string)($item['title'] ?? '')) === '') $errors[] = $issue('POSITION_TITLE_' . $id, $positionLabel, 'Die Bezeichnung fehlt.', '', $id);
			if ((int)($item['orderedQuantityMilli'] ?? 0) <= 0) $errors[] = $issue('POSITION_QUANTITY_' . $id, $positionLabel, 'Die Menge muss größer als 0 sein.', '', $id);
			if (trim((string)($item['unit'] ?? '')) === '') $errors[] = $issue('POSITION_UNIT_' . $id, $positionLabel, 'Die Mengeneinheit fehlt.', '', $id);
			if (!in_array(strtoupper((string)($item['positionType'] ?? '')), ['EL', 'FK', 'DP'], true)) $errors[] = $issue('POSITION_TYPE_' . $id, $positionLabel, 'Der Positionstyp muss EL, FK oder DP sein.', '', $id);
			if (!in_array((int)($item['vatRate'] ?? -1), $allowedVatRates, true)) $errors[] = $issue('POSITION_VAT_' . $id, $positionLabel, 'Der MwSt.-Satz ist nicht zulässig.', '', $id);
			if ((int)($item['articleId'] ?? 0) === 0 && (int)($item['unitPriceCents'] ?? 0) <= 0) $errors[] = $issue('FREE_TEXT_PRICE_' . $id, $positionLabel, 'Eine Freitextposition benötigt einen Preis größer als 0,00 €.', '', $id);
			if (!empty($item['priceOnEntry']) && (int)($item['unitPriceCents'] ?? 0) <= 0) $errors[] = $issue('PRICE_ON_ENTRY_' . $id, $positionLabel, 'Für „' . (string)($item['title'] ?? '') . '“ muss der tatsächliche Preis im Fall erfasst werden' . (($item['guidePriceCents'] ?? null) !== null ? ' (Richtwert ' . number_format((int)$item['guidePriceCents'] / 100, 2, ',', '.') . ' €).' : '.'), '', $id);
			elseif ((int)($item['articleId'] ?? 0) > 0 && (int)($item['unitPriceCents'] ?? 0) <= 0 && !$inFixedPricePackage) $warnings[] = $issue('CATALOG_ZERO_PRICE_' . $id, $positionLabel, 'Katalogposition ohne Preis prüfen.', '', $id);
			if ((int)($item['vatRate'] ?? 0) === 0 && strtoupper((string)($item['positionType'] ?? '')) !== 'DP') $warnings[] = $issue('ZERO_VAT_' . $id, $positionLabel, '0 % MwSt. fachlich prüfen.', '', $id);
			if (in_array(strtoupper((string)($item['positionType'] ?? '')), ['FK', 'DP'], true) && trim((string)($item['note'] ?? '')) === '') $warnings[] = $issue('CLASSIFICATION_NOTE_' . $id, $positionLabel, 'Für FK/DP ist noch keine erläuternde Positionsnotiz hinterlegt.', '', $id);
			$articleNumber = strtoupper(trim((string)($item['articleNumber'] ?? '')));
			if ($articleNumber !== '' && (int)($item['articleId'] ?? 0) > 0) $numbers[$articleNumber][] = (string)($item['positionLabel'] ?? $position);
		}
		foreach ($this->articles->selectionRuleViolations($items, true) as $violation) $errors[] = $issue('GROUP_RULE_' . $violation['type'] . '_' . preg_replace('/[^A-Z0-9]/', '_', strtoupper((string)$violation['groupKey'])), 'Auswahlregel', $violation['message']);
		foreach ($numbers as $articleNumber => $positions) if (count($positions) > 1) $warnings[] = $issue('DUPLICATE_' . preg_replace('/[^A-Z0-9]/', '_', (string)$articleNumber), 'Doppelte Katalogposition', (string)$articleNumber . ' ist in den Positionen ' . implode(', ', $positions) . ' mehrfach enthalten.');
		if ((int)($services['totals']['grossCents'] ?? 0) <= 0) $warnings[] = $issue('ZERO_TOTAL', 'Gesamtsumme', 'Die Gesamtsumme ist 0,00 €; bitte bewusst prüfen.');
		if (trim(implode('', [(string)($master['order_client_phone'] ?? ''), (string)($master['order_client_mobile'] ?? ''), (string)($master['order_client_email'] ?? '')])) === '') $warnings[] = $issue('NO_CONTACT', 'Kontaktdaten', 'Für den Auftraggeber ist weder Telefon, Mobilnummer noch E-Mail hinterlegt.', 'order_client_phone');
		if ($type === 'ORDER' && trim((string)($master['order_signature_name'] ?? '')) === '') $warnings[] = $issue('NO_SIGNATURE', 'Beauftragungsnachweis', 'Es liegt noch keine digitale Bestätigung/Unterschrift vor.', 'order_signature_name');
		if (($master['payment_method'] ?? 'TRANSFER') === 'SEPA_DIRECT_DEBIT') {
			foreach (['sepa_mandate_reference' => 'SEPA-Mandatsreferenz', 'sepa_mandate_date' => 'Mandatsdatum', 'sepa_collection_date' => 'Einzugsdatum', 'sepa_debtor_name' => 'Kontoinhaber/in', 'sepa_debtor_iban' => 'IBAN'] as $field => $label) if (trim((string)($master[$field] ?? '')) === '') $errors[] = $issue('SEPA_' . strtoupper(substr($field, 5)), $label, $label . ' fehlt.', $field);
		}
		return ['result' => $errors !== [] ? 'KRITISCH' : ($warnings !== [] ? 'WARNUNG' : 'OK'), 'documentType' => $type, 'errors' => $errors, 'warnings' => $warnings, 'totals' => $services['totals'] ?? ['netCents' => 0, 'vatCents' => 0, 'grossCents' => 0]];
	}

	private function assertFinalizationAllowed(int $caseId, string $documentType, array $input): array {
		$check = $this->finalizationCheck($caseId, $documentType, $input);
		if ($check['errors'] !== []) throw new \InvalidArgumentException('Festschreibung nicht möglich: ' . implode(' ', array_column($check['errors'], 'message')));
		$confirmed = array_map('strval', (array)($input['confirmedWarningCodes'] ?? []));
		$openWarnings = array_values(array_filter($check['warnings'], static fn(array $warning): bool => !in_array($warning['code'], $confirmed, true)));
		if ($openWarnings !== []) throw new \InvalidArgumentException('Hinweise müssen vor der Festschreibung bestätigt werden: ' . implode(' ', array_column($openWarnings, 'message')));
		$check['confirmedWarningCodes'] = $confirmed;
		$check['checkedAt'] = date('c');
		$check['checkedBy'] = $this->uid();
		return $check;
	}

	public function freezeQuote(int $caseId, array $input = []): array {
		$finalizationCheck = $this->assertFinalizationAllowed($caseId, 'QUOTE', $input);
		$case = $this->cases->getCase($caseId);
		$services = $this->articles->caseServices($caseId);
		if (count($services['items']) === 0) throw new \InvalidArgumentException('Für ein KVA muss mindestens eine Leistung ausgewählt sein.');
		$master = $case['masterData'] ?? [];
		$number = trim((string)($input['number'] ?? $master['kva_number'] ?? '')) ?: 'KVA-' . $case['caseNumber'];
		$snapshot = ['case' => $case, 'recipient' => $this->recipient($master), 'items' => $services['items'], 'totals' => $services['totals'], 'validUntil' => (string)($input['validUntil'] ?? ''), 'finalizationCheck' => $finalizationCheck];
		$this->db->beginTransaction();
		try {
			$this->lockCase($caseId);
			if ($this->hasActiveFinalInvoice($caseId)) throw new \InvalidArgumentException('Für diesen Fall besteht bereits eine Schlussrechnung. Weitere Rechnungen sind gesperrt.');
			if ($this->latestOrderId($caseId) !== 0) throw new \InvalidArgumentException('Für diesen Fall besteht bereits ein Auftrag. Ein weiterer Kostenvoranschlag kann nicht festgeschrieben werden.');
			if ($this->latestQuoteId($caseId) !== 0) throw new \InvalidArgumentException('Für diesen Fall wurde bereits ein Kostenvoranschlag festgeschrieben. Er bleibt bis zur einmaligen Auftragsübernahme der verbindliche Nachweis.');
			$id = $this->insertDocument($caseId, 'QUOTE', $number, 1, 'FESTGESCHRIEBEN', null, $snapshot, $services['totals']);
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		$this->audit->log($caseId, 'QUOTE', $id, 'VERSION_CREATED', null, $snapshot);
		$this->synchronizeOrderStatus($caseId);
		return $this->document($id);
	}

	public function freezeOrder(int $caseId, array $input = []): array {
		$finalizationCheck = $this->assertFinalizationAllowed($caseId, 'ORDER', $input);
		$case = $this->cases->getCase($caseId);
		$services = $this->articles->caseServices($caseId);
		if (count($services['items']) === 0) throw new \InvalidArgumentException('Für einen Auftrag muss mindestens eine Leistung ausgewählt sein.');
		$master = $case['masterData'] ?? [];
		if (strtoupper((string)($master['order_mode'] ?? 'A')) !== 'A') throw new \InvalidArgumentException('Ein direkter Auftrag kann nur im Modus „Auftrag“ festgeschrieben werden.');
		$number = trim((string)($input['number'] ?? $master['order_number'] ?? '')) ?: 'A-' . $case['caseNumber'];
		$snapshot = [
			'case' => $case,
			'recipient' => $this->recipient($master),
			'items' => $services['items'],
			'totals' => $services['totals'],
			'commissioning' => [
				'type' => (string)($input['commissioningType'] ?? $master['commissioning_type'] ?? 'SCHRIFTLICH'),
				'date' => (string)($input['date'] ?? $master['order_date'] ?? date('Y-m-d')),
				'note' => (string)($input['note'] ?? ''),
			],
			'finalizationCheck' => $finalizationCheck,
		];
		$this->db->beginTransaction();
		try {
			$this->lockCase($caseId);
			if ($this->latestOrderId($caseId) !== 0) throw new \InvalidArgumentException('Für diesen Fall wurde bereits ein Auftrag festgeschrieben.');
			if ($this->latestQuoteId($caseId) !== 0) throw new \InvalidArgumentException('Für diesen Fall besteht ein festgeschriebener KVA. Bitte diesen einmalig in einen Auftrag übernehmen.');
			$id = $this->insertDocument($caseId, 'ORDER', $number, 1, 'BEAUFTRAGT', null, $snapshot, $services['totals']);
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		$this->audit->log($caseId, 'ORDER', $id, 'VERSION_CREATED', null, $snapshot);
		$this->synchronizeOrderStatus($caseId);
		return $this->document($id);
	}

	public function convertQuoteToOrder(int $caseId, int $quoteId, array $input = []): array {
		$quote = $this->document($quoteId);
		if ($quote['caseId'] !== $caseId || $quote['documentType'] !== 'QUOTE') throw new \InvalidArgumentException('Die KVA-Version gehört nicht zu diesem Fall.');
		if (($quote['convertedOrderId'] ?? null) !== null || $quote['status'] === 'UEBERNOMMEN') throw new \InvalidArgumentException('Dieser Kostenvoranschlag wurde bereits in einen Auftrag übernommen. Eine zweite Übernahme ist nicht zulässig.');
		$case = $this->cases->getCase($caseId);
		$number = trim((string)($input['number'] ?? '')) ?: 'A-' . $case['caseNumber'];
		$version = 1;
		$snapshot = $quote['snapshot'];
		$snapshot['commissioning'] = ['type' => (string)($input['commissioningType'] ?? 'SCHRIFTLICH'), 'date' => (string)($input['date'] ?? date('Y-m-d')), 'note' => (string)($input['note'] ?? '')];
		$this->db->beginTransaction();
		try {
			$this->lockCase($caseId);
			if ($this->latestOrderId($caseId) !== 0) throw new \InvalidArgumentException('Für diesen Fall besteht bereits ein Auftrag. Ein weiterer Auftrag kann nicht aus einem Kostenvoranschlag erzeugt werden.');
			$claim = $this->db->getQueryBuilder();
			$affected = $claim->update('bestatter_commercial_docs')
				->set('status', $claim->createNamedParameter('UEBERNOMMEN'))
				->where($claim->expr()->eq('id', $claim->createNamedParameter($quoteId)))
				->andWhere($claim->expr()->eq('case_id', $claim->createNamedParameter($caseId)))
				->andWhere($claim->expr()->eq('document_type', $claim->createNamedParameter('QUOTE')))
				->andWhere($claim->expr()->neq('status', $claim->createNamedParameter('UEBERNOMMEN')))
				->executeStatement();
			if ($affected !== 1 || $this->convertedOrderId($quoteId) !== 0) throw new \InvalidArgumentException('Dieser Kostenvoranschlag wurde bereits in einen Auftrag übernommen. Eine zweite Übernahme ist nicht zulässig.');
			$id = $this->insertDocument($caseId, 'ORDER', $number, $version, 'BEAUFTRAGT', $quoteId, $snapshot, $quote['totals']);
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		$this->audit->log($caseId, 'ORDER', $id, 'QUOTE_CONVERTED', $quote, $snapshot);
		$master = $case['masterData'] ?? [];
		$master['kva_number'] = (string)$quote['documentNumber'];
		$master['kva_converted_at'] = date('c');
		$master['kva_converted_order_id'] = $id;
		$master['order_mode'] = 'A';
		$master['order_number'] = $number;
		$master['order_date'] = (string)$snapshot['commissioning']['date'];
		$master['order_status'] = 'beauftragt';
		$this->cases->updateMasterData($caseId, $master);
		return $this->document($id);
	}

	/**
	 * Befund #14: Ein bereits beauftragter Hauptauftrag kann bewusst nicht storniert werden
	 * (fachliche Entscheidung des Kunden). Damit ein beauftragter, aber niemals fakturierter
	 * Auftrag nicht als dauerhafter "Geisterauftrag" offen bleibt, kann er stattdessen hierüber
	 * als ohne weitere Rechnungsstellung erledigt markiert werden. Das ist eine bewusst
	 * endgültige, protokollierte Aktion (kein weiterer Statuswechsel danach vorgesehen) und
	 * setzt lediglich einen zusätzlichen Marker in den Stammdaten (order_closed_at) – der
	 * Auftrag selbst, seine Positionen und ein etwa schon vorhandenes Auftragsdokument bleiben
	 * unverändert als Nachweis erhalten.
	 */
	public function closeOrderWithoutInvoice(int $caseId, string $reason, ?string $expectedUpdatedAt = null): array {
		$reason = trim($reason);
		if ($reason === '') throw new \InvalidArgumentException('Für den Abschluss ohne Rechnungsstellung ist eine kurze Begründung erforderlich.');
		$state = $this->commercialState->state($caseId);
		if ($state['effectiveOrderStatus'] !== 'beauftragt') {
			throw new \InvalidArgumentException('Nur ein bereits beauftragter, noch nicht abgeschlossener oder stornierter Auftrag kann so erledigt werden.');
		}
		$case = $this->cases->getCase($caseId);
		$master = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$before = $master;
		$master['order_closed_at'] = date('c');
		$master['order_closed_by'] = $this->uid();
		$master['order_closed_reason'] = $reason;
		$this->cases->updateMasterData($caseId, $master, $expectedUpdatedAt);
		$this->synchronizeOrderStatus($caseId);
		$after = $this->cases->getCase($caseId);
		$this->audit->log($caseId, 'ORDER', $state['documentId'] ?? 0, 'CLOSED_WITHOUT_INVOICE', $before, ['reason' => $reason] + (is_array($after['masterData'] ?? null) ? $after['masterData'] : []));
		return $after;
	}

	/**
	 * Leistungserfassung: erbrachte Menge und Abrechnungsklassifikation. Der Leistungsstatus wird
	 * seit 0.69.0 aus beauftragter, erbrachter und berechneter Menge abgeleitet und nicht mehr
	 * gepflegt. Für eine Paket-Kopfposition gilt „erbracht“ für alle Unterpositionen.
	 */
	public function updateService(int $caseId, int $serviceId, array $input): array {
		$before = $this->service($caseId, $serviceId);
		if (($before['serviceStatus'] ?? '') === 'STORNIERT') throw new \InvalidArgumentException('Eine stornierte Position kann nicht mehr erfasst werden.');
		if (!empty($before['isPackageHeader'])) {
			$complete = $this->quantityToMilli($input['performedQuantity'] ?? 0) > 0;
			foreach ($this->articles->caseServices($caseId, $before['sideOrderId'])['items'] as $child) {
				if ((int)($child['parentServiceId'] ?? 0) !== $serviceId || ($child['serviceStatus'] ?? '') === 'STORNIERT') continue;
				$target = $complete ? max((int)$child['orderedQuantityMilli'], (int)$child['invoicedQuantityMilli']) : (int)$child['invoicedQuantityMilli'];
				$this->writePerformance((int)$child['id'], $caseId, $target, (string)$child['billability'], (string)$child['classificationReason']);
			}
			$after = $this->service($caseId, $serviceId);
			$this->audit->log($caseId, 'SERVICE', $serviceId, 'LIFECYCLE_UPDATED', $before, $after);
			return $after;
		}
		$billability = strtoupper(trim((string)($input['billability'] ?? $before['billability'])));
		if (!in_array($billability, self::BILLABILITIES, true)) throw new \InvalidArgumentException('Ungültige Abrechnungsklassifikation.');
		$performed = $this->quantityToMilli($input['performedQuantity'] ?? ($before['performedQuantityMilli'] / 1000));
		$this->validateQuantityPrecision($performed, (int)($before['quantityDecimals'] ?? 0), (string)($before['unit'] ?? 'STK'));
		if ($performed < (int)$before['invoicedQuantityMilli']) throw new \InvalidArgumentException('Die erbrachte Menge darf nicht kleiner als die bereits fakturierte Menge sein.');
		$reason = trim((string)($input['reason'] ?? $before['classificationReason']));
		if (in_array($billability, ['KULANZ', 'NICHT_ABRECHENBAR', 'UNKLAR'], true) && $reason === '') throw new \InvalidArgumentException('Für diese Abrechnungsklassifikation ist eine Begründung erforderlich.');
		$this->writePerformance($serviceId, $caseId, $performed, $billability, $reason);
		$after = $this->service($caseId, $serviceId);
		$this->audit->log($caseId, 'SERVICE', $serviceId, 'LIFECYCLE_UPDATED', $before, $after);
		return $after;
	}

	private function writePerformance(int $serviceId, int $caseId, int $performed, string $billability, string $reason): void {
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_case_services')
			->set('performed_quantity_milli', $query->createNamedParameter($performed))
			->set('billability', $query->createNamedParameter($billability))
			->set('classification_reason', $query->createNamedParameter($reason))
			->set('performed_at', $query->createNamedParameter($performed > 0 ? date('c') : null))
			->set('performed_by', $query->createNamedParameter($performed > 0 ? $this->uid() : null))
			->set('updated_at', $query->createNamedParameter(date('c')))
			->where($query->expr()->eq('id', $query->createNamedParameter($serviceId)))
			->andWhere($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->executeStatement();
	}

	public function billingCheck(int $caseId, string $invoiceType = 'FINAL'): array {
		return $this->billingCheckForScope($caseId, $invoiceType, null);
	}

	public function billingCheckForScope(int $caseId, string $invoiceType = 'FINAL', ?int $sideOrderId = null): array {
		$invoiceType = strtoupper(trim($invoiceType));
		if (!in_array($invoiceType, ['PARTIAL', 'FINAL'], true)) throw new \InvalidArgumentException('Die Rechnungsart ist ungültig.');
		$services = $this->articles->caseServices($caseId, $sideOrderId)['items'];
		$exceptions = [];
		$case = $this->cases->getCase($caseId);
		$master = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		if ($sideOrderId !== null) {
			$sideOrder = $this->sideOrders->get($sideOrderId);
			if ($sideOrder['caseId'] !== $caseId) throw new \InvalidArgumentException('Der Nebenauftrag gehört nicht zu diesem Fall.');
			if ($sideOrder['status'] !== 'BEAUFTRAGT') $exceptions[] = $this->exception('SIDE_ORDER_NOT_COMMISSIONED', 'KRITISCH', 0, 'Nebenauftrag', 'Der Nebenauftrag muss vor der Rechnungsstellung den Status BEAUFTRAGT haben.');
		}
		if (strtoupper((string)($master['payment_method'] ?? 'TRANSFER')) === 'SEPA_DIRECT_DEBIT') {
			$branch = $this->configuration->branchByKey((string)($case['branch'] ?? $master['branch'] ?? '')) ?? [];
			$missing = [];
			foreach (['sepa_mandate_reference' => 'Mandatsreferenz', 'sepa_mandate_date' => 'Mandatsdatum', 'sepa_debtor_name' => 'Kontoinhaber/in', 'sepa_debtor_iban' => 'IBAN des Zahlungspflichtigen'] as $key => $label) if (trim((string)($master[$key] ?? '')) === '') $missing[] = $label;
			if (trim((string)($branch['creditorId'] ?? '')) === '') $missing[] = 'Gläubiger-ID der Niederlassung';
			if ($missing !== []) $exceptions[] = $this->exception('SEPA_MANDATE_INCOMPLETE', 'KRITISCH', 0, 'SEPA-Lastschrift', 'Für den Lastschrifteinzug fehlen: ' . implode(', ', $missing) . '.');
		}
		if ($sideOrderId === null && $this->latestOrderId($caseId, null) === 0) $exceptions[] = $this->exception('ORDER_MISSING', 'KRITISCH', 0, 'Auftrag', 'Es liegt noch keine festgeschriebene Auftragsversion vor.');
		// 0.66.0 (Paket F, Anzahlung): Ein je Fall fest vereinbarter Anzahlungsbetrag wird nicht
		// automatisch steuerlich verrechnet; der Hinweis stellt sicher, dass er in der
		// Schlussrechnung berücksichtigt bzw. ein fehlender Zahlungseingang geklärt wird.
		$depositCents = (int)($master['deposit_amount_cents'] ?? 0);
		if ($sideOrderId === null && $invoiceType === 'FINAL' && $depositCents > 0) {
			$amount = number_format($depositCents / 100, 2, ',', '.') . ' €';
			$received = trim((string)($master['deposit_received_at'] ?? ''));
			$exceptions[] = $received !== ''
				? $this->exception('DEPOSIT_RECEIVED', 'WARNUNG', 0, 'Anzahlung', 'Eine Anzahlung von ' . $amount . ' ist am ' . date('d.m.Y', (int)strtotime($received)) . ' eingegangen. Bitte in der Schlussrechnung als bereits geleistete Zahlung ausweisen.')
				: $this->exception('DEPOSIT_OPEN', 'WARNUNG', 0, 'Anzahlung', 'Es wurde eine Anzahlung von ' . $amount . ' vereinbart, deren Eingang noch nicht erfasst ist.');
		}
		foreach ($services as $item) {
			if (!empty($item['isPackageHeader']) || ($item['serviceStatus'] ?? '') === 'STORNIERT') continue;
			$label = trim(($item['positionLabel'] ?? '') . ' ' . $item['articleNumber'] . ' – ' . $item['title']);
			$performed = $item['performedQuantityMilli'];
			$ordered = $item['orderedQuantityMilli'];
			$billable = $item['billability'] === 'ABRECHENBAR';
			if ($performed <= 0 && $billable) $exceptions[] = $this->exception('PERFORMANCE_MISSING', $invoiceType === 'FINAL' ? 'KRITISCH' : 'WARNUNG', $item['id'], $label, $invoiceType === 'FINAL' ? 'Beauftragte Leistung wurde noch nicht als erbracht erfasst.' : 'Diese beauftragte Leistung ist noch nicht erbracht und wird nicht in die Teilrechnung aufgenommen.');
			if ($performed > 0 && $ordered > 0 && $performed !== $ordered) $exceptions[] = $this->exception('QUANTITY_VARIANCE', 'WARNUNG', $item['id'], $label, 'Erbrachte und beauftragte Menge weichen voneinander ab.');
			if ($billable && $performed > $item['invoicedQuantityMilli'] && $item['unitPriceCents'] <= 0 && ($item['packagePricingOfParent'] ?? '') !== 'HEADER') $exceptions[] = $this->exception('PRICE_MISSING', 'KRITISCH', $item['id'], $label, !empty($item['priceOnEntry']) ? 'Der tatsächliche Preis ist noch nicht im Fall erfasst.' : 'Für die noch abzurechnende Leistung fehlt ein Verkaufspreis.');
			if ($item['billability'] === 'UNKLAR') $exceptions[] = $this->exception('CLASSIFICATION_OPEN', 'KRITISCH', $item['id'], $label, 'Abrechnungsklassifikation ist noch offen.');
			if (in_array($item['billability'], ['KULANZ', 'NICHT_ABRECHENBAR'], true) && trim($item['classificationReason']) === '') $exceptions[] = $this->exception('REASON_MISSING', 'KRITISCH', $item['id'], $label, 'Begründung für die Nichtabrechnung fehlt.');
			if ($item['invoicedQuantityMilli'] > $performed) $exceptions[] = $this->exception('OVERBILLED', 'KRITISCH', $item['id'], $label, 'Die fakturierte Menge übersteigt die erbrachte Menge.');
		}
		$hasCritical = count(array_filter($exceptions, static fn(array $item): bool => $item['severity'] === 'KRITISCH')) > 0;
		$hasWarning = count($exceptions) > 0;
		return ['invoiceType' => $invoiceType, 'result' => $hasCritical ? 'KRITISCH' : ($hasWarning ? 'WARNUNG' : 'OK'), 'exceptions' => $exceptions, 'checkedAt' => date('c')];
	}

	public function createInvoice(int $caseId, array $input = []): array {
		$case = $this->cases->getCase($caseId);
		$sideOrderId = $this->scopeId($input['sideOrderId'] ?? null);
		$sideOrder = $sideOrderId !== null ? $this->sideOrders->get($sideOrderId) : null;
		if ($sideOrder !== null && $sideOrder['caseId'] !== $caseId) throw new \InvalidArgumentException('Der Nebenauftrag gehört nicht zu diesem Fall.');
		if ($sideOrder !== null && $sideOrder['status'] !== 'BEAUFTRAGT') throw new \InvalidArgumentException('Rechnungen können nur für einen beauftragten Nebenauftrag angelegt werden.');
		$invoiceType = strtoupper(trim((string)($input['invoiceType'] ?? 'PARTIAL')));
		if (!in_array($invoiceType, ['PARTIAL', 'FINAL'], true)) throw new \InvalidArgumentException('Bitte Teilrechnung oder Schlussrechnung auswählen.');
		if ($this->hasActiveFinalInvoice($caseId, $sideOrderId)) throw new \InvalidArgumentException('Für diesen Auftragskreis besteht bereits eine Schlussrechnung. Weitere Rechnungen sind gesperrt.');
		$services = $this->selectInvoiceServices($caseId, $invoiceType, is_array($input['selections'] ?? null) ? $input['selections'] : [], $sideOrderId);
		if (count($services) === 0) throw new \InvalidArgumentException('Es sind keine abrechenbaren, noch nicht fakturierten Leistungen vorhanden.');
		$check = $this->billingCheckForSelection($this->billingCheckForScope($caseId, $invoiceType, $sideOrderId), $invoiceType, $services, is_array($input['selections'] ?? null) ? $input['selections'] : []);
		$override = filter_var($input['override'] ?? false, FILTER_VALIDATE_BOOLEAN);
		$reason = trim((string)($input['overrideReason'] ?? ''));
		if ($check['result'] === 'KRITISCH' && (!$override || $reason === '')) throw new \InvalidArgumentException('Kritische Abrechnungsfehler verhindern die Rechnung. Eine manuelle Freigabe erfordert eine Begründung.');
		$periodFrom = $this->optionalDate((string)($input['servicePeriodFrom'] ?? ''), 'Beginn des Leistungszeitraums');
		$periodTo = $this->optionalDate((string)($input['servicePeriodTo'] ?? ''), 'Ende des Leistungszeitraums');
		if ($periodFrom !== null && $periodTo !== null && $periodFrom > $periodTo) throw new \InvalidArgumentException('Der Leistungszeitraum endet vor seinem Beginn.');
		$recipient = is_array($input['recipient'] ?? null) ? $input['recipient'] : ($sideOrder !== null ? $this->sideOrderRecipient($sideOrder) : $this->recipient($case['masterData'] ?? []));
		if (trim((string)($recipient['name'] ?? '')) === '' || trim((string)($recipient['street'] ?? '')) === '' || trim((string)($recipient['city'] ?? '')) === '') throw new \InvalidArgumentException('Name und vollständige Anschrift des Rechnungsempfängers sind erforderlich.');
		$paymentSnapshot = $this->invoicePaymentSnapshot($case);
		$now = date('c');
		$this->db->beginTransaction();
		try {
			$this->lockCase($caseId);
			if ($this->hasActiveFinalInvoice($caseId, $sideOrderId)) throw new \InvalidArgumentException('Für diesen Auftragskreis besteht bereits eine Schlussrechnung. Weitere Rechnungen sind gesperrt.');
			$number = $this->nextInvoiceNumber($case);
			$sequence = $this->nextCaseInvoiceSequence($caseId, $sideOrderId);
			$priorGross = $this->priorInvoiceGross($caseId, $sideOrderId);
			$totals = ['netCents' => 0, 'vatCents' => 0, 'grossCents' => 0];
			$query = $this->db->getQueryBuilder();
			$query->insert('bestatter_invoices')->values([
				'case_id' => $query->createNamedParameter($caseId), 'side_order_id' => $query->createNamedParameter($sideOrderId), 'order_id' => $query->createNamedParameter($this->latestOrderId($caseId, $sideOrderId) ?: null),
				'invoice_number' => $query->createNamedParameter($number), 'status' => $query->createNamedParameter('ENTWURF'),
				'invoice_type' => $query->createNamedParameter($invoiceType), 'invoice_sequence' => $query->createNamedParameter($sequence), 'prior_gross_cents' => $query->createNamedParameter($priorGross),
				'service_period_from' => $query->createNamedParameter($periodFrom), 'service_period_to' => $query->createNamedParameter($periodTo),
				'recipient' => $query->createNamedParameter(json_encode($recipient, JSON_THROW_ON_ERROR)), 'billing_check' => $query->createNamedParameter(json_encode($check, JSON_THROW_ON_ERROR)),
				'payment_snapshot' => $query->createNamedParameter(json_encode($paymentSnapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
				'override_reason' => $query->createNamedParameter($reason), 'net_cents' => $query->createNamedParameter(0), 'vat_cents' => $query->createNamedParameter(0), 'gross_cents' => $query->createNamedParameter(0),
				'created_by' => $query->createNamedParameter($this->uid()), 'created_at' => $query->createNamedParameter($now), 'updated_at' => $query->createNamedParameter($now),
			])->executeStatement();
			$invoiceId = (int)$this->db->lastInsertId('bestatter_invoices');
			foreach ($services as $position => $service) {
				$quantity = (int)$service['invoiceQuantityMilli'];
				if (strtoupper((string)($service['positionType'] ?? 'EL')) === 'DP' && (int)$service['vatRate'] !== 0) throw new \InvalidArgumentException('Echte durchlaufende Posten müssen mit 0 % Umsatzsteuer erfasst sein, da sie nicht zum Entgelt gehören.');
				$net = (int)round($quantity * $service['unitPriceCents'] / 1000); $vat = (int)round($net * $service['vatRate'] / 100); $gross = $net + $vat;
				$totals['netCents'] += $net; $totals['vatCents'] += $vat; $totals['grossCents'] += $gross;
				$insert = $this->db->getQueryBuilder();
				$insert->insert('bestatter_invoice_items')->values(['invoice_id' => $insert->createNamedParameter($invoiceId), 'case_service_id' => $insert->createNamedParameter($service['id']), 'position_no' => $insert->createNamedParameter((int)($service['position'] ?? (($position + 1) * 10))), 'sub_position' => $insert->createNamedParameter((int)($service['subPosition'] ?? 0)), 'package_service_id' => $insert->createNamedParameter($service['packageServiceId'] ?? null), 'package_title' => $insert->createNamedParameter(($service['packageTitle'] ?? '') !== '' ? $service['packageTitle'] : null), 'package_position' => $insert->createNamedParameter($service['packagePosition'] ?? null), 'print_mode' => $insert->createNamedParameter($service['printMode'] ?? null), 'description' => $insert->createNamedParameter($service['title']), 'quantity_milli' => $insert->createNamedParameter($quantity), 'unit' => $insert->createNamedParameter($service['unit'] ?? 'STK'), 'unit_price_cents' => $insert->createNamedParameter($service['unitPriceCents']), 'vat_rate' => $insert->createNamedParameter($service['vatRate']), 'net_cents' => $insert->createNamedParameter($net), 'vat_cents' => $insert->createNamedParameter($vat), 'gross_cents' => $insert->createNamedParameter($gross)])->executeStatement();
				$update = $this->db->getQueryBuilder();
				$update->update('bestatter_case_services')->set('invoiced_quantity_milli', $update->createNamedParameter($service['invoicedQuantityMilli'] + $quantity))->set('updated_at', $update->createNamedParameter($now))->where($update->expr()->eq('id', $update->createNamedParameter($service['id'])))->executeStatement();
			}
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_invoices')->set('net_cents', $update->createNamedParameter($totals['netCents']))->set('vat_cents', $update->createNamedParameter($totals['vatCents']))->set('gross_cents', $update->createNamedParameter($totals['grossCents']))->where($update->expr()->eq('id', $update->createNamedParameter($invoiceId)))->executeStatement();
			$this->db->commit();
		} catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
		$invoice = $this->invoice($invoiceId);
		if ($sideOrderId === null) $this->synchronizeOrderStatus($caseId);
		$this->audit->log($caseId, 'INVOICE', $invoiceId, $override ? 'CREATED_WITH_OVERRIDE' : 'CREATED', null, $invoice);
		return $invoice;
	}

	public function transitionInvoice(int $id, string $target, string $reason = ''): array {
		$invoice = $this->invoice($id); $target = strtoupper(trim($target));
		$isCorrection = $invoice['documentKind'] !== 'INVOICE';
		$map = $isCorrection ? self::CORRECTION_TRANSITIONS : self::INVOICE_TRANSITIONS;
		if (!in_array($target, $map[$invoice['status']] ?? [], true)) {
			if ($target === 'STORNIERT' && in_array($invoice['status'], self::ISSUED_STATUSES, true)) throw new \InvalidArgumentException($isCorrection ? 'Ein ausgestellter Korrekturbeleg kann nicht storniert werden.' : 'Eine ausgestellte Rechnung wird über eine Stornorechnung mit eigener Nummer aufgehoben (Schaltfläche „Stornorechnung“).');
			throw new \InvalidArgumentException('Dieser Rechnungsstatus darf nicht gesetzt werden.');
		}
		if ($target === 'STORNIERT' && trim($reason) === '') throw new \InvalidArgumentException('Für das Verwerfen des Entwurfs ist eine Begründung erforderlich.');
		if ($target === 'FREIGEGEBEN' && empty($invoice['closureManifest'])) throw new \InvalidArgumentException('Vor der Freigabe muss der reproduzierbare Beleg mit Dokument und Prüfsummen erzeugt werden.');
		$original = null;
		$this->db->beginTransaction();
		try {
			if ($target === 'FREIGEGEBEN' && $invoice['documentKind'] === 'CANCELLATION') {
				$original = $this->invoice((int)$invoice['referenceInvoiceId']);
				if (!in_array($original['status'], self::ISSUED_STATUSES, true) || $original['cancelledById'] !== null) throw new \InvalidArgumentException('Die Rechnung ' . $original['invoiceNumber'] . ' ist nicht (mehr) stornierbar.');
			}
			$query = $this->db->getQueryBuilder();
			$query->update('bestatter_invoices')->set('status', $query->createNamedParameter($target))->set('updated_at', $query->createNamedParameter(date('c')))->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeStatement();
			if ($target === 'STORNIERT' && !$isCorrection) $this->applyInvoiceServiceStatus($id, 'ABRECHENBAR', true);
			if ($original !== null) {
				// Stornorechnung freigegeben: ursprüngliche Rechnung ist aufgehoben, Mengen wieder abrechenbar.
				$update = $this->db->getQueryBuilder();
				$update->update('bestatter_invoices')->set('status', $update->createNamedParameter('STORNIERT'))->set('cancelled_by_id', $update->createNamedParameter($id))
					->set('updated_at', $update->createNamedParameter(date('c')))->where($update->expr()->eq('id', $update->createNamedParameter((int)$original['id'])))->executeStatement();
				$this->applyInvoiceServiceStatus((int)$original['id'], 'ABRECHENBAR', true);
			}
			if ($target === 'FREIGEGEBEN' && $invoice['documentKind'] === 'CREDIT_NOTE' && $invoice['releaseQuantities']) $this->releaseCreditedQuantities($invoice);
			$this->db->commit();
		} catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
		$after = $this->invoice($id); $this->audit->log($invoice['caseId'], 'INVOICE', $id, 'STATUS_' . $target, $invoice, $after + ['reason' => $reason]);
		if ($original !== null) $this->audit->log($original['caseId'], 'INVOICE', (int)$original['id'], 'CANCELLED_BY_DOCUMENT', $original, $this->invoice((int)$original['id']) + ['cancellationNumber' => $after['invoiceNumber']]);
		if ($invoice['sideOrderId'] === null) $this->synchronizeOrderStatus($invoice['caseId']);
		return $after;
	}

	/**
	 * Stornorechnung: hebt eine ausgestellte Rechnung vollständig auf (alle Positionen mit umgekehrtem Vorzeichen,
	 * eigene Nummer, Bezug auf die Rechnung). Wirksam mit der Freigabe; bis dahin bleibt die Rechnung unverändert.
	 */
	public function createCancellation(int $invoiceId, string $reason): array {
		$original = $this->invoice($invoiceId);
		$reason = trim($reason);
		if ($reason === '') throw new \InvalidArgumentException('Bitte den Grund der Stornierung angeben.');
		$this->assertCorrectable($original);
		$open = array_filter($original['corrections'], static fn(array $entry): bool => $entry['status'] !== 'STORNIERT');
		if ($open !== []) throw new \InvalidArgumentException('Zu dieser Rechnung gibt es bereits ' . (count($open) === 1 ? 'einen Korrekturbeleg' : 'Korrekturbelege') . ' (' . implode(', ', array_column($open, 'number')) . '). Eine vollständige Stornierung ist dann nicht mehr möglich; den Restbetrag bitte per Gutschrift korrigieren.');
		$lines = array_map(static fn(array $item): array => ['item' => $item, 'quantityMilli' => (int)$item['quantityMilli'], 'unitPriceCents' => (int)$item['unitPriceCents'], 'netCents' => (int)$item['netCents']], $original['items']);
		return $this->insertCorrection($original, 'CANCELLATION', $lines, $reason, false);
	}

	/**
	 * Gutschrift (Rechnungskorrektur): mindert einzelne Positionen einer ausgestellten Rechnung um Menge und/oder
	 * Einzelpreis. Der Steuersatz folgt der jeweiligen Position. Optional werden gutgeschriebene Mengen bei der
	 * Freigabe wieder zur Abrechnung frei (z. B. falsche Menge, wird neu berechnet).
	 * @param list<array{itemId:int|string, quantity?:mixed, unitPrice?:mixed, unitPriceCents?:mixed}> $lines
	 */
	public function createCreditNote(int $invoiceId, array $lines, string $reason, bool $releaseQuantities = false): array {
		$original = $this->invoice($invoiceId);
		$reason = trim($reason);
		if ($reason === '') throw new \InvalidArgumentException('Bitte den Grund der Gutschrift angeben.');
		$this->assertCorrectable($original);
		foreach ($original['corrections'] as $entry) if ($entry['kind'] === 'CANCELLATION' && $entry['status'] !== 'STORNIERT') throw new \InvalidArgumentException('Für diese Rechnung ist bereits eine Stornorechnung (' . $entry['number'] . ') angelegt.');
		$items = array_column($original['items'], null, 'id');
		$prepared = [];
		foreach ($lines as $index => $line) {
			$item = $items[(int)($line['itemId'] ?? 0)] ?? null;
			if ($item === null) throw new \InvalidArgumentException('Zeile ' . ($index + 1) . ': Die Position gehört nicht zu dieser Rechnung.');
			$quantityMilli = (int)round((float)str_replace(',', '.', (string)($line['quantity'] ?? $item['quantity'])) * 1000);
			if ($quantityMilli <= 0) continue;
			$unit = isset($line['unitPriceCents']) ? (int)$line['unitPriceCents'] : (int)round((float)str_replace(',', '.', (string)($line['unitPrice'] ?? ($item['unitPriceCents'] / 100))) * 100);
			$label = 'Position ' . $item['positionLabel'] . ' „' . $item['description'] . '“';
			if ($quantityMilli > (int)$item['quantityMilli']) throw new \InvalidArgumentException($label . ': Die Menge ist größer als auf der Rechnung.');
			if ($unit <= 0 || $unit > (int)$item['unitPriceCents']) throw new \InvalidArgumentException($label . ': Der Einzelpreis der Gutschrift muss größer als 0 und höchstens der Rechnungspreis sein.');
			$net = (int)round($quantityMilli * $unit / 1000);
			if ($net > (int)$item['creditRemainingCents']) throw new \InvalidArgumentException($label . ': Es können höchstens ' . number_format((int)$item['creditRemainingCents'] / 100, 2, ',', '.') . ' € netto gutgeschrieben werden.');
			$prepared[] = ['item' => $item, 'quantityMilli' => $quantityMilli, 'unitPriceCents' => $unit, 'netCents' => $net];
		}
		if ($prepared === []) throw new \InvalidArgumentException('Bitte mindestens eine Position mit Menge und Betrag für die Gutschrift auswählen.');
		return $this->insertCorrection($original, 'CREDIT_NOTE', $prepared, $reason, $releaseQuantities);
	}

	private function assertCorrectable(array $original): void {
		if ($original['documentKind'] !== 'INVOICE') throw new \InvalidArgumentException('Korrekturbelege können nur zu einer Rechnung erstellt werden.');
		if ($original['cancelledById'] !== null || $original['status'] === 'STORNIERT') throw new \InvalidArgumentException('Die Rechnung ist bereits storniert.');
		if (!in_array($original['status'], self::ISSUED_STATUSES, true)) throw new \InvalidArgumentException('Storno und Gutschrift sind erst nach Freigabe der Rechnung möglich. Einen Entwurf bitte verwerfen.');
	}

	/** @param list<array{item:array, quantityMilli:int, unitPriceCents:int, netCents:int}> $lines */
	private function insertCorrection(array $original, string $kind, array $lines, string $reason, bool $releaseQuantities): array {
		$caseId = (int)$original['caseId'];
		$case = $this->cases->getCase($caseId);
		$now = date('c');
		$this->db->beginTransaction();
		try {
			$this->lockCase($caseId);
			$fresh = $this->invoice((int)$original['id']);
			$this->assertCorrectable($fresh);
			// Erneut unter Sperre prüfen (gleichzeitige Korrekturbelege).
			$active = array_filter($fresh['corrections'], static fn(array $entry): bool => $entry['status'] !== 'STORNIERT');
			if ($kind === 'CANCELLATION' && $active !== []) throw new \InvalidArgumentException('Zu dieser Rechnung wurde inzwischen ein Korrekturbeleg angelegt.');
			if ($kind === 'CREDIT_NOTE') {
				foreach ($active as $entry) if ($entry['kind'] === 'CANCELLATION') throw new \InvalidArgumentException('Für diese Rechnung wurde inzwischen eine Stornorechnung angelegt.');
				$remaining = array_column($fresh['items'], 'creditRemainingCents', 'id');
				foreach ($lines as $line) if ((int)$line['netCents'] > (int)($remaining[(int)$line['item']['id']] ?? 0)) throw new \InvalidArgumentException('Position ' . $line['item']['positionLabel'] . ': Der gutschreibbare Betrag wurde inzwischen geändert. Bitte neu laden.');
			}
			$number = $this->nextInvoiceNumber($case, true);
			$query = $this->db->getQueryBuilder();
			$query->insert('bestatter_invoices')->values([
				'case_id' => $query->createNamedParameter($caseId), 'side_order_id' => $query->createNamedParameter($original['sideOrderId']), 'order_id' => $query->createNamedParameter($original['orderId']),
				'invoice_number' => $query->createNamedParameter($number), 'status' => $query->createNamedParameter('ENTWURF'),
				'invoice_type' => $query->createNamedParameter('CORRECTION'), 'invoice_sequence' => $query->createNamedParameter(0), 'prior_gross_cents' => $query->createNamedParameter(0),
				'service_period_from' => $query->createNamedParameter($original['servicePeriodFrom']), 'service_period_to' => $query->createNamedParameter($original['servicePeriodTo']),
				'recipient' => $query->createNamedParameter(json_encode($original['recipient'], JSON_THROW_ON_ERROR)), 'billing_check' => $query->createNamedParameter('{}'),
				'payment_snapshot' => $query->createNamedParameter(json_encode($original['paymentSnapshot'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
				'override_reason' => $query->createNamedParameter(''), 'net_cents' => $query->createNamedParameter(0), 'vat_cents' => $query->createNamedParameter(0), 'gross_cents' => $query->createNamedParameter(0),
				'document_kind' => $query->createNamedParameter($kind), 'reference_invoice_id' => $query->createNamedParameter((int)$original['id']),
				'correction_reason' => $query->createNamedParameter($reason), 'release_quantities' => $query->createNamedParameter($releaseQuantities, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL),
				'created_by' => $query->createNamedParameter($this->uid()), 'created_at' => $query->createNamedParameter($now), 'updated_at' => $query->createNamedParameter($now),
			])->executeStatement();
			$id = (int)$this->db->lastInsertId('bestatter_invoices');
			$totals = ['netCents' => 0, 'vatCents' => 0, 'grossCents' => 0];
			foreach ($lines as $line) {
				$item = $line['item'];
				$net = -abs((int)$line['netCents']); $vat = -abs((int)round(abs($net) * (int)$item['vatRate'] / 100));
				if ($kind === 'CANCELLATION') { $net = -(int)$item['netCents']; $vat = -(int)$item['vatCents']; }
				$gross = $net + $vat;
				$totals['netCents'] += $net; $totals['vatCents'] += $vat; $totals['grossCents'] += $gross;
				$insert = $this->db->getQueryBuilder();
				$insert->insert('bestatter_invoice_items')->values([
					'invoice_id' => $insert->createNamedParameter($id), 'case_service_id' => $insert->createNamedParameter((int)$item['caseServiceId']), 'reference_item_id' => $insert->createNamedParameter((int)$item['id']),
					'position_no' => $insert->createNamedParameter((int)$item['position']), 'sub_position' => $insert->createNamedParameter((int)$item['subPosition']),
					'package_service_id' => $insert->createNamedParameter($item['packageServiceId']), 'package_title' => $insert->createNamedParameter($item['packageTitle'] !== '' ? $item['packageTitle'] : null),
					'package_position' => $insert->createNamedParameter($item['packagePosition']), 'print_mode' => $insert->createNamedParameter($item['printMode'] !== '' ? $item['printMode'] : null),
					'description' => $insert->createNamedParameter($item['description']), 'quantity_milli' => $insert->createNamedParameter((int)$line['quantityMilli']), 'unit' => $insert->createNamedParameter($item['unit']),
					'unit_price_cents' => $insert->createNamedParameter(-abs((int)$line['unitPriceCents'])), 'vat_rate' => $insert->createNamedParameter((int)$item['vatRate']),
					'net_cents' => $insert->createNamedParameter($net), 'vat_cents' => $insert->createNamedParameter($vat), 'gross_cents' => $insert->createNamedParameter($gross),
				])->executeStatement();
			}
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_invoices')->set('net_cents', $update->createNamedParameter($totals['netCents']))->set('vat_cents', $update->createNamedParameter($totals['vatCents']))->set('gross_cents', $update->createNamedParameter($totals['grossCents']))->where($update->expr()->eq('id', $update->createNamedParameter($id)))->executeStatement();
			$this->db->commit();
		} catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
		$document = $this->invoice($id);
		$this->audit->log($caseId, 'INVOICE', $id, $kind === 'CANCELLATION' ? 'CANCELLATION_CREATED' : 'CREDIT_NOTE_CREATED', null, $document + ['referenceInvoiceNumber' => $original['invoiceNumber']]);
		return $document;
	}

	/** Gutschrift mit „wieder abrechenbar“: nur voll gutgeschriebene Mengen (Einzelpreis wie auf der Rechnung) werden freigegeben. */
	private function releaseCreditedQuantities(array $creditNote): void {
		$original = $this->invoice((int)$creditNote['referenceInvoiceId']);
		$originalItems = array_column($original['items'], null, 'id');
		foreach ($creditNote['items'] as $item) {
			$reference = $originalItems[(int)$item['referenceItemId']] ?? null;
			if ($reference === null || abs((int)$item['unitPriceCents']) !== (int)$reference['unitPriceCents']) continue;
			$service = $this->serviceById((int)$item['caseServiceId']);
			$q = $this->db->getQueryBuilder();
			$q->update('bestatter_case_services')->set('updated_at', $q->createNamedParameter(date('c')))
				->set('invoiced_quantity_milli', $q->createNamedParameter(max(0, (int)$service['invoiced_quantity_milli'] - (int)$item['quantityMilli'])))
				->where($q->expr()->eq('id', $q->createNamedParameter((int)$item['caseServiceId'])))->executeStatement();
		}
	}

	public function storeClosureManifest(int $id, array $manifest): array {
		if ($manifest === [] || empty($manifest['pdfSha256'])) throw new \InvalidArgumentException('Der Rechnungsabschluss enthält keine PDF-Prüfsumme.');
		$before = $this->invoice($id);
		if (!in_array($before['status'], ['ENTWURF', 'PRUEFUNG'], true)) throw new \InvalidArgumentException('Der Abschlussnachweis kann nach Freigabe der Rechnung nicht mehr ersetzt werden.');
		$now = date('c');
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_invoices')
			->set('closure_manifest', $query->createNamedParameter(json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)))
			->set('closed_at', $query->createNamedParameter($now))->set('closed_by', $query->createNamedParameter($this->uid()))
			->set('updated_at', $query->createNamedParameter($now))
			->where($query->expr()->eq('id', $query->createNamedParameter($id)))->executeStatement();
		$after = $this->invoice($id); $this->audit->log($after['caseId'], 'INVOICE', $id, 'CLOSURE_RECORDED', $before, $after); return $after;
	}

	/** Der Positionsstatus ist abgeleitet; bei Storno wird nur die fakturierte Menge wieder freigegeben. */
	private function applyInvoiceServiceStatus(int $invoiceId, string $status, bool $releaseQuantity): void {
		if (!$releaseQuantity) return;
		$q = $this->db->getQueryBuilder();
		$items = $q->select('case_service_id', 'quantity_milli')->from('bestatter_invoice_items')->where($q->expr()->eq('invoice_id', $q->createNamedParameter($invoiceId)))->executeQuery()->fetchAllAssociative();
		foreach ($items as $item) {
			$service = $this->serviceById((int)$item['case_service_id']);
			$q = $this->db->getQueryBuilder();
			$q->update('bestatter_case_services')->set('updated_at', $q->createNamedParameter(date('c')))
				->set('invoiced_quantity_milli', $q->createNamedParameter(max(0, (int)$service['invoiced_quantity_milli'] - (int)$item['quantity_milli'])))
				->where($q->expr()->eq('id', $q->createNamedParameter((int)$item['case_service_id'])))->executeStatement();
		}
	}

	private function serviceById(int $id): array { $q=$this->db->getQueryBuilder(); $row=$q->select('*')->from('bestatter_case_services')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeQuery()->fetchAssociative(); if(!$row) throw new \InvalidArgumentException('Leistung wurde nicht gefunden.'); return $row; }

	private function selectInvoiceServices(int $caseId, string $invoiceType, array $selections, ?int $sideOrderId = null): array {
		$eligible=array_values(array_filter($this->articles->caseServices($caseId, $sideOrderId)['items'],static fn(array$item):bool=>$item['billability']==='ABRECHENBAR'&&empty($item['isPackageHeader'])&&$item['serviceStatus']!=='STORNIERT'&&$item['performedQuantityMilli'] > $item['invoicedQuantityMilli']));
		$requested=[];foreach($selections as$selection){$id=(int)($selection['caseServiceId']??0);$quantity=$this->quantityToMilli($selection['quantity']??0);if($id>0&&$quantity>0)$requested[$id]=$quantity;}
		if($invoiceType==='FINAL'||$requested===[])return array_map(static function(array$item):array{$item['invoiceQuantityMilli']=$item['performedQuantityMilli']-$item['invoicedQuantityMilli'];return$item;},$eligible);
		$result=[];foreach($eligible as$item){$id=(int)$item['id'];if(!isset($requested[$id]))continue;$remaining=(int)$item['performedQuantityMilli']-(int)$item['invoicedQuantityMilli'];if($requested[$id]>$remaining)throw new \InvalidArgumentException('Die gewählte Rechnungsmenge für „'.$item['title'].'“ übersteigt die noch abrechenbare Menge.');$this->validateQuantityPrecision($requested[$id],(int)($item['quantityDecimals']??0),(string)($item['unit']??'STK'));$item['invoiceQuantityMilli']=$requested[$id];$result[]=$item;unset($requested[$id]);}
		if($requested!==[])throw new \InvalidArgumentException('Mindestens eine ausgewählte Rechnungsposition ist nicht mehr abrechenbar. Bitte Auswahl aktualisieren.');
		return$result;
	}

	private function billingCheckForSelection(array $check, string $invoiceType, array $services, array $selections): array {
		if ($invoiceType !== 'PARTIAL' || $selections === []) return $check;
		$selectedIds = array_fill_keys(array_map(static fn(array $service): int => (int)$service['id'], $services), true);
		$check['exceptions'] = array_values(array_filter($check['exceptions'] ?? [], static fn(array $exception): bool => (int)($exception['serviceId'] ?? 0) === 0 || isset($selectedIds[(int)$exception['serviceId']])));
		$hasCritical = count(array_filter($check['exceptions'], static fn(array $exception): bool => ($exception['severity'] ?? '') === 'KRITISCH')) > 0;
		$check['result'] = $hasCritical ? 'KRITISCH' : ($check['exceptions'] !== [] ? 'WARNUNG' : 'OK');
		return $check;
	}

	private function optionalDate(string $value, string $label): ?string { $value=trim($value);if($value==='')return null;$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new \InvalidArgumentException($label.' ist ungültig.');return$value; }

	public function invoiceData(int $id): array { return $this->invoice($id); }

	private function scopeId(mixed $value): ?int { $id = (int)$value; return $id > 0 ? $id : null; }
	private function applySideOrderScope($query, ?int $sideOrderId, string $column): void { $value = $query->createNamedParameter($sideOrderId); $query->andWhere($sideOrderId === null ? $query->expr()->isNull($column) : $query->expr()->eq($column, $value)); }
	private function sideOrderRecipient(array $order): array { $parts = preg_split('/\s+/', trim((string)($order['postalCity'] ?? '')), 2) ?: []; return ['name' => trim((string)($order['firstName'] ?? '') . ' ' . (string)($order['lastName'] ?? '')), 'street' => (string)($order['street'] ?? ''), 'postalCode' => (string)($parts[0] ?? ''), 'city' => (string)($parts[1] ?? '')]; }
	private function documents(int $caseId, ?int $sideOrderId = null): array { $q=$this->db->getQueryBuilder(); $rows=$q->select('*')->from('bestatter_commercial_docs')->where($q->expr()->eq('case_id',$q->createNamedParameter($caseId))); $this->applySideOrderScope($q, $sideOrderId, 'side_order_id'); $rows=$q->orderBy('created_at','DESC')->executeQuery()->fetchAllAssociative(); return array_map(fn(array $r):array=>$this->mapDocument($r),$rows); }
	private function document(int $id): array { $q=$this->db->getQueryBuilder(); $r=$q->select('*')->from('bestatter_commercial_docs')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeQuery()->fetchAssociative(); if(!$r) throw new \InvalidArgumentException('Kaufmännischer Dokumentstand wurde nicht gefunden.'); return $this->mapDocument($r); }
	private function mapDocument(array $r): array { $convertedOrderId=$r['document_type']==='QUOTE'?$this->convertedOrderId((int)$r['id']):0; return ['id'=>(int)$r['id'],'caseId'=>(int)$r['case_id'],'sideOrderId'=>$r['side_order_id']!==null?(int)$r['side_order_id']:null,'documentType'=>$r['document_type'],'documentNumber'=>$r['document_number'],'version'=>(int)$r['version_no'],'status'=>$convertedOrderId>0?'UEBERNOMMEN':$r['status'],'sourceDocumentId'=>$r['source_document_id']!==null?(int)$r['source_document_id']:null,'convertedOrderId'=>$convertedOrderId?:null,'snapshot'=>json_decode($r['snapshot'],true)?:[],'totals'=>['netCents'=>(int)$r['net_cents'],'vatCents'=>(int)$r['vat_cents'],'grossCents'=>(int)$r['gross_cents']],'createdBy'=>$r['created_by'],'createdAt'=>$r['created_at']]; }
	private function convertedOrderId(int $quoteId): int { $q=$this->db->getQueryBuilder(); return (int)$q->select('id')->from('bestatter_commercial_docs')->where($q->expr()->eq('document_type',$q->createNamedParameter('ORDER')))->andWhere($q->expr()->eq('source_document_id',$q->createNamedParameter($quoteId)))->orderBy('created_at','ASC')->setMaxResults(1)->executeQuery()->fetchOne(); }
	/**
	 * Die Unique-Sperre `bestatter_commercial_number` auf (document_type, document_number,
	 * version_no) ist die eigentliche, DB-durchgesetzte Absicherung gegen doppelte KVA-/
	 * Auftragsversionen bei gleichzeitigen Anfragen (die Zeilensperre in lockCase() dient
	 * nur zusätzlich der Konsistenz der vorgelagerten Prüfungen). Eine Verletzung wird hier
	 * in die gewohnte fachliche Fehlermeldung übersetzt, statt als technischer Fehler
	 * durchzuschlagen.
	 */
	private function insertDocument(int $caseId,string $type,string $number,int $version,string $status,?int $source,array $snapshot,array $totals,?int $sideOrderId = null): int {
		$q=$this->db->getQueryBuilder(); $q->insert('bestatter_commercial_docs')->values(['case_id'=>$q->createNamedParameter($caseId),'side_order_id'=>$q->createNamedParameter($sideOrderId),'document_type'=>$q->createNamedParameter($type),'document_number'=>$q->createNamedParameter($number),'version_no'=>$q->createNamedParameter($version),'status'=>$q->createNamedParameter($status),'source_document_id'=>$q->createNamedParameter($source),'snapshot'=>$q->createNamedParameter(json_encode($snapshot,JSON_THROW_ON_ERROR)),'net_cents'=>$q->createNamedParameter($totals['netCents']),'vat_cents'=>$q->createNamedParameter($totals['vatCents']),'gross_cents'=>$q->createNamedParameter($totals['grossCents']),'created_by'=>$q->createNamedParameter($this->uid()),'created_at'=>$q->createNamedParameter(date('c'))]);
		try {
			$q->executeStatement();
		} catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
			throw new \InvalidArgumentException('Für diesen Beleg (' . $type . ' ' . $number . ', Version ' . $version . ') besteht bereits ein Eintrag. Bitte die Fallübersicht neu laden.');
		}
		return (int)$this->db->lastInsertId('bestatter_commercial_docs');
	}
	private function nextDocumentVersion(string $type,string $number): int { $q=$this->db->getQueryBuilder(); return 1+(int)$q->select($q->func()->max('version_no'))->from('bestatter_commercial_docs')->where($q->expr()->eq('document_type',$q->createNamedParameter($type)))->andWhere($q->expr()->eq('document_number',$q->createNamedParameter($number)))->executeQuery()->fetchOne(); }
	private function service(int $caseId,int $id): array { foreach($this->articles->caseServices($caseId)['items'] as $item) if($item['id']===$id) return $item; throw new \InvalidArgumentException('Leistung wurde nicht gefunden.'); }
	private function invoices(int $caseId, ?int $sideOrderId = null): array { $q=$this->db->getQueryBuilder(); $rows=$q->select('id')->from('bestatter_invoices')->where($q->expr()->eq('case_id',$q->createNamedParameter($caseId))); $this->applySideOrderScope($q, $sideOrderId, 'side_order_id'); $rows=$q->orderBy('created_at','DESC')->executeQuery()->fetchAllAssociative(); return array_map(fn(array $r):array=>$this->invoice((int)$r['id']),$rows); }
	private function invoice(int $id): array { $q=$this->db->getQueryBuilder(); $r=$q->select('*')->from('bestatter_invoices')->where($q->expr()->eq('id',$q->createNamedParameter($id)))->executeQuery()->fetchAssociative(); if(!$r) throw new \InvalidArgumentException('Rechnung wurde nicht gefunden.'); $invoice=$this->mapInvoice($r); $q=$this->db->getQueryBuilder(); $rows=$q->select('*')->from('bestatter_invoice_items')->where($q->expr()->eq('invoice_id',$q->createNamedParameter($id)))->orderBy('position_no','ASC')->addOrderBy('sub_position','ASC')->addOrderBy('id','ASC')->executeQuery()->fetchAllAssociative(); $types=[]; foreach($rows as$i){$service=$this->serviceById((int)$i['case_service_id']);$types[(int)$i['case_service_id']]=(string)($service['position_type']??'EL');} $invoice['items']=array_map(static fn(array $i):array=>['id'=>(int)$i['id'],'caseServiceId'=>(int)$i['case_service_id'],'position'=>(int)$i['position_no'],'description'=>$i['description'],'quantity'=>(int)$i['quantity_milli']/1000,'quantityMilli'=>(int)$i['quantity_milli'],'unit'=>(string)($i['unit']??'STK'),'unitPriceCents'=>(int)$i['unit_price_cents'],'vatRate'=>(int)$i['vat_rate'],'netCents'=>(int)$i['net_cents'],'vatCents'=>(int)$i['vat_cents'],'grossCents'=>(int)$i['gross_cents'],'positionType'=>$types[(int)$i['case_service_id']]??'EL','subPosition'=>(int)($i['sub_position']??0),'positionLabel'=>(int)($i['sub_position']??0)>0?$i['position_no'].'.'.$i['sub_position']:(string)$i['position_no'],'packageServiceId'=>$i['package_service_id']!==null?(int)$i['package_service_id']:null,'packageTitle'=>(string)($i['package_title']??''),'packagePosition'=>$i['package_position']!==null?(int)$i['package_position']:null,'printMode'=>(string)($i['print_mode']??''),'referenceItemId'=>isset($i['reference_item_id'])&&$i['reference_item_id']!==null?(int)$i['reference_item_id']:null],$rows); return $this->withCorrections($invoice); }
	/**
	 * Rechnung: zugehörige Korrekturbelege und je Position der noch gutschreibbare Nettobetrag.
	 * Korrekturbeleg: Nummer und Datum der Bezugsrechnung.
	 */
	private function withCorrections(array $invoice): array {
		$invoice['corrections'] = [];
		if ($invoice['documentKind'] === 'INVOICE') {
			$q = $this->db->getQueryBuilder();
			$rows = $q->select('id', 'invoice_number', 'document_kind', 'status', 'gross_cents', 'created_at')->from('bestatter_invoices')->where($q->expr()->eq('reference_invoice_id', $q->createNamedParameter($invoice['id'])))->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
			$invoice['corrections'] = array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'number' => (string)$r['invoice_number'], 'kind' => (string)$r['document_kind'], 'status' => (string)$r['status'], 'grossCents' => (int)$r['gross_cents'], 'createdAt' => (string)$r['created_at']], $rows);
			$credited = [];
			$activeIds = array_column(array_filter($invoice['corrections'], static fn(array $c): bool => $c['status'] !== 'STORNIERT'), 'id');
			if ($activeIds !== []) {
				$q = $this->db->getQueryBuilder();
				foreach ($q->select('reference_item_id', 'net_cents')->from('bestatter_invoice_items')->where($q->expr()->in('invoice_id', $q->createNamedParameter($activeIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))->executeQuery()->fetchAllAssociative() as $row) $credited[(int)$row['reference_item_id']] = ($credited[(int)$row['reference_item_id']] ?? 0) + (int)$row['net_cents'];
			}
			foreach ($invoice['items'] as &$item) $item['creditRemainingCents'] = max(0, (int)$item['netCents'] + ($credited[(int)$item['id']] ?? 0));
			unset($item);
			$invoice['correctable'] = in_array($invoice['status'], self::ISSUED_STATUSES, true) && $invoice['cancelledById'] === null;
		} else {
			$reference = $invoice['referenceInvoiceId'] !== null ? $this->db->getQueryBuilder() : null;
			if ($reference !== null) {
				$row = $reference->select('invoice_number', 'created_at', 'invoice_type')->from('bestatter_invoices')->where($reference->expr()->eq('id', $reference->createNamedParameter($invoice['referenceInvoiceId'])))->executeQuery()->fetchAssociative();
				if ($row) { $invoice['referenceInvoiceNumber'] = (string)$row['invoice_number']; $invoice['referenceInvoiceDate'] = substr((string)$row['created_at'], 0, 10); $invoice['referenceInvoiceTypeLabel'] = (string)$row['invoice_type'] === 'FINAL' ? 'Schlussrechnung' : 'Teilrechnung'; }
			}
			$invoice['correctable'] = false;
		}
		if ($invoice['cancelledById'] !== null) {
			$q = $this->db->getQueryBuilder();
			$invoice['cancelledByNumber'] = (string)$q->select('invoice_number')->from('bestatter_invoices')->where($q->expr()->eq('id', $q->createNamedParameter($invoice['cancelledById'])))->executeQuery()->fetchOne();
		}
		return $invoice;
	}
	private function mapInvoice(array $r): array { $type=(string)($r['invoice_type']??'PARTIAL'); $kind=(string)($r['document_kind']??'INVOICE')?:'INVOICE'; return ['documentKind'=>$kind,'documentKindLabel'=>self::DOCUMENT_KINDS[$kind]??'Rechnung','referenceInvoiceId'=>isset($r['reference_invoice_id'])&&$r['reference_invoice_id']!==null?(int)$r['reference_invoice_id']:null,'cancelledById'=>isset($r['cancelled_by_id'])&&$r['cancelled_by_id']!==null?(int)$r['cancelled_by_id']:null,'correctionReason'=>(string)($r['correction_reason']??''),'releaseQuantities'=>(bool)($r['release_quantities']??false),'id'=>(int)$r['id'],'caseId'=>(int)$r['case_id'],'sideOrderId'=>$r['side_order_id']!==null?(int)$r['side_order_id']:null,'orderId'=>$r['order_id']!==null?(int)$r['order_id']:null,'invoiceNumber'=>$r['invoice_number'],'invoiceType'=>$type,'invoiceTypeLabel'=>match($kind){'CANCELLATION'=>'Stornorechnung','CREDIT_NOTE'=>'Gutschrift',default=>$type==='FINAL'?'Schlussrechnung':'Teilrechnung'},'invoiceSequence'=>(int)($r['invoice_sequence']??1),'priorGrossCents'=>(int)($r['prior_gross_cents']??0),'servicePeriodFrom'=>$r['service_period_from']??null,'servicePeriodTo'=>$r['service_period_to']??null,'status'=>$r['status'],'recipient'=>json_decode($r['recipient'],true)?:[],'billingCheck'=>json_decode($r['billing_check'],true)?:[],'paymentSnapshot'=>json_decode((string)($r['payment_snapshot']??''),true)?:null,'overrideReason'=>(string)($r['override_reason']??''),'closureManifest'=>json_decode((string)($r['closure_manifest']??''),true)?:null,'closedAt'=>$r['closed_at']??null,'closedBy'=>$r['closed_by']??null,'totals'=>['netCents'=>(int)$r['net_cents'],'vatCents'=>(int)$r['vat_cents'],'grossCents'=>(int)$r['gross_cents']],'createdBy'=>$r['created_by'],'createdAt'=>$r['created_at'],'updatedAt'=>$r['updated_at']]; }
	private function invoicePaymentSnapshot(array $case): array {
		$master = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$branch = $this->configuration->branchByKey((string)($case['branch'] ?? $master['branch'] ?? '')) ?? [];
		$settings = $this->configuration->invoiceSettings();
		$method = strtoupper(trim((string)($master['payment_method'] ?? 'TRANSFER')));
		if (!in_array($method, ['TRANSFER', 'SEPA_DIRECT_DEBIT'], true)) $method = 'TRANSFER';
		$branchStandard = strtoupper(trim((string)($branch['paymentQrStandard'] ?? 'EPC069-12')));
		$qrStandard = ($settings['qrEnabled'] ?? false) && $branchStandard === 'EPC069-12' ? 'EPC069-12' : 'NONE';
		return [
			'schema' => 'bestatter-invoice-payment/1', 'method' => $method, 'qrStandard' => $qrStandard,
			'accountHolder' => (string)($branch['accountHolder'] ?? ''), 'bankName' => (string)($branch['bankName'] ?? ''),
			'iban' => (string)($branch['iban'] ?? ''), 'bic' => (string)($branch['bic'] ?? ''), 'creditorId' => (string)($branch['creditorId'] ?? ''),
			'paymentTermDays' => (int)($branch['paymentTermDays'] ?? 14), 'mandateReference' => (string)($master['sepa_mandate_reference'] ?? ''),
			'collectionDate' => (string)($master['sepa_collection_date'] ?? ''), 'debtorName' => (string)($master['sepa_debtor_name'] ?? ''),
			'debtorIban' => (string)($master['sepa_debtor_iban'] ?? ''), 'debtorBic' => (string)($master['sepa_debtor_bic'] ?? ''),
		];
	}
	private function hasActiveFinalInvoice(int $caseId, ?int $sideOrderId = null): bool { $q=$this->db->getQueryBuilder(); $q->select($q->func()->count('*','count'))->from('bestatter_invoices')->where($q->expr()->eq('case_id',$q->createNamedParameter($caseId)))->andWhere($q->expr()->eq('invoice_type',$q->createNamedParameter('FINAL')))->andWhere($q->expr()->neq('status',$q->createNamedParameter('STORNIERT'))); $this->applySideOrderScope($q, $sideOrderId, 'side_order_id'); return (int)$q->executeQuery()->fetchOne()>0; }
	private function nextCaseInvoiceSequence(int $caseId, ?int $sideOrderId = null): int { $q=$this->db->getQueryBuilder(); return 1+(int)$q->select($q->func()->max('invoice_sequence'))->from('bestatter_invoices')->where($q->expr()->eq('case_id',$q->createNamedParameter($caseId)))->executeQuery()->fetchOne(); }
	private function priorInvoiceGross(int $caseId, ?int $sideOrderId = null): int { $q=$this->db->getQueryBuilder(); $q->select($q->func()->sum('gross_cents'))->from('bestatter_invoices')->where($q->expr()->eq('case_id',$q->createNamedParameter($caseId)))->andWhere($q->expr()->neq('status',$q->createNamedParameter('STORNIERT')))->andWhere($q->expr()->neq('document_kind',$q->createNamedParameter('CANCELLATION'))); $this->applySideOrderScope($q, $sideOrderId, 'side_order_id'); return (int)$q->executeQuery()->fetchOne(); }
	private function latestOrderId(int $caseId, ?int $sideOrderId = null): int { $q=$this->db->getQueryBuilder(); $q->select('id')->from('bestatter_commercial_docs')->where($q->expr()->eq('case_id',$q->createNamedParameter($caseId)))->andWhere($q->expr()->eq('document_type',$q->createNamedParameter('ORDER'))); $this->applySideOrderScope($q, $sideOrderId, 'side_order_id'); return (int)$q->orderBy('created_at','DESC')->setMaxResults(1)->executeQuery()->fetchOne(); }
	private function latestQuoteId(int $caseId, ?int $sideOrderId = null): int { $q=$this->db->getQueryBuilder(); $q->select('id')->from('bestatter_commercial_docs')->where($q->expr()->eq('case_id',$q->createNamedParameter($caseId)))->andWhere($q->expr()->eq('document_type',$q->createNamedParameter('QUOTE'))); $this->applySideOrderScope($q, $sideOrderId, 'side_order_id'); return (int)$q->orderBy('created_at','DESC')->setMaxResults(1)->executeQuery()->fetchOne(); }
	/** Setzt bewusst voraus, dass IQueryBuilder::forUpdate() verfügbar ist (seit langem
	 *  Bestandteil der unterstützten Nextcloud-Versionen); ein stillschweigendes Weiterlaufen
	 *  ohne Zeilensperre wäre schlimmer als ein lauter Fehler. */
	private function lockCase(int $caseId): void { $q=$this->db->getQueryBuilder(); $q->select('id')->from('bestatter_cases')->where($q->expr()->eq('id',$q->createNamedParameter($caseId)))->forUpdate(); if(!$q->executeQuery()->fetchOne())throw new \InvalidArgumentException('Fall wurde nicht gefunden.'); }

	private function synchronizeOrderStatus(int $caseId): array {
		$state = $this->commercialState->state($caseId);
		if (!$state['statusInconsistent']) return $state;
		$case = $this->cases->getCase($caseId);
		$master = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$master['order_status'] = $state['effectiveOrderStatus'];
		$this->cases->updateMasterData($caseId, $master);
		return $this->commercialState->state($caseId);
	}
	/**
	 * Fortlaufende Belegnummer. Korrekturbelege (Storno/Gutschrift) nutzen ein eigenes Präfix (Standard GS) mit
	 * eigener Folge; ist das Präfix gleich dem der Rechnungen, teilen sie sich den Nummernkreis.
	 */
	private function nextInvoiceNumber(array $case, bool $correction = false): string {
		$settings = $this->configuration->invoiceSettings();
		$branch = strtoupper(trim((string)($case['branch'] ?? $case['masterData']['branch'] ?? '')));
		$scope = (string)$settings['sequenceScope'];
		$key = match ($scope) { 'GLOBAL' => 'GLOBAL', 'YEAR_BRANCH' => date('Y') . ':' . ($branch ?: 'OHNE'), default => date('Y') };
		if ($correction && ($settings['correctionPrefix'] ?? 'GS') !== $settings['prefix']) { $key = 'K:' . $key; $settings['prefix'] = $settings['correctionPrefix']; }
		$q = $this->db->getQueryBuilder();
		$q->select('id', 'last_number')->from('bestatter_invoice_sequences')->where($q->expr()->eq('sequence_key', $q->createNamedParameter($key)))->forUpdate();
		$row = $q->executeQuery()->fetchAssociative();
		if (!$row) {
			// FOR UPDATE sperrt nur existierende Zeilen: legen zwei gleichzeitige Anfragen
			// dieselbe neue Sequenz-ID zum ersten Mal an, kann der Insert wegen des
			// Unique-Index auf sequence_key fehlschlagen. In diesem Fall existiert die Zeile
			// jetzt bereits – erneut lesen und wie im Normalfall fortfahren, statt die ganze
			// Rechnungserstellung mit einem technischen Datenbankfehler abzubrechen.
			try {
				$sequence = 1;
				$insert = $this->db->getQueryBuilder();
				$insert->insert('bestatter_invoice_sequences')->values(['sequence_key' => $insert->createNamedParameter($key), 'last_number' => $insert->createNamedParameter($sequence), 'updated_at' => $insert->createNamedParameter(date('c'))])->executeStatement();
			} catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
				$retry = $this->db->getQueryBuilder();
				$retry->select('id', 'last_number')->from('bestatter_invoice_sequences')->where($retry->expr()->eq('sequence_key', $retry->createNamedParameter($key)))->forUpdate();
				$row = $retry->executeQuery()->fetchAssociative();
				if (!$row) throw new \RuntimeException('Die Rechnungsnummernfolge „' . $key . '“ konnte nicht ermittelt werden.');
				$sequence = (int)$row['last_number'] + 1;
				$update = $this->db->getQueryBuilder();
				$update->update('bestatter_invoice_sequences')->set('last_number', $update->createNamedParameter($sequence))->set('updated_at', $update->createNamedParameter(date('c')))->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'])))->executeStatement();
			}
		} else {
			$sequence = (int)$row['last_number'] + 1;
			$update = $this->db->getQueryBuilder();
			$update->update('bestatter_invoice_sequences')->set('last_number', $update->createNamedParameter($sequence))->set('updated_at', $update->createNamedParameter(date('c')))->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'])))->executeStatement();
		}
		return $this->configuration->renderInvoiceNumber($settings, $sequence, (string)$case['caseNumber'], $branch);
	}
	private function recipient(array $master): array { $postalCity=trim((string)($master['invoice_postal_city']??$master['order_client_postal_city']??'')); $parts=preg_split('/\s+/', $postalCity, 2) ?: []; return ['name'=>trim((string)($master['invoice_first_name']??$master['order_client_first_name']??'').' '.(string)($master['invoice_name']??$master['order_client_name']??'')),'street'=>(string)($master['invoice_street']??$master['order_client_street']??''),'postalCode'=>(string)($parts[0]??''),'city'=>(string)($parts[1]??'')]; }
	private function exception(string $rule,string $severity,int $serviceId,string $label,string $message): array { return compact('rule','severity','serviceId','label','message'); }
	private function uid(): string { return $this->userSession->getUser()?->getUID() ?? 'system'; }
	private function quantityToMilli(mixed $value): int { return max(0,(int)round((float)str_replace(',','.',(string)$value)*1000)); }
	private function validateQuantityPrecision(int $quantityMilli, int $decimals, string $unit): void {
		$decimals = max(0, min(3, $decimals));
		$increment = 10 ** (3 - $decimals);
		if ($quantityMilli % $increment !== 0) {
			$labels = ['STK' => 'Stück', 'PAUSCHAL' => 'Pauschale', 'STD' => 'Stunden', 'KM' => 'Kilometer', 'TAG' => 'Tage', 'KG' => 'Kilogramm', 'L' => 'Liter'];
			throw new \InvalidArgumentException(sprintf('Für die Einheit %s sind höchstens %d Nachkommastellen zulässig.', $labels[$unit] ?? $unit, $decimals));
		}
	}
}
