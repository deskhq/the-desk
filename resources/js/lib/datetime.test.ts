import { afterEach, describe, expect, it } from 'vitest';
import { setTimeFormat } from './clock';
import {
    formatCalendarDate,
    formatDateTime,
    formatIsoDay,
    formatLocalTime,
    formatRelativeTime,
    formatTimeOfDay,
    formatWallTime,
} from './datetime';
import { formatNumber } from './numbers';

/**
 * A fixed instant: 2026-07-10 15:30 UTC. July is DST in the US, so New York
 * (UTC-4) reads 11:30 and Tokyo (UTC+9) reads the next day at 00:30.
 */
const INSTANT = '2026-07-10T15:30:00Z';

/**
 * Run `assertions` with the runtime's local zone pinned to `timeZone`, restoring
 * the previous setting afterwards — including the unset case, where assigning
 * `undefined` back would leave the literal string "undefined" behind.
 */
function inTimeZone(timeZone: string, assertions: () => void): void {
    const previous = process.env.TZ;
    process.env.TZ = timeZone;

    try {
        assertions();
    } finally {
        if (previous === undefined) {
            delete process.env.TZ;
        } else {
            process.env.TZ = previous;
        }
    }
}

describe('formatTimeOfDay', () => {
    it('renders the time in the given zone', () => {
        expect(formatTimeOfDay(INSTANT, 'UTC')).toContain('3:30');
        expect(formatTimeOfDay(INSTANT, 'America/New_York')).toContain('11:30');
    });

    it('converts across zones', () => {
        expect(formatTimeOfDay(INSTANT, 'UTC')).not.toEqual(
            formatTimeOfDay(INSTANT, 'America/New_York'),
        );
    });
});

describe('formatDateTime', () => {
    it('includes the date and time in the given zone', () => {
        const utc = formatDateTime(INSTANT, 'UTC');

        expect(utc).toContain('Jul');
        expect(utc).toContain('3:30');
    });

    it('rolls over to the next day in a far-ahead zone', () => {
        expect(formatDateTime(INSTANT, 'Asia/Tokyo')).toContain('11');
        expect(formatDateTime(INSTANT, 'Asia/Tokyo')).toContain('12:30');
    });
});

describe('formatCalendarDate', () => {
    /**
     * The search date-facet chip formats the bare `YYYY-MM-DD` the user filtered
     * on. `new Date()` reads such a day as UTC midnight, so a behind-UTC viewer
     * saw the chip name the day *before* the one the results were filtered from.
     */
    it('keeps a bare calendar day stable in a behind-UTC time zone', () => {
        inTimeZone('America/Los_Angeles', () => {
            expect(formatCalendarDate('2026-01-01', 'en-US')).toBe('Jan 1');
        });
    });

    /**
     * An instant just after UTC midnight, read in a behind-UTC zone, still falls
     * on the previous local day — so it fails loudly if a full timestamp were
     * ever mistaken for a bare day and re-anchored to local midnight.
     */
    it('leaves Date objects and full timestamps on their existing behaviour', () => {
        const boundary = '2026-07-10T00:30:00Z';

        inTimeZone('America/Los_Angeles', () => {
            expect(formatCalendarDate(new Date(boundary), 'en-US')).toBe(
                'Jul 9',
            );
            expect(formatCalendarDate(boundary, 'en-US')).toBe('Jul 9');
        });
    });
});

describe('formatIsoDay', () => {
    it('renders a calendar day with its month and year', () => {
        expect(formatIsoDay('2026-07-10')).toBe('Jul 10, 2026');
    });

    it('follows the requested locale', () => {
        expect(formatIsoDay('2026-07-10', 'fr')).toContain('juil');
    });

    /**
     * A bare `YYYY-MM-DD` is parsed as UTC midnight by `new Date()`, which reads
     * as the previous day in any behind-UTC zone. The helper anchors the day to
     * local midnight instead, so a calendar day never shifts under the reader.
     * Run in a behind-UTC zone, since the bug is invisible at or ahead of UTC.
     */
    it('keeps the day stable in a behind-UTC time zone', () => {
        inTimeZone('America/Los_Angeles', () => {
            // The naive `new Date('2026-01-01')` would render "Dec 31, 2025" here.
            expect(formatIsoDay('2026-01-01', 'en-US')).toBe('Jan 1, 2026');
        });
    });
});

describe('locale-aware formatting', () => {
    it('formats the date in the requested locale', () => {
        // French abbreviates July as "juil." and uses a 24-hour clock.
        const french = formatDateTime(INSTANT, 'UTC', 'fr');

        expect(french).toContain('juil');
        expect(french).toContain('15:30');
    });

    it('formats numbers in the requested locale', () => {
        // French groups thousands with a narrow no-break space, not a comma.
        expect(formatNumber(1234, 'en')).toBe('1,234');
        expect(formatNumber(1234, 'fr')).not.toBe('1,234');
    });
});

describe('formatLocalTime', () => {
    it('returns the wall-clock time for a valid zone', () => {
        expect(
            formatLocalTime('America/New_York', new Date(INSTANT)),
        ).toContain('11:30');
    });

    it('returns null when the zone is missing', () => {
        expect(formatLocalTime(null, new Date(INSTANT))).toBeNull();
    });

    it('returns null when the zone is invalid', () => {
        expect(
            formatLocalTime('Mars/Olympus_Mons', new Date(INSTANT)),
        ).toBeNull();
    });
});

describe('formatRelativeTime', () => {
    const now = new Date(INSTANT);
    const ago = (seconds: number): string =>
        new Date(now.getTime() - seconds * 1000).toISOString();

    it('reads as "just now" under a minute', () => {
        expect(formatRelativeTime(ago(30), 'en', now)).toBe('just now');
    });

    it('picks the coarsest whole unit', () => {
        expect(formatRelativeTime(ago(5 * 3600), 'en', now)).toBe(
            '5 hours ago',
        );
        expect(formatRelativeTime(ago(2 * 86400), 'en', now)).toBe(
            '2 days ago',
        );
        expect(formatRelativeTime(ago(3 * 604800), 'en', now)).toBe(
            '3 weeks ago',
        );
        expect(formatRelativeTime(ago(2 * 2629800), 'en', now)).toBe(
            '2 months ago',
        );
        expect(formatRelativeTime(ago(2 * 31557600), 'en', now)).toBe(
            '2 years ago',
        );
    });

    it('uses numeric "auto" phrasing for the nearest units', () => {
        expect(formatRelativeTime(ago(86400), 'en', now)).toBe('yesterday');
    });

    it('localizes the phrase', () => {
        // French renders -3 days as "il y a 3 jours" (‑2 would collapse to the
        // word "avant-hier"), so a mid-range value exercises the translation.
        expect(formatRelativeTime(ago(3 * 86400), 'fr', now)).toContain('jour');
    });
});

describe('the clock-style preference', () => {
    afterEach(() => {
        setTimeFormat('auto');
    });

    /**
     * Auto is the migration's default, so it has to reproduce the app's
     * pre-preference behaviour byte for byte in both shipped locales: the clock
     * style keeps following the display language.
     */
    describe('auto', () => {
        it('leaves each locale on its own clock style', () => {
            expect(formatTimeOfDay(INSTANT, 'UTC', 'en')).toBe('3:30 PM');
            expect(formatTimeOfDay(INSTANT, 'UTC', 'fr')).toBe('15:30');
        });

        it('leaves quiet-hours bounds on the locale clock too', () => {
            expect(formatWallTime('18:00', 'en')).toBe('6:00 PM');
            expect(formatWallTime('18:00', 'fr')).toBe('18:00');
        });
    });

    describe('12-hour', () => {
        it('renders an English time on a 12-hour clock', () => {
            setTimeFormat('12h');

            expect(formatTimeOfDay(INSTANT, 'UTC', 'en')).toBe('3:30 PM');
        });

        it('overrides a 24-hour locale', () => {
            setTimeFormat('12h');

            expect(formatTimeOfDay(INSTANT, 'UTC', 'fr')).toBe('3:30 PM');
            expect(formatDateTime(INSTANT, 'UTC', 'fr')).toContain('3:30 PM');
            expect(formatLocalTime('UTC', new Date(INSTANT), 'fr')).toBe(
                '3:30 PM',
            );
            expect(formatWallTime('18:00', 'fr')).toBe('6:00 PM');
        });

        it('renders midnight as 12 AM rather than 0 AM', () => {
            setTimeFormat('12h');

            expect(formatWallTime('00:30', 'en')).toBe('12:30 AM');
        });
    });

    describe('24-hour', () => {
        it('overrides a 12-hour locale', () => {
            setTimeFormat('24h');

            expect(formatTimeOfDay(INSTANT, 'UTC', 'en')).toBe('15:30');
            expect(formatDateTime(INSTANT, 'UTC', 'en')).toBe('Jul 10, 15:30');
            expect(formatLocalTime('UTC', new Date(INSTANT), 'en')).toBe(
                '15:30',
            );
            expect(formatWallTime('18:00', 'en')).toBe('18:00');
        });

        it('renders midnight as 00 rather than 24', () => {
            setTimeFormat('24h');

            expect(formatWallTime('00:30', 'en')).toBe('00:30');
        });

        it('leaves a 24-hour locale untouched', () => {
            setTimeFormat('24h');

            expect(formatTimeOfDay(INSTANT, 'UTC', 'fr')).toBe('15:30');
        });
    });

    it('takes an explicit style over the stored preference', () => {
        setTimeFormat('24h');

        expect(formatTimeOfDay(INSTANT, 'UTC', 'en', '12h')).toBe('3:30 PM');
        expect(formatWallTime('18:00', 'en', '12h')).toBe('6:00 PM');
    });

    /**
     * Date-only and relative helpers are explicitly out of scope: they carry no
     * time of day, so the preference must not reach them.
     */
    it('leaves date-only and relative helpers alone', () => {
        setTimeFormat('24h');

        expect(formatIsoDay('2026-07-10', 'en')).toBe('Jul 10, 2026');
        expect(formatCalendarDate('2026-07-10', 'en')).toBe('Jul 10');
        expect(
            formatRelativeTime('2026-07-08T15:30:00Z', 'en', new Date(INSTANT)),
        ).toBe('2 days ago');
    });
});
