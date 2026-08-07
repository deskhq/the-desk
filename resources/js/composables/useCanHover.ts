import { useMediaQuery } from '@vueuse/core';
import type { Ref } from 'vue';

/**
 * The media query for "the primary pointer can hover".
 *
 * Deliberately `hover` and not `any-hover`: the latter answers yes for a laptop
 * with a touchscreen or a phone with a mouse plugged in, whichever the person is
 * actually holding, and the whole point of the question is what the *current*
 * interaction can offer.
 */
export const HOVER_MEDIA_QUERY = '(hover: hover)';

/**
 * Whether this device hovers before it clicks. The single source of truth for
 * that question, the way {@see useIsMobile} owns the breakpoint — read by the
 * nav links to choose their prefetch trigger, since a `hover` prefetch buys a
 * pointer the whole travel-to-click time and buys a finger nothing at all.
 */
export function useCanHover(): Ref<boolean> {
    return useMediaQuery(HOVER_MEDIA_QUERY);
}
