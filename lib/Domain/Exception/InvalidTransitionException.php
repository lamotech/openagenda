<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain\Exception;

/**
 * A status transition is not allowed by the domain rules.
 */
class InvalidTransitionException extends OpenAgendaException {
}
