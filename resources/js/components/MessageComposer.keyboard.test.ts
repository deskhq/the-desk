// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App, Component } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import MessageComposer from './MessageComposer.vue';

/**
 * Covers `useComposerKeyboard`'s IME guard through the real `<MessageComposer>`.
 *
 * While an IME composition is open, Enter commits the candidate word rather than
 * meaning "send" — so Japanese, Chinese and Korean users would post a
 * half-finished word on every candidate they accept. The same Enter also reaches
 * the mention and slash menus, and Escape/the arrows are used to pick a candidate
 * in some IMEs, so the whole keydown model has to stand down until the
 * composition ends.
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

const MANIFEST: App.Data.SlashCommandData[] = [
    {
        name: 'shrug',
        description: 'Append a shrug to your message',
        argumentHint: '[message]',
    },
    {
        name: 'tableflip',
        description: 'Flip the table',
        argumentHint: '[message]',
    },
];

let active: Array<{ app: App; container: HTMLElement }> = [];

function mountComposer() {
    const sent: string[] = [];
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
                    slashCommands: MANIFEST,
                    onSend: (body: string) => sent.push(body),
                }),
        }),
    );
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(container);
    active.push({ app, container });

    const textarea = container.querySelector<HTMLTextAreaElement>(
        '[data-test="message-composer-input"]',
    )!;

    return { container, sent, textarea };
}

/** Set the field's value + caret and fire the composer's `input` handler. */
function type(textarea: HTMLTextAreaElement, value: string): Promise<void> {
    textarea.value = value;
    textarea.setSelectionRange(value.length, value.length);
    textarea.dispatchEvent(new Event('input', { bubbles: true }));

    return nextTick();
}

function press(
    textarea: HTMLTextAreaElement,
    key: string,
    init: KeyboardEventInit = {},
): Promise<void> {
    textarea.dispatchEvent(
        new KeyboardEvent('keydown', {
            key,
            bubbles: true,
            cancelable: true,
            ...init,
        }),
    );

    return nextTick();
}

function options(container: HTMLElement): HTMLElement[] {
    return Array.from(
        container.querySelectorAll<HTMLElement>('[data-test="slash-option"]'),
    );
}

afterEach(() => {
    active.forEach(({ app, container }) => {
        app.unmount();
        container.remove();
    });
    active = [];
});

describe('MessageComposer IME composition', () => {
    it('does not send on the Enter that commits an IME candidate', async () => {
        const { sent, textarea } = mountComposer();

        await type(textarea, 'にほんご');
        await press(textarea, 'Enter', { isComposing: true });

        expect(sent).toHaveLength(0);
        expect(textarea.value).toBe('にほんご');
    });

    it('does not send on the legacy keyCode 229 composition Enter', async () => {
        const { sent, textarea } = mountComposer();

        await type(textarea, '中文');
        // Older WebKit/Safari builds report an in-composition key as 229 and
        // leave `isComposing` unset, so the fallback has to carry them.
        await press(textarea, 'Enter', { keyCode: 229 });

        expect(sent).toHaveLength(0);
        expect(textarea.value).toBe('中文');
    });

    it('still sends once the composition has ended', async () => {
        const { sent, textarea } = mountComposer();

        await type(textarea, 'にほんご');
        await press(textarea, 'Enter');

        expect(sent).toEqual(['にほんご']);
    });

    it('leaves the slash menu alone while a composition is open', async () => {
        const { container, textarea } = mountComposer();

        await type(textarea, '/');
        await press(textarea, 'ArrowDown', { isComposing: true });
        expect(options(container)[0].getAttribute('aria-selected')).toBe(
            'true',
        );

        await press(textarea, 'Escape', { isComposing: true });
        expect(
            container.querySelector('[data-test="slash-menu"]'),
        ).not.toBeNull();

        await press(textarea, 'Enter', { isComposing: true });
        expect(textarea.value).toBe('/');
    });
});
