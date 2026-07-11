import { describe, expect, it } from 'vitest';
import { isReminderToday, reminderPresets } from '@/lib/reminderTime';

// A fixed reference instant: Tuesday 14 Jul 2026, 15:30 UTC.
const NOW = new Date('2026-07-14T15:30:00.000Z');

describe('reminderPresets', () => {
    it('offers the three fixed offsets plus tomorrow and next Monday at 9am', () => {
        const presets = reminderPresets('UTC', NOW);

        expect(presets.map((preset) => preset.key)).toEqual([
            'in-20-minutes',
            'in-1-hour',
            'in-3-hours',
            'tomorrow',
            'next-week',
        ]);
    });

    it('measures the fixed offsets from now', () => {
        const presets = reminderPresets('UTC', NOW);
        const byKey = Object.fromEntries(
            presets.map((preset) => [preset.key, preset.remindAt]),
        );

        expect(byKey['in-20-minutes']).toBe('2026-07-14T15:50:00.000Z');
        expect(byKey['in-1-hour']).toBe('2026-07-14T16:30:00.000Z');
        expect(byKey['in-3-hours']).toBe('2026-07-14T18:30:00.000Z');
    });

    it('resolves tomorrow to 9am the next calendar day in the zone', () => {
        const [tomorrow] = reminderPresets('UTC', NOW).filter(
            (preset) => preset.key === 'tomorrow',
        );

        // 15 Jul 2026 is a Wednesday; 9am UTC.
        expect(tomorrow.remindAt).toBe('2026-07-15T09:00:00.000Z');
        expect(tomorrow.detail).toBeTruthy();
    });

    it('resolves next week to the following Monday at 9am', () => {
        const [nextWeek] = reminderPresets('UTC', NOW).filter(
            (preset) => preset.key === 'next-week',
        );

        // The Monday after Tuesday 14 Jul is 20 Jul 2026.
        expect(nextWeek.remindAt).toBe('2026-07-20T09:00:00.000Z');
        expect(nextWeek.detail).toBeTruthy();
    });

    it('resolves the 9am anchors in the viewer zone, not UTC', () => {
        // America/New_York is UTC-4 in July, so 9am local is 13:00 UTC.
        const [tomorrow] = reminderPresets('America/New_York', NOW).filter(
            (preset) => preset.key === 'tomorrow',
        );

        expect(tomorrow.remindAt).toBe('2026-07-15T13:00:00.000Z');
    });
});

describe('isReminderToday', () => {
    it('is true for an instant on the same calendar day in the zone', () => {
        expect(isReminderToday('2026-07-14T23:00:00.000Z', 'UTC', NOW)).toBe(
            true,
        );
    });

    it('is false for an instant on another calendar day', () => {
        expect(isReminderToday('2026-07-15T09:00:00.000Z', 'UTC', NOW)).toBe(
            false,
        );
    });

    it('judges the day in the viewer zone, not UTC', () => {
        // 03:00 UTC on 15 Jul is still 14 Jul (23:00) in New York.
        expect(
            isReminderToday(
                '2026-07-15T03:00:00.000Z',
                'America/New_York',
                NOW,
            ),
        ).toBe(true);
    });
});
