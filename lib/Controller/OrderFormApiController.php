<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\OrderFormService;
use OCA\Bestatter\Service\TeamService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** 0.74.0 – Auftragsformular-Vorlagen (Pflege: Bestatter-Administration) und Erfassung im Fall (Mitglieder). */
class OrderFormApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private OrderFormService $forms, private TeamService $team) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse(['forms' => $this->forms->forms()]);
	}

	#[NoAdminRequired]
	public function save(int $id, string $form = '{}'): DataResponse {
		$this->team->requireBestatterAdmin();
		$input = json_decode($form, true, 512, JSON_THROW_ON_ERROR);
		return new DataResponse($this->forms->save($id, is_array($input) ? $input : []), $id === 0 ? 201 : 200);
	}

	#[NoAdminRequired]
	public function delete(int $id): DataResponse {
		$this->team->requireBestatterAdmin();
		return new DataResponse($this->forms->delete($id));
	}

	#[NoAdminRequired]
	public function installSample(): DataResponse {
		$this->team->requireBestatterAdmin();
		return new DataResponse($this->forms->installSample(), 201);
	}

	/** 0.75.2: Vorlage als Austauschdatei herunterladen. */
	#[NoAdminRequired]
	public function export(int $id): DataDownloadResponse {
		$this->team->requireBestatterAdmin();
		$file = $this->forms->export($id);
		return new DataDownloadResponse($file['content'], $file['filename'], 'application/json; charset=UTF-8');
	}

	/** 0.75.2: Austauschdatei einspielen (legt immer eine neue Vorlage an). */
	#[NoAdminRequired]
	public function import(string $content = ''): DataResponse {
		$this->team->requireBestatterAdmin();
		return new DataResponse($this->forms->import($content), 201);
	}

	#[NoAdminRequired]
	public function caseState(int $caseId): DataResponse {
		return new DataResponse($this->forms->caseState($caseId));
	}

	#[NoAdminRequired]
	public function saveCaseEntry(int $caseId, int $formId, string $values = '{}', string $declaredTotal = '', bool $apply = false, string $amendmentReason = ''): DataResponse {
		$decoded = json_decode($values, true, 512, JSON_THROW_ON_ERROR);
		$declared = trim($declaredTotal);
		$declaredCents = null;
		if ($declared !== '') {
			$normalized = preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $declared) === 1 ? str_replace(['.', ','], ['', '.'], $declared) : str_replace(',', '.', $declared);
			if (preg_match('/^\d+(\.\d{1,2})?$/', $normalized) !== 1) throw new \InvalidArgumentException('Die Summe laut Papierformular ist keine gültige Eurosumme.');
			$declaredCents = (int)round((float)$normalized * 100);
		}
		return new DataResponse($this->forms->saveEntry($caseId, $formId, is_array($decoded) ? $decoded : [], $declaredCents, $apply, $amendmentReason));
	}
}
