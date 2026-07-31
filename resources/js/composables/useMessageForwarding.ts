import { router } from '@inertiajs/vue3';
import { store as forwardMessageAction } from '@/actions/App/Http/Controllers/Channels/ForwardMessageController';
import { destroy as destroyMessage } from '@/actions/App/Http/Controllers/Channels/MessageController';
import type { MessageActionsOptions } from '@/composables/useMessageActions';
import { optimisticMessage } from '@/composables/useMessageStream';
import { useOptimisticWrite } from '@/composables/useOptimisticWrite';
import { useToast } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';
import { planForward } from '@/lib/forwardPlacement';
import { CHANNEL_LIST_PROPS } from '@/lib/reloadProps';
import { generateUuid } from '@/lib/uuid';
import type { Message } from '@/types';
import type { ForwardTarget } from '@/types/forward';

export interface MessageForwarding {
    /** Forward a message to a channel or a person, optimistic to the current channel. */
    forwardMessage: (
        source: Message,
        payload: { target: ForwardTarget; note: string },
    ) => void;
}

export type MessageForwardingOptions = Pick<
    MessageActionsOptions,
    'teamSlug' | 'channel' | 'currentUser' | 'mainStream'
> & {
    /** Add an optimistic row to the main timeline, honouring the pin-to-bottom rule. */
    appendPendingMain: (message: Message) => void;
};

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
 * Forwarding a message onward, and taking it back. Where the copy lands decides
 * how it is reported: into the open channel it renders optimistically and stays
 * silent, elsewhere it confirms with a toast carrying an Undo.
 *
 * The pure placement decision (current-channel target, DM quote naming) lives in
 * {@see planForward}; the optimistic row factory is the shared
 * {@see optimisticMessage}.
 */
export function useMessageForwarding(
    options: MessageForwardingOptions,
): MessageForwarding {
    const { t } = useTranslations();
    const toast = useToast();
    const { write } = useOptimisticWrite();

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
                only: CHANNEL_LIST_PROPS,
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

        /**
         * The rollback. A copy only renders here when it lands in the open
         * channel, so a forward sent elsewhere has nothing to take back and
         * this is a no-op for it.
         */
        function dropOptimisticCopy(): void {
            if (plan.toCurrentChannel) {
                options.mainStream.removePending(clientUuid);
            }
        }

        write({
            capture: () => dropOptimisticCopy,
            apply: () => {
                if (!plan.toCurrentChannel) {
                    return;
                }

                options.appendPendingMain(
                    optimisticMessage({
                        clientUuid,
                        body: note,
                        author: options.currentUser(),
                        mentions: [],
                        forwardedFrom: {
                            id: source.id,
                            body: source.body,
                            authorName: source.user.name,
                            authorIsBot: source.user.isBot,
                            authorOverride: source.authorOverride ?? null,
                            channelName: plan.quoteChannelName,
                            isDeleted: source.isDeleted,
                            mentions: source.mentions,
                        },
                    }),
                );
            },
            url: forwardMessageAction({
                team: options.teamSlug(),
                channel: channel.slug,
                message: source.id,
            }).url,
            data: { body: note, client_uuid: clientUuid, ...plan.destination },
            only: CHANNEL_LIST_PROPS,
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
            failure: t('Failed to forward the message'),
        });
    }

    return { forwardMessage };
}
