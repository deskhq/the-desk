import { describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import { useChannelHistory } from '@/composables/useChannelHistory';
import type { ChannelHistory } from '@/composables/useChannelHistory';

function withScope(options: {
    channelId: { value: string };
    loadedCount: { value: number };
    hasNext?: () => boolean;
}) {
    const fetchNext = vi.fn();
    const scope = effectScope();
    let history!: ChannelHistory;

    scope.run(() => {
        history = useChannelHistory({
            channelId: () => options.channelId.value,
            loadedCount: () => options.loadedCount.value,
        });
    });

    history.infiniteScrollRef.value = {
        fetchNext,
        hasNext: options.hasNext ?? ((): boolean => true),
    };

    return { history, fetchNext, unmount: () => scope.stop() };
}

describe('useChannelHistory', () => {
    it('fetches the next older page and marks it in flight', () => {
        const { history, fetchNext } = withScope({
            channelId: ref('c1'),
            loadedCount: ref(20),
        });

        expect(history.isLoadingOlder()).toBe(false);
        history.loadOlderMessages();

        expect(fetchNext).toHaveBeenCalledOnce();
        expect(history.isLoadingOlder()).toBe(true);
    });

    it('does not stack a second request while one is in flight', () => {
        const { history, fetchNext } = withScope({
            channelId: ref('c1'),
            loadedCount: ref(20),
        });

        history.loadOlderMessages();
        history.loadOlderMessages();

        expect(fetchNext).toHaveBeenCalledOnce();
    });

    it('does not fetch when there is no older history left', () => {
        const { history, fetchNext } = withScope({
            channelId: ref('c1'),
            loadedCount: ref(20),
            hasNext: () => false,
        });

        expect(history.hasOlder()).toBe(false);
        history.loadOlderMessages();

        expect(fetchNext).not.toHaveBeenCalled();
        expect(history.isLoadingOlder()).toBe(false);
    });

    it('clears the in-flight flag once the older page lands', async () => {
        const loadedCount = ref(20);
        const { history } = withScope({ channelId: ref('c1'), loadedCount });

        history.loadOlderMessages();
        loadedCount.value = 40;
        await nextTick();

        expect(history.isLoadingOlder()).toBe(false);
    });

    it('clears the in-flight flag on a channel switch onto a page of the same length', async () => {
        const channelId = ref('c1');
        const loadedCount = ref(20);
        const { history, fetchNext } = withScope({ channelId, loadedCount });

        history.loadOlderMessages();
        expect(history.isLoadingOlder()).toBe(true);

        // The reader switches away before the page lands, and the new channel's
        // first page happens to hold exactly as many messages as the old one.
        channelId.value = 'c2';
        await nextTick();

        expect(history.isLoadingOlder()).toBe(false);

        // Older history is fetchable again in the new channel.
        history.loadOlderMessages();
        expect(fetchNext).toHaveBeenCalledTimes(2);
    });
});
