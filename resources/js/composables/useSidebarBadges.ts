import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useChannelFleetSubscription } from '@/composables/useChannelFleetSubscription';
import { useDebouncedPost } from '@/composables/useDebouncedPost';
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
 */
export function useSidebarBadges(): void {
    const page = usePage();

    const currentUserId = computed(() => String(page.props.auth.user.id));
    const activeChannelId = computed(
        () => (page.props.channel as { id?: string } | undefined)?.id ?? null,
    );

    // reload defaults to preserving scroll and page state; it re-evaluates the
    // shared `channels` prop to recompute every badge count, plus the aggregate
    // `hasUnreadThreads` flag behind the sidebar's Threads dot.
    const refresh = useDebouncedPost(
        () => router.reload({ only: ['channels', 'hasUnreadThreads'] }),
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
}
