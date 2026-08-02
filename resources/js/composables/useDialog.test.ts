import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { useDialog as UseDialog } from '@/composables/useDialog';

/**
 * A registry as the app boots with it. The open state is module-scoped — that is
 * what lets two subtrees share it — so each test takes a fresh module rather
 * than resetting the refs by hand and testing its own reset.
 */
async function freshRegistry(): Promise<typeof UseDialog> {
    vi.resetModules();

    return (await import('@/composables/useDialog')).useDialog;
}

describe('useDialog', () => {
    let useDialog: typeof UseDialog;

    beforeEach(async () => {
        useDialog = await freshRegistry();
    });

    it('hands the same state to every caller, wherever they sit in the tree', () => {
        // The point of the registry: the user menu opens a dialog the shell
        // mounts, two subtrees apart, with no emit chain between them.
        useDialog('install').open();

        expect(useDialog('install').isOpen.value).toBe(true);
    });

    it('keeps one dialog’s state out of its neighbour’s', () => {
        useDialog('status').open();

        expect(useDialog('dnd').isOpen.value).toBe(false);
    });

    it('closes, so a dialog can be retired by something other than its own chrome', () => {
        useDialog('switcher').open();
        useDialog('switcher').close();

        expect(useDialog('switcher').isOpen.value).toBe(false);
    });

    it('toggles, for the shortcuts that are a switch rather than an entrance', () => {
        const shortcuts = useDialog('shortcuts');

        shortcuts.toggle();
        expect(shortcuts.isOpen.value).toBe(true);

        shortcuts.toggle();
        expect(shortcuts.isOpen.value).toBe(false);
    });

    it('starts every dialog shut but the pending invitations', () => {
        // That one is mounted the moment the lazily shared invitations land, and
        // presenting itself is its whole job; the rest wait to be opened.
        expect(useDialog('invitations').isOpen.value).toBe(true);
        expect(useDialog('invite').isOpen.value).toBe(false);
        expect(useDialog('newMessage').isOpen.value).toBe(false);
        expect(useDialog('shortcuts').isOpen.value).toBe(false);
    });
});
