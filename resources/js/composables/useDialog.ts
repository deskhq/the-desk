import { ref } from 'vue';
import type { Ref } from 'vue';

/**
 * The shell's dialogs, and whether each starts open.
 *
 * Every one of them is mounted once by {@see DialogHost} and opened from
 * somewhere else entirely — the user menu, the masthead, a keyboard shortcut, a
 * panel row. Naming them here is what replaces the five near-identical singleton
 * composables that each held one `ref(false)`, and the emit chains that carried
 * the other three up through four components to the layout that owned them
 * (#1093).
 *
 * Only the pending-invitations prompt starts open: it is mounted the moment the
 * lazily shared invitations land, and presenting itself is its whole job.
 */
const SHELL_DIALOGS = {
    /** The people picker behind "New message". */
    newMessage: false,
    /**
     * The create-channel form, reached from the sidebar's "+", the "New" menu,
     * the first-run welcome and the palette (#1223).
     */
    createChannel: false,
    /** The member-invite modal. */
    invite: false,
    /** The prompt listing workspaces the viewer has been invited to. */
    invitations: true,
    /** The ⌘K jump-to palette. */
    switcher: false,
    /** The keyboard-shortcuts help modal. */
    shortcuts: false,
    /** "Set a status". */
    status: false,
    /** The custom "Pause notifications" flyout. */
    dnd: false,
    /** The install sheet. */
    install: false,
} as const;

export type ShellDialog = keyof typeof SHELL_DIALOGS;

/** Every dialog in the registry, for the host that mounts them. */
export const SHELL_DIALOG_NAMES = Object.keys(
    SHELL_DIALOGS,
) as readonly ShellDialog[];

/**
 * Module-scoped, which is the whole point: the opener and the mount site are in
 * different subtrees, so the state cannot live in either of them.
 */
const openState = Object.fromEntries(
    Object.entries(SHELL_DIALOGS).map(([name, initial]) => [
        name,
        ref(initial),
    ]),
) as Record<ShellDialog, Ref<boolean>>;

export interface Dialog {
    /** The open flag, bound straight to the dialog's `v-model:open`. */
    isOpen: Ref<boolean>;
    open: () => void;
    close: () => void;
    /** For the shortcuts that are a switch rather than an entrance (⌘K, ?). */
    toggle: () => void;
}

/**
 * Reach one of the shell's dialogs by name.
 *
 * Modelled on {@see useToast}: one module owns the whole family, so a new dialog
 * is an entry in {@link SHELL_DIALOGS} plus a mount in {@see DialogHost} rather
 * than a new composable, a new import in the layout and a new mount site.
 */
export function useDialog(name: ShellDialog): Dialog {
    const isOpen = openState[name];

    return {
        isOpen,
        open: (): void => {
            isOpen.value = true;
        },
        close: (): void => {
            isOpen.value = false;
        },
        toggle: (): void => {
            isOpen.value = !isOpen.value;
        },
    };
}
