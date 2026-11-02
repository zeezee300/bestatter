<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * 0.89.7 (OP-075, Schritt C): Vertragsstand einer Vorsorge (bestatter_precare) und ihre Finanzierungen
 * (bestatter_precare_funding).
 *
 * Ablauf: Beratung → Angebot (KVA festgeschrieben) → Vertrag abgeschlossen (Auftrag = Vorsorgevertrag festgeschrieben)
 * → Betreuung mit Wiedervorlage → Ende (gekündigt, widerrufen) oder Eingelöst (Sterbefall, Schritt D). Angebot und
 * Vertragsabschluss folgen dem kaufmännischen Stand der Akte (syncFromCommercial); von Hand lassen sich nur Kündigung und
 * Widerruf eintragen. Die Wünsche stehen in den Stammdaten des Vorsorgefalls unter denselben Schlüsseln wie im Sterbefall.
 *
 * Finanzierungen werden heute von Hand geführt (provider_key MANUELL). external_reference/external_status/last_checked_at
 * sind für spätere Anbieter-Schnittstellen (z. B. Treuhand, OP-076) vorgesehen; diese Klasse ruft keine Dienste im Netz auf.
 */
class PrecareService {
	public const STATUSES = [
		'BERATUNG' => 'Beratung', 'ANGEBOT' => 'Angebot', 'ABGESCHLOSSEN' => 'Vertrag abgeschlossen',
		'GEKUENDIGT' => 'Gekündigt', 'WIDERRUFEN' => 'Widerrufen', 'EINGELOEST' => 'Eingelöst',
	];
	/** Aktive Vorsorgen sind vom Löschvorschlag der Aufbewahrung ausgenommen (Konzept Abschnitt 10). */
	public const ACTIVE_STATUSES = ['BERATUNG', 'ANGEBOT', 'ABGESCHLOSSEN'];
	public const ENDED_STATUSES = ['GEKUENDIGT', 'WIDERRUFEN', 'EINGELOEST'];
	/** Ohne Vertragsabschluss: eigene, kürzere Frist in der Aufbewahrungsrichtlinie. */
	public const CONSULTATION_STATUSES = ['BERATUNG', 'ANGEBOT'];
	public const PRICE_MODES = ['CURRENT' => 'Preise zum Zeitpunkt der Ausführung', 'GUARANTEED' => 'Preisgarantie'];
	public const FUNDING_TYPES = [
		'TREUHAND' => 'Treuhand', 'STERBEGELD' => 'Sterbegeldversicherung', 'VERPFAENDUNG' => 'Verpfändung',
		'SPARANLAGE' => 'Sparanlage', 'SELBSTZAHLER' => 'Selbstzahler', 'SONSTIGE' => 'Sonstige',
	];
	public const FUNDING_STATUSES = ['BEANTRAGT' => 'Beantragt', 'AKTIV' => 'Aktiv', 'GEKUENDIGT' => 'Gekündigt', 'AUSGEZAHLT' => 'Ausgezahlt'];
	public const PROVIDER_MANUAL = 'MANUELL';
	public const CONFIG_KEY = 'precare_settings';
	public const DEFAULT_REVIEW_MONTHS = 24;
	public const DEFAULT_LEAD_DAYS = 30;

	public function __construct(
		private IDBConnection $db,
		private ?IConfig $config = null,
		private ?AuditService $audit = null,
		private ?TeamService $team = null,
		private ?CommercialStateService $commercialState = null,
	) {
	}

	// ------------------------------------------------------------------------------------------- Einstellungen

	/** Customizing → Vorsorge: Wiedervorlage-Intervall (Monate), Vorlauf der Übersicht (Tage), Link zum Treuhand-/Versicherungsportal. */
	public function settings(): array {
		$stored = json_decode((string)($this->config?->getAppValue(Application::APP_ID, self::CONFIG_KEY, '') ?? ''), true);
		$stored = is_array($stored) ? $stored : [];
		return [
			'reviewMonths' => self::months($stored['reviewMonths'] ?? self::DEFAULT_REVIEW_MONTHS),
			'leadDays' => max(0, min(180, (int)($stored['leadDays'] ?? self::DEFAULT_LEAD_DAYS))),
			'portalLabel' => mb_substr(trim((string)($stored['portalLabel'] ?? '')), 0, 80),
			'portalUrl' => self::safeUrl((string)($stored['portalUrl'] ?? '')),
			'statuses' => self::STATUSES, 'priceModes' => self::PRICE_MODES, 'fundingTypes' => self::FUNDING_TYPES, 'fundingStatuses' => self::FUNDING_STATUSES,
			'canEdit' => $this->team?->isBestatterAdmin() ?? false,
		];
	}

	public function saveSettings(array $input): array {
		$this->team?->requireBestatterAdmin();
		$months = (int)($input['reviewMonths'] ?? self::DEFAULT_REVIEW_MONTHS);
		if ($months < 1 || $months > 120) {
			throw new \InvalidArgumentException('Das Intervall der Wiedervorlage muss zwischen 1 und 120 Monaten liegen.');
		}
		$lead = (int)($input['leadDays'] ?? self::DEFAULT_LEAD_DAYS);
		if ($lead < 0 || $lead > 180) {
			throw new \InvalidArgumentException('Der Vorlauf muss zwischen 0 und 180 Tagen liegen.');
		}
		$url = trim((string)($input['portalUrl'] ?? ''));
		if ($url !== '' && self::safeUrl($url) === '') {
			throw new \InvalidArgumentException('Bitte eine vollständige Adresse mit https:// angeben.');
		}
		$value = ['reviewMonths' => $months, 'leadDays' => $lead, 'portalLabel' => mb_substr(trim((string)($input['portalLabel'] ?? '')), 0, 80), 'portalUrl' => self::safeUrl($url)];
		$this->config?->setAppValue(Application::APP_ID, self::CONFIG_KEY, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		$this->audit?->logSystem('PRECARE_SETTINGS', 'UPDATED', $value);
		return $this->settings();
	}

	// ------------------------------------------------------------------------------------------- Vertragsstand

	/** Legt den Vertragsstand an, falls er fehlt (Anlage der Vorsorge oder Vorsorge aus einem älteren Zwischenstand). */
	public function ensure(int $caseId): array {
		$row = $this->row($caseId);
		if ($row !== null) {
			return $row;
		}
		$months = $this->settings()['reviewMonths'];
		$now = date('c');
		$query = $this->db->getQueryBuilder();
		try {
			$query->insert('bestatter_precare')->values([
				'case_id' => $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT),
				'precare_status' => $query->createNamedParameter('BERATUNG'),
				'price_mode' => $query->createNamedParameter('CURRENT'),
				'review_interval_months' => $query->createNamedParameter($months, IQueryBuilder::PARAM_INT),
				'next_review_at' => $query->createNamedParameter(self::addMonths(date('Y-m-d'), $months)),
				'created_at' => $query->createNamedParameter($now),
				'updated_at' => $query->createNamedParameter($now),
			])->executeStatement();
		} catch (\OCP\DB\Exception $error) {
			// gleichzeitig angelegt – vorhandene Zeile verwenden
			if ($error->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $error;
			}
		}
		return $this->row($caseId) ?? throw new \RuntimeException('Der Vertragsstand der Vorsorge konnte nicht angelegt werden.');
	}

	/** Vertragsstand mit Finanzierungen und Auswahlwerten für die Akte (gleicht vorher den kaufmännischen Stand ab). */
	public function forCase(int $caseId): array {
		$this->ensure($caseId);
		$this->syncFromCommercial($caseId);
		$row = $this->row($caseId) ?? [];
		$settings = $this->settings();
		$fundings = $this->fundings($caseId);
		$today = date('Y-m-d');
		$reviewDue = in_array($row['status'], self::ACTIVE_STATUSES, true) && ($row['nextReviewAt'] ?? '') !== '' && $row['nextReviewAt'] <= self::addDays($today, $settings['leadDays']);
		return $row + [
			'statusLabel' => self::STATUSES[$row['status']] ?? $row['status'],
			'priceModeLabel' => self::PRICE_MODES[$row['priceMode']] ?? $row['priceMode'],
			'active' => in_array($row['status'], self::ACTIVE_STATUSES, true),
			'ended' => in_array($row['status'], self::ENDED_STATUSES, true),
			'reviewDue' => $reviewDue,
			'reviewOverdue' => $reviewDue && $row['nextReviewAt'] < $today,
			'fundings' => $fundings,
			'fundingTotalCents' => array_sum(array_map(static fn (array $funding): int => in_array($funding['status'], ['BEANTRAGT', 'AKTIV'], true) ? (int)($funding['amountCents'] ?? 0) : 0, $fundings)),
			'settings' => $settings,
		];
	}

	/** Preisart, Intervall und Termin der Wiedervorlage (nicht nach dem Ende der Vorsorge). */
	public function update(int $caseId, array $input): array {
		$before = $this->ensure($caseId);
		if (in_array($before['status'], self::ENDED_STATUSES, true)) {
			throw new \InvalidArgumentException('Die Vorsorge ist beendet und kann nicht mehr geändert werden.');
		}
		$priceMode = strtoupper(trim((string)($input['priceMode'] ?? $before['priceMode'])));
		if (!isset(self::PRICE_MODES[$priceMode])) {
			throw new \InvalidArgumentException('Die Preisart ist ungültig.');
		}
		$months = self::months($input['reviewIntervalMonths'] ?? $before['reviewIntervalMonths']);
		$next = trim((string)($input['nextReviewAt'] ?? $before['nextReviewAt']));
		if ($next !== '' && !self::isDate($next)) {
			throw new \InvalidArgumentException('Das Datum der Wiedervorlage ist ungültig.');
		}
		if ($months !== (int)$before['reviewIntervalMonths'] && ($input['nextReviewAt'] ?? null) === null) {
			$next = self::addMonths((string)($before['reviewedAt'] ?: ($before['contractDate'] ?: date('Y-m-d'))), $months);
		}
		$contractDate = trim((string)($input['contractDate'] ?? $before['contractDate']));
		if ($contractDate !== '' && !self::isDate($contractDate)) {
			throw new \InvalidArgumentException('Das Vertragsdatum ist ungültig.');
		}
		$this->write($caseId, ['price_mode' => $priceMode, 'review_interval_months' => $months, 'next_review_at' => $next !== '' ? $next : null, 'contract_date' => $contractDate !== '' ? $contractDate : null]);
		$after = $this->row($caseId);
		$this->audit?->log($caseId, 'PRECARE', $caseId, 'UPDATED', $before, $after);
		return $this->forCase($caseId);
	}

	/**
	 * Von Hand: Kündigung oder Widerruf (mit Grund und Datum). Angebot und Vertragsabschluss folgen dem kaufmännischen Stand,
	 * „Eingelöst“ entsteht nur über die Übernahme in einen Sterbefall.
	 */
	public function end(int $caseId, string $target, string $reason, string $date = ''): array {
		$before = $this->ensure($caseId);
		$target = strtoupper(trim($target));
		if (!in_array($target, ['GEKUENDIGT', 'WIDERRUFEN'], true)) {
			throw new \InvalidArgumentException('Von Hand lässt sich eine Vorsorge nur kündigen oder widerrufen.');
		}
		if (in_array($before['status'], self::ENDED_STATUSES, true)) {
			throw new \InvalidArgumentException('Die Vorsorge ist bereits beendet.');
		}
		$reason = trim($reason);
		if (mb_strlen($reason) < 3) {
			throw new \InvalidArgumentException('Bitte einen Grund angeben.');
		}
		$date = trim($date) !== '' ? trim($date) : date('Y-m-d');
		if (!self::isDate($date)) {
			throw new \InvalidArgumentException('Das Datum ist ungültig.');
		}
		$this->write($caseId, ['precare_status' => $target, 'ended_at' => $date, 'end_reason' => mb_substr($reason, 0, 500), 'next_review_at' => null]);
		$this->audit?->log($caseId, 'PRECARE', $caseId, $target === 'WIDERRUFEN' ? 'REVOKED' : 'TERMINATED', $before, $this->row($caseId));
		return $this->forCase($caseId);
	}

	/** Wiedervorlage erledigt: nächster Termin nach dem Intervall ab heute. */
	public function markReviewed(int $caseId, string $note = ''): array {
		$before = $this->ensure($caseId);
		if (!in_array($before['status'], self::ACTIVE_STATUSES, true)) {
			throw new \InvalidArgumentException('Nur aktive Vorsorgen haben eine Wiedervorlage.');
		}
		$today = date('Y-m-d');
		$this->write($caseId, ['reviewed_at' => $today, 'next_review_at' => self::addMonths($today, (int)$before['reviewIntervalMonths'] ?: $this->settings()['reviewMonths'])]);
		$after = $this->row($caseId);
		$this->audit?->log($caseId, 'PRECARE', $caseId, 'REVIEWED', $before, $after + ['note' => mb_substr(trim($note), 0, 500)]);
		return $this->forCase($caseId);
	}

	/** Schritt D: Vorsorge eingelöst – Verweis auf den neuen Sterbefall, keine Wiedervorlage mehr. */
	public function markRedeemed(int $caseId, int $deathCaseId): array {
		$before = $this->ensure($caseId);
		if ($before['status'] === 'EINGELOEST') {
			throw new \InvalidArgumentException('Die Vorsorge wurde bereits eingelöst (Sterbefall verknüpft).');
		}
		$now = date('c');
		$this->write($caseId, ['precare_status' => 'EINGELOEST', 'redeemed_case_id' => $deathCaseId, 'redeemed_at' => $now, 'ended_at' => substr($now, 0, 10), 'next_review_at' => null]);
		$this->audit?->log($caseId, 'PRECARE', $caseId, 'REDEEMED', $before, $this->row($caseId));
		return $this->row($caseId) ?? [];
	}

	/**
	 * Angebot und Vertrag folgen dem kaufmännischen Stand: festgeschriebener KVA → Angebot, festgeschriebener Auftrag
	 * (= Vorsorgevertrag) → Vertrag abgeschlossen mit Vertragsdatum und erster Wiedervorlage.
	 */
	public function syncFromCommercial(int $caseId): void {
		if ($this->commercialState === null) {
			return;
		}
		$row = $this->row($caseId);
		if ($row === null || !in_array($row['status'], self::CONSULTATION_STATUSES, true)) {
			return;
		}
		try {
			$state = $this->commercialState->state($caseId);
		} catch (\Throwable) {
			return;
		}
		$type = (string)($state['documentType'] ?? '');
		$status = mb_strtolower((string)($state['effectiveOrderStatus'] ?? ''));
		if ($type === 'ORDER' && in_array($status, ['beauftragt', 'abgeschlossen'], true)) {
			$date = $this->orderDate($caseId) ?: date('Y-m-d');
			$months = (int)$row['reviewIntervalMonths'] ?: $this->settings()['reviewMonths'];
			$this->write($caseId, ['precare_status' => 'ABGESCHLOSSEN', 'contract_date' => $row['contractDate'] ?: $date, 'next_review_at' => self::addMonths($row['contractDate'] ?: $date, $months)]);
			$this->audit?->log($caseId, 'PRECARE', $caseId, 'CONTRACT_CONCLUDED', $row, $this->row($caseId));
		} elseif ($type === 'QUOTE' && $row['status'] === 'BERATUNG') {
			$this->write($caseId, ['precare_status' => 'ANGEBOT']);
			$this->audit?->log($caseId, 'PRECARE', $caseId, 'OFFER_CREATED', $row, $this->row($caseId));
		}
	}

	// ------------------------------------------------------------------------------------------- Finanzierungen

	/** @return list<array> */
	public function fundings(int $caseId): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_precare_funding')->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		return array_map([self::class, 'mapFunding'], $rows);
	}

	public function saveFunding(int $caseId, array $input, int $id = 0): array {
		$precare = $this->ensure($caseId);
		if ($precare['status'] === 'EINGELOEST' && $id === 0) {
			throw new \InvalidArgumentException('Die Vorsorge ist eingelöst; Finanzierungen bitte im Sterbefall weiterführen.');
		}
		$values = self::normalizeFunding($input);
		$before = $id > 0 ? $this->funding($caseId, $id) : null;
		$now = date('c');
		$query = $this->db->getQueryBuilder();
		if ($before === null) {
			$query->insert('bestatter_precare_funding')->values([
				'case_id' => $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT),
				'funding_type' => $query->createNamedParameter($values['funding_type']), 'provider_name' => $query->createNamedParameter($values['provider_name']),
				'provider_key' => $query->createNamedParameter(self::PROVIDER_MANUAL), 'contract_number' => $query->createNamedParameter($values['contract_number']),
				'amount_cents' => $query->createNamedParameter($values['amount_cents'], $values['amount_cents'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
				'contracted_at' => $query->createNamedParameter($values['contracted_at']), 'funding_status' => $query->createNamedParameter($values['funding_status']),
				'notes' => $query->createNamedParameter($values['notes']), 'created_at' => $query->createNamedParameter($now), 'updated_at' => $query->createNamedParameter($now),
			])->executeStatement();
			$id = (int)$this->db->lastInsertId('bestatter_precare_funding');
		} else {
			$query->update('bestatter_precare_funding');
			foreach ($values as $column => $value) {
				$query->set($column, $query->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : ($column === 'amount_cents' ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)));
			}
			$query->set('updated_at', $query->createNamedParameter($now))
				->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->andWhere($query->expr()->eq('case_id', $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))->executeStatement();
		}
		$after = $this->funding($caseId, $id);
		$this->audit?->log($caseId, 'PRECARE_FUNDING', $id, $before === null ? 'CREATED' : 'UPDATED', $before, $after);
		return $after ?? [];
	}

	public function deleteFunding(int $caseId, int $id): void {
		$before = $this->funding($caseId, $id);
		if ($before === null) {
			throw new \InvalidArgumentException('Die Finanzierung wurde nicht gefunden.');
		}
		if ($this->ensure($caseId)['status'] === 'EINGELOEST') {
			throw new \InvalidArgumentException('Die Vorsorge ist eingelöst; ihre Finanzierungen bleiben als Nachweis erhalten.');
		}
		$query = $this->db->getQueryBuilder();
		$query->delete('bestatter_precare_funding')->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
		$this->audit?->log($caseId, 'PRECARE_FUNDING', $id, 'DELETED', $before, null);
	}

	public function funding(int $caseId, int $id): ?array {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('*')->from('bestatter_precare_funding')->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('case_id', $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchAssociative();
		return $row ? self::mapFunding($row) : null;
	}

	/** Schritt D: Status „Ausgezahlt“ an der Finanzierung, wenn der Treuhänder bzw. Versicherer gezahlt hat. */
	public function setFundingStatus(int $caseId, int $id, string $status): array {
		$funding = $this->funding($caseId, $id);
		if ($funding === null) {
			throw new \InvalidArgumentException('Die Finanzierung wurde nicht gefunden.');
		}
		return $this->saveFunding($caseId, ['fundingStatus' => $status] + $funding, $id);
	}

	// ------------------------------------------------------------------------------------------- Auswertungen

	/**
	 * Aktive Vorsorgen mit fälliger Wiedervorlage (Arbeitsübersicht), Vorlauf aus den Einstellungen.
	 * @return array{count:int,items:list<array>}
	 */
	public function reviewsDue(int $limit = 20): array {
		$settings = $this->settings();
		$until = self::addDays(date('Y-m-d'), $settings['leadDays']);
		$query = $this->db->getQueryBuilder();
		$query->select('p.case_id', 'p.next_review_at', 'p.precare_status', 'c.case_number', 'c.first_name', 'c.last_name', 'c.responsible_employee')
			->from('bestatter_precare', 'p')->innerJoin('p', 'bestatter_cases', 'c', $query->expr()->eq('c.id', 'p.case_id'))
			->where($query->expr()->in('p.precare_status', $query->createNamedParameter(self::ACTIVE_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($query->expr()->isNotNull('p.next_review_at'))
			->andWhere($query->expr()->lte('p.next_review_at', $query->createNamedParameter($until)))
			->andWhere($query->expr()->isNull('c.anonymized_at'))
			->orderBy('p.next_review_at', 'ASC');
		$rows = $query->executeQuery()->fetchAllAssociative();
		$today = date('Y-m-d');
		return ['count' => count($rows), 'leadDays' => $settings['leadDays'], 'items' => array_map(static fn (array $row): array => [
			'caseId' => (int)$row['case_id'], 'caseNumber' => (string)$row['case_number'], 'name' => trim((string)$row['last_name'] . ', ' . (string)$row['first_name'], ', '),
			'nextReviewAt' => (string)$row['next_review_at'], 'overdue' => (string)$row['next_review_at'] < $today, 'status' => (string)$row['precare_status'],
			'responsibleEmployee' => (string)($row['responsible_employee'] ?? ''),
		], array_slice($rows, 0, max(1, $limit)))];
	}

	/** @return array<int, array{status:string,updatedAt:string,endedAt:string,nextReviewAt:string,reviewedAt:string}> Vertragsstand je Vorsorgefall (Aufbewahrung). */
	public function statesByCase(): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('case_id', 'precare_status', 'updated_at', 'ended_at', 'next_review_at', 'reviewed_at')->from('bestatter_precare')->executeQuery()->fetchAllAssociative();
		$result = [];
		foreach ($rows as $row) {
			$result[(int)$row['case_id']] = ['status' => (string)$row['precare_status'], 'updatedAt' => (string)$row['updated_at'], 'endedAt' => (string)($row['ended_at'] ?? ''), 'nextReviewAt' => (string)($row['next_review_at'] ?? ''), 'reviewedAt' => (string)($row['reviewed_at'] ?? '')];
		}
		return $result;
	}

	/**
	 * Kennzahlen (Auswertungen): aktive Vorsorgen, Abschlüsse/Einlösungen/Kündigungen im Zeitraum, Summe erfasster
	 * Finanzierungen (Angabe aus der Akte, keine Buchhaltung).
	 */
	public function metrics(string $from, string $to, string $branch = 'ALL'): array {
		$query = $this->db->getQueryBuilder();
		$query->select('p.case_id', 'p.precare_status', 'p.contract_date', 'p.redeemed_at', 'p.ended_at', 'c.branch')->from('bestatter_precare', 'p')
			->innerJoin('p', 'bestatter_cases', 'c', $query->expr()->eq('c.id', 'p.case_id'));
		if ($branch !== 'ALL' && $branch !== '') {
			$query->where($query->expr()->eq('c.branch', $query->createNamedParameter($branch)));
		}
		$rows = $query->executeQuery()->fetchAllAssociative();
		$in = static fn (string $date): bool => $date !== '' && substr($date, 0, 10) >= $from && substr($date, 0, 10) <= $to;
		$active = 0;
		$contracts = 0;
		$redeemed = 0;
		$ended = 0;
		$ids = [];
		foreach ($rows as $row) {
			$status = (string)$row['precare_status'];
			if (in_array($status, self::ACTIVE_STATUSES, true)) {
				$active++;
				$ids[] = (int)$row['case_id'];
			}
			if ($in((string)($row['contract_date'] ?? ''))) {
				$contracts++;
			}
			if ($status === 'EINGELOEST' && $in((string)($row['redeemed_at'] ?? ''))) {
				$redeemed++;
			}
			if (in_array($status, ['GEKUENDIGT', 'WIDERRUFEN'], true) && $in((string)($row['ended_at'] ?? ''))) {
				$ended++;
			}
		}
		$fundingCents = 0;
		if ($ids !== []) {
			foreach (array_chunk($ids, 500) as $chunk) {
				$sum = $this->db->getQueryBuilder();
				$sum->select($sum->func()->sum('amount_cents'))->from('bestatter_precare_funding')
					->where($sum->expr()->in('case_id', $sum->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
					->andWhere($sum->expr()->in('funding_status', $sum->createNamedParameter(['BEANTRAGT', 'AKTIV'], IQueryBuilder::PARAM_STR_ARRAY)));
				$fundingCents += (int)$sum->executeQuery()->fetchOne();
			}
		}
		return ['active' => $active, 'contracts' => $contracts, 'redeemed' => $redeemed, 'ended' => $ended, 'fundingCents' => $fundingCents];
	}

	/** @return array{precare: list<array<string, mixed>>, fundings: list<array>} Zeilen für Fallexport */
	public function exportRows(int $caseId): array {
		$query = $this->db->getQueryBuilder();
		$precare = $query->select('*')->from('bestatter_precare')->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchAllAssociative();
		return ['precare' => $precare, 'fundings' => $this->fundings($caseId)];
	}

	/** Anonymisieren (Aufbewahrung): Finanzierungen entfernen, im Vertragsstand nur Status und Daten behalten. */
	public function anonymizeCase(int $caseId): void {
		$delete = $this->db->getQueryBuilder();
		$delete->delete('bestatter_precare_funding')->where($delete->expr()->eq('case_id', $delete->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))->executeStatement();
		$update = $this->db->getQueryBuilder();
		$update->update('bestatter_precare')->set('end_reason', $update->createNamedParameter(null, IQueryBuilder::PARAM_NULL))->set('next_review_at', $update->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $update->createNamedParameter(date('c')))->where($update->expr()->eq('case_id', $update->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	// ------------------------------------------------------------------------------------------- intern

	public function row(int $caseId): ?array {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('*')->from('bestatter_precare')->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))->executeQuery()->fetchAssociative();
		if (!$row) {
			return null;
		}
		return [
			'id' => (int)$row['id'], 'caseId' => (int)$row['case_id'], 'status' => (string)$row['precare_status'], 'contractDate' => (string)($row['contract_date'] ?? ''),
			'priceMode' => (string)$row['price_mode'], 'reviewIntervalMonths' => (int)($row['review_interval_months'] ?? self::DEFAULT_REVIEW_MONTHS),
			'nextReviewAt' => (string)($row['next_review_at'] ?? ''), 'reviewedAt' => (string)($row['reviewed_at'] ?? ''),
			'redeemedCaseId' => $row['redeemed_case_id'] !== null ? (int)$row['redeemed_case_id'] : null, 'redeemedAt' => (string)($row['redeemed_at'] ?? ''),
			'endedAt' => (string)($row['ended_at'] ?? ''), 'endReason' => (string)($row['end_reason'] ?? ''), 'createdAt' => (string)$row['created_at'], 'updatedAt' => (string)$row['updated_at'],
		];
	}

	private function write(int $caseId, array $values): void {
		$query = $this->db->getQueryBuilder();
		$query->update('bestatter_precare');
		foreach ($values as $column => $value) {
			$query->set($column, $query->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : (is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)));
		}
		$query->set('updated_at', $query->createNamedParameter(date('c')))->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	private function orderDate(int $caseId): string {
		$query = $this->db->getQueryBuilder();
		$created = (string)$query->select('created_at')->from('bestatter_commercial_docs')->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('document_type', $query->createNamedParameter('ORDER')))->andWhere($query->expr()->isNull('side_order_id'))
			->orderBy('created_at', 'ASC')->setMaxResults(1)->executeQuery()->fetchOne();
		return $created !== '' ? substr($created, 0, 10) : '';
	}

	/** @return array{funding_type:string,provider_name:?string,contract_number:?string,amount_cents:?int,contracted_at:?string,funding_status:string,notes:?string} */
	public static function normalizeFunding(array $input): array {
		$type = strtoupper(trim((string)($input['fundingType'] ?? $input['type'] ?? '')));
		if (!isset(self::FUNDING_TYPES[$type])) {
			throw new \InvalidArgumentException('Bitte die Art der Finanzierung wählen.');
		}
		$status = strtoupper(trim((string)($input['fundingStatus'] ?? $input['status'] ?? 'BEANTRAGT')));
		if (!isset(self::FUNDING_STATUSES[$status])) {
			throw new \InvalidArgumentException('Der Status der Finanzierung ist ungültig.');
		}
		$amount = $input['amountCents'] ?? null;
		if (($amount === null || $amount === '') && isset($input['amount']) && trim((string)$input['amount']) !== '') {
			$text = str_replace(['€', ' '], '', trim((string)$input['amount']));
			if (str_contains($text, ',')) {
				$text = str_replace(['.', ','], ['', '.'], $text);
			}
			if (!is_numeric($text)) {
				throw new \InvalidArgumentException('Der Betrag ist ungültig.');
			}
			$amount = (int)round((float)$text * 100);
		}
		$amount = $amount === null || $amount === '' ? null : (int)$amount;
		if ($amount !== null && ($amount < 0 || $amount > 100000000)) {
			throw new \InvalidArgumentException('Der Betrag ist ungültig.');
		}
		$date = trim((string)($input['contractedAt'] ?? ''));
		if ($date !== '' && !self::isDate($date)) {
			throw new \InvalidArgumentException('Das Abschlussdatum ist ungültig.');
		}
		$text = static fn (string $key, int $max): ?string => ($value = mb_substr(trim((string)($input[$key] ?? '')), 0, $max)) !== '' ? $value : null;
		return ['funding_type' => $type, 'provider_name' => $text('providerName', 200), 'contract_number' => $text('contractNumber', 100), 'amount_cents' => $amount,
			'contracted_at' => $date !== '' ? $date : null, 'funding_status' => $status, 'notes' => $text('notes', 2000)];
	}

	public static function mapFunding(array $row): array {
		$type = (string)$row['funding_type'];
		$status = (string)$row['funding_status'];
		return [
			'id' => (int)$row['id'], 'caseId' => (int)$row['case_id'], 'fundingType' => $type, 'fundingTypeLabel' => self::FUNDING_TYPES[$type] ?? $type,
			'providerName' => (string)($row['provider_name'] ?? ''), 'providerKey' => (string)($row['provider_key'] ?? self::PROVIDER_MANUAL),
			'contractNumber' => (string)($row['contract_number'] ?? ''), 'amountCents' => $row['amount_cents'] !== null ? (int)$row['amount_cents'] : null,
			'contractedAt' => (string)($row['contracted_at'] ?? ''), 'status' => $status, 'statusLabel' => self::FUNDING_STATUSES[$status] ?? $status,
			'externalReference' => (string)($row['external_reference'] ?? ''), 'externalStatus' => (string)($row['external_status'] ?? ''), 'lastCheckedAt' => (string)($row['last_checked_at'] ?? ''),
			'notes' => (string)($row['notes'] ?? ''), 'createdAt' => (string)$row['created_at'], 'updatedAt' => (string)$row['updated_at'],
		];
	}

	public static function addMonths(string $date, int $months): string {
		$base = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10)) ?: new \DateTimeImmutable('today');
		$target = $base->modify('first day of +' . max(0, $months) . ' month');
		$day = min((int)$base->format('d'), (int)$target->format('t'));
		return $target->setDate((int)$target->format('Y'), (int)$target->format('m'), $day)->format('Y-m-d');
	}

	public static function addDays(string $date, int $days): string {
		$base = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10)) ?: new \DateTimeImmutable('today');
		return $base->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
	}

	public static function isDate(string $value): bool {
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		return $date !== false && $date->format('Y-m-d') === $value;
	}

	private static function months(mixed $value): int {
		$months = (int)$value;
		return $months >= 1 && $months <= 120 ? $months : self::DEFAULT_REVIEW_MONTHS;
	}

	private static function safeUrl(string $url): string {
		$url = trim($url);
		return preg_match('#^https://[^\s<>"]{3,500}$#', $url) === 1 ? $url : '';
	}
}
