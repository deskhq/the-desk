import { useMediaQuery } from '@vueuse/core';
import type { Ref } from 'vue';

/**
 * The app's one breakpoint, in pixels: the width Tailwind opens `md:` at. Below
 * it the shell renders a single pane; from it up, the dock and the workspace sit
 * side by side.
 */
export const MOBILE_BREAKPOINT = 768;

/**
 * The media query for "below the breakpoint".
 *
 * It stops at 767.98px rather than 768px on purpose. `(max-width: 768px)` is
 * true *at* 768 while Tailwind's `md:` applies *from* 768 up, so at exactly that
 * width both sides claim the viewport: the dock renders as a Sheet (JS says
 * mobile) while `md:hidden` hides the trigger that opens it (CSS says desktop),
 * leaving no way to reach the dock at all.
 */
export const MOBILE_MEDIA_QUERY = `(max-width: ${MOBILE_BREAKPOINT - 0.02}px)`;

/**
 * Whether the viewport is below the breakpoint. The single source of truth for
 * that question — the shell, the dock, the masthead and the composer all read
 * this rather than each testing their own query.
 */
export function useIsMobile(): Ref<boolean> {
    return useMediaQuery(MOBILE_MEDIA_QUERY);
}
