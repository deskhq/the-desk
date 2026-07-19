import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import {
    readThread as markThreadReadAction,
    show as showChannel,
} from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { useDebouncedPost } from '@/composables/useDebouncedPost';
import { useMessageStream } from '@/composables/useMessageStream';
import type { Message, MessagePage, Thread } from '@/types';

type MessageStream = ReturnType<typeof useMessageStream>;

/** How long thread mark-read requests coalesce, mirroring the channel's markRead. */
export const THREAD_READ_DEBOUNCE_MS = 400;

export interface ThreadPanelOptions {
    /** The current team's slug, for the thread show/read routes. */
    teamSlug: () => string;
    /** The open channel's slug, for the thread show/read routes. */
    channelSlug: () => string;
    /** The open channel's id; a change resets the panel for the new channel. */
    channelId: () => string;
    /** The main channel timeline stream, so a root's unread dot can be cleared. */
    mainStream: MessageStream;
    /** The server-resolved thread root, present only on a reload that requests it. */
    thread: () => Thread | null | undefined;
    /** The thread's reply page, newest-first, growing as older replies page in. */
    threadReplies: () => MessagePage;
}

export interface ThreadPanel {
    /** The open thread's root id, kept client-side; null when the panel is closed. */
    activeThreadRootId: Ref<string | null>;
    /** The open thread's root + metadata, or null before it loads / when closed. */
    threadData: Ref<Thread | null>;
    /** Whether the panel is waiting for the root and first reply page to arrive. */
    threadLoading: Ref<boolean>;
    /** The panel's own optimistic + realtime merge stream. */
    threadStream: MessageStream;
    /** The panel's rendered messages (root pinned first, replies by timestamp). */
    threadMessages: ComputedRef<Message[]>;
    /** The client uuids of the panel's optimistic, unconfirmed replies. */
    threadPendingUuids: ComputedRef<string[]>;
    /** Open the thread rooted at a message: navigate, load, and clear its dot. */
    openThread: (rootId: string) => void;
    /** Close the panel: drop `?thread=` from the URL and reset client state. */
    closeThread: () => void;
    /** Reset the panel's client state without navigating (e.g. channel switch). */
    resetThreadPanel: () => void;
    /** Advance the open thread's read pointer, debounced and gated on focus. */
    markThreadRead: () => void;
    /** Adopt a deep-linked thread resolved on the initial page load. */
    adoptDeepLinkedThread: () => void;
}

/**
 * Own the thread panel's open → load → reset → mark-read lifecycle, previously
 * interleaved with the main timeline in `Show.vue`. The panel runs its own
 * {@see useMessageStream} instance over the root plus its paginated replies, kept
 * client-side so a partial reload that omits the optional `thread` prop can't
 * blank an open panel.
 *
 * Opening puts `?thread=<root>` in the URL (loading the root and first reply
 * page), closing drops it, and a channel switch resets the panel here via its own
 * watcher — one seam for the reset-on-switch behaviour. The mark-read post rides
 * {@see useDebouncedPost}, gated on tab focus and capturing the root id so a
 * fire uses the thread that was open when it was scheduled. Posting a reply stays
 * in `useMessageActions`, which shares this panel's stream.
 */
export function useThreadPanel(options: ThreadPanelOptions): ThreadPanel {
    const activeThreadRootId = ref<string | null>(null);
    const threadData = ref<Thread | null>(null);
    const threadLoading = ref(false);

    // The reply page arrives newest-first (older replies page in on scroll-up);
    // the root is the thread's oldest message, so it leads the reversed list. The
    // stream then re-sorts by timestamp, keeping the root pinned to the top.
    const threadServerMessages = computed<Message[]>(() =>
        threadData.value
            ? [
                  threadData.value.root,
                  ...[...(options.threadReplies()?.data ?? [])].reverse(),
              ]
            : [],
    );

    const threadStream = useMessageStream(threadServerMessages);
    const threadMessages = threadStream.displayMessages;
    const threadPendingUuids = threadStream.pendingUuids;

    // Advance the open thread's read pointer so its unread dot clears, mirroring
    // the channel's markRead: debounced, gated on focus, and optimistically
    // clearing the dot on the root back in the main timeline. The root id is
    // captured as the payload so a fire uses the thread open when it was scheduled.
    const threadReadPost = useDebouncedPost(
        (rootId: string) => {
            router.post(
                markThreadReadAction({
                    team: options.teamSlug(),
                    channel: options.channelSlug(),
                    message: rootId,
                }).url,
                {},
                {
                    // A background write: `async` keeps it from interrupting an
                    // in-flight visit (interrupting the openThread GET strands
                    // the panel empty, #581), and `preserveUrl` keeps its
                    // redirect-follow from dropping the `?thread=` param.
                    async: true,
                    preserveUrl: true,
                    preserveScroll: true,
                    preserveState: true,
                    only: ['channels'],
                },
            );

            options.mainStream.patchThreadState(rootId, {
                threadUnread: false,
            });
        },
        { delay: THREAD_READ_DEBOUNCE_MS, gate: () => document.hasFocus() },
    );

    function markThreadRead(): void {
        const rootId = activeThreadRootId.value;

        if (!rootId) {
            return;
        }

        threadReadPost.schedule(rootId);
    }

    function resetThreadPanel(): void {
        activeThreadRootId.value = null;
        threadData.value = null;
        threadLoading.value = false;
        threadStream.reset();
    }

    function openThread(rootId: string): void {
        if (activeThreadRootId.value === rootId) {
            return;
        }

        activeThreadRootId.value = rootId;
        threadStream.reset();
        threadData.value = null;
        threadLoading.value = true;

        // Opening the thread clears its dot straight away; the read pointer
        // advances once the replies load (and again on focus / as replies stream).
        options.mainStream.patchThreadState(rootId, { threadUnread: false });

        router.get(
            showChannel(
                { team: options.teamSlug(), channel: options.channelSlug() },
                { query: { thread: rootId } },
            ).url,
            {},
            {
                only: ['thread', 'threadReplies'],
                reset: ['threadReplies'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => {
                    threadLoading.value = false;
                    markThreadRead();
                },
            },
        );
    }

    function closeThread(): void {
        if (activeThreadRootId.value === null) {
            return;
        }

        resetThreadPanel();

        router.get(
            showChannel({
                team: options.teamSlug(),
                channel: options.channelSlug(),
            }).url,
            {},
            {
                only: ['thread', 'threadReplies'],
                reset: ['threadReplies'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    // Reopen a deep-linked thread: the `thread` prop is already resolved from the
    // `?thread=` param on the initial load, so adopt it directly.
    function adoptDeepLinkedThread(): void {
        const thread = options.thread();

        if (thread) {
            activeThreadRootId.value = thread.root.id;
            threadData.value = thread;
        }
    }

    // The thread prop only arrives on a partial reload that requests it; copy it
    // into client state (guarded to the thread we're actually opening) so a later
    // full visit that omits the optional prop can't blank the open panel.
    watch(options.thread, (thread) => {
        if (thread && thread.root.id === activeThreadRootId.value) {
            threadData.value = thread;
            threadLoading.value = false;
        }
    });

    // Inertia may reuse the page component when navigating between channels; the
    // URL has already moved off any open thread, so reset the panel for the new
    // channel — the one seam for the reset-on-channel-switch behaviour.
    watch(options.channelId, resetThreadPanel);

    return {
        activeThreadRootId,
        threadData,
        threadLoading,
        threadStream,
        threadMessages,
        threadPendingUuids,
        openThread,
        closeThread,
        resetThreadPanel,
        markThreadRead,
        adoptDeepLinkedThread,
    };
}
