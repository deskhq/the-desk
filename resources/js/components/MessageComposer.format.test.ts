// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App, Component } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import MessageComposer from './MessageComposer.vue';

/**
 * Covers the composer's inline-format cluster: the four toolbar controls, the
 * keyboard shortcuts that mirror them, and the selection they leave behind.
 * `toggleInlineMark` is unit-tested on its own — what is watched here is the
 * wiring around it, which is what moves when the composer is split.
 */

vi.mock('@/actions/App/Http/Controllers/Channels/AttachmentController', () => ({
    store: () => ({ url: '/t/acme/c/general/attachments' }),
}));

vi.mock('@/components/ui/button', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Button: defineComponent({
            name: 'ButtonStub',
            inheritAttrs: false,
            setup:
                (_props, { attrs, slots }) =>
                () =>
                    h('button', attrs, slots.default?.()),
        }),
    };
});

vi.mock('@/components/ui/tooltip', async () => {
    const { defineComponent, h } = await import('vue');
    const slot = (name: string) =>
        defineComponent({
            name,
            setup:
                (_props, { slots }) =>
                () =>
                    h('div', slots.default?.()),
        });

    return {
        Tooltip: slot('TooltipStub'),
        TooltipContent: slot('TooltipContentStub'),
        TooltipProvider: slot('TooltipProviderStub'),
        TooltipTrigger: slot('TooltipTriggerStub'),
    };
});

let active: Array<{ app: App; container: HTMLElement }> = [];

function mountComposer() {
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
                }),
        }),
    );
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(container);
    active.push({ app, container });

    const textarea = container.querySelector<HTMLTextAreaElement>(
        '[data-test="message-composer-input"]',
    )!;

    return { container, textarea };
}

/** Seed the field's text, then select the given range. */
async function select(
    textarea: HTMLTextAreaElement,
    value: string,
    start: number,
    end: number,
): Promise<void> {
    textarea.value = value;
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    await nextTick();
    textarea.setSelectionRange(start, end);
}

/** Click a format control and let the composer restore the selection. */
async function clickFormat(
    container: HTMLElement,
    marker: string,
): Promise<void> {
    container
        .querySelector<HTMLButtonElement>(
            `[data-test="message-composer-format-${marker}"]`,
        )!
        .click();
    await nextTick();
    await nextTick();
}

function shortcut(
    textarea: HTMLTextAreaElement,
    key: string,
    modifiers: { shiftKey?: boolean; altKey?: boolean } = {},
): KeyboardEvent {
    const event = new KeyboardEvent('keydown', {
        key,
        ctrlKey: true,
        bubbles: true,
        cancelable: true,
        ...modifiers,
    });
    textarea.dispatchEvent(event);

    return event;
}

afterEach(() => {
    active.forEach(({ app, container }) => {
        app.unmount();
        container.remove();
    });
    active = [];
});

describe('MessageComposer inline formatting', () => {
    it('offers a control per Markdown marker', () => {
        const { container } = mountComposer();

        const markers = Array.from(
            container.querySelectorAll<HTMLElement>(
                '[data-test="composer-format-cluster"] [data-test^="message-composer-format-"]',
            ),
        ).map((node) =>
            node
                .getAttribute('data-test')
                ?.replace('message-composer-format-', ''),
        );

        expect(markers).toEqual(['**', '*', '~~', '`']);
    });

    it('wraps the selection from each control and keeps the inner text selected', async () => {
        const cases: Array<[string, string]> = [
            ['**', 'say **hello** now'],
            ['*', 'say *hello* now'],
            ['~~', 'say ~~hello~~ now'],
            ['`', 'say `hello` now'],
        ];

        for (const [marker, expected] of cases) {
            const { container, textarea } = mountComposer();

            await select(textarea, 'say hello now', 4, 9);
            await clickFormat(container, marker);

            expect(textarea.value).toBe(expected);
            expect([textarea.selectionStart, textarea.selectionEnd]).toEqual([
                4 + marker.length,
                9 + marker.length,
            ]);
        }
    });

    it('unwraps a selection already carrying the marker', async () => {
        const { container, textarea } = mountComposer();

        await select(textarea, 'say **hello** now', 6, 11);
        await clickFormat(container, '**');

        expect(textarea.value).toBe('say hello now');
    });

    it('inserts an empty pair with the caret between the markers', async () => {
        const { container, textarea } = mountComposer();

        await select(textarea, 'say ', 4, 4);
        await clickFormat(container, '`');

        expect(textarea.value).toBe('say ``');
        expect(textarea.selectionStart).toBe(5);
    });

    it('applies the same markers from the keyboard', async () => {
        const cases: Array<[string, { shiftKey?: boolean }, string]> = [
            ['b', {}, 'say **hello** now'],
            ['i', {}, 'say *hello* now'],
            ['e', {}, 'say `hello` now'],
            ['x', { shiftKey: true }, 'say ~~hello~~ now'],
        ];

        for (const [key, modifiers, expected] of cases) {
            const { textarea } = mountComposer();

            await select(textarea, 'say hello now', 4, 9);
            const event = shortcut(textarea, key, modifiers);
            await nextTick();

            expect(textarea.value).toBe(expected);
            expect(event.defaultPrevented).toBe(true);
        }
    });

    it('keeps a format shortcut from reaching the window-level shortcuts', async () => {
        const { textarea } = mountComposer();
        const onWindowKey = vi.fn();
        window.addEventListener('keydown', onWindowKey);

        await select(textarea, 'say hello now', 4, 9);
        shortcut(textarea, 'b');
        await nextTick();

        window.removeEventListener('keydown', onWindowKey);
        // ⌘/Ctrl+B also toggles the sidebar, so formatting must claim the key.
        expect(onWindowKey).not.toHaveBeenCalled();
    });

    it('leaves a modified combination that is not a format shortcut alone', async () => {
        const { textarea } = mountComposer();

        await select(textarea, 'say hello now', 4, 9);
        // Alt disqualifies the combination, as does an unmapped key.
        shortcut(textarea, 'b', { altKey: true });
        shortcut(textarea, 'k');
        await nextTick();

        expect(textarea.value).toBe('say hello now');
    });

    it('names each control and its shortcut hint', () => {
        const { container } = mountComposer();
        const cluster = container.querySelector<HTMLElement>(
            '[data-test="composer-format-cluster"]',
        )!;

        expect(
            container
                .querySelector('[data-test="message-composer-format-**"]')
                ?.getAttribute('aria-label'),
        ).toBe('Bold');
        expect(cluster.textContent).toContain('Ctrl+B');
        expect(cluster.textContent).toContain('Ctrl+Shift+X');
    });
});
