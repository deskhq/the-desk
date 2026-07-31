// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Ref } from 'vue';
import { defineComponent, h, nextTick } from 'vue';
import {
    click,
    composerHandleDouble,
    mountShow,
    stubObservers,
    unmountAll,
} from './Show.doubles';

/**
 * Covers what the channel page does on the viewer's behalf: the typing beat, the
 * debounced read pointer, the forward and reminder dialogs, the archive
 * confirmation, and the offline outbox with its reconnect flush.
 *
 * Written against the page as it stands so a split of `Show.vue` can be checked
 * against it — every request shape, every string and every selector is pinned
 * here, and a pure move may change none of them.
 */
const router = vi.hoisted(() => ({
    post: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
}));

vi.mock('@inertiajs/vue3', async () => {
    const { inertiaDouble, inertiaPageProps } = await import('./Show.doubles');

    return inertiaDouble(router, inertiaPageProps, {
        fetchNext: vi.fn(),
        hasNext: () => false,
    });
});

/** The live socket status, so a suite can drop and restore the connection. */
const connection = vi.hoisted(() => ({ status: null as Ref<string> | null }));

vi.mock('@laravel/echo-vue', async () => {
    const { ref } = await import('vue');

    return {
        echo: () => ({ socketId: () => 'socket-1' }),
        useConnectionStatus: () => {
            connection.status = ref('connected');

            return connection.status;
        },
    };
});

const actions = vi.hoisted(() => ({
    current: null as Record<string, ReturnType<typeof vi.fn>> | null,
}));

vi.mock('@/composables/useMessageActions', async () => {
    const { messageActionsDouble } = await import('./Show.doubles');

    actions.current = messageActionsDouble(2);

    return {
        QUEUED_SENDS_TOAST_KEY: 'queued-sends',
        useMessageActions: () => actions.current,
    };
});

vi.mock('@/composables/useChannelRealtime', () => ({
    useChannelRealtime: vi.fn(),
}));

vi.mock('@/composables/useTeamPresence', async () => {
    const { teamPresenceDouble } = await import('./Show.doubles');

    return { useTeamPresence: teamPresenceDouble };
});

vi.mock('@/composables/useChannelDraft', async () => {
    const { channelDraftDouble } = await import('./Show.doubles');

    return { useChannelDraft: channelDraftDouble };
});

vi.mock('@/composables/useChannelPreferences', async () => {
    const { channelPreferencesDouble } = await import('./Show.doubles');

    return { useChannelPreferences: channelPreferencesDouble };
});

vi.mock('@/composables/useThreadPanel', async () => {
    const { threadPanelDouble } = await import('./Show.doubles');

    return { useThreadPanel: threadPanelDouble };
});

const toast = vi.hoisted(() => ({
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
}));

vi.mock('@/composables/useToast', () => ({ useToast: () => toast }));

vi.mock('@/components/ChannelMasthead.vue', () => ({
    default: defineComponent({
        name: 'ChannelMastheadStub',
        emits: ['archive'],
        setup:
            (_props, { emit }) =>
            () =>
                h('button', {
                    'data-test': 'stub-archive',
                    onClick: () => emit('archive'),
                }),
    }),
}));

/**
 * Stands in for the timeline, reaching the page's provided facade the way a row
 * does — the page no longer listens for these as events (ADR-0009).
 */
vi.mock('@/components/MessageList.vue', async () => {
    const { message } = await import('./Show.doubles');
    const { useMessageActionsContext } =
        await import('@/composables/useMessageActionsContext');

    return {
        default: defineComponent({
            name: 'MessageListStub',
            setup(_props, { expose }) {
                const scope = useMessageActionsContext();

                expose({ scrollToIndex: vi.fn(), scrollToLatest: vi.fn() });

                return () =>
                    h('div', [
                        h('button', {
                            'data-test': 'stub-forward',
                            onClick: () => scope.actions.forward(message()),
                        }),
                        h('button', {
                            'data-test': 'stub-remind',
                            onClick: () =>
                                scope.actions.remind(
                                    message(),
                                    '2024-03-06T09:00:00.000Z',
                                ),
                        }),
                        h('button', {
                            'data-test': 'stub-remind-custom',
                            onClick: () =>
                                scope.actions.remindCustom(message()),
                        }),
                    ]);
            },
        }),
    };
});

vi.mock('@/components/MessageComposer.vue', () => ({
    default: defineComponent({
        name: 'MessageComposerStub',
        emits: ['typing'],
        setup(_props, { emit, expose }) {
            expose(composerHandleDouble());

            return () =>
                h('button', {
                    'data-test': 'stub-typing',
                    onClick: () => emit('typing'),
                });
        },
    }),
}));

vi.mock('@/components/ForwardMessageDialog.vue', () => ({
    default: defineComponent({
        name: 'ForwardMessageDialogStub',
        props: { open: { type: Boolean, default: false } },
        emits: ['submit'],
        setup:
            (props, { emit }) =>
            () =>
                props.open
                    ? h('button', {
                          'data-test': 'stub-forward-submit',
                          onClick: () =>
                              emit('submit', {
                                  target: { type: 'channel', id: 'c2' },
                                  note: 'fyi',
                              }),
                      })
                    : null,
    }),
}));

vi.mock('@/components/ScheduleMessageDialog.vue', () => ({
    default: defineComponent({
        name: 'ScheduleMessageDialogStub',
        props: { open: { type: Boolean, default: false } },
        emits: ['confirm'],
        setup:
            (props, { emit }) =>
            () =>
                props.open
                    ? h('button', {
                          'data-test': 'stub-reminder-confirm',
                          onClick: () =>
                              emit('confirm', '2024-03-07T09:00:00.000Z'),
                      })
                    : null,
    }),
}));

vi.mock('@/components/PinsPanel.vue', async () => {
    const { inert } = await import('./Show.doubles');

    return { default: inert('PinsPanelStub') };
});

vi.mock('@/components/ThreadPanel.vue', async () => {
    const { inert } = await import('./Show.doubles');

    return { default: inert('ThreadPanelStub') };
});

vi.mock('@/components/ScheduledMessagesDialog.vue', async () => {
    const { inert } = await import('./Show.doubles');

    return { default: inert('ScheduledMessagesDialogStub') };
});

vi.mock('@/components/LeaveChannelModal.vue', async () => {
    const { inert } = await import('./Show.doubles');

    return { default: inert('LeaveChannelModalStub') };
});

vi.mock('@/components/AddDirectMessagePeopleModal.vue', async () => {
    const { inert } = await import('./Show.doubles');

    return { default: inert('AddDirectMessagePeopleModalStub') };
});

vi.mock('@/components/ChannelEmptyState.vue', async () => {
    const { inert } = await import('./Show.doubles');

    return { default: inert('ChannelEmptyStateStub') };
});

import Show from './Show.vue';

function mount(props: Record<string, unknown> = {}): HTMLElement {
    return mountShow(Show, props).host;
}

beforeEach(() => {
    vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.resolve(new Response())),
    );
    stubObservers();
    localStorage.clear();
});

afterEach(() => {
    unmountAll();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('the typing beat', () => {
    it('posts a fire-and-forget signal carrying the socket id', () => {
        document.cookie = 'XSRF-TOKEN=tok%3Den';

        const host = mount();

        click(host, '[data-test="stub-typing"]');

        expect(fetch).toHaveBeenCalledTimes(1);

        const [url, init] = (fetch as ReturnType<typeof vi.fn>).mock.calls[0];

        expect(url).toContain('/t/acme/c/general/typing');
        expect(init.method).toBe('POST');
        expect(init.headers['X-Socket-ID']).toBe('socket-1');
        expect(init.headers['X-Requested-With']).toBe('XMLHttpRequest');
        expect(init.headers['X-XSRF-TOKEN']).toBe('tok=en');
    });
});

describe('the read pointer', () => {
    it('advances the pointer for the open channel, refreshing only the sidebar', async () => {
        vi.useFakeTimers();
        vi.spyOn(document, 'hasFocus').mockReturnValue(true);

        mount();

        vi.advanceTimersByTime(400);
        await nextTick();

        const [url, payload, options] = router.post.mock.calls[0];

        expect(url).toContain('/t/acme/c/general/read');
        expect(payload).toEqual({});
        expect(options.only).toEqual(['channels']);
        expect(options.preserveScroll).toBe(true);
        expect(options.preserveState).toBe(true);
        expect(options.headers).toEqual({ 'X-Socket-ID': 'socket-1' });
    });
});

describe('archiving the channel', () => {
    it('confirms before archiving, then posts', async () => {
        const host = mount();

        expect(
            host.ownerDocument.querySelector(
                '[data-test="archive-channel-confirm"]',
            ),
        ).toBe(null);

        click(host, '[data-test="stub-archive"]');
        await nextTick();

        const confirm = host.ownerDocument.querySelector(
            '[data-test="archive-channel-confirm"]',
        ) as HTMLElement;

        expect(confirm).not.toBe(null);

        confirm.click();

        const archivePost = router.post.mock.calls.find(([url]) =>
            String(url).includes('/archive'),
        );

        expect(archivePost?.[0]).toContain('/t/acme/c/general/archive');
    });
});

describe('forwarding a message', () => {
    it('opens the dialog on the timeline’s request and hands the pick to the actions engine', async () => {
        const host = mount();

        expect(host.querySelector('[data-test="stub-forward-submit"]')).toBe(
            null,
        );

        click(host, '[data-test="stub-forward"]');
        await nextTick();

        click(host, '[data-test="stub-forward-submit"]');

        expect(actions.current!.forwardMessage).toHaveBeenCalledWith(
            expect.objectContaining({ id: 'm1' }),
            { target: { type: 'channel', id: 'c2' }, note: 'fyi' },
        );
    });
});

describe('setting a reminder', () => {
    it('sets a preset time straight away', () => {
        const host = mount();

        click(host, '[data-test="stub-remind"]');

        expect(actions.current!.setReminder).toHaveBeenCalledWith(
            'm1',
            '2024-03-06T09:00:00.000Z',
        );
    });

    it('asks for a custom time and remembers which message it is for', async () => {
        const host = mount();

        expect(host.querySelector('[data-test="stub-reminder-confirm"]')).toBe(
            null,
        );

        click(host, '[data-test="stub-remind-custom"]');
        await nextTick();

        click(host, '[data-test="stub-reminder-confirm"]');

        expect(actions.current!.setReminder).toHaveBeenCalledWith(
            'm1',
            '2024-03-07T09:00:00.000Z',
        );
    });
});

describe('the offline outbox', () => {
    beforeEach(() => {
        localStorage.setItem(
            'outbox:c1',
            JSON.stringify([
                {
                    clientUuid: 'queued-1',
                    body: 'held back',
                    replyToId: null,
                    attachmentIds: [],
                },
            ]),
        );
    });

    it('banners the queue while the socket is down and discards it on request', async () => {
        const host = mount();

        connection.status!.value = 'unavailable';
        await nextTick();

        const banner = host.querySelector(
            '[data-test="offline-queue-banner"]',
        ) as HTMLElement;

        expect(banner.textContent).toContain(
            "You're offline. 1 message is queued and will send automatically.",
        );

        click(host, '[data-test="discard-queue"]');
        await nextTick();

        expect(host.querySelector('[data-test="offline-queue-banner"]')).toBe(
            null,
        );
    });

    it('flushes on reconnect, backfills the timeline and confirms what landed', async () => {
        mount();

        connection.status!.value = 'unavailable';
        await nextTick();
        actions.current!.flushOutbox.mockClear();

        connection.status!.value = 'connected';
        await nextTick();

        expect(actions.current!.flushOutbox).toHaveBeenCalledTimes(1);
        expect(router.reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ['messages'] }),
        );

        await nextTick();

        expect(toast.success).toHaveBeenCalledWith(
            "Reconnected — 2 queued messages sent, you're caught up.",
            { key: 'queued-sends' },
        );
    });
});
