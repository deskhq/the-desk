import { beforeEach, describe, expect, it, vi } from 'vitest';

import { HOVER_MEDIA_QUERY } from '@/composables/useCanHover';

/**
 * Stand in for the browser's media-query engine with one that answers the
 * `hover` feature the way a real device would, so the query below is asserted
 * against pointer semantics rather than against a hand-fed boolean.
 */
function deviceThatHovers(canHover: boolean): void {
    vi.stubGlobal('window', {
        matchMedia: (query: string) => ({
            media: query,
            matches: /\(\s*hover:\s*hover\s*\)/.test(query) && canHover,
            addEventListener: () => {},
            removeEventListener: () => {},
        }),
    });
}

beforeEach(() => {
    vi.unstubAllGlobals();
});

describe('the hover-capability query', () => {
    it('matches a device whose primary pointer can hover', () => {
        deviceThatHovers(true);

        expect(window.matchMedia(HOVER_MEDIA_QUERY).matches).toBe(true);
    });

    it('does not match a touch device, where nothing hovers before a tap', () => {
        deviceThatHovers(false);

        expect(window.matchMedia(HOVER_MEDIA_QUERY).matches).toBe(false);
    });

    /**
     * `(any-hover: hover)` would call a laptop with a touchscreen — or a phone
     * with a mouse plugged in — hover-capable regardless of what the person is
     * actually using. The plain feature asks about the *primary* pointer.
     */
    it('asks about the primary pointer rather than any attached one', () => {
        expect(HOVER_MEDIA_QUERY).not.toContain('any-hover');
    });
});
