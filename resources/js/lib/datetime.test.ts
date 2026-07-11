import { describe, expect, it } from 'vitest';
import { formatDateTime, formatLocalTime, formatTimeOfDay } from './datetime';
import { formatNumber } from './numbers';

// A fixed instant: 2026-07-10 15:30 UTC. July is DST in the US, so New York
// (UTC-4) reads 11:30 and Tokyo (UTC+9) reads the next day at 00:30.
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
