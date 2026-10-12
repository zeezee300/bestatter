<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCP\AppFramework\Controller;
use OCP\IRequest;

/** Marker base class for the app's centrally protected JSON controllers. */
abstract class ApiController extends Controller {
	public function __construct(string $appName, protected IRequest $apiRequest) {
		parent::__construct($appName, $apiRequest);
	}
}
