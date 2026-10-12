<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\ArticleService;
use OCA\Bestatter\Service\ConfigurationService;
use OCA\Bestatter\Service\CustomizingService;
use OCA\Bestatter\Service\DocumentService;
use OCA\Bestatter\Service\TeamService;
use OCA\Bestatter\Service\TemplateFieldCatalogService;
use OCA\Bestatter\Service\InstallationConfigService;
use OCA\Bestatter\Service\AuditService;
use OCA\Bestatter\Service\SurchargeRuleService;
use OCA\Bestatter\Service\BurialVariantRuleService;
use OCA\Bestatter\Service\CatalogStructureService;
use OCA\Bestatter\Service\PriceListService;
use OCA\Bestatter\Service\CaseStorageService;
use OCA\Bestatter\Service\StorageMigrationService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class CatalogApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private CustomizingService $customizingService, private ArticleService $articles, private ConfigurationService $configuration, private TeamService $teamService, private TemplateFieldCatalogService $templateFields, private InstallationConfigService $installationConfig, private \OCA\Bestatter\Service\CaseFolderPlanService $folderPlan, private AuditService $audit, private SurchargeRuleService $surcharges, private BurialVariantRuleService $burialRules, private DocumentService $documents, private CatalogStructureService $structure, private PriceListService $priceLists, private CaseStorageService $storage, private StorageMigrationService $storageMigration) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function customizing(): DataResponse {
		$this->customizingService->ensureSeedData();
		return new DataResponse($this->customizingService->overview());
	}

	#[NoAdminRequired]
	public function surchargeRules(): DataResponse {
		return new DataResponse($this->surcharges->all());
	}

	#[NoAdminRequired]
	public function createSurchargeRule(string $rule = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$saved = $this->surcharges->save(json_decode($rule, true, 512, JSON_THROW_ON_ERROR));
		$this->audit->logSystem('SURCHARGE_RULE', 'CREATED', $saved);
		return new DataResponse($saved, 201);
	}

	#[NoAdminRequired]
	public function updateSurchargeRule(int $id, string $rule = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$saved = $this->surcharges->save(json_decode($rule, true, 512, JSON_THROW_ON_ERROR), $id);
		$this->audit->logSystem('SURCHARGE_RULE', 'UPDATED', $saved);
		return new DataResponse($saved);
	}

	#[NoAdminRequired]
	public function burialVariantRules(): DataResponse {
		return new DataResponse($this->burialRules->all());
	}

	#[NoAdminRequired]
	public function createBurialVariantRule(string $rule = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$saved = $this->burialRules->save(json_decode($rule, true, 512, JSON_THROW_ON_ERROR));
		$this->audit->logSystem('BURIAL_VARIANT_RULE', 'CREATED', $saved);
		return new DataResponse($saved, 201);
	}

	#[NoAdminRequired]
	public function updateBurialVariantRule(int $id, string $rule = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$saved = $this->burialRules->save(json_decode($rule, true, 512, JSON_THROW_ON_ERROR), $id);
		$this->audit->logSystem('BURIAL_VARIANT_RULE', 'UPDATED', $saved);
		return new DataResponse($saved);
	}

	#[NoAdminRequired]
	public function updateCustomizingItem(int $id, string $value = '', string $label = '', string $parentValue = '', string $funeralScope = ''): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->customizingService->updateItem($id, $value, $label, $parentValue, $funeralScope));
	}

	#[NoAdminRequired]
	public function deleteCustomizingItem(int $id): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$this->customizingService->deleteItem($id);
		return new DataResponse([], 204);
	}

	#[NoAdminRequired]
	public function addCustomizingItem(string $key, string $value = '', string $label = '', string $parentValue = '', string $funeralScope = ''): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->customizingService->addItem($key, $value, $label, $parentValue, $funeralScope), 201);
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

	/** 0.72.0: Ordnerplan der Fallakte (Kennzeichen, Namen, Verwendung). */
	#[NoAdminRequired]
	public function folderPlan(): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->folderPlan->current());
	}

	/** Vorschau einer Planänderung (verändert nichts). */
	#[NoAdminRequired]
	public function previewFolderPlan(string $plan = '[]', string $functions = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->folderPlan->preview(json_decode($plan, true, 512, JSON_THROW_ON_ERROR), json_decode($functions, true, 512, JSON_THROW_ON_ERROR) ?: []));
	}

	/** Planänderung übernehmen: vorhandene Fallordner umbenennen, Verweise umstellen, Plan speichern. */
	#[NoAdminRequired]
	public function applyFolderPlan(string $plan = '[]', string $functions = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$result = $this->folderPlan->apply(json_decode($plan, true, 512, JSON_THROW_ON_ERROR), json_decode($functions, true, 512, JSON_THROW_ON_ERROR) ?: []);
		return new DataResponse($result + ['current' => $this->folderPlan->current(), 'settings' => $this->installationConfig->settings()]);
	}

	/** 0.70.0 (OP-040): Zustand der Fallablage mit Bestandsaufnahme (Trockenlauf, ohne Dateiliste). */
	#[NoAdminRequired]
	public function storageStatus(): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$status = $this->storage->status();
		$plan = null; $planError = '';
		if ($status['account'] !== '' && $status['accountValid']) {
			try { $plan = $this->storageMigration->plan(); unset($plan['items']); } catch (\Throwable $error) { $planError = $error->getMessage(); }
		}
		return new DataResponse(['status' => $status, 'plan' => $plan, 'planError' => $planError, 'command' => 'occ bestatter:storage-migrate']);
	}

	/** Ablagekonto prüfen und vorbereiten; zentrale Ablage direkt aktivieren, solange keine persönlichen Fallakten umzuziehen sind. */
	#[NoAdminRequired]
	public function saveStorage(string $account = '', bool $activate = false): DataResponse {
		$this->teamService->requireBestatterAdmin();
		// Freigaben nur bei aktiver zentraler Ablage ergänzen („Freigaben prüfen“); vor der Aktivierung keine Freigabe anlegen.
		$prepared = $this->storage->prepareAccount($account, $this->storage->isCentral());
		$this->installationConfig->setStorage($this->installationConfig->storageMode(), $prepared['account']);
		if ($activate && !$this->storage->isCentral()) {
			$plan = $this->storageMigration->plan($prepared['account']);
			if ($plan['files'] > 0) throw new \InvalidArgumentException('Es liegen ' . $plan['files'] . ' Dateien in persönlichen Ordnern. Bitte den Umzug mit „occ bestatter:storage-migrate“ ausführen (erst Trockenlauf, dann --execute); danach ist die zentrale Ablage aktiv.');
			$prepared = $this->storage->activate('CENTRAL', $prepared['account']);
		}
		$this->audit->logSystem('STORAGE', $activate ? 'ACTIVATED' : 'ACCOUNT_PREPARED', ['account' => $prepared['account'], 'location' => $prepared['location'], 'createdShares' => $prepared['createdShares']]);
		return $this->storageStatus();
	}

	#[NoAdminRequired]
	public function articles(): DataResponse { return new DataResponse($this->articles->catalog()); }

	#[NoAdminRequired]
	public function createArticle(string $article = '{}', string $components = '[]'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$saved = $this->articles->save(json_decode($article, true, 512, JSON_THROW_ON_ERROR), json_decode($components, true, 512, JSON_THROW_ON_ERROR));
		$this->audit->logSystem('ARTICLE', 'CREATED', ['id' => $saved['id'], 'articleNumber' => $saved['articleNumber']]);
		return new DataResponse($saved, 201);
	}

	#[NoAdminRequired]
	public function updateArticle(int $id, string $article = '{}', string $components = '[]'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$saved = $this->articles->save(json_decode($article, true, 512, JSON_THROW_ON_ERROR), json_decode($components, true, 512, JSON_THROW_ON_ERROR), $id);
		$this->audit->logSystem('ARTICLE', 'UPDATED', ['id' => $saved['id'], 'articleNumber' => $saved['articleNumber']]);
		return new DataResponse($saved);
	}

	#[NoAdminRequired]
	public function nextArticleNumber(string $group = ''): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse(['articleNumber' => $this->articles->suggestArticleNumber($group)]);
	}

	#[NoAdminRequired]
	public function installSampleCatalog(bool $deactivateLegacy = true): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$result = $this->articles->installSampleCatalog($deactivateLegacy);
		$this->audit->logSystem('ARTICLE_CATALOG', 'SAMPLE_INSTALLED', $result);
		return new DataResponse($result + $this->articles->catalog());
	}

	#[NoAdminRequired]
	public function createArticleGroup(string $group = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$saved = $this->structure->saveGroup(json_decode($group, true, 512, JSON_THROW_ON_ERROR), true);
		$this->audit->logSystem('ARTICLE_GROUP', 'CREATED', $saved);
		return new DataResponse($this->articles->catalog(), 201);
	}

	#[NoAdminRequired]
	public function updateArticleGroup(string $key, string $group = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$data = json_decode($group, true, 512, JSON_THROW_ON_ERROR);
		$data['key'] = $key;
		$saved = $this->structure->saveGroup($data, false);
		$this->audit->logSystem('ARTICLE_GROUP', 'UPDATED', $saved);
		return new DataResponse($this->articles->catalog());
	}

	#[NoAdminRequired]
	public function deleteArticleGroup(string $key): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$this->structure->deleteGroup($key);
		$this->audit->logSystem('ARTICLE_GROUP', 'DELETED', ['key' => $key]);
		return new DataResponse($this->articles->catalog());
	}

	#[NoAdminRequired]
	public function saveArticleArea(string $key, string $area = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$data = json_decode($area, true, 512, JSON_THROW_ON_ERROR);
		$data['key'] = $key;
		$saved = $this->structure->saveArea($data);
		$this->audit->logSystem('ARTICLE_AREA', 'UPDATED', $saved);
		return new DataResponse($this->articles->catalog());
	}

	#[NoAdminRequired]
	public function priceLists(): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->priceLists->lists());
	}

	#[NoAdminRequired]
	public function createPriceList(string $branchKey = '', string $name = ''): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$saved = $this->priceLists->createBranchList($branchKey, $name);
		$this->audit->logSystem('PRICE_LIST', 'CREATED', $saved);
		return new DataResponse($saved, 201);
	}

	#[NoAdminRequired]
	public function updatePriceList(int $id, string $priceList = '{}'): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$saved = $this->priceLists->updateList($id, json_decode($priceList, true, 512, JSON_THROW_ON_ERROR));
		$this->audit->logSystem('PRICE_LIST', 'UPDATED', $saved);
		return new DataResponse($saved);
	}

	#[NoAdminRequired]
	public function deletePriceList(int $id): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$this->priceLists->deleteList($id);
		$this->audit->logSystem('PRICE_LIST', 'DELETED', ['id' => $id]);
		return new DataResponse([], 204);
	}

	#[NoAdminRequired]
	public function priceListItems(int $id): DataResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataResponse($this->priceLists->items($id));
	}

	#[NoAdminRequired]
	public function setPriceListItem(int $id, int $articleId = 0, string $price = '', string $validFrom = '', string $validTo = ''): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$cents = (int)round((float)str_replace(',', '.', trim($price)) * 100);
		if ($articleId <= 0 || trim($price) === '') throw new \InvalidArgumentException('Bitte Artikel und Preis angeben.');
		$this->articles->article($articleId);
		$this->priceLists->setPrice($id, $articleId, $cents, $validFrom, $validTo !== '' ? $validTo : null);
		$this->audit->logSystem('PRICE_LIST_ITEM', 'SAVED', ['priceListId' => $id, 'articleId' => $articleId, 'priceCents' => $cents, 'validFrom' => $validFrom, 'validTo' => $validTo]);
		return new DataResponse($this->priceLists->items($id));
	}

	#[NoAdminRequired]
	public function deletePriceListItem(int $id): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$this->priceLists->deleteItem($id);
		$this->audit->logSystem('PRICE_LIST_ITEM', 'DELETED', ['id' => $id]);
		return new DataResponse([], 204);
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
	public function exportArticlesCsv(): DataDownloadResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataDownloadResponse($this->articles->exportCsv(), 'Bestatter-Leistungskatalog-' . date('Y-m-d') . '.csv', 'text/csv; charset=UTF-8');
	}

	#[NoAdminRequired]
	public function exportArticleGroupsCsv(): DataDownloadResponse {
		$this->teamService->requireBestatterAdmin();
		return new DataDownloadResponse($this->structure->exportGroupsCsv(), 'Bestatter-Warengruppen-' . date('Y-m-d') . '.csv', 'text/csv; charset=UTF-8');
	}

	#[NoAdminRequired]
	public function importArticleGroups(): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$result = $this->structure->importGroupsCsv($this->uploadedCsv());
		$this->audit->logSystem('ARTICLE_GROUPS', 'CSV_IMPORTED', $result);
		return new DataResponse($result + $this->articles->catalog());
	}

	#[NoAdminRequired]
	public function exportPriceListCsv(int $id): DataDownloadResponse {
		$this->teamService->requireBestatterAdmin();
		$name = preg_replace('/[^A-Za-z0-9ÄÖÜäöüß_-]+/u', '-', $this->priceLists->listName($id)) ?: 'Preisliste';
		return new DataDownloadResponse($this->priceLists->exportCsv($id), 'Bestatter-' . trim($name, '-') . '-' . date('Y-m-d') . '.csv', 'text/csv; charset=UTF-8');
	}

	#[NoAdminRequired]
	public function importPriceList(int $id): DataResponse {
		$this->teamService->requireBestatterAdmin();
		$result = $this->priceLists->importCsv($id, $this->uploadedCsv());
		$this->audit->logSystem('PRICE_LIST', 'CSV_IMPORTED', ['priceListId' => $id] + $result);
		return new DataResponse($result + ['items' => $this->priceLists->items($id), 'lists' => $this->priceLists->lists()] + $this->articles->catalog());
	}

	private function uploadedCsv(): string {
		$upload = $this->apiRequest->getUploadedFile('file');
		$csv = is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK ? (string)file_get_contents((string)$upload['tmp_name']) : '';
		if (trim($csv) === '') throw new \InvalidArgumentException('Bitte eine CSV-Datei auswählen.');
		return $csv;
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
	public function previewDocumentTemplate(string $fileName): DataDownloadResponse {
		$this->teamService->requireBestatterAdmin();
		$preview = $this->documents->previewTemplateFilePdf($fileName);
		return new DataDownloadResponse($preview['content'], $preview['fileName'], 'application/pdf');
	}

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
