<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\BusinessMailService;
use OCA\Bestatter\Service\CaseService;
use OCA\Bestatter\Service\DocumentService;
use OCA\Bestatter\Service\MailTemplateService;
use OCA\Bestatter\Service\OrderSignatureService;
use OCA\Bestatter\Service\ProcessService;
use OCA\Bestatter\Service\RecordService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Zugriff auf alle Endpunkte dieses Controllers ist bereits durch die
 * app-weite BestatterAccessMiddleware auf Mitglieder der konfigurierten
 * Bestatter-Nextcloud-Gruppe beschränkt. Es gibt bewusst KEINE zusätzliche
 * Prüfung auf eine "zuständige Person" je Fall: Team- und Rollenzugehörigkeit
 * (Mitglied/Administrator) werden ausschließlich über Nextcloud-Gruppen
 * gesteuert, damit sich Kolleg:innen jederzeit gegenseitig bei fremden
 * Fällen vertreten/aushelfen können.
 */
class BusinessMailApiController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private CaseService $cases,
		private BusinessMailService $businessMail,
		private ProcessService $process,
		private MailTemplateService $templates,
		private RecordService $records,
		private OrderSignatureService $signatures,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function availability(int $caseId): DataResponse {
		// $caseId wird nur validiert (löst bei unbekanntem Fall eine Exception aus);
		// die Verfügbarkeit des Geschäfts-Postfachs ist fallunabhängig.
		$this->cases->getCase($caseId);
		return new DataResponse($this->businessMail->availability());
	}

	#[NoAdminRequired]
	public function preview(int $caseId, int $recordId): DataResponse {
		$case = $this->cases->getCase($caseId);
		$preview = $this->businessMail->preview($case, $recordId);
		// 0.89.3: Betreff, Text und Empfänger aus Customizing → E-Mail-Vorlagen; beim Auftrag die Widerrufsbelehrung als Anlage
		$record = $this->records->get($recordId);
		$key = MailTemplateService::keyForRecord($record);
		$preview['extraAttachments'] = [];
		if ($key !== null) {
			$withdrawal = $key === 'ORDER' && DocumentService::withdrawalApplies((array)($case['masterData'] ?? []));
			if ($withdrawal) {
				$preview['extraAttachments'][] = ['key' => 'WIDERRUFSBELEHRUNG', 'title' => 'Widerrufsbelehrung mit Muster-Widerrufsformular (finale PDF, wird bei Bedarf erzeugt)', 'checked' => true];
			}
			$mail = $this->templates->render($key, $case, ['invoiceId' => (int)($record['data']['invoiceId'] ?? 0), 'withdrawalEnclosure' => $withdrawal]);
			$preview += ['templateKey' => $key, 'subject' => $mail['subject'], 'body' => $mail['body'], 'recipient' => $mail['recipient'], 'withdrawalEnclosure' => $withdrawal ? MailTemplateService::WITHDRAWAL_ENCLOSURE : ''];
		}
		return new DataResponse($preview);
	}

	#[NoAdminRequired]
	public function history(int $caseId): DataResponse {
		$case = $this->cases->getCase($caseId);
		return new DataResponse($this->businessMail->history($case));
	}

	#[NoAdminRequired]
	public function send(int $caseId, int $recordId, string $mail = '{}'): DataResponse {
		$case = $this->cases->getCase($caseId);
		$input = json_decode($mail, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($input)) {
			throw new \InvalidArgumentException('Ungültige Versanddaten.');
		}
		$extra = [];
		if (in_array('WIDERRUFSBELEHRUNG', array_map('strval', (array)($input['extraAttachments'] ?? [])), true) && DocumentService::withdrawalApplies((array)($case['masterData'] ?? []))) {
			$noticeId = $this->signatures->withdrawalNoticeRecordId($caseId);
			if ($noticeId <= 0) {
				throw new \InvalidArgumentException('Die Widerrufsbelehrung konnte nicht als PDF erzeugt werden. Bitte ohne diese Anlage senden oder die PDF-Umwandlung prüfen.');
			}
			$extra[] = $noticeId;
		}
		$result = $this->businessMail->send($case, $recordId, $input, $extra);
		$this->process->reconcileQuietly($caseId);
		return new DataResponse($result);
	}
}
