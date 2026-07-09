import { router, usePage } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { shouldRefreshSidebar } from '@/lib/shouldRefreshSidebar';
import type { Message } from '@/types';

/** Coalesce a burst of arrivals into a single sidebar reload. */
const REFRESH_DEBOUNCE_MS = 500;

/**
 * Keep the sidebar's unread and mention badges live.
 *
 * The shared `channels` prop is recomputed server-side (see HandleInertiaRequests)
 * but only refreshes on navigation and when the open channel is marked read, so a
 * message posted in a channel the user belongs to but is not viewing would not move
 * its badge until the next visit. Mounted once in the persistent channel layout,
 * this subscribes to each sidebar channel's private broadcast and, on a qualifying
 * MessageSent, debounces a partial reload of `channels` so the badge updates without
 * a manual navigation. A single reload recomputes every channel's count, so bursts
 * across channels collapse to one request.
 *
 * It mirrors useChimeNotifications' subscription bookkeeping: Show.vue owns the open
 * channel's subscription and tears it down with a full `leave` on navigation, which
 * also drops our listener on it; the post-flush reconcile re-attaches the channel it
 * just left.
 */
export function useSidebarBadges(): void {
    const page = usePage();

    const currentUserId = computed(() => String(page.props.auth.user.id));
    const channels = computed(() => page.props.channels ?? []);
    const activeChannelId = computed(
        () => (page.props.channel as { id?: string } | undefined)?.id ?? null,
    );

    // Only reconcile when the set of channels or the open one actually changes,
    // not on every badge-count refresh of the shared `channels` prop.
    const subscriptionKey = computed(
        () =>
            `${channels.value.map((channel) => channel.id).join('|')}::${activeChannelId.value ?? ''}`,
    );

    const bound = new Set<string>();
    let previousActiveId: string | null = null;
    let refreshTimer: ReturnType<typeof setTimeout> | null = null;

    function channelName(id: string): string {
        return `channel.${id}`;
    }

    function scheduleRefresh(): void {
        if (refreshTimer) {
            clearTimeout(refreshTimer);
        }

        refreshTimer = setTimeout(() => {
            // reload defaults to preserving scroll and page state; it re-evaluates
            // the shared `channels` prop to recompute every badge count, plus the
            // aggregate `hasUnreadThreads` flag behind the sidebar's Threads dot.
            router.reload({ only: ['channels', 'hasUnreadThreads'] });
        }, REFRESH_DEBOUNCE_MS);
    }

    function handleMessage(channelId: string, message: Message): void {
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
            scheduleRefresh();
        }
    }

    function listen(id: string): void {
        echo()
            .private(channelName(id))
            .listen('MessageSent', (message: Message) => {
                handleMessage(id, message);
            });
    }

    function reconcile(): void {
        const desired = new Set(channels.value.map((channel) => channel.id));
        const active = activeChannelId.value;

        if (previousActiveId !== null && previousActiveId !== active) {
            bound.delete(previousActiveId);
        }

        previousActiveId = active;

        for (const id of desired) {
            if (!bound.has(id)) {
                listen(id);
                bound.add(id);
            }
        }

        for (const id of [...bound]) {
            if (!desired.has(id)) {
                echo().leave(channelName(id));
                bound.delete(id);
            }
        }
    }

    function leaveAll(): void {
        for (const id of bound) {
            echo().leave(channelName(id));
        }

        bound.clear();
    }

    onMounted(reconcile);

    // Post-flush so it runs after Show.vue's own per-channel teardown.
    watch(subscriptionKey, reconcile, { flush: 'post' });

    onBeforeUnmount(() => {
        if (refreshTimer) {
            clearTimeout(refreshTimer);
        }

        leaveAll();
    });
}
