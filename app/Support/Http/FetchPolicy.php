<?php

declare(strict_types=1);

namespace App\Support\Http;

use Closure;

/**
 * Everything a {@see GuardedEgress::fetch()} caller has that the next one does
 * not: which content types it will read, and how many bytes it will take.
 *
 * The hop bound, the timeout, the re-guarding and the pinning are the module's,
 * not the caller's — a policy that could turn any of those off would be a way of
 * asking for unguarded egress, which is the thing this module exists to end.
 *
 * There used to be two readings of the cap, because there used to be two callers:
 * an unfurl truncated an over-cap body (it only parses `<head>`) while an image
 * refused one (half an image is a corrupt image, not a small one). The unfurl
 * moved to `services/unfurler` in ADR-0016 and took its reading with it, so this
 * is the image's reading and the only one left. If a second `fetch()` caller ever
 * appears that genuinely wants a prefix, the flag comes back with it — but not
 * before, since an unused branch here is a branch nothing proves.
 */
final readonly class FetchPolicy
{
    /**
     * @param  Closure(string): bool  $accepts  Given the response's content type — lower-cased, parameters stripped — whether to read the body at all.
     */
    private function __construct(
        public Closure $accepts,
        public int $maxBytes,
    ) {}

    /**
     * A body over the cap is refused outright.
     *
     * @param  Closure(string): bool  $accepts
     */
    public static function refusingOver(int $maxBytes, Closure $accepts): self
    {
        return new self($accepts, $maxBytes);
    }
}
