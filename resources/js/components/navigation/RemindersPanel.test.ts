// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, reactive } from 'vue';
import type { MessageReminder } from '@/types';

const { destroy } = vi.hoisted(() => ({ destroy: vi.fn() }));

const page = reactive<{ url: string; props: Record<string, unknown> }>({
    url: '/t/acme/c/general?nav=reminders',
    props: {},
});

vi.mock('@inertiajs/vue3', () => ({
    router: { delete: destroy },
    usePage: () => page,
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h('a', { href: props.href }, slots.default?.()),
    }),
}));

import RemindersPanel from './RemindersPanel.vue';

/** Tuesday 14 Jul 2026, 15:30 UTC — the instant every row is placed against. */
const NOW = new Date('2026-07-14T15:30:00.000Z');

function reminder(overrides: Partial<MessageReminder> = {}): MessageReminder {
    return {
        id: 'rem-1',
        messageId: 'msg-1',
        remindAt: '2026-07-14T18:30:00.000Z',
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

let mounted: App | null = null;

function mount(
    reminders: MessageReminder[],
    currentTeam: { slug: string } | null = { slug: 'acme' },
): HTMLElement {
    page.props = {
        reminders,
        currentTeam,
        auth: { user: { timezone: 'UTC' } },
    };

    const root = document.createElement('div');
    document.body.append(root);

    const app = createApp({ render: () => h(RemindersPanel) });
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(root);
    mounted = app;

    return root;
}

function textOf(root: HTMLElement, selector: string): string {
    return (root.querySelector(selector)?.textContent ?? '')
        .replace(/\s+/g, ' ')
        .trim();
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(NOW);
    destroy.mockClear();
});

afterEach(() => {
    vi.useRealTimers();
    mounted?.unmount();
    mounted = null;
    document.body.innerHTML = '';
});

describe('RemindersPanel grouping', () => {
    it('files each row under the bucket its due date falls in', () => {
        const root = mount([
            reminder({ id: 'a', remindAt: '2026-07-13T09:00:00.000Z' }),
            reminder({ id: 'b', remindAt: '2026-07-14T18:30:00.000Z' }),
            reminder({ id: 'c', remindAt: '2026-07-17T10:00:00.000Z' }),
            reminder({ id: 'd', remindAt: '2026-08-20T10:00:00.000Z' }),
        ]);

        expect(
            [...root.querySelectorAll('[data-test^="reminders-group-"]')].map(
                (heading) => heading.getAttribute('data-test'),
            ),
        ).toEqual([
            'reminders-group-overdue',
            'reminders-group-today',
            'reminders-group-this-week',
            'reminders-group-later',
        ]);
    });

    it('groups in the viewer zone rather than UTC', () => {
        // 02:00 UTC on 15 Jul is 22:00 on the 14th in New York, so the row is
        // due today there and not until tomorrow in UTC.
        page.props = {
            reminders: [reminder({ remindAt: '2026-07-15T02:00:00.000Z' })],
            currentTeam: { slug: 'acme' },
            auth: { user: { timezone: 'America/New_York' } },
        };

        const root = document.createElement('div');
        document.body.append(root);
        const app = createApp({ render: () => h(RemindersPanel) });
        app.config.globalProperties.$t = (key: string) => key;
        app.mount(root);
        mounted = app;

        expect(
            root.querySelector('[data-test="reminders-group-today"]'),
        ).not.toBeNull();
        expect(
            root.querySelector('[data-test="reminders-group-this-week"]'),
        ).toBeNull();
    });

    it('drops the buckets with nothing in them', () => {
        const root = mount([reminder()]);

        expect(
            root.querySelectorAll('[data-test^="reminders-group-"]'),
        ).toHaveLength(1);
        expect(
            root.querySelector('[data-test="reminders-group-today"]'),
        ).not.toBeNull();
    });
});

describe('RemindersPanel rows', () => {
    it('quotes the message with its channel and due time beneath', () => {
        const root = mount([reminder()]);

        expect(textOf(root, '[data-test="reminder-open"]')).toContain(
            'the secret plan',
        );
        expect(textOf(root, '[data-test="reminder-open"]')).toContain(
            '#war-room',
        );
        expect(textOf(root, '[data-test="reminder-when"]')).toBe('6:30 PM');
    });

    it('names the other party when the reminder is on a direct message', () => {
        const root = mount([reminder({ channelName: null })]);

        expect(textOf(root, '[data-test="reminder-open"]')).toContain(
            'Jordan West',
        );
    });

    it('links the row to its message', () => {
        const root = mount([reminder()]);

        expect(
            root
                .querySelector('[data-test="reminder-open"]')
                ?.getAttribute('href'),
        ).toBe('/t/acme/c/war-room?message=msg-1');
    });

    it('renders a tombstone rather than a quote for a deleted message', () => {
        const root = mount([reminder({ isDeleted: true, body: '' })]);

        expect(textOf(root, '[data-test="reminder-open"]')).toContain(
            'This message was deleted.',
        );
        // Still reachable: the channel is intact, only the message is gone.
        expect(
            root.querySelector('[data-test="reminder-open"]'),
        ).not.toBeNull();
    });

    it('redacts a row whose channel the viewer has lost access to', () => {
        // The payload is left populated on purpose: the row must not surface the
        // body or the author even if the server ever stopped blanking them.
        const root = mount([
            reminder({ isAccessible: false, channelName: null }),
        ]);

        expect(
            root.querySelector('[data-test="reminder-unavailable"]'),
        ).not.toBeNull();
        expect(root.querySelector('[data-test="reminder-open"]')).toBeNull();
        expect(root.textContent).not.toContain('the secret plan');
        expect(root.textContent).not.toContain('Jordan West');
        expect(root.textContent).toContain('No longer available');
        // The clear control survives: the owner can still get rid of the row.
        expect(
            root.querySelector('[data-test="reminder-clear"]'),
        ).not.toBeNull();
    });
});

describe('RemindersPanel actions', () => {
    it('clears the reminder when its row is checked', () => {
        const root = mount([reminder()]);

        root.querySelector<HTMLElement>(
            '[data-test="reminder-clear"]',
        )?.click();

        expect(destroy).toHaveBeenCalledTimes(1);
        expect(destroy.mock.calls[0][0]).toBe('/t/acme/reminders/rem-1');
        expect(destroy.mock.calls[0][1]).toMatchObject({
            only: ['reminders', 'firedReminders'],
        });
    });

    it('labels the checkbox as the done action', () => {
        const root = mount([reminder()]);

        expect(
            root
                .querySelector('[data-test="reminder-clear"]')
                ?.getAttribute('aria-label'),
        ).toBe('Mark as done');
    });

    it('clears the whole workspace from the header action', () => {
        const root = mount([reminder()]);

        root.querySelector<HTMLElement>(
            '[data-test="reminders-clear-all"]',
        )?.click();

        expect(destroy).toHaveBeenCalledTimes(1);
        expect(destroy.mock.calls[0][0]).toBe('/t/acme/reminders');
    });

    it('sends no workspace-wide clear when there is no workspace to clear', () => {
        // The slug builds the URL the delete goes to, so falling back to an
        // empty one would fire the mutation at a malformed path.
        const root = mount([reminder()], null);

        root.querySelector<HTMLElement>(
            '[data-test="reminders-clear-all"]',
        )?.click();

        expect(destroy).not.toHaveBeenCalled();
    });

    it('counts the pending rows in the header badge', () => {
        const root = mount([reminder({ id: 'a' }), reminder({ id: 'b' })]);

        expect(textOf(root, '[data-test="reminders-count"]')).toContain('2');
    });

    it('offers neither count nor clear-all with an empty list', () => {
        const root = mount([]);

        expect(
            root.querySelector('[data-test="reminders-empty"]'),
        ).not.toBeNull();
        expect(root.querySelector('[data-test="reminders-count"]')).toBeNull();
        expect(
            root.querySelector('[data-test="reminders-clear-all"]'),
        ).toBeNull();
        expect(root.querySelector('[data-test="reminders-list"]')).toBeNull();
    });
});
