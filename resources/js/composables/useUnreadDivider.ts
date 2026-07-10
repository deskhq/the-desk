import { computed, nextTick, onScopeDispose, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { unreadDividerMessageId } from '@/lib/unreadDivider';
import type { Message } from '@/types';

/** The stable DOM id the "New messages" divider element renders with. */
const DIVIDER_ELEMENT_ID = 'unread-divider';

export interface UnreadDividerOptions {
    /** The open channel's id; a change refreezes the divider for that channel. */
    channelId: () => string;
    /** The scroll container the divider lives in, used as the observer root. */
    scrollContainer: Ref<HTMLElement | null>;
    /**
     * The channel's messages at open, oldest-first. Read from the server page
     * (not the live-merged list) so the boundary is immune to the order in which
     * per-channel state resets on a channel switch.
     */
    messages: () => Message[];
    /** The read pointer captured when the channel opened, or null if never read. */
    lastReadMessageId: () => string | null;
    /** The current viewer's id, so their own trailing messages aren't flagged. */
    currentUserId: () => string;
}

export interface UnreadDivider {
    /** The message the divider sits above, frozen at open; null when none. */
    unreadDividerId: Ref<string | null>;
    /** Whether to show the floating "jump to new messages" pill. */
    showJumpToUnread: ComputedRef<boolean>;
    /** Smooth-scroll the divider into view. */
    scrollToUnread: () => void;
}

/**
 * Own the "New messages" divider's lifecycle: freeze its position at channel
 * open (and refreeze on every channel switch), and watch the divider element so
 * the floating jump pill hides once the boundary scrolls into view.
 *
 * The boundary itself is decided by the pure {@see unreadDividerMessageId}; this
 * composable is the reactive + IntersectionObserver wiring around it. The divider
 * is deliberately *not* a plain computed: it is captured once per channel so the
 * read pointer advancing as the user reads doesn't move the line under them.
 */
export function useUnreadDivider(options: UnreadDividerOptions): UnreadDivider {
    const unreadDividerId = ref<string | null>(null);
    const unreadDividerInView = ref(false);
    let observer: IntersectionObserver | null = null;

    // The pill shows only while there's a boundary the reader hasn't reached yet.
    const showJumpToUnread = computed(
        () => unreadDividerId.value !== null && !unreadDividerInView.value,
    );

    // Watch the divider element so the pill hides once it scrolls into view.
    function observeDivider(): void {
        observer?.disconnect();
        observer = null;
        unreadDividerInView.value = false;

        // The divider element and IntersectionObserver only exist in the
        // browser. During SSR there's no DOM, so skip the wiring entirely — the
        // client re-runs this on hydration. Guards against a `document is not
        // defined` crash when the immediate watcher fires on the server.
        if (unreadDividerId.value === null || typeof document === 'undefined') {
            return;
        }

        nextTick(() => {
            const el = document.getElementById(DIVIDER_ELEMENT_ID);
            const root = options.scrollContainer.value;

            if (!el || !root) {
                return;
            }

            observer = new IntersectionObserver(
                ([entry]) => {
                    unreadDividerInView.value = entry.isIntersecting;
                },
                { root },
            );
            observer.observe(el);
        });
    }

    function recompute(): void {
        unreadDividerId.value = unreadDividerMessageId(
            options.messages(),
            options.lastReadMessageId(),
            options.currentUserId(),
        );

        observeDivider();
    }

    function scrollToUnread(): void {
        document
            .getElementById(DIVIDER_ELEMENT_ID)
            ?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }

    // Freeze on open and refreeze on each channel switch; `recompute` owns the
    // reset (divider id, observer, in-view flag) so the page doesn't hand-reset it.
    watch(options.channelId, recompute, { immediate: true });

    onScopeDispose(() => {
        observer?.disconnect();
    });

    return { unreadDividerId, showJumpToUnread, scrollToUnread };
}
