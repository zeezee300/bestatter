<?php

declare(strict_types=1);

namespace OCA\Bestatter\Listener;

use OCA\Bestatter\Service\GroupwareService;
use OCP\Calendar\Events\AbstractCalendarObjectEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/** @implements IEventListener<AbstractCalendarObjectEvent> */
class CalendarObjectChangedListener implements IEventListener {
	public function __construct(
		private GroupwareService $groupware,
		private LoggerInterface $logger,
	) {}

	public function handle(Event $event): void {
		if (!$event instanceof AbstractCalendarObjectEvent) {
			return;
		}
		try {
			$this->groupware->importCalendarObject(
				$event->getObjectData(),
				$event->getCalendarId(),
				$event->getCalendarData(),
				$event instanceof CalendarObjectDeletedEvent,
			);
		} catch (\Throwable $error) {
			$this->logger->warning('Bestatter-Groupware-Ereignis konnte nicht übernommen werden.', [
				'app' => 'bestatter',
				'exception' => $error,
			]);
		}
	}
}
