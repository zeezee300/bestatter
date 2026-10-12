<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\BranchLogoService;
use OCA\Bestatter\Service\SetupPackageService;
use OCA\Bestatter\Service\TeamService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** 0.75.3 – Einrichtungspaket (Export, Vorschau, Einspielen) und Logo je Niederlassung. Pflege nur für Bestatter-Administratoren. */
class SetupPackageApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private SetupPackageService $packages, private BranchLogoService $logos, private TeamService $team) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function exportPackage(string $customer = '', string $types = ''): DataDownloadResponse {
		$this->team->requireBestatterAdmin();
		$result = $this->packages->export($customer, array_values(array_filter(array_map('trim', explode(',', $types)))));
		return new DataDownloadResponse($result['content'], $result['fileName'], 'application/zip');
	}

	#[NoAdminRequired]
	public function previewPackage(): DataResponse {
		$this->team->requireBestatterAdmin();
		return new DataResponse($this->packages->preview($this->uploaded('file', SetupPackageService::MAX_BYTES)));
	}

	#[NoAdminRequired]
	public function applyPackage(string $decisions = '{}'): DataResponse {
		$this->team->requireBestatterAdmin();
		$decoded = json_decode($decisions, true, 16, JSON_THROW_ON_ERROR);
		return new DataResponse($this->packages->apply($this->uploaded('file', SetupPackageService::MAX_BYTES), is_array($decoded) ? array_map('strval', $decoded) : []));
	}

	#[NoAdminRequired]
	public function branchLogos(): DataResponse {
		return new DataResponse(['logos' => $this->logos->index()]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function branchLogo(string $key): DataDisplayResponse {
		$png = $this->logos->logo($key);
		if ($png === null) return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		return new DataDisplayResponse($png, Http::STATUS_OK, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-cache']);
	}

	#[NoAdminRequired]
	public function saveBranchLogo(string $key): DataResponse {
		$this->team->requireBestatterAdmin();
		return new DataResponse($this->logos->save($key, $this->uploaded('file', 2 * 1024 * 1024)));
	}

	#[NoAdminRequired]
	public function deleteBranchLogo(string $key): DataResponse {
		$this->team->requireBestatterAdmin();
		$this->logos->delete($key);
		return new DataResponse(['deleted' => true]);
	}

	#[NoAdminRequired]
	public function logoPlaceholder(): DataDownloadResponse {
		return new DataDownloadResponse((string)file_get_contents(BranchLogoService::PLACEHOLDER_FILE), 'Logo-Platzhalter.png', 'image/png');
	}

	private function uploaded(string $field, int $maxBytes): string {
		$upload = $this->apiRequest->getUploadedFile($field);
		if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new \InvalidArgumentException('Bitte eine Datei auswählen.');
		if ((int)($upload['size'] ?? 0) > $maxBytes) throw new \InvalidArgumentException('Die Datei ist zu groß.');
		return (string)file_get_contents((string)$upload['tmp_name']);
	}
}
