<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\IManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Server;

class GroupwareService {
	private const SYNC_PAGE_SIZE = 500;
	private const TIMEZONE = 'Europe/Berlin';

	public function __construct(
		private IManager $calendarManager,
		private IUserSession $userSession,
		private RecordService $records,
		private CaseService $cases,
		private TeamService $team,
		private IURLGenerator $urlGenerator,
	) {}

	public function createTask(string $title, string $due = '', string $status = 'OFFEN', string $data = '{}', int $caseId = 0): array {
		return $this->createRecord('task', $title, $due, $status, $data, $caseId);
	}

	public function createEvent(string $title, string $start = '', string $status = 'OFFEN', string $data = '{}', int $caseId = 0): array {
		if ($start === '') {
			throw new \InvalidArgumentException('Für einen Termin ist ein Beginn erforderlich.');
		}
		$meta = json_decode($data, true, 512, JSON_THROW_ON_ERROR) ?: [];
		$meta = $this->enrichScheduleMeta($meta);
		return $this->createRecord('schedule', $title, $start, $status, json_encode($meta, JSON_THROW_ON_ERROR), $caseId);
	}

	public function update(array $record): array {
		if (!in_array($record['type'] ?? '', ['task', 'schedule'], true)) {
			return $record;
		}

		$meta = is_array($record['data'] ?? null) ? $record['data'] : [];
		$previousCopies = (array)($meta['nextcloudCopies'] ?? []);
		$uid = (string)($meta['nextcloud']['uid'] ?? '');
		if ($uid === '') {
			$uid = $this->uuid();
		}
		$caseId = (int)($record['caseId'] ?? 0);
		$caseNumber = $this->caseNumber($caseId, $record);
		$meta['caseNumber'] = $caseNumber;
		$meta['casePersonName'] = $this->casePersonName($caseId, $record);
		$meta['nextcloud']['uid'] = $uid;
		$meta['nextcloud']['type'] = (string)$record['type'];
		if (($record['type'] ?? '') === 'task') {
			$meta = $this->enrichTaskMeta($meta, $caseId, $uid, (int)($record['id'] ?? 0));
		} elseif (($record['type'] ?? '') === 'schedule') {
			$meta = $this->enrichScheduleMeta($meta);
		}

		try {
			$ics = $this->buildComponent(
				(string)$record['type'],
				$uid,
				(string)$record['title'],
				(string)($record['date'] ?? ''),
				(string)($record['status'] ?? 'OFFEN'),
				$meta,
				$caseId,
				$caseNumber,
			);
			$target = $this->writeComponent($uid, $ics, $meta);
			$meta['nextcloud'] = array_replace($meta['nextcloud'], $target, [
				'stored' => true,
				'syncHash' => hash('sha256', $ics),
			]);
			unset($meta['nextcloud']['syncError'], $meta['nextcloud']['remoteDeletedAt']);
			if (($record['type'] ?? '') === 'schedule') $meta['nextcloudCopies'] = $this->writeScheduleCopies($uid, $ics, $meta, $previousCopies);
		} catch (\Throwable $error) {
			$meta['nextcloud']['stored'] = false;
			$meta['nextcloud']['syncError'] = $error->getMessage();
		}

		return $this->records->update(
			(int)$record['id'],
			(string)$record['title'],
			(string)($record['date'] ?? ''),
			(string)($record['status'] ?? 'OFFEN'),
			json_encode($meta, JSON_THROW_ON_ERROR),
			$caseId,
		);
	}

	public function synchronize(string $type): array {
		if (!in_array($type, ['task', 'schedule'], true)) {
			throw new \InvalidArgumentException('Synchronisation ist nur für Aufgaben und Termine verfügbar.');
		}

		$component = $type === 'task' ? 'VTODO' : 'VEVENT';
		$ownerUid = $this->currentUserId();
		$imported = 0;
		$updated = 0;
		$unchanged = 0;
		$remoteDeleted = 0;
		$ignored = 0;
		$removedLocalMirrors = 0;
		$errors = [];

		foreach ($this->calendarManager->getCalendarsForPrincipal($this->principal()) as $calendar) {
			if ($calendar->isDeleted()) {
				continue;
			}
			if ($this->excludedCalendarName((string)($calendar->getDisplayName() ?? ''))) {
				$removedLocalMirrors += $this->records->removeImportedCalendarRecords($type, (string)$calendar->getKey(), $ownerUid);
				continue;
			}
			try {
				$offset = 0;
				do {
					$rows = $calendar->search('', [], ['types' => [$component]], self::SYNC_PAGE_SIZE, $offset);
					foreach ($rows as $row) {
						$calendarData = $this->calendarData($row);
						if ($calendarData === '') {
							continue;
						}
						if (!$this->isManagedComponent($calendarData, $type, $ownerUid)) {
							$ignored++;
							continue;
						}
						$action = $this->importCalendarData($calendarData, $type, (string)$calendar->getKey(), $row, $ownerUid, true);
						if ($action === 'imported') {
							$imported++;
						} elseif ($action === 'updated') {
							$updated++;
						} else {
							$unchanged++;
						}
					}
					$offset += count($rows);
				} while (count($rows) === self::SYNC_PAGE_SIZE);
			} catch (\Throwable $error) {
				$errors[] = ($calendar->getDisplayName() ?? $calendar->getKey()) . ': ' . $error->getMessage();
			}
		}
		// Fehlende Treffer einer Kalendersuche sind kein belastbarer Löschbeweis:
		// Kalender-Backends, Berechtigungen und Suchindizes können vorübergehend
		// unvollständige Ergebnisse liefern. Remote-Löschungen werden ausschließlich
		// über CalendarObjectDeletedEvent in importCalendarObject() verarbeitet.

		return [
			'type' => $type,
			'imported' => $imported,
			'updated' => $updated,
			'unchanged' => $unchanged,
			'remoteDeleted' => $remoteDeleted,
			'ignored' => $ignored,
			'removedLocalMirrors' => $removedLocalMirrors,
			'errors' => $errors,
		];
	}

	public function importCalendarObject(array $objectData, int $calendarId, array $calendarData, bool $deleted = false): void {
		$raw = $this->calendarData($objectData);
		$component = strtoupper((string)($objectData['componenttype'] ?? $objectData['componentType'] ?? ''));
		$type = $component === 'VTODO' || ($component === '' && str_contains($raw, 'BEGIN:VTODO')) ? 'task'
			: ($component === 'VEVENT' || ($component === '' && str_contains($raw, 'BEGIN:VEVENT')) ? 'schedule' : '');
		if ($type === '') {
			return;
		}

		$ownerUid = $this->ownerFromCalendarData($calendarData);
		if ($ownerUid === '' || !$this->team->hasBestatterRole($ownerUid)) {
			return;
		}
		if ($this->excludedCalendarName($this->calendarDataName($calendarData))) {
			$this->records->removeImportedCalendarRecords($type, (string)$calendarId, $ownerUid);
			return;
		}
		$uid = (string)($objectData['uid'] ?? ($raw !== '' ? $this->property($raw, 'UID') : ''));
		if ($uid === '') {
			return;
		}
		if ($deleted) {
			if ($this->records->findByNextcloudUid($type, $uid, $ownerUid) !== null) {
				$this->records->markRemoteDeleted($type, $uid, $ownerUid);
			}
			return;
		}
		if ($raw !== '' && $this->isManagedComponent($raw, $type, $ownerUid)) {
			$this->importCalendarData($raw, $type, (string)$calendarId, $objectData, $ownerUid);
		}
	}

	private function isManagedComponent(string $ics, string $type, string $ownerUid): bool {
		if ($this->hasBestatterMarker($ics, $type)) {
			return true;
		}
		$uid = $this->property($ics, 'UID');
		return $uid !== '' && $this->records->findByNextcloudUid($type, $uid, $ownerUid) !== null;
	}

	private function hasBestatterMarker(string $ics, string $type): bool {
		$expectedType = $type === 'task' ? 'TASK' : 'SCHEDULE';
		if (strtoupper($this->unescape($this->property($ics, 'X-BESTATTER-RECORD-TYPE'))) === $expectedType) {
			return true;
		}
		if ($this->property($ics, 'X-BESTATTER-CASE-ID') !== '') {
			return true;
		}
		$categories = array_map('trim', explode(',', strtoupper($this->unescape($this->property($ics, 'CATEGORIES')))));
		return in_array('BESTATTER', $categories, true);
	}

	private function excludedCalendarName(string $name): bool {
		$name = str_replace(['_', '-'], ' ', mb_strtolower(trim($name)));
		return $name !== '' && preg_match('/(?:geburtstag|geburtstage|birthday|birthdays|kontaktgeburtstag|contact birthdays|anniversar)/u', $name) === 1;
	}

	private function calendarDataName(array $calendarData): string {
		return (string)($calendarData['displayname']
			?? $calendarData['displayName']
			?? $calendarData['{DAV:}displayname']
			?? $calendarData['uri']
			?? $calendarData['calendaruri']
			?? '');
	}

	private function createRecord(string $type, string $title, string $date, string $status, string $data, int $caseId): array {
		$meta = json_decode($data, true, 512, JSON_THROW_ON_ERROR) ?: [];
		$uid = $this->uuid();
		$caseNumber = $this->caseNumber($caseId);
		$meta['caseNumber'] = $caseNumber;
		$meta['casePersonName'] = $this->casePersonName($caseId);
		$meta['nextcloud'] = [
			'type' => $type,
			'uid' => $uid,
			'ownerUid' => $this->currentUserId(),
			'stored' => false,
		];
		if ($type === 'task') {
			$meta = $this->enrichTaskMeta($meta, $caseId, $uid);
		} elseif ($type === 'schedule') {
			$meta = $this->enrichScheduleMeta($meta);
		}
		$record = $this->records->create($type, $title, $date, $status, json_encode($meta, JSON_THROW_ON_ERROR), $caseId);
		if ($type === 'task' && $caseId > 0) {
			$meta['workflowUrl'] = $this->workflowUrl($caseId, $uid, (int)$record['id']);
		}

		try {
			$ics = $this->buildComponent($type, $uid, $title, $date, $status, $meta, $caseId, $caseNumber);
			$target = $this->writeNewComponent($uid . '.ics', $ics);
			$meta['nextcloud'] = array_replace($meta['nextcloud'], $target, [
				'stored' => true,
				'syncHash' => hash('sha256', $ics),
			]);
			unset($meta['nextcloud']['syncError']);
			if ($type === 'schedule') $meta['nextcloudCopies'] = $this->writeScheduleCopies($uid, $ics, $meta);
		} catch (\Throwable $error) {
			$meta['nextcloud']['syncError'] = $error->getMessage();
		}

		return $this->records->update(
			$record['id'],
			$title,
			$date,
			$status,
			json_encode($meta, JSON_THROW_ON_ERROR),
			$caseId,
		);
	}

	private function importCalendarData(string $ics, string $type, string $calendarKey, array $row, string $ownerUid, bool $repairRemote = false): string {
		$parsed = $this->parseComponent($ics, $type);
		if ($parsed['uid'] === '' || $parsed['title'] === '') {
			return 'unchanged';
		}

		$existing = $this->records->findByNextcloudUid($type, $parsed['uid'], $ownerUid);
		$case = $this->resolveCase($parsed['caseId'], $parsed['caseNumber']);
		$caseId = $case !== null ? (int)$case['id'] : (int)($existing['caseId'] ?? 0);
		$caseNumber = $case !== null ? (string)$case['caseNumber'] : (string)($existing['caseNumber'] ?? $parsed['caseNumber']);
		$syncHash = hash('sha256', $ics);
		$casePersonName = $case !== null ? trim((string)($case['firstName'] ?? '') . ' ' . (string)($case['lastName'] ?? '')) : (string)($existing['data']['casePersonName'] ?? '');
		$remoteTitle = $this->unescape($this->property($ics, 'SUMMARY'));
		$expectedTitle = $this->visibleTitle($parsed['title'], $caseNumber, $casePersonName, $type);
		$needsDisplayContext = $remoteTitle !== $expectedTitle;
		$workflowUrl = (string)($parsed['data']['workflowUrl'] ?? '');
		$needsWorkflowLink = $type === 'task' && $caseId > 0 && ($workflowUrl === '' || !str_contains($workflowUrl, 'taskId='));
		$expectedCategories = $type === 'schedule' ? 'Bestatter,' . (($parsed['data']['scheduleKind'] ?? 'INTERNAL_ACTIVITY') === 'EXTERNAL_APPOINTMENT' ? 'Externer Termin' : 'Interne Tätigkeit') : 'Bestatter';
		$needsCategories = $this->unescape($this->property($ics, 'CATEGORIES')) !== $expectedCategories;
		if ($type === 'task') {
			$assigneeUid = (string)($parsed['data']['assigneeUid'] ?? '');
			$assignee = $assigneeUid !== '' ? $this->team->member($assigneeUid) : $this->team->member($ownerUid);
			$parsed['data']['assigneeUid'] = $assignee['uid'] ?? ($assigneeUid !== '' ? $assigneeUid : $ownerUid);
			$parsed['data']['assigneeName'] = $assignee['displayName'] ?? (string)($parsed['data']['assigneeName'] ?? $parsed['data']['assigneeUid']);
			if ($needsWorkflowLink) {
				$parsed['data']['workflowUrl'] = $this->workflowUrl($caseId, $parsed['uid'], (int)($existing['id'] ?? 0));
			}
		}
		$parsed['data']['caseNumber'] = $caseNumber;
		$parsed['data']['casePersonName'] = $casePersonName;
		$parsed['data']['nextcloud'] = [
			'uid' => $parsed['uid'],
			'ownerUid' => $ownerUid,
			'uri' => (string)($row['uri'] ?? ($parsed['uid'] . '.ics')),
			'calendarKey' => $calendarKey,
			'etag' => (string)($row['etag'] ?? ''),
			'lastModified' => (string)($row['lastmodified'] ?? $row['lastModified'] ?? ''),
			'syncHash' => $syncHash,
			'stored' => true,
		];

		if ($existing !== null
			&& ($existing['data']['nextcloud']['syncHash'] ?? '') === $syncHash
			&& (int)($existing['caseId'] ?? 0) === $caseId
			&& (!$repairRemote || (!$needsDisplayContext && !$needsWorkflowLink && !$needsCategories))) {
			return 'unchanged';
		}

		$record = $this->records->upsertFromNextcloud(
			$type,
			$parsed['uid'],
			$parsed['title'],
			$parsed['date'],
			$parsed['status'],
			$parsed['data'],
			$caseId,
			$ownerUid,
		);
		if ($repairRemote && ($needsDisplayContext || $needsWorkflowLink || $needsCategories)) {
			$this->update($record);
		}
		return $existing === null ? 'imported' : 'updated';
	}

	private function writeComponent(string $uid, string $ics, array $meta): array {
		$preferredKey = (string)($meta['nextcloud']['calendarKey'] ?? '');
		$preferredUri = (string)($meta['nextcloud']['uri'] ?? ($uid . '.ics'));
		$ownerUid = (string)($meta['nextcloud']['ownerUid'] ?? $this->currentUserId());
		$lastError = null;
		$requiredComponent = str_contains($ics, 'BEGIN:VTODO') ? 'VTODO' : 'VEVENT';
		$calendars = $this->calendarManager->getCalendarsForPrincipal($this->principal($ownerUid));
		if ($preferredKey !== '') {
			foreach ($calendars as $calendar) {
				if ((string)$calendar->getKey() === $preferredKey && !$this->supportsCalendarComponent($calendar, $requiredComponent)) {
					$preferredKey = '';
					$preferredUri = $uid . '.ics';
					break;
				}
			}
		}

		foreach ($calendars as $calendar) {
			if (!$this->isWritable($calendar)
				|| !$this->supportsCalendarComponent($calendar, $requiredComponent)
				|| ($preferredKey !== '' && (string)$calendar->getKey() !== $preferredKey)) {
				continue;
			}
			try {
				$matches = $calendar->search('', [], ['uid' => $uid], 1);
				if ($matches === []) {
					$this->createDavObject($calendar, $preferredUri, $ics);
				} else {
					$uri = (string)($matches[0]['uri'] ?? $preferredUri);
					$this->updateDavObject($calendar, $uri, $ics);
					$preferredUri = $uri;
				}
				return $this->targetMetadata($calendar, $uid, $preferredUri);
			} catch (\Throwable $error) {
				$lastError = $error;
			}
		}
		throw $lastError ?? new \RuntimeException('Der zugeordnete Nextcloud-Kalender ist nicht beschreibbar oder nicht mehr verfügbar.');
	}

	private function writeNewComponent(string $name, string $ics): array {
		$lastError = null;
		$requiredComponent = str_contains($ics, 'BEGIN:VTODO') ? 'VTODO' : 'VEVENT';
		foreach ($this->calendarManager->getCalendarsForPrincipal($this->principal()) as $calendar) {
			if (!$this->isWritable($calendar) || !$this->supportsCalendarComponent($calendar, $requiredComponent)) {
				continue;
			}
			try {
				$this->createDavObject($calendar, $name, $ics);
				$uid = $this->property($ics, 'UID');
				return $this->targetMetadata($calendar, $uid, $name);
			} catch (\Throwable $error) {
				$lastError = $error;
			}
		}
		throw $lastError ?? new \RuntimeException('Kein beschreibbarer Nextcloud-Kalender gefunden.');
	}

	private function createDavObject(ICalendar $calendar, string $uri, string $ics): void {
		$class = '\\OCA\\DAV\\CalDAV\\CalDavBackend';
		if (!class_exists($class)) {
			throw new \RuntimeException('Das Nextcloud-DAV-Backend ist nicht verfügbar.');
		}
		$backend = Server::get($class);
		$backend->createCalendarObject((int)$calendar->getKey(), $uri, $ics);
	}

	private function supportsCalendarComponent(ICalendar $calendar, string $component): bool {
		$class = '\\OCA\\DAV\\CalDAV\\CalDavBackend';
		if (!class_exists($class)) {
			return false;
		}
		$backend = Server::get($class);
		$info = $backend->getCalendarById((int)$calendar->getKey());
		$property = $info['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'] ?? null;
		if (is_object($property) && method_exists($property, 'getValue')) {
			return in_array($component, (array)$property->getValue(), true);
		}
		return true;
	}

	private function updateDavObject(ICalendar $calendar, string $uri, string $ics): void {
		$class = '\\OCA\\DAV\\CalDAV\\CalDavBackend';
		if (!class_exists($class)) {
			throw new \RuntimeException('Das Nextcloud-DAV-Backend ist nicht verfügbar.');
		}
		$backend = Server::get($class);
		$backend->updateCalendarObject((int)$calendar->getKey(), $uri, $ics);
	}

	private function targetMetadata(ICalendar $calendar, string $uid, string $fallbackUri): array {
		$matches = $calendar->search('', [], ['uid' => $uid], 1);
		$row = $matches[0] ?? [];
		return [
			'calendarKey' => (string)$calendar->getKey(),
			'uri' => (string)($row['uri'] ?? $fallbackUri),
			'etag' => (string)($row['etag'] ?? ''),
			'lastModified' => (string)($row['lastmodified'] ?? $row['lastModified'] ?? ''),
		];
	}

	private function buildComponent(string $type, string $uid, string $title, string $date, string $status, array $meta, int $caseId, string $caseNumber): string {
		$stamp = gmdate('Ymd\\THis\\Z');
		$component = $type === 'task' ? 'VTODO' : 'VEVENT';
		$visibleTitle = $this->visibleTitle($title, $caseNumber, (string)($meta['casePersonName'] ?? ''), $type);
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Bestatterplattform//Nextcloud//DE\r\nBEGIN:$component\r\n"
			. "UID:$uid\r\nDTSTAMP:$stamp\r\nLAST-MODIFIED:$stamp\r\nSUMMARY:" . $this->escape($visibleTitle) . "\r\n";

		if ($type === 'task') {
			$percent = strtoupper($status) === 'ERLEDIGT' ? 100 : (strtoupper($status) === 'IN_BEARBEITUNG' ? 50 : 0);
			$ics .= 'STATUS:' . $this->taskStatus($status) . "\r\n"
				. 'PERCENT-COMPLETE:' . $percent . "\r\n"
				. 'PRIORITY:' . $this->priority((string)($meta['priority'] ?? 'NORMAL')) . "\r\n";
			if ($date !== '') {
				$ics .= 'DUE:' . $this->icalDate($date) . "\r\n";
			}
			if (strtoupper($status) === 'ERLEDIGT') {
				$ics .= "COMPLETED:$stamp\r\n";
			}
		} else {
			$start = new \DateTimeImmutable($date ?: 'today 00:00', new \DateTimeZone(self::TIMEZONE));
			$durationMinutes = max(15, (int)($meta['durationMinutes'] ?? 60));
			$calendarStatus = strtoupper($status) === 'ABGESAGT' ? 'CANCELLED' : 'CONFIRMED';
			$ics .= 'DTSTART;TZID=' . self::TIMEZONE . ':' . $start->format('Ymd\\THis') . "\r\n"
				. 'DTEND;TZID=' . self::TIMEZONE . ':' . $start->modify('+' . $durationMinutes . ' minutes')->format('Ymd\\THis') . "\r\n"
				. 'STATUS:' . $calendarStatus . "\r\n"
				. 'X-BESTATTER-STATUS:' . $this->escape(strtoupper($status)) . "\r\n"
				. 'X-BESTATTER-DURATION-MINUTES:' . $durationMinutes . "\r\n"
				. 'X-BESTATTER-BUFFER-BEFORE-MINUTES:' . max(0, (int)($meta['bufferBeforeMinutes'] ?? 0)) . "\r\n"
				. 'X-BESTATTER-BUFFER-AFTER-MINUTES:' . max(0, (int)($meta['bufferAfterMinutes'] ?? 0)) . "\r\n"
				. 'X-BESTATTER-RESOURCE-KEYS:' . $this->escape(implode(',', (array)($meta['resourceKeys'] ?? []))) . "\r\n";
			foreach ($meta['assignees'] ?? [] as $assignee) if (($assignee['email'] ?? '') !== '') $ics .= 'ATTENDEE;CN=' . $this->escape((string)$assignee['displayName']) . ':mailto:' . $this->escape((string)$assignee['email']) . "\r\n";
			$ics .= 'X-BESTATTER-ASSIGNEE-UIDS:' . $this->escape(implode(',', (array)($meta['assigneeUids'] ?? []))) . "\r\n";
			$ics .= 'X-BESTATTER-SCHEDULE-KIND:' . $this->escape((string)($meta['scheduleKind'] ?? 'INTERNAL_ACTIVITY')) . "\r\n";
			if (($meta['schedulePresetKey'] ?? '') !== '') $ics .= 'X-BESTATTER-SCHEDULE-PRESET-KEY:' . $this->escape((string)$meta['schedulePresetKey']) . "\r\n";
			if (($meta['appointmentCategory'] ?? '') !== '') $ics .= 'X-BESTATTER-APPOINTMENT-CATEGORY:' . $this->escape((string)$meta['appointmentCategory']) . "\r\n";
			if (($meta['externalParticipants'] ?? '') !== '') $ics .= 'X-BESTATTER-EXTERNAL-PARTICIPANTS:' . $this->escape((string)$meta['externalParticipants']) . "\r\n";
			if (($meta['externalContactIds'] ?? []) !== []) $ics .= 'X-BESTATTER-EXTERNAL-CONTACT-IDS:' . $this->escape(implode(',', array_map('strval', (array)$meta['externalContactIds']))) . "\r\n";
			if (($meta['externalParticipantsAdditional'] ?? '') !== '') $ics .= 'X-BESTATTER-EXTERNAL-PARTICIPANTS-ADDITIONAL:' . $this->escape((string)$meta['externalParticipantsAdditional']) . "\r\n";
			if (($meta['location'] ?? '') !== '') $ics .= 'LOCATION:' . $this->escape((string)$meta['location']) . "\r\n";
		}

		$userDescription = trim((string)($meta['description'] ?? ''));
		$descriptionParts = [];
		if ($caseNumber !== '') {
			$descriptionParts[] = 'Fall: ' . $caseNumber;
		}
		if ($type === 'task' && ($meta['assigneeName'] ?? '') !== '') {
			$descriptionParts[] = 'Zuständig: ' . (string)$meta['assigneeName'];
		}
		if ($type === 'task' && ($meta['workflowUrl'] ?? '') !== '') {
			$descriptionParts[] = 'In Bestatter bearbeiten: ' . (string)$meta['workflowUrl'];
		}
		if ($type === 'schedule' && ($meta['scheduleKind'] ?? 'INTERNAL_ACTIVITY') === 'EXTERNAL_APPOINTMENT') {
			$descriptionParts[] = 'Terminart: ' . (string)($meta['appointmentCategory'] ?? 'Externer Fixtermin');
			if (($meta['externalParticipants'] ?? '') !== '') $descriptionParts[] = 'Externe Beteiligte: ' . (string)$meta['externalParticipants'];
		}
		if ($userDescription !== '') {
			$descriptionParts[] = '';
			$descriptionParts[] = $userDescription;
		}
		$description = trim(implode("\n", $descriptionParts));
		if ($description !== '') {
			$ics .= 'DESCRIPTION:' . $this->escape($description) . "\r\n";
		}
		$categories = ['Bestatter'];
		if ($type === 'schedule') $categories[] = ($meta['scheduleKind'] ?? 'INTERNAL_ACTIVITY') === 'EXTERNAL_APPOINTMENT' ? 'Externer Termin' : 'Interne Tätigkeit';
		$ics .= 'CATEGORIES:' . implode(',', array_map(fn(string $category): string => $this->escape($category), $categories)) . "\r\n"
			. "X-BESTATTER-CASE-ID:$caseId\r\n"
			. 'X-BESTATTER-CASE-NUMBER:' . $this->escape($caseNumber) . "\r\n"
			. 'X-BESTATTER-CASE-PERSON:' . $this->escape((string)($meta['casePersonName'] ?? '')) . "\r\n"
			. 'X-BESTATTER-RECORD-TITLE:' . $this->escape($this->stripCasePrefix($title)) . "\r\n"
			. 'X-BESTATTER-RECORD-TYPE:' . strtoupper($type) . "\r\n";
		if ($type === 'task') {
			$ics .= 'X-BESTATTER-ASSIGNEE-UID:' . $this->escape((string)($meta['assigneeUid'] ?? '')) . "\r\n"
				. 'X-BESTATTER-ASSIGNEE-NAME:' . $this->escape((string)($meta['assigneeName'] ?? '')) . "\r\n";
			if (($meta['workflowUrl'] ?? '') !== '') {
				$ics .= 'URL:' . $this->escape((string)$meta['workflowUrl']) . "\r\n"
					. 'X-BESTATTER-WORKFLOW-URL:' . $this->escape((string)$meta['workflowUrl']) . "\r\n";
			}
		}
		$ics .= "END:$component\r\nEND:VCALENDAR\r\n";
		return $ics;
	}

	private function parseComponent(string $ics, string $type): array {
		$ics = preg_replace("/\r?\n[ \t]/", '', $ics) ?? $ics;
		$uid = $this->property($ics, 'UID');
		$title = $this->unescape($this->property($ics, 'SUMMARY'));
		$recordTitle = $this->unescape($this->property($ics, 'X-BESTATTER-RECORD-TITLE'));
		$caseId = (int)$this->property($ics, 'X-BESTATTER-CASE-ID');
		$caseNumber = $this->unescape($this->property($ics, 'X-BESTATTER-CASE-NUMBER'));
		if ($recordTitle !== '') {
			$title = $recordTitle;
		} elseif ($caseNumber === '' && preg_match('/^\[([0-9]{4}-[0-9]+)\]\s*(.*)$/u', $title, $match)) {
			$caseNumber = $match[1];
			$title = $match[2];
		} elseif ($caseNumber !== '') {
			$title = preg_replace('/^\[' . preg_quote($caseNumber, '/') . '\]\s*/u', '', $title) ?? $title;
		}

		$status = strtoupper($this->property($ics, 'STATUS'));
		$bestatterStatus = strtoupper($this->unescape($this->property($ics, 'X-BESTATTER-STATUS')));
		$percent = (int)$this->property($ics, 'PERCENT-COMPLETE');
		$allowedBestatterStatuses = $type === 'schedule'
			? ['ENTWURF', 'BESTAETIGT', 'GEAENDERT', 'ABGESAGT', 'ERLEDIGT']
			: ['OFFEN', 'IN_BEARBEITUNG', 'ERLEDIGT'];
		$localStatus = $type === 'schedule' && $status === 'CANCELLED' ? 'ABGESAGT' : (in_array($bestatterStatus, $allowedBestatterStatuses, true) ? $bestatterStatus
			: (in_array($status, ['COMPLETED', 'CANCELLED'], true) || $percent >= 100
				? 'ERLEDIGT'
				: ($status === 'IN-PROCESS' || $percent > 0 ? 'IN_BEARBEITUNG' : 'OFFEN')));
		$dateValue = $this->property($ics, $type === 'task' ? 'DUE' : 'DTSTART');
		$priority = (int)($this->property($ics, 'PRIORITY') ?: 5);
		$description = $this->unescape($this->property($ics, 'DESCRIPTION'));
		$descriptionLines = preg_split('/\r?\n/u', $description) ?: [];
		$descriptionLines = array_values(array_filter($descriptionLines, static fn(string $line): bool =>
			!str_starts_with($line, 'Fall: ')
			&& !str_starts_with($line, 'Zuständig: ')
			&& !str_starts_with($line, 'In Bestatter bearbeiten: ')
		));
		$description = trim(implode("\n", $descriptionLines));
		$assigneeUid = $this->unescape($this->property($ics, 'X-BESTATTER-ASSIGNEE-UID'));
		$assigneeName = $this->unescape($this->property($ics, 'X-BESTATTER-ASSIGNEE-NAME'));
		$assigneeUids = array_values(array_filter(array_map('trim', explode(',', $this->unescape($this->property($ics, 'X-BESTATTER-ASSIGNEE-UIDS'))))));
		$scheduleKind = $this->unescape($this->property($ics, 'X-BESTATTER-SCHEDULE-KIND')) ?: 'INTERNAL_ACTIVITY';
		$schedulePresetKey = $this->unescape($this->property($ics, 'X-BESTATTER-SCHEDULE-PRESET-KEY'));
		$appointmentCategory = $this->unescape($this->property($ics, 'X-BESTATTER-APPOINTMENT-CATEGORY'));
		$externalParticipants = $this->unescape($this->property($ics, 'X-BESTATTER-EXTERNAL-PARTICIPANTS'));
		$externalContactIds = array_values(array_filter(array_map('intval', explode(',', $this->unescape($this->property($ics, 'X-BESTATTER-EXTERNAL-CONTACT-IDS'))))));
		$externalParticipantsAdditional = $this->unescape($this->property($ics, 'X-BESTATTER-EXTERNAL-PARTICIPANTS-ADDITIONAL'));
		$location = $this->unescape($this->property($ics, 'LOCATION'));
		$casePersonName = $this->unescape($this->property($ics, 'X-BESTATTER-CASE-PERSON'));
		$durationMinutes = max(15, (int)($this->property($ics, 'X-BESTATTER-DURATION-MINUTES') ?: 60));
		$bufferBeforeMinutes = max(0, (int)$this->property($ics, 'X-BESTATTER-BUFFER-BEFORE-MINUTES'));
		$bufferAfterMinutes = max(0, (int)$this->property($ics, 'X-BESTATTER-BUFFER-AFTER-MINUTES'));
		$resourceKeys = array_values(array_filter(array_map('trim', explode(',', $this->unescape($this->property($ics, 'X-BESTATTER-RESOURCE-KEYS'))))));
		$workflowUrl = $this->unescape($this->property($ics, 'X-BESTATTER-WORKFLOW-URL'));
		if ($workflowUrl === '') {
			$workflowUrl = $this->unescape($this->property($ics, 'URL'));
		}
		return [
			'uid' => $uid,
			'title' => trim($title),
			'date' => $this->localDate($dateValue),
			'status' => $localStatus,
			'caseId' => $caseId,
			'caseNumber' => $caseNumber,
			'data' => [
				'description' => trim($description),
				'priority' => $priority <= 3 ? 'HOCH' : ($priority >= 7 ? 'NIEDRIG' : 'NORMAL'),
				'assigneeUid' => $assigneeUid,
				'assigneeName' => $assigneeName,
				'assigneeUids' => $assigneeUids,
				'scheduleKind' => $scheduleKind,
				'schedulePresetKey' => $schedulePresetKey,
				'appointmentCategory' => $appointmentCategory,
				'externalParticipants' => $externalParticipants,
				'externalContactIds' => $externalContactIds,
				'externalParticipantsAdditional' => $externalParticipantsAdditional,
				'location' => $location,
				'casePersonName' => $casePersonName,
				'durationMinutes' => $durationMinutes,
				'bufferBeforeMinutes' => $bufferBeforeMinutes,
				'bufferAfterMinutes' => $bufferAfterMinutes,
				'resourceKeys' => $resourceKeys,
				'workflowUrl' => $workflowUrl,
			],
		];
	}

	private function enrichTaskMeta(array $meta, int $caseId, string $uid, int $recordId = 0): array {
		$assignee = $this->team->assignee((string)($meta['assigneeUid'] ?? ''));
		$meta['assigneeUid'] = $assignee['uid'];
		$meta['assigneeName'] = $assignee['displayName'];
		if ($caseId > 0) {
			$meta['workflowUrl'] = $this->workflowUrl($caseId, $uid, $recordId);
		}
		return $meta;
	}

	private function enrichScheduleMeta(array $meta): array {
		$kind = strtoupper((string)($meta['scheduleKind'] ?? 'INTERNAL_ACTIVITY'));
		if (!in_array($kind, ['INTERNAL_ACTIVITY', 'EXTERNAL_APPOINTMENT'], true)) throw new \InvalidArgumentException('Unbekannte Terminart.');
		$meta['scheduleKind'] = $kind;
		if ($kind === 'EXTERNAL_APPOINTMENT') {
			if (trim((string)($meta['appointmentCategory'] ?? '')) === '') throw new \InvalidArgumentException('Für einen externen Fixtermin ist eine Terminart erforderlich.');
			if (trim((string)($meta['location'] ?? '')) === '') throw new \InvalidArgumentException('Für einen externen Fixtermin ist ein Ort erforderlich.');
		}
		$uids = array_values(array_unique(array_filter(array_map('strval', (array)($meta['assigneeUids'] ?? [$this->currentUserId()])))));
		$assignees = [];
		foreach ($uids as $uid) { $member = $this->team->member($uid); if ($member === null && $uid === $this->currentUserId()) $member = $this->team->assignee($uid); if ($member === null) throw new \InvalidArgumentException('Eine ausgewählte zuständige Person gehört nicht zur Gruppe Bestatter.'); $assignees[] = $member; }
		if ($assignees === []) { $member = $this->team->assignee($this->currentUserId()); $uids = [$member['uid']]; $assignees = [$member]; }
		$meta['assigneeUids'] = $uids; $meta['assignees'] = $assignees; return $meta;
	}

	private function writeScheduleCopies(string $uid, string $ics, array $meta, array $previousCopies = []): array {
		$result = []; $current = $this->currentUserId();
		$selected = array_fill_keys(array_map('strval', (array)($meta['assigneeUids'] ?? [])), true);
		foreach ($previousCopies as $copy) {
			$ownerUid = (string)($copy['ownerUid'] ?? '');
			if ($ownerUid === '' || isset($selected[$ownerUid]) || !($copy['stored'] ?? false)) continue;
			try { $this->deleteDavObject((int)($copy['calendarKey'] ?? 0), (string)($copy['uri'] ?? '')); }
			catch (\Throwable) { /* Ein entfernter oder bereits gelöschter Kalender blockiert die Terminpflege nicht. */ }
		}
		foreach ((array)($meta['assigneeUids'] ?? []) as $ownerUid) {
			if ($ownerUid === $current) continue;
			try { $result[] = $this->writeNewComponentForOwner($uid . '.ics', $ics, (string)$ownerUid); }
			catch (\Throwable $error) { $result[] = ['ownerUid' => (string)$ownerUid, 'stored' => false, 'syncError' => $error->getMessage()]; }
		}
		return $result;
	}

	private function deleteDavObject(int $calendarId, string $uri): void {
		if ($calendarId <= 0 || $uri === '') return;
		$class = '\\OCA\\DAV\\CalDAV\\CalDavBackend';
		if (!class_exists($class)) throw new \RuntimeException('Das Nextcloud-DAV-Backend ist nicht verfügbar.');
		Server::get($class)->deleteCalendarObject($calendarId, $uri);
	}

	private function writeNewComponentForOwner(string $name, string $ics, string $ownerUid): array {
		$lastError = null; $requiredComponent = str_contains($ics, 'BEGIN:VTODO') ? 'VTODO' : 'VEVENT';
		foreach ($this->calendarManager->getCalendarsForPrincipal($this->principal($ownerUid)) as $calendar) {
			if (!$this->isWritable($calendar) || !$this->supportsCalendarComponent($calendar, $requiredComponent)) continue;
			try { $matches=$calendar->search('',[],['uid'=>$this->property($ics,'UID')],1); if($matches===[])$this->createDavObject($calendar,$name,$ics);else$this->updateDavObject($calendar,(string)($matches[0]['uri']??$name),$ics); return $this->targetMetadata($calendar,$this->property($ics,'UID'),$name)+['ownerUid'=>$ownerUid,'stored'=>true]; }
			catch (\Throwable $error) { $lastError=$error; }
		}
		throw $lastError ?? new \RuntimeException('Kein beschreibbarer persönlicher Kalender für ' . $ownerUid . ' gefunden.');
	}

	private function workflowUrl(int $caseId, string $uid, int $recordId = 0): string {
		$base = $this->urlGenerator->linkToRouteAbsolute('bestatter.page.index');
		$params = [
			'caseId' => $caseId,
			'taskUid' => $uid,
		];
		if ($recordId > 0) $params['taskId'] = $recordId;
		return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($params);
	}

	private function resolveCase(int $caseId, string $caseNumber): ?array {
		try {
			if ($caseId > 0) {
				$case = $this->cases->getCase($caseId);
				if ($caseNumber === '' || $case['caseNumber'] === $caseNumber) {
					return $case;
				}
			}
		} catch (\Throwable) {
			// Fall wurde zwischenzeitlich entfernt; Aufbewahrung als nicht zugeordneter Eintrag.
		}
		return $caseNumber !== '' ? $this->cases->findCaseByNumber($caseNumber) : null;
	}

	private function caseNumber(int $caseId, array $record = []): string {
		if ($caseId <= 0) {
			return (string)($record['caseNumber'] ?? $record['data']['caseNumber'] ?? '');
		}
		return $this->cases->caseNumber($caseId);
	}

	private function casePersonName(int $caseId, array $record = []): string {
		if ($caseId <= 0) return trim((string)($record['data']['casePersonName'] ?? ''));
		try {
			$case = $this->cases->getCase($caseId);
			return trim((string)($case['firstName'] ?? '') . ' ' . (string)($case['lastName'] ?? ''));
		} catch (\Throwable) {
			return trim((string)($record['data']['casePersonName'] ?? ''));
		}
	}

	private function visibleTitle(string $title, string $caseNumber, string $casePersonName, string $type): string {
		if ($caseNumber === '') return trim($title);
		$person = trim($casePersonName);
		$recordTitle = $this->stripCasePrefix($title);
		if ($type === 'task') return '[' . $caseNumber . '] ' . $recordTitle . ($person !== '' ? ' – ' . $person : '');
		return '[' . $caseNumber . '] ' . ($person !== '' ? $person . ' · ' : '') . $recordTitle;
	}

	private function isWritable(ICalendar $calendar): bool {
		if ($calendar->isDeleted()) {
			return false;
		}
		return !($calendar instanceof ICalendarIsWritable) || $calendar->isWritable();
	}

	private function calendarData(array $row): string {
		return (string)($row['calendar-data'] ?? $row['calendardata'] ?? $row['data'] ?? '');
	}

	private function ownerFromCalendarData(array $calendarData): string {
		$principal = (string)($calendarData['principaluri'] ?? $calendarData['principalUri'] ?? '');
		if ($principal === '') {
			return '';
		}
		$parts = explode('/', trim($principal, '/'));
		return (string)end($parts);
	}

	private function property(string $ics, string $name): string {
		return preg_match('/(?:^|\\r?\\n)' . preg_quote($name, '/') . '(?:;[^:]*)?:(.*)(?:\\r?\\n|$)/i', $ics, $match)
			? trim((string)$match[1]) : '';
	}

	private function localDate(string $value): string {
		if ($value === '') {
			return '';
		}
		foreach (['Ymd\\THis\\Z', 'Ymd\\THis', 'Ymd'] as $format) {
			$timezone = str_ends_with($format, '\\Z') ? 'UTC' : self::TIMEZONE;
			$date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone($timezone));
			if ($date !== false) {
				return $date->setTimezone(new \DateTimeZone(self::TIMEZONE))->format(strlen($value) === 8 ? 'Y-m-d' : 'Y-m-d\\TH:i');
			}
		}
		return $value;
	}

	private function principal(?string $uid = null): string {
		return 'principals/users/' . ($uid ?: $this->currentUserId());
	}

	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('Kein Nextcloud-Benutzer angemeldet.');
		}
		return $user->getUID();
	}

	private function icalDate(string $value): string {
		return (new \DateTimeImmutable($value, new \DateTimeZone(self::TIMEZONE)))->format('Ymd\\THis');
	}

	private function stripCasePrefix(string $value): string {
		return preg_replace('/^\[[0-9]{4}-[0-9]+\]\s*/u', '', trim($value)) ?? trim($value);
	}

	private function escape(string $value): string {
		return str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], $value);
	}

	private function unescape(string $value): string {
		return str_replace(['\\n', '\\,', '\\;', '\\\\'], ["\n", ',', ';', '\\'], $value);
	}

	private function priority(string $priority): int {
		return match (strtoupper($priority)) {
			'HOCH' => 1,
			'NIEDRIG' => 9,
			default => 5,
		};
	}

	private function taskStatus(string $status): string {
		return match (strtoupper($status)) {
			'ERLEDIGT' => 'COMPLETED',
			'IN_BEARBEITUNG' => 'IN-PROCESS',
			default => 'NEEDS-ACTION',
		};
	}

	private function uuid(): string {
		$bytes = bin2hex(random_bytes(16));
		return substr($bytes, 0, 8) . '-' . substr($bytes, 8, 4) . '-4' . substr($bytes, 13, 3)
			. '-a' . substr($bytes, 17, 3) . '-' . substr($bytes, 20);
	}
}
