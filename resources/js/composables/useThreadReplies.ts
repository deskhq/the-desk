import { router } from '@inertiajs/vue3';
import { store as storeMessage } from '@/actions/App/Http/Controllers/Channels/MessageController';
import type { MessageActionsOptions } from '@/composables/useMessageActions';
import { optimisticMessage } from '@/composables/useMessageStream';
import { useToast } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';
import { generateUuid } from '@/lib/uuid';
import type { Mention, Message } from '@/types';

export interface ThreadReplies {
    /** Post a reply into the open thread, optionally echoed to the channel. */
    sendThreadReply: (
        body: string,
        mentions: Mention[],
        sendToChannel?: boolean,
    ) => void;
}

export type ThreadRepliesOptions = Pick<
    MessageActionsOptions,
    | 'teamSlug'
    | 'channel'
    | 'currentUser'
    | 'mainStream'
    | 'threadStream'
    | 'activeThreadRootId'
> & {
    /** Add an optimistic row to the main timeline, honouring the pin-to-bottom rule. */
    appendPendingMain: (message: Message) => void;
};

/**
 * The send that belongs to the open thread panel rather than to the channel: it
 * writes into the thread's own stream, and echoes into the main timeline only
 * when the reply is deliberately shared with the channel.
 */
export function useThreadReplies(options: ThreadRepliesOptions): ThreadReplies {
    const { t } = useTranslations();
    const toast = useToast();

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
            options.appendPendingMain(optimistic);
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

    return { sendThreadReply };
}
