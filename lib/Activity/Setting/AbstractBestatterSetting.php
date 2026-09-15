<?php

declare(strict_types=1);

namespace OCA\Bestatter\Activity\Setting;

use OCA\Bestatter\Service\TeamService;
use OCP\Activity\ActivitySettings;
use OCP\IURLGenerator;
use OCP\IUserSession;

abstract class AbstractBestatterSetting extends ActivitySettings {
	public function __construct(
		protected IURLGenerator $urlGenerator,
		private IUserSession $userSession,
		private TeamService $team,
	) {}

	public function getIcon() {
		return $this->urlGenerator->imagePath('bestatter', 'app.svg');
	}

	public function getPriority() {
		return 70;
	}

	public function getGroupIdentifier() {
		return 'bestatter';
	}

	public function getGroupName() {
		return 'Bestatter';
	}

	public function canChangeStream() {
		return $this->isBestatterUser();
	}

	public function isDefaultEnabledStream() {
		return true;
	}

	public function canChangeMail() {
		return $this->isBestatterUser();
	}

	public function isDefaultEnabledMail() {
		return false;
	}

	public function canChangeNotification() {
		return $this->isBestatterUser();
	}

	public function isDefaultEnabledNotification() {
		return true;
	}

	private function isBestatterUser(): bool {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		return $uid !== '' && $this->team->hasBestatterRole($uid);
	}
}
