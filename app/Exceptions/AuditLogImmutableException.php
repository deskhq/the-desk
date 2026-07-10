<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to update or delete an audit log entry. The log is
 * append-only, so entries can only ever be created.
 */
class AuditLogImmutableException extends RuntimeException
{
    //
}
