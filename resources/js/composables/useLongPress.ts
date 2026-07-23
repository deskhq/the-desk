import type { Ref } from 'vue';
import { ref } from 'vue';

/** How long a press has to hold still before it counts as a long-press. */
export const LONG_PRESS_MS = 500;

/**
 * How far the pointer may drift before the press reads as a scroll, not a hold.
 * Wide enough for finger jitter, short of a deliberate pan.
 */
export const LONG_PRESS_SLOP_PX = 10;

/**
 * Press targets that own their tap: holding one must never double as a
 * long-press on the row around it.
 */
const INTERACTIVE_SELECTOR =
    'a, button, input, textarea, select, [role="button"], [role="option"], [contenteditable="true"]';

export type UseLongPressOptions<T> = {
    /** Whether the gesture is live at all (below the `md` breakpoint). */
    enabled: Ref<boolean>;
    /** Called with the pressed payload once the hold delay elapses. */
    onLongPress: (payload: T) => void;
};

export type UseLongPress<T> = {
    start: (event: PointerEvent, payload: T) => void;
    move: (event: PointerEvent) => void;
    end: () => void;
    cancel: () => void;
    /**
     * Suppresses the browser's own long-press menu while a press is being
     * timed, so the actions sheet is the one surface that opens.
     */
    onContextMenu: (event: Event) => void;
    /** The payload under a live press, driving the row's hold cue. */
    pressing: Ref<T | null>;
};

/**
 * The touch stand-in for the hover toolbar: press and hold a message row to
 * open its actions sheet.
 */
export function useLongPress<T>(
    options: UseLongPressOptions<T>,
): UseLongPress<T> {
    let timer: number | null = null;
    let origin: { x: number; y: number } | null = null;
    let selectionAtStart = '';
    const pressing = ref(null) as Ref<T | null>;

    function disarm(): void {
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }

        origin = null;
        pressing.value = null;
    }

    /** Whether the press began on a control that owns its own tap. */
    function onInteractiveChild(event: PointerEvent): boolean {
        return (
            event.target instanceof Element &&
            event.target.closest(INTERACTIVE_SELECTOR) !== null
        );
    }

    /** The selected text right now, or an empty string with nothing selected. */
    function selectedText(): string {
        const selection = window.getSelection();

        return selection === null || selection.isCollapsed
            ? ''
            : selection.toString();
    }

    /**
     * Whether the user is selecting text with this press: a live selection that
     * did not exist when the press began. A selection left over from earlier
     * (or dismissed by the press itself) is not selection activity.
     */
    function selectingText(): boolean {
        const selection = selectedText();

        return selection !== '' && selection !== selectionAtStart;
    }

    function start(event: PointerEvent, payload: T): void {
        disarm();

        if (!options.enabled.value || onInteractiveChild(event)) {
            return;
        }

        origin = { x: event.clientX, y: event.clientY };
        selectionAtStart = selectedText();
        pressing.value = payload;
        timer = window.setTimeout(() => {
            timer = null;

            if (selectingText()) {
                disarm();

                return;
            }

            options.onLongPress(payload);
        }, LONG_PRESS_MS);
    }

    function move(event: PointerEvent): void {
        if (origin === null) {
            return;
        }

        const drift = Math.hypot(
            event.clientX - origin.x,
            event.clientY - origin.y,
        );

        if (drift > LONG_PRESS_SLOP_PX) {
            disarm();
        }
    }

    function onContextMenu(event: Event): void {
        if (pressing.value !== null) {
            event.preventDefault();
        }
    }

    return {
        start,
        move,
        end: disarm,
        cancel: disarm,
        onContextMenu,
        pressing,
    };
}
