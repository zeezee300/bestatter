<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use DateTimeImmutable;
use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * Read-only operational projections used by dashboard, phase gates and assistant.
 * Core workflows do not depend on AI and remain available when no provider exists.
 */
class OperationalService {
	private const PHASES = [
		'INTAKE' => [
			'label' => 'Aufnahme und Stammdaten',
			'navigation' => ['caseTab' => 'master', 'label' => 'Stammdaten ergänzen'],
			'fields' => ['first_name' => 'Vorname', 'last_name' => 'Nachname', 'date_of_death' => 'Sterbedatum', 'place_of_death' => 'Sterbeort', 'responsible_employee' => 'Zuständiger Mitarbeiter', 'branch' => 'Niederlassung'],
		],
		'ARRANGEMENT' => [
			'label' => 'Auftrag und Organisation',
			'navigation' => ['caseTab' => 'order', 'label' => 'Auftrag und Leistungen prüfen'],
			'fields' => ['funeral_type' => 'Bestattungsart', 'order_client_name' => 'Auftraggeber', 'cemetery_contact' => 'Friedhof / Beisetzungsort'],
		],
		'CEREMONY' => [
			'label' => 'Trauerfeier und Durchführung',
			'navigation' => ['caseTab' => 'schedule', 'label' => 'Termine und Folgeaktionen prüfen'],
			'fields' => [],
		],
		'BILLING' => [
			'label' => 'Abrechnung',
			'navigation' => ['caseTab' => 'finances', 'label' => 'Abrechnung vorbereiten'],
			'fields' => ['invoice_name' => 'Rechnungsempfänger', 'invoice_street' => 'Rechnungsanschrift', 'invoice_postal_city' => 'PLZ / Ort des Rechnungsempfängers'],
		],
	];

	public function __construct(
		private IDBConnection $db,
		private IUserSession $userSession,
		private CaseService $cases,
	) {}

	public function personalDay(string $date = ''): array {
		$day = $this->validDate($date);
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') throw new \RuntimeException('Kein Nextcloud-Benutzer angemeldet.');
		$records = $this->recordsForUser($uid);
		$tasks = array_values(array_filter($records, static fn(array $row): bool => $row['type'] === 'task' && $row['status'] !== 'ERLEDIGT'));
		$schedules = array_values(array_filter($records, static fn(array $row): bool => $row['type'] === 'schedule' && $row['status'] !== 'ERLEDIGT'));
		$onDay = static fn(array $row): bool => substr((string)$row['date'], 0, 10) === $day;
		$overdue = static fn(array $row): bool => ($recordDay = substr((string)$row['date'], 0, 10)) !== '' && $recordDay < $day;
		$myCases = $this->cases->searchCases('', 'OPEN', 'ALL', $uid, 100, 0);

		return [
			'date' => $day,
			'userUid' => $uid,
			'openCases' => $myCases['items'],
			'tasksToday' => array_values(array_filter($tasks, $onDay)),
			'overdueTasks' => array_values(array_filter($tasks, $overdue)),
			'schedulesToday' => array_values(array_filter($schedules, $onDay)),
			'nextTasks' => array_slice($tasks, 0, 20),
			'counts' => [
				'openCases' => (int)$myCases['total'],
				'tasksToday' => count(array_filter($tasks, $onDay)),
				'overdueTasks' => count(array_filter($tasks, $overdue)),
				'schedulesToday' => count(array_filter($schedules, $onDay)),
			],
		];
	}

	public function completeness(int $caseId, string $phase = 'ALL'): array {
		$case = $this->cases->getCase($caseId);
		$data = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$data += [
			'first_name' => $case['firstName'] ?? '', 'last_name' => $case['lastName'] ?? '',
			'date_of_death' => $case['dateOfDeath'] ?? '', 'funeral_type' => $case['funeralType'] ?? '',
			'branch' => $case['branch'] ?? '', 'responsible_employee' => $case['responsibleEmployee'] ?? '',
		];
		$phase = strtoupper(trim($phase));
		if ($phase !== 'ALL' && !isset(self::PHASES[$phase])) throw new \InvalidArgumentException('Die gewählte Prozessphase ist ungültig.');
		$phases = $phase === 'ALL' ? array_keys(self::PHASES) : [$phase];
		$result = [];
		foreach ($phases as $key) $result[$key] = $this->phaseResult($caseId, $key, $data);
		$required = array_sum(array_column($result, 'required'));
		$complete = array_sum(array_column($result, 'complete'));
		$nextActions = [];
		foreach ($result as $phaseResult) if (!$phaseResult['ready']) $nextActions[] = [
			'phase' => $phaseResult['key'], 'label' => $phaseResult['navigation']['label'],
			'caseTab' => $phaseResult['navigation']['caseTab'],
			'missing' => array_values(array_map(static fn(array $check): string => $check['label'], array_filter($phaseResult['checks'], static fn(array $check): bool => !$check['complete']))),
		];
		return [
			'caseId' => $caseId, 'caseNumber' => $case['caseNumber'], 'phase' => $phase,
			'complete' => $complete, 'required' => $required,
			'percentage' => $required > 0 ? (int)round(($complete / $required) * 100) : 100,
			'ready' => $complete === $required, 'phases' => array_values($result), 'nextActions' => $nextActions,
		];
	}

	private function phaseResult(int $caseId, string $phase, array $data): array {
		$definition = self::PHASES[$phase];
		$checks = [];
		foreach ($definition['fields'] as $field => $label) {
			$value = trim((string)($data[$field] ?? ''));
			$checks[] = ['key' => $field, 'label' => $label, 'complete' => $value !== '', 'kind' => 'FIELD'];
		}
		if ($phase === 'ARRANGEMENT') {
			$checks[] = $this->countCheck($caseId, 'services', 'Mindestens eine beauftragte Leistung', 'bestatter_case_services');
		}
		if ($phase === 'CEREMONY') {
			$checks[] = $this->recordCheck($caseId, 'schedule', 'Mindestens ein externer Termin');
		}
		if ($phase === 'BILLING') {
			$checks[] = $this->countCheck($caseId, 'services', 'Abrechenbare Leistungen vorhanden', 'bestatter_case_services');
			$checks[] = $this->commercialCheck($caseId, 'ORDER', 'Festgeschriebener Auftrag fehlt');
			$sideOrderCheck = $this->sideOrderCheck($caseId);
			if ($sideOrderCheck !== null) $checks[] = $sideOrderCheck;
		}
		$complete = count(array_filter($checks, static fn(array $check): bool => $check['complete']));
		return [
			'key' => $phase, 'label' => $definition['label'], 'complete' => $complete,
			'required' => count($checks), 'ready' => $complete === count($checks),
			'percentage' => $checks === [] ? 100 : (int)round(($complete / count($checks)) * 100),
			'checks' => $checks, 'navigation' => $definition['navigation'],
		];
	}

	private function recordsForUser(string $uid): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('r.id', 'r.case_id', 'r.record_type', 'r.title', 'r.record_date', 'r.status', 'r.assignee_uid', 'r.payload')
			->selectAlias('c.case_number', 'case_number')->selectAlias('c.first_name', 'case_first_name')->selectAlias('c.last_name', 'case_last_name')
			->from('bestatter_records', 'r')->leftJoin('r', 'bestatter_cases', 'c', $query->expr()->eq('c.id', 'r.case_id'))
			->where($query->expr()->orX(
				$query->expr()->eq('r.record_type', $query->createNamedParameter('task')),
				$query->expr()->eq('r.record_type', $query->createNamedParameter('schedule')),
			))
			->andWhere($query->expr()->orX(
				$query->expr()->eq('r.assignee_uid', $query->createNamedParameter($uid)),
				$query->expr()->eq('r.owner_uid', $query->createNamedParameter($uid)),
				$query->expr()->like('r.payload', $query->createNamedParameter('%"' . $uid . '"%')),
			))->orderBy('r.record_date', 'ASC')->setMaxResults(250)->executeQuery()->fetchAllAssociative();
		return array_map(static function (array $row): array {
			return [
				'id' => (int)$row['id'], 'caseId' => (int)($row['case_id'] ?? 0), 'caseNumber' => (string)($row['case_number'] ?? ''),
				'caseName' => trim((string)($row['case_first_name'] ?? '') . ' ' . (string)($row['case_last_name'] ?? '')),
				'type' => (string)$row['record_type'], 'title' => (string)$row['title'], 'date' => (string)$row['record_date'],
				'status' => (string)$row['status'], 'assigneeUid' => (string)($row['assignee_uid'] ?? ''),
			];
		}, $rows);
	}

	private function countCheck(int $caseId, string $key, string $label, string $table): array {
		$query = $this->db->getQueryBuilder();
		$count = (int)$query->select($query->func()->count('*', 'count'))->from($table)
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))->executeQuery()->fetchOne();
		return ['key' => $key, 'label' => $label, 'complete' => $count > 0, 'kind' => 'RECORD', 'count' => $count];
	}

	private function recordCheck(int $caseId, string $type, string $label): array {
		$query = $this->db->getQueryBuilder();
		$count = (int)$query->select($query->func()->count('*', 'count'))->from('bestatter_records')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->andWhere($query->expr()->eq('record_type', $query->createNamedParameter($type)))
			->andWhere($query->expr()->like('payload', $query->createNamedParameter('%"scheduleKind":"EXTERNAL_APPOINTMENT"%')))
			->executeQuery()->fetchOne();
		return ['key' => $type, 'label' => $label, 'complete' => $count > 0, 'kind' => 'RECORD', 'count' => $count];
	}

	private function commercialCheck(int $caseId, string $type, string $label): array {
		$query = $this->db->getQueryBuilder();
		$count = (int)$query->select($query->func()->count('*', 'count'))->from('bestatter_commercial_docs')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))
			->andWhere($query->expr()->eq('document_type', $query->createNamedParameter($type)))->executeQuery()->fetchOne();
		return ['key' => strtolower($type), 'label' => $label, 'complete' => $count > 0, 'kind' => 'COMMERCIAL', 'count' => $count];
	}

	private function sideOrderCheck(int $caseId): ?array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('side_order_number', 'status')->from('bestatter_side_orders')
			->where($query->expr()->eq('case_id', $query->createNamedParameter($caseId)))->executeQuery()->fetchAllAssociative();
		if ($rows === []) return null;
		$open = array_values(array_filter($rows, static fn(array $row): bool => in_array((string)$row['status'], ['ENTWURF', 'BEAUFTRAGT'], true)));
		return ['key' => 'side_orders', 'label' => $open === [] ? 'Alle Nebenaufträge erledigt' : count($open) . ' Nebenauftrag/Nebenaufträge noch offen', 'complete' => $open === [], 'kind' => 'SIDE_ORDER', 'count' => count($rows)];
	}

	private function validDate(string $date): string {
		$date = trim($date);
		if ($date === '') return date('Y-m-d');
		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		if ($parsed === false || $parsed->format('Y-m-d') !== $date) throw new \InvalidArgumentException('Das Datum muss im Format JJJJ-MM-TT angegeben werden.');
		return $date;
	}
}
