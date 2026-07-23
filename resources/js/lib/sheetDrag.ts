/**
 * How far an upward drag may pull the sheet past its bottom edge. It gives a
 * little so the gesture feels alive, then holds: a sheet lifted into the middle
 * of the screen would leave a gap under it that belongs to nothing.
 */
const RUBBER_BAND_PX = 48;

/** The share of its own height a sheet must travel before it counts as thrown away. */
const DISMISS_FRACTION = 1 / 3;

/**
 * The travel that dismisses a sheet whose height is unknown. A sheet caught
 * mid-animation can measure zero, and a fraction of zero would dismiss it on
 * the first pixel of a drag.
 */
const DISMISS_FALLBACK_PX = 160;

/** Downward pixels per millisecond past which a drag reads as a flick. */
const FLICK_VELOCITY = 0.5;

/** The travel a flick must still cover, so a stray twitch cannot dismiss. */
const FLICK_MIN_TRAVEL_PX = 32;

/**
 * How far down the sheet sits while a drag is in flight, given how far the
 * pointer has moved from where it went down.
 */
export function sheetOffset(deltaY: number): number {
    if (deltaY >= 0) {
        return deltaY;
    }

    // Asymptotic resistance: the further up the finger goes, the less the sheet
    // follows, and it never passes the band.
    return -RUBBER_BAND_PX * (1 - RUBBER_BAND_PX / (RUBBER_BAND_PX - deltaY));
}

/**
 * A finished drag, as the sheet saw it.
 */
export type SheetDrag = {
    /** How far down the sheet ended up, in pixels. */
    offset: number;
    /** The sheet's own height, which sets how far "far enough" is. */
    height: number;
    /** The drag's parting speed in pixels per millisecond; positive is downward. */
    velocity: number;
};

/**
 * Whether a finished drag asked for the sheet to be dismissed.
 *
 * Two ways to ask: drag it most of the way down, or flick it. The flick is what
 * a thumb actually does — the finger barely travels, so distance alone would
 * always put the sheet back.
 */
export function shouldDismissSheet({
    offset,
    height,
    velocity,
}: SheetDrag): boolean {
    if (offset <= 0) {
        return false;
    }

    const threshold =
        height > 0 ? height * DISMISS_FRACTION : DISMISS_FALLBACK_PX;

    if (offset >= threshold) {
        return true;
    }

    return velocity >= FLICK_VELOCITY && offset >= FLICK_MIN_TRAVEL_PX;
}
