import { router, usePage } from '@inertiajs/vue3';
import {
    destroy as destroyReminder,
    store as storeReminder,
} from '@/actions/App/Http/Controllers/Channels/MessageReminderController';
import { backgroundVisit } from '@/lib/backgroundVisit';
import { REMINDER_PROPS } from '@/lib/reminderReload';

/**
 * Whatever reminder a message already had, taken before a set or a snooze
 * overwrites it. Null when the message had none, which makes the set a create.
 */
export type ReminderSnapshot = { remindAt: string } | null;

/**
 * The inverse of setting a message reminder.
 *
 * `SetMessageReminder` is an `updateOrCreate` on `(user_id, message_id)`: a user
 * keeps at most one reminder per message, so setting one is sometimes a create
 * and sometimes a re-arm of a time they already had, and the endpoint returns
 * `back()` without saying which. Undoing by deleting would therefore destroy a
 * reminder the user never asked to lose — so the previous value is snapshotted
 * here, client-side, before the write. Snooze posts to the same endpoint and
 * needs exactly the same inverse.
 *
 * Accepted risk, stated so it is not rediscovered as a bug: this leaves the
 * client holding inverse-operation knowledge, which can drift from the server's
 * model. If that ever bites, the fix is to have the endpoint return what it
 * replaced (#978).
 */
export function useReminderUndo() {
    const page = usePage();

    function rowFor(messageId: string) {
        return (page.props.reminders ?? []).find(
            (reminder) => reminder.messageId === messageId,
        );
    }

    /** Read what this message was already set to, before overwriting it. */
    function snapshot(messageId: string): ReminderSnapshot {
        const existing = rowFor(messageId);

        return existing ? { remindAt: existing.remindAt } : null;
    }

    function undo(
        teamSlug: string,
        messageId: string,
        previous: ReminderSnapshot,
    ): void {
        if (previous) {
            router.post(
                storeReminder({ team: teamSlug }).url,
                { message_id: messageId, remind_at: previous.remindAt },
                {
                    ...backgroundVisit,
                    preserveScroll: true,
                    preserveState: true,
                    only: REMINDER_PROPS,
                },
            );

            return;
        }

        const created = rowFor(messageId);

        // Nothing to reverse rather than a guess: without the created row there
        // is no id to delete, and deleting by anything else risks the wrong one.
        if (!created) {
            return;
        }

        router.delete(
            destroyReminder({ team: teamSlug, reminder: created.id }).url,
            {
                ...backgroundVisit,
                preserveScroll: true,
                preserveState: true,
                only: REMINDER_PROPS,
            },
        );
    }

    return { snapshot, undo };
}
