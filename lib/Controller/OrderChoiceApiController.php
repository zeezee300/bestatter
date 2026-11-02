<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\OrderChoiceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** 0.89.3: Ankreuzfelder im Auftrag (Customizing). Lesen für alle Mitglieder, Speichern nur Bestatter-Administration. */
class OrderChoiceApiController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private OrderChoiceService $choices,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function catalog(): DataResponse {
		return new DataResponse($this->choices->catalog());
	}

	#[NoAdminRequired]
	public function save(string $fields = '[]'): DataResponse {
		$list = json_decode($fields, true, 512, JSON_THROW_ON_ERROR);
		return new DataResponse($this->choices->save(is_array($list) ? $list : []));
	}
}
