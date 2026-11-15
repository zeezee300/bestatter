<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\AccountingExportService;
use OCA\Bestatter\Service\AccountingSettingsService;
use OCA\Bestatter\Service\AuditService;
use OCA\Bestatter\Service\TeamService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * 0.90.0 – Buchhaltungsexport (DATEV). Nur Bestatter-Administratoren und Rollengruppe „Buchhaltung“ (NFA-05);
 * die zentrale Middleware verlangt zusätzlich die Mitgliedschaft in der Bestatter-Gruppe.
 */
class AccountingApiController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private AccountingExportService $exports,
		private AccountingSettingsService $settings,
		private AuditService $audit,
		private TeamService $team,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function overview(): DataResponse {
		$this->team->requireAccounting();
		return new DataResponse($this->exports->overview() + ['configuration' => $this->settings->view()]);
	}

	#[NoAdminRequired]
	public function settings(): DataResponse {
		$this->team->requireAccounting();
		return new DataResponse($this->settings->view());
	}

	#[NoAdminRequired]
	public function saveSettings(array $settings = []): DataResponse {
		$this->team->requireAccounting();
		$before = $this->settings->settings();
		$result = $this->settings->save($settings);
		$this->audit->logSystem('ACCOUNTING_SETTINGS', 'SAVED', ['before' => $before, 'after' => $result['settings']]);
		return new DataResponse($result);
	}

	#[NoAdminRequired]
	public function preview(string $from = '', string $to = '', array $invoiceIds = [], bool $includeLate = true, string $reason = ''): DataResponse {
		$this->team->requireAccounting();
		$result = $this->exports->preview(['from' => $from, 'to' => $to, 'invoiceIds' => $invoiceIds, 'includeLate' => $includeLate, 'reason' => $reason]);
		unset($result['allLines'], $result['settings']);
		return new DataResponse($result);
	}

	#[NoAdminRequired]
	public function export(string $from = '', string $to = '', array $invoiceIds = [], bool $includeLate = true, string $reason = ''): DataResponse {
		$this->team->requireAccounting();
		return new DataResponse($this->exports->export(['from' => $from, 'to' => $to, 'invoiceIds' => $invoiceIds, 'includeLate' => $includeLate, 'reason' => $reason]));
	}

	#[NoAdminRequired]
	public function exports(): DataResponse {
		$this->team->requireAccounting();
		return new DataResponse(['exports' => $this->exports->exports()]);
	}

	#[NoAdminRequired]
	public function exportDetails(int $id): DataResponse {
		$this->team->requireAccounting();
		return new DataResponse($this->exports->exportById($id));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function download(int $id): DataDownloadResponse {
		$this->team->requireAccounting();
		$file = $this->exports->download($id);
		return new DataDownloadResponse($file['content'], $file['fileName'], $file['mime']);
	}

	#[NoAdminRequired]
	public function debtors(): DataResponse {
		$this->team->requireAccounting();
		return new DataResponse(['debtors' => $this->exports->debtors()]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function debtorsCsv(): DataDownloadResponse {
		$this->team->requireAccounting();
		return new DataDownloadResponse($this->exports->debtorsCsv(), 'Debitoren-' . date('Ymd') . '.csv', 'text/csv; charset=UTF-8');
	}
}
