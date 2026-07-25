import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { formatCountdown, useResendCooldown } from './useResendCooldown';

/**
 * Covers the "Resend in 0:42" affordance on the forgot-password screen (#860).
 * It is a double-submit guard, not a security control — the server's own
 * throttle is that — so what matters here is that it counts down honestly and
 * always releases the button.
 */

describe('formatCountdown', () => {
    it('renders seconds against a leading minute', () => {
        expect(formatCountdown(42)).toBe('0:42');
        expect(formatCountdown(9)).toBe('0:09');
        expect(formatCountdown(60)).toBe('1:00');
        expect(formatCountdown(125)).toBe('2:05');
        expect(formatCountdown(0)).toBe('0:00');
    });
});

describe('useResendCooldown', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('starts idle', () => {
        const { isCooling, remaining } = useResendCooldown(60);

        expect(isCooling.value).toBe(false);
        expect(remaining.value).toBe(0);
    });

    it('counts down a second at a time once started', () => {
        const { start, remaining, isCooling } = useResendCooldown(60);

        start();
        expect(remaining.value).toBe(60);
        expect(isCooling.value).toBe(true);

        vi.advanceTimersByTime(3000);
        expect(remaining.value).toBe(57);
    });

    it('releases the button when the window elapses', () => {
        const { start, remaining, isCooling } = useResendCooldown(5);

        start();
        vi.advanceTimersByTime(5000);

        expect(remaining.value).toBe(0);
        expect(isCooling.value).toBe(false);
    });

    it('never counts below zero if the timer overruns', () => {
        const { start, remaining } = useResendCooldown(2);

        start();
        vi.advanceTimersByTime(60_000);

        expect(remaining.value).toBe(0);
    });

    it('tracks wall-clock time rather than counting ticks', () => {
        const { start, remaining, isCooling } = useResendCooldown(30);

        start();

        // A throttled or suspended tab delivers far fewer ticks than seconds
        // elapsed; the countdown has to reflect the time, not the callbacks.
        vi.advanceTimersByTime(30_000);

        expect(remaining.value).toBe(0);
        expect(isCooling.value).toBe(false);
    });

    it('restarts from full on a second send', () => {
        const { start, remaining } = useResendCooldown(30);

        start();
        vi.advanceTimersByTime(20_000);
        expect(remaining.value).toBe(10);

        start();
        expect(remaining.value).toBe(30);

        vi.advanceTimersByTime(1000);
        expect(remaining.value).toBe(29);
    });
});
