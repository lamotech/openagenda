<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain\Exception;

/**
 * Base class for expected business errors. Messages are safe to show to users (Danish).
 */
abstract class OpenAgendaException extends \RuntimeException {
}
