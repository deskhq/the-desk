<?php

declare(strict_types=1);

namespace App\Support\Http;

use RuntimeException;

/**
 * Raised by {@see GuardedEgress} when the SSRF guard refuses a destination, so a
 * blocked URL arrives at the caller the same way an unreachable one does — as a
 * throw out of the send — rather than as a second return shape every caller
 * would have to remember to check.
 *
 * The messages are what a caller logs (a webhook delivery records one verbatim),
 * so they name the reason and never the URL: the row already knows its own
 * destination, and the error column is trimmed to 255 characters.
 */
final class BlockedEgress extends RuntimeException
{
    /**
     * The URL failed the pre-flight check: a non-http(s) scheme, a literal
     * private address, or a hostname reserved for local use.
     */
    public static function notPublic(): self
    {
        return new self('Blocked non-public URL');
    }

    /**
     * The URL looked public but its hostname resolved to a private, reserved or
     * unroutable address — or did not resolve at all.
     */
    public static function resolvesToPrivateAddress(): self
    {
        return new self('Blocked URL resolving to a non-public address');
    }
}
