import type { LinkPrefetchOption } from '@inertiajs/core';

/**
 * The prefetch cache tag a channel's speculative visit is filed under.
 *
 * One home for the string so the link that tags an entry and the realtime
 * arrival that flushes it can never drift apart — a rename on one side alone
 * would leave a stale timeline behind with nothing failing loudly.
 */
export function channelCacheTag(channelId: string): string {
    return `channel:${channelId}`;
}

/**
 * Which trigger a nav link prefetches on, given whether the device can hover.
 *
 * Hover is the only trigger that reliably beats the bar, because it buys the
 * whole travel-to-click time — but on touch `mouseenter` fires ~0 ms before
 * `click` and so gives a phone nothing, while click mode's mousedown → mouseup
 * window (80–150 ms there) is the only head start available. The mode is
 * therefore chosen per device rather than per link.
 *
 * It is deliberately a single mode and never `['hover', 'click']`: Inertia's
 * link renders `if hover → hoverEvents; else if click → clickEvents`, so
 * asking for both silently drops the click half.
 */
export function prefetchTrigger(canHover: boolean): LinkPrefetchOption {
    return canHover ? 'hover' : 'click';
}
