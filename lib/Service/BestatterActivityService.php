<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCA\Bestatter\AppInfo\Application;
use OCP\Activity\IManager;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/** Publishes optional user-facing events. It never replaces the immutable audit log. */
class BestatterActivityService {
	private const FINAL_DOCUMENT_STATUSES = ['FINAL', 'UNTERSCHRIEBEN', 'VERSENDET'];

	public function __construct(
		private IManager $activityManager,
		private IDBConnection $db,
		private IUserManager $userManager,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private TeamService $team,
	) {}

	public function publishAuditEvent(int $caseId, string $objectType, ?int $objectId, string $action, mixed $before, mixed $after): void {
		if ($caseId <= 0) return;
		try {
			$this->route($caseId, strtoupper($objectType), $objectId, strtoupper($action), $this->data($before), $this->data($after));
		} catch (\Throwable $error) {
			// A notification failure must never roll back or mask a completed business operation.
			$this->logger->warning('Bestatter-Aktivität konnte nicht veröffentlicht werden.', [
				'app' => Application::APP_ID,
				'caseId' => $caseId,
				'objectType' => $objectType,
				'objectId' => $objectId,
				'action' => $action,
				'exception' => $error,
			]);
		}
	}

	private function route(int $caseId, string $type, ?int $objectId, string $action, array $before, array $after): void {
		$actor = $this->actorUid();
		$case = $this->caseContext($caseId);
		$title = trim((string)($after['title'] ?? $before['title'] ?? ''));

		if ($type === 'CASE' && in_array($action, ['CREATED', 'UPDATED'], true)) {
			$newAssignee = $this->caseAssignee($after);
			$oldAssignee = $this->caseAssignee($before);
			if ($newAssignee !== '' && ($action === 'CREATED' || $newAssignee !== $oldAssignee)) {
				$this->publish('bestatter_case_assigned', 'case_assigned', [$newAssignee], $caseId, $objectId, $case, $actor);
			}
			return;
		}

		if ($type === 'TASK' && in_array($action, ['CREATED', 'UPDATED'], true)) {
			$newAssignee = $this->recordAssignee($after);
			$oldAssignee = $this->recordAssignee($before);
			if ($action === 'CREATED') {
				$this->publish('bestatter_task_changed', 'task_assigned', [$newAssignee], $caseId, $objectId, $case, $actor, $title);
				return;
			}
			if ($newAssignee !== $oldAssignee) {
				$this->publish('bestatter_task_changed', 'task_assigned', [$newAssignee], $caseId, $objectId, $case, $actor, $title);
				$this->publish('bestatter_task_changed', 'task_changed', [$oldAssignee], $caseId, $objectId, $case, $actor, $title);
				return;
			}
			if (!$this->recordContentChanged($before, $after)) return;
			$subject = strtoupper((string)($after['status'] ?? '')) === 'ERLEDIGT' ? 'task_completed' : 'task_changed';
			$this->publish('bestatter_task_changed', $subject, [$newAssignee], $caseId, $objectId, $case, $actor, $title);
			return;
		}

		if ($type === 'SCHEDULE' && in_array($action, ['CREATED', 'UPDATED', 'DELETED'], true)) {
			$remoteDeleted = $action === 'UPDATED'
				&& (string)($before['data']['nextcloud']['remoteDeletedAt'] ?? '') === ''
				&& (string)($after['data']['nextcloud']['remoteDeletedAt'] ?? '') !== '';
			if ($action === 'UPDATED' && !$remoteDeleted && !$this->recordContentChanged($before, $after)) return;
			$beforeRecipients = $this->scheduleAssignees($before);
			$afterRecipients = $this->scheduleAssignees($after);
			$recipients = $action === 'CREATED' ? $afterRecipients : array_values(array_unique(array_merge($beforeRecipients, $afterRecipients)));
			$subject = $action === 'DELETED' || $remoteDeleted ? 'schedule_deleted' : ($action === 'CREATED' ? 'schedule_assigned' : 'schedule_changed');
			$this->publish('bestatter_schedule_changed', $subject, $recipients, $caseId, $objectId, $case, $actor, $title);
			return;
		}

		if ($type === 'DOCUMENT' && in_array(strtoupper((string)($after['status'] ?? '')), self::FINAL_DOCUMENT_STATUSES, true)
			&& strtoupper((string)($before['status'] ?? '')) !== strtoupper((string)($after['status'] ?? ''))) {
			$this->publish('bestatter_document_finalized', 'document_finalized', [$case['responsible']], $caseId, $objectId, $case, $actor, $title);
			return;
		}

		if ($type === 'INVOICE' && in_array($action, ['STATUS_FREIGEGEBEN', 'STATUS_VERSENDET'], true)) {
			$invoiceTitle = trim((string)($after['invoiceNumber'] ?? $before['invoiceNumber'] ?? $title));
			if ($invoiceTitle === '') $invoiceTitle = 'Rechnung';
			$this->publish('bestatter_document_finalized', 'invoice_finalized', [$case['responsible']], $caseId, $objectId, $case, $actor, $invoiceTitle);
			return;
		}

		if ($type === 'WORKFLOW' && $action === 'FAILED') {
			$workflowTitle = trim((string)($after['workflow'] ?? $after['workflowName'] ?? $title));
			if ($workflowTitle === '') $workflowTitle = 'Workflow';
			$this->publish('bestatter_process_attention', 'workflow_failed', [$case['responsible']], $caseId, $objectId, $case, $actor, $workflowTitle);
		}
	}

	private function publish(string $activityType, string $subject, array $recipients, int $caseId, ?int $objectId, array $case, string $actor, string $title = ''): void {
		$recipients = array_values(array_unique(array_filter(array_map('strval', $recipients))));
		foreach ($recipients as $recipient) {
			if ($recipient === $actor || !$this->team->hasBestatterRole($recipient) || $this->userManager->get($recipient) === null) continue;
			$event = $this->activityManager->generateEvent();
			$event->setApp(Application::APP_ID)
				->setType($activityType)
				->setAffectedUser($recipient)
				->setSubject($subject, [
					'case' => $case['label'],
					'caseId' => (string)$caseId,
					'title' => $title,
				])
				->setObject('bestatter_case', $caseId, $case['label'])
				->setTimestamp(time());
			if ($actor !== 'system' && $this->userManager->get($actor) !== null) $event->setAuthor($actor);
			$this->activityManager->publish($event);
		}
	}

	private function caseContext(int $caseId): array {
		$query = $this->db->getQueryBuilder();
		$row = $query->select('case_number', 'first_name', 'last_name', 'responsible_employee')
			->from('bestatter_cases')->where($query->expr()->eq('id', $query->createNamedParameter($caseId)))
			->executeQuery()->fetchAssociative();
		if ($row === false) return ['label' => 'Fall #' . $caseId, 'responsible' => ''];
		$name = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
		$number = trim((string)$row['case_number']);
		return [
			'label' => trim($number . ($name !== '' ? ' – ' . $name : '')),
			'responsible' => trim((string)($row['responsible_employee'] ?? '')),
		];
	}

	private function caseAssignee(array $data): string {
		return trim((string)($data['responsibleEmployee'] ?? $data['masterData']['responsible_employee'] ?? $data['responsible_employee'] ?? ''));
	}

	private function recordAssignee(array $data): string {
		return trim((string)($data['assigneeUid'] ?? $data['data']['assigneeUid'] ?? ''));
	}

	private function scheduleAssignees(array $data): array {
		$uids = $data['data']['assigneeUids'] ?? $data['assigneeUids'] ?? [];
		if (!is_array($uids)) $uids = [];
		$single = $this->recordAssignee($data);
		if ($single !== '') $uids[] = $single;
		return $uids;
	}

	private function recordContentChanged(array $before, array $after): bool {
		$meaningful = static function (array $record): array {
			$data = is_array($record['data'] ?? null) ? $record['data'] : [];
			unset($data['nextcloud'], $data['nextcloudCopies'], $data['updatedAt'], $data['updatedBy']);
			return [
				'title' => (string)($record['title'] ?? ''),
				'date' => (string)($record['date'] ?? ''),
				'status' => strtoupper((string)($record['status'] ?? '')),
				'assigneeUid' => (string)($record['assigneeUid'] ?? $data['assigneeUid'] ?? ''),
				'data' => $data,
			];
		};
		return $meaningful($before) !== $meaningful($after);
	}

	private function data(mixed $value): array {
		return is_array($value) ? $value : [];
	}

	private function actorUid(): string {
		return $this->userSession->getUser()?->getUID() ?? 'system';
	}
}
