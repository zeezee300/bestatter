<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\CaseNoteRules;
use OCA\Bestatter\Service\CaseNoteService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** 0.91.0: Fallnotizen (Reiter „Notizen“, „+ Notiz“ im Fallkopf, Übernahme aus Nextcloud Notizen). */
class CaseNoteApiController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CaseNoteService $notes,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function notes(int $caseId): DataResponse {
		return new DataResponse($this->notes->list($caseId));
	}

	#[NoAdminRequired]
	public function createNote(int $caseId, string $text = '', string $category = '', string $pinned = '', string $mentions = ''): DataResponse {
		return new DataResponse($this->notes->create($caseId, $text, $category, CaseNoteRules::flag($pinned), CaseNoteRules::parseUidList($mentions)), 201);
	}

	/** Nur übermittelte Felder werden geändert (z. B. nur „pinned“ beim Anheften). */
	#[NoAdminRequired]
	public function updateNote(int $caseId, int $id): DataResponse {
		$text = $this->request->getParam('text');
		$category = $this->request->getParam('category');
		$pinned = $this->request->getParam('pinned');
		$mentions = $this->request->getParam('mentions');
		return new DataResponse($this->notes->update(
			$caseId,
			$id,
			is_string($text) ? $text : null,
			is_string($category) ? $category : null,
			$pinned !== null ? CaseNoteRules::flag($pinned) : null,
			is_string($mentions) ? CaseNoteRules::parseUidList($mentions) : null,
		));
	}

	#[NoAdminRequired]
	public function deleteNote(int $caseId, int $id): DataResponse {
		$this->notes->delete($caseId, $id);
		return new DataResponse([], 204);
	}

	#[NoAdminRequired]
	public function exportMarkdown(int $caseId): DataDownloadResponse {
		$export = $this->notes->markdown($caseId);
		return new DataDownloadResponse($export['content'], $export['fileName'], 'text/markdown; charset=UTF-8');
	}

	#[NoAdminRequired]
	public function notesAppCandidates(int $caseId): DataResponse {
		return new DataResponse($this->notes->notesAppCandidates($caseId));
	}

	#[NoAdminRequired]
	public function importNotesApp(int $caseId, string $fileIds = '', string $category = ''): DataResponse {
		$ids = array_map('intval', CaseNoteRules::parseUidList($fileIds));
		return new DataResponse($this->notes->importFromNotesApp($caseId, $ids, $category), 201);
	}
}
