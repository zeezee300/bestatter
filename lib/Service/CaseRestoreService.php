<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * 0.92.6 – Einzelfall-Restore: holt einen gelöschten Fall (Sterbefall, Auftrag oder Vorsorge) aus einem vollständigen
 * Sicherungspaket in die laufende Installation zurück. Der vollständige Restore (BackupService) bleibt auf ein Ziel
 * ohne Falldaten beschränkt; hier wird nur eingefügt, nie überschrieben.
 *
 * Zurück kommen alle Daten, die „Fall löschen“ entfernt (CASE_TABLES/CHILD_TABLES = CaseDeletionService::deleteCaseData),
 * mit ihren ursprünglichen IDs – die Datenbank vergibt gelöschte IDs nicht neu, Verweise zwischen den Zeilen bleiben so
 * gültig. Belegte IDs, eine vergebene Fallnummer oder Rechnungsnummer und abweichende Dateien am Ziel sperren (conflicts).
 *
 * Dateien der Fallakte werden an ihren alten Ort geschrieben. Nextcloud vergibt dabei neue Datei-IDs; die Verweise in den
 * Falldaten (fileId in JSON, file_id-Spalten) werden umgeschrieben – über die im Manifest gesicherte Datei-ID (Pakete ab
 * 0.92.6) oder über den Pfad neben der Datei-ID (ältere Pakete). Termine und Aufgaben legt der Abgleich mit den
 * Nextcloud-Kalendern neu an. Das Änderungsprotokoll des Falls bekommt seine beim Löschen geleerten Inhalte zurück; die
 * Hash-Kette umfasst diese Inhalte nicht und bleibt unverändert gültig.
 *
 * Vorher legt die App einen App-Snapshot ab. Schlägt etwas fehl, werden angelegte Dateien und Ordner entfernt und die
 * Datenbank zurückgerollt.
 */
class CaseRestoreService {
	public const CONFIRMATION = 'BESTATTER-FALL-ZURUECK';

	/** Tabellen mit case_id in Einspielreihenfolge (der Fall selbst zuerst). */
	public const CASE_TABLES = [
		'bestatter_cases',
		'bestatter_precare', 'bestatter_precare_funding', 'bestatter_side_orders',
		'bestatter_records', 'bestatter_process_runs', 'bestatter_schedule_history',
		'bestatter_mail_outbox', 'bestatter_dereg_mail_outbox',
		'bestatter_case_services', 'bestatter_commercial_docs', 'bestatter_invoices', 'bestatter_incoming_invoices',
		'bestatter_capture_imports', 'bestatter_order_form_entries', 'bestatter_external_documents', 'bestatter_case_labels',
	];

	/** Abhängige Tabellen ohne case_id: Tabelle => [Spalte mit der ID der Elternzeile, Elterntabelle]. */
	public const CHILD_TABLES = [
		'bestatter_action_runs' => ['source_record_id', 'bestatter_records'],
		'bestatter_record_assignees' => ['record_id', 'bestatter_records'],
		'bestatter_invoice_items' => ['invoice_id', 'bestatter_invoices'],
		'bestatter_incoming_items' => ['incoming_invoice_id', 'bestatter_incoming_invoices'],
		'bestatter_integration_jobs' => ['object_id', 'bestatter_external_documents'],
	];

	public const TABLE_LABELS = [
		'bestatter_cases' => 'Fall', 'bestatter_precare' => 'Vorsorgevertrag', 'bestatter_precare_funding' => 'Vorsorge-Finanzierungen',
		'bestatter_side_orders' => 'Nebenaufträge', 'bestatter_records' => 'Aufgaben, Termine und Fallangaben', 'bestatter_process_runs' => 'Ablaufschritte',
		'bestatter_schedule_history' => 'Terminänderungen', 'bestatter_mail_outbox' => 'E-Mail-Entwürfe', 'bestatter_dereg_mail_outbox' => 'Abmeldungs-E-Mails',
		'bestatter_case_services' => 'Leistungen', 'bestatter_commercial_docs' => 'Kostenvoranschläge und Aufträge', 'bestatter_invoices' => 'Rechnungen',
		'bestatter_incoming_invoices' => 'Eingangsrechnungen', 'bestatter_capture_imports' => 'Schnellerfassungen', 'bestatter_order_form_entries' => 'ausgefüllte Auftragsformulare',
		'bestatter_external_documents' => 'Belege aus Paperless', 'bestatter_case_labels' => 'Marker', 'bestatter_action_runs' => 'ausgeführte Ablaufaktionen',
		'bestatter_record_assignees' => 'Zuständigkeiten', 'bestatter_invoice_items' => 'Rechnungspositionen', 'bestatter_incoming_items' => 'Positionen von Eingangsrechnungen',
		'bestatter_integration_jobs' => 'Aufträge an Paperless',
	];

	/** Paperless-Aufträge, die noch liefen, werden nicht erneut gestartet (sonst entstünden Dubletten in Paperless). */
	private const OPEN_JOB_STATES = ['QUEUED', 'RETRY', 'RUNNING'];
	private const RESYNC_NOTE = 'Nach Wiederherstellung des Falls neu anzulegen.';

	public function __construct(
		private IDBConnection $db,
		private IRootFolder $rootFolder,
		private IUserManager $users,
		private AuditService $audit,
		private BackupService $backup,
		private InstallationConfigService $installation,
		private GroupwareService $groupware,
	) {
	}

	/** Pfad eines Pakets aus der Sicherungsverwaltung (Name wie in der Liste „Backup & Restore“). */
	public function managedPackage(string $name): string {
		if (!str_ends_with(strtolower($name), '.zip')) {
			throw new \InvalidArgumentException('Einzelne Fälle lassen sich nur aus einem vollständigen Sicherungspaket (ZIP) zurückholen. Ein App-Snapshot enthält keine Dokumente.');
		}
		return $this->backup->managedFilePath($name);
	}

	/**
	 * Fälle im Paket. „restorable“ sind Fälle, die es in der App nicht mehr gibt und deren Nummer frei ist.
	 * @return array{package:array<string,mixed>,cases:list<array<string,mixed>>,restorableCount:int,existingCount:int}
	 */
	public function candidates(string $path): array {
		['manifest' => $manifest, 'payload' => $payload] = $this->openPackage($path);
		$tables = (array)($payload['tables'] ?? []);
		$deletions = $this->deletionEvents();
		$counts = [];
		foreach (['bestatter_records', 'bestatter_invoices'] as $table) {
			foreach ((array)($tables[$table] ?? []) as $row) {
				if (is_array($row)) {
					$counts[$table][(int)($row['case_id'] ?? 0)] = ($counts[$table][(int)($row['case_id'] ?? 0)] ?? 0) + 1;
				}
			}
		}
		$cases = [];
		foreach ((array)($tables['bestatter_cases'] ?? []) as $row) {
			if (!is_array($row)) {
				continue;
			}
			$id = (int)($row['id'] ?? 0);
			$number = (string)($row['case_number'] ?? '');
			$existsById = $this->exists('bestatter_cases', 'id', $id);
			$existsByNumber = !$existsById && $number !== '' && $this->exists('bestatter_cases', 'case_number', $number);
			$cases[] = [
				'id' => $id, 'caseNumber' => $number, 'name' => $this->caseName($row),
				'kind' => CaseKinds::normalize((string)($row['case_kind'] ?? '')), 'kindLabel' => CaseKinds::label(CaseKinds::normalize((string)($row['case_kind'] ?? ''))),
				'status' => (string)($row['status'] ?? ''), 'createdAt' => (string)($row['created_at'] ?? ''),
				'records' => (int)($counts['bestatter_records'][$id] ?? 0), 'invoices' => (int)($counts['bestatter_invoices'][$id] ?? 0),
				'files' => count($this->caseFiles($manifest, $number)),
				'exists' => $existsById, 'numberTaken' => $existsByNumber, 'restorable' => !$existsById && !$existsByNumber,
				'deletedAt' => (string)($deletions[$id]['at'] ?? ''), 'deletedBy' => (string)($deletions[$id]['by'] ?? ''),
			];
		}
		usort($cases, static fn (array $a, array $b): int => [$b['restorable'], $b['deletedAt'], $b['caseNumber']] <=> [$a['restorable'], $a['deletedAt'], $a['caseNumber']]);
		$restorable = count(array_filter($cases, static fn (array $case): bool => $case['restorable']));
		return ['package' => $this->packageInfo($path, $manifest), 'cases' => $cases, 'restorableCount' => $restorable, 'existingCount' => count($cases) - $restorable];
	}

	/** Prüflauf: was zurückkommt, was sperrt, worauf zu achten ist. Ändert nichts. */
	public function preview(string $path, string $case): array {
		$plan = $this->plan($path, $case);
		unset($plan['rows'], $plan['fileEntries'], $plan['auditRows']);
		return $plan;
	}

	/** Holt den Fall zurück. Bricht ohne Änderung ab, wenn der Prüflauf Konflikte meldet. */
	public function restore(string $path, string $case): array {
		$plan = $this->plan($path, $case);
		if ($plan['conflicts'] !== []) {
			throw new \InvalidArgumentException('Fall ' . $plan['caseNumber'] . ' kann nicht zurückgeholt werden: ' . implode(' ', $plan['conflicts']));
		}
		$preRestoreSnapshot = (string)($this->backup->create()['path'] ?? '');
		$zip = new \ZipArchive();
		if ($zip->open($path) !== true) {
			throw new \RuntimeException('Das Sicherungspaket konnte nicht geöffnet werden.');
		}
		$createdFiles = [];
		$createdFolders = [];
		$idMap = [];
		$pathMap = [];
		$identical = 0;
		$stats = ['fileIdsMapped' => 0, 'calendarPlanned' => 0, 'jobsStopped' => 0];
		$transaction = false;
		$inserted = [];
		$relinked = 0;
		$auditRestored = 0;
		try {
			try {
				foreach ($plan['fileEntries'] as $entry) {
					$content = $zip->getFromName((string)$entry['archivePath']);
					if ($content === false || !hash_equals((string)$entry['sha256'], hash('sha256', $content))) {
						throw new \RuntimeException('Datei fehlt im Paket oder ist beschädigt: ' . (string)$entry['relativePath']);
					}
					[$folder, $name] = $this->ensureFolder((string)$entry['ownerUid'], (string)$entry['relativePath'], $createdFolders);
					if ($folder->nodeExists($name)) {
						$node = $folder->get($name);
						if (!$node instanceof File || !hash_equals((string)$entry['sha256'], hash('sha256', (string)$node->getContent()))) {
							throw new \RuntimeException('Am Ziel liegt inzwischen eine andere Datei: ' . (string)$entry['relativePath']);
						}
						$identical++;
					} else {
						$node = $folder->newFile($name);
						$node->putContent($content);
						$createdFiles[] = $node;
					}
					$newId = (int)$node->getId();
					if (is_numeric($entry['fileId'] ?? null)) {
						$idMap[(int)$entry['fileId']] = $newId;
					}
					foreach ($this->pathKeys((string)$entry['relativePath'], (string)$entry['caseRelative']) as $key) {
						$pathMap[$key] = $newId;
					}
				}
				$this->db->beginTransaction();
				$transaction = true;
				foreach ($plan['rows'] as $table => $rows) {
					$inserted[$table] = 0;
					foreach ($rows as $row) {
						$this->insert($table, $this->prepareRow($table, $row, $idMap, $pathMap, $stats));
						$inserted[$table]++;
					}
				}
				$relinked = $this->relink((int)$plan['caseId'], $plan['links']);
				$auditRestored = $this->restoreAuditContent($plan['auditRows']);
				$this->db->commit();
				$transaction = false;
			} catch (\Throwable $error) {
				if ($transaction) {
					$this->db->rollBack();
				}
				throw $error;
			}
		} catch (\Throwable $error) {
			foreach (array_reverse($createdFiles) as $file) {
				try {
					$file->delete();
				} catch (\Throwable) {
				}
			}
			foreach (array_reverse($createdFolders) as $folder) {
				try {
					if ($folder->getDirectoryListing() === []) {
						$folder->delete();
					}
				} catch (\Throwable) {
				}
			}
			throw new \RuntimeException('Fall ' . $plan['caseNumber'] . ' wurde nicht zurückgeholt, alle Änderungen sind zurückgenommen. Ursache: ' . $error->getMessage(), 0, $error);
		} finally {
			$zip->close();
		}
		$summary = [
			'caseNumber' => $plan['caseNumber'], 'source' => basename($path), 'packageCreatedAt' => $plan['package']['createdAt'],
			'inserted' => array_filter($inserted), 'files' => count($createdFiles), 'identicalFiles' => $identical,
			'fileIdsMapped' => $stats['fileIdsMapped'], 'auditEntriesRestored' => $auditRestored, 'relinked' => $relinked,
			'calendarPlanned' => $stats['calendarPlanned'], 'paperlessJobsStopped' => $stats['jobsStopped'], 'preRestoreSnapshot' => $preRestoreSnapshot,
		];
		$this->audit->log((int)$plan['caseId'], 'CASE', (int)$plan['caseId'], 'RESTORED', null, $summary);
		$this->audit->logSystem('CASE', 'RESTORED', ['caseId' => (int)$plan['caseId']] + $summary);
		$calendar = ['checked' => 0, 'repaired' => 0, 'waiting' => 0, 'failed' => 0];
		if ($stats['calendarPlanned'] > 0) {
			try {
				$calendar = $this->groupware->retryFailedSync();
			} catch (\Throwable) {
				// Der Hintergrundjob versucht es erneut.
			}
		}
		return ['restored' => true, 'caseId' => (int)$plan['caseId'], 'caseNumber' => $plan['caseNumber'], 'name' => $plan['name']] + $summary
			+ ['calendar' => $calendar, 'warnings' => $plan['warnings']];
	}

	/** @return array<string,mixed> */
	private function plan(string $path, string $case): array {
		['manifest' => $manifest, 'payload' => $payload] = $this->openPackage($path);
		$caseRow = $this->findCase($payload, $case);
		$caseId = (int)$caseRow['id'];
		$caseNumber = (string)($caseRow['case_number'] ?? '');
		$rows = $this->caseRows($payload, $caseId);
		$conflicts = [];
		$warnings = [];
		$hints = [];

		if ($this->exists('bestatter_cases', 'id', $caseId)) {
			$conflicts[] = 'Der Fall ist in der App vorhanden – zurückgeholt werden nur gelöschte Fälle.';
		} elseif ($caseNumber !== '' && $this->exists('bestatter_cases', 'case_number', $caseNumber)) {
			$conflicts[] = 'Die Fallnummer ' . $caseNumber . ' ist inzwischen an einen anderen Fall vergeben.';
		}
		foreach ($rows as $table => $tableRows) {
			if ($table === 'bestatter_cases' || $tableRows === []) {
				continue;
			}
			$taken = $this->existingIds($table, array_map(static fn (array $row): int => (int)$row['id'], $tableRows));
			if ($taken !== []) {
				$conflicts[] = count($taken) . ' ' . (self::TABLE_LABELS[$table] ?? $table) . ' mit derselben ID gibt es schon (ID ' . implode(', ', array_slice($taken, 0, 5)) . (count($taken) > 5 ? ', …' : '') . ').';
			}
		}
		foreach ([['bestatter_invoices', 'invoice_number', 'Rechnungsnummer'], ['bestatter_commercial_docs', 'document_number', 'Belegnummer']] as [$table, $column, $label]) {
			foreach ($rows[$table] as $row) {
				$number = trim((string)($row[$column] ?? ''));
				if ($number !== '' && $this->exists($table, $column, $number)) {
					$conflicts[] = 'Die ' . $label . ' ' . $number . ' ist inzwischen vergeben.';
				}
			}
		}

		// Marker, die es nicht mehr gibt, entfallen.
		$labels = $rows['bestatter_case_labels'];
		$rows['bestatter_case_labels'] = array_values(array_filter($labels, fn (array $row): bool => !isset($row['label_id']) || $this->exists('bestatter_labels', 'id', (int)$row['label_id'])));
		if (count($labels) > count($rows['bestatter_case_labels'])) {
			$warnings[] = (count($labels) - count($rows['bestatter_case_labels'])) . ' Marker gibt es nicht mehr; sie werden nicht zugeordnet.';
		}

		// Dateien der Fallakte
		$fileEntries = [];
		$identical = 0;
		$bytes = 0;
		$missingUsers = [];
		foreach ($this->caseFiles($manifest, $caseNumber) as $entry) {
			$uid = (string)($entry['ownerUid'] ?? '');
			if (!$this->users->userExists($uid)) {
				$missingUsers[$uid] = true;
				continue;
			}
			$existing = $this->nodeAt($uid, (string)$entry['relativePath']);
			if ($existing !== null) {
				if (!$existing instanceof File || !hash_equals((string)$entry['sha256'], hash('sha256', (string)$existing->getContent()))) {
					$conflicts[] = 'Am Ziel liegt schon eine andere Datei: ' . (string)$entry['relativePath'] . '.';
					continue;
				}
				$identical++;
			}
			$bytes += (int)($entry['size'] ?? 0);
			$fileEntries[] = $entry;
		}
		foreach (array_keys($missingUsers) as $uid) {
			$conflicts[] = 'Das Nextcloud-Konto „' . $uid . '“, dem die Fallakte gehört, gibt es nicht mehr.';
		}
		$fileErrors = array_filter((array)($manifest['errors'] ?? []), fn (mixed $error): bool => is_array($error) && $this->belongsToCase((string)($error['file'] ?? $error['root'] ?? ''), $caseNumber));
		if ($fileErrors !== []) {
			$warnings[] = count($fileErrors) . ' Datei(en) dieses Falls konnten beim Sichern nicht gelesen werden und fehlen im Paket.';
		}
		if ($fileEntries === [] && $conflicts === []) {
			$warnings[] = 'Die Sicherung enthält keine Dokumente dieses Falls.';
		}
		$withoutIds = array_filter($fileEntries, static fn (array $entry): bool => !is_numeric($entry['fileId'] ?? null));
		if ($withoutIds !== []) {
			$hints[] = 'Das Paket stammt aus einer App-Version vor 0.92.6: Verweise auf Dokumente werden über den Ablageort zugeordnet.';
		}

		// Verknüpfungen zu anderen Akten
		$links = ['origin' => null, 'redeemed' => null, 'derived' => [], 'redeemedBy' => []];
		$origin = (int)($caseRow['origin_case_id'] ?? 0);
		if ($origin > 0 && !$this->exists('bestatter_cases', 'id', $origin)) {
			$warnings[] = 'Die Akte, aus der dieser Fall übernommen wurde, gibt es nicht mehr; der Verweis entfällt.';
		}
		foreach ($rows['bestatter_precare'] as $row) {
			$redeemed = (int)($row['redeemed_case_id'] ?? 0);
			if ($redeemed > 0 && !$this->exists('bestatter_cases', 'id', $redeemed)) {
				$warnings[] = 'Der Sterbefall, für den diese Vorsorge eingelöst wurde, gibt es nicht mehr; der Verweis entfällt.';
			}
		}
		$tables = (array)($payload['tables'] ?? []);
		foreach ((array)($tables['bestatter_cases'] ?? []) as $row) {
			if (is_array($row) && (int)($row['origin_case_id'] ?? 0) === $caseId && $this->currentlyUnlinked('bestatter_cases', 'id', (int)$row['id'], 'origin_case_id')) {
				$links['derived'][] = ['id' => (int)$row['id'], 'caseNumber' => (string)($row['case_number'] ?? '')];
			}
		}
		foreach ((array)($tables['bestatter_precare'] ?? []) as $row) {
			if (is_array($row) && (int)($row['redeemed_case_id'] ?? 0) === $caseId && (int)($row['case_id'] ?? 0) !== $caseId
				&& $this->currentlyUnlinked('bestatter_precare', 'case_id', (int)$row['case_id'], 'redeemed_case_id')) {
				$links['redeemedBy'][] = ['caseId' => (int)$row['case_id']];
			}
		}
		if ($links['derived'] !== []) {
			$hints[] = 'Die Verknüpfung mit ' . implode(', ', array_column($links['derived'], 'caseNumber')) . ' wird wiederhergestellt.';
		}
		if ($links['redeemedBy'] !== []) {
			$hints[] = 'Die Verknüpfung mit der eingelösten Vorsorge wird wiederhergestellt.';
		}

		// Personen, Version, Kalender, Paperless
		$owners = [];
		foreach ([...$rows['bestatter_records'], $caseRow] as $row) {
			foreach (['owner_uid', 'created_by', 'assignee_uid'] as $column) {
				$uid = trim((string)($row[$column] ?? ''));
				if ($uid !== '' && !isset($owners[$uid])) {
					$owners[$uid] = $this->users->userExists($uid);
				}
			}
		}
		$gone = array_keys(array_filter($owners, static fn (bool $exists): bool => !$exists));
		if ($gone !== []) {
			$warnings[] = 'Diese Konten gibt es nicht mehr: ' . implode(', ', $gone) . '. Ihre Aufgaben und Termine kommen zurück, sind aber niemandem zugeordnet, der sich anmelden kann.';
		}
		$packageVersion = (string)($payload['appVersion'] ?? $manifest['appVersion'] ?? '');
		if ($packageVersion !== '' && $packageVersion !== Application::VERSION) {
			$hints[] = 'Die Sicherung stammt aus App-Version ' . $packageVersion . ' (installiert: ' . Application::VERSION . '). Die Daten kommen so zurück, wie sie damals gespeichert waren.';
		}
		$calendar = count(array_filter($rows['bestatter_records'], fn (array $row): bool => $this->needsResync($row)));
		if ($calendar > 0) {
			$hints[] = $calendar . ' Termin(e) und Aufgabe(n) werden in den Nextcloud-Kalendern neu angelegt.';
		}
		$openJobs = count(array_filter($rows['bestatter_integration_jobs'], static fn (array $row): bool => in_array(strtoupper((string)($row['job_status'] ?? '')), self::OPEN_JOB_STATES, true)));
		if ($openJobs > 0) {
			$warnings[] = $openJobs . ' Übertragung(en) an Paperless waren zum Zeitpunkt der Sicherung nicht abgeschlossen. Sie werden nicht erneut gestartet; bitte in Paperless prüfen.';
		}

		// Änderungsprotokoll: Inhalte, die beim Löschen geleert wurden
		$auditRows = $this->restorableAuditRows((array)($tables['bestatter_audit_log'] ?? []), $caseId);

		$counts = [];
		foreach ($rows as $table => $tableRows) {
			if ($tableRows !== []) {
				$counts[$table] = ['label' => self::TABLE_LABELS[$table] ?? $table, 'count' => count($tableRows)];
			}
		}
		return [
			'caseId' => $caseId, 'caseNumber' => $caseNumber, 'name' => $this->caseName($caseRow),
			'kind' => CaseKinds::normalize((string)($caseRow['case_kind'] ?? '')), 'kindLabel' => CaseKinds::label(CaseKinds::normalize((string)($caseRow['case_kind'] ?? ''))),
			'status' => (string)($caseRow['status'] ?? ''), 'package' => $this->packageInfo($path, $manifest),
			'tables' => $counts, 'fileCount' => count($fileEntries), 'fileBytes' => $bytes, 'identicalFiles' => $identical,
			'auditEntries' => count($auditRows), 'calendarEntries' => $calendar,
			'conflicts' => array_values(array_unique($conflicts)), 'warnings' => array_values(array_unique($warnings)), 'hints' => array_values(array_unique($hints)),
			'canRestore' => $conflicts === [], 'confirmation' => self::CONFIRMATION,
			'rows' => $rows, 'fileEntries' => $fileEntries, 'links' => $links, 'auditRows' => $auditRows,
		];
	}

	/** @return array{manifest:array<string,mixed>,payload:array<string,mixed>} */
	private function openPackage(string $path): array {
		if (!str_ends_with(strtolower($path), '.zip')) {
			throw new \InvalidArgumentException('Einzelne Fälle lassen sich nur aus einem vollständigen Sicherungspaket (ZIP) zurückholen. Ein App-Snapshot enthält keine Dokumente.');
		}
		if (!is_file($path) || !is_readable($path)) {
			throw new \InvalidArgumentException('Das Sicherungspaket ist nicht lesbar.');
		}
		$zip = new \ZipArchive();
		if ($zip->open($path) !== true) {
			throw new \InvalidArgumentException('Das Sicherungspaket ist kein lesbares ZIP-Archiv.');
		}
		try {
			$manifestJson = $zip->getFromName('manifest.json');
			$snapshotJson = $zip->getFromName('snapshot.json');
			if ($manifestJson === false || $snapshotJson === false) {
				throw new \InvalidArgumentException('Manifest oder Datenbanksnapshot fehlt im Sicherungspaket.');
			}
			$manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'bestatter-package/1') {
				throw new \InvalidArgumentException('Das Sicherungspaketformat wird nicht unterstützt.');
			}
			if (!hash_equals((string)($manifest['database']['sha256'] ?? ''), hash('sha256', $snapshotJson))) {
				throw new \InvalidArgumentException('Der Datenbanksnapshot im Sicherungspaket wurde verändert.');
			}
			$envelope = json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR);
			$payload = is_array($envelope) ? ($envelope['payload'] ?? null) : null;
			if (!is_array($payload) || ($payload['format'] ?? '') !== 'bestatter-backup/1') {
				throw new \InvalidArgumentException('Das Sicherungsformat wird nicht unterstützt.');
			}
			return ['manifest' => $manifest, 'payload' => $payload];
		} finally {
			$zip->close();
		}
	}

	/** @return array<string,mixed> */
	private function packageInfo(string $path, array $manifest): array {
		return ['name' => basename($path), 'createdAt' => (string)($manifest['createdAt'] ?? ''), 'appVersion' => (string)($manifest['appVersion'] ?? ''), 'status' => (string)($manifest['status'] ?? 'OK')];
	}

	/** @return array<string,mixed> */
	private function findCase(array $payload, string $case): array {
		$case = trim($case);
		if ($case === '') {
			throw new \InvalidArgumentException('Bitte die Fallnummer angeben.');
		}
		foreach ((array)($payload['tables']['bestatter_cases'] ?? []) as $row) {
			if (is_array($row) && ((string)($row['case_number'] ?? '') === $case || (ctype_digit($case) && (int)($row['id'] ?? 0) === (int)$case))) {
				return $row;
			}
		}
		throw new \InvalidArgumentException('Der Fall ' . $case . ' ist in dieser Sicherung nicht enthalten.');
	}

	/** @return array<string,list<array<string,mixed>>> */
	private function caseRows(array $payload, int $caseId): array {
		$tables = (array)($payload['tables'] ?? []);
		$rows = [];
		foreach (self::CASE_TABLES as $table) {
			$column = $table === 'bestatter_cases' ? 'id' : 'case_id';
			$rows[$table] = array_values(array_filter((array)($tables[$table] ?? []), static fn (mixed $row): bool => is_array($row) && (int)($row[$column] ?? 0) === $caseId));
		}
		foreach (self::CHILD_TABLES as $table => [$column, $parent]) {
			$parentIds = array_flip(array_map(static fn (array $row): int => (int)($row['id'] ?? 0), $rows[$parent]));
			$rows[$table] = array_values(array_filter((array)($tables[$table] ?? []), static fn (mixed $row): bool => is_array($row)
				&& isset($parentIds[(int)($row[$column] ?? 0)])
				&& ($table !== 'bestatter_integration_jobs' || strtoupper((string)($row['object_type'] ?? '')) === 'EXTERNAL_DOCUMENT')));
		}
		return $rows;
	}

	/**
	 * Dateien der Fallakte im Manifest: unter dem Fallordner nach der aktuellen Ablage. Hat sich die Ablage seit der
	 * Sicherung geändert, wird der Fallordner am Ende des Pfads erkannt (…/<Jahr>/<Fallnummer>/…).
	 * @return list<array<string,mixed>>
	 */
	private function caseFiles(array $manifest, string $caseNumber): array {
		$year = CaseKinds::yearOfNumber($caseNumber);
		if ($caseNumber === '' || $year === null || str_contains($caseNumber, '/') || str_contains($caseNumber, '\\')) {
			return [];
		}
		try {
			$prefix = trim($this->installation->caseFolderPath($caseNumber), '/') . '/';
		} catch (\InvalidArgumentException) {
			$prefix = null;
		}
		$byPrefix = [];
		$bySegment = [];
		$needle = '/' . $year . '/' . $caseNumber . '/';
		foreach ((array)($manifest['files'] ?? []) as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$relative = trim((string)($entry['relativePath'] ?? ''), '/');
			if (!$this->safePath($relative) || !$this->safePath((string)($entry['ownerUid'] ?? ''))) {
				continue;
			}
			if ($prefix !== null && str_starts_with($relative, $prefix)) {
				$byPrefix[] = ['relativePath' => $relative, 'caseRelative' => substr($relative, strlen($prefix))] + $entry;
			} elseif (($position = strpos('/' . $relative, $needle)) !== false) {
				$bySegment[] = ['relativePath' => $relative, 'caseRelative' => substr('/' . $relative, $position + strlen($needle))] + $entry;
			}
		}
		return $byPrefix !== [] ? $byPrefix : $bySegment;
	}

	private function belongsToCase(string $path, string $caseNumber): bool {
		$year = CaseKinds::yearOfNumber($caseNumber);
		return $year !== null && str_contains('/' . trim($path, '/') . '/', '/' . $year . '/' . $caseNumber . '/');
	}

	private function safePath(string $path): bool {
		$path = trim($path, '/');
		if ($path === '' || str_contains($path, '\\')) {
			return false;
		}
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				return false;
			}
		}
		return true;
	}

	private function nodeAt(string $uid, string $relative): ?Node {
		$node = $this->rootFolder->getUserFolder($uid);
		foreach (explode('/', trim($relative, '/')) as $segment) {
			if (!$node instanceof Folder || !$node->nodeExists($segment)) {
				return null;
			}
			$node = $node->get($segment);
		}
		return $node;
	}

	/**
	 * @param list<Folder> $created neu angelegte Ordner (für das Aufräumen bei einem Fehler)
	 * @return array{0:Folder,1:string}
	 */
	private function ensureFolder(string $uid, string $relative, array &$created): array {
		$segments = explode('/', trim($relative, '/'));
		$name = (string)array_pop($segments);
		$folder = $this->rootFolder->getUserFolder($uid);
		foreach ($segments as $segment) {
			if ($folder->nodeExists($segment)) {
				$node = $folder->get($segment);
				if (!$node instanceof Folder) {
					throw new \RuntimeException('Am Ziel liegt eine Datei, wo ein Ordner erwartet wird: ' . $relative);
				}
				$folder = $node;
				continue;
			}
			$folder = $folder->newFolder($segment);
			$created[] = $folder;
		}
		return [$folder, $name];
	}

	/** @return list<string> Schlüssel, unter denen eine Datei in den Falldaten stehen kann */
	private function pathKeys(string $relative, string $caseRelative): array {
		$keys = [FileReferences::normalizePath($relative)];
		if (FileReferences::normalizePath($caseRelative) !== '') {
			$keys[] = FileReferences::normalizePath($caseRelative);
		}
		return $keys;
	}

	/** @param array<int,int> $idMap @param array<string,int> $pathMap @param array<string,int> $stats */
	private function prepareRow(string $table, array $row, array $idMap, array $pathMap, array &$stats): array {
		if ($table === 'bestatter_cases' && (int)($row['origin_case_id'] ?? 0) > 0 && !$this->exists('bestatter_cases', 'id', (int)$row['origin_case_id'])) {
			$row['origin_case_id'] = null;
		}
		if ($table === 'bestatter_precare' && (int)($row['redeemed_case_id'] ?? 0) > 0 && !$this->exists('bestatter_cases', 'id', (int)$row['redeemed_case_id'])) {
			$row['redeemed_case_id'] = null;
		}
		if ($table === 'bestatter_integration_jobs' && in_array(strtoupper((string)($row['job_status'] ?? '')), self::OPEN_JOB_STATES, true)) {
			$row['job_status'] = 'FAILED';
			$row['last_error'] = 'Nach Wiederherstellung des Falls nicht erneut ausgeführt.';
			$stats['jobsStopped']++;
		}
		// Datei-IDs: über die im Manifest gesicherte ID, sonst über den Pfad neben dem Verweis (Pakete vor 0.92.6).
		$resolve = static fn (int $old, ?string $path): ?int => $idMap[$old] ?? ($path !== null ? ($pathMap[FileReferences::normalizePath($path)] ?? null) : null);
		$changed = 0;
		$row = FileReferences::rewriteRow($row, $resolve, $changed);
		$stats['fileIdsMapped'] += $changed;
		if ($table === 'bestatter_records' && $this->needsResync($row)) {
			$payload = json_decode((string)$row['payload'], true);
			$row['payload'] = json_encode($this->markForResync(is_array($payload) ? $payload : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			$stats['calendarPlanned']++;
		}
		return $row;
	}

	private function needsResync(array $row): bool {
		if (!in_array((string)($row['record_type'] ?? ''), ['task', 'schedule'], true)) {
			return false;
		}
		$payload = json_decode((string)($row['payload'] ?? ''), true);
		$nextcloud = is_array($payload) && is_array($payload['nextcloud'] ?? null) ? $payload['nextcloud'] : [];
		return (string)($nextcloud['uid'] ?? '') !== '' && (string)($nextcloud['remoteDeletedAt'] ?? '') === '';
	}

	/** Kalenderobjekte wurden beim Löschen entfernt: als „nicht übertragen“ markieren, der Abgleich legt sie neu an. */
	private function markForResync(array $payload): array {
		$payload['nextcloud']['stored'] = false;
		$payload['nextcloud']['syncError'] = self::RESYNC_NOTE;
		unset($payload['nextcloud']['lastRetryAt']);
		foreach ((array)($payload['nextcloudCopies'] ?? []) as $index => $copy) {
			if (is_array($copy)) {
				$payload['nextcloudCopies'][$index]['stored'] = false;
			}
		}
		return $payload;
	}

	private function insert(string $table, array $row): void {
		$query = $this->db->getQueryBuilder();
		$values = [];
		foreach ($row as $column => $value) {
			$values[(string)$column] = $value === null ? $query->createNamedParameter(null, IQueryBuilder::PARAM_NULL) : $query->createNamedParameter($value);
		}
		$query->insert($table)->values($values)->executeStatement();
	}

	/** Verweise anderer Akten auf diesen Fall, die „Fall löschen“ entfernt hat, wieder setzen. */
	private function relink(int $caseId, array $links): int {
		$count = 0;
		foreach ($links['derived'] ?? [] as $derived) {
			$query = $this->db->getQueryBuilder();
			$count += $query->update('bestatter_cases')->set('origin_case_id', $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT))
				->where($query->expr()->eq('id', $query->createNamedParameter((int)$derived['id'], IQueryBuilder::PARAM_INT)))
				->andWhere($query->expr()->isNull('origin_case_id'))->executeStatement();
			$this->audit->log((int)$derived['id'], 'CASE', (int)$derived['id'], 'ORIGIN_RELINKED', ['originCaseId' => 0], ['originCaseId' => $caseId]);
		}
		foreach ($links['redeemedBy'] ?? [] as $precare) {
			$query = $this->db->getQueryBuilder();
			$count += $query->update('bestatter_precare')->set('redeemed_case_id', $query->createNamedParameter($caseId, IQueryBuilder::PARAM_INT))
				->where($query->expr()->eq('case_id', $query->createNamedParameter((int)$precare['caseId'], IQueryBuilder::PARAM_INT)))
				->andWhere($query->expr()->isNull('redeemed_case_id'))->executeStatement();
			$this->audit->log((int)$precare['caseId'], 'PRECARE', (int)$precare['caseId'], 'REDEEMED_CASE_RELINKED', ['redeemedCaseId' => 0], ['redeemedCaseId' => $caseId]);
		}
		return $count;
	}

	/**
	 * Protokolleinträge des Falls, deren Inhalt beim Löschen geleert wurde und die unverändert in der Kette stehen
	 * (gleiche ID, gleicher Hash). Nur dann kommt der Inhalt aus der Sicherung zurück.
	 * @return list<array{id:int,before:mixed,after:mixed}>
	 */
	private function restorableAuditRows(array $snapshotRows, int $caseId): array {
		$wanted = [];
		foreach ($snapshotRows as $row) {
			if (is_array($row) && (int)($row['case_id'] ?? 0) === $caseId && (($row['before_data'] ?? null) !== null || ($row['after_data'] ?? null) !== null)) {
				$wanted[(int)$row['id']] = $row;
			}
		}
		if ($wanted === []) {
			return [];
		}
		$result = [];
		foreach (array_chunk(array_keys($wanted), 500) as $ids) {
			$query = $this->db->getQueryBuilder();
			$current = $query->select('id', 'case_id', 'event_hash', 'before_data', 'after_data')->from('bestatter_audit_log')
				->where($query->expr()->in('id', $query->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))->executeQuery()->fetchAllAssociative();
			foreach ($current as $row) {
				$saved = $wanted[(int)$row['id']] ?? null;
				if ($saved === null || (int)$row['case_id'] !== $caseId || (string)$row['event_hash'] !== (string)($saved['event_hash'] ?? '')
					|| $row['before_data'] !== null || $row['after_data'] !== null) {
					continue;
				}
				$result[] = ['id' => (int)$row['id'], 'before' => $saved['before_data'] ?? null, 'after' => $saved['after_data'] ?? null];
			}
		}
		return $result;
	}

	private function restoreAuditContent(array $rows): int {
		$count = 0;
		foreach ($rows as $row) {
			$query = $this->db->getQueryBuilder();
			$count += $query->update('bestatter_audit_log')
				->set('before_data', $row['before'] === null ? $query->createNamedParameter(null, IQueryBuilder::PARAM_NULL) : $query->createNamedParameter((string)$row['before']))
				->set('after_data', $row['after'] === null ? $query->createNamedParameter(null, IQueryBuilder::PARAM_NULL) : $query->createNamedParameter((string)$row['after']))
				->where($query->expr()->eq('id', $query->createNamedParameter($row['id'], IQueryBuilder::PARAM_INT)))
				->andWhere($query->expr()->isNull('before_data'))->andWhere($query->expr()->isNull('after_data'))
				->executeStatement();
		}
		return $count;
	}

	/** @return array<int,array{at:string,by:string}> Zeitpunkt und Person von „Fall löschen“ je Fall-ID */
	private function deletionEvents(): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('created_at', 'user_uid', 'after_data')->from('bestatter_audit_log')
			->where($query->expr()->eq('case_id', $query->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('action', $query->createNamedParameter('DELETED')))
			->andWhere($query->expr()->in('object_type', $query->createNamedParameter(['CASE', 'RETENTION'], IQueryBuilder::PARAM_STR_ARRAY)))
			->executeQuery()->fetchAllAssociative();
		$events = [];
		foreach ($rows as $row) {
			$data = json_decode((string)($row['after_data'] ?? ''), true);
			if (is_array($data) && (int)($data['caseId'] ?? 0) > 0) {
				$events[(int)$data['caseId']] = ['at' => (string)$row['created_at'], 'by' => (string)($row['user_uid'] ?? '')];
			}
		}
		return $events;
	}

	private function caseName(array $row): string {
		return trim(trim((string)($row['first_name'] ?? '')) . ' ' . trim((string)($row['last_name'] ?? '')));
	}

	private function exists(string $table, string $column, int|string $value): bool {
		$query = $this->db->getQueryBuilder();
		$parameter = is_int($value) ? $query->createNamedParameter($value, IQueryBuilder::PARAM_INT) : $query->createNamedParameter($value);
		return $query->select('id')->from($table)->where($query->expr()->eq($column, $parameter))->setMaxResults(1)->executeQuery()->fetchOne() !== false;
	}

	private function currentlyUnlinked(string $table, string $keyColumn, int $key, string $linkColumn): bool {
		$query = $this->db->getQueryBuilder();
		$value = $query->select($linkColumn)->from($table)->where($query->expr()->eq($keyColumn, $query->createNamedParameter($key, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1)->executeQuery()->fetchOne();
		return $value !== false && ($value === null || (int)$value === 0);
	}

	/** @param list<int> $ids @return list<int> */
	private function existingIds(string $table, array $ids): array {
		$taken = [];
		foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
			$query = $this->db->getQueryBuilder();
			$found = $query->select('id')->from($table)->where($query->expr()->in('id', $query->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeQuery()->fetchFirstColumn();
			$taken = [...$taken, ...array_map('intval', $found)];
		}
		sort($taken);
		return $taken;
	}
}
