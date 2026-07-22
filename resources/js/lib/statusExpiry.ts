/**
 * The "Clear after" choices offered when setting a custom status, and the time
 * math that turns one into the instant the status lapses.
 *
 * Every choice is resolved against the viewer's *own* zone — "Today" ends when
 * their day does, not when the server's does — and handed to the server as a UTC
 * instant, the same contract the composer's "Send later" uses. Kept free of Vue
 * and DOM so it can be unit-tested in isolation; `now` is always injectable so
 * tests are deterministic.
 */

import { translate } from './i18n';
import { wallTimeToInstant, zonedWallTime } from './scheduleTime';

/** The "Clear after" choices, in the order the menu lists them. */
export const STATUS_EXPIRY_KEYS = [
    'never',
    'thirty-minutes',
    'one-hour',
    'today',
    'this-week',
    'custom',
] as const;

export type StatusExpiryKey = (typeof STATUS_EXPIRY_KEYS)[number];

const MS_PER_MINUTE = 60_000;

/**
 * The instant a choice resolves to, as a UTC ISO 8601 string, or null when it
 * names no instant of its own — `never` (the status simply doesn't expire) and
 * `custom` (the date-and-time picker supplies the instant instead).
 */
export function resolveStatusExpiry(
    key: StatusExpiryKey,
    timeZone: string,
    now: Date = new Date(),
): string | null {
    if (key === 'never' || key === 'custom') {
        return null;
    }

    if (key === 'thirty-minutes') {
        return new Date(now.getTime() + 30 * MS_PER_MINUTE).toISOString();
    }

    if (key === 'one-hour') {
        return new Date(now.getTime() + 60 * MS_PER_MINUTE).toISOString();
    }

    const wall = zonedWallTime(timeZone, now);

    // Both remaining choices end *at* a midnight, which is the 00:00 opening the
    // following day rather than a 24:00 that no wall clock shows. "This week"
    // runs to the end of Sunday, so a Sunday resolves to that same evening.
    const daysAhead =
        key === 'this-week' ? 7 - (civilWeekday(wall) || 7) + 1 : 1;

    const boundary = addCivilDays(wall, daysAhead);

    return wallTimeToInstant(
        { ...boundary, hour: 0, minute: 0 },
        timeZone,
    ).toISOString();
}

/**
 * The translated label for a choice, as the "Clear after" select shows it.
 */
export function statusExpiryLabel(key: StatusExpiryKey): string {
    const labels: Record<StatusExpiryKey, string> = {
        never: translate("Don't clear"),
        'thirty-minutes': translate('30 minutes'),
        'one-hour': translate('1 hour'),
        today: translate('Today'),
        'this-week': translate('This week'),
        custom: translate('Custom…'),
    };

    return labels[key];
}

/** A calendar day with no time part. */
type CivilDate = { year: number; month: number; day: number };

/**
 * The day-of-week (0 = Sunday … 6 = Saturday) of a civil date.
 */
function civilWeekday(date: CivilDate): number {
    return new Date(Date.UTC(date.year, date.month - 1, date.day)).getUTCDay();
}

/**
 * Shift a civil date by whole days, wrapping months and years correctly.
 */
function addCivilDays(date: CivilDate, days: number): CivilDate {
    const shifted = new Date(Date.UTC(date.year, date.month - 1, date.day));
    shifted.setUTCDate(shifted.getUTCDate() + days);

    return {
        year: shifted.getUTCFullYear(),
        month: shifted.getUTCMonth() + 1,
        day: shifted.getUTCDate(),
    };
}
