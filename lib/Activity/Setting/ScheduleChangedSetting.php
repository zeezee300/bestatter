<?php

declare(strict_types=1);

namespace OCA\Bestatter\Activity\Setting;

class ScheduleChangedSetting extends AbstractBestatterSetting {
	public function getIdentifier() {
		return 'bestatter_schedule_changed';
	}

	public function getName() {
		return 'Ein mir zugewiesener <strong>Termin</strong> wurde geändert oder gelöscht';
	}
}
