/**
 * The shortfall below which a visual-viewport change is browser chrome (a
 * collapsing URL bar, a findbar) rather than an on-screen keyboard. Padding the
 * composer by those few dozen pixels would make it jitter as the user scrolls.
 */
const KEYBOARD_THRESHOLD_PX = 120;

/**
 * The visual-viewport reading a keyboard inset is derived from.
 */
export type ViewportReading = {
    /** The layout viewport's height — what `100svh` and `h-svh` resolve to. */
    innerHeight: number;
    /** The visual viewport's height: the part not covered by the keyboard. */
    height: number;
    /** How far the visual viewport is scrolled down the layout viewport. */
    offsetTop: number;
};

/**
 * How many pixels of the layout viewport the on-screen keyboard covers.
 *
 * The layout viewport (what `svh` sizes against) does not shrink when the
 * keyboard opens, so a bottom-anchored composer ends up behind it. The visual
 * viewport does shrink, and the difference — less whatever the browser scrolled
 * the page by to compensate — is the padding that keeps the composer above the
 * keyboard.
 */
export function keyboardInset({
    innerHeight,
    height,
    offsetTop,
}: ViewportReading): number {
    const covered = innerHeight - height - offsetTop;

    return covered >= KEYBOARD_THRESHOLD_PX ? covered : 0;
}
