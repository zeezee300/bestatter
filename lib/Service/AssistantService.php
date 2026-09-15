<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\AudioToText;
use OCP\TaskProcessing\TaskTypes\TextToText;

/**
 * Provider-neutral foundation for guided capture, speech input and safe intent previews.
 * This service never writes case business data. Confirmed changes continue to use the
 * existing case/record/document services and their validation and audit rules.
 */
class AssistantService {
	private const AUDIO_LIMIT = 25_000_000;
	private const TEXT_LIMIT = 12_000;
	private const AUDIO_MIME_TYPES = [
		'audio/webm', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/mpeg',
		'audio/mp4', 'audio/m4a', 'audio/x-m4a', 'audio/aac', 'audio/x-aac',
		'video/webm', 'application/ogg',
	];

	public function __construct(
		private IConfig $config,
		private IManager $tasks,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private CaseService $cases,
		private GroupwareService $groupware,
		private WorkflowService $workflows,
		private RecordService $records,
		private DocumentService $documents,
		private ConfigurationService $configuration,
		private ContactService $contacts,
		private AssistantResearchService $research,
		private OperationalService $operations,
		private AuditService $audit,
		private IDBConnection $db,
		private InstallationConfigService $installationConfig,
	) {}

	public function configuration(): array {
		$available = $this->availableTaskTypes();
		return [
			'guidedCaptureEnabled' => $this->flag('guided_capture_enabled', true),
			'speechInputEnabled' => $this->flag('speech_input_enabled', true),
			'assistantEnabled' => $this->flag('assistant_enabled', true),
			'webResearchEnabled' => $this->flag('assistant_web_research_enabled', false),
			'externalAiProvidersAllowed' => $this->flag('external_ai_providers_allowed', false),
			'speechProviderApproved' => $this->flag('speech_provider_approved', false),
			'audioRetention' => $this->config->getAppValue(Application::APP_ID, 'assistant_audio_retention', 'DELETE_AFTER_TRANSCRIPTION'),
			'language' => $this->config->getAppValue(Application::APP_ID, 'assistant_language', 'de'),
			'providers' => [
				'speechToText' => in_array(AudioToText::ID, $available, true),
				'textToText' => in_array(TextToText::ID, $available, true),
			],
			'availableTaskTypes' => array_values(array_intersect($available, [AudioToText::ID, TextToText::ID])),
			'limits' => ['audioBytes' => self::AUDIO_LIMIT, 'textCharacters' => self::TEXT_LIMIT],
		];
	}

	/** Fachlich begrenzter Aktionskatalog des regelbasierten Assistenten. */
	public function catalog(): array {
		return [
			['key' => 'DAY', 'label' => 'Mein Arbeitstag', 'example' => 'Was ist heute fällig?', 'writeOperation' => false],
			['key' => 'CASE_SEARCH', 'label' => 'Fall suchen', 'example' => 'Suche Fall Meier', 'writeOperation' => false],
			['key' => 'COMPLETENESS', 'label' => 'Vollständigkeit prüfen', 'example' => 'Welche Angaben fehlen in diesem Fall?', 'writeOperation' => false],
			['key' => 'MASTER_DATA', 'label' => 'Stammdaten ergänzen', 'example' => 'Öffne die Stammdaten dieses Falls', 'writeOperation' => false],
			['key' => 'UPDATE_CASE_MASTER_DATA', 'label' => 'Falldaten aus Notizen ergänzen', 'example' => 'Befülle den Sterbefall mit diesen Daten: …', 'writeOperation' => true],
			['key' => 'TASK', 'label' => 'Aufgabe vorbereiten', 'example' => 'Lege eine Aufgabe Rückruf Angehörige an', 'writeOperation' => true],
			['key' => 'TASK_BATCH', 'label' => 'Mehrere Aufgaben aus Notizen vorbereiten', 'example' => 'Erstelle aus diesen Notizen Aufgaben zum Sterbefall: …', 'writeOperation' => true],
			['key' => 'SCHEDULE', 'label' => 'Termin vorbereiten', 'example' => 'Lege einen Termin Trauerfeier am 15.09.2026 um 11:00 an', 'writeOperation' => true],
			['key' => 'DOCUMENT', 'label' => 'Dokument erzeugen oder öffnen', 'example' => 'Erzeuge die Rundfunkbeitrag-Abmeldung', 'writeOperation' => true],
			['key' => 'DEREGISTRATION', 'label' => 'Abmeldung vorbereiten', 'example' => 'Bereite eine Abmeldung vor', 'writeOperation' => false],
			['key' => 'SERVICES', 'label' => 'Leistungen und Auftrag', 'example' => 'Öffne die Leistungen dieses Falls', 'writeOperation' => false],
			['key' => 'INVOICE', 'label' => 'Teil- oder Schlussrechnung vorbereiten', 'example' => 'Prüfe die Schlussrechnung', 'writeOperation' => false],
			['key' => 'SYSTEM', 'label' => 'Systemprüfung erklären', 'example' => 'Öffne die Systemprüfung', 'writeOperation' => false],
		];
	}

	public function saveConfiguration(array $data): array {
		foreach (['guidedCaptureEnabled' => 'guided_capture_enabled', 'speechInputEnabled' => 'speech_input_enabled', 'assistantEnabled' => 'assistant_enabled', 'webResearchEnabled' => 'assistant_web_research_enabled', 'externalAiProvidersAllowed' => 'external_ai_providers_allowed', 'speechProviderApproved' => 'speech_provider_approved'] as $input => $key) {
			if (array_key_exists($input, $data)) $this->config->setAppValue(Application::APP_ID, $key, filter_var($data[$input], FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no');
		}
		$retention = strtoupper(trim((string)($data['audioRetention'] ?? 'DELETE_AFTER_TRANSCRIPTION')));
		if ($retention !== 'DELETE_AFTER_TRANSCRIPTION') throw new \InvalidArgumentException('Audioaufnahmen müssen im aktuellen Entwicklungsstand unmittelbar nach der Transkription gelöscht werden.');
		$language = strtolower(trim((string)($data['language'] ?? 'de')));
		if (!preg_match('/^[a-z]{2,3}(?:-[a-z]{2})?$/', $language)) throw new \InvalidArgumentException('Der Sprachcode ist ungültig.');
		$this->config->setAppValue(Application::APP_ID, 'assistant_audio_retention', $retention);
		$this->config->setAppValue(Application::APP_ID, 'assistant_language', $language);
		return $this->configuration();
	}

	public function analyze(string $text, string $context = 'CASE_CAPTURE'): array {
		$context = strtoupper(trim($context));
		if (!in_array($context, ['CASE_CAPTURE', 'OCR_FORM', 'TASK', 'SCHEDULE', 'NOTE'], true)) throw new \InvalidArgumentException('Der Erfassungskontext ist ungültig.');
		$text = $context === 'OCR_FORM'
			? trim(preg_replace('/[ \t]+/u', ' ', str_replace(["\r\n", "\r"], "\n", $text)) ?? $text)
			: trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
		if ($text === '') throw new \InvalidArgumentException('Bitte einen Gesprächstext eingeben oder eine Aufnahme transkribieren.');
		if (mb_strlen($text) > self::TEXT_LIMIT) throw new \InvalidArgumentException('Der Gesprächstext ist für eine einzelne Auswertung zu lang.');

		$suggestions = [];
		if ($context === 'OCR_FORM') $this->suggestFormLabels($text, $suggestions);
		$this->suggestPersonalDetails($text, $suggestions);
		$this->suggestName($text, $suggestions);
		$this->suggestDate($text, $suggestions, 'date_of_birth', '/(?:\bgeboren\s+(?:am\s+)?|\b(?:wurde\s+)?am\s+)(\d{1,2}[.]\d{1,2}[.]\d{2,4})(?:\s+geboren)?/iu', 'Geburtsdatum');
		$this->suggestDate($text, $suggestions, 'date_of_birth', '/(?:geboren|geb\.)\s+(?:am\s+)?([^,.;]+)/iu', 'Geburtsdatum');
		$this->suggestDate($text, $suggestions, 'date_of_death', '/(?:verstorben|gestorben)\s+(?:am\s+)?([^,.;]+)/iu', 'Sterbedatum');
		$this->suggestDate($text, $suggestions, 'date_of_death', '/\b(?:sie|er|die\s+person|der\s+verstorbene|die\s+verstorbene)\s+ist\s+am\s+(\d{1,2}[.]\d{1,2}[.]\d{2,4})\s+(?:verstorben|gestorben)\b/iu', 'Sterbedatum');
		$this->suggestValue($text, $suggestions, 'place_of_death', '/(?:verstorben|gestorben)\s+(?:am\s+)?[^,.;]+\s+(?:im|in der|in)\s+([^,.;]+)/iu', 'Sterbeort / Einrichtung', .72);
		$this->suggestValue($text, $suggestions, 'place_of_death', '/\b(?:verstorben|gestorben)\s+(?:und\s+zwar\s+)?(?:im|in\s+der|in)\s+([^,.;]+)/iu', 'Sterbeort / Einrichtung', .9);
		$this->suggestValue($text, $suggestions, 'birth_place', '/\bgeboren\s+(?:worden\s+)?in\s+(.+?)(?=\s+und\b|[,.;]|$)/iu', 'Geburtsort', .9);
		$this->suggestValue($text, $suggestions, 'birth_place', '/\bgeboren\s+(?:am\s+)?\d{1,2}[.]\d{1,2}[.]\d{2,4}\s+in\s+(.+?)(?=\s+und\b|[,.;]|$)/iu', 'Geburtsort', .92);
		$this->suggestValue($text, $suggestions, 'last_residence_city', '/(?:wohnhaft|zuletzt wohnhaft)\s+(?:in|unter)\s+([^,.;]+)/iu', 'Letzter Wohnort', .68);
		$this->suggestResidence($text, $suggestions);
		$this->suggestCemetery($text, $suggestions);
		$this->suggestAdministrativeDetails($text, $suggestions);
		$this->suggestClientDetails($text, $suggestions);
		$this->suggestCertificateCounts($text, $suggestions);
		if (preg_match('/\bfeuerbestattung\b/iu', $text)) $this->add($suggestions, 'funeral_type', 'FEUERBESTATTUNG', 'Bestattungsart', .98, 'Feuerbestattung');
		elseif (preg_match('/\berdbestattung\b/iu', $text)) $this->add($suggestions, 'funeral_type', 'ERDBESTATTUNG', 'Bestattungsart', .98, 'Erdbestattung');
		elseif (preg_match('/\büberführung\b/iu', $text)) $this->add($suggestions, 'funeral_type', 'UEBERFUEHRUNG', 'Bestattungsart', .86, 'Überführung');
		$this->applyApprovedRules($text, $suggestions);

		$warnings = [];
		$fields = array_column($suggestions, 'field');
		foreach ([['first_name', 'Vorname'], ['last_name', 'Nachname'], ['date_of_death', 'Sterbedatum'], ['funeral_type', 'Bestattungsart']] as [$field, $label]) {
			if (!in_array($field, $fields, true)) $warnings[] = "$label wurde nicht eindeutig erkannt.";
		}
		foreach ($suggestions as $suggestion) {
			if ($suggestion['field'] === 'spouse_last_name' && (float)$suggestion['confidence'] < .7) {
				$warnings[] = 'Der Nachname des Ehepartners wurde nur aus dem aktuellen Familiennamen der verstorbenen Person abgeleitet und muss ausdrücklich geprüft werden.';
			}
		}

		$conflicts = [];
		foreach ($suggestions as $suggestion) {
			if (empty($suggestion['alternatives'])) continue;
			$conflicts[] = ['field' => $suggestion['field'], 'label' => $suggestion['label'], 'values' => array_values(array_unique(array_merge([$suggestion['value']], $suggestion['alternatives']))), 'question' => 'Welcher Wert ist für „' . $suggestion['label'] . '“ richtig?'];
		}
		$questions = array_map(static fn(array $item): array => ['field' => $item[0], 'label' => $item[1], 'question' => 'Bitte „' . $item[1] . '“ ergänzen oder ausdrücklich als unbekannt bestätigen.'], array_values(array_filter([
			['first_name', 'Vorname'], ['last_name', 'Nachname'], ['date_of_death', 'Sterbedatum'], ['place_of_death', 'Sterbeort'], ['funeral_type', 'Bestattungsart'], ['order_client_name', 'Auftraggeber/in'],
		], static fn(array $item): bool => !in_array($item[0], $fields, true))));

		return [
			'context' => $context,
			'text' => $text,
			'suggestions' => $suggestions,
			'warnings' => $warnings,
			'questions' => $questions,
			'conflicts' => $conflicts,
			'unmatchedText' => $text,
			'engine' => 'BESTATTER_RULES_V2',
			'requiresConfirmation' => true,
		];
	}

	/** Stores only compact correction evidence, never the full transcript. */
	public function submitCorrections(array $corrections): array {
		$allowed = ['salutation','title','first_name','last_name','birth_name','date_of_birth','date_of_death','funeral_type','place_of_death','birth_place','birth_registry_office','profession','pension_insurance_number','civil_status','spouse_first_name','spouse_last_name','spouse_date_of_birth','spouse_birth_place','spouse_residence','spouse_date_of_death','spouse_death_place','marriage_date','marriage_place','partnership_date','divorce_date','religion','last_residence','last_residence_postal_code','last_residence_city','cemetery_contact','order_client_relation','order_client_first_name','order_client_name','order_client_mobile','certificate_free_count','certificate_paid_count'];
		$created = 0;
		foreach (array_slice($corrections, 0, 40) as $correction) {
			$field = trim((string)($correction['field'] ?? '')); $source = $this->compactEvidence((string)($correction['source'] ?? ''));
			$observed = trim((string)($correction['observedValue'] ?? '')); $confirmed = trim((string)($correction['confirmedValue'] ?? ''));
			if (!in_array($field, $allowed, true) || $source === '' || $confirmed === '' || $observed === $confirmed) continue;
			$strategy = in_array($field, ['civil_status','funeral_type','religion','salutation'], true) ? 'ENUM' : 'REVIEW_ONLY';
			$pattern = mb_strtolower($source);
			foreach (array_filter([$observed, $confirmed]) as $value) $pattern = str_ireplace(mb_strtolower($value), '{VALUE}', $pattern);
			$query = $this->db->getQueryBuilder();
			try {
				$query->insert('bestatter_assistant_rules')->values(['field_key'=>$query->createNamedParameter($field),'trigger_pattern'=>$query->createNamedParameter(mb_substr($pattern,0,255)),'strategy'=>$query->createNamedParameter($strategy),'canonical_value'=>$query->createNamedParameter($strategy==='ENUM'?$confirmed:null),'status'=>$query->createNamedParameter('PENDING'),'usage_count'=>$query->createNamedParameter(0),'created_by'=>$query->createNamedParameter($this->userSession->getUser()?->getUID()??'system'),'approved_by'=>$query->createNamedParameter(null),'created_at'=>$query->createNamedParameter(date('c')),'updated_at'=>$query->createNamedParameter(date('c'))])->executeStatement();
				$created++;
			} catch (\Throwable) { /* Duplicate evidence remains one review item. */ }
		}
		return ['accepted' => $created, 'status' => 'PENDING_REVIEW', 'privacy' => 'Vollständige Gesprächsprotokolle und Falldaten werden nicht als Lernregel gespeichert.'];
	}

	public function learningRules(): array {
		$query = $this->db->getQueryBuilder();
		$rows = $query->select('*')->from('bestatter_assistant_rules')->orderBy('created_at', 'DESC')->setMaxResults(250)->executeQuery()->fetchAllAssociative();
		return array_map(static fn(array $row): array => ['id'=>(int)$row['id'],'field'=>$row['field_key'],'pattern'=>$row['trigger_pattern'],'strategy'=>$row['strategy'],'canonicalValue'=>$row['canonical_value'],'status'=>$row['status'],'usageCount'=>(int)$row['usage_count'],'createdBy'=>$row['created_by'],'approvedBy'=>$row['approved_by'],'createdAt'=>$row['created_at']], $rows);
	}

	public function reviewLearningRule(int $id, string $status): array {
		$status = strtoupper(trim($status)); if (!in_array($status, ['APPROVED','REJECTED'], true)) throw new \InvalidArgumentException('Ungültiger Prüfstatus für die Lernregel.');
		$query = $this->db->getQueryBuilder();
		$affected = $query->update('bestatter_assistant_rules')->set('status',$query->createNamedParameter($status))->set('approved_by',$query->createNamedParameter($this->userSession->getUser()?->getUID()??'system'))->set('updated_at',$query->createNamedParameter(date('c')))->where($query->expr()->eq('id',$query->createNamedParameter($id)))->executeStatement();
		if ($affected !== 1) throw new \InvalidArgumentException('Die Lernregel wurde nicht gefunden.');
		foreach ($this->learningRules() as $rule) if ($rule['id'] === $id) return $rule;
		throw new \RuntimeException('Die Lernregel konnte nicht erneut geladen werden.');
	}

	public function scheduleTranscription(array $upload): array {
		$settings = $this->configuration();
		if (!$settings['speechInputEnabled']) throw new \InvalidArgumentException('Die Spracheingabe ist administrativ deaktiviert.');
		if (!$settings['providers']['speechToText']) throw new \InvalidArgumentException('Es ist noch kein Speech-to-Text-Provider in Nextcloud verfügbar.');
		if (!$settings['speechProviderApproved']) throw new \InvalidArgumentException('Der konfigurierte Speech-to-Text-Provider wurde für sensible Falldaten noch nicht administrativ freigegeben.');
		if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new \InvalidArgumentException('Die Audioaufnahme konnte nicht hochgeladen werden.');
		$size = (int)($upload['size'] ?? 0);
		if ($size <= 0 || $size > self::AUDIO_LIMIT) throw new \InvalidArgumentException('Die Audioaufnahme ist leer oder größer als 25 MB.');
		$mime = strtolower(trim(explode(';', (string)($upload['type'] ?? ''), 2)[0]));
		if ($mime !== '' && !in_array($mime, self::AUDIO_MIME_TYPES, true)) {
			throw new \InvalidArgumentException(sprintf('Das Audioformat "%s" wird nicht unterstützt. Unterstützt werden WebM, OGG, WAV, MP3, M4A und AAC.', $mime));
		}
		$tempName = (string)($upload['tmp_name'] ?? '');
		if ($tempName === '' || !is_file($tempName)) throw new \InvalidArgumentException('Die temporäre Audioaufnahme wurde nicht gefunden.');

		$user = $this->userSession->getUser();
		if ($user === null) throw new \RuntimeException('Kein Nextcloud-Benutzer angemeldet.');
		$folder = $this->assistantFolder($user->getUID());
		$extension = $this->extension($mime, (string)($upload['name'] ?? 'aufnahme.webm'));
		$name = 'aufnahme-' . bin2hex(random_bytes(12)) . '.' . $extension;
		$file = $folder->newFile($name);
		$file->putContent((string)file_get_contents($tempName));

		try {
			$task = new Task(AudioToText::ID, ['input' => $file->getId()], Application::APP_ID, $user->getUID(), 'speech:' . $file->getId());
			$this->tasks->scheduleTask($task);
			return [
				'taskId' => $task->getId(),
				'status' => 'SCHEDULED',
				'fileId' => $file->getId(),
				'completionExpectedAt' => $task->getCompletionExpectedAt()?->format(DATE_ATOM),
			];
		} catch (\Throwable $error) {
			$file->delete();
			throw new \RuntimeException('Die Transkription konnte nicht gestartet werden.', 0, $error);
		}
	}

	public function transcriptionStatus(int $taskId): array {
		if ($taskId <= 0) throw new \InvalidArgumentException('Ungültige Transkriptionskennung.');
		$task = $this->tasks->getTask($taskId);
		$userId = $this->userSession->getUser()?->getUID();
		if ($task->getAppId() !== Application::APP_ID || $userId === null || $task->getUserId() !== $userId || !str_starts_with($task->getCustomId(), 'speech:')) {
			throw new \InvalidArgumentException('Die Transkription wurde nicht gefunden.');
		}
		$status = $this->statusName($task->getStatus());
		$result = [
			'taskId' => $taskId,
			'status' => $status,
			'progress' => $task->getProgress(),
			'transcript' => null,
			'message' => null,
		];
		if ($task->getStatus() === Task::STATUS_SUCCESSFUL) {
			$result['transcript'] = trim((string)(($task->getOutput() ?? [])['output'] ?? ''));
			if ($result['transcript'] === '') $result['message'] = 'Die Aufnahme enthielt keinen erkennbaren Text.';
			$this->cleanupTaskAudio($task->getCustomId(), $userId);
		} elseif ($task->getStatus() === Task::STATUS_FAILED) {
			$result['message'] = $task->getUserFacingErrorMessage() ?: 'Die Aufnahme konnte nicht transkribiert werden.';
			$this->cleanupTaskAudio($task->getCustomId(), $userId);
		} elseif ($task->getStatus() === Task::STATUS_CANCELLED) {
			$result['message'] = 'Die Transkription wurde abgebrochen.';
			$this->cleanupTaskAudio($task->getCustomId(), $userId);
		}
		return $result;
	}

	public function previewIntent(string $input, int $caseId = 0): array {
		$rawInput = trim($input);
		$input = trim(preg_replace('/\s+/u', ' ', $input) ?? $input);
		if ($input === '') throw new \InvalidArgumentException('Bitte einen Arbeitsauftrag an den Assistenten eingeben.');
		if (mb_strlen($input) > 2_000) throw new \InvalidArgumentException('Der Arbeitsauftrag ist zu lang.');

		$intent = 'INFORMATION';
		$label = 'Information anzeigen';
		$data = ['query' => $input];
		$write = false;
		$answer = null;
		$choices = [];
		$documents = [];
		$requiredInput = null;
		if (preg_match('/\b(?:suche|finde|öffne|zeige)\w*\s+(?:mir\s+)?(?:den\s+)?fall\b/iu', $input)) {
			$intent = 'FIND_CASE'; $label = 'Fall suchen und öffnen';
			$query = trim((string)preg_replace('/^.*?\bfall\b/iu', '', $input), " \t\n\r\0\x0B,.;");
			$matches = $this->cases->searchCases($query, 'ALL', 'ALL', 'ALL', 12, 0);
			foreach ($matches['items'] as $match) {
				$choices[] = [
					'id' => 'case-' . $match['id'], 'kind' => 'navigation',
					'label' => $match['caseNumber'] . ' · ' . trim($match['firstName'] . ' ' . $match['lastName']),
					'description' => 'Fallakte öffnen',
					'confirmationToken' => $this->confirmationToken('NAVIGATE', ['view' => 'case-detail', 'caseId' => (int)$match['id'], 'caseTab' => 'overview']),
				];
			}
			$answer = ['title' => 'Fallsuche', 'text' => $choices === [] ? 'Es wurde kein passender Fall gefunden.' : $matches['total'] . ' passende Fälle gefunden.', 'items' => []];
			$data = ['query' => $query];
		} elseif ($this->isOrganizationResearchRequest($input)) {
			$intent = 'RESEARCH_ORGANIZATION'; $label = 'Organisation recherchieren und Kontakt vorbereiten'; $write = true;
			$query = $this->organizationQuery($input);
			if (!preg_match('/\b(in|aus|bei)\s+[\p{L}][\p{L}\s.-]{1,80}$/iu', $query)) {
				$requiredInput = ['field' => 'location', 'question' => 'Für welchen Ort oder Bezirk soll die Organisation recherchiert werden?'];
				$answer = ['title' => 'Ort fehlt', 'text' => $requiredInput['question'], 'items' => []];
			} else {
				$results = $this->research->searchOrganizations($query);
				foreach ($results as $index => $candidate) {
					$contactData = $candidate;
					unset($contactData['duplicates'], $contactData['displayName']);
					$choices[] = [
						'id' => 'contact-' . ($index + 1), 'label' => $candidate['title'],
						'description' => $candidate['displayName'], 'data' => $candidate,
						'confirmationToken' => $this->confirmationToken('CREATE_CONTACT', ['caseId' => $caseId, 'title' => $candidate['title'], 'contact' => $contactData]),
					];
				}
				$answer = ['title' => 'Rechercheergebnisse', 'text' => $choices === [] ? 'Es wurde kein passender Eintrag gefunden. Bitte Organisation und Ort genauer angeben.' : 'Bitte genau einen Treffer prüfen. Webdaten werden erst nach Ihrer Bestätigung als Kontakt gespeichert.', 'items' => []];
			}
			$data = ['query' => $query];
		} elseif ($this->isDocumentOutputRequest($input)) {
			$intent = 'PREPARE_DOCUMENT_OUTPUT'; $label = 'Dokumentausgabe prüfen, erzeugen oder öffnen'; $write = true;
			$preparation = $this->prepareDocumentOutput($caseId, $input);
			$intent = $preparation['intent']; $data = $preparation['data']; $answer = $preparation['answer']; $documents = $preparation['documents']; $requiredInput = $preparation['requiredInput'];
			$choices = $preparation['choices'] ?? [];
		} elseif ($this->isCaseUpdateRequest($input)) {
			$intent = 'UPDATE_CASE_MASTER_DATA'; $label = 'Falldaten aus Notizen ergänzen'; $write = true;
			$prepared = $this->prepareCaseUpdate($caseId, $input);
			$data = $prepared['data']; $answer = $prepared['answer']; $requiredInput = $prepared['requiredInput'];
		} elseif ($this->isTaskBatchRequest($input)) {
			$intent = 'CREATE_TASK_BATCH'; $label = 'Aufgaben aus Notizen vorbereiten'; $write = true;
			$prepared = $this->prepareTaskBatch($caseId, $rawInput);
			$data = $prepared['data']; $answer = $prepared['answer']; $requiredInput = $prepared['requiredInput'];
		} elseif (preg_match('/\b(aufgabe|wiedervorlage)\b/iu', $input)) {
			$intent = 'CREATE_TASK_DRAFT'; $label = 'Aufgabenentwurf vorbereiten'; $write = true;
			$data = ['caseId' => $caseId, 'title' => $this->commandTitle($input, ['aufgabe', 'anlegen', 'erstelle', 'bitte']), 'status' => 'OFFEN'];
		} elseif (preg_match('/\b(termin|trauerfeier|beisetzung|aufbahrung)\b/iu', $input)) {
			$intent = 'CREATE_SCHEDULE_DRAFT'; $label = 'Terminentwurf vorbereiten'; $write = true;
			$data = ['caseId' => $caseId, 'title' => $this->commandTitle($input, ['termin', 'anlegen', 'erstelle', 'bitte']), 'date' => $this->intentDate($input), 'status' => 'OFFEN'];
		} elseif (preg_match('/\b(fehlt|pflichtangaben|vollständig|offen)\b/iu', $input)) {
			$intent = 'CHECK_MISSING_FIELDS'; $label = 'Fehlende Angaben prüfen';
			$data = ['caseId' => $caseId, 'scope' => preg_match('/rechnung/iu', $input) ? 'INVOICE' : 'CASE'];
		} elseif (preg_match('/\b(stammdaten|personendaten|falldaten)\b/iu', $input)) {
			[$intent, $label, $answer, $choices] = $this->navigationPreview($caseId, 'master', 'Stammdaten öffnen', 'Stammdaten können dort geprüft und ergänzt werden.');
		} elseif (preg_match('/\b(abmeldung|abmeldungen)\b/iu', $input)) {
			[$intent, $label, $answer, $choices] = $this->navigationPreview($caseId, 'deregistration', 'Abmeldungen vorbereiten', 'Vorlagen, Empfänger, Übertragungsweg und Anlagen werden vor der Finalisierung geprüft.');
		} elseif (preg_match('/\b(checkliste|checklisten)\b/iu', $input)) {
			[$intent, $label, $answer, $choices] = $this->navigationPreview($caseId, 'task', 'Checklisten und Aufgaben öffnen', 'Vorgegebene Checklisten können ausgewählt und als Aufgaben angelegt werden.');
		} elseif (preg_match('/\b(leistung|leistungen|artikel|kva|kostenvoranschlag|auftrag)\b/iu', $input)) {
			$tab = preg_match('/\b(kva|kostenvoranschlag|auftrag)\b/iu', $input) ? 'order' : 'services';
			[$intent, $label, $answer, $choices] = $this->navigationPreview($caseId, $tab, 'Auftrag und Leistungen öffnen', 'Der Assistent führt zur fachlichen Auswahl; Paket- und Exklusivregeln bleiben wirksam.');
		} elseif (preg_match('/\b(teilrechnung|schlussrechnung|rechnung|abrechnung|finanzen)\b/iu', $input)) {
			[$intent, $label, $answer, $choices] = $this->navigationPreview($caseId, 'finances', 'Abrechnung öffnen', 'Vor der Erstellung werden Leistungen, Rechnungsempfänger und die gewählte Rechnungsart geprüft.');
		} elseif (preg_match('/\b(systemprüfung|systemcheck|systemfehler)\b/iu', $input)) {
			$intent = 'NAVIGATE'; $label = 'Systemprüfung öffnen';
			$answer = ['title' => 'Systemprüfung', 'text' => 'Die technische Prüfung zeigt Ursache, Auswirkung und – soweit möglich – einen direkten Absprung zur zuständigen Konfiguration.', 'items' => []];
			$choices[] = ['id' => 'system-check', 'kind' => 'navigation', 'label' => 'Administration → Systemprüfung', 'description' => 'Systemprüfung jetzt öffnen', 'confirmationToken' => $this->confirmationToken('NAVIGATE', ['view' => 'administration', 'administrationTab' => 'system'])];
		}

		$executable = $write && $caseId > 0 && in_array($intent, ['CREATE_TASK_DRAFT', 'CREATE_TASK_BATCH', 'CREATE_SCHEDULE_DRAFT', 'UPDATE_CASE_MASTER_DATA', 'EXECUTE_WORKFLOW_ACTION', 'GENERATE_DOCUMENT_TEMPLATE'], true)
			&& ($intent !== 'CREATE_SCHEDULE_DRAFT' || ($data['date'] ?? '') !== '') && $requiredInput === null;
		if (!$write) {
			if ($intent === 'CHECK_MISSING_FIELDS') {
				if ($caseId <= 0) {
					$answer = ['title' => 'Fallbezug erforderlich', 'text' => 'Bitte zuerst einen Fall auswählen.', 'items' => []];
				} else {
					$scope = (string)($data['scope'] ?? 'CASE');
					$report = $this->operations->completeness($caseId, $scope === 'INVOICE' ? 'BILLING' : 'ALL');
					$missing = [];
					foreach ($report['phases'] as $phaseReport) foreach ($phaseReport['checks'] as $check) {
						if (!$check['complete']) $missing[] = $phaseReport['label'] . ': ' . $check['label'];
					}
					$answer = ['title' => 'Vollständigkeitsprüfung', 'text' => $report['ready'] ? 'Alle geprüften Angaben und Voraussetzungen sind vollständig.' : $report['percentage'] . ' % vollständig.', 'items' => $missing, 'report' => $report];
				}
			} elseif (preg_match('/\b(heute|arbeitstag|fällig|überfällig|meine aufgaben|meine fälle)\b/iu', $input)) {
				$day = $this->operations->personalDay();
				$answer = [
					'title' => 'Persönliche Tagesübersicht',
					'text' => sprintf('%d offene Fälle, %d Aufgaben heute, %d überfällige Aufgaben und %d Termine heute.', $day['counts']['openCases'], $day['counts']['tasksToday'], $day['counts']['overdueTasks'], $day['counts']['schedulesToday']),
					'items' => array_map(static fn(array $item): string => ($item['caseNumber'] !== '' ? $item['caseNumber'] . ' · ' : '') . $item['title'], array_slice(array_merge($day['overdueTasks'], $day['tasksToday'], $day['schedulesToday']), 0, 12)),
				];
			} else {
				$answer = ['title' => 'Unterstützte Auskünfte', 'text' => 'Ich kann den persönlichen Arbeitstag, offene und überfällige Aufgaben sowie die Vollständigkeit eines Falls oder einer Rechnung prüfen.', 'items' => ['Was ist heute fällig?', 'Welche Aufgaben sind überfällig?', 'Welche Angaben fehlen in diesem Fall?', 'Ist der Fall für die Rechnung vollständig?']];
			}
		}
		$result = [
			'intent' => $intent,
			'label' => $label,
			'data' => $data,
			'writeOperation' => $write,
			'requiresConfirmation' => $write,
			'executable' => $executable,
			'message' => $write
				? ($caseId <= 0 ? 'Der Assistent hat einen Entwurf erkannt. Für die Ausführung muss zuerst ein Fall ausgewählt werden.' : ($intent === 'CREATE_SCHEDULE_DRAFT' && ($data['date'] ?? '') === '' ? 'Der Termin wurde erkannt, aber Datum und Uhrzeit sind nicht eindeutig. Bitte den Auftrag mit einem absoluten Datum und einer Uhrzeit wiederholen.' : 'Der Assistent hat einen Entwurf erkannt. Bitte alle Werte prüfen und die Ausführung ausdrücklich bestätigen.'))
				: (string)($answer['text'] ?? 'Die Anfrage wurde ausgewertet.'),
			'answer' => $answer,
			'choices' => $choices,
			'documents' => $documents,
			'requiredInput' => $requiredInput,
		];
		if ($executable) $result['confirmationToken'] = $this->confirmationToken($intent, $data);
		if ($caseId > 0) $this->audit->log($caseId, 'ASSISTANT', null, $write ? 'INTENT_PREVIEWED' : 'INFORMATION_REQUESTED', null, ['intent' => $intent, 'queryLength' => mb_strlen($input), 'writeOperation' => $write]);
		return $result;
	}

	public function executeIntent(string $confirmationToken): array {
		$payload = $this->verifyConfirmationToken($confirmationToken);
		$intent = (string)$payload['intent'];
		$data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
		$caseId = (int)($data['caseId'] ?? 0);
		if ($intent === 'NAVIGATE') return ['intent' => $intent, 'navigation' => $data, 'caseNumber' => $caseId > 0 ? $this->cases->getCase($caseId)['caseNumber'] : null];
		if ($intent === 'CREATE_CONTACT') {
			$contactData = is_array($data['contact'] ?? null) ? $data['contact'] : [];
			$title = trim((string)($data['title'] ?? ''));
			if ($title === '') throw new \InvalidArgumentException('Die Bezeichnung des Kontakts fehlt.');
			$duplicates = $this->contacts->duplicates($title, $contactData);
			if ($duplicates !== []) throw new \InvalidArgumentException('Ein möglicherweise gleicher Kontakt ist bereits vorhanden. Bitte das Adressbuch prüfen.');
			$contact = $this->contacts->create($title, json_encode($contactData, JSON_THROW_ON_ERROR));
			if ($caseId > 0) $this->audit->log($caseId, 'ASSISTANT', null, 'CONTACT_CREATED', null, ['contactId' => $contact['id'], 'title' => $contact['title'], 'sourceUrl' => $contactData['sourceUrl'] ?? '']);
			return ['intent' => $intent, 'contact' => $contact, 'caseNumber' => $caseId > 0 ? $this->cases->getCase($caseId)['caseNumber'] : null];
		}
		if ($caseId <= 0) throw new \InvalidArgumentException('Für die Aktion ist ein Fall erforderlich.');
		$case = $this->cases->getCase($caseId);
		if ($intent === 'UPDATE_CASE_MASTER_DATA') {
			$changes = is_array($data['changes'] ?? null) ? $data['changes'] : [];
			if ($changes === []) throw new \InvalidArgumentException('Es wurden keine bestätigungsfähigen Falldaten erkannt.');
			$masterData = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
			$masterData = array_replace($masterData, [
				'first_name' => (string)$case['firstName'], 'last_name' => (string)$case['lastName'],
				'date_of_death' => (string)($case['dateOfDeath'] ?? ''), 'funeral_type' => (string)($case['funeralType'] ?? ''),
				'status' => (string)$case['status'], 'branch' => (string)($case['branch'] ?? ''),
				'responsible_employee' => (string)($case['responsibleEmployee'] ?? ''),
			], $changes);
			$updated = $this->cases->updateMasterData($caseId, $masterData);
			$this->audit->log($caseId, 'ASSISTANT', null, 'MASTER_DATA_APPLIED', null, ['fields' => array_keys($changes)]);
			return ['intent' => $intent, 'caseNumber' => $updated['caseNumber'], 'case' => $updated, 'updatedFields' => array_keys($changes)];
		}
		if ($intent === 'CREATE_TASK_BATCH') {
			$tasks = is_array($data['tasks'] ?? null) ? array_slice($data['tasks'], 0, 12) : [];
			$batchId = trim((string)($data['batchId'] ?? ''));
			if ($tasks === [] || $batchId === '') throw new \InvalidArgumentException('Der bestätigte Aufgabenentwurf ist unvollständig.');
			$existingIndexes = [];
			foreach ($this->records->list('task', $caseId) as $existing) {
				$assistant = is_array($existing['data']['assistant'] ?? null) ? $existing['data']['assistant'] : [];
				if (($assistant['batchId'] ?? '') === $batchId) $existingIndexes[(int)($assistant['sourceIndex'] ?? -1)] = $existing;
			}
			$created = [];
			$uid = $this->userSession->getUser()?->getUID() ?? '';
			foreach ($tasks as $index => $task) {
				if (isset($existingIndexes[$index])) { $created[] = $existingIndexes[$index]; continue; }
				$title = trim((string)($task['title'] ?? ''));
				if ($title === '') continue;
				$created[] = $this->groupware->createTask($title, (string)($task['date'] ?? date('Y-m-d') . 'T00:00'), 'OFFEN', json_encode([
					'description' => 'Aus Notizen durch den Bestatter-Assistenten vorbereitet und vom Benutzer ausdrücklich bestätigt.',
					'priority' => 'NORMAL', 'assigneeUid' => $uid, 'assigneeName' => $uid,
					'assistant' => ['confirmed' => true, 'confirmedAt' => date('c'), 'intent' => $intent, 'batchId' => $batchId, 'sourceIndex' => $index],
				], JSON_THROW_ON_ERROR), $caseId);
			}
			$this->audit->log($caseId, 'ASSISTANT', null, 'TASK_BATCH_CREATED', null, ['batchId' => $batchId, 'count' => count($created)]);
			return ['intent' => $intent, 'caseNumber' => $case['caseNumber'], 'records' => $created, 'createdCount' => count($created)];
		}
		if ($intent === 'EXECUTE_WORKFLOW_ACTION') {
			$result = $this->workflows->execute((int)($data['recordId'] ?? 0), (int)($data['workflowId'] ?? 0), (string)($data['actionKey'] ?? ''));
			return ['intent' => $intent, 'caseNumber' => $case['caseNumber'], 'workflowResult' => $result, 'documents' => $this->workflowDocuments($result)];
		}
		if ($intent === 'GENERATE_DOCUMENT_TEMPLATE') {
			$templateKey = strtoupper(trim((string)($data['templateKey'] ?? '')));
			if ($templateKey === 'RECHNUNG') throw new \InvalidArgumentException('Rechnungen müssen wegen Nummernkreis, Teilrechnungen und E-Rechnung über Fallakte → Finanzen erzeugt werden.');
			$file = $this->documents->generateTemplate($case, $templateKey, 'ENTWURF', true, false);
			try {
				$record = $this->records->saveDocument($caseId, (string)$file['title'], (string)$file['status'], $file);
			} catch (\Throwable $error) {
				$this->documents->discardGeneratedOutput($file);
				throw $error;
			}
			return ['intent' => $intent, 'caseNumber' => $case['caseNumber'], 'record' => $record, 'documents' => [$this->documentDescriptor($file, (string)$file['title'])]];
		}
		$title = trim((string)($data['title'] ?? ''));
		if ($title === '') throw new \InvalidArgumentException('Die Bezeichnung des Entwurfs fehlt.');
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$date = (string)($data['date'] ?? (date('Y-m-d') . 'T00:00'));
		$recordData = json_encode([
			'description' => 'Vom Bestatter-Assistenten vorbereitet und vom Benutzer ausdrücklich bestätigt.',
			'priority' => 'NORMAL', 'assigneeUid' => $uid, 'assigneeName' => $uid,
			'assistant' => ['confirmed' => true, 'confirmedAt' => date('c'), 'intent' => $intent],
		], JSON_THROW_ON_ERROR);
		$record = match ($intent) {
			'CREATE_TASK_DRAFT' => $this->groupware->createTask($title, $date, 'OFFEN', $recordData, $caseId),
			'CREATE_SCHEDULE_DRAFT' => $this->groupware->createEvent($title, $date, 'OFFEN', json_encode([
				'description' => 'Vom Bestatter-Assistenten vorbereitet und vom Benutzer ausdrücklich bestätigt.',
				'priority' => 'NORMAL', 'assigneeUids' => [$uid],
				'scheduleKind' => preg_match('/trauerfeier|beisetzung|aufbahrung/iu', $title) ? 'EXTERNAL_APPOINTMENT' : 'INTERNAL_ACTIVITY',
				'assistant' => ['confirmed' => true, 'confirmedAt' => date('c'), 'intent' => $intent],
			], JSON_THROW_ON_ERROR), $caseId),
			default => throw new \InvalidArgumentException('Diese Assistentenaktion darf nicht ausgeführt werden.'),
		};
		$automation = $intent === 'CREATE_SCHEDULE_DRAFT' ? $this->workflows->handleScheduleEvent($record) : null;
		return ['record' => $record, 'intent' => $intent, 'caseNumber' => $case['caseNumber'], 'automation' => $automation];
	}

	private function isCaseUpdateRequest(string $input): bool {
		return preg_match('/\b(?:befüll|ergänz|aktualisier|übernimm|trage)\w*\b.*\b(?:sterbefall|fall|stammdaten|falldaten)\b.*\b(?:daten|angaben|notizen)\b/iu', $input) === 1
			|| preg_match('/\b(?:daten|angaben|notizen)\b.*\b(?:in|für)\b.*\b(?:sterbefall|fall|stammdaten|falldaten)\b.*\b(?:übernimm|eintragen|befüllen|ergänzen)\w*/iu', $input) === 1;
	}

	private function prepareCaseUpdate(int $caseId, string $input): array {
		$empty = ['data' => ['caseId' => $caseId, 'changes' => []], 'answer' => null, 'requiredInput' => null];
		if ($caseId <= 0) return array_replace($empty, [
			'answer' => ['title' => 'Fallbezug erforderlich', 'text' => 'Bitte den zu ergänzenden Sterbefall auswählen.', 'items' => []],
			'requiredInput' => ['field' => 'caseId', 'question' => 'Welcher Sterbefall soll mit den Angaben ergänzt werden?'],
		]);
		$details = preg_replace('/^.*?\b(?:daten|angaben|notizen)\b\s*[:\-–]?\s*/iu', '', $input) ?? $input;
		$details = trim($details);
		if ($details === '' || $details === $input) {
			$colon = mb_strpos($input, ':');
			if ($colon !== false) $details = trim(mb_substr($input, $colon + 1));
		}
		if ($details === '') return array_replace($empty, [
			'answer' => ['title' => 'Angaben fehlen', 'text' => 'Bitte die zu übernehmenden Personendaten nach einem Doppelpunkt ergänzen.', 'items' => []],
			'requiredInput' => ['field' => 'details', 'question' => 'Welche konkreten Daten sollen in den Sterbefall übernommen werden?'],
		]);
		$analysis = $this->analyze($details, 'CASE_CAPTURE');
		$changes = [];
		$items = [];
		foreach ($analysis['suggestions'] as $suggestion) {
			$field = (string)($suggestion['field'] ?? '');
			$value = trim((string)($suggestion['value'] ?? ''));
			if ($field === '' || $value === '' || array_key_exists($field, $changes)) continue;
			$changes[$field] = $value;
			$items[] = (string)($suggestion['label'] ?? $field) . ': ' . $value;
		}
		if ($changes === []) return array_replace($empty, [
			'answer' => ['title' => 'Keine eindeutigen Falldaten erkannt', 'text' => 'Die Notiz wurde nicht automatisch übernommen. Bitte klar benannte Angaben verwenden oder die Stammdaten manuell öffnen.', 'items' => []],
			'requiredInput' => ['field' => 'details', 'question' => 'Bitte die Angaben eindeutiger formulieren, zum Beispiel „Vorname Maria, Nachname Muster, geboren am …“.'],
		]);
		return [
			'data' => ['caseId' => $caseId, 'changes' => $changes],
			'answer' => ['title' => count($changes) . ' Falldaten erkannt', 'text' => 'Diese Angaben werden erst nach Ihrer Bestätigung in die Stammdaten übernommen. Bereits vorhandene, nicht genannte Felder bleiben unverändert.', 'items' => $items],
			'requiredInput' => null,
		];
	}

	private function isTaskBatchRequest(string $input): bool {
		return preg_match('/\b(?:erstelle|erzeuge|lege|mach)\w*\b.*\b(?:notiz|notizen|stichpunkt|stichpunkte)\w*\b.*\baufgaben?\b/iu', $input) === 1
			|| preg_match('/\baufgaben?\b.*\b(?:aus|von)\b.*\b(?:notiz|notizen|stichpunkt|stichpunkte)\w*\b/iu', $input) === 1;
	}

	private function prepareTaskBatch(int $caseId, string $input): array {
		$empty = ['data' => ['caseId' => $caseId, 'tasks' => []], 'answer' => null, 'requiredInput' => null];
		if ($caseId <= 0) return array_replace($empty, [
			'answer' => ['title' => 'Fallbezug erforderlich', 'text' => 'Bitte zuerst den Sterbefall auswählen, dem die Aufgaben zugeordnet werden sollen.', 'items' => []],
			'requiredInput' => ['field' => 'caseId', 'question' => 'Zu welchem Sterbefall sollen die Aufgaben angelegt werden?'],
		]);
		$notes = '';
		$colon = mb_strpos($input, ':');
		if ($colon !== false) $notes = trim(mb_substr($input, $colon + 1));
		if ($notes === '') {
			$notes = trim((string)preg_replace('/^.*?\baufgaben?\b\s+(?:zum|für\s+den)\s+sterbefall\b\s*[:\-–]?\s*/iu', '', $input));
			if ($notes === trim($input)) $notes = '';
		}
		if ($notes === '') return array_replace($empty, [
			'answer' => ['title' => 'Aufgabennotizen fehlen', 'text' => 'Bitte die einzelnen Tätigkeiten nach einem Doppelpunkt als Liste angeben.', 'items' => []],
			'requiredInput' => ['field' => 'notes', 'question' => 'Welche Aufgaben sollen angelegt werden?'],
		]);
		$notes = preg_replace('/(?:^|\R)\s*(?:[-*•]|\d+[.)])\s*/u', "\n", $notes) ?? $notes;
		$parts = preg_split('/\R+|\s*;\s*|(?<=[.!?])\s+(?=[\p{Lu}\d])/u', $notes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		if (count($parts) === 1) $parts = preg_split('/\s*,\s*|\s+und\s+(?=[\p{Lu}])/u', $notes, -1, PREG_SPLIT_NO_EMPTY) ?: $parts;
		$titles = [];
		foreach ($parts as $part) {
			$title = trim((string)preg_replace('/^\s*(?:bitte\s+|dann\s+|anschließend\s+)?/iu', '', $part), " \t\n\r\0\x0B,.;:-–");
			if (mb_strlen($title) < 3) continue;
			$title = mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1, 254);
			$titles[mb_strtolower($title)] = $title;
			if (count($titles) >= 12) break;
		}
		if ($titles === []) return array_replace($empty, [
			'answer' => ['title' => 'Keine Aufgaben erkannt', 'text' => 'Aus der Notiz konnten keine eindeutigen Tätigkeiten abgeleitet werden.', 'items' => []],
			'requiredInput' => ['field' => 'notes', 'question' => 'Bitte jede Aufgabe in einer eigenen Zeile oder mit Semikolon getrennt angeben.'],
		]);
		$tasks = array_map(static fn(string $title): array => ['title' => $title, 'date' => date('Y-m-d') . 'T00:00', 'status' => 'OFFEN'], array_values($titles));
		return [
			'data' => ['caseId' => $caseId, 'batchId' => bin2hex(random_bytes(12)), 'tasks' => $tasks],
			'answer' => ['title' => count($tasks) . ' Aufgaben erkannt', 'text' => 'Bitte die Aufgabenliste prüfen. Die Aufgaben werden erst nach ausdrücklicher Bestätigung angelegt und dem ausgewählten Fall zugeordnet.', 'items' => array_values($titles)],
			'requiredInput' => null,
		];
	}

	private function navigationPreview(int $caseId, string $caseTab, string $label, string $text): array {
		if ($caseId <= 0) return ['NAVIGATE', $label, ['title' => 'Fall auswählen', 'text' => 'Bitte zuerst einen Fall auswählen.', 'items' => []], []];
		$case = $this->cases->getCase($caseId);
		return ['NAVIGATE', $label, ['title' => $label, 'text' => $text, 'items' => [$case['caseNumber'] . ' · ' . trim($case['firstName'] . ' ' . $case['lastName'])]], [[
			'id' => 'navigate-' . $caseTab, 'kind' => 'navigation', 'label' => $label, 'description' => 'Fallakte · ' . $case['caseNumber'],
			'confirmationToken' => $this->confirmationToken('NAVIGATE', ['view' => 'case-detail', 'caseId' => $caseId, 'caseTab' => $caseTab]),
		]]];
	}

	private function isOrganizationResearchRequest(string $input): bool {
		return preg_match('/\b(such|suche|finde|recherchier|recherchiere)\w*\b.*\b(adresse|anschrift|kontakt|standesamt|friedhof|krematorium|behörde)\w*\b/iu', $input) === 1
			&& preg_match('/\b(adressbuch|kontakt\s*(?:anlegen|eintragen|speichern)|trage\w*\s+.*\s+ein)\b/iu', $input) === 1;
	}

	private function organizationQuery(string $input): string {
		$query = preg_replace('/\b(?:und\s+)?(?:trage|trag|speichere|speicher|lege)\w*\b.*$/iu', '', $input) ?? $input;
		$query = preg_replace('/\b(?:suche|such|finde|find|recherchiere|recherchier)\w*\b(?:\s+mir)?/iu', '', $query) ?? $query;
		$query = preg_replace('/\b(?:die|den|der|des|das|vom|von)\s+(?:adresse|anschrift|kontaktdaten)\b/iu', '', $query) ?? $query;
		$query = preg_replace('/\bstandesamtes\b/iu', 'Standesamt', $query) ?? $query;
		$query = preg_replace('/\b(?:des|der|die|das|vom|von)\b/iu', '', $query) ?? $query;
		$query = preg_replace('/\b(?:heraus|raus|bitte)\b/iu', '', $query) ?? $query;
		return trim(preg_replace('/\s+/u', ' ', $query) ?? $query, " \t\n\r\0\x0B,.;");
	}

	private function isDocumentOutputRequest(string $input): bool {
		if (preg_match('/\b(druck|drucke|ausdruck|ausgeben|erzeug|erstelle|generier|öffne|anzeig|vorschau|finde|suche)\w*\b/iu', $input) !== 1) return false;
		if (preg_match('/\b(dokument|formular|urkunde|vollmacht|rechnung|kva|kostenvoranschlag|auftrag|kondolenzliste|trauerbrief|trauerkarte|anschreiben|vorlage)\w*\b/iu', $input) === 1) return true;
		foreach ($this->configuration->documents() as $template) {
			if ((bool)($template['active'] ?? false) && $this->templateMatchesRequest($template, $input)) return true;
		}
		return false;
	}

	private function prepareDocumentOutput(int $caseId, string $input): array {
		$empty = ['intent' => 'PREPARE_DOCUMENT_OUTPUT', 'data' => ['caseId' => $caseId], 'documents' => [], 'choices' => [], 'requiredInput' => null];
		if ($caseId <= 0) return array_replace($empty, [
			'answer' => ['title' => 'Fall auswählen', 'text' => 'Dokumente werden immer aus einem konkreten Fall und dessen aktuellen Daten erzeugt.', 'items' => []],
			'requiredInput' => ['field' => 'caseId', 'question' => 'Für welchen Fall soll die Dokumentausgabe vorbereitet werden?'],
		]);

		$templates = array_values(array_filter($this->configuration->documents(), static fn(array $template): bool => (bool)($template['active'] ?? false) && str_ends_with(mb_strtolower((string)($template['fileName'] ?? '')), '.docx')));
		$matches = array_values(array_filter($templates, fn(array $template): bool => $this->templateMatchesRequest($template, $input)));
		if (preg_match('/\bkondolenzlisten?\b/iu', $input) === 1) return $this->prepareCondolencePrint($caseId, $input);
		if ($matches === []) {
			$choices = array_map(fn(array $template): array => $this->documentTemplateChoice($caseId, $template), array_slice($templates, 0, 20));
			return array_replace($empty, [
				'choices' => $choices,
				'answer' => ['title' => 'Dokumentvorlage auswählen', 'text' => 'Die gewünschte Ausgabe war nicht eindeutig. Bitte eine aktive Vorlage auswählen; vorhandene aktuelle Ausgaben werden dabei bevorzugt geöffnet.', 'items' => []],
			]);
		}
		if (count($matches) > 1) {
			return array_replace($empty, [
				'choices' => array_map(fn(array $template): array => $this->documentTemplateChoice($caseId, $template), $matches),
				'answer' => ['title' => 'Mehrere passende Vorlagen', 'text' => 'Bitte die gewünschte Ausgabe auswählen.', 'items' => []],
			]);
		}

		$template = $matches[0];
		$key = (string)$template['key'];
		if (in_array($key, ['KONDOLENZLISTE_DECKBLATT', 'KONDOLENZLISTE_LISTE'], true)) return $this->prepareCondolencePrint($caseId, $input);
		$existing = $this->documentsForTemplate($caseId, $key);
		if ($existing !== []) return array_replace($empty, [
			'intent' => 'OPEN_EXISTING_DOCUMENTS', 'data' => ['caseId' => $caseId, 'templateKey' => $key], 'documents' => $existing,
			'answer' => ['title' => 'Aktuelle Ausgabe vorhanden', 'text' => 'Die vorhandene aktuelle Ausgabe kann geöffnet, in der Vorschau geprüft oder – bei PDF – gedruckt werden.', 'items' => [(string)$template['name']]],
		]);
		if ($key === 'RECHNUNG') return array_replace($empty, [
			'answer' => ['title' => 'Rechnung fachlich erzeugen', 'text' => 'Eine Rechnung darf nicht direkt aus der DOCX-Vorlage erzeugt werden. Bitte Fallakte → Finanzen verwenden; dort werden Nummernkreis, Teilrechnungen, ZUGFeRD und Zahlungs-QR berücksichtigt.', 'items' => []],
			'requiredInput' => ['field' => 'invoiceProcess', 'question' => 'Bitte die Rechnung in der Fallakte unter „Finanzen“ erzeugen.'],
		]);

		$case = $this->cases->getCase($caseId);
		$preview = $this->documents->preview($case, $key);
		if (($preview['missingFields'] ?? []) !== []) return array_replace($empty, [
			'data' => ['caseId' => $caseId, 'templateKey' => $key],
			'answer' => ['title' => 'Pflichtangaben fehlen', 'text' => 'Die Ausgabe kann erst nach Ergänzung der erforderlichen Falldaten erzeugt werden.', 'items' => (array)$preview['missingFields']],
			'requiredInput' => ['field' => 'masterData', 'question' => 'Bitte die genannten Angaben in der Fallakte ergänzen.'],
		]);
		return [
			'intent' => 'GENERATE_DOCUMENT_TEMPLATE', 'data' => ['caseId' => $caseId, 'templateKey' => $key], 'documents' => [], 'choices' => [], 'requiredInput' => null,
			'answer' => ['title' => 'Dokumentausgabe ist bereit', 'text' => sprintf('„%s“ wird aus den aktuellen Falldaten als DOCX und – sofern verfügbar – zusätzlich als PDF erzeugt.', (string)$template['name']), 'items' => ['Ablage: ' . (string)$preview['outputSubfolder'], 'Keine automatische Druckausgabe ohne Browserbestätigung']],
		];
	}

	private function templateMatchesRequest(array $template, string $input): bool {
		$haystack = $this->normalizeDocumentText($input);
		$haystack = trim(preg_replace('/\b(?:druck\w*|ausdruck\w*|ausgeben\w*|ausgabe\w*|erzeug\w*|erstelle\w*|generier\w*|oeffne\w*|anzeig\w*|vorschau\w*|finde\w*|suche\w*|bitte)\b/u', ' ', $haystack) ?? $haystack);
		$haystack = trim(preg_replace('/\s+/u', ' ', $haystack) ?? $haystack);
		if ($haystack === '') return false;
		$candidates = [(string)($template['name'] ?? ''), (string)($template['key'] ?? ''), pathinfo((string)($template['fileName'] ?? ''), PATHINFO_FILENAME)];
		foreach ($candidates as $candidate) {
			$needle = $this->normalizeDocumentText($candidate);
			if ($needle !== '' && (str_contains($haystack, $needle) || str_contains($needle, $haystack))) return true;
			$words = array_values(array_filter(explode(' ', $needle), static fn(string $word): bool => mb_strlen($word) >= 5));
			if ($words !== [] && count(array_filter($words, static fn(string $word): bool => str_contains($haystack, $word))) >= min(2, count($words))) return true;
		}
		return false;
	}

	private function normalizeDocumentText(string $value): string {
		$value = mb_strtolower(strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']));
		return trim(preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value);
	}

	private function documentTemplateChoice(int $caseId, array $template): array {
		$key = (string)$template['key'];
		$existing = $this->documentsForTemplate($caseId, $key);
		return [
			'id' => 'document-' . strtolower($key), 'kind' => 'document', 'label' => (string)$template['name'],
			'description' => (string)($template['category'] ?? 'Dokument') . ' · ' . (string)($template['outputSubfolder'] ?? 'Fallakte'),
			'actionLabel' => $existing !== [] ? 'Aktuelle Ausgabe öffnen' : ($key === 'RECHNUNG' ? 'Finanzprozess erforderlich' : 'Geprüft erzeugen'),
			'disabled' => $key === 'RECHNUNG' && $existing === [], 'documents' => $existing,
			'confirmationToken' => $existing !== [] || $key === 'RECHNUNG' ? '' : $this->confirmationToken('GENERATE_DOCUMENT_TEMPLATE', ['caseId' => $caseId, 'templateKey' => $key]),
		];
	}

	private function documentsForTemplate(int $caseId, string $templateKey): array {
		$documents = [];
		foreach ($this->records->list('document', $caseId) as $record) {
			if ((string)($record['data']['templateKey'] ?? '') !== $templateKey) continue;
			$documents[] = $this->documentDescriptor((array)$record['data'], (string)$record['title']);
		}
		return $documents;
	}

	private function prepareCondolencePrint(int $caseId, string $input): array {
		$empty = ['intent' => 'PREPARE_CONDOLENCE_PRINT', 'data' => ['caseId' => $caseId], 'documents' => [], 'requiredInput' => null];
		if ($caseId <= 0) return array_replace($empty, ['answer' => ['title' => 'Fall auswählen', 'text' => 'Bitte zuerst den zugehörigen Fall auswählen.', 'items' => []], 'requiredInput' => ['field' => 'caseId', 'question' => 'Für welchen Fall sollen die Kondolenzlisten ausgegeben werden?']]);
		$case = $this->cases->getCase($caseId);
		$schedules = array_values(array_filter($this->records->list('schedule', $caseId), static function (array $record): bool {
			$category = (string)($record['data']['appointmentCategory'] ?? '');
			return preg_match('/trauerfeier/iu', (string)$record['title'] . ' ' . $category) === 1
				&& in_array(strtoupper((string)$record['status']), ['BESTAETIGT', 'ERLEDIGT'], true);
		}));
		if (count($schedules) > 1) {
			$requestedDate = $this->intentDate($input);
			if ($requestedDate !== '') $schedules = array_values(array_filter($schedules, static fn(array $record): bool => str_starts_with((string)$record['date'], substr($requestedDate, 0, 10))));
		}
		if ($schedules === []) return array_replace($empty, ['answer' => ['title' => 'Bestätigte Trauerfeier fehlt', 'text' => 'Für den Fall wurde keine eindeutig bestätigte Trauerfeier gefunden. Bitte den Termin zuerst anlegen oder bestätigen.', 'items' => []], 'requiredInput' => ['field' => 'schedule', 'question' => 'Wann findet die bestätigte Trauerfeier statt?']]);
		if (count($schedules) > 1) return array_replace($empty, ['answer' => ['title' => 'Mehrere Trauerfeiern gefunden', 'text' => 'Bitte den gewünschten Termin mit Datum im Arbeitsauftrag nennen.', 'items' => array_map(static fn(array $record): string => (string)$record['date'] . ' · ' . (string)$record['title'], $schedules)], 'requiredInput' => ['field' => 'scheduleDate', 'question' => 'Für welchen Trauerfeier-Termin sollen die Kondolenzlisten ausgegeben werden?']]);
		$schedule = $schedules[0];
		$master = is_array($case['masterData'] ?? null) ? $case['masterData'] : [];
		$missing = [];
		if (trim((string)($master['first_name'] ?? $case['firstName'] ?? '')) === '') $missing[] = 'Vorname';
		if (trim((string)($master['last_name'] ?? $case['lastName'] ?? '')) === '') $missing[] = 'Nachname';
		if ($missing !== []) return array_replace($empty, ['answer' => ['title' => 'Falldaten unvollständig', 'text' => 'Bitte vor der Dokumenterzeugung ergänzen: ' . implode(', ', $missing) . '.', 'items' => $missing], 'requiredInput' => ['field' => 'masterData', 'question' => 'Bitte die fehlenden Falldaten ergänzen.']]);

		$documents = $this->condolenceDocuments($caseId, $schedule);
		if (count($documents) >= 2) {
			return ['intent' => 'OPEN_EXISTING_DOCUMENTS', 'data' => ['caseId' => $caseId, 'scheduleId' => $schedule['id']], 'documents' => $documents, 'requiredInput' => null,
				'answer' => ['title' => 'Kondolenzlisten sind vorhanden', 'text' => 'Die aktuellen Ausgaben können jetzt als PDF geöffnet und über den Browser-Druckdialog ausgegeben werden.', 'items' => []]];
		}

		foreach ($this->records->list('task', $caseId) as $task) {
			if (preg_match('/kondolenzlisten?/iu', (string)$task['title']) !== 1) continue;
			$sourceScheduleId = (int)($task['data']['sourceScheduleId'] ?? $task['data']['workflow']['sourceScheduleId'] ?? 0);
			if ($sourceScheduleId > 0 && $sourceScheduleId !== (int)$schedule['id']) continue;
			foreach ($this->workflows->available((int)$task['id']) as $workflow) foreach ($workflow['actions'] as $action) {
				if (($action['key'] ?? '') !== 'KONDOLENZLISTEN_PAKET') continue;
				if (($action['executed'] ?? false) && !($action['retryable'] ?? false)) continue;
				return ['intent' => 'EXECUTE_WORKFLOW_ACTION', 'data' => ['caseId' => $caseId, 'recordId' => $task['id'], 'workflowId' => $workflow['id'], 'actionKey' => $action['key']], 'documents' => [], 'requiredInput' => null,
					'answer' => ['title' => 'Dokumentpaket ist bereit', 'text' => sprintf('Deckblatt und Kondolenzliste werden aus der bestätigten Trauerfeier vom %s erzeugt. Danach können die PDFs geöffnet und gedruckt werden.', (new \DateTimeImmutable((string)$schedule['date']))->format('d.m.Y H:i')), 'items' => ['Zielordner: 05 Trauerdruck', 'Keine automatische Druckausgabe ohne Browserbestätigung']]];
			}
		}
		return array_replace($empty, ['answer' => ['title' => 'Folgeaufgabe fehlt', 'text' => 'Zur bestätigten Trauerfeier wurde keine ausführbare Aufgabe „Kondolenzlisten erstellen“ gefunden. Bitte den Termin-Workflow prüfen oder erneut auslösen.', 'items' => []], 'requiredInput' => ['field' => 'workflowTask', 'question' => 'Soll zuerst die fehlende Folgeaufgabe aus dem Terminprozess erzeugt werden?']]);
	}

	private function condolenceDocuments(int $caseId, array $schedule): array {
		$documents = [];
		foreach ($this->records->list('document', $caseId) as $record) {
			if (!in_array((string)($record['data']['templateKey'] ?? ''), ['KONDOLENZLISTE_DECKBLATT', 'KONDOLENZLISTE_LISTE'], true)) continue;
			$snapshot = is_array($record['data']['sourceScheduleSnapshot'] ?? null) ? $record['data']['sourceScheduleSnapshot'] : [];
			if ((int)($snapshot['id'] ?? 0) !== (int)$schedule['id'] || (string)($snapshot['date'] ?? '') !== (string)$schedule['date']) continue;
			$documents[] = $this->documentDescriptor((array)$record['data'], (string)$record['title']);
		}
		return $documents;
	}

	private function workflowDocuments(array $workflowResult): array {
		$result = is_array($workflowResult['result'] ?? null) ? $workflowResult['result'] : [];
		$entries = is_array($result['documents'] ?? null) ? $result['documents'] : (isset($result['file']) ? [$result] : []);
		$documents = [];
		foreach ($entries as $entry) {
			$file = is_array($entry['file'] ?? null) ? $entry['file'] : $entry;
			$documents[] = $this->documentDescriptor($file, (string)($file['title'] ?? 'Dokument'));
		}
		return $documents;
	}

	private function documentDescriptor(array $file, string $title): array {
		return [
			'title' => $title, 'path' => (string)($file['path'] ?? $file['pdf']['path'] ?? ''),
			'docxFileId' => (int)($file['fileId'] ?? 0), 'pdfFileId' => (int)($file['pdf']['fileId'] ?? 0),
			'previewFileId' => (int)($file['pdf']['fileId'] ?? $file['fileId'] ?? 0),
			'pdfWarning' => (string)($file['pdfWarning'] ?? ''),
		];
	}

	public function health(): array {
		$configuration = $this->configuration();
		return [
			'enabled' => $configuration['guidedCaptureEnabled'] || $configuration['speechInputEnabled'] || $configuration['assistantEnabled'],
			'speechProvider' => $configuration['providers']['speechToText'],
			'speechProviderApproved' => $configuration['speechProviderApproved'],
			'textProvider' => $configuration['providers']['textToText'],
			'webResearch' => $configuration['webResearchEnabled'],
			'audioRetention' => $configuration['audioRetention'],
			'manualFallback' => true,
			'microphonePolicyRequired' => "microphone 'self'",
			'supportedAudioFormats' => ['WebM', 'OGG', 'WAV', 'MP3', 'M4A', 'AAC'],
		];
	}

	private function availableTaskTypes(): array {
		try { return $this->tasks->getAvailableTaskTypeIds(); }
		catch (\Throwable) { return []; }
	}

	private function flag(string $key, bool $default): bool {
		return filter_var($this->config->getAppValue(Application::APP_ID, $key, $default ? 'yes' : 'no'), FILTER_VALIDATE_BOOLEAN);
	}

	private function assistantFolder(string $uid): Folder {
		return $this->installationConfig->ensurePath(
			$this->rootFolder->getUserFolder($uid),
			$this->installationConfig->storageRoot() . '/' . $this->installationConfig->assistantFolder(),
		);
	}

	private function cleanupTaskAudio(string $customId, string $uid): void {
		if ($this->config->getAppValue(Application::APP_ID, 'assistant_audio_retention', 'DELETE_AFTER_TRANSCRIPTION') !== 'DELETE_AFTER_TRANSCRIPTION') return;
		$fileId = (int)substr($customId, strlen('speech:'));
		if ($fileId <= 0) return;
		$expected = '/' . $this->installationConfig->storageRoot() . '/' . $this->installationConfig->assistantFolder() . '/';
		foreach ($this->rootFolder->getUserFolder($uid)->getById($fileId) as $node) {
			if ($node instanceof File && str_contains($node->getPath(), $expected)) $node->delete();
		}
	}

	private function extension(string $mime, string $name): string {
		$map = [
			'audio/webm' => 'webm', 'video/webm' => 'webm',
			'audio/ogg' => 'ogg', 'application/ogg' => 'ogg',
			'audio/wav' => 'wav', 'audio/x-wav' => 'wav',
			'audio/mpeg' => 'mp3',
			'audio/mp4' => 'm4a', 'audio/m4a' => 'm4a', 'audio/x-m4a' => 'm4a',
			'audio/aac' => 'aac', 'audio/x-aac' => 'aac',
		];
		return $map[$mime] ?? (preg_match('/\.([a-z0-9]{2,5})$/i', $name, $match) ? strtolower($match[1]) : 'webm');
	}

	private function statusName(int $status): string {
		return match ($status) {
			Task::STATUS_SCHEDULED => 'SCHEDULED', Task::STATUS_RUNNING => 'RUNNING', Task::STATUS_SUCCESSFUL => 'SUCCESSFUL',
			Task::STATUS_FAILED => 'FAILED', Task::STATUS_CANCELLED => 'CANCELLED', default => 'UNKNOWN',
		};
	}

	private function suggestName(string $text, array &$suggestions): void {
		if (!preg_match('/(?:sterbefall|verstorbene(?:r| person)?|verstorben ist|es\s+geht\s+um)\s+(?:frau\s+|herr\s+)?([\p{L}\'-]+)\s+([\p{L}\'-]+)(?=\s*[,.;]|\s+(?:geborene|geborener|geb[.]|ist|war|wurde)\b|$)/iu', $text, $match)) return;
		$this->add($suggestions, 'first_name', $match[1], 'Vorname', .88, $match[0]);
		$this->add($suggestions, 'last_name', $match[2], 'Nachname', .88, $match[0]);
	}

	private function suggestPersonalDetails(string $text, array &$suggestions): void {
		if (preg_match('/\b(frau|herr)\b/iu', $text, $match)) {
			$this->add($suggestions, 'salutation', ucfirst(mb_strtolower($match[1])), 'Anrede', .94, $match[0]);
		}
		if (preg_match('/\b(dr[.]\s*med[.]|dr[.]|prof[.])\s+/iu', $text, $match)) {
			$title = preg_replace('/\s+/u', ' ', trim($match[1])) ?? trim($match[1]);
			$this->add($suggestions, 'title', $title, 'Titel', .92, $match[0]);
		}
		if (preg_match('/\b(?:heißt|heisst)\s+(?:mit\s+)?vornamen?\s+([\p{L}][\p{L}\'-]*)/iu', $text, $match)) {
			$this->add($suggestions, 'first_name', $match[1], 'Vorname', .98, $match[0]);
		}
		if (preg_match('/\b(?:person\s+ist|name\s+ist|sie\s+heißt|sie\s+heisst|er\s+heißt|er\s+heisst)\s+(?:frau|herr)\s+(?:(?:prof|dr)(?:[.]\s*(?:med[.])?)?\s+)?([\p{L}][\p{L}\'-]*)/iu', $text, $match)) {
			$this->add($suggestions, 'last_name', $match[1], 'Nachname', .95, $match[0]);
		}
		if (preg_match('/\b(?:geborene|geborener|geb[.])\s+([\p{L}][\p{L}\'\-]*(?:\s+[\p{L}\d][\p{L}\d\'\-]*)?)(?=\s*[,.;]|\s+(?:geboren|wurde|ist|war)\b|$)/iu', $text, $match)) {
			$this->add($suggestions, 'birth_name', $match[1], 'Geburtsname', .96, $match[0]);
		}
		if (preg_match('/\b(verheiratet|ledig|verwitwet|geschieden)\b/iu', $text, $match)) {
			$this->add($suggestions, 'civil_status', mb_strtolower($match[1]), 'Familienstand', .96, $match[0]);
		}
		if (preg_match('/\bverheiratet\s+mit\s+(?:ihrem|seinem)\s+(?:ehemann|ehefrau|mann|frau)\s+([\p{L}][\p{L}\'-]*)(?:\s+([\p{L}][\p{L}\'-]*))?(?=\s+(?:und|der|die|ist|war)\b|[,.;]|$)/iu', $text, $match)) {
			$this->add($suggestions, 'spouse_first_name', $match[1], 'Vorname Ehepartner/in', .96, $match[0]);
			$spouseLastName = trim((string)($match[2] ?? ''));
			if ($spouseLastName !== '') {
				$this->add($suggestions, 'spouse_last_name', $spouseLastName, 'Nachname Ehepartner/in', .94, $match[0]);
			} else {
				$currentLastName = $this->suggestedValue($suggestions, 'last_name');
				if ($currentLastName !== '') {
					$this->add($suggestions, 'spouse_last_name', $currentLastName, 'Nachname Ehepartner/in', .62, $match[0] . ' · aus aktuellem Familiennamen abgeleitet');
				}
			}
		}
		if (preg_match('/\b(evangelisch|katholisch|islamisch|muslimisch|jüdisch|konfessionslos)\b/iu', $text, $match)) {
			$religion = mb_strtolower($match[1]);
			if ($religion === 'muslimisch') $religion = 'Islam';
			$this->add($suggestions, 'religion', $religion, 'Religion', .94, $match[0]);
		}
	}

	private function suggestResidence(string $text, array &$suggestions): void {
		if (!preg_match('/(?:zuletzt\s+)?in\s+der\s+(.+?)\s+(?:in\s+)?(\d{5})\s+([\p{L}][\p{L}\s-]*?)\s+wohnhaft(?:\s+gewesen)?\b/iu', $text, $match)
			&& !preg_match('/\b(?:der\s+)?letzte\s+wohnsitz\s+(?:ist|war|lautet)\s+(.+?)\s+(?:in\s+)?(\d{5})\s+([\p{L}][\p{L}\s-]*?)(?=$|[,.;])/iu', $text, $match)) return;
		$this->add($suggestions, 'last_residence', trim($match[1]), 'Straße / Hausnummer', .9, $match[0]);
		$this->add($suggestions, 'last_residence_postal_code', $match[2], 'PLZ', .98, $match[0]);
		$this->add($suggestions, 'last_residence_city', trim($match[3]), 'Letzter Wohnort', .94, $match[0]);
	}

	private function suggestCemetery(string $text, array &$suggestions): void {
		if (preg_match('/\b(?:auf|im)\s+(?:dem\s+)?friedhof\s+([^,.;]+?)(?=$|[,.;])/iu', $text, $match)) {
			$this->add($suggestions, 'cemetery_contact', 'Friedhof ' . trim($match[1]), 'Friedhof', .88, $match[0]);
			return;
		}
		if (preg_match('/\b(?:auf|im)\s+(?:dem\s+)?([\p{L}][\p{L}\s-]*?)\s+friedhof(?=$|[,.;])/iu', $text, $match)) {
			$this->add($suggestions, 'cemetery_contact', trim($match[1]) . ' Friedhof', 'Friedhof', .9, $match[0]);
		}
	}

	private function suggestAdministrativeDetails(string $text, array &$suggestions): void {
		$this->suggestValue($text, $suggestions, 'birth_registry_office', '/\bgeburtsstandesamt\s+(?:ist|war|lautet)\s+([^,.;]+)/iu', 'Geburtsstandesamt', .94);
		$this->suggestValue($text, $suggestions, 'profession', '/\b(?:vom\s+beruf|beruflich)\s+(?:ist|war)?\s*([^,.;]+)/iu', 'Beruf', .9);
		$this->suggestValue($text, $suggestions, 'pension_insurance_number', '/\bpostrentennummer\s+(?:ist|war|lautet)\s+([A-Z0-9][A-Z0-9\s\/-]+)/iu', 'Postrentennummer', .96);
	}

	private function suggestClientDetails(string $text, array &$suggestions): void {
		if (preg_match('/\bauftraggeber(?:in)?\s+(?:ist|wird)\s+(?:sein(?:e)?|ihr(?:e)?|die|der)?\s*([\p{L}\-]+)\s+([\p{L}\'\-]+)\s+([\p{L}\'\-]+)(?=\s+(?:mit|telefon|mobil)|[,.;]|$)/iu', $text, $match)) {
			$this->add($suggestions, 'order_client_relation', ucfirst(mb_strtolower($match[1])), 'Beziehung der Auftraggeberin / des Auftraggebers', .82, $match[0]);
			$this->add($suggestions, 'order_client_first_name', $match[2], 'Vorname Auftraggeber/in', .92, $match[0]);
			$this->add($suggestions, 'order_client_name', $match[3], 'Nachname Auftraggeber/in', .92, $match[0]);
		}
		if (preg_match('/\bmobil(?:funk)?nummer\s+(?:ist|lautet)?\s*([+\d][\d\s\/-]{6,})/iu', $text, $match)) {
			$this->add($suggestions, 'order_client_mobile', trim($match[1]), 'Mobilfunknummer Auftraggeber/in', .94, $match[0]);
		}
	}

	private function suggestCertificateCounts(string $text, array &$suggestions): void {
		foreach ([['certificate_free_count', 'gebührenfrei(?:e|en)?', 'Sterbeurkunden gebührenfrei'], ['certificate_paid_count', 'gebührenpflichtig(?:e|en)?', 'Sterbeurkunden gebührenpflichtig']] as [$field, $qualifier, $label]) {
			$explicit = preg_match('/\b(\d+|ein(?:e|en)?|zwei|drei|vier|fünf|sechs|sieben|acht|neun|zehn)\s+(?:weitere\s+)?' . $qualifier . '\s+sterbeurkunden?\b/iu', $text, $match) === 1;
			$elliptic = !$explicit
				&& str_contains(mb_strtolower($text), 'sterbeurkund')
				&& preg_match('/\b(\d+|ein(?:e|en)?|zwei|drei|vier|fünf|sechs|sieben|acht|neun|zehn)\s+weitere\s+' . $qualifier . '\b/iu', $text, $match) === 1;
			if (!$explicit && !$elliptic) continue;
			$value = $this->numberValue($match[1]);
			if ($value !== null) $this->add($suggestions, $field, (string)$value, $label, .94, $match[0]);
		}
	}

	private function numberValue(string $value): ?int {
		$value = mb_strtolower(trim($value));
		if (ctype_digit($value)) return (int)$value;
		$numbers = ['ein'=>1, 'eine'=>1, 'einen'=>1, 'zwei'=>2, 'drei'=>3, 'vier'=>4, 'fünf'=>5, 'sechs'=>6, 'sieben'=>7, 'acht'=>8, 'neun'=>9, 'zehn'=>10];
		return $numbers[$value] ?? null;
	}

	private function suggestDate(string $text, array &$suggestions, string $field, string $pattern, string $label): void {
		if (!preg_match($pattern, $text, $match)) return;
		$value = $this->dateValue(trim($match[1]));
		if ($value !== null) $this->add($suggestions, $field, $value, $label, .9, $match[0]);
	}

	private function suggestValue(string $text, array &$suggestions, string $field, string $pattern, string $label, float $confidence): void {
		if (!preg_match($pattern, $text, $match)) return;
		$value = trim($match[1]);
		if ($value !== '') $this->add($suggestions, $field, $value, $label, $confidence, $match[0]);
	}

	/** Extrahiert nur explizit beschriftete Formularwerte; unklare OCR-Fragmente werden nicht geraten. */
	private function suggestFormLabels(string $text, array &$suggestions): void {
		$fieldLabels=['first_name'=>'Vorname','last_name'=>'Nachname','birth_name'=>'Geburtsname','date_of_birth'=>'Geburtsdatum','birth_place'=>'Geburtsort','date_of_death'=>'Sterbedatum','place_of_death'=>'Sterbeort / Einrichtung','birth_registry_office'=>'Geburtsstandesamt','profession'=>'Beruf','pension_insurance_number'=>'Postrentennummer','civil_status'=>'Familienstand','religion'=>'Religion','last_residence'=>'Straße / Hausnummer','last_residence_postal_code'=>'PLZ','last_residence_city'=>'Letzter Wohnort','funeral_type'=>'Bestattungsart','cemetery_contact'=>'Friedhof','order_client_first_name'=>'Vorname Auftraggeber/in','order_client_name'=>'Nachname Auftraggeber/in','order_client_relation'=>'Beziehung Auftraggeber/in','order_client_mobile'=>'Mobilfunknummer Auftraggeber/in','certificate_free_count'=>'Sterbeurkunden gebührenfrei','certificate_paid_count'=>'Sterbeurkunden gebührenpflichtig'];
		$labels = [
			'first_name'=>['Vorname','Vorname(?:n)?'],'last_name'=>['Nachname','Familienname'],'birth_name'=>['Geburtsname','geb(?:orene[rs]?)? Name'],
			'date_of_birth'=>['Geburtsdatum','geboren am'],'birth_place'=>['Geburtsort','geboren in'],'date_of_death'=>['Sterbedatum','verstorben am'],'place_of_death'=>['Sterbeort','verstorben in'],
			'birth_registry_office'=>['Geburtsstandesamt'],'profession'=>['Beruf'],'pension_insurance_number'=>['Postrentennummer','Rentenversicherungsnummer'],
			'civil_status'=>['Familienstand'],'religion'=>['Religion','Konfession'],'last_residence'=>['Straße(?: und Hausnummer)?','Anschrift'],'last_residence_postal_code'=>['PLZ','Postleitzahl'],'last_residence_city'=>['Wohnort','Ort'],
			'funeral_type'=>['Bestattungsart','Bestattungsform'],'cemetery_contact'=>['Friedhof','Beisetzungsort'],
			'order_client_first_name'=>['Vorname Auftraggeber(?:in)?'],'order_client_name'=>['Nachname Auftraggeber(?:in)?','Auftraggeber(?:in)?'],'order_client_relation'=>['Beziehung Auftraggeber(?:in)?'],'order_client_mobile'=>['Mobil(?:funknummer)? Auftraggeber(?:in)?','Telefon Auftraggeber(?:in)?'],
			'certificate_free_count'=>['Sterbeurkunden gebührenfrei'],'certificate_paid_count'=>['Sterbeurkunden gebührenpflichtig'],
		];
		foreach($labels as $field=>$variants){
			$pattern='/^(?:'.implode('|',$variants).')\s*[:\-]?\s*(.+?)\s*$/imu';
			if(!preg_match($pattern,$text,$match))continue;
			$value=trim(preg_replace('/\s{2,}.*/u','',trim($match[1]))??trim($match[1]));
			if($value===''||preg_match('/^(?:-|—|unbekannt|keine angabe|nicht bekannt)$/iu',$value))continue;
			if(in_array($field,['date_of_birth','date_of_death'],true)){$date=$this->dateValue($value);if($date===null)continue;$value=$date;}
			if(in_array($field,['certificate_free_count','certificate_paid_count'],true)){if(!preg_match('/\d+/', $value,$number))continue;$value=$number[0];}
			if($field==='funeral_type'){$normalized=mb_strtolower($value);$value=str_contains($normalized,'feuer')?'FEUERBESTATTUNG':(str_contains($normalized,'erd')?'ERDBESTATTUNG':$value);}
			$this->add($suggestions,$field,$value,$fieldLabels[$field]??$field,.93,$match[0]);
		}
	}

	private function add(array &$suggestions, string $field, string $value, string $label, float $confidence, string $source): void {
		foreach ($suggestions as &$existing) {
			if ($existing['field'] !== $field) continue;
			if ((string)$existing['value'] !== $value) $existing['alternatives'][] = $value;
			return;
		}
		unset($existing);
		$suggestions[] = ['field' => $field, 'label' => $label, 'value' => $value, 'confidence' => $confidence, 'source' => trim($source), 'origin' => 'DETERMINISTIC_RULE'];
	}

	private function applyApprovedRules(string $text, array &$suggestions): void {
		if (!isset($this->db)) return;
		try {
			$query = $this->db->getQueryBuilder();
			$rows = $query->select('id','field_key','trigger_pattern','canonical_value')->from('bestatter_assistant_rules')->where($query->expr()->eq('status',$query->createNamedParameter('APPROVED')))->andWhere($query->expr()->eq('strategy',$query->createNamedParameter('ENUM')))->executeQuery()->fetchAllAssociative();
			$normalized = mb_strtolower($text);
			foreach ($rows as $row) {
				$needle = str_replace('{value}', '', mb_strtolower((string)$row['trigger_pattern']));
				if ($needle === '' || !str_contains($normalized, trim($needle))) continue;
				$this->add($suggestions, (string)$row['field_key'], (string)$row['canonical_value'], (string)$row['field_key'], .8, (string)$row['trigger_pattern']);
				$update=$this->db->getQueryBuilder(); $update->update('bestatter_assistant_rules')->set('usage_count',$update->createFunction('usage_count + 1'))->where($update->expr()->eq('id',$update->createNamedParameter((int)$row['id'])))->executeStatement();
			}
		} catch (\Throwable) { /* Learning must never disable deterministic capture. */ }
	}

	private function compactEvidence(string $source): string {
		$source = trim(preg_replace('/\s+/u', ' ', $source) ?? $source);
		$source = preg_replace('/\b(?:\+?\d[\d\s\/.-]{5,}|\d{5}\s+[\p{L}-]+)\b/u', '[redigiert]', $source) ?? $source;
		return mb_substr($source, 0, 255);
	}

	private function suggestedValue(array $suggestions, string $field): string {
		foreach ($suggestions as $suggestion) {
			if (($suggestion['field'] ?? '') === $field) return trim((string)($suggestion['value'] ?? ''));
		}
		return '';
	}

	private function dateValue(string $value): ?string {
		$value = mb_strtolower(trim($value));
		$today = new \DateTimeImmutable('today');
		if (preg_match('/\bheute\b/u', $value)) return $today->format('Y-m-d');
		if (preg_match('/\bgestern\b/u', $value)) return $today->modify('-1 day')->format('Y-m-d');
		if (preg_match('/(\d{1,2})[.]\s*(\d{1,2})[.]\s*(\d{2,4})/', $value, $match)) {
			$year = (int)$match[3]; if ($year < 100) $year += $year > 30 ? 1900 : 2000;
			return checkdate((int)$match[2], (int)$match[1], $year) ? sprintf('%04d-%02d-%02d', $year, $match[2], $match[1]) : null;
		}
		$months = ['januar'=>1,'februar'=>2,'märz'=>3,'maerz'=>3,'april'=>4,'mai'=>5,'juni'=>6,'juli'=>7,'august'=>8,'september'=>9,'oktober'=>10,'november'=>11,'dezember'=>12];
		if (preg_match('/(\d{1,2})[.]?\s*([\p{L}]+)\s*(\d{4})/u', $value, $match) && isset($months[$match[2]])) {
			return checkdate($months[$match[2]], (int)$match[1], (int)$match[3]) ? sprintf('%04d-%02d-%02d', $match[3], $months[$match[2]], $match[1]) : null;
		}
		return null;
	}

	private function commandTitle(string $input, array $remove): string {
		$title = preg_replace('/\b(' . implode('|', array_map('preg_quote', $remove)) . ')\b/iu', '', $input) ?? $input;
		$title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title, " \t\n\r\0\x0B,.;:");
		return mb_substr($title !== '' ? ucfirst($title) : 'Neuer Entwurf', 0, 250);
	}

	private function intentDate(string $input): string {
		$date = null;
		$today = new \DateTimeImmutable('today');
		if (preg_match('/\bheute\b/iu', $input)) $date = $today;
		elseif (preg_match('/\bmorgen\b/iu', $input)) $date = $today->modify('+1 day');
		elseif (preg_match('/\b(\d{1,2})[.](\d{1,2})[.](\d{4})\b/u', $input, $match) && checkdate((int)$match[2], (int)$match[1], (int)$match[3])) {
			$date = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $match[3], $match[2], $match[1]));
		}
		if ($date === null) return '';
		$time = '00:00';
		if (preg_match('/\b(?:um\s+)?([01]?\d|2[0-3])[:.]([0-5]\d)\s*(?:uhr)?\b/iu', $input, $match)) $time = sprintf('%02d:%02d', $match[1], $match[2]);
		elseif (preg_match('/\bum\s+([01]?\d|2[0-3])\s+uhr\b/iu', $input, $match)) $time = sprintf('%02d:00', $match[1]);
		return $date->format('Y-m-d') . 'T' . $time;
	}

	private function confirmationToken(string $intent, array $data): string {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null) throw new \RuntimeException('Kein Nextcloud-Benutzer angemeldet.');
		$payload = $this->base64Url(json_encode(['intent' => $intent, 'data' => $data, 'userId' => $userId, 'expiresAt' => time() + 300], JSON_THROW_ON_ERROR));
		$signature = $this->base64Url(hash_hmac('sha256', $payload, $this->config->getSystemValueString('secret'), true));
		return $payload . '.' . $signature;
	}

	private function verifyConfirmationToken(string $token): array {
		$parts = explode('.', $token, 2);
		if (count($parts) !== 2) throw new \InvalidArgumentException('Die Bestätigung ist ungültig. Bitte die Vorschau erneut aufrufen.');
		[$payload, $signature] = $parts;
		$expected = $this->base64Url(hash_hmac('sha256', $payload, $this->config->getSystemValueString('secret'), true));
		if (!hash_equals($expected, $signature)) throw new \InvalidArgumentException('Die Bestätigung ist ungültig. Bitte die Vorschau erneut aufrufen.');
		$decoded = base64_decode(strtr($payload, '-_', '+/'), true);
		$data = $decoded === false ? null : json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
		$userId = $this->userSession->getUser()?->getUID();
		if (!is_array($data) || $userId === null || ($data['userId'] ?? '') !== $userId || (int)($data['expiresAt'] ?? 0) < time()) {
			throw new \InvalidArgumentException('Die Bestätigung ist abgelaufen. Bitte die Vorschau erneut prüfen.');
		}
		return $data;
	}

	private function base64Url(string $value): string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}
}
