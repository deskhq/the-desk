import { beforeEach, describe, expect, it, vi } from 'vitest';

const { post, destroy, toastError } = vi.hoisted(() => ({
    post: vi.fn(),
    destroy: vi.fn(),
    toastError: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { post, patch: vi.fn(), delete: destroy },
}));
vi.mock('@/composables/useToast', () => {
    const toast = {
        error: toastError,
        success: vi.fn(),
        warning: vi.fn(),
        progress: vi.fn(),
    };

    return { useToast: () => toast };
});

import { shown, streams } from '@/composables/messageWrites.harness';
import { channel, me, message } from '@/composables/useMessageActions.harness';
import { useMessagePins } from '@/composables/useMessagePins';
import type { MessagePin } from '@/types';

const EXISTING_PIN: MessagePin = {
    pinnedBy: { id: 'peer', name: 'Peer', avatar: null },
    pinnedAt: '2024-01-01T12:00:00.000Z',
};

function harness(pin: MessagePin | null = null) {
    const row = message({ pin });
    const { mainStream, threadStream, unmount } = streams([row]);

    const pins = useMessagePins({
        teamSlug: () => 'acme',
        channel: () => channel,
        currentUser: () => me,
        mainStream,
        threadStream,
    });

    return { pins, row, mainStream, threadStream, unmount };
}

describe('useMessagePins', () => {
    beforeEach(() => {
        post.mockClear();
        destroy.mockClear();
        toastError.mockClear();
    });

    it('shows the pin in both streams before the server has agreed', () => {
        const { pins, row, mainStream, threadStream, unmount } = harness();

        pins.pinMessage(row);

        expect(shown(mainStream, row.id).pin?.pinnedBy).toEqual(me);
        expect(shown(threadStream, row.id).pin?.pinnedBy).toEqual(me);

        unmount();
    });

    it('takes the pin back out of both streams when the write is refused', () => {
        // The two streams are independent, so a rollback that reached only the
        // timeline would leave the same message pinned in the thread beside it.
        const { pins, row, mainStream, threadStream, unmount } = harness();

        pins.pinMessage(row);
        post.mock.calls[0][2].onError({});

        expect(shown(mainStream, row.id).pin).toBeNull();
        expect(shown(threadStream, row.id).pin).toBeNull();

        unmount();
    });

    it('says the pin failed', () => {
        const { pins, row, unmount } = harness();

        pins.pinMessage(row);
        post.mock.calls[0][2].onError({});

        expect(toastError).toHaveBeenCalledWith(
            'Failed to pin the message. Please try again.',
        );

        unmount();
    });

    it('prefers the cap message the server refused with', () => {
        const { pins, row, unmount } = harness();

        pins.pinMessage(row);
        post.mock.calls[0][2].onError({
            message: 'This channel already has 100 pins.',
        });

        expect(toastError).toHaveBeenCalledWith(
            'This channel already has 100 pins.',
        );

        unmount();
    });

    it('puts the existing pin back in both streams when an unpin is refused', () => {
        const { pins, row, mainStream, threadStream, unmount } =
            harness(EXISTING_PIN);

        pins.unpinMessage(row);

        expect(shown(mainStream, row.id).pin).toBeNull();
        expect(shown(threadStream, row.id).pin).toBeNull();

        destroy.mock.calls[0][1].onError({});

        expect(shown(mainStream, row.id).pin).toEqual(EXISTING_PIN);
        expect(shown(threadStream, row.id).pin).toEqual(EXISTING_PIN);
        expect(toastError).toHaveBeenCalledWith('Failed to unpin the message');

        unmount();
    });
});
