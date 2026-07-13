/**
 * Timestamp formatting helpers. All rendering happens client-side in a target
 * IANA time zone — the viewer's stored zone where known, otherwise the runtime's
 * local zone (passing `timeZone: undefined` to the Intl APIs falls back to it) —
 * and in the active locale, so month names, ordering, and clock style follow the
 * user's language.
 */

import { i18n, translate } from './i18n';

/**
 * Format an ISO timestamp as a time of day (e.g. "3:45 PM").
 */
export function formatTimeOfDay(
    iso: string,
    timeZone?: string,
    locale: string = i18n.locale,
): string {
    return new Date(iso).toLocaleTimeString(locale, {
        hour: 'numeric',
        minute: '2-digit',
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
): string {
    return new Date(iso).toLocaleString(locale, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone,
    });
}

/**
 * Format a date as an abbreviated month and day (e.g. "Jul 10"), for chart axes
 * and other date-only labels.
 */
export function formatCalendarDate(
    date: Date | string,
    locale: string = i18n.locale,
): string {
    return new Date(date).toLocaleDateString(locale, {
        month: 'short',
        day: 'numeric',
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
): string | null {
    if (!timeZone) {
        return null;
    }

    try {
        return at.toLocaleTimeString(locale, {
            hour: 'numeric',
            minute: '2-digit',
            timeZone,
        });
    } catch {
        return null;
    }
}
