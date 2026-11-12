<?php

declare(strict_types=1);

namespace OCA\Bestatter\Activity\Setting;

/** 0.91.0: Benachrichtigung, wenn man in einer Fallnotiz erwähnt wird. */
class NoteMentionSetting extends AbstractBestatterSetting {
	public function getIdentifier() {
		return 'bestatter_note_mention';
	}

	public function getName() {
		return 'Ich wurde in einer <strong>Notiz</strong> zu einem Fall erwähnt';
	}
}
