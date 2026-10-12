<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\AuditService;
use OCA\Bestatter\Service\RetentionPolicyService;
use OCA\Bestatter\Service\OperationsCockpitService;
use OCA\Bestatter\Service\BackupService;
use OCA\Bestatter\Service\OnboardingService;
use OCA\Bestatter\Service\TeamService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;

class OperationsApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private TeamService $team, private RetentionPolicyService $retention, private AuditService $audit, private OperationsCockpitService $cockpit, private BackupService $backup, private OnboardingService $onboarding) { parent::__construct($appName, $request); }

	#[NoAdminRequired]
	public function cockpit(): DataResponse { $this->team->requireBestatterAdmin(); return new DataResponse($this->cockpit->report()); }

	#[NoAdminRequired]
	public function retentionPolicy(): DataResponse { $this->team->requireBestatterAdmin(); return new DataResponse($this->retention->settings()); }
	#[NoAdminRequired]
	public function saveRetentionPolicy(string $settings='{}'): DataResponse { $this->team->requireBestatterAdmin(); return new DataResponse($this->retention->save(json_decode($settings,true,512,JSON_THROW_ON_ERROR))); }
	#[NoAdminRequired]
	public function retentionPreview(): DataResponse { $this->team->requireBestatterAdmin(); return new DataResponse($this->retention->preview()); }
	#[NoAdminRequired]
	public function setCaseRetention(int $id, bool $hold=false, string $dueAt='', string $reason='', string $responsibleUid='', string $reviewAt=''): DataResponse {
		$this->team->requireBestatterAdmin();
		$responsible = $hold ? $this->team->assignee($responsibleUid) : null;
		return new DataResponse($this->retention->setHold($id, $hold, $dueAt ?: null, $reason, $responsible, $reviewAt ?: null));
	}
	#[NoAdminRequired]
	public function auditIntegrity(): DataResponse { $this->team->requireBestatterAdmin(); return new DataResponse($this->audit->verifyIntegrity()); }

	#[NoAdminRequired]
	public function backups(): DataResponse { $this->team->requireBestatterAdmin(); return new DataResponse($this->backup->managedSnapshots()); }

	#[NoAdminRequired]
	public function createBackup(bool $includeFiles = false): DataResponse { $this->team->requireBestatterAdmin(); return new DataResponse($includeFiles ? $this->backup->createPackage() : $this->backup->create(), 201); }

	#[NoAdminRequired]
	public function saveBackupSettings(string $settings = '{}'): DataResponse { $this->team->requireBestatterAdmin(); return new DataResponse($this->backup->saveSettings(json_decode($settings, true, 512, JSON_THROW_ON_ERROR))); }

	#[NoAdminRequired]
	public function cleanupBackups(): DataResponse { $this->team->requireBestatterAdmin(); return new DataResponse($this->backup->deleteExpired()); }

	#[NoAdminRequired]
	public function verifyBackup(string $file): DataResponse { $this->team->requireBestatterAdmin(); return new DataResponse($this->backup->inspectManaged($file)); }

	#[NoAdminRequired]
	public function downloadBackup(string $file): DataDownloadResponse {
		$this->team->requireBestatterAdmin();
		$mime = str_ends_with(strtolower($file), '.zip') ? 'application/zip' : 'application/json; charset=UTF-8';
		return new DataDownloadResponse($this->backup->readManaged($file), $file, $mime);
	}

	#[NoAdminRequired]
	public function deleteBackup(string $file): DataResponse { $this->team->requireBestatterAdmin(); return new DataResponse($this->backup->deleteManaged($file)); }

	#[NoAdminRequired]
	public function onboarding(): DataResponse { $this->team->requireBestatterAdmin(); return new DataResponse($this->onboarding->status()); }

	#[NoAdminRequired]
	public function completeOnboarding(string $configuration = '{}'): DataResponse { $this->team->requireBestatterAdmin(); return new DataResponse($this->onboarding->configure(json_decode($configuration, true, 512, JSON_THROW_ON_ERROR))); }
}
