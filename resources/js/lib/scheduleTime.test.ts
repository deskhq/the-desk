import { describe, expect, it } from 'vitest';
import {
    formatPresetPreview,
    formatScheduledFor,
    isSendAtInFuture,
    schedulePresets,
    to12Hour,
    to24Hour,
    wallTimeToInstant,
    zonedWallTime,
} from '@/lib/scheduleTime';

// A fixed reference instant: Tuesday 14 Jul 2026, 15:30 UTC.
const NOW = new Date('2026-07-14T15:30:00.000Z');

describe('zonedWallTime', () => {
    it('reads the wall clock a zone shows at an instant', () => {
        expect(zonedWallTime('UTC', NOW)).toEqual({
            year: 2026,
            month: 7,
            day: 14,
            hour: 15,
            minute: 30,
        });

        // America/New_York is UTC-4 in July (EDT).
        expect(zonedWallTime('America/New_York', NOW)).toEqual({
            year: 2026,
            month: 7,
            day: 14,
            hour: 11,
            minute: 30,
        });
    });
});

describe('wallTimeToInstant', () => {
    it('resolves a wall-clock time in UTC to the same instant', () => {
        const instant = wallTimeToInstant(
            { year: 2026, month: 7, day: 14, hour: 9, minute: 0 },
            'UTC',
        );

        expect(instant.toISOString()).toBe('2026-07-14T09:00:00.000Z');
    });

    it('resolves a wall-clock time in an offset zone by correcting for the offset', () => {
        // 9:00 AM in New York (UTC-4) is 13:00 UTC.
        const instant = wallTimeToInstant(
            { year: 2026, month: 7, day: 14, hour: 9, minute: 0 },
            'America/New_York',
        );

        expect(instant.toISOString()).toBe('2026-07-14T13:00:00.000Z');
    });
});

describe('schedulePresets', () => {
    it('offers in-an-hour, this-evening, tomorrow and next-monday in the afternoon', () => {
        const presets = schedulePresets('UTC', NOW);
        const byKey = Object.fromEntries(presets.map((p) => [p.key, p.sendAt]));

        expect(presets.map((p) => p.key)).toEqual([
            'in-an-hour',
            'this-evening',
            'tomorrow-morning',
            'next-monday',
        ]);
        expect(byKey['in-an-hour']).toBe('2026-07-14T16:30:00.000Z');
        expect(byKey['this-evening']).toBe('2026-07-14T18:00:00.000Z');
        expect(byKey['tomorrow-morning']).toBe('2026-07-15T09:00:00.000Z');
        // Tuesday 14 Jul → next Monday is 20 Jul.
        expect(byKey['next-monday']).toBe('2026-07-20T09:00:00.000Z');
    });

    it('drops the evening option once it is too late in the day', () => {
        const evening = new Date('2026-07-14T20:00:00.000Z');
        const keys = schedulePresets('UTC', evening).map((p) => p.key);

        expect(keys).not.toContain('this-evening');
        expect(keys).toContain('tomorrow-morning');
    });

    it('resolves the next Monday to the following week when today is Monday', () => {
        const monday = new Date('2026-07-20T09:00:00.000Z');
        const byKey = Object.fromEntries(
            schedulePresets('UTC', monday).map((p) => [p.key, p.sendAt]),
        );

        expect(byKey['next-monday']).toBe('2026-07-27T09:00:00.000Z');
    });
});

describe('formatPresetPreview', () => {
    it('shows only the time for a preset later today', () => {
        // In an hour from 15:30 UTC is 16:30 UTC, same calendar day.
        expect(
            formatPresetPreview('2026-07-14T16:30:00.000Z', 'UTC', NOW),
        ).toBe('4:30 PM');
    });

    it('leads with the weekday for a preset tomorrow', () => {
        // 15 Jul 2026 is a Wednesday.
        expect(
            formatPresetPreview('2026-07-15T09:00:00.000Z', 'UTC', NOW),
        ).toBe('Wed 9:00 AM');
    });

    it('spells out the calendar date for a preset further out', () => {
        expect(
            formatPresetPreview('2026-07-20T09:00:00.000Z', 'UTC', NOW),
        ).toBe('Jul 20, 9:00 AM');
    });
});

describe('to24Hour', () => {
    it('maps a 12-hour clock and meridiem onto a 24-hour hour', () => {
        expect(to24Hour(12, 'AM')).toBe(0);
        expect(to24Hour(9, 'AM')).toBe(9);
        expect(to24Hour(12, 'PM')).toBe(12);
        expect(to24Hour(1, 'PM')).toBe(13);
        expect(to24Hour(11, 'PM')).toBe(23);
    });
});

describe('to12Hour', () => {
    it('splits a 24-hour hour back into a 12-hour hour and meridiem', () => {
        expect(to12Hour(0)).toEqual({ hour: 12, period: 'AM' });
        expect(to12Hour(9)).toEqual({ hour: 9, period: 'AM' });
        expect(to12Hour(12)).toEqual({ hour: 12, period: 'PM' });
        expect(to12Hour(13)).toEqual({ hour: 1, period: 'PM' });
        expect(to12Hour(23)).toEqual({ hour: 11, period: 'PM' });
    });
});

describe('isSendAtInFuture', () => {
    it('accepts an instant at least the lead ahead', () => {
        expect(isSendAtInFuture('2026-07-14T15:32:00.000Z', NOW, 1)).toBe(true);
    });

    it('rejects an instant inside the lead window or in the past', () => {
        expect(isSendAtInFuture('2026-07-14T15:30:30.000Z', NOW, 1)).toBe(
            false,
        );
        expect(isSendAtInFuture('2026-07-14T15:00:00.000Z', NOW, 1)).toBe(
            false,
        );
    });

    it('rejects an unparseable instant', () => {
        expect(isSendAtInFuture('nonsense', NOW, 1)).toBe(false);
    });
});

describe('formatScheduledFor', () => {
    it('labels today and tomorrow by name', () => {
        expect(
            formatScheduledFor('2026-07-14T18:00:00.000Z', 'UTC', NOW),
        ).toMatch(/^Today at /);
        expect(
            formatScheduledFor('2026-07-15T09:00:00.000Z', 'UTC', NOW),
        ).toMatch(/^Tomorrow at /);
    });

    it('labels a further day by its weekday and date', () => {
        const label = formatScheduledFor(
            '2026-07-20T09:00:00.000Z',
            'UTC',
            NOW,
        );

        expect(label).toMatch(/Jul 20/);
        expect(label).not.toMatch(/^Today/);
        expect(label).not.toMatch(/^Tomorrow/);
    });
});
