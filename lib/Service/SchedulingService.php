<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserSession;

class SchedulingService {
	private const KINDS = ['INTERNAL_ACTIVITY', 'EXTERNAL_APPOINTMENT'];
	/** 0.92.0: Bisherige Einzelspalten des Pflichtbedarfs; werden neben required_resources weiter mitgeschrieben. */
	private const LEGACY_REQUIREMENT_COLUMNS = ['CHAPEL' => 'required_chapels', 'ROOM' => 'required_rooms', 'VEHICLE' => 'required_vehicles', 'EQUIPMENT' => 'required_equipment'];
	/** 0.90.5: App-Einstellung mit der Arbeitszeit für die Terminplanung (JSON {start, end}). */
	public const WORKING_HOURS_KEY = 'scheduling_working_hours';
	/** 0.92.0: Format der Terminplanung im Einrichtungspaket (Terminarten mit Pflichtbedarf und Zuordnung, Ressourcen). */
	public const EXCHANGE_FORMAT = 'bestatter-scheduling';
	/** Übertragene Felder einer Terminart bzw. Ressource (ohne Datenbank-ID, verknüpfte Person und Änderungsvermerk). */
	public const EXCHANGE_TYPE_FIELDS = ['key', 'name', 'scheduleKind', 'durationMinutes', 'bufferBeforeMinutes', 'bufferAfterMinutes', 'requiredStaff', 'requiredResources', 'allowedResourceKeys', 'defaultResourceKeys', 'active', 'sortOrder'];
	public const EXCHANGE_RESOURCE_FIELDS = ['key', 'resourceType', 'name', 'identifier', 'description', 'branchKeys', 'conflictRelevant', 'active', 'sortOrder'];

	public function __construct(
		private IDBConnection $db,
		private ?IUserSession $userSession,
		private ?IConfig $config = null,
	) {
	}

	/**
	 * 0.90.3: Mit $activeOnly = false (nur Customizing, Bestatter-Administratoren) auch deaktivierte Terminarten und
	 * Ressourcen, damit sie sich wieder aktivieren lassen. Die Hinweise zur Zuordnung gelten immer für alle Einträge.
	 */
	public function catalog(bool $activeOnly = true): array {
		$this->seed();
		return [
			'types' => $this->types($activeOnly),
			'resources' => $this->resources($activeOnly),
			// 0.92.0: Werteliste „Ressourcentypen“ (Bezeichnung, Mehrzahl, Symbol) und Niederlassungen für Filter und Anzeige.
			'resourceTypes' => $this->resourceTypes(),
			'branches' => $this->branches(),
			'assignmentIssues' => $this->assignmentIssues(),
			'workingHours' => $this->workingHours(),
		];
	}

	/**
	 * 0.92.0: Ressourcentypen aus der Werteliste „Ressourcentypen“ (Stammdaten → Wertelisten); ohne Liste die Standardtypen.
	 *
	 * @return list<array{key:string,label:string,plural:string,icon:string,system:bool}>
	 */
	public function resourceTypes(): array {
		try {
			$list = $this->db->getQueryBuilder();
			$listId = $list->select('id')->from('bestatter_choice_lists')->where($list->expr()->eq('list_key', $list->createNamedParameter(SchedulingRules::RESOURCE_TYPE_LIST)))->executeQuery()->fetchOne();
			if ($listId === false) {
				return SchedulingRules::defaultResourceTypes();
			}
			$items = $this->db->getQueryBuilder();
			$rows = $items->select('value', 'label', 'metadata')->from('bestatter_choice_items')->where($items->expr()->eq('list_id', $items->createNamedParameter((int)$listId)))->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
		} catch (\Throwable) {
			return SchedulingRules::defaultResourceTypes();
		}
		return SchedulingRules::resourceTypesFromItems(array_map(static fn (array $row): array => ['value' => (string)$row['value'], 'label' => (string)$row['label'], 'metadata' => json_decode((string)($row['metadata'] ?? ''), true) ?: []], $rows));
	}

	/**
	 * 0.92.0: Niederlassungen (Schlüssel, Name, aktiv) für die Zuordnung der Ressourcen.
	 *
	 * @return list<array{key:string,name:string,active:bool}>
	 */
	public function branches(): array {
		try {
			$q = $this->db->getQueryBuilder();
			$rows = $q->select('branch_key', 'name', 'active')->from('bestatter_branches')->orderBy('name', 'ASC')->executeQuery()->fetchAllAssociative();
		} catch (\Throwable) {
			return [];
		}
		return array_map(static fn (array $row): array => ['key' => (string)$row['branch_key'], 'name' => (string)$row['name'], 'active' => (bool)$row['active']], $rows);
	}

	/** @return list<array{key:string,name:string,active:bool}> */
	private function activeBranches(): array {
		return array_values(array_filter($this->branches(), static fn (array $branch): bool => $branch['active']));
	}

	/**
	 * 0.90.5: Arbeitszeit für die Terminplanung (Customizing → Terminplanung). Ohne Eintrag oder bei ungültigem Eintrag gilt
	 * 07:00–18:00 Uhr.
	 *
	 * @return array{start:string,end:string}
	 */
	public function workingHours(): array {
		$stored = $this->config?->getAppValue(Application::APP_ID, self::WORKING_HOURS_KEY, '') ?? '';
		$decoded = json_decode((string)$stored, true);
		try {
			return SchedulingRules::normalizeWorkingHours(is_array($decoded) ? $decoded : []);
		} catch (\InvalidArgumentException) {
			return SchedulingRules::DEFAULT_WORKING_HOURS;
		}
	}

	/** @return array{start:string,end:string} */
	public function saveWorkingHours(array $input): array {
		$hours = SchedulingRules::normalizeWorkingHours($input);
		if ($this->config === null) {
			throw new \RuntimeException('Die Einstellungen sind nicht verfügbar.');
		}
		$this->config->setAppValue(Application::APP_ID, self::WORKING_HOURS_KEY, json_encode($hours, JSON_THROW_ON_ERROR));
		return $hours;
	}

	/** Für die Systemprüfung: Terminarten, deren Pflichtbedarf mit den zugeordneten Ressourcen nicht erfüllbar ist. */
	public function assignmentIssues(): array {
		return SchedulingRules::assignmentIssues($this->types(true), $this->resources(false), $this->resourceTypes(), $this->activeBranches());
	}

	/**
	 * 0.90.3: Matrix „Ressourcen je Terminart“ speichern – je Terminart die zulässigen und die vorbelegten Ressourcen.
	 * Terminarten, die in $assignments fehlen, bleiben unverändert.
	 *
	 * @param array<string,mixed> $assignments Terminart-Schlüssel → {allowed: list<string>, defaults: list<string>}
	 */
	public function saveAssignments(array $assignments): array {
		$this->seed();
		$resources = $this->resources(false);
		$types = [];
		foreach ($this->types(false) as $type) {
			$types[$type['key']] = $type;
		}
		$rows = [];
		foreach ($assignments as $typeKey => $assignment) {
			$typeKey = strtoupper(trim((string)$typeKey));
			if (!isset($types[$typeKey])) {
				throw new \InvalidArgumentException('Die Terminart „' . $typeKey . '“ ist nicht bekannt.');
			}
			$assignment = is_array($assignment) ? $assignment : [];
			$rows[$types[$typeKey]['id']] = SchedulingRules::normalizeAssignment($assignment['allowed'] ?? [], $assignment['defaults'] ?? [], $resources);
		}
		$this->db->beginTransaction();
		try {
			foreach ($rows as $id => $row) {
				$q = $this->db->getQueryBuilder();
				$q->update('bestatter_schedule_types')
					->set('allowed_resource_keys', $q->createNamedParameter(json_encode($row['allowed'], JSON_THROW_ON_ERROR)))
					->set('default_resource_keys', $q->createNamedParameter(json_encode($row['defaults'], JSON_THROW_ON_ERROR)))
					->where($q->expr()->eq('id', $q->createNamedParameter($id)))
					->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}
		return $this->catalog(false);
	}

	public function saveType(array $input, int $id = 0): array {
		$key = strtoupper(trim((string)($input['key'] ?? '')));
		$name = trim((string)($input['name'] ?? ''));
		if ($key === '' && $id === 0 && $name !== '') {
			$key = SchedulingRules::keyFromName($name, array_column($this->types(false), 'key'));
		}
		$kind = strtoupper((string)($input['scheduleKind'] ?? 'EXTERNAL_APPOINTMENT'));
		if (!preg_match('/^[A-Z0-9_-]+$/', $key) || $name === '') {
			throw new \InvalidArgumentException('Terminart: technischer Schlüssel und Bezeichnung sind erforderlich.');
		}
		if (!in_array($kind, self::KINDS, true)) {
			throw new \InvalidArgumentException('Die fachliche Terminart ist ungültig.');
		}
		$values = ['type_key' => $key,'name' => $name,'schedule_kind' => $kind,'default_duration' => max(15, (int)($input['durationMinutes'] ?? 60)),'buffer_before' => max(0, (int)($input['bufferBeforeMinutes'] ?? 0)),'buffer_after' => max(0, (int)($input['bufferAfterMinutes'] ?? 0)),'required_staff' => max(0, (int)($input['requiredStaff'] ?? 0)),'active' => filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN)?1:0,'sort_order' => max(0, (int)($input['sortOrder'] ?? 0))];
		// 0.92.0: Pflichtbedarf je Ressourcentyp (auch eigene Typen aus der Werteliste); die vier Standardspalten bleiben gefüllt.
		$required = SchedulingRules::normalizeRequirements(SchedulingRules::requiredResources($input), $this->resourceTypes());
		$values['required_resources'] = json_encode((object)$required, JSON_THROW_ON_ERROR);
		foreach (self::LEGACY_REQUIREMENT_COLUMNS as $resourceType => $column) {
			$values[$column] = $required[$resourceType] ?? 0;
		}
		// 0.90.3: Zulässige und vorbelegte Ressourcen pflegt die Matrix „Ressourcen je Terminart“ (saveAssignments).
		// Nur wenn sie mitgeschickt werden (API, Einrichtung), werden sie hier gesetzt; sonst bleiben sie unverändert.
		if ($id === 0 || array_key_exists('allowedResourceKeys', $input) || array_key_exists('defaultResourceKeys', $input)) {
			$assignment = SchedulingRules::normalizeAssignment($input['allowedResourceKeys'] ?? [], $input['defaultResourceKeys'] ?? [], $this->resources(false));
			$values['allowed_resource_keys'] = json_encode($assignment['allowed'], JSON_THROW_ON_ERROR);
			$values['default_resource_keys'] = json_encode($assignment['defaults'], JSON_THROW_ON_ERROR);
		}
		$q = $this->db->getQueryBuilder();
		if ($id > 0) {
			$q->update('bestatter_schedule_types');
			foreach ($values as $column => $value) {
				$q->set($column, $q->createNamedParameter($value));
			}$q->where($q->expr()->eq('id', $q->createNamedParameter($id)));
		} else {
			$q->insert('bestatter_schedule_types')->values(array_map(fn ($value) => $q->createNamedParameter($value), $values));
		}
		$q->executeStatement();
		return $this->type($id ?: (int)$this->db->lastInsertId('bestatter_schedule_types'));
	}

	/**
	 * 0.92.0: Ressource speichern (Stammdaten → Ressourcen) – mit Nummer/Kennung, Beschreibung, Niederlassungen (leer = alle)
	 * und „Letzte Änderung“. Der Ressourcentyp stammt aus der Werteliste „Ressourcentypen“; „Mitarbeiter“ bleibt nur bei
	 * bestehenden Einträgen erhalten. Das frühere Feld „Nextcloud-Benutzer“ bleibt unverändert, wenn es nicht mitkommt.
	 */
	public function saveResource(array $input, int $id = 0): array {
		$existing = $id > 0 ? $this->resource($id) : null;
		$key = strtoupper(trim((string)($input['key'] ?? ($existing['key'] ?? ''))));
		$name = trim((string)($input['name'] ?? ''));
		if ($key === '' && $id === 0 && $name !== '') {
			$key = SchedulingRules::keyFromName($name, array_column($this->resources(false), 'key'));
		}
		$type = strtoupper(trim((string)($input['resourceType'] ?? 'EQUIPMENT')));
		if (!preg_match('/^[A-Z0-9_-]+$/', $key) || $name === '') {
			throw new \InvalidArgumentException('Ressource: technischer Schlüssel und Bezeichnung sind erforderlich.');
		}
		$knownTypes = array_column($this->resourceTypes(), 'key');
		if (!in_array($type, $knownTypes, true) && !($type === 'STAFF' && ($existing['resourceType'] ?? '') === 'STAFF')) {
			throw new \InvalidArgumentException('Der Ressourcentyp „' . $type . '“ ist nicht bekannt. Ressourcentypen pflegen Sie unter Stammdaten → Wertelisten → „Ressourcentypen“.');
		}
		$identifier = trim((string)($input['identifier'] ?? ''));
		if (mb_strlen($identifier) > SchedulingRules::IDENTIFIER_MAX) {
			throw new \InvalidArgumentException('Die Nummer bzw. Kennung darf höchstens ' . SchedulingRules::IDENTIFIER_MAX . ' Zeichen lang sein.');
		}
		$description = trim((string)($input['description'] ?? ''));
		if (mb_strlen($description) > SchedulingRules::DESCRIPTION_MAX) {
			throw new \InvalidArgumentException('Die Beschreibung darf höchstens ' . SchedulingRules::DESCRIPTION_MAX . ' Zeichen lang sein.');
		}
		$branchKeys = SchedulingRules::normalizeBranchKeys($input['branchKeys'] ?? [], array_column($this->branches(), 'key'));
		$linkedUid = array_key_exists('linkedUid', $input) ? trim((string)$input['linkedUid']) : (string)($existing['linkedUid'] ?? '');
		$values = [
			'resource_key' => $key, 'name' => $name, 'resource_type' => $type, 'linked_uid' => $linkedUid ?: null,
			'conflict_relevant' => filter_var($input['conflictRelevant'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
			'active' => filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
			'sort_order' => max(0, (int)($input['sortOrder'] ?? ($existing['sortOrder'] ?? 0))),
			'identifier' => $identifier !== '' ? $identifier : null,
			'description' => $description !== '' ? $description : null,
			'branch_keys' => json_encode($branchKeys, JSON_THROW_ON_ERROR),
			'updated_at' => date('c'),
			'updated_by' => $this->userSession?->getUser()?->getUID() ?? 'system',
		];
		$q = $this->db->getQueryBuilder();
		if ($id > 0) {
			$q->update('bestatter_resources');
			foreach ($values as $column => $value) {
				$q->set($column, $q->createNamedParameter($value));
			}
			$q->where($q->expr()->eq('id', $q->createNamedParameter($id)));
		} else {
			$q->insert('bestatter_resources')->values(array_map(fn ($value) => $q->createNamedParameter($value), $values));
		}
		$q->executeStatement();
		return $this->resource($id ?: (int)$this->db->lastInsertId('bestatter_resources'));
	}

	public function prepare(string $json, string $date, int $excludeRecordId = 0, int $caseId = 0): array {
		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($data)) {
			$data = [];
		}
		$typeKey = strtoupper(trim((string)($data['scheduleTypeKey'] ?? $data['schedulePresetKey'] ?? '')));
		$type = $typeKey !== ''?$this->typeByKey($typeKey):null;
		if ($type) {
			$data['scheduleTypeKey'] = $type['key'];
			$data['scheduleKind'] = $data['scheduleKind'] ?? $type['scheduleKind'];
			$data['appointmentCategory'] = $data['appointmentCategory'] ?? $type['name'];
			$data['durationMinutes'] = max(15, (int)($data['durationMinutes'] ?? $type['durationMinutes']));
			$data['bufferBeforeMinutes'] = max(0, (int)($data['bufferBeforeMinutes'] ?? $type['bufferBeforeMinutes']));
			$data['bufferAfterMinutes'] = max(0, (int)($data['bufferAfterMinutes'] ?? $type['bufferAfterMinutes']));
			if (!array_key_exists('resourceKeys', $data)) {
				$data['resourceKeys'] = $type['defaultResourceKeys'];
			}$data['resourceRequirements'] = $this->requirements($type);
		}
		$data['durationMinutes'] = max(15, (int)($data['durationMinutes'] ?? 60));
		$data['bufferBeforeMinutes'] = max(0, (int)($data['bufferBeforeMinutes'] ?? 0));
		$data['bufferAfterMinutes'] = max(0, (int)($data['bufferAfterMinutes'] ?? 0));
		$data['resourceKeys'] = array_values(array_unique(array_filter(array_map(static fn ($v):string => strtoupper(trim((string)$v)), (array)($data['resourceKeys'] ?? [])))));
		// 0.90.3: Nur Ressourcen, die der Terminart zugeordnet sind (Matrix im Customizing); ohne Terminart keine.
		$typeForResources = $type ?? ($typeKey !== '' ? $this->typeByKeyAnyState($typeKey) : null);
		// 0.92.0: Mit Fallbezug nur Ressourcen der Niederlassung des Falls (oder „Alle Niederlassungen“).
		[$branchKey, $branchName] = $this->caseBranch($caseId);
		$allResources = $this->resources(false);
		$selectionErrors = SchedulingRules::selectionErrors($typeForResources, $data['resourceKeys'], $allResources, $excludeRecordId > 0 ? $this->previousResourceKeys($excludeRecordId) : [], $branchKey, $branchName);
		if ($selectionErrors !== []) {
			throw new \InvalidArgumentException(implode(' ', $selectionErrors));
		}
		// 0.92.0: Bezeichnung, Kennung und Typ zum Zeitpunkt des Speicherns – für Listen, Kalenderbeschreibung und Verlauf.
		$byKey = SchedulingRules::resourcesByKey($allResources);
		$data['resourceSummary'] = array_values(array_map(static fn (string $key): array => ['key' => $key, 'name' => (string)($byKey[$key]['name'] ?? $key), 'identifier' => (string)($byKey[$key]['identifier'] ?? ''), 'resourceType' => (string)($byKey[$key]['resourceType'] ?? '')], $data['resourceKeys']));
		$this->assertRequirements($data, $type);
		$conflicts = $this->conflicts($date, $data, $excludeRecordId);
		$override = filter_var($data['conflictOverride'] ?? false, FILTER_VALIDATE_BOOLEAN);
		$reason = trim((string)($data['changeReason'] ?? $data['conflictReason'] ?? ''));
		if ($conflicts !== [] && !$override) {
			$alternatives = $this->alternatives($date, $data, $excludeRecordId);
			$hint = $alternatives !== []?' Mögliche Alternativen: ' . implode(', ', array_column($alternatives, 'label')) . '.':'';
			throw new \InvalidArgumentException('Terminkonflikt: ' . implode(' · ', array_column($conflicts, 'message')) . '.' . $hint . ' Bitte einen Vorschlag übernehmen, den Termin ändern oder begründet übersteuern.');
		}
		if ($conflicts !== [] && $override && $reason === '') {
			throw new \InvalidArgumentException('Für die Übersteuerung eines Terminkonflikts ist eine Begründung erforderlich.');
		}
		$data['conflictsAtSave'] = $conflicts;
		return $data;
	}

	public function requireChangeReason(array $previous, string $title, string $date, string $status, array $data): void {
		$changes = $this->changes($previous, ['title' => $title,'date' => $date,'status' => $status,'data' => $data]);
		// 0.89.10: Ein Termin, der wie geplant stattgefunden hat, wird ohne Begründung als erledigt markiert.
		if (count($changes) === 1 && $changes[0]['field'] === 'status' && strtoupper($status) === 'ERLEDIGT') {
			return;
		}
		// 0.90.5: Entwürfe (auch „Entwurf“ → „Bestätigt“) und die erneute Bestätigung nach „Geändert“ brauchen keinen Grund.
		if (!SchedulingRules::changeReasonRequired((string)($previous['status'] ?? ''), $status, array_column($changes, 'field'))) {
			return;
		}
		if ($changes !== [] && trim((string)($data['changeReason'] ?? '')) === '') {
			throw new \InvalidArgumentException('Bitte einen Änderungsgrund angeben. Änderungen an Zeitpunkt, Status, Ort, Beteiligten oder Ressourcen werden nachvollziehbar protokolliert.');
		}
	}

	public function conflicts(string $date, array $data, int $excludeRecordId = 0): array {
		if (trim($date) === '') {
			return[];
		}try {
			$start = new \DateTimeImmutable($date, new \DateTimeZone('Europe/Berlin'));
		} catch (\Throwable) {
			throw new \InvalidArgumentException('Datum und Uhrzeit des Termins sind ungültig.');
		}
		$from = $start->modify('-' . max(0, (int)($data['bufferBeforeMinutes'] ?? 0)) . ' minutes');
		$to = $start->modify('+' . (max(15, (int)($data['durationMinutes'] ?? 60)) + max(0, (int)($data['bufferAfterMinutes'] ?? 0))) . ' minutes');
		$relevantKeys = array_map(static fn (array $resource):string => $resource['key'], array_filter($this->resources(false), static fn (array $resource):bool => $resource['conflictRelevant']));
		$keys = array_values(array_intersect((array)($data['resourceKeys'] ?? []), $relevantKeys));
		$uids = (array)($data['assigneeUids'] ?? []);
		if ($keys === [] && $uids === []) {
			return[];
		}
		$q = $this->db->getQueryBuilder();
		$rows = $q->select('id', 'title', 'record_date', 'payload')->from('bestatter_records')->where($q->expr()->eq('record_type', $q->createNamedParameter('schedule')))->andWhere($q->expr()->neq('status', $q->createNamedParameter('ABGESAGT')))->executeQuery()->fetchAllAssociative();
		$result = [];
		foreach ($rows as $row) {
			if ((int)$row['id'] === $excludeRecordId || trim((string)$row['record_date']) === '') {
				continue;
			}$other = json_decode((string)$row['payload'], true)?:[];
			$shared = array_values(array_unique(array_merge(array_intersect($keys, (array)($other['resourceKeys'] ?? [])), array_intersect($uids, (array)($other['assigneeUids'] ?? [])))));
			if ($shared === []) {
				continue;
			}try {
				$otherStart = new \DateTimeImmutable((string)$row['record_date'], new \DateTimeZone('Europe/Berlin'));
			} catch (\Throwable) {
				continue;
			}$otherFrom = $otherStart->modify('-' . max(0, (int)($other['bufferBeforeMinutes'] ?? 0)) . ' minutes');
			$otherTo = $otherStart->modify('+' . (max(15, (int)($other['durationMinutes'] ?? 60)) + max(0, (int)($other['bufferAfterMinutes'] ?? 0))) . ' minutes');
			if ($from < $otherTo && $to > $otherFrom) {
				$result[] = ['recordId' => (int)$row['id'],'title' => (string)$row['title'],'resources' => $shared,'message' => '„' . $row['title'] . '“ überschneidet sich bei ' . implode(', ', $shared) . '.'];
			}
		}
		return$result;
	}

	public function alternatives(string $date, array $data, int $excludeRecordId = 0, int $limit = 3): array {
		if (trim($date) === '') {
			return [];
		}
		try {
			$candidate = new \DateTimeImmutable($date, new \DateTimeZone('Europe/Berlin'));
		} catch (\Throwable) {
			return [];
		}
		$candidate = $candidate->modify('+30 minutes');
		$result = [];
		// 0.90.5: Ersatzzeiten nur innerhalb der Arbeitszeit aus dem Customizing (bisher fest 08:00–18:00 Uhr).
		$hours = $this->workingHours();
		$windowStart = SchedulingRules::minutesOfDay($hours['start']);
		$windowEnd = SchedulingRules::minutesOfDay($hours['end']);
		for ($attempt = 0; $attempt < 672 && count($result) < max(1, $limit); $attempt++, $candidate = $candidate->modify('+30 minutes')) {
			$availableFrom = $candidate->modify('-' . max(0, (int)($data['bufferBeforeMinutes'] ?? 0)) . ' minutes');
			$duration = max(15, (int)($data['durationMinutes'] ?? 60)) + max(0, (int)($data['bufferAfterMinutes'] ?? 0));
			$end = $candidate->modify('+' . $duration . ' minutes');
			$fromMinutes = (int)$availableFrom->format('G') * 60 + (int)$availableFrom->format('i');
			$endMinutes = (int)$end->format('G') * 60 + (int)$end->format('i');
			if ($fromMinutes < $windowStart || $availableFrom->format('Y-m-d') !== $end->format('Y-m-d') || $endMinutes > $windowEnd) {
				continue;
			}
			if ($this->conflicts($candidate->format('Y-m-d\TH:i'), $data, $excludeRecordId) !== []) {
				continue;
			}
			$result[] = ['date' => $candidate->format('Y-m-d\TH:i'),'label' => $candidate->format('d.m.Y H:i') . ' Uhr'];
		}
		return $result;
	}

	/**
	 * 0.90.5: Verfügbarkeit für das Terminformular (wie der Terminplanungs-Assistent in Outlook). Für den Tag des Termins
	 * liefert sie je gewählter Person und Ressource die belegten Zeiträume aus anderen Bestatter-Terminen (einschließlich
	 * Vor- und Nachbereitung, ohne abgesagte) und ob sie den geplanten Zeitraum berühren. Grundlage ist dieselbe Regel wie
	 * bei der Konfliktprüfung beim Speichern; Ressourcen ohne „Konflikt prüfen“ werden nur angezeigt, nie als Konflikt
	 * gewertet. Persönliche Nextcloud-Kalender werden (noch) nicht ausgewertet.
	 *
	 * @param list<string> $uids
	 * @param list<string> $resourceKeys
	 */
	public function availability(string $date, array $data, array $uids, array $resourceKeys, int $excludeRecordId = 0): array {
		$zone = new \DateTimeZone('Europe/Berlin');
		try {
			$start = new \DateTimeImmutable(trim($date), $zone);
		} catch (\Throwable) {
			throw new \InvalidArgumentException('Datum und Uhrzeit des Termins sind ungültig.');
		}
		$window = $this->occupancy($start->format('Y-m-d\\TH:i'), $data);
		$dayStart = $start->setTime(0, 0);
		$dayEnd = $dayStart->modify('+1 day');
		$uids = array_values(array_unique(array_filter(array_map(static fn (mixed $uid): string => trim((string)$uid), $uids))));
		$resourceKeys = SchedulingRules::normalizeKeys($resourceKeys);
		$resources = SchedulingRules::resourcesByKey($this->resources(false));
		$people = [];
		foreach ($uids as $uid) {
			$people[$uid] = ['uid' => $uid, 'busy' => [], 'conflict' => false];
		}
		$items = [];
		foreach ($resourceKeys as $key) {
			$items[$key] = ['key' => $key, 'name' => isset($resources[$key]) ? SchedulingRules::resourceLabel($resources[$key]) : $key, 'resourceType' => (string)($resources[$key]['resourceType'] ?? ''), 'conflictRelevant' => (bool)($resources[$key]['conflictRelevant'] ?? true), 'busy' => [], 'conflict' => false];
		}
		if ($people !== [] || $items !== []) {
			$q = $this->db->getQueryBuilder();
			$rows = $q->select('id', 'title', 'record_date', 'payload')->from('bestatter_records')->where($q->expr()->eq('record_type', $q->createNamedParameter('schedule')))->andWhere($q->expr()->neq('status', $q->createNamedParameter('ABGESAGT')))->executeQuery()->fetchAllAssociative();
			foreach ($rows as $row) {
				if ((int)$row['id'] === $excludeRecordId || trim((string)$row['record_date']) === '') {
					continue;
				}
				$other = json_decode((string)$row['payload'], true) ?: [];
				$span = $this->occupancy((string)$row['record_date'], $other);
				if ($span === null || $span[0] >= $dayEnd || $span[1] <= $dayStart) {
					continue;
				}
				$overlaps = $window !== null && $window[0] < $span[1] && $window[1] > $span[0];
				$block = ['recordId' => (int)$row['id'], 'title' => (string)$row['title'], 'from' => $span[0]->format('Y-m-d\\TH:i'), 'to' => $span[1]->format('Y-m-d\\TH:i'), 'overlaps' => $overlaps];
				foreach (array_intersect(array_keys($people), array_map('strval', (array)($other['assigneeUids'] ?? []))) as $uid) {
					$people[$uid]['busy'][] = $block;
					$people[$uid]['conflict'] = $people[$uid]['conflict'] || $overlaps;
				}
				foreach (array_intersect(array_keys($items), SchedulingRules::normalizeKeys($other['resourceKeys'] ?? [])) as $key) {
					$items[$key]['busy'][] = $block;
					$items[$key]['conflict'] = $items[$key]['conflict'] || ($overlaps && $items[$key]['conflictRelevant']);
				}
			}
		}
		$sort = static function (array $entry): array {
			usort($entry['busy'], static fn (array $left, array $right): int => strcmp($left['from'], $right['from']));
			return $entry;
		};
		return [
			'date' => $dayStart->format('Y-m-d'),
			'window' => $window === null ? null : ['from' => $window[0]->format('Y-m-d\\TH:i'), 'to' => $window[1]->format('Y-m-d\\TH:i')],
			'workingHours' => $this->workingHours(),
			'people' => array_values(array_map($sort, $people)),
			'resources' => array_values(array_map($sort, $items)),
		];
	}

	/**
	 * 0.90.5: Belegter Zeitraum eines Termins einschließlich Vor- und Nachbereitung.
	 *
	 * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}|null
	 */
	private function occupancy(string $date, array $data): ?array {
		try {
			$start = new \DateTimeImmutable($date, new \DateTimeZone('Europe/Berlin'));
		} catch (\Throwable) {
			return null;
		}
		return [
			$start->modify('-' . max(0, (int)($data['bufferBeforeMinutes'] ?? 0)) . ' minutes'),
			$start->modify('+' . (max(15, (int)($data['durationMinutes'] ?? 60)) + max(0, (int)($data['bufferAfterMinutes'] ?? 0))) . ' minutes'),
		];
	}

	public function log(array $record, string $action, string $reason = '', ?array $previous = null): void {
		$changes = $previous === null?[]:$this->changes($previous, $record);
		$q = $this->db->getQueryBuilder();
		$q->insert('bestatter_schedule_history')->values(['record_id' => $q->createNamedParameter((int)$record['id']),'case_id' => $q->createNamedParameter((int)($record['caseId'] ?? 0)?:null),'action' => $q->createNamedParameter($action),'change_reason' => $q->createNamedParameter(trim($reason)?:null),'snapshot_json' => $q->createNamedParameter(json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),'changes_json' => $q->createNamedParameter(json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),'changed_by' => $q->createNamedParameter($this->userSession?->getUser()?->getUID() ?? 'system'),'created_at' => $q->createNamedParameter(date('c'))])->executeStatement();
	}
	public function history(int $recordId): array {
		$q = $this->db->getQueryBuilder();
		$rows = $q->select('*')->from('bestatter_schedule_history')->where($q->expr()->eq('record_id', $q->createNamedParameter($recordId)))->orderBy('created_at', 'DESC')->executeQuery()->fetchAllAssociative();
		return array_map(static fn ($r) => ['id' => (int)$r['id'],'action' => $r['action'],'reason' => (string)($r['change_reason'] ?? ''),'changes' => json_decode((string)($r['changes_json'] ?? '[]'), true)?:[],'snapshot' => json_decode($r['snapshot_json'], true)?:[],'changedBy' => $r['changed_by'],'createdAt' => $r['created_at']], $rows);
	}

	// Befund #19 (2026-09-20): Ursprünglich seedete diese Methode nur bei komplett leerer Tabelle (Neuinstallation).
	// Auf bereits installierten Systemen kamen dadurch nachträglich ergänzte Standard-Terminarten nie an, während der
	// unabhängig gepflegte Terminvorlagen-Katalog (heute resources/schedule-presets.json) bereits Termine wie
	// „Abschiednahme"/„Behördentermin"/„Interne Tätigkeit" kannte. Jetzt pro Terminart einzeln und idempotent,
	// damit fehlende Standard-Terminarten auch
	// nachträglich ergänzt werden, ohne bereits vorhandene (auch deaktivierte oder vom Kunden angepasste) zu berühren.
	private function seed(): void {
		foreach ([
			['TRAUERFEIER_1', '1. Trauerfeier', 90, 30, 30, 'EXTERNAL_APPOINTMENT'],
			['TRAUERFEIER_2', '2. Trauerfeier', 90, 30, 30, 'EXTERNAL_APPOINTMENT'],
			['BEISETZUNG', 'Beisetzung', 60, 30, 30, 'EXTERNAL_APPOINTMENT'],
			['AUFBAHRUNG', 'Aufbahrung', 60, 15, 15, 'EXTERNAL_APPOINTMENT'],
			['UEBERFUEHRUNG', 'Überführung', 60, 15, 15, 'EXTERNAL_APPOINTMENT'],
			['BERATUNG', 'Beratungsgespräch', 90, 15, 15, 'EXTERNAL_APPOINTMENT'],
			// Ergänzt, damit dieselben Schlüssel wie im Terminvorlagenkatalog verwendet werden (Befund #19) —
			// vorher gab es für diese drei nur einen Eintrag im Terminvorlagenkatalog, keinen in der Terminplanung.
			['ABSCHIEDNAHME', 'Abschiednahme', 60, 0, 0, 'EXTERNAL_APPOINTMENT'],
			['BEHOERDENTERMIN', 'Behördentermin', 60, 0, 0, 'EXTERNAL_APPOINTMENT'],
			['INTERNE_TAETIGKEIT', 'Interne Tätigkeit', 60, 0, 0, 'INTERNAL_ACTIVITY'],
		] as $i) {
			if ($this->typeKeyExistsAnyState($i[0])) {
				continue;
			}
			$this->saveType(['key' => $i[0], 'name' => $i[1], 'durationMinutes' => $i[2], 'bufferBeforeMinutes' => $i[3], 'bufferAfterMinutes' => $i[4], 'scheduleKind' => $i[5], 'active' => true, 'sortOrder' => 10]);
		}
	}

	private function typeKeyExistsAnyState(string $key): bool {
		$query = $this->db->getQueryBuilder();
		return (bool)$query->select('id')->from('bestatter_schedule_types')->where($query->expr()->eq('type_key', $query->createNamedParameter($key)))->executeQuery()->fetchOne();
	}
	private function types(bool $active): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('bestatter_schedule_types')->orderBy('sort_order', 'ASC')->addOrderBy('name', 'ASC');
		if ($active) {
			$q->where($q->expr()->eq('active', $q->createNamedParameter(1)));
		}return array_map(fn ($r) => $this->mapType($r), $q->executeQuery()->fetchAllAssociative());
	}
	private function resources(bool $active): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('bestatter_resources')->orderBy('sort_order', 'ASC')->addOrderBy('name', 'ASC');
		if ($active) {
			$q->where($q->expr()->eq('active', $q->createNamedParameter(1)));
		}return array_map(fn ($r) => $this->mapResource($r), $q->executeQuery()->fetchAllAssociative());
	}
	private function type(int $id): array {
		$q = $this->db->getQueryBuilder();
		$r = $q->select('*')->from('bestatter_schedule_types')->where($q->expr()->eq('id', $q->createNamedParameter($id)))->executeQuery()->fetchAssociative();
		if (!$r) {
			throw new \InvalidArgumentException('Terminart wurde nicht gefunden.');
		}return$this->mapType($r);
	}
	private function resource(int $id): array {
		$q = $this->db->getQueryBuilder();
		$r = $q->select('*')->from('bestatter_resources')->where($q->expr()->eq('id', $q->createNamedParameter($id)))->executeQuery()->fetchAssociative();
		if (!$r) {
			throw new \InvalidArgumentException('Ressource wurde nicht gefunden.');
		}return$this->mapResource($r);
	}
	private function typeByKeyAnyState(string $key): ?array {
		foreach ($this->types(false) as $type) {
			if ($type['key'] === $key) {
				return $type;
			}
		}
		return null;
	}

	/** @return list<string> */
	private function previousResourceKeys(int $recordId): array {
		$q = $this->db->getQueryBuilder();
		$payload = $q->select('payload')->from('bestatter_records')->where($q->expr()->eq('id', $q->createNamedParameter($recordId)))->executeQuery()->fetchOne();
		$data = json_decode((string)($payload ?: '{}'), true);
		return SchedulingRules::normalizeKeys(is_array($data) ? ($data['resourceKeys'] ?? []) : []);
	}

	private function typeByKey(string $key):?array {
		foreach ($this->types(true) as $type) {
			if ($type['key'] === $key) {
				return$type;
			}
		}return null;
	}
	/**
	 * 0.92.0: Niederlassung des Falls (Schlüssel und Name); ohne Fall oder ohne Niederlassung leer.
	 *
	 * @return array{0:string,1:string}
	 */
	private function caseBranch(int $caseId): array {
		if ($caseId <= 0) {
			return ['', ''];
		}
		try {
			$q = $this->db->getQueryBuilder();
			$branchKey = trim((string)$q->select('branch')->from('bestatter_cases')->where($q->expr()->eq('id', $q->createNamedParameter($caseId)))->executeQuery()->fetchOne());
		} catch (\Throwable) {
			return ['', ''];
		}
		if ($branchKey === '') {
			return ['', ''];
		}
		foreach ($this->branches() as $branch) {
			if (strtoupper($branch['key']) === strtoupper($branchKey)) {
				return [$branch['key'], $branch['name']];
			}
		}
		return [$branchKey, $branchKey];
	}

	private function mapType(array $r):array {
		return['id' => (int)$r['id'],'key' => $r['type_key'],'name' => $r['name'],'scheduleKind' => $r['schedule_kind'],'durationMinutes' => (int)$r['default_duration'],'bufferBeforeMinutes' => (int)$r['buffer_before'],'bufferAfterMinutes' => (int)$r['buffer_after'],'requiredStaff' => (int)($r['required_staff'] ?? 0),'requiredResources' => $this->storedRequirements($r),'requiredVehicles' => (int)($r['required_vehicles'] ?? 0),'requiredRooms' => (int)($r['required_rooms'] ?? 0),'requiredChapels' => (int)($r['required_chapels'] ?? 0),'requiredEquipment' => (int)($r['required_equipment'] ?? 0),'defaultResourceKeys' => json_decode((string)($r['default_resource_keys'] ?? '[]'), true)?:[],'allowedResourceKeys' => json_decode((string)($r['allowed_resource_keys'] ?? '[]'), true)?:[],'active' => (bool)$r['active'],'sortOrder' => (int)$r['sort_order']];
	}
	/**
	 * 0.92.0: Pflichtbedarf aus required_resources; ältere Zeilen (vor der Migration) aus den Einzelspalten.
	 *
	 * @return array<string,int>
	 */
	private function storedRequirements(array $r): array {
		$decoded = json_decode((string)($r['required_resources'] ?? ''), true);
		if (is_array($decoded)) {
			return SchedulingRules::requiredResources(['requiredResources' => $decoded]);
		}
		$legacy = [];
		foreach (self::LEGACY_REQUIREMENT_COLUMNS as $resourceType => $column) {
			if ((int)($r[$column] ?? 0) > 0) {
				$legacy[$resourceType] = (int)$r[$column];
			}
		}
		return $legacy;
	}

	private function mapResource(array $r):array {
		$branchKeys = json_decode((string)($r['branch_keys'] ?? '[]'), true);
		return [
			'id' => (int)$r['id'], 'key' => $r['resource_key'], 'name' => $r['name'], 'resourceType' => $r['resource_type'],
			'identifier' => (string)($r['identifier'] ?? ''), 'description' => (string)($r['description'] ?? ''),
			'branchKeys' => is_array($branchKeys) ? array_values(array_map('strval', $branchKeys)) : [],
			'linkedUid' => (string)($r['linked_uid'] ?? ''), 'conflictRelevant' => (bool)$r['conflict_relevant'], 'active' => (bool)$r['active'], 'sortOrder' => (int)$r['sort_order'],
			'updatedAt' => (string)($r['updated_at'] ?? ''), 'updatedBy' => (string)($r['updated_by'] ?? ''),
		];
	}

	/** @return array<string,int> Mitarbeitende (STAFF) und Pflichtbedarf je Ressourcentyp */
	private function requirements(array $type): array {
		return ['STAFF' => (int)($type['requiredStaff'] ?? 0), ...SchedulingRules::requiredResources($type)];
	}
	private function assertRequirements(array $data, ?array $type): void {
		if ($type === null) {
			return;
		}
		$requirements = $this->requirements($type);
		$missing = [];
		$staff = array_values(array_unique(array_filter(array_map('strval', (array)($data['assigneeUids'] ?? [])))));
		if (count($staff) < $requirements['STAFF']) {
			$missing[] = ($requirements['STAFF'] - count($staff)) . ' Mitarbeiter/in';
		}
		$selected = array_fill_keys((array)($data['resourceKeys'] ?? []), true);
		$counts = [];
		foreach ($this->resources(true) as $resource) {
			if (isset($selected[$resource['key']]) && SchedulingRules::isAssignable($resource)) {
				$counts[$resource['resourceType']] = ($counts[$resource['resourceType']] ?? 0) + 1;
			}
		}
		$labels = SchedulingRules::typeLabels($this->resourceTypes());
		foreach (SchedulingRules::requiredResources($type) as $resourceType => $count) {
			if (($counts[$resourceType] ?? 0) < $count) {
				$missing[] = ($count - ($counts[$resourceType] ?? 0)) . ' × ' . ($labels[$resourceType] ?? $resourceType);
			}
		}
		if ($missing !== []) {
			throw new \InvalidArgumentException('Für die Terminart fehlen noch verpflichtende Ressourcen: ' . implode(', ', $missing) . '.');
		}
	}
	private function changes(array $before, array $after): array {
		$beforeData = (array)($before['data'] ?? []);
		$afterData = (array)($after['data'] ?? []);
		$fields = ['title' => 'Bezeichnung','date' => 'Beginn','status' => 'Status'];
		$dataFields = ['location' => 'Ort','durationMinutes' => 'Dauer','bufferBeforeMinutes' => 'Puffer davor','bufferAfterMinutes' => 'Puffer danach','assigneeUids' => 'Mitarbeitende','resourceKeys' => 'Ressourcen','externalParticipants' => 'Externe Beteiligte'];
		$result = [];
		foreach ($fields as $key => $label) {
			$old = $before[$key] ?? '';
			$new = $after[$key] ?? '';
			if ($old != $new) {
				$result[] = ['field' => $key,'label' => $label,'before' => $old,'after' => $new];
			}
		}
		foreach ($dataFields as $key => $label) {
			$old = $beforeData[$key] ?? '';
			$new = $afterData[$key] ?? '';
			if (is_array($old)) {
				$old = implode(', ', $old);
			}if (is_array($new)) {
				$new = implode(', ', $new);
			}if ((string)$old !== (string)$new) {
				$result[] = ['field' => $key,'label' => $label,'before' => $old,'after' => $new];
			}
		}
		return$result;
	}

	// ---------------------------------------------------------------------------------------------- Einrichtungspaket

	/**
	 * 0.92.0: Terminplanung für das Einrichtungspaket – alle Terminarten (mit Pflichtbedarf und Zuordnung „Ressourcen je
	 * Terminart“) und alle Ressourcen außer Mitarbeitenden. Ohne verknüpfte Personen und ohne Änderungsvermerk.
	 */
	public function exportExchange(): string {
		$this->seed();
		$pick = static fn (array $row, array $fields): array => array_intersect_key($row, array_flip($fields));
		$resources = array_values(array_filter($this->resources(false), static fn (array $resource): bool => $resource['resourceType'] !== 'STAFF'));
		return json_encode([
			'format' => self::EXCHANGE_FORMAT, 'formatVersion' => 1, 'appVersion' => Application::VERSION,
			'scheduleTypes' => array_map(static fn (array $type): array => $pick($type, self::EXCHANGE_TYPE_FIELDS), $this->types(false)),
			'resources' => array_map(static fn (array $resource): array => $pick($resource, self::EXCHANGE_RESOURCE_FIELDS), $resources),
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	/**
	 * Liest und normalisiert die Terminplanung eines Pakets (Schlüssel groß, Listen bereinigt). Prüft Aufbau und Pflichtfelder,
	 * nicht die Verweise – die meldet die Vorschau.
	 *
	 * @return array{scheduleTypes:list<array>,resources:list<array>,assignment:'replace'|'add'}
	 */
	public static function parseExchange(string $content): array {
		$data = json_decode($content, true);
		if (!is_array($data) || ($data['format'] ?? '') !== self::EXCHANGE_FORMAT || !is_array($data['scheduleTypes'] ?? []) || !is_array($data['resources'] ?? [])) {
			throw new \InvalidArgumentException('Keine Terminplanung im erwarteten Format.');
		}
		if ((int)($data['formatVersion'] ?? 1) > 1) {
			throw new \InvalidArgumentException('Die Terminplanung stammt aus einer neueren Version der App.');
		}
		$key = static function (mixed $value, string $label): string {
			$value = strtoupper(trim((string)$value));
			if (!preg_match('/^[A-Z0-9_-]{1,64}$/', $value)) {
				throw new \InvalidArgumentException($label . ': Der technische Schlüssel „' . $value . '“ ist ungültig.');
			}
			return $value;
		};
		$resources = [];
		foreach ((array)($data['resources'] ?? []) as $row) {
			$row = is_array($row) ? $row : [];
			$resource = [
				'key' => $key($row['key'] ?? '', 'Ressource'), 'resourceType' => strtoupper(trim((string)($row['resourceType'] ?? ''))), 'name' => trim((string)($row['name'] ?? '')),
				'identifier' => trim((string)($row['identifier'] ?? '')), 'description' => trim((string)($row['description'] ?? '')),
				'branchKeys' => array_values(array_unique(array_filter(array_map(static fn ($branch): string => strtoupper(trim((string)$branch)), (array)($row['branchKeys'] ?? [])), static fn (string $branch): bool => $branch !== '' && $branch !== 'ALL'))),
				'conflictRelevant' => filter_var($row['conflictRelevant'] ?? true, FILTER_VALIDATE_BOOLEAN), 'active' => filter_var($row['active'] ?? true, FILTER_VALIDATE_BOOLEAN),
				'sortOrder' => max(0, (int)($row['sortOrder'] ?? 0)),
			];
			if ($resource['name'] === '' || $resource['resourceType'] === '' || $resource['resourceType'] === 'STAFF') {
				throw new \InvalidArgumentException('Ressource ' . $resource['key'] . ': Bezeichnung und Ressourcentyp sind erforderlich (Mitarbeitende sind keine Ressource).');
			}
			if (isset($resources[$resource['key']])) {
				throw new \InvalidArgumentException('Die Ressource ' . $resource['key'] . ' steht doppelt in der Terminplanung.');
			}
			$resources[$resource['key']] = $resource;
		}
		$types = [];
		foreach ((array)($data['scheduleTypes'] ?? []) as $row) {
			$row = is_array($row) ? $row : [];
			// Nur mitgelieferte Felder zählen; fehlende bleiben beim Einspielen wie in der Installation (z. B. nur die Zuordnung).
			$fields = array_values(array_diff(array_keys(array_intersect_key($row, array_flip(self::EXCHANGE_TYPE_FIELDS))), ['key']));
			if (array_intersect(array_keys($row), ['requiredChapels', 'requiredRooms', 'requiredVehicles', 'requiredEquipment']) !== [] && !in_array('requiredResources', $fields, true)) {
				$fields[] = 'requiredResources';
			}
			$all = [
				'name' => trim((string)($row['name'] ?? '')),
				'scheduleKind' => strtoupper(trim((string)($row['scheduleKind'] ?? 'EXTERNAL_APPOINTMENT'))),
				'durationMinutes' => max(15, (int)($row['durationMinutes'] ?? 60)), 'bufferBeforeMinutes' => max(0, (int)($row['bufferBeforeMinutes'] ?? 0)),
				'bufferAfterMinutes' => max(0, (int)($row['bufferAfterMinutes'] ?? 0)), 'requiredStaff' => max(0, (int)($row['requiredStaff'] ?? 0)),
				'requiredResources' => SchedulingRules::requiredResources($row),
				'allowedResourceKeys' => SchedulingRules::normalizeKeys($row['allowedResourceKeys'] ?? []), 'defaultResourceKeys' => SchedulingRules::normalizeKeys($row['defaultResourceKeys'] ?? []),
				'active' => filter_var($row['active'] ?? true, FILTER_VALIDATE_BOOLEAN), 'sortOrder' => max(0, (int)($row['sortOrder'] ?? 0)),
			];
			$type = ['key' => $key($row['key'] ?? '', 'Terminart')] + array_intersect_key($all, array_flip($fields)) + ['fields' => $fields];
			if ((in_array('name', $fields, true) && $all['name'] === '') || (in_array('scheduleKind', $fields, true) && !in_array($all['scheduleKind'], self::KINDS, true))) {
				throw new \InvalidArgumentException('Terminart ' . $type['key'] . ': Bezeichnung und Einordnung (intern/extern) sind erforderlich.');
			}
			if (isset($types[$type['key']])) {
				throw new \InvalidArgumentException('Die Terminart ' . $type['key'] . ' steht doppelt in der Terminplanung.');
			}
			$types[$type['key']] = $type;
		}
		if ($types === [] && $resources === []) {
			throw new \InvalidArgumentException('Die Terminplanung enthält weder Terminarten noch Ressourcen.');
		}
		$assignment = strtolower(trim((string)($data['assignment'] ?? 'replace')));
		if (!in_array($assignment, ['replace', 'add'], true)) {
			throw new \InvalidArgumentException('Die Terminplanung nennt eine unbekannte Art der Zuordnung („' . $assignment . '“; möglich: replace, add).');
		}
		return ['scheduleTypes' => array_values($types), 'resources' => array_values($resources), 'assignment' => $assignment];
	}

	/**
	 * Terminart nach dem Einspielen: vorhandene Werte, darüber die mitgelieferten Felder. „add“ ergänzt die Zuordnung,
	 * statt sie zu ersetzen (Standardpaket mit Beispielressourcen). Ohne vorhandene Terminart und ohne Bezeichnung: null.
	 *
	 * @param list<string> $assignable Ressourcen, auf die eine Zuordnung zeigen darf
	 */
	private static function mergedType(?array $existing, array $type, string $assignment, array $assignable): ?array {
		if ($existing === null && !isset($type['name'])) {
			return null;
		}
		$merged = array_merge($existing ?? [], array_diff_key($type, ['fields' => true]));
		if (in_array('requiredResources', $type['fields'], true)) {
			// Der mitgelieferte Pflichtbedarf gilt vollständig – auch „keiner“; die früheren Einzelfelder dürfen ihn nicht überdecken.
			$merged = array_diff_key($merged, array_flip(SchedulingRules::REQUIREMENT_FIELDS));
		}
		foreach (['allowedResourceKeys', 'defaultResourceKeys'] as $field) {
			$incoming = array_values(array_intersect((array)($type[$field] ?? []), $assignable));
			$merged[$field] = $assignment === 'add' && $existing !== null ? SchedulingRules::normalizeKeys([...(array)($existing[$field] ?? []), ...$incoming]) : (in_array($field, $type['fields'], true) ? $incoming : (array)($existing[$field] ?? []));
		}
		return $merged;
	}

	/**
	 * Vorschau: Stand je Terminart und Ressource sowie Verweise ohne Ziel nach dem Einspielen.
	 *
	 * @param list<string> $extraResourceTypes Ressourcentypen, die das Paket in der Werteliste mitbringt
	 * @param list<string> $extraBranchKeys Niederlassungen, die das Paket mitbringt
	 * @return array{types:array{new:int,changed:int,same:int},resources:array{new:int,changed:int,same:int},warnings:list<string>}
	 */
	public function exchangePreview(array $parsed, array $extraResourceTypes = [], array $extraBranchKeys = []): array {
		$this->seed();
		$installedTypes = array_column($this->types(false), null, 'key');
		$installedResources = array_column($this->resources(false), null, 'key');
		$resourceTypes = array_unique([...array_column($this->resourceTypes(), 'key'), ...array_map('strtoupper', $extraResourceTypes)]);
		$branches = array_unique([...array_map('strtoupper', array_column($this->branches(), 'key')), ...array_map('strtoupper', $extraBranchKeys)]);
		$count = static function (array $items, array $installed, array $fields): array {
			$result = ['new' => 0, 'changed' => 0, 'same' => 0];
			foreach ($items as $item) {
				$existing = $installed[$item['key']] ?? null;
				$result[$existing === null ? 'new' : (self::sameExchangeItem($existing, $item, $fields) ? 'same' : 'changed')]++;
			}
			return $result;
		};
		$name = static fn (array $type): string => (string)($type['name'] ?? $installedTypes[$type['key']]['name'] ?? $type['key']);
		$warnings = [];
		$unknownTypes = $unknownBranches = $unknownResources = $staff = [];
		$resourceKeys = [...array_keys(array_filter($installedResources, static fn (array $resource): bool => $resource['resourceType'] !== 'STAFF')), ...array_column($parsed['resources'], 'key')];
		foreach ($parsed['resources'] as $resource) {
			if (!in_array($resource['resourceType'], $resourceTypes, true)) {
				$unknownTypes[] = $resource['resourceType'] . ' (' . $resource['name'] . ')';
			}
			foreach ($resource['branchKeys'] as $branch) {
				if (!in_array($branch, $branches, true)) {
					$unknownBranches[] = $branch . ' (' . $resource['name'] . ')';
				}
			}
		}
		foreach ($parsed['scheduleTypes'] as $type) {
			if (!isset($installedTypes[$type['key']]) && !isset($type['name'])) {
				$warnings[] = 'Die Terminart ' . $type['key'] . ' gibt es in dieser Installation nicht, und das Paket nennt keine Bezeichnung – sie wird übersprungen.';
			}
			foreach (array_keys($type['requiredResources'] ?? []) as $resourceType) {
				if (!in_array($resourceType, $resourceTypes, true)) {
					$unknownTypes[] = $resourceType . ' (Pflichtbedarf „' . $name($type) . '“)';
				}
			}
			foreach ([...($type['allowedResourceKeys'] ?? []), ...($type['defaultResourceKeys'] ?? [])] as $resourceKey) {
				if (!in_array($resourceKey, $resourceKeys, true)) {
					if (isset($installedResources[$resourceKey])) {
						$staff[] = $resourceKey . ' („' . $name($type) . '“)';
					} else {
						$unknownResources[] = $resourceKey . ' („' . $name($type) . '“)';
					}
				}
			}
		}
		$list = static fn (array $values): string => implode(', ', array_slice(array_values(array_unique($values)), 0, 10)) . (count(array_unique($values)) > 10 ? ' …' : '');
		if ($unknownTypes !== []) {
			$warnings[] = 'Ressourcentyp nicht bekannt (vorher in der Werteliste „Ressourcentypen“ anlegen oder mit dem Paket einspielen): ' . $list($unknownTypes) . '. Diese Einträge werden übersprungen.';
		}
		if ($unknownBranches !== []) {
			$warnings[] = 'Niederlassung nicht vorhanden (die Ressource gilt dann nur für die übrigen bzw. für alle): ' . $list($unknownBranches) . '.';
		}
		if ($unknownResources !== [] || $staff !== []) {
			$warnings[] = 'Zuordnung ohne Ziel (wird weggelassen): ' . $list([...$unknownResources, ...$staff]) . '.';
		}
		// Verglichen wird mit dem Stand nach dem Einspielen: unbekannte Niederlassungen entfallen dabei.
		$comparable = array_map(static fn (array $resource): array => ['branchKeys' => array_values(array_filter($resource['branchKeys'], static fn (string $branch): bool => in_array($branch, $branches, true)))] + $resource, $parsed['resources']);
		$assignable = array_values(array_diff($resourceKeys, array_keys(array_filter($installedResources, static fn (array $resource): bool => $resource['resourceType'] === 'STAFF'))));
		$comparableTypes = array_map(static fn (array $type): array => self::mergedType($installedTypes[$type['key']] ?? null, $type, $parsed['assignment'] ?? 'replace', $assignable) ?? $type, $parsed['scheduleTypes']);
		return ['types' => $count($comparableTypes, $installedTypes, self::EXCHANGE_TYPE_FIELDS), 'resources' => $count($comparable, $installedResources, self::EXCHANGE_RESOURCE_FIELDS), 'warnings' => $warnings];
	}

	/**
	 * Einspielen: Ressourcen zuerst (die Zuordnung der Terminarten verweist auf sie), dann Terminarten. Gleiche Schlüssel werden
	 * aktualisiert, eigene Einträge der Installation bleiben unverändert, gelöscht wird nichts. Einträge mit unbekanntem
	 * Ressourcentyp werden übersprungen, unbekannte Niederlassungen und Zuordnungen ohne Ziel weggelassen.
	 *
	 * @return array{typesCreated:int,typesUpdated:int,resourcesCreated:int,resourcesUpdated:int,skipped:list<string>}
	 */
	public function importExchange(string $content): array {
		$parsed = self::parseExchange($content);
		$this->seed();
		$result = ['typesCreated' => 0, 'typesUpdated' => 0, 'resourcesCreated' => 0, 'resourcesUpdated' => 0, 'skipped' => []];
		$branchKeys = array_column($this->branches(), 'key');
		$knownBranches = array_map('strtoupper', $branchKeys);
		$resourceTypes = array_column($this->resourceTypes(), 'key');
		$installedResources = array_column($this->resources(false), null, 'key');
		foreach ($parsed['resources'] as $resource) {
			if (!in_array($resource['resourceType'], $resourceTypes, true)) {
				$result['skipped'][] = 'Ressource „' . $resource['name'] . '“: Ressourcentyp ' . $resource['resourceType'] . ' nicht bekannt';
				continue;
			}
			$existing = $installedResources[$resource['key']] ?? null;
			if ($existing !== null && $existing['resourceType'] === 'STAFF') {
				$result['skipped'][] = 'Ressource „' . $resource['name'] . '“: Schlüssel ist als Mitarbeiter vergeben';
				continue;
			}
			$resource['branchKeys'] = array_values(array_filter($resource['branchKeys'], static fn (string $branch): bool => in_array($branch, $knownBranches, true)));
			$this->saveResource($resource, (int)($existing['id'] ?? 0));
			$existing === null ? $result['resourcesCreated']++ : $result['resourcesUpdated']++;
		}
		$assignable = array_column(array_filter($this->resources(false), static fn (array $resource): bool => $resource['resourceType'] !== 'STAFF'), 'key');
		$installedTypes = array_column($this->types(false), null, 'key');
		foreach ($parsed['scheduleTypes'] as $type) {
			$existing = $installedTypes[$type['key']] ?? null;
			$label = (string)($type['name'] ?? $existing['name'] ?? $type['key']);
			if (array_diff(array_keys($type['requiredResources'] ?? []), $resourceTypes) !== []) {
				$result['skipped'][] = 'Terminart „' . $label . '“: Pflichtbedarf mit unbekanntem Ressourcentyp';
				continue;
			}
			$merged = self::mergedType($existing, $type, $parsed['assignment'], $assignable);
			if ($merged === null) {
				$result['skipped'][] = 'Terminart ' . $type['key'] . ': in dieser Installation nicht vorhanden und ohne Bezeichnung im Paket';
				continue;
			}
			$this->saveType($merged, (int)($existing['id'] ?? 0));
			$existing === null ? $result['typesCreated']++ : $result['typesUpdated']++;
		}
		return $result;
	}

	/** Gleicher Inhalt einer Terminart bzw. Ressource (nur die übertragenen Felder, Listen ohne Reihenfolge). */
	private static function sameExchangeItem(array $existing, array $incoming, array $fields): bool {
		foreach ($fields as $field) {
			$a = $existing[$field] ?? null;
			$b = $incoming[$field] ?? null;
			if (is_array($a) || is_array($b)) {
				$a = (array)$a;
				$b = (array)$b;
				if (array_is_list($a) && array_is_list($b)) {
					$a = array_map('strtoupper', array_map('strval', $a));
					$b = array_map('strtoupper', array_map('strval', $b));
					sort($a);
					sort($b);
				} else {
					ksort($a);
					ksort($b);
				}
			}
			if (is_bool($a) || is_bool($b)) {
				$a = (bool)$a;
				$b = (bool)$b;
			}
			if ($a != $b) {
				return false;
			}
		}
		return true;
	}
}
