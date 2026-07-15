<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to update or delete a security event. The security
 * log is append-only, so events can only ever be created.
 */
class SecurityEventImmutableException extends RuntimeException
{
    //
}
