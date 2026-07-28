import { router } from '@inertiajs/vue3';
import { store as remindMessage } from '@/actions/App/Http/Controllers/Channels/MessageReminderController';
import type { MessageActionsOptions } from '@/composables/useMessageActions';
import { useReminderUndo } from '@/composables/useReminderUndo';
import { useToast } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';
import { formatDateTime } from '@/lib/datetime';

export interface ReminderActions {
    /** Set (or re-arm) a personal reminder on a message at a chosen instant. */
    setReminder: (messageId: string, remindAt: string) => void;
}

export type ReminderActionsOptions = Pick<MessageActionsOptions, 'teamSlug'>;

/**
 * The viewer's personal reminder on a message. Unlike every other action here
 * it is a workspace-level write rather than a channel one, and its Undo has to
 * put back whatever the message was already set to — see {@see useReminderUndo}.
 */
export function useReminderActions(
    options: ReminderActionsOptions,
): ReminderActions {
    const { t } = useTranslations();
    const toast = useToast();
    const reminderUndo = useReminderUndo();

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

    return { setReminder };
}
