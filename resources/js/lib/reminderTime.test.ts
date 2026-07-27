import { afterEach, describe, expect, it } from 'vitest';
import { setTimeFormat } from '@/lib/clock';
import {
    formatReminderDue,
    groupReminders,
    isReminderToday,
    reminderGroup,
    reminderPresets,
} from '@/lib/reminderTime';

/** A fixed reference instant: Tuesday 14 Jul 2026, 15:30 UTC. */
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

describe('reminderGroup', () => {
    it('files an instant already past as overdue', () => {
        // Earlier the same day still counts: overdue is derived from the
        // instant, not from the calendar day it falls on.
        expect(reminderGroup('2026-07-14T09:00:00.000Z', 'UTC', NOW)).toBe(
            'overdue',
        );
        expect(reminderGroup('2026-07-13T09:00:00.000Z', 'UTC', NOW)).toBe(
            'overdue',
        );
    });

    it('files a still-pending instant later today under today', () => {
        expect(reminderGroup('2026-07-14T17:00:00.000Z', 'UTC', NOW)).toBe(
            'today',
        );
    });

    it('files the next six days under this week', () => {
        // Friday 17 Jul, three days out.
        expect(reminderGroup('2026-07-17T10:00:00.000Z', 'UTC', NOW)).toBe(
            'this-week',
        );
        expect(reminderGroup('2026-07-20T10:00:00.000Z', 'UTC', NOW)).toBe(
            'this-week',
        );
    });

    it('files anything further out under later', () => {
        expect(reminderGroup('2026-07-21T10:00:00.000Z', 'UTC', NOW)).toBe(
            'later',
        );
        expect(reminderGroup('2026-08-14T10:00:00.000Z', 'UTC', NOW)).toBe(
            'later',
        );
    });

    it('judges the buckets in the viewer zone, not UTC', () => {
        // 02:00 UTC on 15 Jul is still 22:00 on 14 Jul in New York, so it is
        // "today" there and "this week" in UTC.
        expect(reminderGroup('2026-07-15T02:00:00.000Z', 'UTC', NOW)).toBe(
            'this-week',
        );
        expect(
            reminderGroup('2026-07-15T02:00:00.000Z', 'America/New_York', NOW),
        ).toBe('today');
    });
});

describe('groupReminders', () => {
    const rows = [
        { id: 'overdue', remindAt: '2026-07-13T09:00:00.000Z' },
        { id: 'today', remindAt: '2026-07-14T18:30:00.000Z' },
        { id: 'this-week', remindAt: '2026-07-17T10:00:00.000Z' },
        { id: 'later', remindAt: '2026-08-20T10:00:00.000Z' },
    ];

    it('splits the rows into the four buckets in display order', () => {
        const groups = groupReminders(rows, 'UTC', NOW);

        expect(groups.map((group) => group.key)).toEqual([
            'overdue',
            'today',
            'this-week',
            'later',
        ]);
        expect(
            groups.map((group) => group.items.map((item) => item.id)),
        ).toEqual([['overdue'], ['today'], ['this-week'], ['later']]);
    });

    it('drops a bucket with nothing in it', () => {
        const groups = groupReminders([rows[1]], 'UTC', NOW);

        expect(groups.map((group) => group.key)).toEqual(['today']);
    });

    it('keeps the soonest-first order the server sent within a bucket', () => {
        const groups = groupReminders(
            [
                { id: 'first', remindAt: '2026-07-14T16:00:00.000Z' },
                { id: 'second', remindAt: '2026-07-14T18:00:00.000Z' },
            ],
            'UTC',
            NOW,
        );

        expect(groups[0].items.map((item) => item.id)).toEqual([
            'first',
            'second',
        ]);
    });

    it('labels each bucket with its English source string', () => {
        const groups = groupReminders(rows, 'UTC', NOW);

        expect(groups.map((group) => group.label)).toEqual([
            'Overdue',
            'Today',
            'This week',
            'Later',
        ]);
    });
});

describe('formatReminderDue', () => {
    it('spells out only the time for something due today', () => {
        expect(formatReminderDue('2026-07-14T17:00:00.000Z', 'UTC', NOW)).toBe(
            '5:00 PM',
        );
    });

    it('names yesterday for the day just gone', () => {
        expect(formatReminderDue('2026-07-13T09:00:00.000Z', 'UTC', NOW)).toBe(
            'Yesterday 9:00 AM',
        );
    });

    it('names the weekday within the coming week', () => {
        expect(formatReminderDue('2026-07-17T10:00:00.000Z', 'UTC', NOW)).toBe(
            'Friday 10:00 AM',
        );
    });

    it('falls back to a calendar date further out', () => {
        expect(formatReminderDue('2026-08-20T10:00:00.000Z', 'UTC', NOW)).toBe(
            'Aug 20 10:00 AM',
        );
    });

    it('renders the instant in the viewer zone', () => {
        // 17:00 UTC is 13:00 in New York, still the same day there.
        expect(
            formatReminderDue(
                '2026-07-14T17:00:00.000Z',
                'America/New_York',
                NOW,
            ),
        ).toBe('1:00 PM');
    });
});

describe('the clock-style preference', () => {
    afterEach(() => {
        setTimeFormat('auto');
    });

    it('renders the preset details on the chosen clock', () => {
        setTimeFormat('24h');

        const presets = reminderPresets('UTC', NOW);
        const byKey = Object.fromEntries(
            presets.map((preset) => [preset.key, preset.detail]),
        );

        expect(byKey.tomorrow).toBe('09:00');
        expect(byKey['next-week']).toBe('Mon, 09:00');
    });
});
