<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\BusinessMailService;
use OCA\Bestatter\Service\CaseService;
use OCA\Bestatter\Service\ProcessService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Zugriff auf alle Endpunkte dieses Controllers ist bereits durch die
 * app-weite BestatterAccessMiddleware auf Mitglieder der konfigurierten
 * Bestatter-Nextcloud-Gruppe beschränkt. Es gibt bewusst KEINE zusätzliche
 * Prüfung auf eine "zuständige Person" je Fall: Team- und Rollenzugehörigkeit
 * (Mitglied/Administrator) werden ausschließlich über Nextcloud-Gruppen
 * gesteuert, damit sich Kolleg:innen jederzeit gegenseitig bei fremden
 * Fällen vertreten/aushelfen können.
 */
class BusinessMailApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private CaseService $cases, private BusinessMailService $businessMail, private ProcessService $process) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function availability(int $caseId): DataResponse {
		// $caseId wird nur validiert (löst bei unbekanntem Fall eine Exception aus);
		// die Verfügbarkeit des Geschäfts-Postfachs ist fallunabhängig.
		$this->cases->getCase($caseId);
		return new DataResponse($this->businessMail->availability());
	}

	#[NoAdminRequired]
	public function preview(int $caseId, int $recordId): DataResponse {
		$case = $this->cases->getCase($caseId);
		return new DataResponse($this->businessMail->preview($case, $recordId));
	}

	#[NoAdminRequired]
	public function history(int $caseId): DataResponse {
		$case = $this->cases->getCase($caseId);
		return new DataResponse($this->businessMail->history($case));
	}

	#[NoAdminRequired]
	public function send(int $caseId, int $recordId, string $mail = '{}'): DataResponse {
		$case = $this->cases->getCase($caseId);
		$input = json_decode($mail, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($input)) throw new \InvalidArgumentException('Ungültige Versanddaten.');
		$result = $this->businessMail->send($case, $recordId, $input);
		$this->process->reconcileQuietly($caseId);
		return new DataResponse($result);
	}
}
