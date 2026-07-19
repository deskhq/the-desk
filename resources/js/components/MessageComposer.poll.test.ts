// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App, Component } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import type { CommandCallbacks } from '@/composables/useMessageActions';
import MessageComposer from './MessageComposer.vue';

/**
 * Covers the composer's `/poll` interception: typing or selecting `/poll` opens
 * the poll builder (rather than posting a message or a text command). The builder
 * itself is stubbed — its own behaviour is covered in PollComposerPanel.test.ts.
 */

vi.mock('@/actions/App/Http/Controllers/Channels/AttachmentController', () => ({
    store: () => ({ url: '/t/acme/c/general/attachments' }),
}));

vi.mock('@/components/PollComposerPanel.vue', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        default: defineComponent({
            name: 'PollComposerPanelStub',
            emits: ['close'],
            setup: () => () => h('div', { 'data-test': 'poll-builder' }),
        }),
    };
});

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
        name: 'poll',
        description: 'Create a poll in this channel',
        argumentHint: null,
    },
    {
        name: 'shrug',
        description: 'Append a shrug to your message',
        argumentHint: '[message]',
    },
];

let active: Array<{ app: App; container: HTMLElement }> = [];

function mountComposer() {
    const sent: string[] = [];
    const commands: Array<{ body: string; callbacks: CommandCallbacks }> = [];
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
                    pollsEnabled: true,
                    onSend: (body: string) => sent.push(body),
                    onCommand: (body: string, callbacks: CommandCallbacks) =>
                        commands.push({ body, callbacks }),
                }),
        }),
    );
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(container);
    active.push({ app, container });

    const textarea = container.querySelector<HTMLTextAreaElement>(
        '[data-test="message-composer-input"]',
    )!;

    return { container, sent, commands, textarea };
}

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

async function settle(): Promise<void> {
    await nextTick();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await nextTick();
}

afterEach(() => {
    active.forEach(({ app, container }) => {
        app.unmount();
        container.remove();
    });
    active = [];
});

describe('MessageComposer /poll builder', () => {
    it('opens the builder on submitting /poll instead of posting', async () => {
        const { container, textarea, sent, commands } = mountComposer();

        await type(textarea, '/poll');
        await press(textarea, 'Enter');
        await settle();

        expect(
            container.querySelector('[data-test="poll-builder"]'),
        ).not.toBeNull();
        expect(sent).toEqual([]);
        expect(commands).toEqual([]);
        // The `/poll` text is cleared from the composer once the builder opens.
        expect(textarea.value).toBe('');
    });

    it('posts /poll verbatim when polls are disabled', async () => {
        const container = document.createElement('div');
        document.body.appendChild(container);
        const sent: string[] = [];

        const app = createApp(
            defineComponent({
                setup: () => () =>
                    h(MessageComposer as Component, {
                        channelName: 'general',
                        members: [],
                        teamSlug: 'acme',
                        channelSlug: 'general',
                        // `/poll` is absent from the manifest when polls are off.
                        slashCommands: [MANIFEST[1]],
                        pollsEnabled: false,
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

        await type(textarea, '/poll');
        await press(textarea, 'Enter');
        await settle();

        expect(
            container.querySelector('[data-test="poll-builder"]'),
        ).toBeNull();
        // With the builder unavailable and `/poll` unknown, it posts as text.
        expect(sent).toEqual(['/poll']);
    });
});
