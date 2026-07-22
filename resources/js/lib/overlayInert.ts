/**
 * Reka traps assistive tech inside an open overlay by marking every sibling of
 * the overlay `aria-hidden="true"` — it delegates to the `aria-hidden` package,
 * which stamps its own `data-aria-hidden` marker on each node it hid. It stops
 * there: the hidden shell keeps its tab stops, so the skip link, the sidebar and
 * the composer stay keyboard-focusable while being invisible to a screen reader.
 * That is the WCAG 4.1.2 failure axe reports as `aria-hidden-focus` (#730), and
 * it fires on every dropdown menu — a `Dialog` only escapes it because axe
 * recognises an open `role="dialog"` and holds its checks back.
 *
 * A menu's focus trap makes the hidden region unreachable in practice, but only
 * while the trap holds and only for Tab. Mirroring `inert` onto whatever reka
 * hid removes the region from the tab order for real, so the two halves of
 * "hidden" finally agree. Every reka overlay is covered at once, because they
 * all hide the background through the same marker.
 */

/** The attribute the `aria-hidden` package stamps on each node it hides. */
const HIDDEN_MARKER = 'data-aria-hidden';

/**
 * Our own marker, so `inert` is only ever removed from elements we made inert —
 * an `inert` the app set itself is left untouched.
 */
const OWNED_MARKER = 'data-overlay-inert';

/**
 * Bring `inert` back in line with what is currently hidden behind an overlay.
 * Idempotent, so it is safe to run on every mutation the overlay emits.
 */
function syncOverlayInert(): void {
    for (const element of document.querySelectorAll<HTMLElement>(
        `[${HIDDEN_MARKER}="true"]:not([${OWNED_MARKER}]):not([inert])`,
    )) {
        element.setAttribute('inert', '');
        element.setAttribute(OWNED_MARKER, '');
    }

    for (const element of document.querySelectorAll<HTMLElement>(
        `[${OWNED_MARKER}]:not([${HIDDEN_MARKER}="true"])`,
    )) {
        element.removeAttribute('inert');
        element.removeAttribute(OWNED_MARKER);
    }
}

/**
 * Start mirroring `inert` onto the region reka hides behind an open overlay.
 * Runs for the lifetime of the app; the returned disposer exists for tests.
 */
export function initializeOverlayInert(): () => void {
    if (typeof window === 'undefined') {
        return () => {};
    }

    const observer = new MutationObserver(syncOverlayInert);

    // Only the marker matters: the package adds it as it hides a node and drops
    // it as it restores one, so both directions arrive as attribute changes.
    observer.observe(document.body, {
        attributes: true,
        attributeFilter: [HIDDEN_MARKER],
        subtree: true,
    });

    syncOverlayInert();

    return () => observer.disconnect();
}
