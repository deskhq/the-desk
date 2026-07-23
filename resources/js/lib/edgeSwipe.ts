/** How close to the screen edge a drag must begin to count as an edge swipe. */
const EDGE_ZONE_PX = 24;

/** How far a drag must travel horizontally before it reads as deliberate. */
const MIN_TRAVEL_PX = 56;

/** Which screen edge the panel is anchored to. */
export type SwipeEdge = 'left' | 'right';

/**
 * A completed drag, in viewport coordinates.
 */
export type Swipe = {
    startX: number;
    startY: number;
    endX: number;
    endY: number;
    viewportWidth: number;
    /** The edge the panel lives on, which is the edge it is pulled from. */
    edge: SwipeEdge;
};

/** What a completed drag asks of the panel, or null if it asks nothing. */
export type SwipeIntent = 'open' | 'close' | null;

/**
 * Read a completed drag as an instruction to an edge panel.
 *
 * Opening is deliberately harder than closing: it only counts from the panel's
 * own screen edge, so a drag in the middle of the conversation (swiping a
 * message, panning an image) never pulls the panel out. Closing counts
 * anywhere, because by then the panel is what the finger is on.
 *
 * A drag that travels further vertically than horizontally is someone scrolling,
 * not swiping, however far it goes.
 */
export function swipeIntent({
    startX,
    startY,
    endX,
    endY,
    viewportWidth,
    edge,
}: Swipe): SwipeIntent {
    const travelX = endX - startX;

    if (Math.abs(travelX) < MIN_TRAVEL_PX) {
        return null;
    }

    if (Math.abs(endY - startY) > Math.abs(travelX)) {
        return null;
    }

    // Inward is rightward for a left-hand panel, leftward for a right-hand one.
    const isInward = edge === 'left' ? travelX > 0 : travelX < 0;

    if (!isInward) {
        return 'close';
    }

    const startedAtEdge =
        edge === 'left'
            ? startX <= EDGE_ZONE_PX
            : startX >= viewportWidth - EDGE_ZONE_PX;

    return startedAtEdge ? 'open' : null;
}
