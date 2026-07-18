import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useMessageSearch } from '@/composables/useMessageSearch';
import type { MessageSearchResult } from '@/types';

function makeResult(id: string): MessageSearchResult {
    return {
        message: { id },
        channelName: 'general',
        channelSlug: 'general',
    } as unknown as MessageSearchResult;
}

function jsonResponse(results: MessageSearchResult[], ok = true): Response {
    return { ok, json: async () => ({ results }) } as unknown as Response;
}

const ids = (results: MessageSearchResult[]): string[] =>
    results.map((result) => result.message.id);

/** Drain the microtask queue so an already-resolved fetch chain settles. */
const flush = async (): Promise<void> => {
    await vi.advanceTimersByTimeAsync(0);
};

describe('useMessageSearch', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('debounces the request, then populates results', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValue(jsonResponse([makeResult('hi')]));
        vi.stubGlobal('fetch', fetchMock);

        const search = useMessageSearch((term) => `/s?q=${term}`, 200);
        search.search('hi');

        expect(search.isSearching.value).toBe(true);
        expect(fetchMock).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(200);

        expect(fetchMock).toHaveBeenCalledWith(
            '/s?q=hi',
            expect.objectContaining({
                headers: { Accept: 'application/json' },
            }),
        );
        expect(ids(search.results.value)).toEqual(['hi']);
        expect(search.isSearching.value).toBe(false);
    });

    it('collapses rapid keystrokes into a single request', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValue(jsonResponse([makeResult('abc')]));
        vi.stubGlobal('fetch', fetchMock);

        const search = useMessageSearch((term) => `/s?q=${term}`, 200);
        search.search('a');
        await vi.advanceTimersByTimeAsync(100);
        search.search('ab');
        await vi.advanceTimersByTimeAsync(100);
        search.search('abc');
        await vi.advanceTimersByTimeAsync(200);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledWith('/s?q=abc', expect.anything());
    });

    it('clears results without hitting the network for an empty term', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        const search = useMessageSearch((term) => `/s?q=${term}`, 200);
        search.search('');
        await vi.advanceTimersByTimeAsync(200);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(search.results.value).toEqual([]);
        expect(search.isSearching.value).toBe(false);
    });

    it('clears results when the response is not ok', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse([makeResult('a')]))
            .mockResolvedValueOnce(jsonResponse([], false));
        vi.stubGlobal('fetch', fetchMock);

        const search = useMessageSearch((term) => `/s?q=${term}`, 200);
        search.search('a');
        await vi.advanceTimersByTimeAsync(200);
        expect(ids(search.results.value)).toEqual(['a']);

        search.search('b');
        await vi.advanceTimersByTimeAsync(200);
        expect(search.results.value).toEqual([]);
        expect(search.isSearching.value).toBe(false);
    });

    it('clears results when the request throws', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse([makeResult('a')]))
            .mockRejectedValueOnce(new Error('network'));
        vi.stubGlobal('fetch', fetchMock);

        const search = useMessageSearch((term) => `/s?q=${term}`, 200);
        search.search('a');
        await vi.advanceTimersByTimeAsync(200);
        expect(ids(search.results.value)).toEqual(['a']);

        search.search('b');
        await vi.advanceTimersByTimeAsync(200);
        expect(search.results.value).toEqual([]);
    });

    it('ignores a stale in-flight response when a newer query supersedes it', async () => {
        const resolvers: Record<string, (value: Response) => void> = {};
        const fetchMock = vi.fn(
            (url: string) =>
                new Promise<Response>((resolve) => {
                    resolvers[url] = resolve;
                }),
        );
        vi.stubGlobal('fetch', fetchMock);

        const search = useMessageSearch((term) => `/s?q=${term}`, 200);

        search.search('old');
        await vi.advanceTimersByTimeAsync(200); // 'old' request now in flight
        search.search('new');
        await vi.advanceTimersByTimeAsync(200); // 'old' aborted, 'new' in flight

        // Resolve the stale request first — it must not land.
        resolvers['/s?q=old'](jsonResponse([makeResult('old')]));
        await flush();
        expect(search.results.value).toEqual([]);

        resolvers['/s?q=new'](jsonResponse([makeResult('new')]));
        await flush();
        expect(ids(search.results.value)).toEqual(['new']);
        expect(search.isSearching.value).toBe(false);
    });

    it('reset clears results and cancels a pending request', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValue(jsonResponse([makeResult('a')]));
        vi.stubGlobal('fetch', fetchMock);

        const search = useMessageSearch((term) => `/s?q=${term}`, 200);
        search.search('a');
        await vi.advanceTimersByTimeAsync(200);
        expect(ids(search.results.value)).toEqual(['a']);

        // A pending (debouncing) request is dropped by reset without firing.
        search.search('b');
        search.reset();
        await vi.advanceTimersByTimeAsync(200);

        expect(fetchMock).toHaveBeenCalledTimes(1); // only the first 'a' request
        expect(search.results.value).toEqual([]);
        expect(search.isSearching.value).toBe(false);
    });
});
