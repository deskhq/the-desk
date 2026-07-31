import { beforeEach, describe, expect, it, vi } from 'vitest';

const { post, patch, destroy, toastError } = vi.hoisted(() => ({
    post: vi.fn(),
    patch: vi.fn(),
    destroy: vi.fn(),
    toastError: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { post, patch, delete: destroy },
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
import { useMessageEdits } from '@/composables/useMessageEdits';
import type { Message } from '@/types';

function harness(overrides: Partial<Message> = {}) {
    const row = message(overrides);
    const { mainStream, threadStream, unmount } = streams([row]);

    const edits = useMessageEdits({
        teamSlug: () => 'acme',
        channel: () => channel,
        currentUser: () => me,
        mainStream,
        threadStream,
    });

    return { edits, row, mainStream, threadStream, unmount };
}

describe('useMessageEdits', () => {
    beforeEach(() => {
        post.mockClear();
        patch.mockClear();
        destroy.mockClear();
        toastError.mockClear();
    });

    it('puts the original body back in both streams when an edit is refused', () => {
        const { edits, row, mainStream, threadStream, unmount } = harness();

        edits.editMessage(row, 'a better sentence');

        expect(shown(mainStream, row.id).body).toBe('a better sentence');
        expect(shown(threadStream, row.id).body).toBe('a better sentence');

        patch.mock.calls[0][2].onError({});

        expect(shown(mainStream, row.id).body).toBe('hello');
        expect(shown(threadStream, row.id).body).toBe('hello');
        expect(toastError).toHaveBeenCalledWith('Your edit failed to save');

        unmount();
    });

    it('takes the tombstone back out of both streams when a delete is refused', () => {
        const { edits, row, mainStream, threadStream, unmount } = harness();

        edits.deleteMessage(row);

        expect(shown(mainStream, row.id).isDeleted).toBe(true);
        expect(shown(threadStream, row.id).isDeleted).toBe(true);

        destroy.mock.calls[0][1].onError({});

        expect(shown(mainStream, row.id).isDeleted).toBe(false);
        expect(shown(mainStream, row.id).body).toBe('hello');
        expect(shown(threadStream, row.id).isDeleted).toBe(false);
        expect(toastError).toHaveBeenCalledWith('Failed to delete the message');

        unmount();
    });

    it('takes the reaction back out of both streams when the write is refused', () => {
        const { edits, row, mainStream, threadStream, unmount } = harness();

        edits.reactToMessage(row, '🎉');

        expect(shown(mainStream, row.id).reactions).toHaveLength(1);
        expect(shown(threadStream, row.id).reactions).toHaveLength(1);

        post.mock.calls[0][2].onError({});

        expect(shown(mainStream, row.id).reactions).toEqual([]);
        expect(shown(threadStream, row.id).reactions).toEqual([]);
        expect(toastError).toHaveBeenCalledWith(
            'Failed to update the reaction',
        );

        unmount();
    });

    it('restores an edit made over an earlier one to that earlier one', () => {
        // The snapshot is whatever the streams were already carrying, not the
        // server's row: editing twice and having the second refused must land
        // back on the first edit rather than on the original body.
        const { edits, row, mainStream, unmount } = harness();

        edits.editMessage(row, 'first edit');
        patch.mock.calls[0][2].onSuccess?.({});

        edits.editMessage(shown(mainStream, row.id), 'second edit');
        patch.mock.calls[1][2].onError({});

        expect(shown(mainStream, row.id).body).toBe('first edit');

        unmount();
    });
});
