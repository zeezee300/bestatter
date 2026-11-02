<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\TypicalCostService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** 0.89.5: Typische Fremdkosten (Customizing → Fremdkosten) und Vorschläge je Fall. Speichern nur Bestatter-Administration. */
class TypicalCostApiController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private TypicalCostService $costs,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function settings(): DataResponse {
		return new DataResponse($this->costs->settings());
	}

	#[NoAdminRequired]
	public function save(string $settings = '{}'): DataResponse {
		$input = json_decode($settings, true, 512, JSON_THROW_ON_ERROR);
		return new DataResponse($this->costs->save(is_array($input) ? $input : []));
	}

	#[NoAdminRequired]
	public function forCase(int $caseId): DataResponse {
		return new DataResponse($this->costs->forCase($caseId));
	}
}
