// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import {
    afterLeave,
    click,
    composerHandleDouble,
    message,
    mountShow,
    settle,
    starveAnimationFrames,
    stubObservers,
    unmountAll,
} from './Show.doubles';

/**
 * Covers the position-dependent affordances the channel page derives from the
 * virtualizer's window: the jump-to-message highlight, the "New messages" pill
 * and its per-visit seen latch, the sticky day chip, and the pins popover.
 *
 * Written against the page as it stands so a split of `Show.vue` can be checked
 * against it — every selector, every string and every scroll call is pinned
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

vi.mock('@/composables/useMessageActions', async () => {
    const { messageActionsDouble } = await import('./Show.doubles');
    const actions = messageActionsDouble();

    return {
        QUEUED_SENDS_TOAST_KEY: 'queued-sends',
        useMessageActions: () => actions,
    };
});

vi.mock('@laravel/echo-vue', async () => {
    const { ref } = await import('vue');

    return {
        echo: () => ({ socketId: () => 'socket-1' }),
        useConnectionStatus: () => ref('connected'),
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

vi.mock('@/composables/useToast', () => ({
    useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }),
}));

vi.mock('@/components/ChannelMasthead.vue', () => ({
    default: defineComponent({
        name: 'ChannelMastheadStub',
        emits: ['openPins'],
        setup:
            (_props, { emit }) =>
            () =>
                h('button', {
                    'data-test': 'stub-open-pins',
                    onClick: () => emit('openPins'),
                }),
    }),
}));

vi.mock('@/components/PinsPanel.vue', () => ({
    default: defineComponent({
        name: 'PinsPanelStub',
        emits: ['jump'],
        setup:
            (_props, { emit }) =>
            () =>
                h('button', {
                    'data-test': 'stub-pins-jump',
                    onClick: () => emit('jump', 'm2'),
                }),
    }),
}));

const timeline = vi.hoisted(() => ({
    scrollToIndex: vi.fn(),
    scrollToLatest: vi.fn(),
    /** The window the stub reports on its next `range-change`. */
    range: { startIndex: 0, endIndex: 10 },
}));

vi.mock('@/components/MessageList.vue', () => ({
    default: defineComponent({
        name: 'MessageListStub',
        props: {
            highlightMessageId: { type: String, default: null },
            unreadDividerId: { type: String, default: null },
        },
        emits: ['rangeChange'],
        setup(props, { emit, expose }) {
            expose(timeline);

            return () =>
                h('button', {
                    'data-test': 'stub-range',
                    'data-highlight': props.highlightMessageId ?? '',
                    'data-unread-divider': props.unreadDividerId ?? '',
                    onClick: () => emit('rangeChange', timeline.range),
                });
        },
    }),
}));

vi.mock('@/components/MessageComposer.vue', () => ({
    default: defineComponent({
        name: 'MessageComposerStub',
        setup(_props, { expose }) {
            expose(composerHandleDouble());

            return () => h('div', { 'data-test': 'stub-composer' });
        },
    }),
}));

vi.mock('@/components/ThreadPanel.vue', async () => {
    const { inert } = await import('./Show.doubles');

    return { default: inert('ThreadPanelStub') };
});

vi.mock('@/components/ForwardMessageDialog.vue', async () => {
    const { inert } = await import('./Show.doubles');

    return { default: inert('ForwardMessageDialogStub') };
});

vi.mock('@/components/ScheduledMessagesDialog.vue', async () => {
    const { inert } = await import('./Show.doubles');

    return { default: inert('ScheduledMessagesDialogStub') };
});

vi.mock('@/components/ScheduleMessageDialog.vue', async () => {
    const { inert } = await import('./Show.doubles');

    return { default: inert('ScheduleMessageDialogStub') };
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

/** Two peer messages a day apart, so the timeline carries two day dividers. */
function mount(props: Record<string, unknown> = {}): {
    host: HTMLElement;
    props: Record<string, unknown>;
} {
    return mountShow(Show, {
        messages: {
            data: [
                message({
                    id: 'm2',
                    clientUuid: 'uuid-2',
                    createdAt: '2024-03-05T10:30:00.000Z',
                }),
                message(),
            ],
            next_cursor: null,
            prev_cursor: null,
        },
        pinCount: 2,
        ...props,
    });
}

/** The element the `MessageList` stub stands in for, carrying its props. */
function stub(host: HTMLElement): HTMLElement {
    return host.querySelector('[data-test="stub-range"]') as HTMLElement;
}

/** Scroll the history off its bottom edge, so the page reads as scrolled up. */
async function scrollUp(host: HTMLElement): Promise<void> {
    const el = host.querySelector(
        '[data-test="message-history"]',
    ) as HTMLElement;

    Object.defineProperty(el, 'scrollHeight', {
        value: 1000,
        configurable: true,
    });
    Object.defineProperty(el, 'clientHeight', {
        value: 200,
        configurable: true,
    });
    el.scrollTop = 0;
    el.dispatchEvent(new Event('scroll'));
    await nextTick();
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

describe('jumping to a message', () => {
    it('centres the target in the window and highlights it for a beat', async () => {
        vi.useFakeTimers();

        const { host } = mount({ jumpToMessageId: 'm1' });

        await nextTick();
        await nextTick();

        expect(timeline.scrollToIndex).toHaveBeenCalledWith(
            expect.any(Number),
            'center',
        );
        expect(stub(host).getAttribute('data-highlight')).toBe('m1');

        vi.advanceTimersByTime(2000);
        await nextTick();

        expect(stub(host).getAttribute('data-highlight')).toBe('');
    });

    it('jumps again when a search result targets the already-open channel', async () => {
        const { host, props } = mount();

        await nextTick();
        expect(timeline.scrollToIndex).not.toHaveBeenCalled();

        props.jumpToMessageId = 'm2';
        await settle();

        expect(timeline.scrollToIndex).toHaveBeenCalledWith(
            expect.any(Number),
            'center',
        );
        expect(stub(host).getAttribute('data-highlight')).toBe('m2');
    });
});

describe('the unread jump pill', () => {
    // The pill rides a `<Transition>`, so every assertion here has to outlast a
    // fade that only gets slower on a loaded machine.
    beforeEach(starveAnimationFrames);

    it('offers the boundary while it sits above the window and scrolls to it', async () => {
        const { host } = mount({ lastReadMessageId: 'm1' });

        await nextTick();

        const pill = host.querySelector('[data-test="jump-to-unread"]');

        expect(pill?.textContent).toContain('New messages');

        (pill as HTMLElement).click();

        expect(timeline.scrollToIndex).toHaveBeenCalledWith(
            expect.any(Number),
            'start',
        );
    });

    it('renders no pill when the channel has no unread boundary', async () => {
        const { host } = mount({ lastReadMessageId: 'm2' });

        await nextTick();

        expect(host.querySelector('[data-test="jump-to-unread"]')).toBe(null);
    });

    it('dismisses the pill for the visit once the reader reaches the boundary', async () => {
        const { host } = mount({ lastReadMessageId: 'm1' });

        await nextTick();

        timeline.range = { startIndex: 5, endIndex: 10 };
        click(host, '[data-test="stub-range"]');
        await nextTick();

        expect(host.querySelector('[data-test="jump-to-unread"]')).not.toBe(
            null,
        );

        timeline.range = { startIndex: 0, endIndex: 10 };
        click(host, '[data-test="stub-range"]');

        expect(await afterLeave(host, '[data-test="jump-to-unread"]')).toBe(
            null,
        );
    });

    it('dismisses the pill when the reader jumps back to the present', async () => {
        const { host } = mount({ lastReadMessageId: 'm1' });

        await nextTick();
        await scrollUp(host);

        click(host, '[data-test="jump-to-latest"]');

        expect(await afterLeave(host, '[data-test="jump-to-unread"]')).toBe(
            null,
        );
        expect(timeline.scrollToLatest).toHaveBeenCalledWith('smooth');
    });
});

describe('the sticky day chip', () => {
    it('names the day of the topmost visible row while scrolled up', async () => {
        const { host } = mount();

        await nextTick();
        await scrollUp(host);

        timeline.range = { startIndex: 1, endIndex: 10 };
        click(host, '[data-test="stub-range"]');
        await nextTick();

        const chip = host.querySelector('[data-test="sticky-date"]');

        expect(chip?.textContent?.trim()).not.toBe('');
    });

    it('suppresses the chip when a day divider already leads the window', async () => {
        const { host } = mount();

        await nextTick();
        await scrollUp(host);

        timeline.range = { startIndex: 0, endIndex: 10 };
        click(host, '[data-test="stub-range"]');
        await nextTick();

        expect(host.querySelector('[data-test="sticky-date"]')).toBe(null);
    });

    it('stays hidden while the view is pinned to the newest message', async () => {
        const { host } = mount();

        await nextTick();

        timeline.range = { startIndex: 1, endIndex: 10 };
        click(host, '[data-test="stub-range"]');
        await nextTick();

        expect(host.querySelector('[data-test="sticky-date"]')).toBe(null);
    });
});

describe('the pins popover', () => {
    it('pulls the freshest pins as it opens', async () => {
        const { host } = mount();

        click(host, '[data-test="stub-open-pins"]');
        await nextTick();

        expect(router.reload).toHaveBeenCalledWith({
            only: ['pins', 'pinCount'],
        });
        expect(host.querySelector('[data-test="stub-pins-jump"]')).not.toBe(
            null,
        );
    });

    it('closes itself on the way to a pinned message', async () => {
        const { host } = mount();

        click(host, '[data-test="stub-open-pins"]');
        await nextTick();
        click(host, '[data-test="stub-pins-jump"]');
        await settle();

        expect(host.querySelector('[data-test="stub-pins-jump"]')).toBe(null);
        expect(timeline.scrollToIndex).toHaveBeenCalledWith(
            expect.any(Number),
            'center',
        );
    });
});
