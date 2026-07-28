import { ref } from 'vue';
import type { Ref } from 'vue';
import type { Message } from '@/types';

export interface ReminderPickerOptions {
    /** The actions engine's reminder, run for both a preset and a custom time. */
    setReminder: (messageId: string, remindAt: string) => void;
}

export interface ReminderPicker {
    /** Whether the custom date & time picker is open. */
    reminderCustomOpen: Ref<boolean>;
    remindWith: (message: Message, remindAt: string) => void;
    openCustomReminder: (message: Message) => void;
    confirmCustomReminder: (remindAt: string) => void;
}

/**
 * Reminders raised from a message's popover: a preset time is set straight away,
 * while "Custom date & time…" holds the target until the picker confirms.
 */
export function useReminderPicker(
    options: ReminderPickerOptions,
): ReminderPicker {
    /** The message a custom-time reminder is being set for. */
    const reminderTargetId = ref<string | null>(null);
    const reminderCustomOpen = ref(false);

    /** A preset was chosen from a message's reminder popover. */
    function remindWith(message: Message, remindAt: string): void {
        options.setReminder(message.id, remindAt);
    }

    /** The viewer chose "Custom date & time…"; remember the target and open the picker. */
    function openCustomReminder(message: Message): void {
        reminderTargetId.value = message.id;
        reminderCustomOpen.value = true;
    }

    /** Confirm the custom reminder time picked in the dialog. */
    function confirmCustomReminder(remindAt: string): void {
        if (reminderTargetId.value === null) {
            return;
        }

        options.setReminder(reminderTargetId.value, remindAt);
        reminderTargetId.value = null;
    }

    return {
        reminderCustomOpen,
        remindWith,
        openCustomReminder,
        confirmCustomReminder,
    };
}
