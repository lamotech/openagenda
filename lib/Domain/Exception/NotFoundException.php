<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain\Exception;

/**
 * Requested object does not exist or is not visible to the actor.
 */
class NotFoundException extends OpenAgendaException {
}
