import { ref, watch } from 'vue';
import type { Ref } from 'vue';

/** The slice of Inertia's `<InfiniteScroll>` the timeline drives manually. */
interface InfiniteScrollHandle {
    fetchNext: () => void;
    hasNext: () => boolean;
}

export interface ChannelHistoryOptions {
    /** How many messages the server page currently holds. */
    loadedCount: () => number;
}

export interface ChannelHistory {
    /** Bind to Inertia's `<InfiniteScroll>` so the fetch can be driven manually. */
    infiniteScrollRef: Ref<InfiniteScrollHandle | null>;
    /** Whether an older page is in flight, driving the "Loading earlier…" pill. */
    loadingOlder: Ref<boolean>;
    hasOlder: () => boolean;
    isLoadingOlder: () => boolean;
    loadOlderMessages: () => void;
}

/**
 * Reverse-paginated history for the channel timeline.
 *
 * In reverse mode, older history is the paginator's *next* page (the server
 * returns messages newest-first), so "load older" maps to fetchNext/hasNext.
 * The in-flight flag gates the virtualizer's top-load trigger so a burst of
 * range updates can't stack duplicate requests; it clears once the merged page
 * grows (older rows have landed).
 */
export function useChannelHistory(
    options: ChannelHistoryOptions,
): ChannelHistory {
    const infiniteScrollRef = ref<InfiniteScrollHandle | null>(null);
    const loadingOlder = ref(false);

    const hasOlder = (): boolean => infiniteScrollRef.value?.hasNext() ?? false;

    const isLoadingOlder = (): boolean => loadingOlder.value;

    /**
     * Fetch the next older page through Inertia's merge engine. The virtualizer
     * decides *when* (the reader nears the top of loaded history); Inertia handles
     * the cursor request, prepend, and scroll positioning.
     */
    function loadOlderMessages(): void {
        if (loadingOlder.value || !hasOlder()) {
            return;
        }

        loadingOlder.value = true;
        infiniteScrollRef.value?.fetchNext();
    }

    watch(options.loadedCount, () => {
        loadingOlder.value = false;
    });

    return {
        infiniteScrollRef,
        loadingOlder,
        hasOlder,
        isLoadingOlder,
        loadOlderMessages,
    };
}
