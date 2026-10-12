<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\DocumentKindService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** 0.80.0 – Dokumentarten (OP-058): Lesen für alle Mitglieder, Pflege nur Bestatter-Administration (im Service geprüft). */
class DocumentKindApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private DocumentKindService $kinds) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function catalog(): DataResponse { return new DataResponse($this->kinds->catalog()); }

	#[NoAdminRequired]
	public function create(string $kind = '{}'): DataResponse { return new DataResponse($this->kinds->create($this->decode($kind)), 201); }

	#[NoAdminRequired]
	public function update(string $key, string $kind = '{}'): DataResponse { return new DataResponse($this->kinds->update($key, $this->decode($kind))); }

	#[NoAdminRequired]
	public function delete(string $key): DataResponse { $this->kinds->delete($key); return new DataResponse([], 204); }

	private function decode(string $json): array {
		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		return is_array($data) ? $data : [];
	}
}
