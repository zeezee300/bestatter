<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\MailTemplateService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** 0.89.3: E-Mail-Vorlagen für Auftrag, Kostenvoranschlag und Rechnung (Speichern nur Bestatter-Administration). */
class MailTemplateApiController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private MailTemplateService $templates,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function catalog(): DataResponse {
		return new DataResponse($this->templates->catalog());
	}

	#[NoAdminRequired]
	public function save(string $key, string $subject = '', string $body = ''): DataResponse {
		return new DataResponse($this->templates->save($key, $subject, $body));
	}

	#[NoAdminRequired]
	public function saveSignature(string $signatureExtra = ''): DataResponse {
		return new DataResponse($this->templates->saveSignatureExtra($signatureExtra));
	}

	#[NoAdminRequired]
	public function preview(string $key, string $subject = '', string $body = ''): DataResponse {
		return new DataResponse($this->templates->preview($key, $subject, $body));
	}
}
