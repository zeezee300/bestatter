<?php

declare(strict_types=1);

namespace OCA\Bestatter\Activity;

use OCA\Bestatter\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;

class Provider implements IProvider {
	private const SUBJECTS = [
		'case_assigned' => 'Sterbefall {case} wurde Ihnen zugewiesen',
		'task_assigned' => 'Aufgabe „{title}“ wurde Ihnen zugewiesen ({case})',
		'task_changed' => 'Aufgabe „{title}“ wurde geändert ({case})',
		'task_completed' => 'Aufgabe „{title}“ wurde erledigt ({case})',
		'schedule_assigned' => 'Termin „{title}“ wurde Ihnen zugewiesen ({case})',
		'schedule_changed' => 'Termin „{title}“ wurde geändert ({case})',
		'schedule_deleted' => 'Termin „{title}“ wurde gelöscht ({case})',
		'document_finalized' => 'Dokument „{title}“ wurde finalisiert ({case})',
		'invoice_finalized' => 'Rechnung „{title}“ wurde finalisiert ({case})',
		'workflow_failed' => 'Workflow „{title}“ ist fehlgeschlagen ({case})',
	];

	public function __construct(private IURLGenerator $urlGenerator) {}

	public function parse($language, IEvent $event, ?IEvent $previousEvent = null) {
		if ($event->getApp() !== Application::APP_ID || !isset(self::SUBJECTS[$event->getSubject()])) {
			throw new UnknownActivityException();
		}

		$parameters = array_map(static fn(mixed $value): string => is_scalar($value) ? (string)$value : '', $event->getSubjectParameters());
		$subject = self::SUBJECTS[$event->getSubject()];
		foreach ($parameters as $name => $value) {
			$subject = str_replace('{' . $name . '}', $value, $subject);
		}
		$event->setParsedSubject($subject);

		$caseId = (int)($parameters['caseId'] ?? 0);
		if ($caseId > 0) {
			$link = $this->urlGenerator->linkToRouteAbsolute('bestatter.page.index') . '?' . http_build_query(['caseId' => $caseId]);
			$event->setLink($link);
		}
		$event->setIcon($this->urlGenerator->imagePath('bestatter', 'app.svg'));
		return $event;
	}
}
