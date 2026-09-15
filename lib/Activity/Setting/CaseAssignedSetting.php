<?php

declare(strict_types=1);

namespace OCA\Bestatter\Activity\Setting;

class CaseAssignedSetting extends AbstractBestatterSetting {
	public function getIdentifier() {
		return 'bestatter_case_assigned';
	}

	public function getName() {
		return 'Ein neuer <strong>Sterbefall</strong> wurde mir zugewiesen';
	}
}
