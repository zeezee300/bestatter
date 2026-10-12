<?php

declare(strict_types=1);

namespace OCA\Bestatter\Exception;

/**
 * Raised when an authenticated Nextcloud user has no Bestatter role.
 */
class BestatterAccessDeniedException extends \RuntimeException {
}
