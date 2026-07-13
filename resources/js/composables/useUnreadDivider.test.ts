import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import type { Ref } from 'vue';
import { useUnreadDivider } from '@/composables/useUnreadDivider';
import type { UnreadDivider } from '@/composables/useUnreadDivider';
import type { Message } from '@/types';

type IntersectionCallback = (entries: { isIntersecting: boolean }[]) => void;

const observe = vi.fn();
const disconnect = vi.fn();
let capturedCallback: IntersectionCallback | null = null;

class FakeIntersectionObserver {
    constructor(callback: IntersectionCallback) {
        capturedCallback = callback;
    }

    observe = observe;
    disconnect = disconnect;
}

/** A message with just the fields the divider decision reads. */
function message(id: string, userId: string): Message {
    return { id, user: { id: userId }, type: 'standard' } as unknown as Message;
}

function withScope(options: {
    channelId: Ref<string>;
    messages: () => Message[];
    lastReadMessageId: Ref<string | null>;
    currentUserId?: string;
}) {
    const scope = effectScope();
    let divider!: UnreadDivider;

    scope.run(() => {
        divider = useUnreadDivider({
            channelId: () => options.channelId.value,
            scrollContainer: ref({} as HTMLElement),
            messages: options.messages,
            lastReadMessageId: () => options.lastReadMessageId.value,
            currentUserId: () => options.currentUserId ?? 'me',
        });
    });

    return { divider, unmount: () => scope.stop() };
}

describe('useUnreadDivider', () => {
    beforeEach(() => {
        observe.mockClear();
        disconnect.mockClear();
        capturedCallback = null;
        vi.stubGlobal('IntersectionObserver', FakeIntersectionObserver);
        vi.stubGlobal('document', {
            getElementById: (id: string) =>
                id === 'unread-divider' ? ({} as Element) : null,
        });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('freezes the divider at the first unread peer message and shows the pill', async () => {
        const { divider } = withScope({
            channelId: ref('c1'),
            // m1 is read; m2 is the viewer's own; m3 is the first unread peer.
            messages: () => [
                message('m1', 'peer'),
                message('m2', 'me'),
                message('m3', 'peer'),
            ],
            lastReadMessageId: ref('m1'),
        });

        expect(divider.unreadDividerId.value).toBe('m3');
        expect(divider.showJumpToUnread.value).toBe(true);

        // The observer attaches after the divider element renders.
        await nextTick();
        expect(observe).toHaveBeenCalledOnce();
    });

    it('hides the pill once the divider scrolls into view', async () => {
        const { divider } = withScope({
            channelId: ref('c1'),
            messages: () => [message('m1', 'peer')],
            lastReadMessageId: ref(null),
        });

        await nextTick();
        capturedCallback?.([{ isIntersecting: true }]);

        expect(divider.showJumpToUnread.value).toBe(false);
    });

    it('marks no boundary when every message is already read', () => {
        const { divider } = withScope({
            channelId: ref('c1'),
            messages: () => [message('m1', 'peer'), message('m2', 'peer')],
            lastReadMessageId: ref('m2'),
        });

        expect(divider.unreadDividerId.value).toBeNull();
        expect(divider.showJumpToUnread.value).toBe(false);
        // No boundary, so no observer is attached.
        expect(observe).not.toHaveBeenCalled();
    });

    it('refreezes the boundary for the new channel on a switch', async () => {
        const channelId = ref('c1');
        const lastReadMessageId = ref<string | null>('m2');
        let messages: Message[] = [
            message('m1', 'peer'),
            message('m2', 'peer'),
        ];

        const { divider } = withScope({
            channelId,
            messages: () => messages,
            lastReadMessageId,
        });

        expect(divider.unreadDividerId.value).toBeNull();

        // Switch to a channel with an unread peer message below the pointer.
        messages = [message('n1', 'peer'), message('n2', 'peer')];
        lastReadMessageId.value = 'n1';
        channelId.value = 'c2';
        await nextTick();

        expect(divider.unreadDividerId.value).toBe('n2');
    });

    it('disconnects the observer on teardown', async () => {
        const { unmount } = withScope({
            channelId: ref('c1'),
            messages: () => [message('m1', 'peer')],
            lastReadMessageId: ref(null),
        });

        await nextTick();
        disconnect.mockClear();
        unmount();

        expect(disconnect).toHaveBeenCalledOnce();
    });

    it('computes the boundary without touching the DOM during SSR', async () => {
        // The server has no DOM; the immediate watcher must still compute the
        // pure boundary without scheduling any document/observer access (which
        // would throw and crash the SSR render).
        const rejections: unknown[] = [];
        const onRejection = (reason: unknown): void => {
            rejections.push(reason);
        };
        process.on('unhandledRejection', onRejection);

        vi.stubGlobal('document', undefined);
        vi.stubGlobal('IntersectionObserver', undefined);

        const { divider } = withScope({
            channelId: ref('c1'),
            messages: () => [message('m1', 'peer')],
            lastReadMessageId: ref(null),
        });

        // The pure decision still runs on the server.
        expect(divider.unreadDividerId.value).toBe('m1');

        // Let any scheduled microtasks surface an unhandled rejection.
        await new Promise((resolve) => setTimeout(resolve, 0));
        process.off('unhandledRejection', onRejection);

        expect(rejections).toEqual([]);
        expect(observe).not.toHaveBeenCalled();
    });
});
