<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\CaseService;
use OCA\Bestatter\Service\OperationalService;
use OCA\Bestatter\Service\StabilizationService;
use OCA\Bestatter\Service\TeamService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class DashboardApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private CaseService $caseService, private OperationalService $operations, private TeamService $teamService, private StabilizationService $stabilization) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function dashboard(): DataResponse { return new DataResponse($this->caseService->dashboard()); }

	#[NoAdminRequired]
	public function personalDay(string $date = ''): DataResponse { return new DataResponse($this->operations->personalDay($date)); }

	#[NoAdminRequired]
	public function team(): DataResponse { return new DataResponse($this->teamService->overview()); }

	#[NoAdminRequired]
	public function systemCheck(): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->stabilization->report());
	}
}
