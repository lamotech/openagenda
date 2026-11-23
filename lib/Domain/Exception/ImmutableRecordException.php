<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain\Exception;

/**
 * Raised when code attempts to modify an immutable record (snapshot, approved protocol, audit event).
 */
class ImmutableRecordException extends ConflictException {
}
