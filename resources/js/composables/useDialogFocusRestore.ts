import { injectDialogRootContext } from 'reka-ui';
import { watch } from 'vue';

import { focusAfterOverlayInert } from '@/lib/overlayInert';

/**
 * Give a reka dialog surface the focus restore WCAG 2.4.3 asks for: closing it
 * hands focus back to the control that opened it, instead of dropping a keyboard
 * or screen-reader user at the top of the document (#784).
 *
 * reka already tries. `DialogContentModal` cancels the browser's own restore and
 * focuses the trigger itself, synchronously, from inside `closeAutoFocus` — but
 * at that moment the page behind the surface is still `inert`, because the
 * mirror that makes it so is torn down a microtask later (see
 * `@/lib/overlayInert`). Focusing into an inert subtree does nothing, and reka's
 * own deferred fallback has already been cancelled, so focus lands nowhere.
 * Waiting for the mirror to lift is the whole fix.
 */
export function useDialogFocusRestore(): {
    restoreFocusOnClose: (event: Event) => void;
} {
    const rootContext = injectDialogRootContext();

    /**
     * Whatever held focus the instant before the surface opened. reka's own
     * `triggerElement` only covers surfaces opened through a `<DialogTrigger>`;
     * most here are driven straight from `v-model:open`, and some open from a
     * keyboard shortcut, where the right destination is the control the user
     * actually left — not a trigger at all.
     */
    let opener: HTMLElement | null = null;

    watch(
        () => rootContext.open.value,
        (open) => {
            if (!open) {
                return;
            }

            const active = document.activeElement;

            opener =
                active instanceof HTMLElement && active !== document.body
                    ? active
                    : null;
        },
        { immediate: true },
    );

    function restoreFocusOnClose(event: Event): void {
        // A call site that named its own destination has already prevented this
        // — the mobile sidebar hands focus to the destination pane, because the
        // row that opened it navigated away with the sheet.
        if (event.defaultPrevented) {
            return;
        }

        // Cancels reka's restore, which cannot land while the page is inert.
        event.preventDefault();

        focusAfterOverlayInert(
            opener ?? rootContext.triggerElement.value ?? null,
        );
    }

    return { restoreFocusOnClose };
}
