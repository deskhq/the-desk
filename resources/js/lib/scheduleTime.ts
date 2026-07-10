/**
 * Pure time math for the "schedule / send later" composer. Every value the user
 * picks is a wall-clock time in *their* stored IANA zone; this module converts
 * between that wall clock and the UTC instant stored on the server, computes the
 * quick presets, and formats a stored instant back for display. Kept free of Vue
 * and DOM so it can be unit-tested in isolation.
 *
 * `now` is always injectable so tests are deterministic.
 */

/** Wall-clock components, as read off a clock on the wall in some zone. */
export type WallTime = {
    year: number;
    month: number; // 1-12
    day: number;
    hour: number; // 0-23
    minute: number;
};

/** A quick-pick option offered in the schedule dialog. */
export type SchedulePreset = {
    key: string;
    label: string;
    /** The chosen instant as a UTC ISO 8601 string. */
    sendAt: string;
};

const MS_PER_MINUTE = 60_000;
const MS_PER_HOUR = 3_600_000;

/**
 * Read the wall-clock components a zone shows at a given instant.
 */
export function zonedWallTime(timeZone: string, at: Date): WallTime {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone,
        hourCycle: 'h23',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).formatToParts(at);

    const read = (type: string): number =>
        Number(parts.find((part) => part.type === type)?.value);

    return {
        year: read('year'),
        month: read('month'),
        day: read('day'),
        hour: read('hour'),
        minute: read('minute'),
    };
}

/**
 * The instant a wall-clock time names in a given zone.
 *
 * Reads the zone's offset at the candidate instant and corrects for it. A single
 * correction is exact everywhere except within the ~1h window of a DST shift,
 * which the presets (morning/evening) never land in.
 */
export function wallTimeToInstant(wall: WallTime, timeZone: string): Date {
    const naiveUtc = Date.UTC(
        wall.year,
        wall.month - 1,
        wall.day,
        wall.hour,
        wall.minute,
    );

    const shown = zonedWallTime(timeZone, new Date(naiveUtc));
    const shownUtc = Date.UTC(
        shown.year,
        shown.month - 1,
        shown.day,
        shown.hour,
        shown.minute,
    );

    // How far the zone's wall clock ran ahead of UTC at the candidate instant.
    const offset = shownUtc - naiveUtc;

    return new Date(naiveUtc - offset);
}

/**
 * Shift a civil date (calendar day, no zone) by whole days, wrapping months and
 * years correctly. Used to name "tomorrow" and "next Monday" as calendar days.
 */
function addCivilDays(
    date: Pick<WallTime, 'year' | 'month' | 'day'>,
    days: number,
): Pick<WallTime, 'year' | 'month' | 'day'> {
    const shifted = new Date(Date.UTC(date.year, date.month - 1, date.day));
    shifted.setUTCDate(shifted.getUTCDate() + days);

    return {
        year: shifted.getUTCFullYear(),
        month: shifted.getUTCMonth() + 1,
        day: shifted.getUTCDate(),
    };
}

/**
 * The day-of-week (0 = Sunday … 6 = Saturday) of a civil date.
 */
function civilWeekday(date: Pick<WallTime, 'year' | 'month' | 'day'>): number {
    return new Date(Date.UTC(date.year, date.month - 1, date.day)).getUTCDay();
}

/**
 * The quick-pick presets to offer, computed against the viewer's zone and the
 * current instant. "In 1 hour" is a pure offset; the rest are wall-clock times
 * (this evening 6pm, tomorrow & next Monday at 9am) resolved to instants. The
 * evening option is dropped once it's too late in the day for it to be future.
 */
export function schedulePresets(
    timeZone: string,
    now: Date = new Date(),
): SchedulePreset[] {
    const presets: SchedulePreset[] = [
        {
            key: 'in-an-hour',
            label: 'In 1 hour',
            sendAt: new Date(now.getTime() + MS_PER_HOUR).toISOString(),
        },
    ];

    const wall = zonedWallTime(timeZone, now);

    // "This evening" (6pm) only while it's still comfortably ahead.
    if (wall.hour < 17) {
        presets.push({
            key: 'this-evening',
            label: 'This evening',
            sendAt: wallTimeToInstant(
                {
                    year: wall.year,
                    month: wall.month,
                    day: wall.day,
                    hour: 18,
                    minute: 0,
                },
                timeZone,
            ).toISOString(),
        });
    }

    const tomorrow = addCivilDays(wall, 1);
    presets.push({
        key: 'tomorrow-morning',
        label: 'Tomorrow morning',
        sendAt: wallTimeToInstant(
            { ...tomorrow, hour: 9, minute: 0 },
            timeZone,
        ).toISOString(),
    });

    // Next Monday (1). `|| 7` makes "on a Monday" resolve to the following one.
    const daysUntilMonday = (((1 - civilWeekday(wall)) % 7) + 7) % 7 || 7;
    const nextMonday = addCivilDays(wall, daysUntilMonday);
    presets.push({
        key: 'next-monday',
        label: 'Next Monday',
        sendAt: wallTimeToInstant(
            { ...nextMonday, hour: 9, minute: 0 },
            timeZone,
        ).toISOString(),
    });

    return presets;
}

/**
 * Combine a 12-hour clock hour with its meridiem into a 24-hour hour, the way
 * the time selects express what the viewer picked.
 */
export function to24Hour(hour12: number, period: 'AM' | 'PM'): number {
    const base = hour12 % 12;

    return period === 'PM' ? base + 12 : base;
}

/**
 * Split a 24-hour clock hour into a 12-hour hour and meridiem, for seeding the
 * time selects when editing an existing scheduled message.
 */
export function to12Hour(hour24: number): {
    hour: number;
    period: 'AM' | 'PM';
} {
    const period: 'AM' | 'PM' = hour24 < 12 ? 'AM' : 'PM';
    const hour = hour24 % 12 === 0 ? 12 : hour24 % 12;

    return { hour, period };
}

/**
 * Whether a chosen instant is far enough in the future to schedule. Mirrors the
 * server's "must be in the future" rule with a small lead so a value the user
 * just picked hasn't already lapsed by the time they confirm.
 */
export function isSendAtInFuture(
    iso: string,
    now: Date = new Date(),
    leadMinutes = 1,
): boolean {
    const target = new Date(iso).getTime();

    return (
        Number.isFinite(target) &&
        target - now.getTime() >= leadMinutes * MS_PER_MINUTE
    );
}

/**
 * Format a stored instant for display in the viewer's zone, e.g.
 * "Today at 3:45 PM", "Tomorrow at 9:00 AM", or "Mon, Jul 14 at 9:00 AM".
 */
export function formatScheduledFor(
    iso: string,
    timeZone: string,
    now: Date = new Date(),
): string {
    const target = new Date(iso);
    const time = target.toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
        timeZone,
    });

    const targetDay = zonedWallTime(timeZone, target);
    const nowDay = zonedWallTime(timeZone, now);

    const dayDiff = Math.round(
        (Date.UTC(targetDay.year, targetDay.month - 1, targetDay.day) -
            Date.UTC(nowDay.year, nowDay.month - 1, nowDay.day)) /
            (24 * MS_PER_HOUR),
    );

    if (dayDiff === 0) {
        return `Today at ${time}`;
    }

    if (dayDiff === 1) {
        return `Tomorrow at ${time}`;
    }

    const date = target.toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        timeZone,
    });

    return `${date} at ${time}`;
}
