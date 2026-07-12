import { describe, expect, it } from 'vitest';
import { formatDayLabel } from '@/lib/datetime';

describe('formatDayLabel', () => {
    it('labels today and yesterday relatively', () => {
        const today = new Date();
        const yesterday = new Date();
        yesterday.setDate(today.getDate() - 1);

        // No catalog loaded in tests, so translate falls back to the English key.
        expect(formatDayLabel(today.toISOString())).toBe('Today');
        expect(formatDayLabel(yesterday.toISOString())).toBe('Yesterday');
    });

    it('spells out an absolute day for older dates', () => {
        const label = formatDayLabel('2020-03-14T12:00:00.000Z');

        // A long-past date is neither relative label and carries its year.
        expect(label).not.toBe('Today');
        expect(label).not.toBe('Yesterday');
        expect(label).toContain('2020');
    });
});
