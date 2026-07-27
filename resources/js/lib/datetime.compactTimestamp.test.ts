import { describe, expect, it } from 'vitest';
import { formatCompactTimestamp, formatTimeOfDay } from '@/lib/datetime';

/** An ISO timestamp `days` days before now, at the same time of day. */
function daysAgo(days: number): string {
    const date = new Date();
    date.setDate(date.getDate() - days);

    return date.toISOString();
}

describe('formatCompactTimestamp', () => {
    it('shows only the time of day for today', () => {
        const now = new Date().toISOString();

        expect(formatCompactTimestamp(now)).toBe(formatTimeOfDay(now));
    });

    it('labels yesterday relatively', () => {
        // No catalog loaded in tests, so translate falls back to the English key.
        expect(formatCompactTimestamp(daysAgo(1))).toBe('Yesterday');
    });

    it('names the weekday within the past week', () => {
        const threeDaysAgo = daysAgo(3);
        const weekday = new Date(threeDaysAgo).toLocaleDateString('en-US', {
            weekday: 'short',
        });

        expect(formatCompactTimestamp(threeDaysAgo, undefined, 'en-US')).toBe(
            weekday,
        );
    });

    it('falls back to an abbreviated date past a week, where a weekday would be ambiguous', () => {
        expect(
            formatCompactTimestamp('2020-03-14T12:00:00.000Z', 'UTC', 'en-US'),
        ).toBe('Mar 14');
    });
});
