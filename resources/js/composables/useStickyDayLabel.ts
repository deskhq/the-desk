import { computed } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import type { TimelineRange } from '@/composables/useUnreadJump';
import { formatDayLabel } from '@/lib/datetime';
import type { TimelineItem } from '@/lib/timeline';

export interface StickyDayLabelOptions {
    /** The render list the topmost visible row is read from. */
    timelineItems: () => TimelineItem[];
    /** The virtualizer's window, or null before the first range lands. */
    timelineRange: Ref<TimelineRange | null>;
}

/**
 * The day the topmost visible row falls in, driving the floating sticky date
 * chip while the reader is scrolled up into history (design 1a). Reads the first
 * dated render item at or after the window's top — a group's lead timestamp or a
 * day divider's own ISO.
 */
export function useStickyDayLabel(
    options: StickyDayLabelOptions,
): ComputedRef<string | null> {
    return computed<string | null>(() => {
        const range = options.timelineRange.value;
        const items = options.timelineItems();

        if (!range || items.length === 0) {
            return null;
        }

        for (
            let i = Math.min(range.startIndex, items.length - 1);
            i < items.length;
            i += 1
        ) {
            const item = items[i];

            // A day boundary already sits at the top of the window, shown inline —
            // the chip would just duplicate it (e.g. scrolled to the very top), so
            // suppress it until the divider scrolls off and a group leads instead.
            if (item.type === 'divider' && item.variant === 'day') {
                return null;
            }

            if (item.type === 'group') {
                return formatDayLabel(item.leadCreatedAt);
            }
        }

        return null;
    });
}
