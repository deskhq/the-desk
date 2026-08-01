import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import type { ComposerField } from '@/composables/useComposerField';
import type { PickerCommand } from '@/composables/useComposerSlashCommands';
import type { AttachmentData } from '@/types/attachments';

/**
 * The `/gif` command, with an optional search term (`/gif cats`). The one
 * spelling of what the picker answers to, read both when the command is chosen
 * from the menu and when it is typed out and sent.
 */
const GIF_COMMAND = /^\/gif(?:\s+(.*))?$/i;

export type ComposerGifPicker = {
    /** Whether the picker is up. */
    open: Ref<boolean>;
    /** The search term it opened on. */
    query: Ref<string>;
    /** Whether the picker is configured and applies to this composer. */
    available: ComputedRef<boolean>;
    /** The `/gif` command, or null while the picker is unavailable. */
    command: ComputedRef<PickerCommand | null>;
    /** Open the picker on a search term, leaving the body alone. */
    openPicker: (query: string) => void;
    close: () => void;
    onSelected: (attachment: AttachmentData) => void;
};

/**
 * The composer's Giphy picker: when `/gif` opens it rather than posting text,
 * and what a picked GIF does to the attachment tray.
 */
export function useComposerGifPicker(options: {
    field: ComposerField;
    /** Whether the Giphy picker is configured for this instance. */
    gifPickerEnabled: () => boolean;
    /** Whether the composer knows its channel, so a picked GIF can be staged. */
    attachmentsEnabled: () => boolean;
    /** Whether an existing message is being edited (the picker does not apply then). */
    isEditing: () => boolean;
    /** Dismiss the slash menu, which the picker opens over. */
    closeSlashMenu: () => void;
    /** Stage a picked GIF in the attachment tray; false when the tray refused it. */
    stageRemote: (attachment: AttachmentData) => boolean;
}): ComposerGifPicker {
    const { body, focus } = options.field;

    const open = ref(false);
    const query = ref('');

    /**
     * The picker is usable only when configured, when the composer knows its
     * channel (a picked GIF is staged as an attachment on that channel), and while
     * composing a new message — not editing an existing one (an inline edit saves
     * text only and cannot carry an attachment).
     */
    const available = computed(
        () =>
            options.gifPickerEnabled() &&
            options.attachmentsEnabled() &&
            !options.isEditing(),
    );

    /**
     * Open the picker on the given search term, leaving the body alone: the
     * picker is also reachable from the mobile attach sheet, where whatever is
     * typed is a message the user is part-way through rather than a command.
     */
    function openPicker(term: string): void {
        options.closeSlashMenu();
        query.value = term;
        open.value = true;
    }

    /**
     * The `/gif` command path: the typed text *is* the command, so it goes once
     * the picker it asked for is up.
     */
    function openFromCommand(text: string): void {
        openPicker(text.match(GIF_COMMAND)?.[1]?.trim() ?? '');
        body.value = '';
    }

    const command = computed<PickerCommand | null>(() =>
        available.value
            ? {
                  name: 'gif',
                  claims: (text) => GIF_COMMAND.test(text),
                  open: openFromCommand,
              }
            : null,
    );

    function close(): void {
        open.value = false;
        focus();
    }

    /**
     * A picked GIF joins the tray as a remote attachment; the picker closes only if
     * it was accepted, so a full tray keeps the picker open with its toast shown.
     */
    function onSelected(attachment: AttachmentData): void {
        if (options.stageRemote(attachment)) {
            close();
        }
    }

    return { open, query, available, command, openPicker, close, onSelected };
}
