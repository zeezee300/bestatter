<?php

declare(strict_types=1);

namespace OCA\Bestatter\AppInfo;

use OCA\Bestatter\Listener\CalendarObjectChangedListener;
use OCA\Bestatter\Listener\AddMissingIndicesListener;
use OCA\Bestatter\Middleware\BestatterAccessMiddleware;
use OCA\Bestatter\Search\CaseSearchProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\DB\Events\AddMissingIndicesEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'bestatter';
	public const VERSION = '0.88.2';
	/**
	 * Muss exakt dem max-version-Attribut in appinfo/info.xml entsprechen. Einzige Quelle
	 * der Wahrheit für StabilizationService::nextcloudCompatibilityCheck(), damit das
	 * Systemprüfungs-Dashboard rechtzeitig warnt, bevor die App durch eine neue Nextcloud-
	 * Hauptversion automatisch deaktiviert wird (siehe dortiger Kommentar).
	 */
	public const MAX_NEXTCLOUD_VERSION = 35;

	public function __construct(array $urlParams = []) {
		$vendorAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
		if (is_file($vendorAutoload)) require_once $vendorAutoload;
        parent::__construct(self::APP_ID, $urlParams);
    }

	public function register(IRegistrationContext $context): void {
		$context->registerMiddleware(BestatterAccessMiddleware::class);
		$context->registerSearchProvider(CaseSearchProvider::class);
		$context->registerEventListener(AddMissingIndicesEvent::class, AddMissingIndicesListener::class);
		$context->registerEventListener(CalendarObjectCreatedEvent::class, CalendarObjectChangedListener::class);
		$context->registerEventListener(CalendarObjectUpdatedEvent::class, CalendarObjectChangedListener::class);
		$context->registerEventListener(CalendarObjectDeletedEvent::class, CalendarObjectChangedListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
