<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * The timestamp a row that reached the database is guaranteed to carry.
 *
 * Eloquent types every timestamp as nullable because a model exists before it
 * is saved: `new Message` has no `created_at` until an insert gives it one. A
 * read-model built from a row that was read back is on the other side of that,
 * and reading it as nullable costs either a `?->` that hides a bug or a fallback
 * branch nothing can ever reach — an untestable line, in a suite gated at 100%.
 *
 * So the invariant is asserted once, here, and the read-models state it by
 * calling this. A null means the caller was handed an unsaved model, which is a
 * programming error rather than a state to render around.
 */
final class PersistedTimestamp
{
    /**
     * The timestamp, which a persisted row always has.
     *
     * @template TTimestamp of CarbonInterface
     *
     * @param  TTimestamp|null  $at
     * @return TTimestamp
     */
    public static function of(?CarbonInterface $at): CarbonInterface
    {
        return $at ?? throw new RuntimeException('Expected a persisted model to carry the timestamp being read.');
    }

    /**
     * The timestamp as the ISO-8601 string the frontend parses.
     *
     * `toISOString()` is typed as nullable for the second way a Carbon can have
     * nothing to print — an instance that is not a valid date at all — which a
     * value read back out of a timestamp column is not.
     */
    public static function iso(?CarbonInterface $at): string
    {
        return self::of($at)->toISOString() ?? throw new RuntimeException('Expected a persisted timestamp to format as ISO-8601.');
    }
}
