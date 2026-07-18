// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App, Component } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import type { CommandCallbacks } from '@/composables/useMessageActions';
import MessageComposer from './MessageComposer.vue';

/**
 * Covers the composer's `/gif` interception: typing or selecting `/gif` opens the
 * Giphy picker (rather than posting a message or a text command), and a picked
 * GIF is staged in the attachment tray. The picker itself is stubbed — its own
 * behaviour is covered in GifPickerPanel.test.ts.
 */

vi.mock('@/actions/App/Http/Controllers/Channels/AttachmentController', () => ({
    store: () => ({ url: '/t/acme/c/general/attachments' }),
}));

vi.mock('@/components/GifPickerPanel.vue', async () => {
    const { defineComponent, h } = await import('vue');
    const remoteGif = {
        id: 'gif-1',
        filename: null,
        mimeType: 'image/gif',
        sizeBytes: 0,
        width: 2,
        height: 1,
        isImage: true,
        source: 'giphy',
        url: 'https://media.giphy.com/gif-1/200.gif',
        thumbUrl: null,
        description: 'a happy cat',
    };

    return {
        default: defineComponent({
            name: 'GifPickerPanelStub',
            emits: ['select', 'close'],
            setup:
                (_props, { emit }) =>
                () =>
                    h('div', { 'data-test': 'gif-picker' }, [
                        h(
                            'button',
                            {
                                'data-test': 'stub-pick',
                                onClick: () => emit('select', remoteGif),
                            },
                            'pick',
                        ),
                    ]),
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
        name: 'gif',
        description: 'Search Giphy for a GIF to send',
        argumentHint: '[search]',
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
                    gifPickerEnabled: true,
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

/** Wait for the async-loaded picker stub (a dynamic import) to resolve. */
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

describe('MessageComposer /gif picker', () => {
    it('opens the picker on submitting /gif instead of posting', async () => {
        const { container, textarea, sent, commands } = mountComposer();

        await type(textarea, '/gif cats');
        await press(textarea, 'Enter');
        await settle();

        expect(
            container.querySelector('[data-test="gif-picker"]'),
        ).not.toBeNull();
        expect(sent).toEqual([]);
        expect(commands).toEqual([]);
        // The `/gif` text is cleared from the composer once the picker opens.
        expect(textarea.value).toBe('');
    });

    it('opens the picker when /gif is chosen from the slash menu', async () => {
        const { container, textarea } = mountComposer();

        await type(textarea, '/gif');
        // The gif command is the sole, active suggestion; Enter selects it.
        await press(textarea, 'Enter');
        await settle();

        expect(
            container.querySelector('[data-test="gif-picker"]'),
        ).not.toBeNull();
    });

    it('stages a picked GIF in the attachment tray', async () => {
        const { container, textarea } = mountComposer();

        await type(textarea, '/gif');
        await press(textarea, 'Enter');
        await settle();

        container
            .querySelector<HTMLButtonElement>('[data-test="stub-pick"]')!
            .click();
        await nextTick();

        expect(
            container.querySelector('[data-test="composer-attachment"]'),
        ).not.toBeNull();
        // The picker closes once a GIF is picked.
        expect(container.querySelector('[data-test="gif-picker"]')).toBeNull();
    });
});
