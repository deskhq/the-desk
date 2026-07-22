import { describe, expect, it } from 'vitest';
import {
    isDndActiveNow,
    quietHoursEndsAt,
    quietHoursSegments,
    quietHoursTicks,
} from '@/lib/dnd';

function dnd(
    overrides: Partial<App.Data.UserDndData> = {},
): App.Data.UserDndData {
    return {
        until: null,
        scheduleEnabled: false,
        startsAt: null,
        endsAt: null,
        scheduleSnoozedUntil: null,
        ...overrides,
    };
}

describe('isDndActiveNow', () => {
    it('is inactive with no configuration at all', () => {
        expect(isDndActiveNow(dnd(), 'UTC')).toBe(false);
        expect(isDndActiveNow(null, 'UTC')).toBe(false);
        expect(isDndActiveNow(undefined, 'UTC')).toBe(false);
    });

    it('is active while a manual pause is still ahead', () => {
        const at = new Date('2026-07-22T12:00:00Z');

        expect(
            isDndActiveNow(dnd({ until: '2026-07-22T12:30:00Z' }), 'UTC', at),
        ).toBe(true);
    });

    it('lapses the instant the pause passes', () => {
        const at = new Date('2026-07-22T12:30:00Z');

        expect(
            isDndActiveNow(dnd({ until: '2026-07-22T12:30:00Z' }), 'UTC', at),
        ).toBe(false);
    });

    it('is active inside an enabled quiet-hours window', () => {
        const schedule = dnd({
            scheduleEnabled: true,
            startsAt: '09:00',
            endsAt: '17:00',
        });

        expect(
            isDndActiveNow(schedule, 'UTC', new Date('2026-07-22T12:00:00Z')),
        ).toBe(true);
        expect(
            isDndActiveNow(schedule, 'UTC', new Date('2026-07-22T18:30:00Z')),
        ).toBe(false);
    });

    it('is inactive inside a window while a snooze is still ahead', () => {
        const schedule = dnd({
            scheduleEnabled: true,
            startsAt: '09:00',
            endsAt: '17:00',
            scheduleSnoozedUntil: '2026-07-22T17:00:00Z',
        });

        expect(
            isDndActiveNow(schedule, 'UTC', new Date('2026-07-22T12:00:00Z')),
        ).toBe(false);
    });

    it('honours the window again once the snooze lapses', () => {
        const schedule = dnd({
            scheduleEnabled: true,
            startsAt: '09:00',
            endsAt: '17:00',
            scheduleSnoozedUntil: '2026-07-22T17:00:00Z',
        });

        expect(
            isDndActiveNow(schedule, 'UTC', new Date('2026-07-23T12:00:00Z')),
        ).toBe(true);
    });

    it('never mutes a manual pause with a snooze', () => {
        const paused = dnd({
            until: '2026-07-22T14:00:00Z',
            scheduleEnabled: true,
            startsAt: '09:00',
            endsAt: '17:00',
            scheduleSnoozedUntil: '2026-07-22T17:00:00Z',
        });

        expect(
            isDndActiveNow(paused, 'UTC', new Date('2026-07-22T12:00:00Z')),
        ).toBe(true);
    });

    it('starts the window inclusive and ends it exclusive', () => {
        const schedule = dnd({
            scheduleEnabled: true,
            startsAt: '09:00',
            endsAt: '17:00',
        });

        expect(
            isDndActiveNow(schedule, 'UTC', new Date('2026-07-22T09:00:00Z')),
        ).toBe(true);
        expect(
            isDndActiveNow(schedule, 'UTC', new Date('2026-07-22T17:00:00Z')),
        ).toBe(false);
    });

    it('wraps an overnight window across midnight', () => {
        const schedule = dnd({
            scheduleEnabled: true,
            startsAt: '22:00',
            endsAt: '07:00',
        });

        expect(
            isDndActiveNow(schedule, 'UTC', new Date('2026-07-22T23:30:00Z')),
        ).toBe(true);
        expect(
            isDndActiveNow(schedule, 'UTC', new Date('2026-07-22T06:30:00Z')),
        ).toBe(true);
        expect(
            isDndActiveNow(schedule, 'UTC', new Date('2026-07-22T12:00:00Z')),
        ).toBe(false);
    });

    it('evaluates the window on the wall clock of the given zone', () => {
        const schedule = dnd({
            scheduleEnabled: true,
            startsAt: '09:00',
            endsAt: '17:00',
        });

        // 14:00 UTC is 10:00 in New York — inside the window. 12:00 UTC is
        // 08:00 there — outside it, though a naive UTC read would say inside.
        expect(
            isDndActiveNow(
                schedule,
                'America/New_York',
                new Date('2026-07-22T14:00:00Z'),
            ),
        ).toBe(true);
        expect(
            isDndActiveNow(
                schedule,
                'America/New_York',
                new Date('2026-07-22T12:00:00Z'),
            ),
        ).toBe(false);
    });

    it('ignores a disabled or incomplete schedule', () => {
        expect(
            isDndActiveNow(
                dnd({
                    scheduleEnabled: false,
                    startsAt: '00:00',
                    endsAt: '23:59',
                }),
                'UTC',
            ),
        ).toBe(false);
        expect(isDndActiveNow(dnd({ scheduleEnabled: true }), 'UTC')).toBe(
            false,
        );
    });

    it('falls back to the local zone for a missing or invalid zone', () => {
        const schedule = dnd({
            scheduleEnabled: true,
            startsAt: '00:00',
            endsAt: '23:59',
        });

        // A window covering (almost) the whole day is active in any zone, so
        // the fallback path is observable without pinning the runner's zone.
        expect(isDndActiveNow(schedule, null)).toBe(true);
        expect(isDndActiveNow(schedule, 'Not/AZone')).toBe(true);
    });

    it('either the pause or the schedule alone is enough', () => {
        const at = new Date('2026-07-22T12:00:00Z');

        expect(
            isDndActiveNow(
                dnd({
                    until: '2026-07-22T13:00:00Z',
                    scheduleEnabled: true,
                    startsAt: '20:00',
                    endsAt: '21:00',
                }),
                'UTC',
                at,
            ),
        ).toBe(true);
    });
});

describe('quietHoursEndsAt', () => {
    it('is null outside the window, or with no usable schedule', () => {
        const schedule = dnd({
            scheduleEnabled: true,
            startsAt: '09:00',
            endsAt: '17:00',
        });

        expect(
            quietHoursEndsAt(schedule, 'UTC', new Date('2026-07-22T18:00:00Z')),
        ).toBeNull();
        expect(
            quietHoursEndsAt(dnd(), 'UTC', new Date('2026-07-22T12:00:00Z')),
        ).toBeNull();
        expect(quietHoursEndsAt(null, 'UTC')).toBeNull();
    });

    it('ends a same-day window at its closing time today', () => {
        const schedule = dnd({
            scheduleEnabled: true,
            startsAt: '09:00',
            endsAt: '17:00',
        });

        expect(
            quietHoursEndsAt(
                schedule,
                'UTC',
                new Date('2026-07-22T12:00:00Z'),
            )?.toISOString(),
        ).toBe('2026-07-22T17:00:00.000Z');
    });

    it('ends an overnight window tomorrow when entered before midnight', () => {
        const schedule = dnd({
            scheduleEnabled: true,
            startsAt: '22:00',
            endsAt: '07:00',
        });

        expect(
            quietHoursEndsAt(
                schedule,
                'UTC',
                new Date('2026-07-22T23:30:00Z'),
            )?.toISOString(),
        ).toBe('2026-07-23T07:00:00.000Z');
    });

    it('ends an overnight window today when inside its morning tail', () => {
        const schedule = dnd({
            scheduleEnabled: true,
            startsAt: '22:00',
            endsAt: '07:00',
        });

        expect(
            quietHoursEndsAt(
                schedule,
                'UTC',
                new Date('2026-07-22T06:00:00Z'),
            )?.toISOString(),
        ).toBe('2026-07-22T07:00:00.000Z');
    });

    it('resolves the closing instant in the given zone', () => {
        const schedule = dnd({
            scheduleEnabled: true,
            startsAt: '09:00',
            endsAt: '17:00',
        });

        // 14:00 UTC is 10:00 in New York; their 17:00 close is 21:00 UTC.
        expect(
            quietHoursEndsAt(
                schedule,
                'America/New_York',
                new Date('2026-07-22T14:00:00Z'),
            )?.toISOString(),
        ).toBe('2026-07-22T21:00:00.000Z');
    });
});

describe('quietHoursSegments', () => {
    it('draws awake–quiet–awake for a same-day window', () => {
        expect(quietHoursSegments('09:00', '17:00')).toEqual([
            { quiet: false, widthPct: 37.5 },
            { quiet: true, widthPct: (8 / 24) * 100 },
            { quiet: false, widthPct: (7 / 24) * 100 },
        ]);
    });

    it('draws quiet–awake–quiet for an overnight window', () => {
        expect(quietHoursSegments('18:00', '09:00')).toEqual([
            { quiet: true, widthPct: 37.5 },
            { quiet: false, widthPct: 37.5 },
            { quiet: true, widthPct: 25 },
        ]);
    });

    it('drops zero-width segments at the day edges', () => {
        expect(quietHoursSegments('00:00', '17:00')).toEqual([
            { quiet: true, widthPct: (17 / 24) * 100 },
            { quiet: false, widthPct: (7 / 24) * 100 },
        ]);
    });
});

describe('quietHoursTicks', () => {
    it('merges the window bounds into the base ticks, sorted', () => {
        expect(quietHoursTicks('18:00', '09:00')).toEqual([
            '00',
            '06',
            '09',
            '12',
            '18',
            '24',
        ]);
    });

    it('keeps the base ticks alone when the bounds already sit on them', () => {
        expect(quietHoursTicks('18:00', '12:00')).toEqual([
            '00',
            '06',
            '12',
            '18',
            '24',
        ]);
    });
});
