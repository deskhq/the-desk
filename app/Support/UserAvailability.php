<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Users\ClearExpiredUserStatuses;
use App\Actions\Users\ClearLapsedDndPauses;
use App\Actions\Users\ClearLapsedDndScheduleSnoozes;
use App\Enums\PresenceState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Everything the application knows about one question: **is this person
 * available, and until when?**
 *
 * Five columns and an out-of-band connection index answer it — a manual
 * do-not-disturb pause, a recurring quiet-hours window with its own snooze, a
 * custom status with an expiry, and a manual away override the live connections
 * otherwise decide. They were 175 lines on {@see User}, read by nothing else in
 * that model, and evaluable only with a container and a database row behind
 * them.
 *
 * **The instant is a parameter.** Every rule here is wall-clock arithmetic —
 * start-inclusive/end-exclusive bounds, a window that wraps across midnight, a
 * schedule read on the user's own clock rather than the server's — and taking
 * `$at` is what lets those be stated as a pure test instead of a travelled
 * clock and a saved row. The registry is taken too, rather than located, so the
 * one part of the answer that genuinely lives outside the row is visible in the
 * signature.
 *
 * Its client twin is `resources/js/lib/dnd.ts`, and the two are pinned against
 * one shared case table (`tests/Fixtures/availability-cases.json`), so a change
 * to what counts as do-not-disturb made on one side of the wire turns the other
 * side's suite red.
 */
class UserAvailability
{
    public function __construct(
        private readonly User $user,
        private readonly CarbonInterface $at,
        private readonly PresenceRegistry $connections,
    ) {}

    /**
     * The user's availability as of an instant, defaulting to now.
     *
     * The one place the connection index is named, so nothing else has to reach
     * for the container to ask a question about a user — and a test hands in
     * its own registry rather than seeding the shared one.
     */
    public static function for(User $user, ?CarbonInterface $at = null, ?PresenceRegistry $connections = null): self
    {
        return new self(
            $user,
            $at ?? Date::now(),
            $connections ?? app(PresenceRegistry::class),
        );
    }

    /**
     * Whether the user is in do-not-disturb at this instant.
     *
     * Either half is enough, and they are independent: a manual pause is a
     * one-off claim about right now, the window is a standing preference, and
     * the snooze suppresses only the second of them.
     */
    public function isDnd(): bool
    {
        if ($this->pausedUntil() instanceof CarbonInterface) {
            return true;
        }

        return $this->isInsideScheduleWindow();
    }

    /**
     * The running manual pause's lapse, or null when none is running.
     *
     * This is the lazy half of expiry: a pause whose instant has passed reads as
     * over from the moment it does, without waiting for
     * {@see ClearLapsedDndPauses} to null the column and
     * broadcast the clear.
     */
    public function pausedUntil(): ?CarbonInterface
    {
        return $this->stillAhead($this->user->dnd_until);
    }

    /**
     * The running quiet-hours snooze's lapse, or null when none is running.
     *
     * Same lazy expiry as {@see pausedUntil()}, with
     * {@see ClearLapsedDndScheduleSnoozes} as the eager half.
     */
    public function snoozedUntil(): ?CarbonInterface
    {
        return $this->stillAhead($this->user->dnd_schedule_snoozed_until);
    }

    /**
     * Whether the recurring quiet-hours window covers this instant.
     *
     * The bounds are wall-clock `HH:MM` strings compared in the user's own
     * timezone, so the window follows them when they travel. Start is inclusive
     * and end exclusive, and a window whose end precedes its start wraps across
     * midnight (22:00–07:00 covers the night, not an empty set). A snooze still
     * ahead of its lapse suppresses the window outright — it is set to the
     * instant the running window next closes, so the schedule resumes on its own
     * without a re-enable step.
     */
    public function isInsideScheduleWindow(): bool
    {
        $startsAt = $this->user->dnd_starts_at;
        $endsAt = $this->user->dnd_ends_at;

        if (! $this->user->dnd_schedule_enabled || $startsAt === null || $endsAt === null) {
            return false;
        }

        if ($this->snoozedUntil() instanceof CarbonInterface) {
            return false;
        }

        $wallClock = $this->wallClock()->format('H:i');

        if ($startsAt <= $endsAt) {
            return $wallClock >= $startsAt && $wallClock < $endsAt;
        }

        return $wallClock >= $startsAt || $wallClock < $endsAt;
    }

    /**
     * The instant the quiet-hours window covering this moment next closes, or
     * null when no window covers it.
     *
     * This is what a snooze suppresses the schedule until: an overnight window
     * entered before midnight closes tomorrow morning, its morning tail closes
     * today. Null outside the window (or while already snoozed) so a stale
     * request can never suppress a window that has not opened. Computed on the
     * user's own wall clock but returned in the app timezone, because Eloquent
     * persists a datetime's wall-clock reading without converting it first.
     */
    public function scheduleClosesAt(): ?CarbonInterface
    {
        if (! $this->isInsideScheduleWindow()) {
            return null;
        }

        $wallClock = $this->wallClock();

        $closes = $wallClock->setTimeFromTimeString((string) $this->user->dnd_ends_at);

        // Inside the window the end is always ahead on the wall clock; an end
        // reading behind now means tonight's window closes tomorrow morning.
        if ($closes->lessThanOrEqualTo($wallClock)) {
            $closes = $closes->addDay();
        }

        return $closes->setTimezone(config('app.timezone'));
    }

    /**
     * Whether the user's custom status is showing at this instant.
     *
     * The lazy half of expiry once more: a status whose `status_expires_at` has
     * passed reads as absent everywhere from the instant it lapses, without
     * waiting for {@see ClearExpiredUserStatuses} to null the
     * columns and broadcast the clear.
     */
    public function hasLiveStatus(): bool
    {
        if ($this->user->status_emoji === null) {
            return false;
        }

        return $this->user->status_expires_at === null
            || $this->stillAhead($this->user->status_expires_at) instanceof CarbonInterface;
    }

    /**
     * How reachable the user is right now, as teammates should see them.
     *
     * A manual away is an override and wins outright — that is the whole point
     * of setting it, and it survives reconnects because it lives on the row.
     * Otherwise the answer is derived from the user's live browser connections,
     * which is away only once every one of them has gone idle.
     */
    public function presence(): PresenceState
    {
        // Null only on a freshly-made instance the column default has not been
        // read back into yet, which is never away.
        if (($this->user->presence_state ?? PresenceState::Active) === PresenceState::Away) {
            return PresenceState::Away;
        }

        return $this->connections->aggregate($this->user->id);
    }

    /**
     * An instant that has not yet arrived, or null once it has.
     */
    private function stillAhead(?CarbonInterface $instant): ?CarbonInterface
    {
        return $instant?->greaterThan($this->at) === true ? $instant : null;
    }

    /**
     * This instant as the user's own clock on the wall reads it.
     *
     * Immutable whatever the caller handed in, because {@see scheduleClosesAt()}
     * derives the closing instant from it and then compares the two: a mutable
     * reading would have the derivation overwrite the thing it is compared
     * against.
     */
    private function wallClock(): CarbonImmutable
    {
        return $this->at->toImmutable()->setTimezone($this->user->timezone ?? config('app.timezone'));
    }
}
