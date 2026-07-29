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
 * The element to measure, or null where there is nothing measurable.
 *
 * A caller that reads a child component's `$el` can hand us something that is
 * not an element at all: a component whose template opens with a comment renders
 * as a fragment, and its `$el` is then the fragment's anchor comment. Vue keeps
 * root-level comments in a dev build and strips them in production, so the very
 * same component resolves to a comment under the dev server and to its root
 * element in a build (#1051). Neither `getBoundingClientRect` nor
 * `ResizeObserver.observe` accepts a comment, so anything that is not an element
 * claims nothing — the documented fallback — instead of throwing.
 */
function measurable(node: HTMLElement | null): HTMLElement | null {
    return node instanceof Element ? node : null;
}

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
        const target = measurable(element.value);
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

    let settling = 0;

    /**
     * Re-publish across the window a panel takes to arrive.
     *
     * A panel that opens both reflows the pane beside it and slides in under a
     * transform, and neither is observable: a ResizeObserver watches size, not
     * position, and the panel's size never changes across either. `transitionend`
     * is no help either — it never fires at all where animations are disabled,
     * as they are under Playwright. So the settled position is read by looking
     * again, for slightly longer than the 200ms the slide takes.
     */
    function settle(): void {
        cancelAnimationFrame(settling);

        const until = performance.now() + 300;

        const step = (): void => {
            publish();

            if (performance.now() < until) {
                settling = requestAnimationFrame(step);
            }
        };

        step();
    }

    watch(
        element,
        (node) => {
            const target = measurable(node);

            observer?.disconnect();
            observer = target ? new ResizeObserver(publish) : null;
            observer?.observe(target as HTMLElement);

            settle();
        },
        { immediate: true },
    );

    window.addEventListener('resize', publish);

    onScopeDispose(() => {
        cancelAnimationFrame(settling);
        window.removeEventListener('resize', publish);
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
