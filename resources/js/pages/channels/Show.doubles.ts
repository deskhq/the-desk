import { vi } from 'vitest';
import type { App, Component } from 'vue';
import {
    computed,
    createApp,
    defineComponent,
    h,
    nextTick,
    reactive,
    ref,
} from 'vue';
import { translate } from '@/lib/i18n';
import type { Channel, Message, MessagePage } from '@/types';

/**
 * The doubles the channel page's suites share: the shared Inertia props, the
 * model factories, the stand-ins for the composables that talk to Echo or the
 * router, and the mount/teardown boilerplate.
 *
 * They live outside the suites because `Show.vue` reaches for enough of the app
 * that the preamble, repeated three times, would itself breach the `max-lines`
 * cap those suites exist to help lift (#983). A `vi.mock` factory is hoisted
 * above the imports, so a suite pulls what it needs from here with a dynamic
 * `import()` inside the factory.
 */
export const inertiaPageProps = {
    auth: { user: { id: 'me', name: 'Me', timezone: 'UTC' } },
    currentTeam: { role: 'member' },
    channels: [] as unknown[],
    teamMembers: [] as unknown[],
    attachments: { maxSizeMb: 25, maxPerMessage: 10 },
    slashCommands: [] as unknown[],
    gifPickerEnabled: false,
    pollsEnabled: false,
};

/** Renders nothing, standing in for a leaf whose own tests cover it. */
export function inert(name: string): Component {
    return defineComponent({ name, setup: () => () => null });
}

/**
 * The pieces of `@inertiajs/vue3` the page renders through. The router is passed
 * in so the suite asserting on it holds the same spies.
 */
export function inertiaDouble(
    router: unknown,
    page: Record<string, unknown>,
    scroll: { fetchNext: () => void; hasNext: () => boolean },
): Record<string, unknown> {
    return {
        usePage: () => ({ props: page }),
        router,
        Head: defineComponent({ name: 'HeadStub', setup: () => () => null }),
        Form: defineComponent({
            name: 'FormStub',
            setup:
                (_props, { slots }) =>
                () =>
                    h('form', slots.default?.({ processing: false })),
        }),
        InfiniteScroll: defineComponent({
            name: 'InfiniteScrollStub',
            setup(_props, { slots, expose }) {
                expose(scroll);

                return () => h('div', slots.default?.());
            },
        }),
    };
}

/** Every mutation the page hands to `useMessageActions`, as spies. */
export function messageActionsDouble(flushed = 0) {
    return {
        send: vi.fn(),
        flushOutbox: vi.fn(() => Promise.resolve(flushed)),
        editMessage: vi.fn(),
        deleteMessage: vi.fn(),
        reactToMessage: vi.fn(),
        voteOnPoll: vi.fn(),
        closePoll: vi.fn(),
        pinMessage: vi.fn(),
        unpinMessage: vi.fn(),
        sendThreadReply: vi.fn(),
        scheduleMessage: vi.fn(),
        updateScheduled: vi.fn(),
        cancelScheduled: vi.fn(),
        setReminder: vi.fn(),
        sendCommand: vi.fn(),
        forwardMessage: vi.fn(),
    };
}

/** The member's own channel preferences, held steady at their defaults. */
export function channelPreferencesDouble() {
    return {
        notificationLevel: ref('all'),
        muted: ref(false),
        starred: ref(false),
        threadUnreadSuppressed: ref(false),
        notificationStatus: computed(() => null),
        toggleStar: vi.fn(),
        onNotificationLevelChange: vi.fn(),
        onMuteChange: vi.fn(),
    };
}

/** A thread panel that stays shut, so the page renders its main pane alone. */
export function threadPanelDouble() {
    return {
        activeThreadRootId: ref(null),
        threadLoading: ref(false),
        threadStream: {},
        threadMessages: computed(() => []),
        threadPendingUuids: computed(() => []),
        openThread: vi.fn(),
        closeThread: vi.fn(),
        markThreadRead: vi.fn(),
        adoptDeepLinkedThread: vi.fn(),
    };
}

/** The team roster, with everyone offline and nobody in do-not-disturb. */
export function teamPresenceDouble() {
    return { presenceFor: () => 'offline', isDndFor: () => false };
}

/** The per-channel draft, persisting nothing. */
export function channelDraftDouble() {
    return { onDraftChange: vi.fn(), cancel: vi.fn(), clear: vi.fn() };
}

/** What the page holds a composer ref for: the drop zone and the hover cards. */
export function composerHandleDouble() {
    return { addFiles: vi.fn(), focus: vi.fn(), insertMention: vi.fn() };
}

export function channel(overrides: Partial<Channel> = {}): Channel {
    return {
        id: 'c1',
        name: 'general',
        slug: 'general',
        visibility: 'public',
        topic: null,
        isGeneral: true,
        isArchived: false,
        muted: false,
        notificationLevel: 'all',
        unreadCount: 0,
        mentionCount: 0,
        hasDraft: false,
        draft: null,
        starred: false,
        sectionId: null,
        position: 0,
        isDirect: false,
        isGroupDirect: false,
        dmUserId: null,
        dmParticipants: null,
        lastActivityAt: null,
        ...overrides,
    };
}

export function message(overrides: Partial<Message> = {}): Message {
    return {
        id: 'm1',
        clientUuid: 'uuid-1',
        body: 'hello',
        type: 'standard',
        user: { id: 'peer', name: 'Peer' },
        createdAt: '2024-03-04T10:30:00.000Z',
        editedAt: null,
        isDeleted: false,
        mentions: [],
        linkPreviews: [],
        attachments: [],
        reactions: [],
        pin: null,
        poll: null,
        replyTo: null,
        forwardedFrom: null,
        threadRootId: null,
        sentToChannel: false,
        threadReplyCount: 0,
        threadLastReplyAt: null,
        threadParticipants: [],
        threadFollowed: false,
        threadUnread: false,
        threadUnreadReplyCount: 0,
        ...overrides,
    } as Message;
}

/** A page of messages, newest first, the way `Inertia::scroll` delivers them. */
export function messagePage(data: Message[] = [message()]): MessagePage {
    return { data, next_cursor: null, prev_cursor: null };
}

const mounted: Array<{ app: App; host: HTMLElement }> = [];

/**
 * Mount the channel page over a reactive prop bag, so a suite can move a prop
 * the way a partial reload does — a jump into the already-open channel above
 * all. The bag is returned alongside the host for exactly that.
 */
export function mountShow(
    Show: Component,
    props: Record<string, unknown> = {},
): { host: HTMLElement; props: Record<string, unknown> } {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const bag = reactive({
        team: { id: 't1', name: 'Acme', slug: 'acme' },
        channel: channel(),
        messages: messagePage(),
        members: [],
        canArchive: true,
        canManagePreferences: true,
        canLeave: true,
        canReact: true,
        isMember: true,
        memberCount: 3,
        pinCount: 0,
        pins: [],
        notificationLevels: [],
        channelReaders: [],
        threadReplies: messagePage([]),
        scheduledMessages: [],
        ...props,
    });

    const app = createApp({ render: () => h(Show, bag) });

    app.config.globalProperties.$t = translate;
    app.mount(host);
    mounted.push({ app, host });

    return { host, props: bag };
}

export function unmountAll(): void {
    for (const { app, host } of mounted.splice(0)) {
        app.unmount();
        host.remove();
    }
}

/** The observers jsdom lacks, which the page's geometry wiring constructs. */
export function stubObservers(): void {
    class NoopObserver {
        observe(): void {}
        unobserve(): void {}
        disconnect(): void {}
    }

    vi.stubGlobal('IntersectionObserver', NoopObserver);
    vi.stubGlobal('ResizeObserver', NoopObserver);
}

/**
 * Let Vue render, then let any `<Transition>` finish leaving: a pill leaves the
 * DOM only once its leave hook resolves, which jsdom drives off
 * `requestAnimationFrame` rather than a real animation.
 */
export async function settle(): Promise<void> {
    await nextTick();
    await new Promise((resolve) => setTimeout(resolve, 60));
    await nextTick();
}

export function click(host: HTMLElement, selector: string): void {
    (host.querySelector(selector) as HTMLElement).click();
}
