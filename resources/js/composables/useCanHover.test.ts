// @vitest-environment jsdom
import { beforeEach, describe, expect, it } from 'vitest';

import { HOVER_MEDIA_QUERY, useCanHover } from '@/composables/useCanHover';

/**
 * Answer the `hover` feature the way a real device would. jsdom ships a
 * `matchMedia` that reports every query as unmatched, so a composable that
 * genuinely reads the media-query engine needs one that evaluates the feature
 * rather than a hand-fed boolean.
 */
function deviceThatHovers(canHover: boolean): void {
    window.matchMedia = ((query: string) => ({
        media: query,
        matches: /\(\s*hover:\s*hover\s*\)/.test(query) && canHover,
        addEventListener: () => {},
        removeEventListener: () => {},
    })) as unknown as typeof window.matchMedia;
}

beforeEach(() => {
    deviceThatHovers(true);
});

describe('the hover capability', () => {
    it('is true on a device whose primary pointer can hover', () => {
        expect(useCanHover().value).toBe(true);
    });

    it('is false on a touch device, where nothing hovers before a tap', () => {
        deviceThatHovers(false);

        expect(useCanHover().value).toBe(false);
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
