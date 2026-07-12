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
