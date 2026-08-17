<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\User;
use App\Support\UserAvailability;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A user's own do-not-disturb configuration, as their own client reads it.
 *
 * Only ever serialised to its owner (the `auth.user` prop): teammates receive
 * the single {@see UserData::$isDnd} boolean instead, so the pause instant and
 * the quiet-hours schedule never leak. Carrying the full configuration lets the
 * client evaluate "am I in DND right now" locally — the chime gate needs the
 * answer at message-arrival time, not at page-load time.
 */
#[TypeScript]
final class UserDndData extends Data
{
    public function __construct(
        /** ISO-8601 instant the manual pause lapses, or null when none is running. */
        public ?string $until,
        public bool $scheduleEnabled,
        /** Daily window bounds as `HH:MM` wall-clock strings in the user's own timezone. */
        public ?string $startsAt,
        public ?string $endsAt,
        /** ISO-8601 instant a schedule snooze lapses, or null when none is running. */
        public ?string $scheduleSnoozedUntil,
    ) {}

    /**
     * Build the DTO from a user's columns. A lapsed pause or snooze reads as
     * absent immediately, without waiting for the scheduled sweep to null the
     * column — which is {@see UserAvailability}'s reading of them, not a second
     * one spelled here.
     */
    public static function forUser(User $user): self
    {
        $availability = $user->availability();

        return new self(
            until: $availability->pausedUntil()?->toIso8601String(),
            // Null only on a freshly-made instance the column default has not
            // been read back into yet, which is never scheduled.
            scheduleEnabled: $user->dnd_schedule_enabled ?? false,
            startsAt: $user->dnd_starts_at,
            endsAt: $user->dnd_ends_at,
            scheduleSnoozedUntil: $availability->snoozedUntil()?->toIso8601String(),
        );
    }
}
