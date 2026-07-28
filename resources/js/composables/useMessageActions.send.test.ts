import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';

const { post, patch, destroy, toastError, toastSuccess } = vi.hoisted(() => ({
    post: vi.fn(),
    patch: vi.fn(),
    destroy: vi.fn(),
    toastError: vi.fn(),
    toastSuccess: vi.fn(),
}));

/** The workspace props the undo paths read; seeded per test that needs them. */
const inertiaPage = reactive<{ props: Record<string, unknown> }>({ props: {} });

vi.mock('@inertiajs/vue3', () => ({
    router: { post, patch, delete: destroy },
    // Read by `useReminderUndo` (the reminder snapshot) and by the scheduled
    // message Undo, which both look the row up in the workspace props.
    usePage: () => inertiaPage,
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

import {
    clearMessageActionMocks,
    harness,
    message,
    optionsOf,
    payloadOf,
} from '@/composables/useMessageActions.harness';

describe('useMessageActions', () => {
    beforeEach(() => {
        clearMessageActionMocks();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('send', () => {
        it('renders an optimistic row, clears draft/reply, and scrolls', async () => {
            const h = harness({ replyTarget: message({ id: 'parent' }) });

            h.actions.send('a new line', []);

            expect(h.cancelDraft).toHaveBeenCalledOnce();
            expect(h.cancelReply).toHaveBeenCalledOnce();
            expect(
                h.mainStream.displayMessages.value.some(
                    (m) => m.body === 'a new line',
                ),
            ).toBe(true);
            expect(payloadOf(post)).toMatchObject({
                body: 'a new line',
                reply_to_id: 'parent',
            });

            await nextTick();
            expect(h.scrollToBottom).toHaveBeenCalled();
        });

        it('claims uploaded attachment ids in the store payload, in tray order', () => {
            const h = harness();

            h.actions.send('with files', [], ['att-1', 'att-2']);

            expect(payloadOf(post)).toMatchObject({
                body: 'with files',
                attachment_ids: ['att-1', 'att-2'],
            });
        });

        it('carries attachment ids through the offline queue and its later flush', () => {
            const h = harness({ isOnline: false });

            // An attachment-only send (empty body) queued offline.
            h.actions.send('', [], ['att-9']);
            expect(h.outbox.items.value[0]).toMatchObject({
                attachmentIds: ['att-9'],
            });

            h.actions.flushOutbox();
            expect(payloadOf(post)).toMatchObject({
                attachment_ids: ['att-9'],
            });
        });

        it('rolls the optimistic row back and toasts on error', () => {
            const h = harness();

            h.actions.send('doomed', []);
            expect(h.mainStream.pendingUuids.value).toHaveLength(1);

            optionsOf(post).onError?.();
            expect(h.mainStream.pendingUuids.value).toHaveLength(0);
            expect(toastError).toHaveBeenCalledOnce();
        });

        it('announces the failure through the live-region callback on error', () => {
            const h = harness();

            h.actions.send('doomed', []);
            optionsOf(post).onError?.();

            expect(h.onSendFailure).toHaveBeenCalledOnce();
            expect(h.onSendFailure).toHaveBeenCalledWith(
                expect.stringContaining('failed to send'),
            );
        });

        it('queues the send while offline instead of posting', () => {
            const h = harness({
                isOnline: false,
                replyTarget: message({ id: 'parent' }),
            });

            h.actions.send('later', []);

            // The optimistic row still renders, but nothing hits the network.
            expect(
                h.mainStream.displayMessages.value.some(
                    (m) => m.body === 'later',
                ),
            ).toBe(true);
            expect(post).not.toHaveBeenCalled();
            expect(h.outbox.count.value).toBe(1);
            expect(h.outbox.items.value[0]).toMatchObject({
                body: 'later',
                replyToId: 'parent',
            });
            // The saved draft is cleared now, since the store endpoint that
            // normally clears it isn't reached until the queue flushes.
            expect(h.clearDraft).toHaveBeenCalledOnce();
        });

        it('accepts the send on a successful post so the composer drops its snapshot', () => {
            const h = harness();
            const onAccepted = vi.fn();
            const onRejected = vi.fn();

            h.actions.send('with files', [], ['att-1'], {
                onAccepted,
                onRejected,
            });
            optionsOf(post).onSuccess?.();

            expect(onAccepted).toHaveBeenCalledOnce();
            expect(onRejected).not.toHaveBeenCalled();
        });

        it('rejects the send on error so the composer restores its snapshot', () => {
            const h = harness();
            const onAccepted = vi.fn();
            const onRejected = vi.fn();

            h.actions.send('doomed', [], ['att-1'], { onAccepted, onRejected });
            optionsOf(post).onError?.();

            expect(onRejected).toHaveBeenCalledOnce();
            expect(onAccepted).not.toHaveBeenCalled();
        });

        it('accepts a send queued offline so its snapshot is dropped, not restored', () => {
            const h = harness({ isOnline: false });
            const onAccepted = vi.fn();
            const onRejected = vi.fn();

            h.actions.send('later', [], ['att-1'], { onAccepted, onRejected });

            expect(onAccepted).toHaveBeenCalledOnce();
            expect(onRejected).not.toHaveBeenCalled();
        });
    });
});
