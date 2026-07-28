import { router, usePage } from '@inertiajs/vue3';
import { nextTick } from 'vue';
import type { Ref } from 'vue';
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
import {
    close as closePollAction,
    vote as voteOnPollAction,
} from '@/actions/App/Http/Controllers/Channels/PollController';
import { store as toggleReactionAction } from '@/actions/App/Http/Controllers/Channels/ReactionController';
import {
    destroy as destroyScheduledMessage,
    store as storeScheduledMessage,
    update as updateScheduledMessage,
} from '@/actions/App/Http/Controllers/Channels/ScheduledMessageController';
import { store as storeCommand } from '@/actions/App/Http/Controllers/Channels/SlashCommandController';
import type { useMessageStream } from '@/composables/useMessageStream';
import { optimisticMessage } from '@/composables/useMessageStream';
import { useReminderUndo } from '@/composables/useReminderUndo';
import { useToast } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';
import { formatDateTime } from '@/lib/datetime';
import { planForward } from '@/lib/forwardPlacement';
import type { Outbox } from '@/lib/outbox';
import { applyVote } from '@/lib/polls';
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

/**
 * Per-send hooks the composer uses to reconcile the optimistic wipe of its body
 * and attachment tray. The tray is emptied the instant a send fires, so the send
 * reports back whether that wipe should stand — it was {@see onAccepted} (posted,
 * or queued offline) — or be undone, {@see onRejected} (an online send failed and
 * the staged files must return so the user can retry without re-picking them).
 */
export interface SendCallbacks {
    /** The send was accepted (posted, or queued offline): drop the staged snapshot. */
    onAccepted?: () => void;
    /** An online send failed: restore the staged body and attachments. */
    onRejected?: () => void;
}

/**
 * Outcome hooks for a non-optimistic slash-command send. Unlike a normal
 * message the composer renders nothing up front — it keeps the typed text in a
 * pending state and reconciles here: {@see onSuccess} clears it (the command
 * ran; any posted message arrives over the realtime echo), {@see onError} keeps
 * it so the invoker can correct and resend.
 */
export interface CommandCallbacks {
    /** The command ran: clear the composer. */
    onSuccess?: () => void;
    /** The command failed (unknown args, blocked, or a transport error): keep the text. */
    onError?: () => void;
}

export interface MessageActions {
    /**
     * Send a message, optimistically, rolling the row back on error. Any
     * `attachmentIds` (pre-uploaded in the composer) are claimed by the message
     * in the same store request, in tray order. `callbacks` report the send's
     * outcome so the composer can restore a failed send's staged attachments.
     */
    send: (
        body: string,
        mentions: Mention[],
        attachmentIds?: string[],
        callbacks?: SendCallbacks,
    ) => void;
    /**
     * Post every queued send, in order, dropping each from the queue as it goes
     * and putting back the ones that fail. Resolves, once every post has
     * settled, with how many of them actually landed.
     */
    flushOutbox: () => Promise<number>;
    /**
     * Flush the queue at the user's request — the "Retry all" the failure toast
     * carries — and report the outcome on that same card: a confirmation when
     * the queue drains, or the failure card again with its new count.
     */
    retryQueuedSends: () => Promise<void>;
    /** Save an edit, optimistically, rolling the patch back on error. */
    editMessage: (message: Message, body: string) => void;
    /** Delete a message, optimistically, rolling the tombstone back on error. */
    deleteMessage: (message: Message) => void;
    /** Toggle the viewer's reaction, optimistically, rolled back on error. */
    reactToMessage: (message: Message, emoji: string) => void;
    /** Toggle the viewer's vote for a poll option, optimistically, rolled back on error. */
    voteOnPoll: (message: Message, optionId: string) => void;
    /** Close a poll, freezing its tally; the frozen state arrives over the broadcast. */
    closePoll: (message: Message) => void;
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
    /**
     * Run a slash command by posting its raw body to the server, which parses
     * and dispatches authoritatively. Non-optimistic: nothing renders until the
     * server responds. `callbacks` report the outcome so the composer clears on
     * success and preserves the typed text on failure.
     */
    sendCommand: (body: string, callbacks?: CommandCallbacks) => void;
}

/**
 * Merge identity for every toast about the offline queue. The failure card and
 * the retry's confirmation share it so the outcome swaps into the card the user
 * pressed Retry all on, instead of stacking a second one behind it.
 */
export const QUEUED_SENDS_TOAST_KEY = 'queued-sends';

/**
 * The copy a forward created, as its response flashes it. The channel is the
 * one the copy landed in rather than the one being viewed, and for a forward to
 * a person it may be a direct message that very request opened — which is why
 * the client cannot work either value out for itself.
 */
type ForwardedCopy = {
    messageId: string;
    channelSlug: string;
};

/**
 * Read the forwarded copy off a response's flash, or `null` when it carries
 * none. The payload crosses the wire, so a missing or malformed one has to
 * degrade to a confirmation with no Undo rather than to an Undo that would
 * delete nothing.
 */
function forwardedCopy(page: unknown): ForwardedCopy | null {
    const flashed = (page as { flash?: Record<string, unknown> } | undefined)
        ?.flash?.forwarded as Partial<ForwardedCopy> | undefined;

    return typeof flashed?.messageId === 'string' &&
        typeof flashed.channelSlug === 'string'
        ? { messageId: flashed.messageId, channelSlug: flashed.channelSlug }
        : null;
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
    const toast = useToast();
    const reminderUndo = useReminderUndo();
    const page = usePage();

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
        callbacks?: SendCallbacks;
        /**
         * Replaces the default rollback-and-toast when the post fails. A flushed
         * send is not a lost send — it goes back on the queue with its row
         * intact — so the flush path substitutes its own handling here.
         */
        onFailure?: () => void;
    }): Promise<void> {
        // Resolves once the request has settled either way, so a flush can wait
        // for the whole queue before reporting what became of it.
        return new Promise((resolve) => {
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
                    onSuccess: () => item.callbacks?.onAccepted?.(),
                    onError: () => {
                        if (item.onFailure) {
                            item.onFailure();

                            return;
                        }

                        // The optimistic row failed to persist; roll it back and notify.
                        options.mainStream.removePending(item.clientUuid);
                        const message = t(
                            'Your message failed to send. Please try again.',
                        );
                        toast.error(message);
                        options.onSendFailure?.(message);
                        // Hand the staged attachments back so the send is retryable.
                        item.callbacks?.onRejected?.();
                    },
                    // A cancelled visit reports through neither `onSuccess` nor
                    // `onError`, so a flushed send would otherwise leave the
                    // queue having never been posted. The live-send path keeps
                    // its existing behaviour, which is to let the composer's
                    // callbacks stand.
                    onCancel: () => item.onFailure?.(),
                    onFinish: () => resolve(),
                },
            );
        });
    }

    function send(
        body: string,
        mentions: Mention[],
        attachmentIds: string[] = [],
        callbacks: SendCallbacks = {},
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
            // A live send reports itself through `callbacks`; nothing here waits
            // on it, unlike a flush, which needs the whole queue to settle.
            void postMessage({
                clientUuid,
                body,
                replyToId,
                attachmentIds,
                callbacks,
            });

            return;
        }

        // Offline: hold the send locally (the row shows as queued) and flush it
        // when the connection recovers, rather than failing it outright. Clear the
        // saved draft now, since the store endpoint that normally does so won't be
        // reached until flush — otherwise a refresh would repopulate the composer.
        options.outbox.enqueue({ clientUuid, body, replyToId, attachmentIds });
        options.clearDraft();
        // The queue now owns the attachment ids and re-sends them on flush, so
        // the composer's staged copies are safe to drop just as a live send would.
        callbacks.onAccepted?.();
    }

    /**
     * Report that the queue did not fully drain. Every failure in a flush calls
     * this, and they merge onto one card under {@link QUEUED_SENDS_TOAST_KEY};
     * the count is read off the queue at announce time rather than tallied per
     * failure, so ten failed sends read "10 messages didn't send" once instead
     * of stacking ten identical toasts.
     */
    function announceQueuedSends(): void {
        const queued = options.outbox.count.value;

        toast.error(
            queued === 1
                ? t("1 message didn't send")
                : t(":count messages didn't send", { count: queued }),
            {
                key: QUEUED_SENDS_TOAST_KEY,
                action: {
                    label: t('Retry all'),
                    run: () => void retryQueuedSends(),
                },
            },
        );
    }

    async function flushOutbox(): Promise<number> {
        // Snapshot first: `postMessage` never mutates the queue, but draining as
        // we go keeps the queued-row markers clearing in send order.
        const queued = [...options.outbox.items.value];
        // Counted from the posts rather than from how the queue's length moved:
        // a send made while the flush is in flight would skew that difference.
        let landed = 0;

        for (const item of queued) {
            options.outbox.remove(item.clientUuid);

            let failed = false;

            // Awaited before the next one goes out, so a queued conversation
            // reaches the server in the order it was typed rather than in
            // whatever order concurrent requests happen to land.
            await postMessage({
                ...item,
                // A flush that fails leaves the send exactly where it was —
                // queued, with its row still marked as such — so the user can
                // retry the whole queue rather than losing it a message at a time.
                onFailure: () => {
                    failed = true;
                    options.outbox.enqueue(item);
                    announceQueuedSends();
                },
            });

            if (!failed) {
                landed += 1;
            }
        }

        return landed;
    }

    async function retryQueuedSends(): Promise<void> {
        const retrying = options.outbox.count.value;

        if (retrying === 0) {
            return;
        }

        // Posting into a connection that is still down would drain the queue on
        // a request that never lands. Say so instead, and leave Retry all for
        // when it can do something.
        if (!options.isOnline()) {
            announceQueuedSends();

            return;
        }

        const sent = await flushOutbox();

        // Anything still queued has already re-raised the failure card with its
        // new count, so the only outcome left to report is a clean drain.
        if (options.outbox.count.value > 0) {
            return;
        }

        toast.success(
            sent === 1
                ? t('Queued message sent')
                : t(':count queued messages sent', { count: sent }),
            { key: QUEUED_SENDS_TOAST_KEY },
        );
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
                    toast.error(t('Your edit failed to save'));
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
                    toast.error(t('Failed to delete the message'));
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
                    toast.error(t('Failed to update the reaction'));
                },
            },
        );
    }

    /**
     * Toggle the viewer's vote for a poll option. The tally is applied
     * optimistically to both streams (via the pure {@see applyVote}) and rolled
     * back on error; the authoritative tally reaches every client — including this
     * one — over the `PollVoteChanged` broadcast. A no-op when the message carries
     * no poll.
     */
    function voteOnPoll(message: Message, optionId: string): void {
        if (message.poll === null) {
            return;
        }

        const channel = options.channel();
        const previousMain = options.mainStream.getPatch(message.clientUuid);
        const previousThread = options.threadStream.getPatch(
            message.clientUuid,
        );

        const next = applyVote(message.poll, optionId, options.currentUser());
        options.mainStream.patchThreadState(message.id, { poll: next });
        options.threadStream.patchThreadState(message.id, { poll: next });

        router.post(
            voteOnPollAction({
                team: options.teamSlug(),
                channel: channel.slug,
                poll: message.poll.id,
            }).url,
            { option_id: optionId },
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
                    toast.error(t('Failed to record your vote'));
                },
            },
        );
    }

    /**
     * Close a poll, freezing its tally. Non-optimistic: the frozen state reaches
     * every client — including this one — over the `PollVoteChanged` broadcast, so
     * nothing is patched locally up front. A no-op when the message carries no poll.
     */
    function closePoll(message: Message): void {
        if (message.poll === null) {
            return;
        }

        const channel = options.channel();

        router.post(
            closePollAction({
                team: options.teamSlug(),
                channel: channel.slug,
                poll: message.poll.id,
            }).url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['channels'],
                onError: () => {
                    toast.error(t('Failed to close the poll'));
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
                    toast.error(t('Failed to unpin the message'));
                },
            },
        );
    }

    /**
     * Delete the forwarded copy where it landed. The destroy route redirects to
     * the deleted message's own channel — the destination, which is the one
     * place the sender was deliberately not taken — so the visit keeps the URL
     * and takes back only the sidebar, leaving them where they were.
     *
     * Authorization is the ordinary message-delete policy: an Undo the sender is
     * no longer entitled to is refused exactly as a normal delete would be.
     */
    function undoForward(copy: ForwardedCopy): void {
        router.delete(
            destroyMessage({
                team: options.teamSlug(),
                channel: copy.channelSlug,
                message: copy.messageId,
            }).url,
            {
                preserveScroll: true,
                preserveState: true,
                preserveUrl: true,
                only: ['channels'],
                onError: () => toast.error(t('Failed to undo the forward')),
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
                onSuccess: (page) => {
                    if (plan.toCurrentChannel) {
                        return;
                    }

                    const copy = forwardedCopy(page);

                    toast.success(
                        target.kind === 'channel'
                            ? t('Message forwarded to #:channel', {
                                  channel: target.name,
                              })
                            : t('Message forwarded to :name', {
                                  name: target.name,
                              }),
                        copy
                            ? {
                                  action: {
                                      label: t('Undo'),
                                      run: () => undoForward(copy),
                                  },
                              }
                            : {},
                    );
                },
                onError: () => {
                    if (plan.toCurrentChannel) {
                        options.mainStream.removePending(clientUuid);
                    }

                    toast.error(t('Failed to forward the message'));
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

                    toast.error(t('Your reply failed to send'));
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
        // Minted here rather than server-side so Undo can point at exactly the
        // row this send created. Scheduling is always a create, so cancelling
        // that row is a true inverse — but "the newest by createdAt" stops being
        // that row the moment two schedules land in the same second (#978).
        const clientUuid = generateUuid();

        router.post(
            storeScheduledMessage({
                team: options.teamSlug(),
                channel: channel.slug,
            }).url,
            {
                body,
                client_uuid: clientUuid,
                reply_to_id: target?.id ?? null,
                send_at: sendAt,
            },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['scheduledMessages', 'channels'],
                onSuccess: () =>
                    toast.success(t('Message scheduled'), {
                        // The value that was just set belongs on the detail
                        // line, not in the title (#978).
                        detail: formatDateTime(sendAt),
                        action: {
                            label: t('Undo'),
                            run: () => cancelScheduledByClientUuid(clientUuid),
                        },
                    }),
                onError: () =>
                    toast.error(t('Failed to schedule your message')),
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
                    toast.error(t('Failed to update the scheduled message')),
            },
        );
    }

    /**
     * Undo a just-scheduled message: cancel the row carrying the client id this
     * send minted. Silent when it cannot be found — the schedule may already
     * have been sent or cancelled, and there is nothing sensible to guess at.
     */
    function cancelScheduledByClientUuid(clientUuid: string): void {
        const scheduled = (page.props.scheduledMessages ??
            []) as App.Data.ScheduledMessageData[];
        const created = scheduled.find((row) => row.clientUuid === clientUuid);

        if (created) {
            cancelScheduled(created.id);
        }
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
                    toast.success(t('Scheduled message cancelled')),
                onError: () =>
                    toast.error(t('Failed to cancel the scheduled message')),
            },
        );
    }

    function sendCommand(body: string, callbacks: CommandCallbacks = {}): void {
        // A command send clears the draft server-side (a `postMessage` result
        // posts through the normal path), so drop any debounced save in flight.
        options.cancelDraft();

        router.post(
            storeCommand({
                team: options.teamSlug(),
                channel: options.channel().slug,
            }).url,
            { body, client_uuid: generateUuid() },
            {
                preserveScroll: true,
                // Not optimistic: no pending row to reconcile. A `postMessage`
                // result arrives over the realtime echo like any other message;
                // a `notice` surfaces via the flash-toast bridge.
                onSuccess: () => callbacks.onSuccess?.(),
                onError: (errors: Record<string, string>) => {
                    // A command error (or a blocked authorize) comes back as a
                    // `command` validation message; a transport failure has none.
                    toast.error(
                        errors.command ??
                            t(
                                'That command could not be run. Please try again.',
                            ),
                    );
                    callbacks.onError?.();
                },
            },
        );
    }

    function setReminder(messageId: string, remindAt: string): void {
        // Taken before the write: setting a reminder on a message that already
        // had one re-arms that row, so Undo has to put the old time back rather
        // than delete it. See {@see useReminderUndo}.
        const previous = reminderUndo.snapshot(messageId);

        router.post(
            remindMessage({ team: options.teamSlug() }).url,
            { message_id: messageId, remind_at: remindAt },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['reminders', 'firedReminders'],
                onSuccess: () =>
                    toast.success(t('Reminder set'), {
                        detail: formatDateTime(remindAt),
                        // Merged per message, so setting a reminder twice
                        // replaces the first toast rather than leaving its Undo
                        // on screen holding a now-stale snapshot.
                        key: `reminder:${messageId}`,
                        action: {
                            label: t('Undo'),
                            run: () =>
                                reminderUndo.undo(
                                    options.teamSlug(),
                                    messageId,
                                    previous,
                                ),
                        },
                    }),
                onError: () => toast.error(t('Failed to set the reminder')),
            },
        );
    }

    return {
        send,
        flushOutbox,
        retryQueuedSends,
        editMessage,
        deleteMessage,
        reactToMessage,
        voteOnPoll,
        closePoll,
        pinMessage,
        unpinMessage,
        forwardMessage,
        sendThreadReply,
        scheduleMessage,
        updateScheduled,
        cancelScheduled,
        setReminder,
        sendCommand,
    };
}
