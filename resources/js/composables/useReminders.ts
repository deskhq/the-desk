import { router } from '@inertiajs/vue3';
import { show } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import {
    destroy as destroyReminder,
    destroyAll as destroyAllReminders,
    store as storeReminder,
} from '@/actions/App/Http/Controllers/Channels/MessageReminderController';
import { useReminderUndo } from '@/composables/useReminderUndo';
import { useToast } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';
import { formatDateTime } from '@/lib/datetime';
import { reminderReload } from '@/lib/reminderReload';
import type { MessageReminder } from '@/types/messages';

/**
 * How far a snooze pushes a fired reminder out before it comes due again. The
 * confirmation toast interpolates it rather than spelling it out, so changing
 * the span cannot leave the card promising a different one.
 */
const SNOOZE_MINUTES = 20;

/** The copy a successful write confirms itself with, already translated. */
type Confirmation = {
    title: string;
    detail: string;
    failure: string;
};

export interface Reminders {
    /** Set (or re-arm) the viewer's reminder on a message at a chosen instant. */
    set: (teamSlug: string, messageId: string, remindAt: string) => void;
    /** Push a fired reminder out by {@link SNOOZE_MINUTES}, back to pending. */
    snooze: (reminder: MessageReminder) => void;
    /** Clear one reminder — an acknowledged nudge, or a pending row from the list. */
    clear: (reminder: Pick<MessageReminder, 'id' | 'teamSlug'>) => void;
    /** Clear every pending reminder in a workspace at once. */
    clearAll: (teamSlug: string) => void;
    /** Jump to the reminded message, clearing its acknowledged nudge on the way. */
    open: (reminder: MessageReminder) => void;
}

/**
 * Everything the viewer can do to their own message reminders, behind one
 * interface.
 *
 * The five operations are three shapes, and the point of the module is that each
 * shape is written once. Set and snooze are the *same write* — an
 * `updateOrCreate` on `(user_id, message_id)` — differing only in which instant
 * they post and what they say afterwards, so they share the snapshot → post →
 * toast-with-Undo triple below rather than each carrying a copy of it. Clear and
 * clear-all are plain deletes. Open is a delete with a jump on its tail.
 *
 * All five refresh the same two props ({@see reminderReload}) — the list, the
 * rail's dot and the nudges read from them and would otherwise disagree — and
 * all five leave the page they were called from in place, because none of them
 * is a navigation the user asked for.
 *
 * Undo is {@see useReminderUndo}'s: reversing a set means putting back whatever
 * time the message was already at, which only the client knows.
 */
export function useReminders(): Reminders {
    const { t } = useTranslations();
    const toast = useToast();
    const reminderUndo = useReminderUndo();

    /**
     * The write behind both set and snooze, with its Undo.
     *
     * The snapshot is taken *before* the post: the endpoint answers `back()`
     * without saying whether it created a row or re-armed one, so the inverse
     * has to be read while the old value is still there. Both operations toast
     * under `reminder:${messageId}`, so setting and then snoozing the same
     * message replaces the first card instead of leaving its now-stale Undo up.
     */
    function remind(
        teamSlug: string,
        messageId: string,
        remindAt: string,
        confirmation: Confirmation,
    ): void {
        const previous = reminderUndo.snapshot(messageId);

        router.post(
            storeReminder({ team: teamSlug }).url,
            { message_id: messageId, remind_at: remindAt },
            {
                ...reminderReload,
                onSuccess: () =>
                    toast.success(confirmation.title, {
                        detail: confirmation.detail,
                        key: `reminder:${messageId}`,
                        action: {
                            label: t('Undo'),
                            run: () =>
                                reminderUndo.undo(
                                    teamSlug,
                                    messageId,
                                    previous,
                                ),
                        },
                    }),
                onError: () => toast.error(confirmation.failure),
            },
        );
    }

    function set(teamSlug: string, messageId: string, remindAt: string): void {
        remind(teamSlug, messageId, remindAt, {
            title: t('Reminder set'),
            detail: formatDateTime(remindAt),
            failure: t('Failed to set the reminder'),
        });
    }

    function snooze(reminder: MessageReminder): void {
        remind(
            reminder.teamSlug,
            reminder.messageId,
            new Date(Date.now() + SNOOZE_MINUTES * 60_000).toISOString(),
            {
                title: t('Reminder snoozed'),
                detail: t('For :count minutes', {
                    count: SNOOZE_MINUTES,
                }),
                failure: t('Failed to snooze the reminder'),
            },
        );
    }

    function clear(reminder: Pick<MessageReminder, 'id' | 'teamSlug'>): void {
        router.delete(
            destroyReminder({ team: reminder.teamSlug, reminder: reminder.id })
                .url,
            reminderReload,
        );
    }

    function clearAll(teamSlug: string): void {
        router.delete(
            destroyAllReminders({ team: teamSlug }).url,
            reminderReload,
        );
    }

    function open(reminder: MessageReminder): void {
        const target = show(
            { team: reminder.teamSlug, channel: reminder.channelSlug },
            { query: { message: reminder.messageId } },
        ).url;

        // Cleared first, so the page the jump lands on no longer carries the
        // fired reminder and cannot re-raise the nudge behind the reader.
        router.delete(
            destroyReminder({ team: reminder.teamSlug, reminder: reminder.id })
                .url,
            {
                ...reminderReload,
                onFinish: () => router.visit(target),
            },
        );
    }

    return { set, snooze, clear, clearAll, open };
}
