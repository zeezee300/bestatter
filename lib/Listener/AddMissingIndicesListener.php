<?php

declare(strict_types=1);

namespace OCA\Bestatter\Listener;

use OCP\DB\Events\AddMissingIndicesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/** @implements IEventListener<AddMissingIndicesEvent> */
class AddMissingIndicesListener implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof AddMissingIndicesEvent) return;
		$event->addMissingIndex('bestatter_cases', 'bestatter_case_created', ['created_at', 'id']);
		$event->addMissingIndex('bestatter_cases', 'bestatter_case_status', ['status', 'created_at']);
		$event->addMissingIndex('bestatter_cases', 'bestatter_case_branch', ['branch', 'created_at']);
		$event->addMissingIndex('bestatter_cases', 'bestatter_case_death', ['date_of_death']);
	}
}
