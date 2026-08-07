import { router, usePage } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { useChannelFleetSubscription } from '@/composables/useChannelFleetSubscription';
import { useDebouncedPost } from '@/composables/useDebouncedPost';
import { backgroundVisit } from '@/lib/backgroundVisit';
import { isChannelTraffic } from '@/lib/channelTraffic';
import { channelCacheTag } from '@/lib/prefetch';
import { CHANNEL_LIST_PROPS, UNREAD_DIGEST_PROPS } from '@/lib/reloadProps';
import { shouldRefreshSidebar } from '@/lib/shouldRefreshSidebar';

/** Coalesce a burst of arrivals into a single sidebar reload. */
const REFRESH_DEBOUNCE_MS = 500;

/**
 * Keep the sidebar's unread and mention badges live.
 *
 * The shared `unread` digest is recomputed server-side (see HandleInertiaRequests)
 * but only refreshes on navigation and when the open channel is marked read, so a
 * message posted in a channel the user belongs to but is not viewing would not move
 * its badge until the next visit. Mounted once in the persistent channel layout,
 * this rides {@see useChannelFleetSubscription} — the shared subscribe/reconcile/
 * teardown engine — and, on a qualifying MessageSent, debounces a partial reload of
 * the digest so the badge updates without a manual navigation. A single reload
 * recomputes every channel's count, so bursts across channels collapse to one
 * request.
 *
 * The same reload also answers {@see \App\Events\ReadStateAdvanced} on the
 * viewer's own private `user.{id}` channel, which is how *clearing* a badge
 * reaches their other devices: reading on a phone would otherwise leave the
 * desktop showing the channel unread until its next navigation. Sharing one
 * debounced reload with the arrival side means a read and an arrival landing
 * together still cost a single request.
 *
 * The same arrivals also invalidate what the sidebar has *prefetched*. The rows
 * fetch their channel ahead of the click and tag the entry with
 * {@see channelCacheTag}; this fleet is subscribed to exactly that set, so the
 * flush costs one call inside a callback that already runs — no second
 * subscription, no polling, no fingerprint. Two blind spots ride along and are
 * bounded by the 30 s cache lifetime: the fleet hears `MessageSent` only, so an
 * edit, deletion, reaction or pin elsewhere never reaches it, and a dropped
 * socket stops the flushing entirely (Echo is never load-bearing for
 * correctness).
 */
export function useSidebarBadges(): void {
    const page = usePage();

    const currentUserId = computed(() => String(page.props.auth.user.id));
    const activeChannelId = computed(
        () => (page.props.channel as { id?: string } | undefined)?.id ?? null,
    );

    // reload defaults to preserving scroll and page state. One prop answers
    // every badge the shell draws — the sidebar's per-channel counts, the
    // rail's cross-workspace dots and its Threads dot were three readings of
    // the same fact before the digest consolidated them — so a burst across
    // channels still collapses to a single request for a single prop.
    //
    // `channels` rides along because an arrival also moves what the *roster*
    // says: the direct-message group orders on last activity, and a DM nobody
    // had messaged in yet earns its row on the first message. Neither is unread
    // state, so neither belongs in the digest. A teammate's message — or
    // another of the viewer's own devices — decides when this fires, so it runs
    // as a background visit ({@see backgroundVisit}); otherwise an arrival
    // landing mid-navigation cancels the visit the user actually asked for.
    //
    // `unreadThreadCount` is shared only while that panel is open, so asking for
    // it off the destination costs nothing: the server simply has no such prop to
    // resolve. The panel's own card list is deliberately not in this set — pulling
    // a merge prop here would reset the pages the viewer had scrolled through.
    const refresh = useDebouncedPost(
        () =>
            router.reload({
                ...backgroundVisit,
                only: [
                    ...UNREAD_DIGEST_PROPS,
                    ...CHANNEL_LIST_PROPS,
                    'unreadThreadCount',
                ],
            }),
        { delay: REFRESH_DEBOUNCE_MS },
    );

    useChannelFleetSubscription((channelId, message) => {
        // The sidebar rows prefetch the channels this same fleet is subscribed
        // to, so the event that makes a prefetched timeline wrong is already
        // arriving here: drop that entry and the click after it goes back to
        // the server rather than painting a message-old screen. It runs ahead
        // of — and independently of — the badge decision below, which
        // deliberately ignores arrivals a badge does not move.
        router.flushByCacheTags(channelCacheTag(channelId));

        const decision = shouldRefreshSidebar({
            isOwnMessage: message.user.id === currentUserId.value,
            isChannelMessage: isChannelTraffic(message),
            mentionsCurrentUser: message.mentions.some(
                (mention) => mention.id === currentUserId.value,
            ),
            isActiveChannel: channelId === activeChannelId.value,
            tabHasFocus: typeof document !== 'undefined' && document.hasFocus(),
        });

        if (decision) {
            refresh.schedule();
        }
    });

    function ownChannelName(): string {
        return `user.${currentUserId.value}`;
    }

    // Echo opens a websocket, so touch it only in the browser (never on the SSR
    // pass). The authenticated user is stable for the session, so a single
    // subscribe/teardown pair is enough.
    onMounted(() => {
        echo()
            .private(ownChannelName())
            .listen('ReadStateAdvanced', () => {
                refresh.schedule();
            });
    });

    onBeforeUnmount(() => {
        echo().leave(ownChannelName());
    });
}
