import type { NavigableMenu } from '@/composables/useAutocompleteMenu';
import type { ComposerEditMode } from '@/composables/useComposerEditMode';
import type { ComposerField } from '@/composables/useComposerField';
import type { ComposerFormat } from '@/composables/useComposerFormat';
import type { ComposerMentions } from '@/composables/useComposerMentions';
import type { ComposerSlashCommands } from '@/composables/useComposerSlashCommands';
import { isComposerEditTrigger } from '@/lib/composerEdit';
import type { Message } from '@/types';

/**
 * The keyCode browsers without `isComposing` report for any key pressed while an
 * IME composition is open. Safari and older WebKit builds still lean on it.
 */
const COMPOSITION_KEY_CODE = 229;

/**
 * Whether the keypress belongs to an in-progress IME composition, where Enter
 * commits the candidate word (and Escape/the arrows pick between candidates)
 * rather than meaning anything to the composer.
 */
function isComposing(event: KeyboardEvent): boolean {
    return event.isComposing || event.keyCode === COMPOSITION_KEY_CODE;
}

/**
 * The composer's keydown model, in priority order: an open IME composition owns
 * every key; then whichever autocomplete menu is open owns the arrows, Enter/Tab
 * and Escape; then the format shortcuts; then Escape's fallbacks (leave edit
 * mode, else drop the reply); then ArrowUp's "edit last message" recall; and
 * finally Enter to send or save.
 */
export function useComposerKeyboard(options: {
    field: ComposerField;
    mentions: ComposerMentions;
    slash: ComposerSlashCommands;
    format: ComposerFormat;
    editing: ComposerEditMode;
    replyTarget: () => Message | null | undefined;
    onCancelReply: () => void;
    onSubmit: () => void;
}): { onKeydown: (event: KeyboardEvent) => void } {
    const { body, caretAtStart } = options.field;
    const { mentions, slash, editing } = options;

    /**
     * The two autocompletes, in priority order. They are mutually exclusive (a
     * `/…` body never matches an `@query`), so at most one is ever open.
     */
    const menus: NavigableMenu[] = [mentions.menu, slash.menu];

    /**
     * Whether the open menu, if either is, took the key. Arrows move the active
     * row, Enter/Tab complete it and Escape dismisses it.
     */
    function menuTookKey(event: KeyboardEvent): boolean {
        const menu = menus.find((candidate) => candidate.showMenu.value);

        if (!menu) {
            return false;
        }

        if (event.key === 'ArrowDown') {
            menu.moveActive(1);
        } else if (event.key === 'ArrowUp') {
            menu.moveActive(-1);
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            menu.selectActive();
        } else if (event.key === 'Escape') {
            menu.close();
        } else {
            return false;
        }

        event.preventDefault();

        return true;
    }

    function onKeydown(event: KeyboardEvent): void {
        // Stand down entirely until the composition ends, or a CJK user posts a
        // half-finished word every time they accept an IME candidate.
        if (isComposing(event)) {
            return;
        }

        if (menuTookKey(event)) {
            return;
        }

        // Format shortcuts wrap the selection. Placed after the mention menu's key
        // handling so its arrow/Enter/Escape keep priority while it is open; the
        // chosen keys (B/I/E, ⇧X) never collide with it or Enter-to-send.
        if (options.format.tryFormatShortcut(event)) {
            return;
        }

        // With the mention menu closed, Escape leaves edit mode (restoring the empty
        // composer) or, failing that, dismisses the active reply context.
        if (event.key === 'Escape' && editing.editingMessage.value) {
            event.preventDefault();
            editing.exitEditMode();

            return;
        }

        if (event.key === 'Escape' && options.replyTarget()) {
            event.preventDefault();
            options.onCancelReply();

            return;
        }

        // ArrowUp on an empty composer recalls the viewer's last editable message
        // into edit mode ("↑ to edit last message"). The gate keeps it clear of the
        // mention menu, `⌥↑` channel nav, and an in-progress reply.
        if (
            isComposerEditTrigger({
                key: event.key,
                altKey: event.altKey,
                ctrlKey: event.ctrlKey,
                metaKey: event.metaKey,
                shiftKey: event.shiftKey,
                menuOpen: menus.some((menu) => menu.showMenu.value),
                editing: editing.editingMessage.value !== null,
                hasReplyTarget: options.replyTarget() != null,
                isEmpty: body.value.trim() === '',
                caretAtStart: caretAtStart(),
            })
        ) {
            if (editing.tryEditLastMessage()) {
                event.preventDefault();
            }

            return;
        }

        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();

            if (editing.editingMessage.value) {
                editing.saveEdit();
            } else {
                options.onSubmit();
            }
        }
    }

    return { onKeydown };
}
