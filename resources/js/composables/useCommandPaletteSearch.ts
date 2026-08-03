import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { suggest as suggestMessages } from '@/actions/App/Http/Controllers/Channels/SearchController';
import { useSidebar } from '@/components/ui/sidebar';
import { useIsMobile } from '@/composables/useIsMobile';
import { useMessageSearch } from '@/composables/useMessageSearch';
import { urlForDestination } from '@/composables/useNavPanel';
import { pinUrl } from '@/lib/pinUrl';
import { urlForSearchParams } from '@/lib/searchPanel';
import { filtersToParams, parseSearchQuery } from '@/lib/searchTokens';
import type { SearchFilters, SearchParams } from '@/lib/searchTokens';
import type { MessageSearchResult } from '@/types';
import type { Channel } from '@/types/channels';
import type { PersonRef } from '@/types/people';

/** What a token in the palette's query resolves against. */
export type CommandPaletteLookup = {
    teamSlug: () => string;
    members: () => PersonRef[];
    channels: () => Channel[];
};

export type CommandPaletteSearch = {
    /** The raw input, which the ranked groups read as well. */
    query: Ref<string>;
    /** The query with its surrounding noise gone: `#gen` and `gen` are one. */
    trimmedQuery: ComputedRef<string>;
    /** The structured filter model the `from:`/`in:`/date tokens parse into. */
    parsedFilters: ComputedRef<SearchFilters>;
    /** The residual text the results highlight and the groups keep ranking on. */
    searchText: ComputedRef<string>;
    messageResults: Ref<MessageSearchResult[]>;
    isSearchingMessages: Ref<boolean>;
    /** Drop the query and the matches, so the palette reopens blank. */
    clear: () => void;
    /** Hand the criteria over to the Search destination, and reveal the dock. */
    handOffToSearch: () => void;
};

/**
 * The palette's query and everything it drives: the structured filters it parses
 * into, the debounced message search it feeds, and the hand-off that carries
 * both over to the Search panel.
 *
 * Kept apart from the palette's markup because the query is the one piece of it
 * that reaches the server, and it does so on its own schedule — a debounce, a
 * cancellation, and a client-side write of the criteria onto the current route.
 */
export function useCommandPaletteSearch(
    lookup: CommandPaletteLookup,
): CommandPaletteSearch {
    const page = usePage();
    const isMobile = useIsMobile();

    /**
     * The dock, which the hand-off to the Search panel has to reveal: the drawer
     * on a phone, the collapsed column on a desktop.
     */
    const {
        open: dockOpen,
        setOpen: setDockOpen,
        setOpenMobile,
    } = useSidebar();

    /**
     * Our own query drives the fuzzy channel ranking. The Command's internal
     * filter state is deliberately left untouched (empty) so it never hides a
     * subsequence match that `rankChannels` chose to surface — the ranked list is
     * the single source of truth for what shows and in what order.
     */
    const query = ref('');

    const trimmedQuery = computed(() => query.value.trim().replace(/^#+/, ''));

    /**
     * Parse the raw input into the shared filter model so `from:` / `in:` /
     * `before:` / `after:` tokens drive the same structured search the page uses
     * — the palette just has no visible chip bar. The residual text is the query
     * the results highlight and the channel/people groups keep ranking on.
     */
    const parsedFilters = computed(() =>
        parseSearchQuery(query.value, {
            members: lookup.members(),
            channels: lookup.channels(),
        }),
    );

    const searchText = computed(() => parsedFilters.value.text);

    /**
     * Live message search: a debounced JSON call to the suggest endpoint, with
     * the in-flight request cancelled whenever the query changes so late
     * responses never overwrite newer ones.
     */
    const {
        results: messageResults,
        isSearching: isSearchingMessages,
        search: searchMessages,
        reset: resetMessages,
    } = useMessageSearch(
        (params) =>
            suggestMessages(lookup.teamSlug(), {
                query: JSON.parse(params) as SearchParams,
            }).url,
    );

    // Re-search whenever the structured filters change (a token edit counts, not
    // just text), keyed on the serialized params so an identical filter set is
    // not re-fetched. A query with no residual text can never match, so it
    // clears.
    watch(
        parsedFilters,
        (filters) => {
            // A query with no residual text can never match, so clear rather than
            // send an empty request (keeps the URL-builder off an empty params key).
            if (filters.text === '') {
                resetMessages();

                return;
            }

            searchMessages(JSON.stringify(filtersToParams(filters)));
        },
        { deep: true },
    );

    function clear(): void {
        query.value = '';
        resetMessages();
    }

    /**
     * Hand the palette's query over to the Search destination, which is where the
     * full result set lives now that search is a dock panel.
     *
     * The hand-off is a client-side write of the criteria onto the current route:
     * the dock adopts `?nav=search` from the URL, and the panel pulls the matches
     * itself. Either way the dock has to be showing first — the palette can be
     * reached with the drawer shut on a phone and with the column collapsed on a
     * desktop — or the hand-off lands somewhere nobody can see.
     */
    function handOffToSearch(): void {
        pinUrl(
            urlForSearchParams(
                urlForDestination(page.url, 'search'),
                filtersToParams(parsedFilters.value),
            ),
        );

        if (isMobile.value) {
            setOpenMobile(true);
        } else if (!dockOpen.value) {
            setDockOpen(true);
        }
    }

    return {
        query,
        trimmedQuery,
        parsedFilters,
        searchText,
        messageResults,
        isSearchingMessages,
        clear,
        handOffToSearch,
    };
}
