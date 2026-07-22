import { describe, expect, it } from 'vitest';
import {
    resolveStatusExpiry,
    STATUS_EXPIRY_KEYS,
    statusExpiryLabel,
} from './statusExpiry';

// A fixed instant to resolve every preset against: Wednesday 10 July 2026,
// 14:30 in London (which is UTC+1 in July).
const NOW = new Date('2026-07-10T13:30:00Z');
const ZONE = 'Europe/London';

describe('resolveStatusExpiry', () => {
    it('offers the six "clear after" choices in menu order', () => {
        expect(STATUS_EXPIRY_KEYS).toEqual([
            'never',
            'thirty-minutes',
            'one-hour',
            'today',
            'this-week',
            'custom',
        ]);
    });

    it('resolves "don\'t clear" to no expiry at all', () => {
        expect(resolveStatusExpiry('never', ZONE, NOW)).toBeNull();
    });

    it('resolves "30 minutes" to half an hour from now', () => {
        expect(resolveStatusExpiry('thirty-minutes', ZONE, NOW)).toBe(
            '2026-07-10T14:00:00.000Z',
        );
    });

    it('resolves "1 hour" to an hour from now', () => {
        expect(resolveStatusExpiry('one-hour', ZONE, NOW)).toBe(
            '2026-07-10T14:30:00.000Z',
        );
    });

    it('resolves "today" to the end of the day in the viewer zone', () => {
        // Midnight ending Friday 10 July in London is 23:00 UTC that evening.
        expect(resolveStatusExpiry('today', ZONE, NOW)).toBe(
            '2026-07-10T23:00:00.000Z',
        );
    });

    it('resolves "this week" to the end of the coming Sunday', () => {
        // 10 July 2026 is a Friday; the week ends at midnight ending Sunday the
        // 12th, which is 23:00 UTC on the 12th in London.
        expect(resolveStatusExpiry('this-week', ZONE, NOW)).toBe(
            '2026-07-12T23:00:00.000Z',
        );
    });

    it('resolves "this week" on a Sunday to that same evening', () => {
        const sunday = new Date('2026-07-12T09:00:00Z');

        expect(resolveStatusExpiry('this-week', ZONE, sunday)).toBe(
            '2026-07-12T23:00:00.000Z',
        );
    });

    it('has no preset instant for a custom expiry, which the picker supplies', () => {
        expect(resolveStatusExpiry('custom', ZONE, NOW)).toBeNull();
    });

    it('resolves the day boundary in the viewer zone, not the runtime one', () => {
        // Same instant, read in Tokyo (UTC+9): it is already 22:30 on the 10th
        // there, so "today" ends only 90 minutes later.
        expect(resolveStatusExpiry('today', 'Asia/Tokyo', NOW)).toBe(
            '2026-07-10T15:00:00.000Z',
        );
    });
});

describe('statusExpiryLabel', () => {
    it('names every choice', () => {
        expect(STATUS_EXPIRY_KEYS.map(statusExpiryLabel)).toEqual([
            "Don't clear",
            '30 minutes',
            '1 hour',
            'Today',
            'This week',
            'Custom…',
        ]);
    });
});
