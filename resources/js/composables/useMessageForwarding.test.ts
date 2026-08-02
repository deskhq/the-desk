import { beforeEach, describe, expect, it, vi } from 'vitest';

const { post, destroy, toastError, toastSuccess } = vi.hoisted(() => ({
    post: vi.fn(),
    destroy: vi.fn(),
    toastError: vi.fn(),
    toastSuccess: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { post, patch: vi.fn(), delete: destroy },
}));
vi.mock('@/composables/useToast', () => {
    const toast = {
        error: toastError,
        success: toastSuccess,
        warning: vi.fn(),
        progress: vi.fn(),
    };

    return { useToast: () => toast };
});

import { streams } from '@/composables/messageWrites.harness';
import { channel, me, message } from '@/composables/useMessageActions.harness';
import { useMessageForwarding } from '@/composables/useMessageForwarding';
import type { Message } from '@/types';
import type { ForwardTarget } from '@/types/forward';

/** The open channel, so the copy renders optimistically in the timeline. */
const HERE: ForwardTarget = {
    kind: 'channel',
    id: channel.id,
    name: channel.name,
};

/** Somewhere else, so the copy is only reported by a toast. */
const ELSEWHERE: ForwardTarget = {
    kind: 'channel',
    id: 'chan-2',
    name: 'random',
};

function harness() {
    const source = message();
    const { mainStream, unmount } = streams([source]);
    const appended: Message[] = [];

    const forwarding = useMessageForwarding({
        teamSlug: () => 'acme',
        channel: () => channel,
        currentUser: () => me,
        mainStream,
        appendPendingMain: (row) => {
            appended.push(row);
            mainStream.addPending(row);
        },
    });

    return { forwarding, source, mainStream, appended, unmount };
}

/** The client uuid the forward minted, as it posted it. */
function postedUuid(): string {
    return post.mock.calls[0][1].client_uuid as string;
}

describe('useMessageForwarding', () => {
    beforeEach(() => {
        post.mockClear();
        destroy.mockClear();
        toastError.mockClear();
        toastSuccess.mockClear();
    });

    it('renders the copy in the timeline when it lands in the open channel', () => {
        const { forwarding, source, mainStream, appended, unmount } = harness();

        forwarding.forwardMessage(source, { target: HERE, note: 'look' });

        expect(appended).toHaveLength(1);
        expect(mainStream.pendingUuids.value).toEqual([postedUuid()]);

        unmount();
    });

    it('takes the optimistic copy back out when the forward is refused', () => {
        const { forwarding, source, mainStream, unmount } = harness();

        forwarding.forwardMessage(source, { target: HERE, note: 'look' });
        post.mock.calls[0][2].onError({});

        expect(mainStream.pendingUuids.value).toEqual([]);
        expect(toastError).toHaveBeenCalledWith(
            'Failed to forward the message',
        );

        unmount();
    });

    it('renders nothing locally for a forward sent somewhere else', () => {
        const { forwarding, source, mainStream, appended, unmount } = harness();

        forwarding.forwardMessage(source, { target: ELSEWHERE, note: 'look' });

        expect(appended).toEqual([]);
        expect(mainStream.pendingUuids.value).toEqual([]);

        unmount();
    });

    it('says a forward sent elsewhere failed, with no row to take back', () => {
        const { forwarding, source, mainStream, unmount } = harness();

        forwarding.forwardMessage(source, { target: ELSEWHERE, note: 'look' });
        post.mock.calls[0][2].onError({});

        expect(mainStream.pendingUuids.value).toEqual([]);
        expect(toastError).toHaveBeenCalledWith(
            'Failed to forward the message',
        );

        unmount();
    });

    it('confirms a forward sent elsewhere, with an Undo onto the copy', () => {
        const { forwarding, source, unmount } = harness();

        forwarding.forwardMessage(source, { target: ELSEWHERE, note: 'look' });
        post.mock.calls[0][2].onSuccess({
            flash: { forwarded: { messageId: 'm9', channelSlug: 'random' } },
        });

        expect(toastSuccess).toHaveBeenCalledWith(
            'Message forwarded to #random',
            expect.objectContaining({
                action: expect.objectContaining({ label: 'Undo' }),
            }),
        );

        toastSuccess.mock.calls[0][1].action.run();

        expect(destroy).toHaveBeenCalledWith(
            '/t/acme/c/random/messages/m9',
            expect.objectContaining({ preserveUrl: true }),
        );

        unmount();
    });

    it('stays silent for a forward that landed in the channel on screen', () => {
        const { forwarding, source, unmount } = harness();

        forwarding.forwardMessage(source, { target: HERE, note: 'look' });
        post.mock.calls[0][2].onSuccess({ flash: {} });

        expect(toastSuccess).not.toHaveBeenCalled();

        unmount();
    });
});
