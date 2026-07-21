/**
 * The bridge between the `YYYY-MM-DD` strings our forms and query params speak
 * and the `DateValue` objects the reka-ui calendar works on. Keeping it here
 * means call sites never touch `@internationalized/date` themselves: a date
 * field's model stays a plain ISO day, exactly as the server sends and expects it.
 */

import type { CalendarDate } from '@internationalized/date';
import { parseDate } from '@internationalized/date';
import type { DateValue } from 'reka-ui';

/**
 * Parse a `YYYY-MM-DD` day into a calendar date, or `undefined` when the value
 * is absent or not a valid day — an empty field and a malformed one both mean
 * "nothing selected" to a picker.
 */
export function toCalendarDate(
    day: string | null | undefined,
): CalendarDate | undefined {
    if (!day) {
        return undefined;
    }

    try {
        return parseDate(day);
    } catch {
        return undefined;
    }
}

/**
 * Serialize a calendar date back to a zero-padded `YYYY-MM-DD` day, or `null`
 * when nothing is selected. The date is read field by field rather than via
 * `toString()` so a zoned or date-time value can never leak a time offset in.
 */
export function toIsoDay(value: DateValue | null | undefined): string | null {
    if (!value) {
        return null;
    }

    const pad = (part: number): string => String(part).padStart(2, '0');

    return `${String(value.year).padStart(4, '0')}-${pad(value.month)}-${pad(value.day)}`;
}
