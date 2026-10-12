<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\HelpAssistantService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** The app-wide BestatterAccessMiddleware protects all three endpoints. */
class HelpApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private HelpAssistantService $help) { parent::__construct($appName, $request); }

	#[NoAdminRequired]
	public function availability(): DataResponse { return new DataResponse($this->help->availability()); }

	#[NoAdminRequired]
	public function ask(string $question = ''): DataResponse {
		$result = $this->help->ask($question);
		return new DataResponse($result, $result['status'] === 'SCHEDULED' ? 202 : 200);
	}

	#[NoAdminRequired]
	public function status(int $taskId): DataResponse { return new DataResponse($this->help->status($taskId)); }
}
