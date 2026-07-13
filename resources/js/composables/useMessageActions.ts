import { router } from '@inertiajs/vue3';
import { nextTick } from 'vue';
import type { Ref } from 'vue';
import { toast } from 'vue-sonner';
import { store as forwardMessageAction } from '@/actions/App/Http/Controllers/Channels/ForwardMessageController';
import {
    destroy as destroyMessage,
    store as storeMessage,
    update as updateMessage,
} from '@/actions/App/Http/Controllers/Channels/MessageController';
import { store as remindMessage } from '@/actions/App/Http/Controllers/Channels/MessageReminderController';
import {
    destroy as unpinMessageAction,
    store as pinMessageAction,
} from '@/actions/App/Http/Controllers/Channels/PinController';
import { store as toggleReactionAction } from '@/actions/App/Http/Controllers/Channels/ReactionController';
import {
    destroy as destroyScheduledMessage,
    store as storeScheduledMessage,
    update as updateScheduledMessage,
} from '@/actions/App/Http/Controllers/Channels/ScheduledMessageController';
import type { useMessageStream } from '@/composables/useMessageStream';
import { optimisticMessage } from '@/composables/useMessageStream';
import { useTranslations } from '@/composables/useTranslations';
import { planForward } from '@/lib/forwardPlacement';
import type { Outbox } from '@/lib/outbox';
import { toggleReaction } from '@/lib/reactions';
import { generateUuid } from '@/lib/uuid';
import type { Channel, Mention, Message, MessagePin } from '@/types';
import type { ForwardTarget } from '@/types/forward';

type MessageStream = ReturnType<typeof useMessageStream>;

/** The subset of the open channel the message actions route and quote against. */
type ActionChannel = Pick<Channel, 'id' | 'slug' | 'name' | 'isDirect'>;

export interface MessageActionsOptions {
    /** The current team's slug, for every message route. */
    teamSlug: () => string;
    /** The open channel; re-read per call so a channel switch routes correctly. */
    channel: () => ActionChannel;
    /** The current viewer, stamped as the author of optimistic rows. */
    currentUser: () => Mention;
    /** The main channel timeline stream. */
    mainStream: MessageStream;
    /** The open thread panel's stream. */
    threadStream: MessageStream;
    /** The root id of the thread currently open in the panel, or `null`. */
    activeThreadRootId: Ref<string | null>;
    /** The message the composer is quoting, or `null` for a normal send. */
    replyTarget: Ref<Message | null>;
    /** Whether the viewer is scrolled near the bottom of the main timeline. */
    isNearBottom: () => boolean;
    /** Smooth-scroll the main timeline to the newest message. */
    scrollToBottom: () => void;
    /** Drop a pending draft save (a send/schedule clears the draft server-side). */
    cancelDraft: () => void;
    /**
     * Immediately clear the saved draft. A queued (offline) send never reaches the
     * store endpoint that normally clears it, so without this a refresh would
     * repopulate the composer with the already-queued text.
     */
    clearDraft: () => void;
    /** Clear the composer's reply quote. */
    cancelReply: () => void;
    /** Whether the realtime socket is up; a send while offline queues instead. */
    isOnline: () => boolean;
    /** The offline queue holding channel sends until the connection recovers. */
    outbox: Outbox;
    /**
     * Announce a send failure to assistive technology. A rolled-back optimistic
     * row vanishes silently, so this surfaces the same message a toast shows to a
     * screen reader via a polite live region.
     */
    onSendFailure?: (message: string) => void;
}

export interface MessageActions {
    /**
     * Send a message, optimistically, rolling the row back on error. Any
     * `attachmentIds` (pre-uploaded in the composer) are claimed by the message
     * in the same store request, in tray order.
     */
    send: (body: string, mentions: Mention[], attachmentIds?: string[]) => void;
    /** Post every queued send, in order, then drop each from the queue. */
    flushOutbox: () => void;
    /** Save an edit, optimistically, rolling the patch back on error. */
    editMessage: (message: Message, body: string) => void;
    /** Delete a message, optimistically, rolling the tombstone back on error. */
    deleteMessage: (message: Message) => void;
    /** Toggle the viewer's reaction, optimistically, rolled back on error. */
    reactToMessage: (message: Message, emoji: string) => void;
    /** Pin a message to its channel, optimistically, rolled back on error. */
    pinMessage: (message: Message) => void;
    /** Unpin a message from its channel, optimistically, rolled back on error. */
    unpinMessage: (message: Message) => void;
    /** Forward a message to a channel or a person, optimistic to the current channel. */
    forwardMessage: (
        source: Message,
        payload: { target: ForwardTarget; note: string },
    ) => void;
    /** Post a reply into the open thread, optionally echoed to the channel. */
    sendThreadReply: (
        body: string,
        mentions: Mention[],
        sendToChannel?: boolean,
    ) => void;
    /** Schedule the composer's text for later delivery. */
    scheduleMessage: (
        body: string,
        mentions: Mention[],
        sendAt: string,
    ) => void;
    /** Edit a pending scheduled message's body and send time. */
    updateScheduled: (payload: {
        id: string;
        body: string;
        sendAt: string;
    }) => void;
    /** Cancel a pending scheduled message so it is never delivered. */
    cancelScheduled: (id: string) => void;
    /** Set (or re-arm) a personal reminder on a message at a chosen instant. */
    setReminder: (messageId: string, remindAt: string) => void;
}

/**
 * Own the channel's optimistic-mutation engine: every message action follows the
 * same shape — capture the previous state, apply optimistically, fire the router
 * call, then roll back and toast on failure. Concentrating the eight-plus call
 * sites behind one seam keeps the optimistic/rollback contract in a single,
 * unit-testable module rather than scattered through `Show.vue`'s setup block.
 *
 * The pure forward-placement decision (current-channel target, DM quote naming)
 * lives in {@see planForward}; the optimistic row factory is the shared
 * {@see optimisticMessage}. Realtime echoes re-apply the same patches via
 * {@see useChannelRealtime}, so a rolled-back optimistic copy and its later
 * broadcast stay consistent.
 */
export function useMessageActions(
    options: MessageActionsOptions,
): MessageActions {
    const { t } = useTranslations();

    /** Add an optimistic row to the main timeline, honouring the pin-to-bottom rule. */
    function appendPendingMain(message: Message): void {
        const pinned = options.isNearBottom();
        options.mainStream.addPending(message);

        if (pinned) {
            nextTick(() => options.scrollToBottom());
        }
    }

    /** Patch a message into both timelines at once; ignored where it isn't shown. */
    function applyPatch(message: Message): void {
        options.mainStream.applyPatch(message);
        options.threadStream.applyPatch(message);
    }

    /**
     * Fire the store request for one channel send, rolling the optimistic row
     * back and toasting on failure. Shared by an immediate {@see send} and by
     * {@see flushOutbox} so a queued message posts on exactly the same contract.
     */
    function postMessage(item: {
        clientUuid: string;
        body: string;
        replyToId: string | null;
        attachmentIds: string[];
    }): void {
        router.post(
            storeMessage({
                team: options.teamSlug(),
                channel: options.channel().slug,
            }).url,
            {
                body: item.body,
                client_uuid: item.clientUuid,
                reply_to_id: item.replyToId,
                attachment_ids: item.attachmentIds,
            },
            {
                preserveScroll: true,
                onError: () => {
                    // The optimistic row failed to persist; roll it back and notify.
                    options.mainStream.removePending(item.clientUuid);
                    const message = t(
                        'Your message failed to send. Please try again.',
                    );
                    toast.error(message);
                    options.onSendFailure?.(message);
                },
            },
        );
    }

    function send(
        body: string,
        mentions: Mention[],
        attachmentIds: string[] = [],
    ): void {
        // Sending clears the draft server-side, so drop any debounced save still
        // in flight; otherwise it would re-persist the just-sent text.
        options.cancelDraft();

        const clientUuid = generateUuid();
        const target = options.replyTarget.value;
        const replyToId = target?.id ?? null;

        // The optimistic row mirrors the parent quote so the reference renders
        // immediately; the server echo replaces it, keyed on the same client uuid.
        options.mainStream.addPending(
            optimisticMessage({
                clientUuid,
                body,
                author: options.currentUser(),
                mentions,
                replyTo: target,
            }),
        );

        options.cancelReply();
        nextTick(() => options.scrollToBottom());

        if (options.isOnline()) {
            postMessage({ clientUuid, body, replyToId, attachmentIds });

            return;
        }

        // Offline: hold the send locally (the row shows as queued) and flush it
        // when the connection recovers, rather than failing it outright. Clear the
        // saved draft now, since the store endpoint that normally does so won't be
        // reached until flush — otherwise a refresh would repopulate the composer.
        options.outbox.enqueue({ clientUuid, body, replyToId, attachmentIds });
        options.clearDraft();
    }

    function flushOutbox(): void {
        // Snapshot first: `postMessage` never mutates the queue, but draining as
        // we go keeps the queued-row markers clearing in send order.
        for (const item of [...options.outbox.items.value]) {
            options.outbox.remove(item.clientUuid);
            postMessage(item);
        }
    }

    function editMessage(message: Message, body: string): void {
        const channel = options.channel();
        const previousMain = options.mainStream.getPatch(message.clientUuid);
        const previousThread = options.threadStream.getPatch(
            message.clientUuid,
        );

        // Optimistically show the edit; the broadcast echo later confirms it.
        applyPatch({ ...message, body, editedAt: new Date().toISOString() });

        router.patch(
            updateMessage({
                team: options.teamSlug(),
                channel: channel.slug,
                message: message.id,
            }).url,
            { body },
            {
                preserveScroll: true,
                onError: () => {
                    options.mainStream.restorePatch(
                        message.clientUuid,
                        previousMain,
                    );
                    options.threadStream.restorePatch(
                        message.clientUuid,
                        previousThread,
                    );
                    toast.error(
                        t('Your edit failed to save. Please try again.'),
                    );
                },
            },
        );
    }

    function deleteMessage(message: Message): void {
        const channel = options.channel();
        const previousMain = options.mainStream.getPatch(message.clientUuid);
        const previousThread = options.threadStream.getPatch(
            message.clientUuid,
        );

        // Optimistically show the tombstone; the broadcast echo later confirms it.
        applyPatch({ ...message, body: '', isDeleted: true });

        router.delete(
            destroyMessage({
                team: options.teamSlug(),
                channel: channel.slug,
                message: message.id,
            }).url,
            {
                preserveScroll: true,
                onError: () => {
                    options.mainStream.restorePatch(
                        message.clientUuid,
                        previousMain,
                    );
                    options.threadStream.restorePatch(
                        message.clientUuid,
                        previousThread,
                    );
                    toast.error(
                        t('Failed to delete the message. Please try again.'),
                    );
                },
            },
        );
    }

    function reactToMessage(message: Message, emoji: string): void {
        const channel = options.channel();
        const previousMain = options.mainStream.getPatch(message.clientUuid);
        const previousThread = options.threadStream.getPatch(
            message.clientUuid,
        );

        const next = toggleReaction(
            message.reactions,
            emoji,
            options.currentUser(),
        );
        options.mainStream.patchReactions(message.id, next);
        options.threadStream.patchReactions(message.id, next);

        router.post(
            toggleReactionAction({
                team: options.teamSlug(),
                channel: channel.slug,
                message: message.id,
            }).url,
            { emoji },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['channels'],
                onError: () => {
                    options.mainStream.restorePatch(
                        message.clientUuid,
                        previousMain,
                    );
                    options.threadStream.restorePatch(
                        message.clientUuid,
                        previousThread,
                    );
                    toast.error(
                        t('Failed to update the reaction. Please try again.'),
                    );
                },
            },
        );
    }

    /**
     * Pin a message to its channel. The indicator is applied optimistically to
     * both streams and rolled back on error; the masthead count and pins panel
     * reconcile from the refreshed `pinCount`/`pins` props the request returns,
     * and every other client patches over the `MessagePinned` broadcast. The
     * server-side cap error surfaces as its own toast.
     */
    function pinMessage(message: Message): void {
        const channel = options.channel();
        const previousMain = options.mainStream.getPatch(message.clientUuid);
        const previousThread = options.threadStream.getPatch(
            message.clientUuid,
        );

        const optimisticPin: MessagePin = {
            pinnedBy: options.currentUser(),
            pinnedAt: new Date().toISOString(),
        };
        options.mainStream.patchPin(message.id, optimisticPin);
        options.threadStream.patchPin(message.id, optimisticPin);

        router.post(
            pinMessageAction({
                team: options.teamSlug(),
                channel: channel.slug,
                message: message.id,
            }).url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['pins', 'pinCount'],
                onError: (errors: Record<string, string>) => {
                    options.mainStream.restorePatch(
                        message.clientUuid,
                        previousMain,
                    );
                    options.threadStream.restorePatch(
                        message.clientUuid,
                        previousThread,
                    );
                    toast.error(
                        errors.message ??
                            t('Failed to pin the message. Please try again.'),
                    );
                },
            },
        );
    }

    /**
     * Unpin a message from its channel — a shared toggle any member may perform.
     * Mirrors {@see pinMessage}: optimistic removal of the indicator, rolled back
     * on error, with the count and panel reconciling from the returned props.
     */
    function unpinMessage(message: Message): void {
        const channel = options.channel();
        const previousMain = options.mainStream.getPatch(message.clientUuid);
        const previousThread = options.threadStream.getPatch(
            message.clientUuid,
        );

        options.mainStream.patchPin(message.id, null);
        options.threadStream.patchPin(message.id, null);

        router.delete(
            unpinMessageAction({
                team: options.teamSlug(),
                channel: channel.slug,
                message: message.id,
            }).url,
            {
                preserveScroll: true,
                preserveState: true,
                only: ['pins', 'pinCount'],
                onError: () => {
                    options.mainStream.restorePatch(
                        message.clientUuid,
                        previousMain,
                    );
                    options.threadStream.restorePatch(
                        message.clientUuid,
                        previousThread,
                    );
                    toast.error(
                        t('Failed to unpin the message. Please try again.'),
                    );
                },
            },
        );
    }

    function forwardMessage(
        source: Message,
        payload: { target: ForwardTarget; note: string },
    ): void {
        const channel = options.channel();
        const { target, note } = payload;
        const clientUuid = generateUuid();
        const plan = planForward({ target, channel });

        if (plan.toCurrentChannel) {
            appendPendingMain(
                optimisticMessage({
                    clientUuid,
                    body: note,
                    author: options.currentUser(),
                    mentions: [],
                    forwardedFrom: {
                        id: source.id,
                        body: source.body,
                        authorName: source.user.name,
                        channelName: plan.quoteChannelName,
                        isDeleted: source.isDeleted,
                        mentions: source.mentions,
                    },
                }),
            );
        }

        router.post(
            forwardMessageAction({
                team: options.teamSlug(),
                channel: channel.slug,
                message: source.id,
            }).url,
            { body: note, client_uuid: clientUuid, ...plan.destination },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['channels'],
                onSuccess: () => {
                    if (plan.toCurrentChannel) {
                        return;
                    }

                    toast.success(
                        target.kind === 'channel'
                            ? t('Message forwarded to #:channel.', {
                                  channel: target.name,
                              })
                            : t('Message forwarded to :name.', {
                                  name: target.name,
                              }),
                    );
                },
                onError: () => {
                    if (plan.toCurrentChannel) {
                        options.mainStream.removePending(clientUuid);
                    }

                    toast.error(
                        t('Failed to forward the message. Please try again.'),
                    );
                },
            },
        );
    }

    function sendThreadReply(
        body: string,
        mentions: Mention[],
        sendToChannel?: boolean,
    ): void {
        const rootId = options.activeThreadRootId.value;

        if (!rootId) {
            return;
        }

        const channel = options.channel();
        const clientUuid = generateUuid();
        const optimistic = optimisticMessage({
            clientUuid,
            body,
            author: options.currentUser(),
            mentions,
            threadRootId: rootId,
            sentToChannel: sendToChannel ?? false,
        });

        options.threadStream.addPending(optimistic);

        // Replying makes the viewer a follower of the thread and means they've
        // seen it, so keep the root's affordance in the main timeline dot-free.
        options.mainStream.patchThreadState(rootId, {
            threadFollowed: true,
            threadUnread: false,
        });

        if (sendToChannel) {
            appendPendingMain(optimistic);
        }

        router.post(
            storeMessage({ team: options.teamSlug(), channel: channel.slug })
                .url,
            {
                body,
                client_uuid: clientUuid,
                thread_root_id: rootId,
                sent_to_channel: sendToChannel ?? false,
            },
            {
                preserveScroll: true,
                onError: () => {
                    options.threadStream.removePending(clientUuid);

                    if (sendToChannel) {
                        options.mainStream.removePending(clientUuid);
                    }

                    toast.error(
                        t('Your reply failed to send. Please try again.'),
                    );
                },
            },
        );
    }

    function scheduleMessage(
        body: string,
        _mentions: Mention[],
        sendAt: string,
    ): void {
        options.cancelDraft();

        const channel = options.channel();
        const target = options.replyTarget.value;

        router.post(
            storeScheduledMessage({
                team: options.teamSlug(),
                channel: channel.slug,
            }).url,
            {
                body,
                client_uuid: generateUuid(),
                reply_to_id: target?.id ?? null,
                send_at: sendAt,
            },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['scheduledMessages', 'channels'],
                onSuccess: () => toast.success(t('Message scheduled.')),
                onError: () =>
                    toast.error(
                        t('Failed to schedule your message. Please try again.'),
                    ),
            },
        );

        options.cancelReply();
    }

    function updateScheduled(payload: {
        id: string;
        body: string;
        sendAt: string;
    }): void {
        const channel = options.channel();

        router.patch(
            updateScheduledMessage({
                team: options.teamSlug(),
                channel: channel.slug,
                scheduledMessage: payload.id,
            }).url,
            { body: payload.body, send_at: payload.sendAt },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['scheduledMessages'],
                onError: () =>
                    toast.error(
                        t(
                            'Failed to update the scheduled message. Please try again.',
                        ),
                    ),
            },
        );
    }

    function cancelScheduled(id: string): void {
        const channel = options.channel();

        router.delete(
            destroyScheduledMessage({
                team: options.teamSlug(),
                channel: channel.slug,
                scheduledMessage: id,
            }).url,
            {
                preserveScroll: true,
                preserveState: true,
                only: ['scheduledMessages'],
                onSuccess: () =>
                    toast.success(t('Scheduled message cancelled.')),
                onError: () =>
                    toast.error(
                        t(
                            'Failed to cancel the scheduled message. Please try again.',
                        ),
                    ),
            },
        );
    }

    function setReminder(messageId: string, remindAt: string): void {
        router.post(
            remindMessage({ team: options.teamSlug() }).url,
            { message_id: messageId, remind_at: remindAt },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['reminders', 'firedReminders'],
                onSuccess: () => toast.success(t('Reminder set.')),
                onError: () =>
                    toast.error(
                        t('Failed to set the reminder. Please try again.'),
                    ),
            },
        );
    }

    return {
        send,
        flushOutbox,
        editMessage,
        deleteMessage,
        reactToMessage,
        pinMessage,
        unpinMessage,
        forwardMessage,
        sendThreadReply,
        scheduleMessage,
        updateScheduled,
        cancelScheduled,
        setReminder,
    };
}
