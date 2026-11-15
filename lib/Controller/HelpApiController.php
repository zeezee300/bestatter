<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\HelpAssistantService;
use OCA\Bestatter\Service\HelpFeedbackService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** The app-wide BestatterAccessMiddleware protects all endpoints; the feedback list is for Bestatter administrators only. */
class HelpApiController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private HelpAssistantService $help,
		private HelpFeedbackService $feedback,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function availability(): DataResponse {
		return new DataResponse($this->help->availability());
	}

	/** 0.90.1: Ohne passenden Hilfetext wird die Frage automatisch als offene Hilfefrage erfasst (ohne Benutzerkennung). */
	#[NoAdminRequired]
	public function ask(string $question = '', string $context = ''): DataResponse {
		$result = $this->help->ask($question, $context);
		if ($result['status'] === 'NO_SOURCE') {
			$result['recorded'] = $this->record($question, $context, 'NO_SOURCE');
		}
		return new DataResponse($result, $result['status'] === 'SCHEDULED' ? 202 : 200);
	}

	/** 0.90.1: Konnte der Chat-Anbieter keine Antwort erzeugen oder fehlte am Ende eine Quelle, wird die Frage ebenfalls erfasst. */
	#[NoAdminRequired]
	public function status(int $taskId, string $context = ''): DataResponse {
		$result = $this->help->status($taskId);
		$question = (string)($result['question'] ?? '');
		unset($result['question']);
		if ($question !== '' && in_array($result['status'], ['FAILED', 'CANCELLED'], true)) {
			$result['recorded'] = $this->record($question, $context, 'FAILED');
		} elseif ($question !== '' && $result['status'] === 'SUCCESSFUL' && $result['sources'] === []) {
			$result['recorded'] = $this->record($question, $context, 'NO_SOURCE');
		}
		return new DataResponse($result);
	}

	private function record(string $question, string $context, string $reason): bool {
		try {
			$this->feedback->add($question, $context, '', [], $reason);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/** 0.89.6: Hilfetexte zur geöffneten Seite. */
	#[NoAdminRequired]
	public function topics(string $context = ''): DataResponse {
		return new DataResponse($this->help->topics($context));
	}

	/** 0.89.6: ein Hilfetext („Was bedeutet das?“). */
	#[NoAdminRequired]
	public function topic(string $id = ''): DataResponse {
		return new DataResponse($this->help->topic($id));
	}

	/** 0.89.6: „Hat das geholfen? – Nein“ (ohne Benutzerkennung). */
	#[NoAdminRequired]
	public function addFeedback(string $question = '', string $page = '', string $pageTitle = '', string $sources = '[]'): DataResponse {
		$list = json_decode($sources, true);
		return new DataResponse($this->feedback->add($question, $page, $pageTitle, is_array($list) ? $list : []), 201);
	}

	#[NoAdminRequired]
	public function feedback(): DataResponse {
		return new DataResponse($this->feedback->entries());
	}

	#[NoAdminRequired]
	public function resolveFeedback(string $id): DataResponse {
		return new DataResponse($this->feedback->resolve($id));
	}
}
