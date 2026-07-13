import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import type { Ref } from 'vue';

const { post, patch, destroy, toastError, toastSuccess } = vi.hoisted(() => ({
    post: vi.fn(),
    patch: vi.fn(),
    destroy: vi.fn(),
    toastError: vi.fn(),
    toastSuccess: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { post, patch, delete: destroy },
}));
vi.mock('vue-sonner', () => ({
    toast: { error: toastError, success: toastSuccess },
}));

import { useMessageActions } from '@/composables/useMessageActions';
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
            isOnline: () => setup.isOnline ?? true,
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
    onSuccess?: () => void;
} {
    return mock.mock.calls[call][2];
}

/** The payload (second argument) of the nth recorded call on a router mock. */
function payloadOf(mock: typeof post, call = 0): Record<string, unknown> {
    return mock.mock.calls[call][1];
}

/** `router.delete` takes its options as the second argument (no request body). */
function deleteOptionsOf(call = 0): {
    only?: string[];
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
    });

    describe('flushOutbox', () => {
        it('posts each queued send in order and empties the queue', () => {
            const h = harness({ isOnline: false });

            h.actions.send('first', []);
            h.actions.send('second', []);
            expect(post).not.toHaveBeenCalled();

            h.actions.flushOutbox();

            expect(post).toHaveBeenCalledTimes(2);
            expect(payloadOf(post, 0)).toMatchObject({ body: 'first' });
            expect(payloadOf(post, 1)).toMatchObject({ body: 'second' });
            expect(h.outbox.count.value).toBe(0);
        });

        it('rolls a flushed row back and toasts if its post fails', () => {
            const h = harness({ isOnline: false });

            h.actions.send('doomed', []);
            h.actions.flushOutbox();
            expect(h.mainStream.pendingUuids.value).toHaveLength(1);

            optionsOf(post).onError?.();

            expect(h.mainStream.pendingUuids.value).toHaveLength(0);
            expect(toastError).toHaveBeenCalledOnce();
        });

        it('is a no-op with an empty queue', () => {
            const h = harness();

            h.actions.flushOutbox();

            expect(post).not.toHaveBeenCalled();
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

            optionsOf(post).onSuccess?.();
            expect(toastSuccess).toHaveBeenCalledOnce();
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
