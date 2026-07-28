import { nextTick, ref } from 'vue';
import type { Ref } from 'vue';
import type { ComposerField } from '@/composables/useComposerField';
import { resolveComposerEditTarget } from '@/lib/composerEdit';
import type { Message } from '@/types';

export type ComposerEditMode = {
    editingMessage: Ref<Message | null>;
    enterEditMode: (message: Message) => void;
    exitEditMode: () => void;
    tryEditLastMessage: () => boolean;
    saveEdit: () => void;
};

/**
 * The composer's inline edit mode: recalling the viewer's last editable message
 * into the field ("↑ to edit last message"), saving the correction through the
 * shared PATCH path, and leaving again. While it is on, the body is scoped to
 * that message rather than being a new-message draft.
 */
export function useComposerEditMode(options: {
    field: ComposerField;
    /** The surface's messages, oldest first, the recall resolves against. */
    messages: () => Message[];
    /** The viewer's id, deciding which of `messages` they may edit. */
    currentUserId: () => string;
    /** Client uuids of in-flight optimistic sends, skipped when resolving. */
    pendingUuids: () => string[] | undefined;
    /** Close the mention menu, which never survives an entry or an exit. */
    closeMenu: () => void;
    /**
     * Flag the next body change as programmatic, so the wipe that leaves edit
     * mode is not persisted as a channel draft.
     */
    suppressNextDraftChange: (suppress: boolean) => void;
    /** Announce that the composer entered (message id) or left (null) edit mode. */
    onEditingChange: (messageId: string | null) => void;
    /** Save the correction through the same PATCH path the inline editor uses. */
    onSave: (message: Message, body: string) => void;
}): ComposerEditMode {
    const { body, focusEnd, resize } = options.field;

    /**
     * The message the composer is editing in place, or null in normal compose
     * mode. Set by the ArrowUp "edit last message" shortcut.
     */
    const editingMessage = ref<Message | null>(null);

    /**
     * Load a message's body into the composer and switch to edit mode, placing the
     * caret at the end so the just-recalled text is ready to correct. The body is
     * restored verbatim (mention tokens included) so it round-trips faithfully.
     */
    function enterEditMode(message: Message): void {
        editingMessage.value = message;
        body.value = message.body;
        options.closeMenu();
        options.onEditingChange(message.id);

        focusEnd();
    }

    /**
     * Leave edit mode and reset the composer to its prior (empty) state. The wipe is
     * flagged so it isn't persisted as a channel draft (entry required an empty
     * composer, so there is nothing to restore).
     */
    function exitEditMode(): void {
        // Suppress the draft echo from the wipe only when there is text to clear;
        // clearing an already-empty field fires no watcher, which would otherwise
        // leave the guard armed for the next genuine keystroke.
        options.suppressNextDraftChange(body.value !== '');
        body.value = '';
        editingMessage.value = null;
        options.closeMenu();
        options.onEditingChange(null);
        nextTick(resize);
    }

    /**
     * Resolve the viewer's most recent editable message on this surface and enter
     * edit mode for it. A no-op (falling through to the default ArrowUp behaviour)
     * when there is none.
     */
    function tryEditLastMessage(): boolean {
        const target = resolveComposerEditTarget(
            options.messages(),
            options.currentUserId(),
            options.pendingUuids(),
        );

        if (!target) {
            return false;
        }

        enterEditMode(target);

        return true;
    }

    /**
     * Save the in-progress composer edit through the shared edit/PATCH path. An
     * empty or unchanged draft is a no-op, matching the inline editor; either way
     * the composer returns to its normal empty state.
     */
    function saveEdit(): void {
        const target = editingMessage.value;

        if (!target) {
            return;
        }

        const trimmed = body.value.trim();

        if (trimmed !== '' && trimmed !== target.body) {
            options.onSave(target, trimmed);
        }

        exitEditMode();
    }

    return {
        editingMessage,
        enterEditMode,
        exitEditMode,
        tryEditLastMessage,
        saveEdit,
    };
}
