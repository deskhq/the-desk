import type { LinkPrefetchOption } from '@inertiajs/core';
import { config, router } from '@inertiajs/vue3';
import { adjacentSlug } from '@/composables/keyboardShortcuts';
import type { Channel } from '@/types/channels';

/**
 * How long a settled prediction waits before it is believed.
 *
 * Read off Inertia's own configuration rather than re-typed, so a palette
 * arrowed through and a sidebar row hovered over wait exactly as long as each
 * other — including if the framework's default ever moves.
 *
 * Deliberately a call rather than a module constant: a constant would read the
 * framework's configuration at *import* time, which every consumer of this
 * module would then inherit whether or not it predicts anything.
 */
export function predictionDelay(): number {
    return config.get('prefetch.hoverDelay');
}

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

/**
 * Fetch a channel ahead of a click that has not happened, under the very
 * contract the sidebar rows declare through their `<Link>`.
 *
 * The empty visit options are the load-bearing half. `only` is part of the
 * prefetch cache key, so a prediction that carried one could never be claimed
 * by the bare `router.visit` that follows it — and nothing would fail loudly,
 * the click would simply go to the server. `cacheFor` is left unnamed for the
 * same reason in reverse: `router.prefetch` falls back to the config default a
 * `<Link>` also falls back to, and naming it here would be a second place for
 * the lifetime to drift from the sidebar's.
 *
 * Every predicting caller goes through here, which is what makes that sameness
 * structural rather than three call sites that happen to agree today.
 */
export function prefetchChannel(url: string, channelId: string): void {
    router.prefetch(url, {}, { cacheTags: [channelCacheTag(channelId)] });
}

/**
 * The channels a ⌘↑ / ⌘↓ walk from `activeSlug` can reach in one step: at most
 * two, deduped, and never the channel already on screen.
 *
 * Built on the same {@see adjacentSlug} the jump itself calls, so a prediction
 * can never point somewhere the jump would not go — including at the ends of
 * the list, which wrap rather than stop. Next is offered before previous, the
 * commoner direction first.
 *
 * The dedupe is what keeps the cost honest at the small end: in a two-channel
 * workspace both neighbours are the same row, and in a one-channel workspace
 * both are the active channel.
 */
export function adjacentChannels(
    channels: readonly Channel[],
    activeSlug: string | null,
): Channel[] {
    const slugs = channels.map((channel) => channel.slug);

    const neighbours = [1, -1]
        .map((delta) => adjacentSlug(slugs, activeSlug, delta))
        .filter((slug): slug is string => slug !== null && slug !== activeSlug);

    return [...new Set(neighbours)].flatMap(
        (slug) => channels.find((channel) => channel.slug === slug) ?? [],
    );
}
