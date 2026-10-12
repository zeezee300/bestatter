<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\PaperlessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class PaperlessWebhookController extends Controller {
	public function __construct(string $appName, private IRequest $webhookRequest, private PaperlessService $paperless) { parent::__construct($appName,$webhookRequest); }

	#[PublicPage]
	#[NoCSRFRequired]
	public function receive(): JSONResponse {
		try {
			// IRequest::getContent() ist in Nextcloud nicht öffentlich (geschützt) – der Aufruf scheiterte bis 0.68.0
			// mit einem Fehler (HTTP 500). Nextcloud dekodiert JSON-Bodies bereits in die Request-Parameter.
			$payload=array_intersect_key($this->webhookRequest->getParams(),array_flip(['event','document_id','documentId','id','title']));
			if($payload===[])throw new \InvalidArgumentException('Ungültiger Webhook-Inhalt.');
			return new JSONResponse($this->paperless->receiveWebhook($this->webhookRequest->getHeader('X-Bestatter-Webhook-Token'),$payload),Http::STATUS_ACCEPTED);
		} catch (\JsonException|\InvalidArgumentException $error) {
			$status=str_contains($error->getMessage(),'autorisiert')?Http::STATUS_FORBIDDEN:Http::STATUS_BAD_REQUEST;
			return new JSONResponse(['accepted'=>false,'message'=>$status===Http::STATUS_FORBIDDEN?'Webhook nicht autorisiert.':'Webhook-Daten sind ungültig.'],$status);
		} catch (\Throwable) {
			return new JSONResponse(['accepted'=>false,'message'=>'Webhook konnte nicht verarbeitet werden.'],Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
