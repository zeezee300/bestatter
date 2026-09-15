<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\ArticleService;
use OCA\Bestatter\Service\ConfigurationService;
use OCA\Bestatter\Service\CustomizingService;
use OCA\Bestatter\Service\TeamService;
use OCA\Bestatter\Service\TemplateFieldCatalogService;
use OCA\Bestatter\Service\InstallationConfigService;
use OCA\Bestatter\Service\AuditService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class CatalogApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private CustomizingService $customizingService, private ArticleService $articles, private ConfigurationService $configuration, private TeamService $teamService, private TemplateFieldCatalogService $templateFields, private InstallationConfigService $installationConfig, private AuditService $audit) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function customizing(): DataResponse {
		$this->customizingService->ensureSeedData();
		return new DataResponse($this->customizingService->overview());
	}

	#[NoAdminRequired]
	public function updateCustomizingItem(int $id, string $value = '', string $label = ''): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->customizingService->updateItem($id, $value, $label));
	}

	#[NoAdminRequired]
	public function deleteCustomizingItem(int $id): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$this->customizingService->deleteItem($id);
		return new DataResponse([], 204);
	}

	#[NoAdminRequired]
	public function addCustomizingItem(string $key, string $value = '', string $label = ''): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->customizingService->addItem($key, $value, $label), 201);
	}

	#[NoAdminRequired]
	public function reorderCustomizingItems(string $key, string $itemIds = '[]'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$ids = json_decode($itemIds, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($ids)) throw new \InvalidArgumentException('Die neue Reihenfolge ist ungültig.');
		return new DataResponse($this->customizingService->reorderItems($key, $ids));
	}

	#[NoAdminRequired]
	public function caseSchema(): DataResponse { return new DataResponse($this->customizingService->caseFieldSchema()); }

	#[NoAdminRequired]
	public function installationSettings(): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->installationConfig->settings());
	}

	#[NoAdminRequired]
	public function saveInstallationSettings(string $settings = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$before=$this->installationConfig->settings(); $saved=$this->installationConfig->save(json_decode($settings, true, 512, JSON_THROW_ON_ERROR)); $this->audit->logSystem('INSTALLATION_CONFIGURATION','UPDATED',['before'=>$before,'after'=>$saved]); return new DataResponse($saved);
	}

	#[NoAdminRequired]
	public function articles(): DataResponse { return new DataResponse($this->articles->catalog()); }

	#[NoAdminRequired]
	public function createArticle(string $article = '{}', string $components = '[]'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->articles->save(json_decode($article, true, 512, JSON_THROW_ON_ERROR), json_decode($components, true, 512, JSON_THROW_ON_ERROR)), 201);
	}

	#[NoAdminRequired]
	public function updateArticle(int $id, string $article = '{}', string $components = '[]'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->articles->save(json_decode($article, true, 512, JSON_THROW_ON_ERROR), json_decode($components, true, 512, JSON_THROW_ON_ERROR), $id));
	}

	#[NoAdminRequired]
	public function deleteArticle(int $id): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$this->articles->delete($id);
		return new DataResponse([], 204);
	}

	#[NoAdminRequired]
	public function importArticles(string $csv = ''): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$upload = $this->apiRequest->getUploadedFile('file');
		if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) $csv = (string)file_get_contents((string)$upload['tmp_name']);
		if ($csv === '') throw new \InvalidArgumentException('Bitte eine CSV-Datei auswählen.');
		return new DataResponse($this->articles->importCsv($csv));
	}

	#[NoAdminRequired]
	public function saveArticleGroup(string $groupName = '', bool $exclusiveSelection = false): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$this->articles->saveArticleGroupRule($groupName, $exclusiveSelection);
		return new DataResponse($this->articles->catalog());
	}

	#[NoAdminRequired]
	public function uploadArticlePhoto(int $id): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$upload = $this->apiRequest->getUploadedFile('file');
		if (!is_array($upload)) throw new \InvalidArgumentException('Bitte eine Bilddatei auswählen.');
		return new DataResponse($this->articles->savePhoto($id, $upload));
	}

	#[NoAdminRequired]
	public function caseServices(int $caseId, ?int $sideOrderId = null): DataResponse { return new DataResponse($this->articles->caseServices($caseId, $sideOrderId)); }

	#[NoAdminRequired]
	public function saveCaseServices(int $caseId, string $items = '[]', string $amendmentReason = '', ?int $sideOrderId = null): DataResponse {
		$selections = json_decode($items, true, 512, JSON_THROW_ON_ERROR);
		return new DataResponse($this->articles->saveCaseServices($caseId, is_array($selections) ? $selections : [], $amendmentReason, $sideOrderId));
	}

	#[NoAdminRequired]
	public function documentTemplates(): DataResponse { return new DataResponse($this->configuration->documents()); }

	#[NoAdminRequired]
	public function documentTemplateOptions(): DataResponse { return new DataResponse($this->configuration->documentTemplateOptions()); }

	#[NoAdminRequired]
	public function saveDocumentTemplate(int $id, string $template = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->configuration->saveDocument(json_decode($template, true, 512, JSON_THROW_ON_ERROR), $id));
	}

	#[NoAdminRequired]
	public function duplicateDocumentTemplate(int $id): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->configuration->duplicateDocument($id));
	}

	#[NoAdminRequired]
	public function provisionDocumentTemplates(): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse(['files' => $this->configuration->provisionTemplates()]);
	}

	#[NoAdminRequired]
	public function deregistrationTemplates(): DataResponse { return new DataResponse($this->configuration->deregistrations()); }

	#[NoAdminRequired]
	public function saveDeregistrationTemplate(int $id, string $template = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->configuration->saveDeregistration(json_decode($template, true, 512, JSON_THROW_ON_ERROR), $id));
	}

	#[NoAdminRequired]
	public function branches(): DataResponse { return new DataResponse($this->configuration->branches()); }

	#[NoAdminRequired]
	public function countryProfiles(): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->configuration->countryProfiles());
	}

	#[NoAdminRequired]
	public function saveCountryProfile(string $countryCode, string $profile = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$saved = $this->configuration->saveCountryProfile($countryCode, json_decode($profile, true, 512, JSON_THROW_ON_ERROR));
		$this->audit->logSystem('COUNTRY_PROFILE', 'UPDATED', ['countryCode' => $countryCode, 'profile' => $saved]);
		return new DataResponse($saved);
	}

	#[NoAdminRequired]
	public function saveBranch(int $id, string $branch = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$saved=$this->configuration->saveBranch(json_decode($branch, true, 512, JSON_THROW_ON_ERROR), $id); $this->audit->logSystem('BRANCH','UPDATED',['id'=>$saved['id']??$id,'key'=>$saved['key']??'']); return new DataResponse($saved);
	}

	#[NoAdminRequired]
	public function saveBranchBankData(int $id, string $bankData = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$saved=$this->configuration->saveBranchBankData($id, json_decode($bankData, true, 512, JSON_THROW_ON_ERROR)); $this->audit->logSystem('BRANCH_BANK_DATA','UPDATED',['branchId'=>$id,'changed'=>true]); return new DataResponse($saved);
	}

	#[NoAdminRequired]
	public function invoiceSettings(): DataResponse { return new DataResponse($this->configuration->invoiceSettings()); }

	#[NoAdminRequired]
	public function saveInvoiceSettings(string $settings = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$saved=$this->configuration->saveInvoiceSettings(json_decode($settings, true, 512, JSON_THROW_ON_ERROR)); $this->audit->logSystem('INVOICE_CONFIGURATION','UPDATED',$saved); return new DataResponse($saved);
	}

	#[NoAdminRequired]
	public function deleteConfiguration(string $type, int $id): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$this->configuration->delete($type, $id);
		$this->audit->logSystem('CONFIGURATION','DELETED',['type'=>$type,'id'=>$id]);
		return new DataResponse([], 204);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function templateFieldsCsv(): DataDownloadResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataDownloadResponse($this->templateFields->csv(), 'Bestatter-Dokumentvorlagen-Feldkatalog.csv', 'text/csv; charset=UTF-8');
	}
}
