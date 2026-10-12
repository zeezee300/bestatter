<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\ProcessService;
use OCA\Bestatter\Service\SideOrderService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class SideOrderApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private SideOrderService $sideOrders, private ProcessService $process) { parent::__construct($appName, $request); }

	#[NoAdminRequired]
	public function listSideOrders(int $caseId): DataResponse { return new DataResponse($this->sideOrders->all($caseId)); }

	#[NoAdminRequired]
	public function createSideOrder(int $caseId, string $data = '{}'): DataResponse { $result = $this->sideOrders->create($caseId, json_decode($data, true, 512, JSON_THROW_ON_ERROR)); $this->process->reconcileQuietly($caseId); return new DataResponse($result, 201); }

	#[NoAdminRequired]
	public function updateSideOrder(int $id, string $data = '{}'): DataResponse { return new DataResponse($this->sideOrders->update($id, json_decode($data, true, 512, JSON_THROW_ON_ERROR))); }

	#[NoAdminRequired]
	public function cancelSideOrder(int $id): DataResponse { return new DataResponse($this->reconciled($this->sideOrders->cancel($id))); }

	#[NoAdminRequired]
	public function transitionSideOrder(int $id, string $status = ''): DataResponse { return new DataResponse($this->reconciled($this->sideOrders->transition($id, $status))); }

	/** Ablaufauslöser „Nebenauftrag beauftragt/abgeschlossen“ sofort abgleichen. */
	private function reconciled(array $sideOrder): array {
		if ((int)($sideOrder['caseId'] ?? 0) > 0) $this->process->reconcileQuietly((int)$sideOrder['caseId']);
		return $sideOrder;
	}
}
