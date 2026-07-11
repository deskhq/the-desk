import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import type { Ref } from 'vue';

const { post, get } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ router: { post, get } }));

import { useMessageStream } from '@/composables/useMessageStream';
import {
    THREAD_READ_DEBOUNCE_MS,
    useThreadPanel,
} from '@/composables/useThreadPanel';
import type { ThreadPanel } from '@/composables/useThreadPanel';
import type { Message, MessagePage, Thread } from '@/types';

/** A message carrying just the fields the panel reads. */
function message(overrides: Partial<Message> = {}): Message {
    return {
        id: 'root-1',
        clientUuid: 'uuid-root',
        body: 'the root',
        user: { id: 'peer', name: 'Peer' },
        createdAt: '2024-01-01T00:00:00.000Z',
        editedAt: null,
        isDeleted: false,
        mentions: [],
        linkPreviews: [],
        reactions: [],
        replyTo: null,
        forwardedFrom: null,
        threadRootId: null,
        sentToChannel: false,
        threadReplyCount: 0,
        threadLastReplyAt: null,
        threadParticipants: [],
        threadFollowed: false,
        threadUnread: false,
        ...overrides,
    };
}

const emptyPage: MessagePage = {
    data: [],
    next_cursor: null,
    prev_cursor: null,
};

function harness(
    setup: {
        channelId?: Ref<string>;
        thread?: Ref<Thread | null | undefined>;
        threadReplies?: Ref<MessagePage>;
    } = {},
) {
    const scope = effectScope();
    const channelId = setup.channelId ?? ref('chan-1');
    const thread = setup.thread ?? ref<Thread | null | undefined>(null);
    const threadReplies = setup.threadReplies ?? ref(emptyPage);
    const mainStream = useMessageStream(ref<Message[]>([message()]));

    let panel!: ThreadPanel;

    scope.run(() => {
        panel = useThreadPanel({
            teamSlug: () => 'acme',
            channelSlug: () => 'general',
            channelId: () => channelId.value,
            mainStream,
            thread: () => thread.value,
            threadReplies: () => threadReplies.value,
        });
    });

    return {
        panel,
        mainStream,
        channelId,
        thread,
        threadReplies,
        unmount: () => scope.stop(),
    };
}

/** The options object of the nth recorded `router.get`. */
function getOptions(call = 0): {
    only?: string[];
    reset?: string[];
    onFinish?: () => void;
} {
    return get.mock.calls[call][2];
}

describe('useThreadPanel', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.stubGlobal('document', { hasFocus: () => true });
        post.mockClear();
        get.mockClear();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('opens a thread: sets state, clears the root dot, and navigates', () => {
        const h = harness();

        h.panel.openThread('root-1');

        expect(h.panel.activeThreadRootId.value).toBe('root-1');
        expect(h.panel.threadLoading.value).toBe(true);
        // The root's dot clears immediately in the main timeline.
        expect(
            h.mainStream.displayMessages.value.find((m) => m.id === 'root-1')
                ?.threadUnread,
        ).toBe(false);
        expect(getOptions().only).toEqual(['thread', 'threadReplies']);
    });

    it('ignores reopening the already-open thread', () => {
        const h = harness();

        h.panel.openThread('root-1');
        get.mockClear();
        h.panel.openThread('root-1');

        expect(get).not.toHaveBeenCalled();
    });

    it('stops loading and schedules a mark-read once the thread finishes loading', () => {
        const h = harness();

        h.panel.openThread('root-1');
        getOptions().onFinish?.();

        expect(h.panel.threadLoading.value).toBe(false);

        // The debounced, focus-gated thread mark-read fires after its delay.
        vi.advanceTimersByTime(THREAD_READ_DEBOUNCE_MS);
        expect(post).toHaveBeenCalledOnce();
        expect(post.mock.calls[0][2].only).toEqual(['channels']);
    });

    it('does not mark a thread read while the tab is blurred', () => {
        vi.stubGlobal('document', { hasFocus: () => false });
        const h = harness();

        h.panel.openThread('root-1');
        getOptions().onFinish?.();
        vi.advanceTimersByTime(THREAD_READ_DEBOUNCE_MS);

        expect(post).not.toHaveBeenCalled();
    });

    it('closes an open thread: resets state and navigates back', () => {
        const h = harness();

        h.panel.openThread('root-1');
        get.mockClear();
        h.panel.closeThread();

        expect(h.panel.activeThreadRootId.value).toBeNull();
        expect(h.panel.threadData.value).toBeNull();
        expect(h.panel.threadLoading.value).toBe(false);
        expect(get).toHaveBeenCalledOnce();
    });

    it('does nothing when closing an already-closed panel', () => {
        const h = harness();

        h.panel.closeThread();

        expect(get).not.toHaveBeenCalled();
    });

    it('resets the panel on a channel switch', async () => {
        const channelId = ref('chan-1');
        const h = harness({ channelId });

        h.panel.openThread('root-1');
        expect(h.panel.activeThreadRootId.value).toBe('root-1');

        channelId.value = 'chan-2';
        await nextTick();

        expect(h.panel.activeThreadRootId.value).toBeNull();
        expect(h.panel.threadData.value).toBeNull();
    });

    it('adopts a deep-linked thread from the initial load', () => {
        const thread = ref<Thread | null | undefined>({
            root: message({ id: 'deep-root' }),
        });
        const h = harness({ thread });

        h.panel.adoptDeepLinkedThread();

        expect(h.panel.activeThreadRootId.value).toBe('deep-root');
        expect(h.panel.threadData.value?.root.id).toBe('deep-root');
    });

    it('adopts nothing when there is no deep-linked thread', () => {
        const h = harness();

        h.panel.adoptDeepLinkedThread();

        expect(h.panel.activeThreadRootId.value).toBeNull();
    });

    it('copies in a late-arriving thread prop for the open root, ending the load', async () => {
        const thread = ref<Thread | null | undefined>(null);
        const h = harness({ thread });

        h.panel.openThread('root-1');
        expect(h.panel.threadLoading.value).toBe(true);

        // The requested thread prop arrives for the root we're opening.
        thread.value = { root: message({ id: 'root-1' }) };
        await nextTick();

        expect(h.panel.threadData.value?.root.id).toBe('root-1');
        expect(h.panel.threadLoading.value).toBe(false);
    });

    it('ignores a thread prop for a root other than the open one', async () => {
        const thread = ref<Thread | null | undefined>(null);
        const h = harness({ thread });

        h.panel.openThread('root-1');
        thread.value = { root: message({ id: 'some-other-root' }) };
        await nextTick();

        expect(h.panel.threadData.value).toBeNull();
        expect(h.panel.threadLoading.value).toBe(true);
    });

    it('builds the rendered list with the root first, then replies by time', () => {
        const thread = ref<Thread | null | undefined>({
            root: message({
                id: 'root-1',
                clientUuid: 'uuid-root',
                createdAt: '2024-01-01T00:00:00.000Z',
            }),
        });
        // Replies arrive newest-first from the server.
        const threadReplies = ref<MessagePage>({
            data: [
                message({
                    id: 'r2',
                    clientUuid: 'uuid-r2',
                    createdAt: '2024-01-01T00:02:00.000Z',
                }),
                message({
                    id: 'r1',
                    clientUuid: 'uuid-r1',
                    createdAt: '2024-01-01T00:01:00.000Z',
                }),
            ],
            next_cursor: null,
            prev_cursor: null,
        });
        const h = harness({ thread, threadReplies });

        h.panel.adoptDeepLinkedThread();

        expect(h.panel.threadMessages.value.map((m) => m.id)).toEqual([
            'root-1',
            'r1',
            'r2',
        ]);
    });
});
