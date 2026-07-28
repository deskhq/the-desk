import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, reactive, ref } from 'vue';
import type { Ref } from 'vue';

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
    QUEUED_SENDS_TOAST_KEY,
    useMessageActions,
} from '@/composables/useMessageActions';
import type { MessageActions } from '@/composables/useMessageActions';
import { useMessageStream } from '@/composables/useMessageStream';
import { createOutbox } from '@/lib/outbox';
import type { Outbox } from '@/lib/outbox';
import type { Mention, Message } from '@/types';
import type { ForwardTarget } from '@/types/forward';

type Stream = ReturnType<typeof useMessageStream>;

/** A message carrying just the fields the actions read. */
function message(overrides: Partial<Message> = {}): Message {
    return {
        id: 'm1',
        clientUuid: 'uuid-1',
        body: 'hello',
        type: 'standard',
        user: { id: 'peer', name: 'Peer' },
        createdAt: '2024-01-01T00:00:00.000Z',
        editedAt: null,
        isDeleted: false,
        mentions: [],
        linkPreviews: [],
        attachments: [],
        reactions: [],
        pin: null,
        poll: null,
        replyTo: null,
        forwardedFrom: null,
        threadRootId: null,
        sentToChannel: false,
        threadReplyCount: 0,
        threadLastReplyAt: null,
        threadParticipants: [],
        threadFollowed: false,
        threadUnread: false,
        threadUnreadReplyCount: 0,
        ...overrides,
    };
}

const me: Mention = { id: 'me', name: 'Me' };
const channel = {
    id: 'chan-1',
    slug: 'general',
    name: 'general',
    isDirect: false,
};

function harness(
    setup: {
        serverMain?: Message[];
        serverThread?: Message[];
        activeThreadRootId?: string | null;
        replyTarget?: Message | null;
        isNearBottom?: boolean;
        isOnline?: boolean;
    } = {},
) {
    const scope = effectScope();
    const cancelDraft = vi.fn();
    const clearDraft = vi.fn();
    const cancelReply = vi.fn();
    const scrollToBottom = vi.fn();
    const onSendFailure = vi.fn();
    const activeThreadRootId: Ref<string | null> = ref(
        setup.activeThreadRootId ?? null,
    );
    const replyTarget: Ref<Message | null> = ref(setup.replyTarget ?? null);
    /** Flippable so a test can queue offline and then reconnect, as the app does. */
    const isOnline = ref(setup.isOnline ?? true);

    let actions!: MessageActions;
    let mainStream!: Stream;
    let threadStream!: Stream;
    let outbox!: Outbox;

    scope.run(() => {
        mainStream = useMessageStream(ref(setup.serverMain ?? []));
        threadStream = useMessageStream(ref(setup.serverThread ?? []));
        outbox = createOutbox();
        actions = useMessageActions({
            teamSlug: () => 'acme',
            channel: () => channel,
            currentUser: () => me,
            isOnline: () => isOnline.value,
            outbox,
            mainStream,
            threadStream,
            activeThreadRootId,
            replyTarget,
            isNearBottom: () => setup.isNearBottom ?? true,
            scrollToBottom,
            cancelDraft,
            clearDraft,
            cancelReply,
            onSendFailure,
        });
    });

    return {
        actions,
        mainStream,
        threadStream,
        outbox,
        isOnline,
        activeThreadRootId,
        cancelDraft,
        clearDraft,
        cancelReply,
        scrollToBottom,
        onSendFailure,
        unmount: () => scope.stop(),
    };
}

/** The options (third argument) of the nth recorded call on a router mock. */
function optionsOf(
    mock: typeof post,
    call = 0,
): {
    only?: string[];
    onError?: () => void;
    onSuccess?: (page?: unknown) => void;
    onFinish?: () => void;
    onCancel?: () => void;
} {
    return mock.mock.calls[call][2];
}

/**
 * A response page carrying the given flash data. Only `flash` is read by the
 * paths under test, so the rest of the page object is deliberately absent.
 */
function pageFlashing(flash: Record<string, unknown>): unknown {
    return { flash };
}

/** The payload (second argument) of the nth recorded call on a router mock. */
function payloadOf(mock: typeof post, call = 0): Record<string, unknown> {
    return mock.mock.calls[call][1];
}

/**
 * Drain the microtask queue. A flush resolves through several awaits before it
 * reports its outcome, so a single `nextTick` is not far enough down the chain.
 */
function settle(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

/** `router.delete` takes its options as the second argument (no request body). */
function deleteOptionsOf(call = 0): {
    only?: string[];
    preserveUrl?: boolean;
    onError?: () => void;
    onSuccess?: () => void;
} {
    return destroy.mock.calls[call][1];
}

describe('useMessageActions', () => {
    beforeEach(() => {
        post.mockClear();
        patch.mockClear();
        destroy.mockClear();
        toastError.mockClear();
        toastSuccess.mockClear();
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

    describe('editMessage', () => {
        it('patches optimistically and rolls back to the prior copy on error', () => {
            const original = message({ body: 'before' });
            const h = harness({ serverMain: [original] });

            h.actions.editMessage(original, 'after');
            expect(
                h.mainStream.displayMessages.value.find((m) => m.id === 'm1')
                    ?.body,
            ).toBe('after');
            expect(payloadOf(patch)).toEqual({ body: 'after' });

            optionsOf(patch).onError?.();
            expect(
                h.mainStream.displayMessages.value.find((m) => m.id === 'm1')
                    ?.body,
            ).toBe('before');
            expect(toastError).toHaveBeenCalledOnce();
        });
    });

    describe('deleteMessage', () => {
        it('shows a tombstone optimistically and restores it on error', () => {
            const original = message({ body: 'delete me' });
            const h = harness({ serverMain: [original] });

            h.actions.deleteMessage(original);
            expect(
                h.mainStream.displayMessages.value.find((m) => m.id === 'm1')
                    ?.isDeleted,
            ).toBe(true);

            deleteOptionsOf().onError?.();
            expect(
                h.mainStream.displayMessages.value.find((m) => m.id === 'm1')
                    ?.isDeleted,
            ).toBe(false);
            expect(toastError).toHaveBeenCalledOnce();
        });
    });

    describe('reactToMessage', () => {
        it('patches reactions optimistically and rolls back on error', () => {
            const original = message();
            const h = harness({ serverMain: [original] });

            h.actions.reactToMessage(original, '👍');
            expect(
                h.mainStream.displayMessages.value.find((m) => m.id === 'm1')
                    ?.reactions,
            ).toHaveLength(1);
            expect(optionsOf(post).only).toEqual(['channels']);

            optionsOf(post).onError?.();
            expect(
                h.mainStream.displayMessages.value.find((m) => m.id === 'm1')
                    ?.reactions,
            ).toHaveLength(0);
            expect(toastError).toHaveBeenCalledOnce();
        });
    });

    describe('forwardMessage', () => {
        const channelTarget = (
            id: string,
            name = 'elsewhere',
        ): ForwardTarget => ({
            kind: 'channel',
            id,
            name,
        });

        it('appends an optimistic copy when forwarding into the current channel', () => {
            const h = harness();
            const source = message({ id: 'src', body: 'original' });

            h.actions.forwardMessage(source, {
                target: channelTarget('chan-1'),
                note: 'passing this on',
            });

            expect(h.mainStream.pendingUuids.value).toHaveLength(1);
            expect(payloadOf(post)).toMatchObject({
                body: 'passing this on',
                target_channel_id: 'chan-1',
            });

            // A successful forward to the current channel stays silent — the echo
            // confirms it — so no toast fires.
            optionsOf(post).onSuccess?.();
            expect(toastSuccess).not.toHaveBeenCalled();

            // On error the optimistic copy is rolled back.
            optionsOf(post).onError?.();
            expect(h.mainStream.pendingUuids.value).toHaveLength(0);
            expect(toastError).toHaveBeenCalledOnce();
        });

        it('confirms a forward elsewhere with a toast and no optimistic row', () => {
            const h = harness();
            const source = message({ id: 'src' });

            h.actions.forwardMessage(source, {
                target: channelTarget('chan-2', 'random'),
                note: '',
            });

            expect(h.mainStream.pendingUuids.value).toHaveLength(0);
            expect(payloadOf(post)).toMatchObject({
                target_channel_id: 'chan-2',
            });

            optionsOf(post).onSuccess?.(pageFlashing({}));
            expect(toastSuccess).toHaveBeenCalledOnce();
        });

        it('offers Undo on a forward elsewhere, deleting the copy where it landed', () => {
            const h = harness();

            h.actions.forwardMessage(message({ id: 'src' }), {
                target: channelTarget('chan-2', 'random'),
                note: '',
            });

            optionsOf(post).onSuccess?.(
                pageFlashing({
                    forwarded: {
                        messageId: 'fwd-1',
                        channelSlug: 'random',
                    },
                }),
            );

            const action = toastSuccess.mock.calls.at(-1)?.[1]?.action;
            expect(action?.label).toBe('Undo');

            action.run();

            // Deleted in the channel it landed in, not the one being viewed —
            // the whole reason the response has to name that channel.
            expect(destroy).toHaveBeenCalledOnce();
            expect(destroy.mock.calls[0][0]).toContain(
                '/c/random/messages/fwd-1',
            );
        });

        it('stays on the source channel while undoing', () => {
            const h = harness();

            h.actions.forwardMessage(message({ id: 'src' }), {
                target: channelTarget('chan-2', 'random'),
                note: '',
            });
            optionsOf(post).onSuccess?.(
                pageFlashing({
                    forwarded: { messageId: 'fwd-1', channelSlug: 'random' },
                }),
            );
            toastSuccess.mock.calls.at(-1)?.[1]?.action.run();

            // The destroy route redirects to the deleted message's own channel,
            // which is the destination — the opposite of where the sender is.
            expect(deleteOptionsOf(0)).toMatchObject({
                preserveUrl: true,
                only: ['channels'],
            });
        });

        it('confirms without Undo when the response names no copy', () => {
            const h = harness();

            h.actions.forwardMessage(message({ id: 'src' }), {
                target: channelTarget('chan-2', 'random'),
                note: '',
            });

            optionsOf(post).onSuccess?.(
                pageFlashing({ forwarded: 'nonsense' }),
            );

            // A flash that arrives malformed degrades to the plain confirmation
            // rather than offering an Undo that would delete nothing.
            expect(toastSuccess.mock.calls.at(-1)?.[1]?.action).toBeUndefined();
        });

        it('reports a failed undo', () => {
            const h = harness();

            h.actions.forwardMessage(message({ id: 'src' }), {
                target: channelTarget('chan-2', 'random'),
                note: '',
            });
            optionsOf(post).onSuccess?.(
                pageFlashing({
                    forwarded: { messageId: 'fwd-1', channelSlug: 'random' },
                }),
            );
            toastSuccess.mock.calls.at(-1)?.[1]?.action.run();

            deleteOptionsOf(0).onError?.();

            expect(toastError).toHaveBeenCalledWith(
                'Failed to undo the forward',
            );
        });

        it('routes a person target to their DM', () => {
            const h = harness();

            h.actions.forwardMessage(message({ id: 'src' }), {
                target: { kind: 'user', id: 'user-3', name: 'Grace' },
                note: '',
            });

            expect(payloadOf(post)).toMatchObject({ target_user_id: 'user-3' });
        });
    });

    describe('sendThreadReply', () => {
        it('does nothing when no thread is open', () => {
            const h = harness({ activeThreadRootId: null });

            h.actions.sendThreadReply('into the void', []);

            expect(post).not.toHaveBeenCalled();
        });

        it('adds a pending reply to the thread and marks the root followed', () => {
            const root = message({ id: 'root-1' });
            const h = harness({
                serverMain: [root],
                activeThreadRootId: 'root-1',
            });

            h.actions.sendThreadReply('a reply', []);

            expect(h.threadStream.pendingUuids.value).toHaveLength(1);
            // The root's dot clears in the main timeline.
            expect(
                h.mainStream.displayMessages.value.find(
                    (m) => m.id === 'root-1',
                )?.threadFollowed,
            ).toBe(true);
            expect(payloadOf(post)).toMatchObject({
                thread_root_id: 'root-1',
                sent_to_channel: false,
            });
        });

        it('also echoes into the main timeline when sent to channel', () => {
            const root = message({ id: 'root-1' });
            const h = harness({
                serverMain: [root],
                activeThreadRootId: 'root-1',
            });

            h.actions.sendThreadReply('shared reply', [], true);

            expect(h.threadStream.pendingUuids.value).toHaveLength(1);
            expect(h.mainStream.pendingUuids.value).toHaveLength(1);

            optionsOf(post).onError?.();
            expect(h.threadStream.pendingUuids.value).toHaveLength(0);
            expect(h.mainStream.pendingUuids.value).toHaveLength(0);
            expect(toastError).toHaveBeenCalledOnce();
        });
    });

    describe('scheduleMessage', () => {
        it('posts to the scheduled surface and toasts on success/error', () => {
            const h = harness();

            h.actions.scheduleMessage('later', [], '2024-06-01T09:00:00Z');

            expect(h.cancelDraft).toHaveBeenCalledOnce();
            expect(h.cancelReply).toHaveBeenCalledOnce();
            expect(optionsOf(post).only).toEqual([
                'scheduledMessages',
                'channels',
            ]);
            expect(payloadOf(post)).toMatchObject({
                body: 'later',
                send_at: '2024-06-01T09:00:00Z',
            });

            optionsOf(post).onSuccess?.();
            expect(toastSuccess).toHaveBeenCalledOnce();

            optionsOf(post).onError?.();
            expect(toastError).toHaveBeenCalledOnce();
        });
    });

    describe('updateScheduled', () => {
        it('patches the scheduled message and toasts on error', () => {
            const h = harness();

            h.actions.updateScheduled({
                id: 's1',
                body: 'edited',
                sendAt: '2024-06-02T09:00:00Z',
            });

            expect(optionsOf(patch).only).toEqual(['scheduledMessages']);
            expect(payloadOf(patch)).toEqual({
                body: 'edited',
                send_at: '2024-06-02T09:00:00Z',
            });

            optionsOf(patch).onError?.();
            expect(toastError).toHaveBeenCalledOnce();
        });
    });

    describe('cancelScheduled', () => {
        it('deletes the scheduled message and toasts on success/error', () => {
            const h = harness();

            h.actions.cancelScheduled('s1');

            expect(deleteOptionsOf().only).toEqual(['scheduledMessages']);

            deleteOptionsOf().onSuccess?.();
            expect(toastSuccess).toHaveBeenCalledOnce();

            deleteOptionsOf().onError?.();
            expect(toastError).toHaveBeenCalledOnce();
        });
    });

    describe('undo', () => {
        it('offers an Undo on a scheduled message that cancels the row it just created, found by its client uuid', () => {
            const h = harness();

            h.actions.scheduleMessage('later', [], '2024-06-01T09:00:00Z');

            const clientUuid = payloadOf(post).client_uuid as string;

            // The reload the success arrives with brings the created row back.
            inertiaPage.props = {
                scheduledMessages: [
                    { id: 'other', clientUuid: 'not-this-one' },
                    { id: 'sched-1', clientUuid },
                ],
            };

            optionsOf(post).onSuccess?.();

            const action = (
                toastSuccess.mock.calls[0][1] as {
                    action: { label: string; run: () => void };
                }
            ).action;

            expect(action.label).toBe('Undo');

            action.run();

            expect(destroy.mock.calls[0][0]).toContain('sched-1');
        });

        it('leaves the schedule alone when Undo can no longer find the row', () => {
            const h = harness();

            h.actions.scheduleMessage('later', [], '2024-06-01T09:00:00Z');
            inertiaPage.props = { scheduledMessages: [] };
            optionsOf(post).onSuccess?.();

            (
                toastSuccess.mock.calls[0][1] as { action: { run: () => void } }
            ).action.run();

            expect(destroy).not.toHaveBeenCalled();
        });

        it('offers an Undo on a reminder that puts back the time the message was already set to', () => {
            inertiaPage.props = {
                reminders: [
                    {
                        id: 'r1',
                        messageId: 'm9',
                        remindAt: '2024-01-01T07:00:00Z',
                    },
                ],
            };

            const h = harness();

            h.actions.setReminder('m9', '2024-06-03T09:00:00Z');
            optionsOf(post).onSuccess?.();

            (
                toastSuccess.mock.calls[0][1] as { action: { run: () => void } }
            ).action.run();

            // A re-arm, not a delete: setting a reminder on a message that
            // already had one overwrites it, so Undo restores the old time.
            expect(destroy).not.toHaveBeenCalled();
            expect(payloadOf(post, 1)).toEqual({
                message_id: 'm9',
                remind_at: '2024-01-01T07:00:00Z',
            });
        });

        it('offers an Undo on a reminder that clears it when the set created one', () => {
            inertiaPage.props = { reminders: [] };

            const h = harness();

            h.actions.setReminder('m9', '2024-06-03T09:00:00Z');

            inertiaPage.props = {
                reminders: [
                    {
                        id: 'r-new',
                        messageId: 'm9',
                        remindAt: '2024-06-03T09:00:00Z',
                    },
                ],
            };
            optionsOf(post).onSuccess?.();

            (
                toastSuccess.mock.calls[0][1] as { action: { run: () => void } }
            ).action.run();

            expect(destroy.mock.calls[0][0]).toContain('r-new');
        });
    });

    describe('setReminder', () => {
        it('posts the reminder and toasts on success/error', () => {
            const h = harness();

            h.actions.setReminder('m9', '2024-06-03T09:00:00Z');

            expect(optionsOf(post).only).toEqual([
                'reminders',
                'firedReminders',
            ]);
            expect(payloadOf(post)).toEqual({
                message_id: 'm9',
                remind_at: '2024-06-03T09:00:00Z',
            });

            optionsOf(post).onSuccess?.();
            expect(toastSuccess).toHaveBeenCalledOnce();

            optionsOf(post).onError?.();
            expect(toastError).toHaveBeenCalledOnce();
        });
    });
});
