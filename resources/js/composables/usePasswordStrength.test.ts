import { describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';
import type { PasswordScore } from './usePasswordStrength';
import {
    SEGMENT_COUNT,
    strengthLabel,
    usePasswordStrength,
} from './usePasswordStrength';

/**
 * Covers the advisory strength meter on the register and reset screens (#860).
 * The estimator itself is zxcvbn's; what is worth testing is the reporting
 * around it — the empty state, the label mapping, and that a score only appears
 * once the lazily-loaded estimator has actually run.
 */

describe('strengthLabel', () => {
    it('names every score zxcvbn can return', () => {
        const scores: PasswordScore[] = [0, 1, 2, 3, 4];

        expect(scores.map(strengthLabel)).toEqual([
            'Too weak',
            'Weak',
            'Fair',
            'Strong enough',
            'Very strong',
        ]);
    });
});

describe('usePasswordStrength', () => {
    it('draws as many segments as zxcvbn has score steps', () => {
        expect(SEGMENT_COUNT).toBe(4);
    });

    it('reports no score for an empty password', async () => {
        const password = ref('');
        const { score, label } = usePasswordStrength(password);

        await nextTick();

        expect(score.value).toBeNull();
        expect(label.value).toBeNull();
    });

    it('scores a password once the estimator resolves', async () => {
        const password = ref('');
        const { score, label } = usePasswordStrength(password);

        password.value = 'correct horse battery staple';
        await vi.waitUntil(() => score.value !== null);

        expect(score.value).toBeGreaterThanOrEqual(3);
        expect(label.value).toBe(strengthLabel(score.value as PasswordScore));
    });

    it('drops back to no score when the password is cleared', async () => {
        const password = ref('a-reasonable-passphrase');
        const { score } = usePasswordStrength(password);

        await vi.waitUntil(() => score.value !== null);

        password.value = '';
        await vi.waitUntil(() => score.value === null);

        expect(score.value).toBeNull();
    });

    it('rates a throwaway password below a passphrase', async () => {
        const weak = ref('password');
        const strong = ref('correct horse battery staple');

        const weakStrength = usePasswordStrength(weak);
        const strongStrength = usePasswordStrength(strong);

        await vi.waitUntil(
            () =>
                weakStrength.score.value !== null &&
                strongStrength.score.value !== null,
        );

        expect(weakStrength.score.value).toBeLessThan(
            strongStrength.score.value as number,
        );
    });
});
