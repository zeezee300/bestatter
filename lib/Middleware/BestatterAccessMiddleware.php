<?php

declare(strict_types=1);

namespace OCA\Bestatter\Middleware;

use OCA\Bestatter\Controller\ApiController;
use OCA\Bestatter\Controller\PaperlessWebhookController;
use OCA\Bestatter\Exception\BestatterAccessDeniedException;
use OCA\Bestatter\Service\TeamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use Psr\Log\LoggerInterface;

/**
 * Denies every controller request of this app unless the current user has a
 * Bestatter role. The middleware is intentionally app-wide and has no route
 * allowlist, so newly added endpoints are protected automatically.
 */
class BestatterAccessMiddleware extends Middleware {
	public function __construct(
		private TeamService $teamService,
		private LoggerInterface $logger,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void {
		// Einzige öffentliche Integrationsroute; sie authentifiziert sich selbst mit
		// einem separaten, konstantzeitlich geprüften Webhook-Geheimnis.
		if ($controller instanceof PaperlessWebhookController && $methodName === 'receive') return;
		$this->teamService->requireBestatterMember();
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
		if ($exception instanceof BestatterAccessDeniedException) {
			$this->logger->warning('Nicht autorisierter Zugriff auf die Bestatter-Anwendung wurde abgewiesen.', [
				'controller' => $controller::class,
				'method' => $methodName,
			]);
			if (!($controller instanceof ApiController)) {
				$response = new TemplateResponse('core', '403', ['message' => $exception->getMessage()], 'guest');
				$response->setStatus(Http::STATUS_FORBIDDEN);
				$response->addHeader('Cache-Control', 'no-store');
				return $response;
			}
			$response = new JSONResponse([
				'code' => 'BESTATTER_MEMBERSHIP_REQUIRED',
				'message' => $exception->getMessage(),
			], Http::STATUS_FORBIDDEN);
			$response->addHeader('Cache-Control', 'no-store');
			return $response;
		}

		if ($controller instanceof ApiController && ($exception instanceof \InvalidArgumentException || $exception instanceof \JsonException)) {
			$this->logger->info('Ungültige Eingabe an einem Bestatter-API-Endpunkt.', [
				'controller' => $controller::class,
				'method' => $methodName,
				'exceptionClass' => $exception::class,
			]);
			$response = new JSONResponse([
				'code' => 'BESTATTER_VALIDATION_ERROR',
				'message' => $exception->getMessage(),
			], Http::STATUS_BAD_REQUEST);
			$response->addHeader('Cache-Control', 'no-store');
			return $response;
		}

		if ($controller instanceof ApiController && $exception instanceof \RuntimeException) {
			// Do not pass the exception object or its trace to the logger: PHP traces
			// may contain full controller/service arguments and therefore case data.
			$this->logger->error('Eine Bestatter-API-Aktion ist mit einem Betriebsfehler fehlgeschlagen.', [
				'controller' => $controller::class,
				'method' => $methodName,
				'exceptionClass' => $exception::class,
			]);
			$response = new JSONResponse([
				'code' => 'BESTATTER_OPERATION_ERROR',
				'message' => 'Die Aktion konnte technisch nicht abgeschlossen werden. Bitte die Systemprüfung öffnen oder den Administrator informieren.',
			], Http::STATUS_INTERNAL_SERVER_ERROR);
			$response->addHeader('Cache-Control', 'no-store');
			return $response;
		}

		throw $exception;
	}
}
