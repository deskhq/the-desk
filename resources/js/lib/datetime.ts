/**
 * Timestamp formatting helpers. All rendering happens client-side in a target
 * IANA time zone — the viewer's stored zone where known, otherwise the runtime's
 * local zone (passing `timeZone: undefined` to the Intl APIs falls back to it) —
 * and in the active locale, so month names and ordering follow the user's
 * language.
 *
 * The clock style follows the language too, unless the viewer has pinned one:
 * every time of day here resolves its hour cycle through the clock-style
 * preference, which defaults to `auto` (the locale decides). Date-only,
 * relative, and calendar helpers carry no time of day, so the preference does
 * not reach them.
 */

import type { TimeFormat } from '@/types';
import { hourCycleFor, prefersTwelveHour } from './clock';
import { i18n, translate } from './i18n';

/**
 * Format an ISO timestamp as a time of day (e.g. "3:45 PM").
 */
export function formatTimeOfDay(
    iso: string,
    timeZone?: string,
    locale: string = i18n.locale,
    format?: TimeFormat,
): string {
    return new Date(iso).toLocaleTimeString(locale, {
        hour: 'numeric',
        minute: '2-digit',
        hourCycle: hourCycleFor(format),
        timeZone,
    });
}

/**
 * Format an ISO timestamp as an abbreviated date and time (e.g. "Jul 10, 3:45 PM").
 */
export function formatDateTime(
    iso: string,
    timeZone?: string,
    locale: string = i18n.locale,
    format?: TimeFormat,
): string {
    return new Date(iso).toLocaleString(locale, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hourCycle: hourCycleFor(format),
        timeZone,
    });
}

/**
 * Format an `HH:mm` wall-clock reading (e.g. a stored quiet-hours bound) as a
 * time of day. The reading carries no date or zone — it is a time on the clock
 * face, not an instant — so it is anchored to an arbitrary day and rendered in
 * the runtime's own zone, leaving only the hour and minute visible.
 */
export function formatWallTime(
    wallTime: string,
    locale: string = i18n.locale,
    format?: TimeFormat,
): string {
    const [hour, minute] = wallTime.split(':').map(Number);

    return new Date(2000, 0, 1, hour, minute).toLocaleTimeString(locale, {
        hour: 'numeric',
        minute: '2-digit',
        hourCycle: hourCycleFor(format),
    });
}

/**
 * A compact label for a whole hour of the clock face (e.g. "6 PM", "18"), for
 * the tick marks under the quiet-hours strip.
 *
 * On a 24-hour clock the label is the bare zero-padded hour, so the strip keeps
 * its fixed 00 / 06 / 12 / 18 / 24 frame — including the end-of-day 24, which
 * Intl would fold back to 00 (and French would render as "18 h", too wide for a
 * tick). Only the 12-hour clock needs Intl, for its AM/PM markers.
 */
export function formatWallHour(
    hour: number,
    locale: string = i18n.locale,
    format?: TimeFormat,
): string {
    if (!prefersTwelveHour(locale, format)) {
        return String(hour).padStart(2, '0');
    }

    return new Date(2000, 0, 1, hour % 24).toLocaleTimeString(locale, {
        hour: 'numeric',
        hourCycle: 'h12',
    });
}

/** A bare calendar day with no time part, e.g. `2026-07-10`. */
const CALENDAR_DAY = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Read a date input as a `Date`, anchoring a bare `YYYY-MM-DD` calendar day to
 * local midnight: the spec parses such a day as UTC midnight, which renders as
 * the previous day in any behind-UTC zone. `Date` objects and full timestamps
 * carry their own instant and are passed through untouched.
 */
function toLocalDate(date: Date | string): Date {
    if (typeof date === 'string' && CALENDAR_DAY.test(date)) {
        return new Date(`${date}T00:00:00`);
    }

    return new Date(date);
}

/**
 * Format a date as an abbreviated month and day (e.g. "Jul 10"), for chart axes
 * and other date-only labels.
 */
export function formatCalendarDate(
    date: Date | string,
    locale: string = i18n.locale,
): string {
    return toLocalDate(date).toLocaleDateString(locale, {
        month: 'short',
        day: 'numeric',
    });
}

/**
 * Format a `YYYY-MM-DD` calendar day as an abbreviated date with its year
 * (e.g. "Jul 10, 2026"), for date fields and date-range summaries.
 *
 * The day is anchored to local midnight before formatting: `new Date()` reads a
 * bare `YYYY-MM-DD` as UTC midnight, which renders as the previous day in any
 * behind-UTC zone.
 */
export function formatIsoDay(
    day: string,
    locale: string = i18n.locale,
): string {
    return toLocalDate(day).toLocaleDateString(locale, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

/**
 * Format a date as an abbreviated month (e.g. "Jul"), for month-grouped charts.
 */
export function formatMonthLabel(
    date: Date | string,
    locale: string = i18n.locale,
): string {
    return new Date(date).toLocaleDateString(locale, {
        month: 'short',
    });
}

/**
 * A day-boundary label for a timeline divider or the sticky date chip: "Today"
 * or "Yesterday" for the two most recent days (translated), otherwise the full
 * weekday, month, and day — with the year only when it differs from this year.
 */
export function formatDayLabel(
    iso: string,
    locale: string = i18n.locale,
): string {
    const date = new Date(iso);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (date.toDateString() === today.toDateString()) {
        return translate('Today');
    }

    if (date.toDateString() === yesterday.toDateString()) {
        return translate('Yesterday');
    }

    return date.toLocaleDateString(locale, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year:
            date.getFullYear() === today.getFullYear() ? undefined : 'numeric',
    });
}

/**
 * The relative-time divisions, finest first: `amount` is how many of the current
 * unit make up the next one up, so dividing the running duration by each amount
 * in turn climbs from minutes to years. The loop stops at the first unit the
 * magnitude fits inside, yielding the coarsest whole-number phrase ("2 days
 * ago", not "48 hours ago"). The final `Infinity` amount catches everything
 * older, so the loop always returns.
 */
const RELATIVE_TIME_DIVISIONS: ReadonlyArray<{
    amount: number;
    unit: Intl.RelativeTimeFormatUnit;
}> = [
    { amount: 60, unit: 'minute' },
    { amount: 24, unit: 'hour' },
    { amount: 7, unit: 'day' },
    { amount: 4.34524, unit: 'week' },
    { amount: 12, unit: 'month' },
    { amount: Number.POSITIVE_INFINITY, unit: 'year' },
];

/**
 * Format an ISO timestamp as a coarse, locale-aware relative time (e.g. "2 days
 * ago", "5 hours ago", "just now"). Uses `Intl.RelativeTimeFormat` so the phrase
 * and pluralization follow the active locale. `now` is injectable for testing.
 */
export function formatRelativeTime(
    iso: string,
    locale: string = i18n.locale,
    now: Date = new Date(),
): string {
    const elapsedSeconds = (new Date(iso).getTime() - now.getTime()) / 1000;

    if (Math.abs(elapsedSeconds) < 60) {
        return translate('just now');
    }

    const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
    let duration = elapsedSeconds / 60;

    for (const { amount, unit } of RELATIVE_TIME_DIVISIONS) {
        if (Math.abs(duration) < amount) {
            return formatter.format(Math.round(duration), unit);
        }

        duration /= amount;
    }

    // Unreachable: the final division's amount is Infinity, so the loop above
    // always returns before falling through.
    return translate('just now');
}

/**
 * A person's current wall-clock time (e.g. "3:45 PM") in their time zone, or
 * null when the zone is unknown or not a valid IANA identifier.
 */
export function formatLocalTime(
    timeZone: string | null,
    at: Date,
    locale: string = i18n.locale,
    format?: TimeFormat,
): string | null {
    if (!timeZone) {
        return null;
    }

    try {
        return at.toLocaleTimeString(locale, {
            hour: 'numeric',
            minute: '2-digit',
            hourCycle: hourCycleFor(format),
            timeZone,
        });
    } catch {
        return null;
    }
}
