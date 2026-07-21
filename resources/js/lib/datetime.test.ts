import { describe, expect, it } from 'vitest';
import {
    formatDateTime,
    formatIsoDay,
    formatLocalTime,
    formatRelativeTime,
    formatTimeOfDay,
} from './datetime';
import { formatNumber } from './numbers';

/**
 * A fixed instant: 2026-07-10 15:30 UTC. July is DST in the US, so New York
 * (UTC-4) reads 11:30 and Tokyo (UTC+9) reads the next day at 00:30.
 */
const INSTANT = '2026-07-10T15:30:00Z';

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
        const zone = process.env.TZ;
        process.env.TZ = 'America/Los_Angeles';

        try {
            // The naive `new Date('2026-01-01')` would render "Dec 31, 2025" here.
            expect(formatIsoDay('2026-01-01', 'en-US')).toBe('Jan 1, 2026');
        } finally {
            process.env.TZ = zone;
        }
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
