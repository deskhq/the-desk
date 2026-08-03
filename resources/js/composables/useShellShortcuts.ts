import { SHORTCUT_HANDLERS } from '@/composables/commands';
import { useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts';

/**
 * Bind the app-wide shortcuts for the lifetime of the shell that mounts them.
 *
 * What each shortcut *is* belongs to {@see SHORTCUTS}; what it *does* belongs to
 * {@see COMMANDS}, which owns the handler and offers the same verb as a palette
 * row. Nothing is written twice, so a shortcut cannot be declared, documented in
 * the help modal, and bound to nothing.
 */
export function useShellShortcuts(): void {
    useKeyboardShortcuts(SHORTCUT_HANDLERS);
}
