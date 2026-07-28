import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

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

import { QUEUED_SENDS_TOAST_KEY } from '@/composables/useMessageActions';
import {
    clearMessageActionMocks,
    harness,
    optionsOf,
    payloadOf,
    settle,
} from '@/composables/useMessageActions.harness';

describe('useMessageActions', () => {
    beforeEach(() => {
        clearMessageActionMocks();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('flushOutbox', () => {
        it('posts each queued send in order and empties the queue', async () => {
            const h = harness({ isOnline: false });

            h.actions.send('first', []);
            h.actions.send('second', []);
            expect(post).not.toHaveBeenCalled();

            const flushed = h.actions.flushOutbox();

            // One at a time: the second only goes out once the first has
            // settled, so a queued conversation arrives in the order it was
            // typed rather than in whatever order the requests happen to land.
            expect(post).toHaveBeenCalledTimes(1);
            expect(payloadOf(post, 0)).toMatchObject({ body: 'first' });

            optionsOf(post, 0).onFinish?.();
            await settle();

            expect(post).toHaveBeenCalledTimes(2);
            expect(payloadOf(post, 1)).toMatchObject({ body: 'second' });

            optionsOf(post, 1).onFinish?.();

            await expect(flushed).resolves.toBe(2);
            expect(h.outbox.count.value).toBe(0);
        });

        it('re-queues a flushed send whose post fails, keeping its row', () => {
            const h = harness({ isOnline: false });

            h.actions.send('doomed', []);
            h.actions.flushOutbox();
            expect(h.outbox.count.value).toBe(0);
            expect(h.mainStream.pendingUuids.value).toHaveLength(1);

            optionsOf(post).onError?.();

            // The send is back on the queue rather than lost, so "Retry all"
            // has something to retry, and its row still reads as queued.
            expect(h.outbox.count.value).toBe(1);
            expect(h.outbox.items.value[0]).toMatchObject({ body: 'doomed' });
            expect(h.mainStream.pendingUuids.value).toHaveLength(1);
        });

        it('announces a failed flush as one toast counting the queue', async () => {
            const h = harness({ isOnline: false });

            h.actions.send('first', []);
            h.actions.send('second', []);
            h.actions.flushOutbox();

            optionsOf(post, 0).onError?.();
            optionsOf(post, 0).onFinish?.();
            await settle();

            optionsOf(post, 1).onError?.();
            optionsOf(post, 1).onFinish?.();
            await settle();

            // The count is the queue's, not a tally of failures seen, and the
            // repeats merge onto one card rather than stacking two.
            const [title, options] = toastError.mock.calls.at(-1) ?? [];
            expect(title).toBe("2 messages didn't send");
            expect(options).toMatchObject({
                key: QUEUED_SENDS_TOAST_KEY,
                action: { label: 'Retry all' },
            });
        });

        it('re-queues a flushed send whose visit is cancelled', () => {
            const h = harness({ isOnline: false });

            h.actions.send('interrupted', []);
            h.isOnline.value = true;
            h.actions.flushOutbox();

            // A cancelled visit never reports through `onError`, so without this
            // the send would vanish from the queue having never been posted.
            optionsOf(post, 0).onCancel?.();

            expect(h.outbox.count.value).toBe(1);
            expect(h.outbox.items.value[0]).toMatchObject({
                body: 'interrupted',
            });
        });

        it('resolves with how many of the queued sends actually landed', async () => {
            const h = harness({ isOnline: false });

            h.actions.send('lands', []);
            h.actions.send('fails', []);
            h.isOnline.value = true;

            const flushed = h.actions.flushOutbox();

            optionsOf(post, 0).onFinish?.();
            await settle();

            optionsOf(post, 1).onError?.();
            optionsOf(post, 1).onFinish?.();

            // Counted from the posts themselves, not from how the queue's length
            // moved — a send made while the flush is in flight would skew that.
            await expect(flushed).resolves.toBe(1);
        });

        it('is a no-op with an empty queue', async () => {
            const h = harness();

            await expect(h.actions.flushOutbox()).resolves.toBe(0);

            expect(post).not.toHaveBeenCalled();
        });
    });

    describe('retryQueuedSends', () => {
        /**
         * Walk the real sequence: a send queued offline, a reconnect, and a
         * flush that fails. Hands back the failure toast's Retry all.
         */
        function queueOneFailedSend(): {
            h: ReturnType<typeof harness>;
            retryAll: () => void;
        } {
            const h = harness({ isOnline: false });

            h.actions.send('doomed', []);
            h.isOnline.value = true;
            h.actions.flushOutbox();
            optionsOf(post, 0).onError?.();
            optionsOf(post, 0).onFinish?.();

            const options = toastError.mock.calls.at(-1)?.[1];

            return { h, retryAll: options.action.run };
        }

        it('reposts the queue from the toast action', () => {
            const { retryAll } = queueOneFailedSend();

            retryAll();

            expect(post).toHaveBeenCalledTimes(2);
            expect(payloadOf(post, 1)).toMatchObject({ body: 'doomed' });
        });

        it('confirms on the same card once the queue drains', async () => {
            const { h, retryAll } = queueOneFailedSend();
            toastError.mockClear();

            retryAll();
            optionsOf(post, 1).onFinish?.();
            await settle();

            expect(h.outbox.count.value).toBe(0);
            expect(toastSuccess).toHaveBeenCalledWith('Queued message sent', {
                key: QUEUED_SENDS_TOAST_KEY,
            });
            expect(toastError).not.toHaveBeenCalled();
        });

        it('re-announces the still-queued count when the retry fails again', async () => {
            const { h, retryAll } = queueOneFailedSend();
            toastError.mockClear();

            retryAll();
            optionsOf(post, 1).onError?.();
            optionsOf(post, 1).onFinish?.();
            await settle();

            expect(h.outbox.count.value).toBe(1);
            expect(toastError).toHaveBeenCalledWith("1 message didn't send", {
                key: QUEUED_SENDS_TOAST_KEY,
                action: { label: 'Retry all', run: expect.any(Function) },
            });
            expect(toastSuccess).not.toHaveBeenCalled();
        });

        it('holds the queue instead of reposting while still offline', async () => {
            const h = harness({ isOnline: false });

            h.actions.send('later', []);
            await h.actions.retryQueuedSends();

            // Posting into a dead connection would drain the queue on a request
            // that never lands, losing the send.
            expect(post).not.toHaveBeenCalled();
            expect(h.outbox.count.value).toBe(1);
            expect(toastSuccess).not.toHaveBeenCalled();
            expect(toastError).toHaveBeenCalledWith("1 message didn't send", {
                key: QUEUED_SENDS_TOAST_KEY,
                action: { label: 'Retry all', run: expect.any(Function) },
            });
        });

        it('does nothing with an empty queue', async () => {
            const h = harness();

            await h.actions.retryQueuedSends();

            expect(post).not.toHaveBeenCalled();
            expect(toastSuccess).not.toHaveBeenCalled();
        });
    });
});
