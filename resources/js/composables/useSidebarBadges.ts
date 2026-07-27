import { router, usePage } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { useChannelFleetSubscription } from '@/composables/useChannelFleetSubscription';
import { useDebouncedPost } from '@/composables/useDebouncedPost';
import { backgroundVisit } from '@/lib/backgroundVisit';
import { shouldRefreshSidebar } from '@/lib/shouldRefreshSidebar';

/** Coalesce a burst of arrivals into a single sidebar reload. */
const REFRESH_DEBOUNCE_MS = 500;

/**
 * Keep the sidebar's unread and mention badges live.
 *
 * The shared `channels` prop is recomputed server-side (see HandleInertiaRequests)
 * but only refreshes on navigation and when the open channel is marked read, so a
 * message posted in a channel the user belongs to but is not viewing would not move
 * its badge until the next visit. Mounted once in the persistent channel layout,
 * this rides {@see useChannelFleetSubscription} — the shared subscribe/reconcile/
 * teardown engine — and, on a qualifying MessageSent, debounces a partial reload of
 * `channels` so the badge updates without a manual navigation. A single reload
 * recomputes every channel's count, so bursts across channels collapse to one
 * request.
 *
 * The same reload also answers {@see \App\Events\ReadStateAdvanced} on the
 * viewer's own private `user.{id}` channel, which is how *clearing* a badge
 * reaches their other devices: reading on a phone would otherwise leave the
 * desktop showing the channel unread until its next navigation. Sharing one
 * debounced reload with the arrival side means a read and an arrival landing
 * together still cost a single request.
 */
export function useSidebarBadges(): void {
    const page = usePage();

    const currentUserId = computed(() => String(page.props.auth.user.id));
    const activeChannelId = computed(
        () => (page.props.channel as { id?: string } | undefined)?.id ?? null,
    );

    // reload defaults to preserving scroll and page state; it re-evaluates the
    // shared `channels` prop to recompute every badge count, the aggregate
    // `hasUnreadThreads` flag behind the sidebar's Threads dot, and `teams` —
    // whose per-workspace counts are the cross-workspace dots on the rail. The
    // last one rides along rather than getting its own signal: no per-member
    // fanout of MessageSent exists, so a workspace the viewer is not in
    // refreshes on the next trigger any of their own channels raises. A
    // teammate's
    // message — or another of the viewer's own devices — decides when it fires,
    // so it runs as a background visit ({@see backgroundVisit}); otherwise an
    // arrival landing mid-navigation cancels the visit the user actually asked
    // for.
    const refresh = useDebouncedPost(
        () =>
            router.reload({
                ...backgroundVisit,
                only: ['channels', 'hasUnreadThreads', 'teams'],
            }),
        { delay: REFRESH_DEBOUNCE_MS },
    );

    useChannelFleetSubscription((channelId, message) => {
        const decision = shouldRefreshSidebar({
            isOwnMessage: message.user.id === currentUserId.value,
            isChannelMessage:
                message.threadRootId === null || message.sentToChannel,
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
