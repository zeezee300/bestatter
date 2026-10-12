<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\ReportingService;
use OCA\Bestatter\Service\TeamService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ReportingApiController extends ApiController {
	public function __construct(string $appName,IRequest $request,private ReportingService $reporting,private TeamService $teamService){parent::__construct($appName,$request);}
	#[NoAdminRequired]
	public function summary(string $from='',string $to='',string $branch='ALL'): DataResponse { $this->teamService->requireBestatterAdmin(); return new DataResponse($this->reporting->summary($from,$to,$branch)); }
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function csv(string $reportType,string $from='',string $to='',string $branch='ALL'): DataDownloadResponse { $this->teamService->requireBestatterAdmin(); $safe=strtolower(preg_replace('/[^a-z]/i','',$reportType)); return new DataDownloadResponse($this->reporting->csv($safe,$from,$to,$branch),'Bestatter-'.$safe.'-'.date('Ymd').'.csv','text/csv; charset=UTF-8'); }
}
