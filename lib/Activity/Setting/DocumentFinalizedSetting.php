<?php

declare(strict_types=1);

namespace OCA\Bestatter\Activity\Setting;

class DocumentFinalizedSetting extends AbstractBestatterSetting {
	public function getIdentifier() {
		return 'bestatter_document_finalized';
	}

	public function getName() {
		return 'Ein <strong>Dokument oder eine Rechnung</strong> wurde finalisiert';
	}
}
