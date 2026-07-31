import { computed, nextTick, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import type { ComposerField } from '@/composables/useComposerField';
import type { SelectionRange } from '@/composables/useComposerFormat';

export type ComposerAttachSheet = {
    /** Whether the sheet is raised. */
    open: Ref<boolean>;
    /**
     * The range the format strip works against while the sheet is up, and null
     * whenever it is down. Handed to `useComposerFormat`, which prefers it.
     */
    selection: Ref<SelectionRange | null>;
    /**
     * How far the composer should sit off the bottom of the screen: the live
     * keyboard inset ordinarily, the pre-open one while the sheet is up.
     */
    insetPx: ComputedRef<number>;
    /** Seed the field with a `/` and open the slash menu onto it. */
    startSlashCommand: () => void;
};

/**
 * The mobile attach sheet's side of the composer: what it has to hold still
 * while it is up.
 *
 * Two things would otherwise move under the user. The keyboard leaves when the
 * sheet takes the focus off the field, so the measured inset falls to zero and
 * the pill drops by the keyboard's whole height just as the sheet slides up over
 * where it used to be — freezing the pre-open value pins it. And the field, from
 * behind a focus trap, reports no live selection for the format strip to mark
 * up, so the range is snapshotted as the sheet opens and advanced across
 * consecutive marks.
 */
export function useComposerAttachSheet(options: {
    field: ComposerField;
    /** The live keyboard inset, in pixels. */
    keyboardInsetPx: Ref<number>;
    /** Re-run the slash-command autocomplete against the seeded `/`. */
    refreshSlashSuggestions: () => void;
}): ComposerAttachSheet {
    const { body, textarea, focusRange, resize } = options.field;

    const open = ref(false);
    const selection = ref<SelectionRange | null>(null);
    const pinnedInsetPx = ref<number | null>(null);

    const insetPx = computed(
        () => pinnedInsetPx.value ?? options.keyboardInsetPx.value,
    );

    watch(open, (isOpen) => {
        if (isOpen) {
            const el = textarea.value;

            selection.value = el
                ? { start: el.selectionStart, end: el.selectionEnd }
                : { start: body.value.length, end: body.value.length };
            pinnedInsetPx.value = options.keyboardInsetPx.value;

            return;
        }

        const range = selection.value;
        selection.value = null;
        pinnedInsetPx.value = null;

        if (!range) {
            return;
        }

        // Written back without focusing: focusing would both fight the dialog's
        // own focus restore and summon the keyboard over a sheet just dismissed.
        nextTick(() => {
            textarea.value?.setSelectionRange(range.start, range.end);
            resize();
        });
    });

    function startSlashCommand(): void {
        body.value = '/';
        focusRange(1);
        nextTick(options.refreshSlashSuggestions);
    }

    return { open, selection, insetPx, startSlashCommand };
}
