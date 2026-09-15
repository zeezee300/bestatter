<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\SideOrderService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class SideOrderApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private SideOrderService $sideOrders) { parent::__construct($appName, $request); }

	#[NoAdminRequired]
	public function listSideOrders(int $caseId): DataResponse { return new DataResponse($this->sideOrders->all($caseId)); }

	#[NoAdminRequired]
	public function createSideOrder(int $caseId, string $data = '{}'): DataResponse { return new DataResponse($this->sideOrders->create($caseId, json_decode($data, true, 512, JSON_THROW_ON_ERROR)), 201); }

	#[NoAdminRequired]
	public function updateSideOrder(int $id, string $data = '{}'): DataResponse { return new DataResponse($this->sideOrders->update($id, json_decode($data, true, 512, JSON_THROW_ON_ERROR))); }

	#[NoAdminRequired]
	public function cancelSideOrder(int $id): DataResponse { return new DataResponse($this->sideOrders->cancel($id)); }

	#[NoAdminRequired]
	public function transitionSideOrder(int $id, string $status = ''): DataResponse { return new DataResponse($this->sideOrders->transition($id, $status)); }
}
