// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';

import { useSheetDrag } from '@/composables/useSheetDrag';

/**
 * A grab handle inside a sheet of a known height, with pointer capture stubbed:
 * jsdom implements neither, and the composable only uses capture to keep
 * receiving moves — which a dispatched drag delivers regardless.
 */
let handle: HTMLElement;
let captured: number | null;

function mountHandle(sheetHeight: number): void {
    document.body.innerHTML = '';
    captured = null;

    const sheet = document.createElement('div');
    sheet.dataset.slot = 'dialog-content';
    sheet.getBoundingClientRect = () => ({ height: sheetHeight }) as DOMRect;

    handle = document.createElement('div');
    handle.setPointerCapture = (pointerId: number) => {
        captured = pointerId;
    };
    handle.releasePointerCapture = (pointerId: number) => {
        if (captured === pointerId) {
            captured = null;
        }
    };
    handle.hasPointerCapture = (pointerId: number) => captured === pointerId;

    sheet.append(handle);
    document.body.append(sheet);
}

/** A pointer event as the handle would receive it, `touch` unless told otherwise. */
function pointer(
    type: string,
    { y, at = 0, id = 1, pointerType = 'touch' } = {} as {
        y: number;
        at?: number;
        id?: number;
        pointerType?: string;
    },
): PointerEvent {
    const event = new Event(type);

    // `timeStamp` and `currentTarget` are getters on Event, and `currentTarget`
    // is only set while an event is being dispatched — these handlers are called
    // directly, so both have to be planted.
    Object.defineProperties(event, {
        clientY: { value: y },
        pointerId: { value: id },
        pointerType: { value: pointerType },
        timeStamp: { value: at },
        currentTarget: { value: handle },
    });

    return event as PointerEvent;
}

beforeEach(() => mountHandle(600));

describe('useSheetDrag', () => {
    it('follows the finger and puts the sheet back when the drag stops short', () => {
        const onDismiss = vi.fn();
        const drag = useSheetDrag({ enabled: ref(true), onDismiss });

        drag.start(pointer('pointerdown', { y: 100, at: 0 }));
        drag.move(pointer('pointermove', { y: 160, at: 300 }));

        expect(drag.dragging.value).toBe(true);
        expect(drag.offset.value).toBe(60);

        drag.end(pointer('pointerup', { y: 160, at: 400 }));

        // 60px of a 600px sheet, released at a standstill: not thrown away.
        expect(onDismiss).not.toHaveBeenCalled();
        expect(drag.offset.value).toBe(0);
        expect(drag.dragging.value).toBe(false);
        expect(captured).toBeNull();
    });

    it('dismisses a drag that travelled most of the way down', () => {
        const onDismiss = vi.fn();
        const drag = useSheetDrag({ enabled: ref(true), onDismiss });

        drag.start(pointer('pointerdown', { y: 100, at: 0 }));
        drag.move(pointer('pointermove', { y: 400, at: 500 }));
        drag.end(pointer('pointerup', { y: 400, at: 600 }));

        expect(onDismiss).toHaveBeenCalledOnce();
    });

    it('reads the travel the release itself reports', () => {
        const onDismiss = vi.fn();
        const drag = useSheetDrag({ enabled: ref(true), onDismiss });

        // The finger carried on past the last move it reported before lifting:
        // the distance it ended at is the one the decision has to be made on.
        drag.start(pointer('pointerdown', { y: 100, at: 0 }));
        drag.move(pointer('pointermove', { y: 150, at: 400 }));
        drag.end(pointer('pointerup', { y: 420, at: 460 }));

        expect(onDismiss).toHaveBeenCalledOnce();
    });

    it('ignores a mouse, which has the close button instead', () => {
        const onDismiss = vi.fn();
        const drag = useSheetDrag({ enabled: ref(true), onDismiss });

        drag.start(
            pointer('pointerdown', { y: 100, at: 0, pointerType: 'mouse' }),
        );
        drag.move(pointer('pointermove', { y: 500, at: 100 }));
        drag.end(pointer('pointerup', { y: 500, at: 200 }));

        expect(drag.dragging.value).toBe(false);
        expect(drag.offset.value).toBe(0);
        expect(onDismiss).not.toHaveBeenCalled();
    });

    it('ignores the gesture entirely while the surface is a desktop dialog', () => {
        const onDismiss = vi.fn();
        const drag = useSheetDrag({ enabled: ref(false), onDismiss });

        drag.start(pointer('pointerdown', { y: 100, at: 0 }));
        drag.move(pointer('pointermove', { y: 500, at: 100 }));
        drag.end(pointer('pointerup', { y: 500, at: 200 }));

        expect(drag.offset.value).toBe(0);
        expect(onDismiss).not.toHaveBeenCalled();
    });

    it('keeps the drag for the finger that began it', () => {
        const onDismiss = vi.fn();
        const drag = useSheetDrag({ enabled: ref(true), onDismiss });

        drag.start(pointer('pointerdown', { y: 100, at: 0 }));
        // A second finger lands mid-drag: it can neither move the sheet...
        drag.move(pointer('pointermove', { y: 500, at: 100, id: 2 }));

        expect(drag.offset.value).toBe(0);

        // ...nor complete the first finger's gesture.
        drag.end(pointer('pointerup', { y: 500, at: 200, id: 2 }));

        expect(onDismiss).not.toHaveBeenCalled();
        expect(drag.dragging.value).toBe(true);
    });

    it('drops a drag the browser took the pointer away from', () => {
        const onDismiss = vi.fn();
        const drag = useSheetDrag({ enabled: ref(true), onDismiss });

        drag.start(pointer('pointerdown', { y: 100, at: 0 }));
        drag.move(pointer('pointermove', { y: 500, at: 100 }));
        drag.cancel(pointer('pointercancel', { y: 500, at: 200 }));

        // Far enough to have been a dismissal, but a cancelled drag never
        // completes: the sheet goes back and stays open.
        expect(onDismiss).not.toHaveBeenCalled();
        expect(drag.offset.value).toBe(0);
        expect(drag.dragging.value).toBe(false);
        expect(captured).toBeNull();
    });

    it('resists a drag upward instead of lifting the sheet off its edge', () => {
        const drag = useSheetDrag({ enabled: ref(true), onDismiss: vi.fn() });

        drag.start(pointer('pointerdown', { y: 300, at: 0 }));
        drag.move(pointer('pointermove', { y: 100, at: 100 }));

        expect(drag.offset.value).toBeGreaterThan(-48);
        expect(drag.offset.value).toBeLessThan(0);
    });
});
