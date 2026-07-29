import { router } from '@inertiajs/vue3';
import { nextTick } from 'vue';
import { store as storeMessage } from '@/actions/App/Http/Controllers/Channels/MessageController';
import { store as storeCommand } from '@/actions/App/Http/Controllers/Channels/SlashCommandController';
import type { MessageActionsOptions } from '@/composables/useMessageActions';
import { optimisticMessage } from '@/composables/useMessageStream';
import { useToast } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';
import { generateUuid } from '@/lib/uuid';
import type { Mention } from '@/types';

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

export interface MessageSends {
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
    /**
     * Run a slash command by posting its raw body to the server, which parses
     * and dispatches authoritatively. Non-optimistic: nothing renders until the
     * server responds. `callbacks` report the outcome so the composer clears on
     * success and preserves the typed text on failure.
     */
    sendCommand: (body: string, callbacks?: CommandCallbacks) => void;
}

export type MessageSendsOptions = Pick<
    MessageActionsOptions,
    | 'teamSlug'
    | 'channel'
    | 'currentUser'
    | 'mainStream'
    | 'replyTarget'
    | 'scrollToBottom'
    | 'cancelDraft'
    | 'clearDraft'
    | 'cancelReply'
    | 'isOnline'
    | 'outbox'
    | 'onSendFailure'
>;

/**
 * Merge identity for every toast about the offline queue. The failure card and
 * the retry's confirmation share it so the outcome swaps into the card the user
 * pressed Retry all on, instead of stacking a second one behind it.
 */
export const QUEUED_SENDS_TOAST_KEY = 'queued-sends';

/**
 * Everything that leaves the composer as a new channel message: the optimistic
 * send, the offline queue it falls back to and the retry that drains it, and
 * the non-optimistic slash-command send that shares its draft handling.
 */
export function useMessageSends(options: MessageSendsOptions): MessageSends {
    const { t } = useTranslations();
    const toast = useToast();

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

    return { send, flushOutbox, retryQueuedSends, sendCommand };
}
