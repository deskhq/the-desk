import { computed, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import type { TimelineItem } from '@/lib/timeline';
import {
    isDividerVisible,
    shouldShowUnreadJump,
    unreadDividerIndex,
} from '@/lib/virtualTimeline';

/** The virtualizer's visible render-item window. */
export interface TimelineRange {
    startIndex: number;
    endIndex: number;
}

export interface UnreadJumpOptions {
    /** The open channel's id; a change refreezes the per-visit latches. */
    channelId: () => string;
    /** The render list the unread boundary's index is resolved against. */
    timelineItems: () => TimelineItem[];
    /** The virtualizer's window, or null before the first range lands. */
    timelineRange: Ref<TimelineRange | null>;
    /** Bring a render item into the virtualizer's window. */
    scrollToIndex: (index: number, align: 'start' | 'center') => void;
    /** Return to the newest message. */
    scrollToBottom: (smooth?: boolean) => void;
}

export interface UnreadJump {
    /** Whether to float the "New messages" pill over the timeline. */
    showJumpToUnread: ComputedRef<boolean>;
    scrollToUnread: () => void;
    jumpToPresent: () => void;
}

/**
 * The floating "New messages" pill: whether the unread boundary is still ahead
 * of the reader, and the two ways they can leave it behind.
 *
 * Windowing drops the off-screen divider from the DOM, so this is pure range
 * math over the virtualizer's window plus a per-visit seen latch, rather than an
 * `IntersectionObserver` on an element that may not exist.
 */
export function useUnreadJump(options: UnreadJumpOptions): UnreadJump {
    /** The unread boundary's render-item index, or -1 when there's none. */
    const unreadIndex = computed(() =>
        unreadDividerIndex(options.timelineItems()),
    );

    /**
     * Per-visit latch: once the reader reaches the unread boundary (it scrolls into
     * the window) or jumps back to the present, the "New messages" pill is dismissed
     * for the rest of this channel visit. Without it the pill reappears whenever the
     * (frozen) divider sits above the window again — e.g. right after Jump to present
     * (#411). Both flags are refrozen alongside the divider on every channel switch.
     */
    const unreadDividerSeen = ref(false);

    /**
     * Whether the boundary has ever sat above the window this visit. The reader
     * "reaches" the divider only when it scrolls back into view *after* having been
     * above — the transition the seen latch keys off. This guards against the initial
     * pre-`scrollToBottom` render (rows start at the top, so the divider is briefly
     * on screen before the open pins to the newest message) latching it prematurely.
     */
    const unreadDividerWasAbove = ref(false);

    watch(options.channelId, () => {
        unreadDividerSeen.value = false;
        unreadDividerWasAbove.value = false;
    });

    // Track the boundary's position relative to the window and latch it as seen the
    // moment it scrolls back into view after having been above — the reader clicking
    // the pill or scrolling up to the divider.
    watch([options.timelineRange, unreadIndex], ([range, index]) => {
        if (!range || index < 0) {
            return;
        }

        if (index < range.startIndex) {
            unreadDividerWasAbove.value = true;
        } else if (
            unreadDividerWasAbove.value &&
            isDividerVisible(index, range.startIndex, range.endIndex)
        ) {
            unreadDividerSeen.value = true;
        }
    });

    /**
     * Show the floating "New messages" pill while the unread boundary sits above the
     * virtualizer's window and the reader hasn't reached it yet. Before the first
     * range lands the view is pinned to the bottom, so an existing, unseen boundary
     * is necessarily above it.
     */
    const showJumpToUnread = computed(() => {
        const range = options.timelineRange.value;

        if (!range) {
            return unreadIndex.value >= 0 && !unreadDividerSeen.value;
        }

        return shouldShowUnreadJump(
            unreadIndex.value,
            range.startIndex,
            range.endIndex,
            unreadDividerSeen.value,
        );
    });

    /**
     * Scroll the unread boundary to the top of the viewport via the virtualizer,
     * bringing it on screen even when it wasn't rendered.
     */
    function scrollToUnread(): void {
        if (unreadIndex.value >= 0) {
            options.scrollToIndex(unreadIndex.value, 'start');
        }
    }

    /**
     * Return to the newest message. Jumping to present counts as reaching the unread
     * boundary, so latch it — the reader is done with the "New messages" pill for
     * this visit even if the frozen divider now sits above the window again (#411).
     */
    function jumpToPresent(): void {
        unreadDividerSeen.value = true;
        options.scrollToBottom(true);
    }

    return { showJumpToUnread, scrollToUnread, jumpToPresent };
}
