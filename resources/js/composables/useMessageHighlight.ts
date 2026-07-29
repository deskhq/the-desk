import { nextTick, onScopeDispose, ref } from 'vue';
import type { Ref } from 'vue';
import type { TimelineItem } from '@/lib/timeline';
import { timelineItemIndexForMessage } from '@/lib/virtualTimeline';

/** How long a jumped-to message stays highlighted, in ms. */
const HIGHLIGHT_MS = 2000;

export interface MessageHighlightOptions {
    /** The render list the target's index is resolved against. */
    timelineItems: () => TimelineItem[];
    /** Bring a render item into the virtualizer's window. */
    scrollToIndex: (index: number, align: 'start' | 'center') => void;
}

export interface MessageHighlight {
    /** The message to briefly highlight after a jump, or null. */
    highlightedMessageId: Ref<string | null>;
    jumpToMessage: (id: string) => void;
}

/**
 * Jump to a message and mark it for a beat. The server windows the initial page
 * so a search target is loaded; we scroll it into view and clear the highlight
 * after a short beat.
 */
export function useMessageHighlight(
    options: MessageHighlightOptions,
): MessageHighlight {
    const highlightedMessageId = ref<string | null>(null);
    let highlightTimer: ReturnType<typeof setTimeout> | null = null;

    function jumpToMessage(id: string): void {
        // Bring the target's render item into the window first — with windowing its
        // element may not exist yet to scroll to — then refine and highlight once the
        // row mounts. A missing index (target not loaded) still highlights on arrival.
        const index = timelineItemIndexForMessage(options.timelineItems(), id);

        if (index >= 0) {
            options.scrollToIndex(index, 'center');
        }

        nextTick(() => {
            document
                .getElementById(`message-${id}`)
                ?.scrollIntoView({ block: 'center' });

            highlightedMessageId.value = id;

            if (highlightTimer) {
                clearTimeout(highlightTimer);
            }

            highlightTimer = setTimeout(() => {
                highlightedMessageId.value = null;
            }, HIGHLIGHT_MS);
        });
    }

    onScopeDispose(() => {
        if (highlightTimer) {
            clearTimeout(highlightTimer);
        }
    });

    return { highlightedMessageId, jumpToMessage };
}
