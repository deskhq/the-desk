<?php

declare(strict_types=1);

namespace App\Support\Http;

use Closure;

/**
 * Everything a {@see GuardedEgress::fetch()} caller has that the next one does
 * not: which content types it will read, how many bytes it will take, and what
 * an over-cap body means to it.
 *
 * The hop bound, the timeout, the re-guarding and the pinning are the module's,
 * not the caller's — a policy that could turn any of those off would be a way of
 * asking for unguarded egress, which is the thing this module exists to end.
 *
 * The two named constructors are the two readings of the cap:
 * {@see self::truncatingAt()} for a caller that only reads a prefix (an unfurl
 * parses `<head>`), {@see self::refusingOver()} for one that needs the bytes
 * whole (half an image is a corrupt image, not a small one).
 */
final readonly class FetchPolicy
{
    /**
     * @param  Closure(string): bool  $accepts  Given the response's content type — lower-cased, parameters stripped — whether to read the body at all.
     */
    private function __construct(
        public Closure $accepts,
        public int $maxBytes,
        public bool $truncatesOversizeBody,
    ) {}

    /**
     * A body over the cap is cut down to it.
     *
     * @param  Closure(string): bool  $accepts
     */
    public static function truncatingAt(int $maxBytes, Closure $accepts): self
    {
        return new self($accepts, $maxBytes, truncatesOversizeBody: true);
    }

    /**
     * A body over the cap is refused outright.
     *
     * @param  Closure(string): bool  $accepts
     */
    public static function refusingOver(int $maxBytes, Closure $accepts): self
    {
        return new self($accepts, $maxBytes, truncatesOversizeBody: false);
    }
}
