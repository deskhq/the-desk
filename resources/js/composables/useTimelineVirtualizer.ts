import { useVirtualizer } from '@tanstack/vue-virtual';
import type { VirtualItem } from '@tanstack/vue-virtual';
import { computed, watch } from 'vue';
import type { ComponentPublicInstance, ComputedRef, Ref } from 'vue';
import { shouldLoadOlder } from '@/lib/virtualTimeline';

/**
 * Estimated height (px) of an unmeasured timeline row. Author groups and
 * dividers vary widely, so this is only the first-paint guess the virtualizer
 * refines via `measureElement` once each row lands; a mid-range value keeps the
 * initial spacer close enough that anchoring barely shifts on measurement.
 */
export const ESTIMATED_ROW_HEIGHT = 84;

/**
 * Rows rendered beyond the visible window on each side. A small buffer keeps
 * fast scrolls from flashing blank before the next rows mount.
 */
export const OVERSCAN = 6;

/**
 * How close (in render-item indices) the first rendered row must come to the top
 * of the loaded history before the next older page is fetched.
 */
export const LOAD_OLDER_THRESHOLD = 4;

/**
 * Windowed rendering for a message timeline. Wraps TanStack's virtualizer over
 * the flat `buildTimelineItems` render list, driving the existing scroll
 * container so `useScrollPin`'s real-`scrollHeight` math keeps working.
 *
 * Older-page loading stays with Inertia's `<InfiniteScroll>` merge engine: this
 * composable only decides *when* to fetch, calling `loadOlder` once the first
 * rendered row nears the top of the loaded history. The consumer wires that to
 * the component's `fetchPrevious()` and reports whether more history and an
 * in-flight request exist, so the pure `shouldLoadOlder` guard can gate it.
 */
export function useTimelineVirtualizer(options: {
    scrollElement: Ref<HTMLElement | null>;
    count: ComputedRef<number>;
    hasOlder: () => boolean;
    isLoadingOlder: () => boolean;
    loadOlder: () => void;
}) {
    const virtualizer = useVirtualizer(
        computed(() => ({
            count: options.count.value,
            getScrollElement: () => options.scrollElement.value,
            estimateSize: () => ESTIMATED_ROW_HEIGHT,
            overscan: OVERSCAN,
            // Keep the viewport visually stable when a row above it is measured
            // (its real height replaces the estimate): shift the scroll offset
            // so history doesn't jump under a reader scrolled into the past.
            shouldAdjustScrollPositionOnItemSizeChange: (item: VirtualItem) =>
                item.start < (options.scrollElement.value?.scrollTop ?? 0),
        })),
    );

    const virtualRows = computed(() => virtualizer.value.getVirtualItems());

    const totalSize = computed(() => virtualizer.value.getTotalSize());

    const isScrolling = computed(() => virtualizer.value.isScrolling);

    // The first rendered render-item index, or the very top when nothing has
    // rendered yet so an initial short history still triggers a top-load check.
    const startIndex = computed(() => virtualRows.value[0]?.index ?? 0);

    const range = computed(() => virtualizer.value.range);

    /**
     * Bring the row at `index` into view, honoring `align` ('center' for a
     * highlighted jump target, 'start' for the unread boundary).
     */
    function scrollToIndex(
        index: number,
        align: 'auto' | 'start' | 'center' | 'end' = 'center',
    ): void {
        virtualizer.value.scrollToIndex(index, { align });
    }

    // Fetch older history as the reader approaches the top of what's loaded. The
    // guard folds in "more remain" and "not already loading" so a burst of range
    // updates during a fast scroll can't stack duplicate requests.
    watch(startIndex, (first) => {
        if (
            shouldLoadOlder(
                first,
                LOAD_OLDER_THRESHOLD,
                options.hasOlder(),
                options.isLoadingOlder(),
            )
        ) {
            options.loadOlder();
        }
    });

    return {
        virtualizer,
        virtualRows,
        totalSize,
        isScrolling,
        range,
        scrollToIndex,
        // Passed as a template `:ref` on each windowed row so the virtualizer can
        // measure its true height (reading the row's `data-index`). Vue types a
        // function ref's argument as element-or-component; the rows are plain
        // divs, so narrow to the element the virtualizer measures.
        measureRow: (node: Element | ComponentPublicInstance | null) =>
            virtualizer.value.measureElement(node as Element | null),
    };
}
