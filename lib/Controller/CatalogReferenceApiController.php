<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\CatalogReferenceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** 0.89.5: Verweisprüfung (Warengruppen und Artikel in Ankreuzfeldern, typischen Fremdkosten, Variantenregeln, Zuschlagsstaffeln). */
class CatalogReferenceApiController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CatalogReferenceService $references,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function check(): DataResponse {
		return new DataResponse($this->references->check());
	}
}
