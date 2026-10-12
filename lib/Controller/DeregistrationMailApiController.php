<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\CaseService;
use OCA\Bestatter\Service\DeregistrationMailService;
use OCA\Bestatter\Service\ProcessService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Befund (Review 2026-09-22, Kundenmeldung zum Prozess "Abmeldungen"): "Der Mailversand ... wird
 * noch nicht angeboten." Analog zu BusinessMailApiController ist der Zugriff bereits app-weit durch
 * die BestatterAccessMiddleware auf Mitglieder der konfigurierten Bestatter-Nextcloud-Gruppe
 * beschränkt; es gibt bewusst keine zusätzliche Prüfung auf eine "zuständige Person" je Fall.
 */
class DeregistrationMailApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private CaseService $cases, private DeregistrationMailService $mail, private ProcessService $process) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function preview(int $caseId, int $recordId): DataResponse {
		$case = $this->cases->getCase($caseId);
		return new DataResponse($this->mail->preview($case, $recordId));
	}

	#[NoAdminRequired]
	public function history(int $caseId): DataResponse {
		$case = $this->cases->getCase($caseId);
		return new DataResponse($this->mail->history($case));
	}

	#[NoAdminRequired]
	public function send(int $caseId, int $recordId, string $mail = '{}'): DataResponse {
		$case = $this->cases->getCase($caseId);
		$input = json_decode($mail, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($input)) throw new \InvalidArgumentException('Ungültige Versanddaten.');
		$result = $this->mail->send($case, $recordId, $input);
		$this->process->reconcileQuietly($caseId);
		return new DataResponse($result);
	}
}
