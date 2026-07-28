import { nextTick, ref } from 'vue';
import type { Ref } from 'vue';

/**
 * The composer's text field: the body being typed, the textarea behind it, and
 * the caret/auto-grow helpers every surface built on it — mentions, slash
 * commands, inline formatting, edit mode — drives it through. One owner for the
 * "write the body, then put the caret back and re-measure" dance those four
 * would otherwise each repeat.
 */
export type ComposerField = {
    body: Ref<string>;
    textarea: Ref<HTMLTextAreaElement | null>;
    /** Grow (or shrink) the field to its content, up to the scroll ceiling. */
    resize: () => void;
    /** Focus the field without moving the caret. */
    focus: () => void;
    /** Focus the field and restore a selection, then re-measure its height. */
    focusRange: (start: number, end?: number) => void;
    /** Focus the field with the caret past the last character. */
    focusEnd: () => void;
    /** Whether the caret sits at the very start of the field (nothing selected). */
    caretAtStart: () => boolean;
    /** The caret offset, falling back to the end of the body before mount. */
    caretPosition: () => number;
};

export function useComposerField(initialBody: string): ComposerField {
    const body = ref(initialBody);
    const textarea = ref<HTMLTextAreaElement | null>(null);

    function resize(): void {
        const el = textarea.value;

        if (!el) {
            return;
        }

        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
    }

    function focus(): void {
        nextTick(() => textarea.value?.focus());
    }

    function focusRange(start: number, end: number = start): void {
        nextTick(() => {
            const field = textarea.value;

            if (field) {
                field.focus();
                field.setSelectionRange(start, end);
            }

            resize();
        });
    }

    function focusEnd(): void {
        nextTick(() => {
            const field = textarea.value;

            if (field) {
                field.focus();

                const end = field.value.length;
                field.setSelectionRange(end, end);
            }

            resize();
        });
    }

    function caretAtStart(): boolean {
        const el = textarea.value;

        if (!el) {
            return true;
        }

        return el.selectionStart === 0 && el.selectionEnd === 0;
    }

    function caretPosition(): number {
        const el = textarea.value;

        return el ? el.selectionStart : body.value.length;
    }

    return {
        body,
        textarea,
        resize,
        focus,
        focusRange,
        focusEnd,
        caretAtStart,
        caretPosition,
    };
}
