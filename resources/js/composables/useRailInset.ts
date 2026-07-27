import { onScopeDispose, watch } from 'vue';
import type { Ref } from 'vue';

/**
 * How much of the viewport's right and bottom edges the conversation pane's own
 * furniture claims. The bottom-right rail (toasts and reminder nudges) consumes
 * both, so it sits *inside* the pane — clear of a side panel, and above the
 * composer rather than on top of it.
 *
 * The rail is viewport-fixed and lives in `MainLayout`, so it cannot read the
 * geometry of anything mounted inside a page. Publishing the measurements on the
 * document element is the contract between the two: anywhere that publishes
 * nothing — settings, auth, a channel with no thread open — the rail falls back
 * to the viewport edge for free (#978).
 */
export const RAIL_INSET_PROPERTY = '--rail-right-inset';

export const RAIL_BOTTOM_INSET_PROPERTY = '--rail-bottom-inset';

/**
 * Keep `property` in step with what `element` claims of its edge, for as long as
 * the element is mounted.
 *
 * @param claim How much of the edge the element takes, or null to claim none.
 */
function publishInset(
    element: Ref<HTMLElement | null>,
    property: string,
    claim: (element: HTMLElement) => number | null,
): void {
    // There is no rail to inset during SSR, and no geometry to measure it from.
    if (typeof window === 'undefined') {
        return;
    }

    let observer: ResizeObserver | null = null;

    function clear(): void {
        document.documentElement.style.removeProperty(property);
    }

    function publish(): void {
        const target = element.value;
        const inset = target ? claim(target) : null;

        if (inset === null) {
            clear();

            return;
        }

        document.documentElement.style.setProperty(
            property,
            `${Math.max(inset, 0)}px`,
        );
    }

    let transitioning: HTMLElement | null = null;

    watch(
        element,
        (target) => {
            observer?.disconnect();
            transitioning?.removeEventListener('transitionend', publish);

            observer = target ? new ResizeObserver(publish) : null;
            observer?.observe(target as HTMLElement);

            // A panel that slides in is still under its enter transform on the
            // first measurement, which reads as a claim of almost nothing. A
            // ResizeObserver never fires for a transform, so the settled
            // position has to be waited for explicitly.
            transitioning = target;
            target?.addEventListener('transitionend', publish);

            publish();
        },
        { immediate: true },
    );

    window.addEventListener('resize', publish);

    onScopeDispose(() => {
        window.removeEventListener('resize', publish);
        transitioning?.removeEventListener('transitionend', publish);
        observer?.disconnect();
        clear();
    });
}

/**
 * Publish a side panel's claim on the right edge while it is open and laid out
 * beside the conversation pane, so the rail clears it instead of straddling it.
 */
export function useRailInset(panel: Ref<HTMLElement | null>): void {
    publishInset(panel, RAIL_INSET_PROPERTY, (element) =>
        // Below the breakpoint where it becomes a flex sibling, the panel
        // overlays the conversation pane rather than sitting beside it, so there
        // is nothing for the rail to clear — and measuring it there would push
        // the rail off the far side of the screen.
        getComputedStyle(element).position === 'relative'
            ? window.innerWidth - element.getBoundingClientRect().left
            : null,
    );
}

/**
 * Publish the composer's claim on the bottom edge, so the rail rides above it
 * rather than over its send button and scheduled chip — the very thing that sent
 * toasts to top-center in #430. The composer pads itself by the software
 * keyboard's inset, so measuring it lifts the rail with the keyboard for free.
 */
export function useRailBottomInset(composer: Ref<HTMLElement | null>): void {
    publishInset(
        composer,
        RAIL_BOTTOM_INSET_PROPERTY,
        (element) => window.innerHeight - element.getBoundingClientRect().top,
    );
}
