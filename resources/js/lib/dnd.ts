import type { TimeFormat } from '@/types';
import { formatWallHour } from './datetime';
import { i18n } from './i18n';
import { wallTimeToInstant, zonedWallTime } from './scheduleTime';
import type { WallTime } from './scheduleTime';

/**
 * Client-side twin of `App\Support\UserAvailability::isDnd()` on the server:
 * whether the viewer is in do-not-disturb at a given instant, from the full
 * configuration their own `auth.user` prop carries.
 *
 * The chime gate needs this answer at message-arrival time, not at page-load
 * time — a pause that lapsed two minutes ago, or a quiet-hours window that
 * opened one minute ago, must take effect without waiting for a server
 * round-trip. The two halves are independent: a manual pause is a one-off claim
 * about right now, the window is a standing preference, and the snooze
 * suppresses only the second of them.
 *
 * The semantics are not kept in lockstep with the server by instruction: both
 * sides answer `tests/Fixtures/availability-cases.json`, so a rule changed here
 * and not there turns the PHP suite red (and the reverse).
 */
export function isDndActiveNow(
    dnd: App.Data.UserDndData | null | undefined,
    timeZone: string | null | undefined,
    at: Date = new Date(),
): boolean {
    if (!dnd) {
        return false;
    }

    return (
        isStillAhead(dnd.until, at) ||
        runningScheduleWindow(dnd, timeZone, at) !== null
    );
}

/**
 * The instant the currently-running quiet-hours window closes, or null when
 * the schedule is off, incomplete, snoozed, or not covering this instant.
 *
 * Feeds the paused card's "quiet hours · until 9:00 AM" line: an overnight
 * window entered before midnight closes tomorrow, its morning tail closes
 * today.
 */
export function quietHoursEndsAt(
    dnd: App.Data.UserDndData | null | undefined,
    timeZone: string | null | undefined,
    at: Date = new Date(),
): Date | null {
    const window = dnd ? runningScheduleWindow(dnd, timeZone, at) : null;

    if (window === null) {
        return null;
    }

    const zone = timeZone ?? deviceZone();
    const [hour, minute] = window.endsAt.split(':').map(Number);
    const closing = { ...zonedWallTime(zone, at), hour, minute };

    // Inside the window the end is always ahead on the wall clock; an end
    // reading behind now means tonight's window closes tomorrow morning.
    return wallClockIn(timeZone, at) < window.endsAt
        ? wallTimeToInstant(closing, zone)
        : wallTimeToInstant(nextCivilDay(closing), zone);
}

/** The `HH:MM` bounds of a quiet-hours window, in the viewer's own timezone. */
type ScheduleWindow = { startsAt: string; endsAt: string };

/**
 * The recurring quiet-hours window covering this instant, or null when none
 * does.
 *
 * The bounds are wall-clock `HH:MM` strings compared in the viewer's own
 * timezone, so the window follows them when they travel. Start is inclusive and
 * end exclusive, and a window whose end precedes its start wraps across midnight
 * (22:00–07:00 covers the night, not an empty set). A snooze still ahead of its
 * lapse suppresses the window outright.
 *
 * Returns the bounds rather than a boolean so the one caller that needs them —
 * the closing instant — reads them off the same answer that decided the window
 * is running, instead of re-testing that they are set.
 */
function runningScheduleWindow(
    dnd: App.Data.UserDndData,
    timeZone: string | null | undefined,
    at: Date,
): ScheduleWindow | null {
    const { scheduleEnabled, startsAt, endsAt } = dnd;

    if (!scheduleEnabled || startsAt === null || endsAt === null) {
        return null;
    }

    if (isStillAhead(dnd.scheduleSnoozedUntil, at)) {
        return null;
    }

    const wallClock = wallClockIn(timeZone, at);

    const covered =
        startsAt <= endsAt
            ? wallClock >= startsAt && wallClock < endsAt
            : wallClock >= startsAt || wallClock < endsAt;

    return covered ? { startsAt, endsAt } : null;
}

/** Whether a stored instant has yet to arrive, treating a lapsed one as absent. */
function isStillAhead(instant: string | null, at: Date): boolean {
    return instant !== null && new Date(instant) > at;
}

/**
 * The awake/quiet segments a daily window paints across a 24h strip, left to
 * right from midnight, each as a percentage of the day. Zero-width segments
 * are dropped so a bound sitting on midnight doesn't render an empty sliver.
 */
export function quietHoursSegments(
    startsAt: string,
    endsAt: string,
): { quiet: boolean; widthPct: number }[] {
    const start = minutesOfDay(startsAt);
    const end = minutesOfDay(endsAt);

    const segments =
        start <= end
            ? [
                  { quiet: false, widthPct: pctOfDay(start) },
                  { quiet: true, widthPct: pctOfDay(end - start) },
                  { quiet: false, widthPct: pctOfDay(MINUTES_PER_DAY - end) },
              ]
            : [
                  { quiet: true, widthPct: pctOfDay(end) },
                  { quiet: false, widthPct: pctOfDay(start - end) },
                  { quiet: true, widthPct: pctOfDay(MINUTES_PER_DAY - start) },
              ];

    return segments.filter((segment) => segment.widthPct > 0);
}

/**
 * The hour labels under the 24h strip: the fixed 00/06/12/18/24 frame with
 * the window's own bound hours merged in, so the quiet edges are always named.
 *
 * The labels follow the viewer's clock style, so the strip and the bound selects
 * above it speak the same convention as the paused card that reports the window
 * back to them.
 */
export function quietHoursTicks(
    startsAt: string,
    endsAt: string,
    locale: string = i18n.locale,
    format?: TimeFormat,
): string[] {
    const hours = new Set([0, 6, 12, 18, 24]);

    hours.add(Number(startsAt.slice(0, 2)));
    hours.add(Number(endsAt.slice(0, 2)));

    return [...hours]
        .sort((a, b) => a - b)
        .map((hour) => formatWallHour(hour, locale, format));
}

const MINUTES_PER_DAY = 24 * 60;

function minutesOfDay(bound: string): number {
    const [hour, minute] = bound.split(':').map(Number);

    return hour * 60 + minute;
}

function pctOfDay(minutes: number): number {
    return (minutes / MINUTES_PER_DAY) * 100;
}

/** Shift a wall-clock reading to the same time on the following civil day. */
function nextCivilDay(wall: WallTime): WallTime {
    const shifted = new Date(Date.UTC(wall.year, wall.month - 1, wall.day));
    shifted.setUTCDate(shifted.getUTCDate() + 1);

    return {
        ...wall,
        year: shifted.getUTCFullYear(),
        month: shifted.getUTCMonth() + 1,
        day: shifted.getUTCDate(),
    };
}

/** The device's own IANA zone, the fallback when the account has none set. */
function deviceZone(): string {
    return new Intl.DateTimeFormat().resolvedOptions().timeZone;
}

/**
 * The `HH:MM` wall-clock reading of an instant in a zone, falling back to the
 * device's own zone when the given one is missing or not a valid IANA
 * identifier. The locale is pinned because this string is compared against the
 * stored schedule bounds, never shown to anyone.
 */
function wallClockIn(timeZone: string | null | undefined, at: Date): string {
    const options: Intl.DateTimeFormatOptions = {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    };

    try {
        return at.toLocaleTimeString('en-GB', {
            ...options,
            timeZone: timeZone ?? undefined,
        });
    } catch {
        return at.toLocaleTimeString('en-GB', options);
    }
}
