<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\OpenCase;

/**
 * Integration failure (OpenCase unavailable, rejected request, ...).
 * Messages are safe to store and show to secretariat users.
 */
class OpenCaseException extends \RuntimeException {
}
