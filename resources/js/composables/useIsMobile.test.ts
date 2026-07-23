import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    MOBILE_MEDIA_QUERY,
    MOBILE_BREAKPOINT,
} from '@/composables/useIsMobile';

/**
 * Stand in for the browser's media-query engine with one that actually
 * evaluates `(max-width: …)` against a width, so the tests below assert the
 * real boundary behaviour rather than a hand-fed boolean.
 */
function windowOfWidth(width: number): void {
    const listeners = new Set<() => void>();

    vi.stubGlobal('window', {
        innerWidth: width,
        matchMedia: (query: string) => {
            const max = Number(/max-width:\s*([\d.]+)px/.exec(query)?.[1]);

            return {
                media: query,
                matches: width <= max,
                addEventListener: (_: string, listener: () => void) =>
                    listeners.add(listener),
                removeEventListener: (_: string, listener: () => void) =>
                    listeners.delete(listener),
            };
        },
    });
}

beforeEach(() => {
    vi.unstubAllGlobals();
});

describe('the mobile breakpoint', () => {
    it('sits at the same 768px Tailwind opens `md:` at', () => {
        expect(MOBILE_BREAKPOINT).toBe(768);
    });

    it('stops short of the breakpoint so `md:` never overlaps it', () => {
        windowOfWidth(768);

        expect(window.matchMedia(MOBILE_MEDIA_QUERY).matches).toBe(false);
    });

    it('covers the last width below the breakpoint', () => {
        windowOfWidth(767);

        expect(window.matchMedia(MOBILE_MEDIA_QUERY).matches).toBe(true);
    });

    it('covers a fractional width below the breakpoint', () => {
        windowOfWidth(767.5);

        expect(window.matchMedia(MOBILE_MEDIA_QUERY).matches).toBe(true);
    });
});
