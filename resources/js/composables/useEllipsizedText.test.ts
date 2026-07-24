// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, nextTick, ref } from 'vue';
import { ellipsizeToWidth, useEllipsizedText } from './useEllipsizedText';

/**
 * Covers the single-line ellipsizing used for the composer placeholder (#802):
 * the pure width-fitting algorithm, and the composable that wires it to an
 * element's measured width via canvas text metrics and a ResizeObserver.
 */

/** Ten units per code point, so expected widths are trivial to derive. */
const measureByLength = (candidate: string): number =>
    [...candidate].length * 10;

describe('ellipsizeToWidth', () => {
    it('returns the text unchanged when it fits', () => {
        expect(ellipsizeToWidth('Message Ann', 110, measureByLength)).toBe(
            'Message Ann',
        );
    });

    it('truncates an overflowing text and appends an ellipsis', () => {
        // 12 units available: 11 code points of text + the ellipsis itself.
        expect(
            ellipsizeToWidth('Message Bartholomew', 120, measureByLength),
        ).toBe('Message Bar…');
    });

    it('trims trailing whitespace before the ellipsis', () => {
        expect(
            ellipsizeToWidth('Message Bartholomew', 90, measureByLength),
        ).toBe('Message…');
    });

    it('degrades to the ellipsis alone when nothing fits', () => {
        expect(ellipsizeToWidth('Message Bob', 5, measureByLength)).toBe('…');
    });

    it('never splits a surrogate pair', () => {
        // Each 🎉 is one code point (two UTF-16 units); slicing by code
        // points keeps the emoji intact or drops it whole.
        expect(ellipsizeToWidth('Message 🎉🎉🎉', 100, measureByLength)).toBe(
            'Message 🎉…',
        );
    });
});

describe('useEllipsizedText', () => {
    type ResizeCallback = () => void;

    let resizeCallbacks: ResizeCallback[] = [];
    let observed: Element[] = [];
    let active: Array<{ app: App; container: HTMLElement }> = [];

    class FakeResizeObserver {
        constructor(private callback: ResizeCallback) {}

        observe(element: Element): void {
            observed.push(element);
            resizeCallbacks.push(this.callback);
        }

        disconnect(): void {}
    }

    beforeEach(() => {
        vi.stubGlobal('ResizeObserver', FakeResizeObserver);
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
            font: '',
            measureText: (candidate: string) => ({
                width: measureByLength(candidate),
            }),
        } as unknown as CanvasRenderingContext2D);
    });

    afterEach(() => {
        active.forEach(({ app, container }) => {
            app.unmount();
            container.remove();
        });
        active = [];
        resizeCallbacks = [];
        observed = [];
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    function textareaOfWidth(clientWidth: number): HTMLTextAreaElement {
        const element = document.createElement('textarea');

        Object.defineProperty(element, 'clientWidth', {
            value: clientWidth,
            configurable: true,
        });
        document.body.appendChild(element);

        return element;
    }

    function mountHarness(element: HTMLElement | null, initialText: string) {
        const elementRef = ref<HTMLElement | null>(element);
        const text = ref(initialText);
        let result!: Readonly<{ value: string }>;
        const container = document.createElement('div');

        document.body.appendChild(container);

        const app = createApp(
            defineComponent({
                setup: () => {
                    result = useEllipsizedText(elementRef, () => text.value);

                    return () => h('div');
                },
            }),
        );

        app.mount(container);
        active.push({ app, container });

        return { result: result!, text, elementRef };
    }

    it('ellipsizes the text to the element width', async () => {
        const { result } = mountHarness(
            textareaOfWidth(120),
            'Message Bartholomew',
        );

        await nextTick();

        expect(result.value).toBe('Message Bar…');
    });

    it('keeps a fitting text untouched', async () => {
        const { result } = mountHarness(textareaOfWidth(120), 'Message Ann');

        await nextTick();

        expect(result.value).toBe('Message Ann');
    });

    it('re-fits when the text changes', async () => {
        const { result, text } = mountHarness(
            textareaOfWidth(120),
            'Message Bartholomew',
        );

        await nextTick();
        text.value = 'Message Zoe';
        await nextTick();

        expect(result.value).toBe('Message Zoe');
    });

    it('re-fits when the element resizes', async () => {
        const element = textareaOfWidth(120);
        const { result } = mountHarness(element, 'Message Bartholomew');

        await nextTick();
        expect(observed).toContain(element);

        Object.defineProperty(element, 'clientWidth', {
            value: 200,
            configurable: true,
        });
        resizeCallbacks.forEach((callback) => callback());
        await nextTick();

        expect(result.value).toBe('Message Bartholomew');
    });

    it('returns the full text while the element is absent', async () => {
        const { result } = mountHarness(null, 'Message Bartholomew');

        await nextTick();

        expect(result.value).toBe('Message Bartholomew');
    });

    it('returns the full text when canvas measurement is unavailable', async () => {
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(
            null,
        );

        const { result } = mountHarness(
            textareaOfWidth(120),
            'Message Bartholomew',
        );

        await nextTick();

        expect(result.value).toBe('Message Bartholomew');
    });

    it('returns the full text when the element has no width yet', async () => {
        const { result } = mountHarness(
            textareaOfWidth(0),
            'Message Bartholomew',
        );

        await nextTick();

        expect(result.value).toBe('Message Bartholomew');
    });
});
