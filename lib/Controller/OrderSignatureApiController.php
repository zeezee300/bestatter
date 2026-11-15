<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\OrderSignatureService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** 0.89.0: Unterschrift des Bestattungsauftrags vor Ort am Tablet. */
class OrderSignatureApiController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private OrderSignatureService $signatures,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function status(int $caseId): DataResponse {
		return new DataResponse($this->signatures->status($caseId));
	}

	#[NoAdminRequired]
	public function sign(int $caseId, string $data = '{}'): DataResponse {
		$input = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
		return new DataResponse($this->signatures->sign($caseId, is_array($input) ? $input : [], $this->request->getHeader('User-Agent')), 201);
	}
}
