import { beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, ref } from 'vue';

const { post, toastError } = vi.hoisted(() => ({
    post: vi.fn(),
    toastError: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { post, patch: vi.fn(), delete: vi.fn() },
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

import { streams } from '@/composables/messageWrites.harness';
import { channel, me } from '@/composables/useMessageActions.harness';
import { useMessageSends } from '@/composables/useMessageSends';
import { createOutbox } from '@/lib/outbox';
import type { Message } from '@/types';

function harness() {
    const scope = effectScope();
    const { mainStream, unmount } = streams([]);
    const onSendFailure = vi.fn();
    const onRejected = vi.fn();
    const replyTarget = ref<Message | null>(null);

    const outbox = createOutbox();

    let sends!: ReturnType<typeof useMessageSends>;

    scope.run(() => {
        sends = useMessageSends({
            teamSlug: () => 'acme',
            channel: () => channel,
            currentUser: () => me,
            mainStream,
            replyTarget,
            scrollToBottom: vi.fn(),
            cancelDraft: vi.fn(),
            clearDraft: vi.fn(),
            cancelReply: vi.fn(),
            isOnline: () => true,
            outbox,
            onSendFailure,
        });
    });

    return {
        sends,
        mainStream,
        onSendFailure,
        onRejected,
        unmount: () => {
            scope.stop();
            unmount();
        },
    };
}

describe('useMessageSends', () => {
    beforeEach(() => {
        post.mockClear();
        toastError.mockClear();
    });

    it('renders the message optimistically before the server has taken it', () => {
        const { sends, mainStream, unmount } = harness();

        sends.send('hello', []);

        expect(mainStream.pendingUuids.value).toHaveLength(1);

        unmount();
    });

    it('takes the optimistic row back out when the send is refused', () => {
        const { sends, mainStream, onSendFailure, onRejected, unmount } =
            harness();

        sends.send('hello', [], [], { onRejected });
        post.mock.calls[0][2].onError({});

        expect(mainStream.pendingUuids.value).toEqual([]);
        expect(toastError).toHaveBeenCalledWith(
            'Your message failed to send. Please try again.',
        );
        // The staged attachments come back with it, so the send is retryable
        // without re-picking the files.
        expect(onRejected).toHaveBeenCalled();
        expect(onSendFailure).toHaveBeenCalledWith(
            'Your message failed to send. Please try again.',
        );

        unmount();
    });
});
