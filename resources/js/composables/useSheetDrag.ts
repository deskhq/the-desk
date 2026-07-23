import { ref } from 'vue';
import type { Ref } from 'vue';
import { sheetOffset, shouldDismissSheet } from '@/lib/sheetDrag';

type SheetDragOptions = {
    /** Whether the surface is currently a sheet — the gesture is mobile only. */
    enabled: Ref<boolean>;
    /** Close the sheet, once a drag has asked for it. */
    onDismiss: () => void;
};

type SheetDrag = {
    /** How far down the sheet currently sits, in pixels. */
    offset: Ref<number>;
    /** Whether a drag is in flight, so the sheet can follow the finger untweened. */
    dragging: Ref<boolean>;
    /** Begin a drag. Bound to the grab handle's `pointerdown`. */
    start: (event: PointerEvent) => void;
    /** Carry it. Bound to `pointermove`. */
    move: (event: PointerEvent) => void;
    /** Finish it, dismissing the sheet if it travelled far or fast enough. */
    end: (event: PointerEvent) => void;
    /** Drop it — the browser took the pointer over. */
    cancel: (event: PointerEvent) => void;
};

/** The in-flight drag, tagged with the pointer that owns it. */
type Gesture = {
    pointerId: number;
    /** Where the finger went down, which every offset is measured from. */
    startY: number;
    /** The sheet's height when the drag began: how far "far enough" is. */
    height: number;
    /** The move before the current one, for the parting speed of a flick. */
    previousY: number;
    previousAt: number;
    /** The latest move, which the release reads its speed from. */
    lastY: number;
    lastAt: number;
};

/**
 * Drag a bottom sheet down to dismiss it.
 *
 * The gesture belongs to the grab handle rather than to the whole sheet: the
 * sheet body scrolls its own content, and a drag that had to guess between
 * "scroll this list" and "throw the sheet away" would get one of them wrong.
 * Escape, the scrim and the close button dismiss the sheet too, so nothing is
 * reachable only through the gesture.
 */
export function useSheetDrag({
    enabled,
    onDismiss,
}: SheetDragOptions): SheetDrag {
    const offset = ref(0);
    const dragging = ref(false);

    let gesture: Gesture | null = null;

    /** Whether this event belongs to the drag in flight. */
    function owns(event: PointerEvent): boolean {
        return gesture !== null && gesture.pointerId === event.pointerId;
    }

    /** Let go of the pointer and put the sheet back where it started. */
    function settle(event: PointerEvent): void {
        const handle = event.currentTarget as HTMLElement;

        if (handle.hasPointerCapture(event.pointerId)) {
            handle.releasePointerCapture(event.pointerId);
        }

        gesture = null;
        dragging.value = false;
        offset.value = 0;
    }

    function start(event: PointerEvent): void {
        // A mouse press on the handle is not a drag-to-dismiss: capturing the
        // pointer would swallow the click, and a desktop has the close button.
        if (gesture || !enabled.value || event.pointerType === 'mouse') {
            return;
        }

        const handle = event.currentTarget as HTMLElement;
        const sheet = handle.closest<HTMLElement>(
            '[data-slot="dialog-content"]',
        );

        gesture = {
            pointerId: event.pointerId,
            startY: event.clientY,
            height: sheet?.getBoundingClientRect().height ?? 0,
            previousY: event.clientY,
            previousAt: event.timeStamp,
            lastY: event.clientY,
            lastAt: event.timeStamp,
        };

        dragging.value = true;
        handle.setPointerCapture(event.pointerId);
    }

    function move(event: PointerEvent): void {
        if (!gesture || !owns(event)) {
            return;
        }

        offset.value = sheetOffset(event.clientY - gesture.startY);

        gesture.previousY = gesture.lastY;
        gesture.previousAt = gesture.lastAt;
        gesture.lastY = event.clientY;
        gesture.lastAt = event.timeStamp;
    }

    function end(event: PointerEvent): void {
        if (!gesture || !owns(event)) {
            return;
        }

        // The release carries a position of its own, and a finger can travel a
        // good way between the last move it reported and lifting off. Folding it
        // in as a final move is what makes a flick read as one — but only when it
        // moved, or an unmoved release would zero out the speed it left at.
        if (event.clientY !== gesture.lastY) {
            move(event);
        }

        const { height, previousY, previousAt, lastY, lastAt } = gesture;
        const travelled = offset.value;

        settle(event);

        // Guard the divisor: two moves can share a timestamp, and a release
        // with no move before it has none at all.
        const elapsed = Math.max(lastAt - previousAt, 1);

        if (
            shouldDismissSheet({
                offset: travelled,
                height,
                velocity: (lastY - previousY) / elapsed,
            })
        ) {
            onDismiss();
        }
    }

    function cancel(event: PointerEvent): void {
        if (!gesture || !owns(event)) {
            return;
        }

        settle(event);
    }

    return { offset, dragging, start, move, end, cancel };
}
