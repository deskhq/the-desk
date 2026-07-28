import { router } from '@inertiajs/vue3';
import {
    destroy as unpinMessageAction,
    store as pinMessageAction,
} from '@/actions/App/Http/Controllers/Channels/PinController';
import type { MessageActionsOptions } from '@/composables/useMessageActions';
import { useToast } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';
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
    const toast = useToast();

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

    return { pinMessage, unpinMessage };
}
