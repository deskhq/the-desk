<?php

declare(strict_types=1);

namespace App\Support\Unfurl;

use RuntimeException;

/**
 * The unfurl service could not be reached, or answered with something that was
 * not a batch of results.
 *
 * Deliberately not the same outcome as a link that has no preview. That one is a
 * fact about the link and is remembered for a day; this one is a fact about the
 * stack, and caching it would let a five-minute outage decide that a day of
 * perfectly good links are all broken, recoverable only by clearing the cache by
 * hand.
 *
 * It names the reason and never the URL: the URL is text a member typed into a
 * message, and this ends up in the operator's logs.
 */
final class UnfurlerUnavailable extends RuntimeException
{
    public static function unreachable(string $reason): self
    {
        return new self('The unfurl service could not be reached: '.$reason);
    }

    public static function badStatus(int $status): self
    {
        return new self('The unfurl service answered with status '.$status.'.');
    }

    public static function malformed(): self
    {
        return new self('The unfurl service answered with something that was not a batch of results.');
    }
}
