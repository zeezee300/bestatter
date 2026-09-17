<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\BusinessMailService;
use OCA\Bestatter\Service\CaseService;
use OCA\Bestatter\Service\TeamService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class BusinessMailApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private CaseService $cases, private BusinessMailService $businessMail, private TeamService $team) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function availability(int $caseId): DataResponse {
		$case = $this->cases->getCase($caseId);
		$availability = $this->businessMail->availability();
		if (!$this->team->canEditCase($case)) return new DataResponse(['enabled' => false, 'sender' => '', 'reason' => 'Versand ist nur für die zuständige Person oder Bestatter-Administratoren freigegeben.']);
		return new DataResponse($availability);
	}

	#[NoAdminRequired]
	public function preview(int $caseId, int $recordId): DataResponse {
		$case = $this->cases->getCase($caseId);
		$this->team->requireCaseEditor($case);
		return new DataResponse($this->businessMail->preview($case, $recordId));
	}

	#[NoAdminRequired]
	public function history(int $caseId): DataResponse {
		$case = $this->cases->getCase($caseId);
		$this->team->requireCaseEditor($case);
		return new DataResponse($this->businessMail->history($case));
	}

	#[NoAdminRequired]
	public function send(int $caseId, int $recordId, string $mail = '{}'): DataResponse {
		$case = $this->cases->getCase($caseId);
		$this->team->requireCaseEditor($case);
		$input = json_decode($mail, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($input)) throw new \InvalidArgumentException('Ungültige Versanddaten.');
		return new DataResponse($this->businessMail->send($case, $recordId, $input));
	}
}
