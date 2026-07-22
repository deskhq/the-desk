import { describe, expect, it } from 'vitest';
import { DND_PAUSE_KEYS, dndPauseLabel, resolveDndPause } from '@/lib/dndPause';

describe('resolveDndPause', () => {
    const now = new Date('2026-07-22T12:00:00Z');

    it('offers the four presets in menu order', () => {
        expect(DND_PAUSE_KEYS).toEqual([
            'thirty-minutes',
            'one-hour',
            'until-tomorrow',
            'custom',
        ]);
    });

    it('resolves the fixed durations from now', () => {
        expect(resolveDndPause('thirty-minutes', 'UTC', now)).toBe(
            '2026-07-22T12:30:00.000Z',
        );
        expect(resolveDndPause('one-hour', 'UTC', now)).toBe(
            '2026-07-22T13:00:00.000Z',
        );
    });

    it('resolves "until tomorrow" to 9:00 the next morning in the given zone', () => {
        expect(resolveDndPause('until-tomorrow', 'UTC', now)).toBe(
            '2026-07-23T09:00:00.000Z',
        );

        // 9:00 in New York on the 23rd is 13:00 UTC.
        expect(resolveDndPause('until-tomorrow', 'America/New_York', now)).toBe(
            '2026-07-23T13:00:00.000Z',
        );
    });

    it('rolls "until tomorrow" across a month boundary', () => {
        expect(
            resolveDndPause(
                'until-tomorrow',
                'UTC',
                new Date('2026-07-31T22:00:00Z'),
            ),
        ).toBe('2026-08-01T09:00:00.000Z');
    });

    it('names no instant for the custom choice, whose picker supplies it', () => {
        expect(resolveDndPause('custom', 'UTC', now)).toBeNull();
    });

    it('labels every choice', () => {
        expect(dndPauseLabel('thirty-minutes')).toBe('30 minutes');
        expect(dndPauseLabel('one-hour')).toBe('1 hour');
        expect(dndPauseLabel('until-tomorrow')).toBe('Until tomorrow');
        expect(dndPauseLabel('custom')).toBe('Custom…');
    });
});
