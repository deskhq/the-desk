import { router, usePage } from '@inertiajs/vue3';
import { show } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { adjacentSlug } from '@/composables/keyboardShortcuts';
import { useDialog } from '@/composables/useDialog';
import { useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts';
import { useShellFocus } from '@/composables/useShellFocus';

/**
 * The app-wide shortcuts the workspace shell answers, for the lifetime of the
 * shell that binds them.
 *
 * They live together because they are all *shell* gestures — two open a dialog
 * the shell mounts, two walk the sidebar the shell renders, one hands the caret
 * to the shell's notification rail — and because keeping the registry's handler
 * map in one place is what stops a shortcut from being half-wired: declared in
 * `keyboardShortcuts.ts`, documented in the help modal, and bound to nothing.
 *
 * What each shortcut *is* still belongs to {@see SHORTCUTS}; this is only what
 * the shell does when one fires.
 */
export function useShellShortcuts(): void {
    const page = usePage();
    const { focusNotificationRail } = useShellFocus();
    const switcher = useDialog('switcher');
    const shortcuts = useDialog('shortcuts');

    /**
     * Jump `delta` channels along the sidebar list from the active one, wrapping
     * at either end. Does nothing until a team and its channels are loaded.
     */
    function moveChannel(delta: number): void {
        const team = page.props.currentTeam;

        if (!team) {
            return;
        }

        const activeSlug =
            (page.props.channel as { slug?: string } | undefined)?.slug ?? null;

        const slug = adjacentSlug(
            (page.props.channels ?? []).map((channel) => channel.slug),
            activeSlug,
            delta,
        );

        if (slug) {
            router.visit(show({ team: team.slug, channel: slug }).url);
        }
    }

    useKeyboardShortcuts({
        'quick-switcher': () => switcher.toggle(),
        'previous-channel': () => moveChannel(-1),
        'next-channel': () => moveChannel(1),
        'focus-notifications': () => focusNotificationRail(),
        'show-shortcuts': () => shortcuts.toggle(),
    });
}
