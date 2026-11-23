<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain\Exception;

/**
 * The operation conflicts with the current state (e.g. duplicates, locked records).
 */
class ConflictException extends OpenAgendaException {
}
