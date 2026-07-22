/**
 * The "Pause notifications" choices offered by the presence menu, and the time
 * math that turns one into the instant the pause lapses.
 *
 * Mirrors `statusExpiry` for the custom status: every choice is resolved
 * against the viewer's *own* zone and handed to the server as a UTC instant.
 * Kept free of Vue and DOM so it can be unit-tested in isolation; `now` is
 * always injectable so tests are deterministic.
 */

import { translate } from './i18n';
import { wallTimeToInstant, zonedWallTime } from './scheduleTime';

/** The pause choices, in the order the flyout lists them. */
export const DND_PAUSE_KEYS = [
    'thirty-minutes',
    'one-hour',
    'until-tomorrow',
    'custom',
] as const;

export type DndPauseKey = (typeof DND_PAUSE_KEYS)[number];

const MS_PER_MINUTE = 60_000;

/**
 * The hour "Until tomorrow" runs to: 9:00 the next morning in the viewer's
 * zone, so the pause covers the night and lapses at working hours rather than
 * at a midnight the user sleeps through.
 */
const TOMORROW_MORNING_HOUR = 9;

/**
 * The instant a choice resolves to, as a UTC ISO 8601 string, or null for
 * `custom` (the date-and-time picker supplies the instant instead).
 */
export function resolveDndPause(
    key: DndPauseKey,
    timeZone: string,
    now: Date = new Date(),
): string | null {
    if (key === 'custom') {
        return null;
    }

    if (key === 'thirty-minutes') {
        return new Date(now.getTime() + 30 * MS_PER_MINUTE).toISOString();
    }

    if (key === 'one-hour') {
        return new Date(now.getTime() + 60 * MS_PER_MINUTE).toISOString();
    }

    const wall = zonedWallTime(timeZone, now);
    const tomorrow = new Date(Date.UTC(wall.year, wall.month - 1, wall.day));
    tomorrow.setUTCDate(tomorrow.getUTCDate() + 1);

    return wallTimeToInstant(
        {
            year: tomorrow.getUTCFullYear(),
            month: tomorrow.getUTCMonth() + 1,
            day: tomorrow.getUTCDate(),
            hour: TOMORROW_MORNING_HOUR,
            minute: 0,
        },
        timeZone,
    ).toISOString();
}

/**
 * The translated label for a choice, as the flyout shows it.
 */
export function dndPauseLabel(key: DndPauseKey): string {
    const labels: Record<DndPauseKey, string> = {
        'thirty-minutes': translate('30 minutes'),
        'one-hour': translate('1 hour'),
        'until-tomorrow': translate('Until tomorrow'),
        custom: translate('Custom…'),
    };

    return labels[key];
}
