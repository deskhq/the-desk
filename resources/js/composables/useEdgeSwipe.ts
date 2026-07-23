import { onBeforeUnmount, onMounted } from 'vue';
import type { Ref } from 'vue';
import { swipeIntent } from '@/lib/edgeSwipe';
import type { SwipeEdge } from '@/lib/edgeSwipe';

type EdgeSwipeOptions = {
    /** Whether the gesture is live — it is a touch affordance, so mobile only. */
    enabled: Ref<boolean>;
    /** The screen edge the panel lives on, and so the edge it is pulled from. */
    edge: Ref<SwipeEdge>;
    /** Pull the panel out. */
    onOpen: () => void;
    /** Push it back. */
    onClose: () => void;
};

/**
 * Open an edge panel by swiping in from the screen edge, and close it by
 * swiping back out.
 *
 * Bound to touch and pen only: a mouse drag from the window's edge is someone
 * selecting text, not reaching for the dock.
 */
export function useEdgeSwipe({
    enabled,
    edge,
    onOpen,
    onClose,
}: EdgeSwipeOptions): void {
    /**
     * The in-flight gesture. Tagged with the pointer that began it so a second
     * finger landing mid-drag cannot complete — or cancel — the first one's
     * swipe.
     */
    let gesture: { pointerId: number; x: number; y: number } | null = null;

    function onPointerDown(event: PointerEvent): void {
        // First finger down wins the gesture and keeps it until it lifts or is
        // cancelled: a second one landing mid-drag — or a mouse, which never
        // starts one — must not discard the swipe already in flight.
        if (gesture || !enabled.value || event.pointerType === 'mouse') {
            return;
        }

        gesture = {
            pointerId: event.pointerId,
            x: event.clientX,
            y: event.clientY,
        };
    }

    function onPointerUp(event: PointerEvent): void {
        if (!gesture || gesture.pointerId !== event.pointerId) {
            return;
        }

        const { x, y } = gesture;
        gesture = null;

        // The viewport can cross the breakpoint mid-drag (a rotation, a resized
        // window), and a gesture begun on a phone must not act on a desktop.
        if (!enabled.value) {
            return;
        }

        const intent = swipeIntent({
            startX: x,
            startY: y,
            endX: event.clientX,
            endY: event.clientY,
            viewportWidth: window.innerWidth,
            edge: edge.value,
        });

        if (intent === 'open') {
            onOpen();
        } else if (intent === 'close') {
            onClose();
        }
    }

    /**
     * The browser took the pointer over (a scroll or a system back-gesture won
     * it): the drag never completes, so drop it rather than leave it to be
     * matched against some later release.
     */
    function onPointerCancel(event: PointerEvent): void {
        if (gesture?.pointerId === event.pointerId) {
            gesture = null;
        }
    }

    onMounted(() => {
        document.addEventListener('pointerdown', onPointerDown, {
            passive: true,
        });
        document.addEventListener('pointerup', onPointerUp, { passive: true });
        document.addEventListener('pointercancel', onPointerCancel, {
            passive: true,
        });
    });

    onBeforeUnmount(() => {
        document.removeEventListener('pointerdown', onPointerDown);
        document.removeEventListener('pointerup', onPointerUp);
        document.removeEventListener('pointercancel', onPointerCancel);
    });
}
