<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\PaperlessInboxService;
use OCA\Bestatter\Service\PaperlessService;
use OCA\Bestatter\Service\TeamService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class IntegrationApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private TeamService $team, private PaperlessService $paperless, private PaperlessInboxService $inbox) { parent::__construct($appName, $request); }

	#[NoAdminRequired]
	public function paperlessConfiguration(): DataResponse { $this->team->requireBestatterAdmin(); return new DataResponse($this->paperless->settings()); }
	#[NoAdminRequired]
	public function savePaperlessConfiguration(string $configuration='{}'): DataResponse { $this->team->requireBestatterAdmin(); return new DataResponse($this->paperless->saveSettings(json_decode($configuration,true,512,JSON_THROW_ON_ERROR))); }
	#[NoAdminRequired]
	public function testPaperless(): DataResponse { $this->team->requireBestatterAdmin(); return new DataResponse($this->paperless->connectionTest()); }
	#[NoAdminRequired]
	public function paperlessInbox(): DataResponse { return new DataResponse($this->inbox->inbox()); }
	#[NoAdminRequired]
	public function assignPaperlessDocument(int $id, int $caseId, string $data='{}'): DataResponse { return new DataResponse($this->inbox->assign($id,$caseId,json_decode($data,true,512,JSON_THROW_ON_ERROR)),201); }
	#[NoAdminRequired]
	public function retryPaperless(int $id): DataResponse { return new DataResponse($this->paperless->retry($id)); }
}
