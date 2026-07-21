// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import type { MessageReminder } from '@/types';

/**
 * The dialog chrome is stubbed away so the list rows render inline: reka's
 * `DialogContent` teleports and never reaches the SSR string otherwise.
 * `stub(tag)` builds a passthrough component; hoisted so the vi.mock factories
 * (which run before the module body) can reach it.
 */
const { stub } = await vi.hoisted(async () => {
    const { defineComponent, h: hyper } = await import('vue');

    return {
        stub: (tag: string) =>
            defineComponent({
                setup:
                    (_: unknown, ctx: { slots: { default?: () => unknown } }) =>
                    () =>
                        hyper(tag, ctx.slots.default?.() as never),
            }),
    };
});

vi.mock('@/components/ui/dialog', () => ({
    Dialog: stub('div'),
    DialogContent: stub('div'),
    DialogDescription: stub('p'),
    DialogHeader: stub('div'),
    DialogTitle: stub('h2'),
}));

import RemindersDialog from './RemindersDialog.vue';

function reminder(overrides: Partial<MessageReminder> = {}): MessageReminder {
    return {
        id: 'rem-1',
        messageId: 'msg-1',
        remindAt: '2030-01-01T10:00:00+00:00',
        teamSlug: 'acme',
        channelSlug: 'war-room',
        channelName: 'war-room',
        authorName: 'Jordan West',
        body: 'the secret plan',
        isDeleted: false,
        isAccessible: true,
        ...overrides,
    };
}

async function render(
    overrides: Partial<MessageReminder> = {},
): Promise<string> {
    const app = createSSRApp({
        render: () =>
            h(RemindersDialog, {
                reminders: [reminder(overrides)],
                timezone: 'UTC',
                open: true,
            }),
    });

    app.config.globalProperties.$t = (key: string) => key;

    return renderToString(app);
}

/**
 * Mounts the dialog under jsdom with the emitted `open` reminders and
 * `update:open` calls captured, so a click on a row can be checked for both the
 * jump it would request and the dialog close that comes with it.
 */
function mount(overrides: Partial<MessageReminder> = {}): {
    app: App;
    root: HTMLElement;
    opened: MessageReminder[];
    openStates: boolean[];
} {
    const root = document.createElement('div');
    document.body.append(root);

    const opened: MessageReminder[] = [];
    const openStates: boolean[] = [];

    const app = createApp({
        render: () =>
            h(RemindersDialog, {
                reminders: [reminder(overrides)],
                timezone: 'UTC',
                open: true,
                onOpen: (value: MessageReminder) => opened.push(value),
                'onUpdate:open': (value: boolean) => openStates.push(value),
            }),
    });

    app.config.globalProperties.$t = (key: string) => key;
    app.mount(root);

    return { app, root, opened, openStates };
}

let mounted: App | null = null;

afterEach(() => {
    mounted?.unmount();
    mounted = null;
    document.body.innerHTML = '';
});

describe('RemindersDialog inaccessible rows', () => {
    it('renders a redacted row with no jump control once the channel is out of reach', async () => {
        // The payload is left populated on purpose: the row must not surface the
        // body or the author even if the server ever stopped blanking them.
        const html = await render({ isAccessible: false, channelName: null });

        expect(html).toContain('data-test="reminder-unavailable"');
        expect(html).not.toContain('data-test="reminder-open"');
        expect(html).not.toContain('Jordan West');
        expect(html).not.toContain('the secret plan');
        expect(html).toContain('You no longer have access to this channel.');
        expect(html).toContain('No longer available');
        // The clear control survives: the owner can still get rid of the row.
        expect(html).toContain('data-test="reminder-clear"');
    });

    it('ignores a click on an inaccessible row rather than jumping', () => {
        const { app, root, opened, openStates } = mount({
            isAccessible: false,
            channelName: null,
        });
        mounted = app;

        root.querySelector<HTMLElement>(
            '[data-test="reminder-unavailable"]',
        )?.click();

        expect(opened).toEqual([]);
        expect(openStates).toEqual([]);
    });

    it('jumps to the message when an accessible row is clicked', () => {
        const { app, root, opened, openStates } = mount();
        mounted = app;

        root.querySelector<HTMLElement>('[data-test="reminder-open"]')?.click();

        expect(opened).toHaveLength(1);
        expect(openStates).toEqual([false]);
    });

    it('keeps the jump control and the quote for a row still in reach', async () => {
        const html = await render();

        expect(html).toContain('data-test="reminder-open"');
        expect(html).not.toContain('data-test="reminder-unavailable"');
        expect(html).toContain('the secret plan');
        expect(html).toContain('Jordan West');
    });
});
