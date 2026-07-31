import type { MessageActionsOptions } from '@/composables/useMessageActions';
import { useReminders } from '@/composables/useReminders';

export interface ReminderActions {
    /** Set (or re-arm) a personal reminder on a message at a chosen instant. */
    setReminder: (messageId: string, remindAt: string) => void;
}

export type ReminderActionsOptions = Pick<MessageActionsOptions, 'teamSlug'>;

/**
 * The message-actions adapter over {@see useReminders}.
 *
 * A reminder is the one action in {@see useMessageActions} that is a
 * workspace-level write rather than a channel one, and the same write is reached
 * from the shell as well (the nudges' snooze) — so the operation, its Undo and
 * its toast live in the facade, and all this supplies is the team the
 * composition root already carries.
 */
export function useReminderActions(
    options: ReminderActionsOptions,
): ReminderActions {
    const reminders = useReminders();

    return {
        setReminder: (messageId: string, remindAt: string): void =>
            reminders.set(options.teamSlug(), messageId, remindAt),
    };
}
