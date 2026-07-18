import { canEditMessage } from '@/lib/messageActions';
import type { Message } from '@/types';

/**
 * The keyboard/composer state the "↑ to edit last message" trigger resolves
 * against. Kept as a plain shape so the decision below stays pure and
 * unit-testable, independent of the live `KeyboardEvent` and Vue refs.
 */
export type ComposerEditTriggerState = {
    /** The pressed key (`event.key`). */
    key: string;
    /**
     * Modifier flags; any held modifier disqualifies the trigger so it never
     * clashes with `⌥↑` channel navigation.
     */
    altKey: boolean;
    ctrlKey: boolean;
    metaKey: boolean;
    shiftKey: boolean;
    /** Whether the mention autocomplete menu is open (ArrowUp navigates it then). */
    menuOpen: boolean;
    /** Whether the composer is already in edit mode (no re-entry). */
    editing: boolean;
    /** Whether a reply is being composed (the composer answers that instead). */
    hasReplyTarget: boolean;
    /** Whether the composer body is empty (trimmed). */
    isEmpty: boolean;
    /** Whether the caret sits at the very start of the field. */
    caretAtStart: boolean;
};

/**
 * Whether an ArrowUp keypress in the composer should enter "edit last message"
 * mode: only on a bare ArrowUp (no modifiers), with the mention menu closed, no
 * reply in progress, not already editing, and an empty composer with the caret
 * at the start. Any other combination falls through to the default behaviour.
 */
export function isComposerEditTrigger(
    state: ComposerEditTriggerState,
): boolean {
    return (
        state.key === 'ArrowUp' &&
        !state.altKey &&
        !state.ctrlKey &&
        !state.metaKey &&
        !state.shiftKey &&
        !state.menuOpen &&
        !state.editing &&
        !state.hasReplyTarget &&
        state.isEmpty &&
        state.caretAtStart
    );
}

/**
 * The viewer's most recent editable message in a surface (the main timeline or
 * an open thread), or null when they have none. Scans newest-first and reuses
 * the same `canEditMessage` rule the inline editor applies — authored by the
 * viewer, not deleted, not a system notice, and not an in-flight optimistic
 * send (its `clientUuid` is still in `pendingUuids`).
 */
export function resolveComposerEditTarget(
    messages: Message[],
    currentUserId: string,
    pendingUuids: string[] = [],
): Message | null {
    const pending = new Set(pendingUuids);

    for (let index = messages.length - 1; index >= 0; index -= 1) {
        const message = messages[index];

        const canEdit = canEditMessage(message, {
            currentUserId,
            // Editability ignores these, but the shared context shape requires
            // them; only `pending` and the ownership/liveness checks matter.
            canReact: false,
            canPin: false,
            canModerate: false,
            inThread: false,
            pending: pending.has(message.clientUuid),
        });

        if (canEdit) {
            return message;
        }
    }

    return null;
}
