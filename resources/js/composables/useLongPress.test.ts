// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';

import { LONG_PRESS_MS, useLongPress } from '@/composables/useLongPress';

/**
 * A message row with a plain text body and an interactive child, the two kinds
 * of press target the gesture has to tell apart.
 */
let row: HTMLElement;
let body: HTMLElement;
let link: HTMLAnchorElement;

function mountRow(): void {
    document.body.innerHTML = '';

    row = document.createElement('div');
    body = document.createElement('p');
    body.textContent = 'hello there';
    link = document.createElement('a');
    link.href = 'https://desk.test';
    link.textContent = 'a link';

    row.append(body, link);
    document.body.append(row);
}

/** A pointer event as the row would receive it, `touch` unless told otherwise. */
function pointer(
    type: string,
    {
        x = 0,
        y = 0,
        target = body,
        pointerType = 'touch',
    }: {
        x?: number;
        y?: number;
        target?: HTMLElement;
        pointerType?: string;
    } = {},
): PointerEvent {
    const event = new Event(type, { bubbles: true, cancelable: true });

    Object.defineProperties(event, {
        clientX: { value: x },
        clientY: { value: y },
        target: { value: target },
        pointerType: { value: pointerType },
    });

    return event as PointerEvent;
}

beforeEach(() => {
    vi.useFakeTimers();
    mountRow();
});

afterEach(() => {
    vi.useRealTimers();
    window.getSelection()?.removeAllRanges();
});

describe('useLongPress', () => {
    it('fires with the pressed payload after the hold delay', () => {
        const onLongPress = vi.fn();
        const press = useLongPress<string>({ enabled: ref(true), onLongPress });

        press.start(pointer('pointerdown'), 'm1');

        expect(onLongPress).not.toHaveBeenCalled();

        vi.advanceTimersByTime(LONG_PRESS_MS);

        expect(onLongPress).toHaveBeenCalledExactlyOnceWith('m1');
    });

    it('does not fire when the press lifts before the delay', () => {
        const onLongPress = vi.fn();
        const press = useLongPress<string>({ enabled: ref(true), onLongPress });

        press.start(pointer('pointerdown'), 'm1');
        vi.advanceTimersByTime(LONG_PRESS_MS - 1);
        press.end();
        vi.advanceTimersByTime(LONG_PRESS_MS);

        expect(onLongPress).not.toHaveBeenCalled();
    });

    it('does not fire when the pointer drifts into a scroll', () => {
        const onLongPress = vi.fn();
        const press = useLongPress<string>({ enabled: ref(true), onLongPress });

        press.start(pointer('pointerdown', { x: 0, y: 0 }), 'm1');
        press.move(pointer('pointermove', { x: 0, y: 24 }));
        vi.advanceTimersByTime(LONG_PRESS_MS);

        expect(onLongPress).not.toHaveBeenCalled();
    });

    it('survives the finger jitter of a genuine hold', () => {
        const onLongPress = vi.fn();
        const press = useLongPress<string>({ enabled: ref(true), onLongPress });

        press.start(pointer('pointerdown', { x: 0, y: 0 }), 'm1');
        press.move(pointer('pointermove', { x: 3, y: 4 }));
        vi.advanceTimersByTime(LONG_PRESS_MS);

        expect(onLongPress).toHaveBeenCalledExactlyOnceWith('m1');
    });

    it('does not fire when the browser takes the pointer for scrolling', () => {
        const onLongPress = vi.fn();
        const press = useLongPress<string>({ enabled: ref(true), onLongPress });

        press.start(pointer('pointerdown'), 'm1');
        press.cancel();
        vi.advanceTimersByTime(LONG_PRESS_MS);

        expect(onLongPress).not.toHaveBeenCalled();
    });

    it('never arms on an interactive child, whose tap is its own action', () => {
        const onLongPress = vi.fn();
        const press = useLongPress<string>({ enabled: ref(true), onLongPress });

        press.start(pointer('pointerdown', { target: link }), 'm1');
        vi.advanceTimersByTime(LONG_PRESS_MS);

        expect(onLongPress).not.toHaveBeenCalled();
    });

    it('yields to a text selection made during the hold', () => {
        const onLongPress = vi.fn();
        const press = useLongPress<string>({ enabled: ref(true), onLongPress });

        press.start(pointer('pointerdown'), 'm1');

        const range = document.createRange();
        range.selectNodeContents(body);
        window.getSelection()?.addRange(range);

        vi.advanceTimersByTime(LONG_PRESS_MS);

        expect(onLongPress).not.toHaveBeenCalled();
    });

    it('is not fooled by a selection left over from before the press', () => {
        const onLongPress = vi.fn();
        const press = useLongPress<string>({ enabled: ref(true), onLongPress });

        const range = document.createRange();
        range.selectNodeContents(body);
        window.getSelection()?.addRange(range);

        press.start(pointer('pointerdown'), 'm1');
        vi.advanceTimersByTime(LONG_PRESS_MS);

        expect(onLongPress).toHaveBeenCalledExactlyOnceWith('m1');
    });

    it('ignores the gesture entirely while the viewport is desktop', () => {
        const onLongPress = vi.fn();
        const press = useLongPress<string>({
            enabled: ref(false),
            onLongPress,
        });

        press.start(pointer('pointerdown'), 'm1');
        vi.advanceTimersByTime(LONG_PRESS_MS);

        expect(onLongPress).not.toHaveBeenCalled();
    });

    it('exposes the held payload for the press cue, cleared on release', () => {
        const press = useLongPress<string>({
            enabled: ref(true),
            onLongPress: vi.fn(),
        });

        expect(press.pressing.value).toBeNull();

        press.start(pointer('pointerdown'), 'm1');

        expect(press.pressing.value).toBe('m1');

        press.end();

        expect(press.pressing.value).toBeNull();
    });

    it('suppresses the native context menu for a press it is timing', () => {
        const press = useLongPress<string>({
            enabled: ref(true),
            onLongPress: vi.fn(),
        });

        press.start(pointer('pointerdown'), 'm1');

        const duringHold = pointer('contextmenu');
        press.onContextMenu(duringHold);

        expect(duringHold.defaultPrevented).toBe(true);

        press.end();

        const afterRelease = pointer('contextmenu');
        press.onContextMenu(afterRelease);

        expect(afterRelease.defaultPrevented).toBe(false);
    });
});
