<?php

declare(strict_types=1);

namespace OCA\Bestatter\Activity\Setting;

class TaskChangedSetting extends AbstractBestatterSetting {
	public function getIdentifier() {
		return 'bestatter_task_changed';
	}

	public function getName() {
		return 'Eine mir zugewiesene <strong>Aufgabe</strong> wurde geändert oder erledigt';
	}
}
