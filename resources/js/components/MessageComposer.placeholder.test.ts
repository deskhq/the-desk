// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App, Component } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import MessageComposer from './MessageComposer.vue';

/**
 * Covers the composer's single-line placeholder (#802): a long DM placeholder
 * is ellipsized to the textarea's width so it never wraps (and never grows the
 * empty composer), while the aria-label keeps the full untruncated text.
 *
 * Width and text metrics are stubbed — jsdom performs no layout — so ten
 * units per code point against a 200-unit-wide textarea drive the fitting.
 */

const LONG_PLACEHOLDER = 'Message Bartholomew Montgomery Featherstone';

let active: Array<{ app: App; container: HTMLElement }> = [];

class ImmediateResizeObserver {
    constructor(private callback: () => void) {}

    observe(): void {}

    disconnect(): void {}
}

beforeEach(() => {
    vi.stubGlobal('ResizeObserver', ImmediateResizeObserver);
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
        font: '',
        measureText: (candidate: string) => ({
            width: [...candidate].length * 10,
        }),
    } as unknown as CanvasRenderingContext2D);
    Object.defineProperty(HTMLTextAreaElement.prototype, 'clientWidth', {
        value: 200,
        configurable: true,
    });
});

afterEach(() => {
    active.forEach(({ app, container }) => {
        app.unmount();
        container.remove();
    });
    active = [];
    delete (
        HTMLTextAreaElement.prototype as { clientWidth?: number }
    ).clientWidth;
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

function mountComposer(placeholder?: string): HTMLTextAreaElement {
    const container = document.createElement('div');

    document.body.appendChild(container);

    const app = createApp(
        defineComponent({
            setup: () => () =>
                h(MessageComposer as Component, {
                    channelName: 'general',
                    members: [],
                    teamSlug: 'acme',
                    channelSlug: 'general',
                    placeholder,
                }),
        }),
    );

    app.config.globalProperties.$t = (key: string) => key;
    app.mount(container);
    active.push({ app, container });

    return container.querySelector<HTMLTextAreaElement>(
        '[data-test="message-composer-input"]',
    )!;
}

describe('MessageComposer placeholder', () => {
    it('ellipsizes a long placeholder to a single line and keeps the full aria-label', async () => {
        const textarea = mountComposer(LONG_PLACEHOLDER);

        await nextTick();

        expect(textarea.placeholder).toBe('Message Bartholomew…');
        expect(textarea.getAttribute('aria-label')).toBe(LONG_PLACEHOLDER);
    });

    it('leaves a fitting placeholder untouched', async () => {
        const textarea = mountComposer('Message Zoe');

        await nextTick();

        expect(textarea.placeholder).toBe('Message Zoe');
        expect(textarea.getAttribute('aria-label')).toBe('Message Zoe');
    });
});
