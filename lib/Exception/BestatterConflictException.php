<?php

declare(strict_types=1);

namespace OCA\Bestatter\Exception;

/**
 * Raised when an update is rejected because the underlying record changed
 * since the client last read it (optimistic locking). The message is a
 * user-facing business message, safe to return to the API caller as-is.
 */
class BestatterConflictException extends \RuntimeException {
}
