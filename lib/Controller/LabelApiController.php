<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\LabelService;
use OCA\Bestatter\Service\ProcessService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Marker (0.76.0): Definitionen im Customizing (nur Bestatter-Administration) und Marker am Fall
 * (alle Mitglieder der Bestatter-Gruppe). Nach jeder Änderung am Fall wird der Ablauf abgeglichen, damit
 * Schritte mit Bedingung oder Auslöser „Marker“ sofort entstehen bzw. sich erledigen und Notizen in offene
 * Aufgaben übernommen werden.
 */
class LabelApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private LabelService $labels, private ProcessService $process) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function catalog(): DataResponse { return new DataResponse($this->labels->catalog()); }

	#[NoAdminRequired]
	public function createLabel(string $label = '{}'): DataResponse {
		return new DataResponse($this->labels->create($this->decode($label)), 201);
	}

	#[NoAdminRequired]
	public function updateLabel(string $key, string $label = '{}'): DataResponse {
		return new DataResponse($this->labels->update($key, $this->decode($label)));
	}

	#[NoAdminRequired]
	public function deleteLabel(string $key): DataResponse {
		$this->labels->delete($key);
		return new DataResponse([], 204);
	}

	#[NoAdminRequired]
	public function activateLabels(string $keys = ''): DataResponse {
		$list = $keys === '*' ? ['*'] : array_values(array_filter(array_map('trim', explode(',', $keys))));
		$activated = $this->labels->activate($list);
		return new DataResponse(['activated' => $activated] + $this->labels->catalog());
	}

	#[NoAdminRequired]
	public function reorderLabels(string $keys = ''): DataResponse {
		$this->labels->reorder(array_values(array_filter(array_map('trim', explode(',', $keys)))));
		return new DataResponse($this->labels->catalog());
	}

	#[NoAdminRequired]
	public function caseLabels(int $caseId): DataResponse { return new DataResponse($this->labels->forCase($caseId)); }

	#[NoAdminRequired]
	public function setCaseLabel(int $caseId, string $key, string $value = '', ?string $note = null): DataResponse {
		$result = $this->labels->set($caseId, $key, $value, $note);
		$result['process'] = $this->process->reconcileQuietly($caseId);
		return new DataResponse($result);
	}

	/** 0.77.0: Nur die Notiz eines gesetzten Markers ändern (leer = löschen); offene Ablaufaufgaben ziehen nach. */
	#[NoAdminRequired]
	public function setCaseLabelNote(int $caseId, string $key, string $note = ''): DataResponse {
		$result = $this->labels->setNote($caseId, $key, $note);
		$result['process'] = $this->process->reconcileQuietly($caseId);
		return new DataResponse($result);
	}

	#[NoAdminRequired]
	public function removeCaseLabel(int $caseId, string $key): DataResponse {
		$result = $this->labels->remove($caseId, $key);
		$result['process'] = $this->process->reconcileQuietly($caseId);
		return new DataResponse($result);
	}

	private function decode(string $json): array {
		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		return is_array($data) ? $data : [];
	}
}
