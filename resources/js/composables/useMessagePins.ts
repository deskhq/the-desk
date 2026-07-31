import {
    destroy as unpinMessageAction,
    store as pinMessageAction,
} from '@/actions/App/Http/Controllers/Channels/PinController';
import type { MessageActionsOptions } from '@/composables/useMessageActions';
import {
    snapshotStreams,
    useOptimisticWrite,
} from '@/composables/useOptimisticWrite';
import { useTranslations } from '@/composables/useTranslations';
import { PIN_PROPS } from '@/lib/reloadProps';
import type { Message, MessagePin } from '@/types';

export interface MessagePins {
    /** Pin a message to its channel, optimistically, rolled back on error. */
    pinMessage: (message: Message) => void;
    /** Unpin a message from its channel, optimistically, rolled back on error. */
    unpinMessage: (message: Message) => void;
}

export type MessagePinsOptions = Pick<
    MessageActionsOptions,
    'teamSlug' | 'channel' | 'currentUser' | 'mainStream' | 'threadStream'
>;

/** The channel's shared pin toggle, from either side. */
export function useMessagePins(options: MessagePinsOptions): MessagePins {
    const { t } = useTranslations();
    const { write } = useOptimisticWrite();

    /**
     * Pin a message to its channel. The indicator is applied optimistically to
     * both streams and rolled back on error; the masthead count and pins panel
     * reconcile from the refreshed `pinCount`/`pins` props the request returns,
     * and every other client patches over the `MessagePinned` broadcast. The
     * server-side cap error surfaces as its own toast.
     */
    function pinMessage(message: Message): void {
        const channel = options.channel();
        const optimisticPin: MessagePin = {
            pinnedBy: options.currentUser(),
            pinnedAt: new Date().toISOString(),
        };

        write({
            capture: () =>
                snapshotStreams(
                    message.clientUuid,
                    options.mainStream,
                    options.threadStream,
                ),
            apply: () => {
                options.mainStream.patchPin(message.id, optimisticPin);
                options.threadStream.patchPin(message.id, optimisticPin);
            },
            url: pinMessageAction({
                team: options.teamSlug(),
                channel: channel.slug,
                message: message.id,
            }).url,
            only: PIN_PROPS,
            failure: (errors) =>
                errors.message ??
                t('Failed to pin the message. Please try again.'),
        });
    }

    /**
     * Unpin a message from its channel — a shared toggle any member may perform.
     * Mirrors {@see pinMessage}: optimistic removal of the indicator, rolled back
     * on error, with the count and panel reconciling from the returned props.
     */
    function unpinMessage(message: Message): void {
        const channel = options.channel();

        write({
            capture: () =>
                snapshotStreams(
                    message.clientUuid,
                    options.mainStream,
                    options.threadStream,
                ),
            apply: () => {
                options.mainStream.patchPin(message.id, null);
                options.threadStream.patchPin(message.id, null);
            },
            method: 'delete',
            url: unpinMessageAction({
                team: options.teamSlug(),
                channel: channel.slug,
                message: message.id,
            }).url,
            only: PIN_PROPS,
            failure: t('Failed to unpin the message'),
        });
    }

    return { pinMessage, unpinMessage };
}
