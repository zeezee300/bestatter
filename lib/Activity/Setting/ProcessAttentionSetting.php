<?php

declare(strict_types=1);

namespace OCA\Bestatter\Activity\Setting;

class ProcessAttentionSetting extends AbstractBestatterSetting {
	public function getIdentifier() {
		return 'bestatter_process_attention';
	}

	public function getName() {
		return 'Ein <strong>Prozess benötigt Aufmerksamkeit</strong>';
	}
}
