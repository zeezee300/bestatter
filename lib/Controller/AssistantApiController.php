<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\AssistantService;
use OCA\Bestatter\Service\TeamService;
use OCA\Bestatter\Service\CaptureImportService;
use OCA\Bestatter\Service\CaseService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class AssistantApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private AssistantService $assistant, private TeamService $teamService, private CaptureImportService $captureImports, private CaseService $caseService) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function assistantConfiguration(): DataResponse { return new DataResponse($this->assistant->configuration()); }

	#[NoAdminRequired]
	public function assistantCatalog(): DataResponse { return new DataResponse($this->assistant->catalog()); }

	#[NoAdminRequired]
	public function saveAssistantConfiguration(string $configuration = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->assistant->saveConfiguration(json_decode($configuration, true, 512, JSON_THROW_ON_ERROR)));
	}

	#[NoAdminRequired]
	public function analyzeAssistantText(string $text = '', string $context = 'CASE_CAPTURE'): DataResponse { return new DataResponse($this->assistant->analyze($text, $context)); }

	#[NoAdminRequired]
	public function submitAssistantCorrections(string $corrections = '[]'): DataResponse { return new DataResponse($this->assistant->submitCorrections(json_decode($corrections, true, 512, JSON_THROW_ON_ERROR)), 202); }

	#[NoAdminRequired]
	public function assistantLearningRules(): DataResponse { $this->teamService->requireBestatterAdmin(); return new DataResponse($this->assistant->learningRules()); }

	#[NoAdminRequired]
	public function reviewAssistantLearningRule(int $id, string $status = ''): DataResponse { $this->teamService->requireBestatterAdmin(); return new DataResponse($this->assistant->reviewLearningRule($id, $status)); }

	#[NoAdminRequired]
	public function scheduleTranscription(): DataResponse {
		$upload = $this->apiRequest->getUploadedFile('audio');
		if (!is_array($upload)) throw new \InvalidArgumentException('Bitte eine Audioaufnahme auswählen.');
		return new DataResponse($this->assistant->scheduleTranscription($upload), 202);
	}

	#[NoAdminRequired]
	public function transcriptionStatus(int $taskId): DataResponse { return new DataResponse($this->assistant->transcriptionStatus($taskId)); }

	#[NoAdminRequired]
	public function createCaptureImport(string $templateKey = 'AUFTRAGSERFASSUNG_STANDARD', string $templateVersion = '1'): DataResponse {
		$upload=$this->apiRequest->getUploadedFile('document');
		if(!is_array($upload))throw new \InvalidArgumentException('Bitte einen Auftragserfassungsbogen als PDF auswählen.');
		return new DataResponse($this->captureImports->create($upload,$templateKey,$templateVersion),202);
	}

	#[NoAdminRequired]
	public function captureImportStatus(int $id): DataResponse { return new DataResponse($this->captureImports->status($id)); }

	#[NoAdminRequired]
	public function retryCaptureImport(int $id): DataResponse { return new DataResponse($this->captureImports->retry($id),202); }

	#[NoAdminRequired]
	public function completeCaptureImport(int $id, int $caseId = 0, string $confirmedFields = '[]'): DataResponse {
		if($caseId<=0)throw new \InvalidArgumentException('Der erzeugte Fall fehlt.');
		return new DataResponse($this->captureImports->complete($id, $this->caseService->getCase($caseId), json_decode($confirmedFields,true,512,JSON_THROW_ON_ERROR)));
	}

	#[NoAdminRequired]
	public function previewAssistantIntent(string $input = '', int $caseId = 0): DataResponse { return new DataResponse($this->assistant->previewIntent($input, $caseId)); }

	#[NoAdminRequired]
	public function executeAssistantIntent(string $confirmationToken = ''): DataResponse { return new DataResponse($this->assistant->executeIntent($confirmationToken), 201); }
}
