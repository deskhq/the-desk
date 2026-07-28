import { Bold, Code, Italic, Strikethrough } from '@lucide/vue';
import { computed } from 'vue';
import type { ComputedRef, FunctionalComponent } from 'vue';
import type { ComposerField } from '@/composables/useComposerField';
import { useTranslations } from '@/composables/useTranslations';
import { toggleInlineMark } from '@/lib/composerFormat';

/**
 * One inline-format control: its Markdown marker paired with the icon,
 * accessible label, and shortcut hint its tooltip shows.
 */
export type FormatAction = {
    marker: string;
    icon: FunctionalComponent;
    label: string;
    shortcut: string;
};

/**
 * The platform's primary modifier, for the format tooltips' shortcut hints.
 * Falls back to Ctrl off-Mac (and during SSR, where `navigator` is absent).
 */
const isMac =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform);
const modLabel = isMac ? '⌘' : 'Ctrl+';
const shiftLabel = isMac ? '⇧' : 'Shift+';

export type ComposerFormat = {
    formatActions: ComputedRef<FormatAction[]>;
    applyFormat: (marker: string) => void;
    tryFormatShortcut: (event: KeyboardEvent) => boolean;
};

/**
 * The composer's inline formatting: the four toolbar controls and the keyboard
 * shortcuts that mirror them, both wrapping (or unwrapping) the current
 * selection in a Markdown marker.
 */
export function useComposerFormat(options: {
    field: ComposerField;
}): ComposerFormat {
    const { body, textarea, focusRange } = options.field;
    const { t } = useTranslations();

    /**
     * The four inline-format controls. Driven by the toolbar buttons and the
     * keyboard shortcuts alike.
     */
    const formatActions = computed<FormatAction[]>(() => [
        {
            marker: '**',
            icon: Bold,
            label: t('Bold'),
            shortcut: `${modLabel}B`,
        },
        {
            marker: '*',
            icon: Italic,
            label: t('Italic'),
            shortcut: `${modLabel}I`,
        },
        {
            marker: '~~',
            icon: Strikethrough,
            label: t('Strikethrough'),
            shortcut: `${modLabel}${shiftLabel}X`,
        },
        {
            marker: '`',
            icon: Code,
            label: t('Inline code'),
            shortcut: `${modLabel}E`,
        },
    ]);

    /**
     * Wrap (or unwrap) the current textarea selection in a Markdown marker, then
     * restore focus and the resulting selection so the field stays ready to type.
     * Shared by the toolbar buttons and the keyboard shortcuts.
     */
    function applyFormat(marker: string): void {
        const el = textarea.value;

        if (!el) {
            return;
        }

        const result = toggleInlineMark(
            body.value,
            el.selectionStart,
            el.selectionEnd,
            marker,
        );

        body.value = result.value;

        focusRange(result.selectionStart, result.selectionEnd);
    }

    /**
     * Handle the format keyboard shortcuts (⌘/Ctrl+B/I/E and ⌘/Ctrl+Shift+X),
     * returning true when one fired so the caller stops further key handling. The
     * chosen keys avoid every existing composer binding.
     */
    function tryFormatShortcut(event: KeyboardEvent): boolean {
        if (!(event.metaKey || event.ctrlKey) || event.altKey) {
            return false;
        }

        const key = event.key.toLowerCase();

        const marker =
            key === 'b' && !event.shiftKey
                ? '**'
                : key === 'i' && !event.shiftKey
                  ? '*'
                  : key === 'e' && !event.shiftKey
                    ? '`'
                    : key === 'x' && event.shiftKey
                      ? '~~'
                      : null;

        if (marker === null) {
            return false;
        }

        event.preventDefault();
        // Claim the key before it bubbles to window-level shortcuts (⌘/Ctrl+B also
        // toggles the sidebar), so formatting in the composer never fires those too.
        event.stopPropagation();
        applyFormat(marker);

        return true;
    }

    return { formatActions, applyFormat, tryFormatShortcut };
}
