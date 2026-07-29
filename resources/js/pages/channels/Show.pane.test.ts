// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import { channel, mountShow, stubObservers, unmountAll } from './Show.doubles';

/**
 * Covers the conversation pane's chrome: the whole-pane file drop zone, the
 * bottom slot (composer vs. join bar vs. archived notice), the offline queue
 * banner, the screen-reader announcers and the older-history pill.
 *
 * Written against the page as it stands so that a split of `Show.vue` can be
 * checked against it: what is pinned here is the observable behaviour — every
 * `data-test` selector, every guard on whether a surface renders at all, and
 * every string — none of which a pure move is allowed to change.
 */
const router = vi.hoisted(() => ({
    post: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
}));

const infinite = vi.hoisted(() => ({
    fetchNext: vi.fn(),
    hasNext: vi.fn(() => true),
}));

vi.mock('@inertiajs/vue3', async () => {
    const { inertiaDouble, inertiaPageProps } = await import('./Show.doubles');

    return inertiaDouble(router, inertiaPageProps, infinite);
});

vi.mock('@laravel/echo-vue', async () => {
    const { ref } = await import('vue');

    return {
        echo: () => ({ socketId: () => 'socket-1' }),
        useConnectionStatus: () => ref('connected'),
    };
});

vi.mock('@/composables/useMessageActions', async () => {
    const { messageActionsDouble } = await import('./Show.doubles');
    const actions = messageActionsDouble();

    return {
        QUEUED_SENDS_TOAST_KEY: 'queued-sends',
        useMessageActions: () => actions,
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

const timeline = vi.hoisted(() => ({
    scrollToIndex: vi.fn(),
    scrollToLatest: vi.fn(),
}));

vi.mock('@/components/MessageList.vue', () => ({
    default: defineComponent({
        name: 'MessageListStub',
        emits: ['loadOlder', 'rangeChange'],
        setup(_props, { emit, expose }) {
            expose(timeline);

            return () =>
                h('button', {
                    'data-test': 'stub-load-older',
                    onClick: () => emit('loadOlder'),
                });
        },
    }),
}));

const composer = vi.hoisted(() => ({
    addFiles: vi.fn(),
    focus: vi.fn(),
    insertMention: vi.fn(),
}));

vi.mock('@/components/MessageComposer.vue', () => ({
    default: defineComponent({
        name: 'MessageComposerStub',
        setup(_props, { expose }) {
            expose(composer);

            return () => h('div', { 'data-test': 'stub-composer' });
        },
    }),
}));

vi.mock('@/components/ChannelMasthead.vue', async () => {
    const { inert } = await import('./Show.doubles');

    return { default: inert('ChannelMastheadStub') };
});

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

function mount(props: Record<string, unknown> = {}): HTMLElement {
    return mountShow(Show, props).host;
}

/** A drag event carrying the given `dataTransfer.types`, as jsdom has no DataTransfer. */
function dragEvent(type: string, types: string[] = ['Files'], files = []) {
    const event = new Event(type, { bubbles: true, cancelable: true });

    Object.defineProperty(event, 'dataTransfer', {
        value: { types, files, dropEffect: 'none' },
    });

    return event as DragEvent;
}

/**
 * The drop handlers sit on the pane wrapping the whole conversation, so a drag
 * over anything inside it reaches them by bubbling — dispatching on the message
 * history binds the assertion to a selector rather than to the DOM's shape.
 */
function dropPane(host: HTMLElement): HTMLElement {
    return host.querySelector('[data-test="message-history"]') as HTMLElement;
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
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('the channel page announcers', () => {
    it('renders a polite live region for inbound messages and one for send failures', () => {
        const host = mount();

        const announcer = host.querySelector('[data-test="message-announcer"]');
        const failures = host.querySelector(
            '[data-test="send-failure-announcer"]',
        );

        expect(announcer?.getAttribute('aria-live')).toBe('polite');
        expect(announcer?.getAttribute('aria-atomic')).toBe('true');
        expect(announcer?.className).toContain('sr-only');
        expect(failures?.getAttribute('aria-live')).toBe('polite');
    });
});

describe('the whole-pane drop zone', () => {
    it('shows the drop overlay while files are dragged over the pane', async () => {
        const host = mount();
        const pane = dropPane(host);

        expect(host.querySelector('[data-test="channel-drop-overlay"]')).toBe(
            null,
        );

        pane.dispatchEvent(dragEvent('dragenter'));
        await nextTick();

        const overlay = host.querySelector(
            '[data-test="channel-drop-overlay"]',
        ) as HTMLElement;

        expect(overlay).not.toBe(null);
        expect(overlay.textContent).toContain('Drop to attach to #general');
        expect(overlay.textContent).toContain('Up to 10 files · 25 MB each');
        expect(overlay.className).toContain('pointer-events-none');
    });

    it('ignores a drag that carries no files', async () => {
        const host = mount();

        dropPane(host).dispatchEvent(dragEvent('dragenter', ['text/plain']));
        await nextTick();

        expect(host.querySelector('[data-test="channel-drop-overlay"]')).toBe(
            null,
        );
    });

    it('keeps the overlay up until every nested dragleave has matched its dragenter', async () => {
        const host = mount();
        const pane = dropPane(host);

        pane.dispatchEvent(dragEvent('dragenter'));
        pane.dispatchEvent(dragEvent('dragenter'));
        pane.dispatchEvent(dragEvent('dragleave'));
        await nextTick();

        expect(
            host.querySelector('[data-test="channel-drop-overlay"]'),
        ).not.toBe(null);

        pane.dispatchEvent(dragEvent('dragleave'));
        await nextTick();

        expect(host.querySelector('[data-test="channel-drop-overlay"]')).toBe(
            null,
        );
    });

    it('claims every file drag and shows the copy cursor where the drop is accepted', () => {
        const host = mount();
        const event = dragEvent('dragover');

        dropPane(host).dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(event.dataTransfer?.dropEffect).toBe('copy');
    });

    it('claims a file drag over an archived channel without offering the copy cursor', () => {
        const host = mount({ channel: channel({ isArchived: true }) });
        const event = dragEvent('dragover');

        dropPane(host).dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(event.dataTransfer?.dropEffect).toBe('none');
    });

    it('leaves a drag carrying no files to the browser', () => {
        const host = mount();
        const event = dragEvent('dragover', ['text/plain']);

        dropPane(host).dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
    });

    it('stages dropped files in the composer and clears the overlay', async () => {
        const host = mount();
        const pane = dropPane(host);
        const file = new File(['a'], 'a.png', { type: 'image/png' });

        pane.dispatchEvent(dragEvent('dragenter'));
        pane.dispatchEvent(
            dragEvent('drop', ['Files'], [file] as unknown as []),
        );
        await nextTick();

        expect(composer.addFiles).toHaveBeenCalledWith([file]);
        expect(host.querySelector('[data-test="channel-drop-overlay"]')).toBe(
            null,
        );
    });

    it('does not stage a drop on an archived channel', () => {
        const host = mount({ channel: channel({ isArchived: true }) });
        const file = new File(['a'], 'a.png', { type: 'image/png' });

        dropPane(host).dispatchEvent(
            dragEvent('drop', ['Files'], [file] as unknown as []),
        );

        expect(composer.addFiles).not.toHaveBeenCalled();
    });

    it('does not stage a drop that carries no files', () => {
        const host = mount();

        dropPane(host).dispatchEvent(dragEvent('drop', ['text/plain']));

        expect(composer.addFiles).not.toHaveBeenCalled();
    });
});

describe('the pane bottom slot', () => {
    it('renders the composer for a member of a live channel', () => {
        const host = mount();

        expect(host.querySelector('[data-test="stub-composer"]')).not.toBe(
            null,
        );
        expect(host.querySelector('[data-test="archived-notice"]')).toBe(null);
    });

    it('replaces the composer with a read-only notice on an archived channel', () => {
        const host = mount({ channel: channel({ isArchived: true }) });

        const notice = host.querySelector('[data-test="archived-notice"]');

        expect(notice?.textContent).toContain(
            "This channel is archived. It's read-only, but its history is preserved.",
        );
        expect(host.querySelector('[data-test="stub-composer"]')).toBe(null);
    });

    it('replaces the composer with the join bar for a non-member', () => {
        const host = mount({ isMember: false });

        expect(host.querySelector('[data-test="stub-composer"]')).toBe(null);
        expect(host.querySelector('[data-test="join-channel"]')).not.toBe(null);
    });
});

describe('the older-history pill', () => {
    it('fetches the next page and shows the pill when the timeline asks for older messages', async () => {
        const host = mount();

        (
            host.querySelector('[data-test="stub-load-older"]') as HTMLElement
        ).click();
        await nextTick();

        expect(infinite.fetchNext).toHaveBeenCalledTimes(1);

        const pill = host.querySelector('[data-test="loading-older"]');

        expect(pill?.textContent).toContain('Loading earlier messages…');
    });

    it('does not stack a second request while one is in flight', async () => {
        const host = mount();
        const trigger = host.querySelector(
            '[data-test="stub-load-older"]',
        ) as HTMLElement;

        trigger.click();
        await nextTick();
        trigger.click();

        expect(infinite.fetchNext).toHaveBeenCalledTimes(1);
    });

    it('does not fetch when no older history remains', () => {
        infinite.hasNext.mockReturnValueOnce(false);

        const host = mount();

        (
            host.querySelector('[data-test="stub-load-older"]') as HTMLElement
        ).click();

        expect(infinite.fetchNext).not.toHaveBeenCalled();
    });
});

describe('the offline queue banner', () => {
    it('stays hidden while the socket is up', () => {
        const host = mount();

        expect(host.querySelector('[data-test="offline-queue-banner"]')).toBe(
            null,
        );
    });
});
