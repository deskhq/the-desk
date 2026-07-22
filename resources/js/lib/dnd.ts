import { wallTimeToInstant, zonedWallTime } from './scheduleTime';
import type { WallTime } from './scheduleTime';

/**
 * Client-side twin of `User::isDndActive()` on the server: whether the viewer
 * is in do-not-disturb at a given instant, from the full configuration their
 * own `auth.user` prop carries.
 *
 * The chime gate needs this answer at message-arrival time, not at page-load
 * time — a pause that lapsed two minutes ago, or a quiet-hours window that
 * opened one minute ago, must take effect without waiting for a server
 * round-trip. Keep the semantics in lockstep with the server: start inclusive,
 * end exclusive, a window whose end precedes its start wraps across midnight,
 * and a snooze still ahead of its lapse suppresses the window outright.
 */
export function isDndActiveNow(
    dnd: App.Data.UserDndData | null | undefined,
    timeZone: string | null | undefined,
    at: Date = new Date(),
): boolean {
    if (!dnd) {
        return false;
    }

    if (dnd.until !== null && new Date(dnd.until) > at) {
        return true;
    }

    if (!dnd.scheduleEnabled || dnd.startsAt === null || dnd.endsAt === null) {
        return false;
    }

    if (
        dnd.scheduleSnoozedUntil !== null &&
        new Date(dnd.scheduleSnoozedUntil) > at
    ) {
        return false;
    }

    const wallClock = wallClockIn(timeZone, at);

    if (dnd.startsAt <= dnd.endsAt) {
        return wallClock >= dnd.startsAt && wallClock < dnd.endsAt;
    }

    return wallClock >= dnd.startsAt || wallClock < dnd.endsAt;
}

/**
 * The instant the currently-running quiet-hours window closes, or null when
 * the schedule is off, incomplete, or not covering this instant.
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
    if (!dnd?.scheduleEnabled || dnd.startsAt === null || dnd.endsAt === null) {
        return null;
    }

    const wallClock = wallClockIn(timeZone, at);
    const zone = timeZone ?? deviceZone();
    const wall = zonedWallTime(zone, at);
    const [hour, minute] = dnd.endsAt.split(':').map(Number);
    const closing = { ...wall, hour, minute };

    if (dnd.startsAt <= dnd.endsAt) {
        if (wallClock < dnd.startsAt || wallClock >= dnd.endsAt) {
            return null;
        }

        return wallTimeToInstant(closing, zone);
    }

    if (wallClock < dnd.endsAt) {
        return wallTimeToInstant(closing, zone);
    }

    if (wallClock >= dnd.startsAt) {
        return wallTimeToInstant(nextCivilDay(closing), zone);
    }

    return null;
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
 */
export function quietHoursTicks(startsAt: string, endsAt: string): string[] {
    const hours = new Set([0, 6, 12, 18, 24]);

    hours.add(Number(startsAt.slice(0, 2)));
    hours.add(Number(endsAt.slice(0, 2)));

    return [...hours]
        .sort((a, b) => a - b)
        .map((hour) => String(hour).padStart(2, '0'));
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
