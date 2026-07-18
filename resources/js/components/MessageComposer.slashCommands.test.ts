// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App, Component } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import type { CommandCallbacks } from '@/composables/useMessageActions';
import MessageComposer from './MessageComposer.vue';

/**
 * Covers the composer's slash-command surface at the layer that holds it
 * deterministically: mount the real `<MessageComposer>` with a manifest, drive
 * the autocomplete (open, filter, keyboard-complete), and prove `submit()`
 * forks a command onto the non-optimistic `command` emit — clearing on success
 * and keeping the typed text on failure — instead of the optimistic `send`.
 */

vi.mock('@/actions/App/Http/Controllers/Channels/AttachmentController', () => ({
    store: () => ({ url: '/t/acme/c/general/attachments' }),
}));

// The composer's chrome is irrelevant here; stub the heavy children down to
// slot pass-throughs. A real <button> keeps the send button's @click/disabled.
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
    {
        name: 'unflip',
        description: 'Put the table back',
        argumentHint: '[message]',
    },
];

let active: Array<{ app: App; container: HTMLElement }> = [];

function mountComposer() {
    const sent: string[] = [];
    const commands: Array<{ body: string; callbacks: CommandCallbacks }> = [];
    const drafts: string[] = [];
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
                    onCommand: (body: string, callbacks: CommandCallbacks) =>
                        commands.push({ body, callbacks }),
                    onDraftChange: (body: string) => drafts.push(body),
                }),
        }),
    );
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(container);
    active.push({ app, container });

    const textarea = container.querySelector<HTMLTextAreaElement>(
        '[data-test="message-composer-input"]',
    )!;

    return { container, sent, commands, drafts, textarea };
}

/** Set the field's value + caret and fire the composer's `input` handler. */
function type(textarea: HTMLTextAreaElement, value: string): Promise<void> {
    textarea.value = value;
    textarea.setSelectionRange(value.length, value.length);
    textarea.dispatchEvent(new Event('input', { bubbles: true }));

    return nextTick();
}

function press(textarea: HTMLTextAreaElement, key: string): Promise<void> {
    textarea.dispatchEvent(
        new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }),
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

describe('MessageComposer slash-command autocomplete', () => {
    it('opens the menu at position 0 and filters by prefix', async () => {
        const { container, textarea } = mountComposer();

        await type(textarea, '/');
        expect(
            container.querySelector('[data-test="slash-menu"]'),
        ).not.toBeNull();
        expect(options(container)).toHaveLength(3);

        await type(textarea, '/ta');
        const rows = options(container);
        expect(rows).toHaveLength(1);
        expect(rows[0].textContent).toContain('/tableflip');
    });

    it('closes the menu once a space is typed', async () => {
        const { container, textarea } = mountComposer();

        await type(textarea, '/shrug');
        expect(
            container.querySelector('[data-test="slash-menu"]'),
        ).not.toBeNull();

        await type(textarea, '/shrug ');
        expect(container.querySelector('[data-test="slash-menu"]')).toBeNull();
    });

    it('completes the highlighted command with a trailing space on Enter', async () => {
        const { textarea } = mountComposer();

        await type(textarea, '/sh');
        await press(textarea, 'Enter');

        expect(textarea.value).toBe('/shrug ');
    });

    it('navigates with the arrow keys and closes on Escape', async () => {
        const { container, textarea } = mountComposer();

        await type(textarea, '/');
        await press(textarea, 'ArrowDown');
        expect(options(container)[1].getAttribute('aria-selected')).toBe(
            'true',
        );

        await press(textarea, 'Escape');
        expect(container.querySelector('[data-test="slash-menu"]')).toBeNull();
    });

    it('forks a command onto the command emit and clears on success', async () => {
        const { container, sent, commands, textarea } = mountComposer();

        await type(textarea, '/shrug hi');
        container
            .querySelector<HTMLButtonElement>(
                '[data-test="message-composer-send"]',
            )!
            .click();
        await nextTick();

        expect(sent).toHaveLength(0);
        expect(commands).toHaveLength(1);
        expect(commands[0].body).toBe('/shrug hi');

        commands[0].callbacks.onSuccess?.();
        await nextTick();
        expect(textarea.value).toBe('');
    });

    it('keeps the typed text and reschedules the draft when the command fails', async () => {
        const { container, commands, drafts, textarea } = mountComposer();

        await type(textarea, '/shrug hi');
        drafts.length = 0;
        container
            .querySelector<HTMLButtonElement>(
                '[data-test="message-composer-send"]',
            )!
            .click();
        await nextTick();

        commands[0].callbacks.onError?.();
        await nextTick();
        expect(textarea.value).toBe('/shrug hi');
        // The command send cancels the debounced draft save, so a failed run
        // must re-emit the retained text to reschedule its persistence.
        expect(drafts).toEqual(['/shrug hi']);
    });

    it('does not clobber an edit made while a command is pending', async () => {
        const { container, commands, textarea } = mountComposer();

        await type(textarea, '/shrug hi');
        container
            .querySelector<HTMLButtonElement>(
                '[data-test="message-composer-send"]',
            )!
            .click();
        await nextTick();

        // The user keeps typing before the (slow) command resolves.
        await type(textarea, 'a new message');

        commands[0].callbacks.onSuccess?.();
        await nextTick();
        // Success clears only if the body is still the one that was sent, so the
        // fresh edit survives.
        expect(textarea.value).toBe('a new message');
    });

    it('sends an unknown slash token as a normal message, not a command', async () => {
        const { container, sent, commands, textarea } = mountComposer();

        await type(textarea, '/unknown thing');
        container
            .querySelector<HTMLButtonElement>(
                '[data-test="message-composer-send"]',
            )!
            .click();
        await nextTick();

        expect(commands).toHaveLength(0);
        expect(sent).toEqual(['/unknown thing']);
    });
});
