import { ref, watch } from 'vue';
import type { Ref } from 'vue';

/** The slice of Inertia's `<InfiniteScroll>` the timeline drives manually. */
interface InfiniteScrollHandle {
    fetchNext: () => void;
    hasNext: () => boolean;
}

export interface ChannelHistoryOptions {
    /** The channel on show, so a switch can retire an in-flight page. */
    channelId: () => string;
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
 * grows (older rows have landed) or the channel changes under it.
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

    // The channel id is watched alongside the count because the pane component is
    // reused across a switch: a page still in flight when the reader leaves would
    // otherwise keep the flag raised in the new channel whenever the two first
    // pages happen to hold the same number of messages (#1023).
    watch([options.loadedCount, options.channelId], () => {
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
