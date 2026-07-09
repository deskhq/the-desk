import { ref } from 'vue';
import type { Ref } from 'vue';
import type { MessageSearchResult } from '@/types';

export type UseMessageSearch = {
    results: Ref<MessageSearchResult[]>;
    isSearching: Ref<boolean>;
    search: (term: string) => void;
    reset: () => void;
};

/**
 * Debounced live message search for the quick switcher.
 *
 * `search()` debounces keystrokes into a single JSON request built by
 * `buildUrl`. The previous request — whether still waiting out the debounce or
 * already in flight — is always cancelled first, and an aborted request never
 * writes its (stale) response, so the visible results only ever reflect the
 * latest query. An empty term clears results without hitting the network.
 *
 * @param buildUrl   Maps a (trimmed, non-empty) term to the request URL.
 * @param debounceMs Idle delay before a term is sent.
 */
export function useMessageSearch(
    buildUrl: (term: string) => string,
    debounceMs = 250,
): UseMessageSearch {
    const results = ref<MessageSearchResult[]>([]);
    const isSearching = ref(false);
    let debounceHandle: ReturnType<typeof setTimeout> | null = null;
    let inFlight: AbortController | null = null;

    function cancelPending(): void {
        if (debounceHandle) {
            clearTimeout(debounceHandle);
            debounceHandle = null;
        }

        inFlight?.abort();
        inFlight = null;
    }

    async function fetchResults(term: string): Promise<void> {
        const controller = new AbortController();
        inFlight = controller;

        try {
            const response = await fetch(buildUrl(term), {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });

            if (!response.ok) {
                throw new Error('Message search failed');
            }

            const data = (await response.json()) as {
                results: MessageSearchResult[];
            };

            if (controller.signal.aborted) {
                return;
            }

            results.value = data.results;
        } catch {
            if (controller.signal.aborted) {
                return;
            }

            results.value = [];
        } finally {
            if (inFlight === controller) {
                isSearching.value = false;
                inFlight = null;
            }
        }
    }

    function search(term: string): void {
        cancelPending();

        if (term === '') {
            results.value = [];
            isSearching.value = false;

            return;
        }

        isSearching.value = true;
        debounceHandle = setTimeout(() => {
            void fetchResults(term);
        }, debounceMs);
    }

    function reset(): void {
        cancelPending();
        results.value = [];
        isSearching.value = false;
    }

    return { results, isSearching, search, reset };
}
