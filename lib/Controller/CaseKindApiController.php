<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\CaseKinds;
use OCA\Bestatter\Service\CaseKindTransitionService;
use OCA\Bestatter\Service\CaseService;
use OCA\Bestatter\Service\OrderTypeService;
use OCA\Bestatter\Service\PrecareService;
use OCA\Bestatter\Service\ProcessService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * 0.89.7 (OP-074/OP-075, Schritte B–D): Auftragsarten, Vorsorge (Vertragsstand, Finanzierungen, Wiedervorlage, Ende),
 * Einlösen einer Vorsorge in einen Sterbefall und Weiterführen eines Auftrags als Sterbefall. Einstellungen speichert nur
 * die Bestatter-Administration (Prüfung in den Diensten); alles Übrige wie die Fallakte für alle Bestatter-Mitglieder.
 */
class CaseKindApiController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CaseService $cases,
		private OrderTypeService $orderTypes,
		private PrecareService $precare,
		private CaseKindTransitionService $workflow,
		private ProcessService $process,
	) {
		parent::__construct($appName, $request);
	}

	// ------------------------------------------------------------------------------------------- Einstellungen

	#[NoAdminRequired]
	public function orderTypes(): DataResponse {
		return new DataResponse($this->orderTypes->settings());
	}

	#[NoAdminRequired]
	public function saveOrderTypes(string $settings = '{}'): DataResponse {
		return new DataResponse($this->orderTypes->save(self::json($settings)));
	}

	#[NoAdminRequired]
	public function precareSettings(): DataResponse {
		return new DataResponse($this->precare->settings());
	}

	#[NoAdminRequired]
	public function savePrecareSettings(string $settings = '{}'): DataResponse {
		return new DataResponse($this->precare->saveSettings(self::json($settings)));
	}

	// ------------------------------------------------------------------------------------------- Vorsorge

	#[NoAdminRequired]
	public function precare(int $caseId): DataResponse {
		$this->requireKind($caseId, CaseKinds::PRECARE);
		return new DataResponse($this->precareView($caseId));
	}

	#[NoAdminRequired]
	public function updatePrecare(int $caseId, string $data = '{}'): DataResponse {
		$this->requireKind($caseId, CaseKinds::PRECARE);
		$this->precare->update($caseId, self::json($data));
		$this->process->reconcileQuietly($caseId);
		return new DataResponse($this->precareView($caseId));
	}

	#[NoAdminRequired]
	public function endPrecare(int $caseId, string $status = '', string $reason = '', string $date = ''): DataResponse {
		$result = $this->workflow->endPrecare($caseId, $status, $reason, $date);
		$this->process->reconcileQuietly($caseId);
		return new DataResponse($this->precareView($caseId) + ['warnings' => $result['warnings'] ?? []]);
	}

	#[NoAdminRequired]
	public function reviewPrecare(int $caseId, string $note = ''): DataResponse {
		$this->requireKind($caseId, CaseKinds::PRECARE);
		$this->precare->markReviewed($caseId, $note);
		$this->process->reconcileQuietly($caseId);
		return new DataResponse($this->precareView($caseId));
	}

	#[NoAdminRequired]
	public function createFunding(int $caseId, string $data = '{}'): DataResponse {
		$this->requireKind($caseId, CaseKinds::PRECARE);
		$this->precare->saveFunding($caseId, self::json($data));
		$this->process->reconcileQuietly($caseId);
		return new DataResponse($this->precareView($caseId), 201);
	}

	#[NoAdminRequired]
	public function updateFunding(int $caseId, int $fundingId, string $data = '{}'): DataResponse {
		$this->requireKind($caseId, CaseKinds::PRECARE);
		if ($this->precare->funding($caseId, $fundingId) === null) {
			throw new \InvalidArgumentException('Die Finanzierung wurde nicht gefunden.');
		}
		$this->precare->saveFunding($caseId, self::json($data), $fundingId);
		$this->process->reconcileQuietly($caseId);
		return new DataResponse($this->precareView($caseId));
	}

	#[NoAdminRequired]
	public function deleteFunding(int $caseId, int $fundingId): DataResponse {
		$this->requireKind($caseId, CaseKinds::PRECARE);
		$this->precare->deleteFunding($caseId, $fundingId);
		return new DataResponse($this->precareView($caseId));
	}

	#[NoAdminRequired]
	public function redeemPreview(int $caseId, int $deathCaseId = 0): DataResponse {
		return new DataResponse($this->workflow->redeemPreview($caseId, $deathCaseId));
	}

	#[NoAdminRequired]
	public function redeem(int $caseId, string $data = '{}'): DataResponse {
		$result = $this->workflow->redeem($caseId, self::json($data));
		$this->process->reconcileQuietly($caseId);
		$this->process->reconcileQuietly((int)$result['case']['id']);
		return new DataResponse($result, 201);
	}

	// ------------------------------------------------------------------------------------------- Sterbefall und Auftrag

	/** Sterbefall: passende aktive Vorsorgen (Name und Geburtsdatum). */
	#[NoAdminRequired]
	public function precareCandidates(int $caseId): DataResponse {
		return new DataResponse(['candidates' => $this->workflow->candidatesForCase($caseId)]);
	}

	/** Herkunft (Vorsorge bzw. Auftrag) und daraus entstandene Akten. */
	#[NoAdminRequired]
	public function relations(int $caseId): DataResponse {
		return new DataResponse(['origin' => $this->workflow->originForCase($caseId), 'derived' => $this->cases->derivedCases($caseId)]);
	}

	#[NoAdminRequired]
	public function markFundingPaid(int $caseId, int $fundingId): DataResponse {
		$origin = $this->workflow->markFundingPaid($caseId, $fundingId);
		$this->process->reconcileQuietly($caseId);
		return new DataResponse(['origin' => $origin, 'derived' => $this->cases->derivedCases($caseId)]);
	}

	#[NoAdminRequired]
	public function continuePreview(int $caseId): DataResponse {
		return new DataResponse($this->workflow->continueOrderPreview($caseId));
	}

	#[NoAdminRequired]
	public function continueAsDeath(int $caseId, string $data = '{}'): DataResponse {
		$result = $this->workflow->continueOrderAsDeath($caseId, self::json($data));
		$this->process->reconcileQuietly($caseId);
		$this->process->reconcileQuietly((int)$result['case']['id']);
		return new DataResponse($result, 201);
	}

	// ------------------------------------------------------------------------------------------- intern

	private function precareView(int $caseId): array {
		$view = $this->precare->forCase($caseId);
		$redeemed = (int)($view['redeemedCaseId'] ?? 0);
		// 0.89.7: Ein inzwischen gelöschter Sterbefall darf die Vorsorge-Akte nicht unbrauchbar machen.
		$view['redeemedCaseNumber'] = $redeemed > 0 ? $this->cases->existingCaseNumber($redeemed) : '';
		if ($redeemed > 0 && $view['redeemedCaseNumber'] === '') {
			$view['redeemedCaseId'] = null;
		}
		return $view;
	}

	private function requireKind(int $caseId, string $kind): void {
		if ($this->cases->getCase($caseId)['caseKind'] !== $kind) {
			throw new \InvalidArgumentException('Diese Angaben gibt es nur für ' . CaseKinds::PLURAL_LABELS[$kind] . '.');
		}
	}

	private static function json(string $value): array {
		$decoded = json_decode($value !== '' ? $value : '{}', true, 512, JSON_THROW_ON_ERROR);
		return is_array($decoded) ? $decoded : [];
	}
}
