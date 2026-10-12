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
	public function assignPaperlessDocument(int $id, int $caseId, string $data='{}', string $kind=''): DataResponse { return new DataResponse($this->inbox->assign($id,$caseId,$kind,json_decode($data,true,512,JSON_THROW_ON_ERROR) ?: []),201); }
	/** 0.80.0: Eingang nicht übernehmen (Paperless bleibt unverändert). */
	#[NoAdminRequired]
	public function discardPaperlessDocument(int $id, string $reason=''): DataResponse { return new DataResponse($this->inbox->discard($id,$reason)); }
	/** 0.80.0: Vorschlag für Dokumentart und Fall neu aus Paperless lesen. */
	#[NoAdminRequired]
	public function refreshPaperlessDocument(int $id): DataResponse { return new DataResponse($this->inbox->refresh($id)); }
	/** 0.80.0: versendete Abmeldungen eines Falls für die Dokumentart „Rückmeldung Abmeldestelle“. */
	#[NoAdminRequired]
	public function paperlessCaseDeregistrations(int $caseId): DataResponse { return new DataResponse($this->inbox->openDeregistrations($caseId)); }
	/** 0.80.0: Dokumenttypen aus Paperless für Customizing → Dokumentarten (nur Administration). */
	#[NoAdminRequired]
	public function paperlessDocumentTypes(): DataResponse { $this->team->requireBestatterAdmin(); try { return new DataResponse(['available'=>true,'types'=>$this->paperless->documentTypes()]); } catch (\Throwable $error) { return new DataResponse(['available'=>false,'types'=>[],'message'=>'Paperless ist nicht erreichbar oder nicht eingerichtet – die Dokumenttyp-ID kann von Hand eingetragen werden.']); } }
	#[NoAdminRequired]
	public function retryPaperless(int $id): DataResponse { return new DataResponse($this->paperless->retry($id)); }
}
