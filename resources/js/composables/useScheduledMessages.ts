import { router, usePage } from '@inertiajs/vue3';
import {
    destroy as destroyScheduledMessage,
    store as storeScheduledMessage,
    update as updateScheduledMessage,
} from '@/actions/App/Http/Controllers/Channels/ScheduledMessageController';
import type { MessageActionsOptions } from '@/composables/useMessageActions';
import { useToast } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';
import { formatDateTime } from '@/lib/datetime';
import { CHANNEL_LIST_PROPS, SCHEDULED_MESSAGE_PROPS } from '@/lib/reloadProps';
import { generateUuid } from '@/lib/uuid';
import type { Mention } from '@/types';

export interface ScheduledMessages {
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
}

export type ScheduledMessagesOptions = Pick<
    MessageActionsOptions,
    'teamSlug' | 'channel' | 'replyTarget' | 'cancelDraft' | 'cancelReply'
>;

/**
 * The composer's later-delivery surface: creating a schedule, editing a pending
 * one, and cancelling it — including the Undo the confirmation carries, which
 * has to find the row the send just created rather than guess at the newest.
 */
export function useScheduledMessages(
    options: ScheduledMessagesOptions,
): ScheduledMessages {
    const { t } = useTranslations();
    const toast = useToast();
    const page = usePage();

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
                only: [...SCHEDULED_MESSAGE_PROPS, ...CHANNEL_LIST_PROPS],
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
                only: SCHEDULED_MESSAGE_PROPS,
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
                only: SCHEDULED_MESSAGE_PROPS,
                onSuccess: () =>
                    toast.success(t('Scheduled message cancelled')),
                onError: () =>
                    toast.error(t('Failed to cancel the scheduled message')),
            },
        );
    }

    return { scheduleMessage, updateScheduled, cancelScheduled };
}
