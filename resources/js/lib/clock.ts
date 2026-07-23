import { reactive } from 'vue';
import type { TimeFormat } from '@/types';

/**
 * The viewer's clock-style preference, seeded from their `auth.user` prop at
 * boot (see `app.ts`) and swapped live when they pick another style.
 *
 * It is reactive and read inside the formatting helpers, so every rendered time
 * of day re-renders the instant the preference changes — no reload, and no
 * threading the preference through every call site.
 */
const clock = reactive<{ format: TimeFormat }>({ format: 'auto' });

/** The active clock-style preference. */
export function timeFormat(): TimeFormat {
    return clock.format;
}

/** Replace the active clock-style preference, e.g. after the user picks one. */
export function setTimeFormat(format: TimeFormat): void {
    clock.format = format;
}

/**
 * The Intl hour cycle a preference asks for, or `undefined` under `auto` — where
 * omitting the option is what lets the locale keep deciding, reproducing the
 * behaviour the app had before the preference existed.
 *
 * The chosen cycles are the sane halves of each pair: `h12` renders midnight as
 * "12 AM" (not `h11`'s "0 AM") and `h23` renders it as "00" (not `h24`'s "24").
 */
export function hourCycleFor(
    format: TimeFormat = clock.format,
): Intl.DateTimeFormatOptions['hourCycle'] {
    switch (format) {
        case '12h':
            return 'h12';
        case '24h':
            return 'h23';
        default:
            return undefined;
    }
}

/**
 * Whether times land on a 12-hour clock once the preference is resolved against
 * the locale — asking Intl what `auto` means for that language rather than
 * hardcoding a list. Callers that hand-build clock labels (the quiet-hours
 * strip) need this answer; callers that format through Intl want
 * `hourCycleFor` instead.
 */
export function prefersTwelveHour(
    locale: string,
    format: TimeFormat = clock.format,
): boolean {
    if (format !== 'auto') {
        return format === '12h';
    }

    return (
        new Intl.DateTimeFormat(locale, { hour: 'numeric' }).resolvedOptions()
            .hour12 === true
    );
}
