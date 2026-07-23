import { describe, expect, it } from 'vitest';

import { keyboardInset } from '@/lib/keyboardInset';

describe('keyboardInset', () => {
    it('is nothing while the on-screen keyboard is closed', () => {
        expect(
            keyboardInset({ innerHeight: 844, height: 844, offsetTop: 0 }),
        ).toBe(0);
    });

    it('measures the slice of the layout viewport the keyboard covers', () => {
        // An iPhone 14 with the keyboard open: 844 tall, 508 of it still visible.
        expect(
            keyboardInset({ innerHeight: 844, height: 508, offsetTop: 0 }),
        ).toBe(336);
    });

    it('discounts the part of the shortfall the page is scrolled by', () => {
        // Safari scrolls the layout viewport up as the keyboard opens: 60px of
        // the 336px shortfall is offset, not covered.
        expect(
            keyboardInset({ innerHeight: 844, height: 508, offsetTop: 60 }),
        ).toBe(276);
    });

    it('never reports a negative inset', () => {
        // A pinch-zoomed visual viewport can report taller than the layout one.
        expect(
            keyboardInset({ innerHeight: 844, height: 900, offsetTop: 0 }),
        ).toBe(0);
    });

    it('ignores a shortfall too small to be a keyboard', () => {
        // Browser chrome (a collapsing URL bar) moves the visual viewport by a
        // few dozen pixels; padding the composer by that would make it jitter.
        expect(
            keyboardInset({ innerHeight: 844, height: 800, offsetTop: 0 }),
        ).toBe(0);
    });
});
