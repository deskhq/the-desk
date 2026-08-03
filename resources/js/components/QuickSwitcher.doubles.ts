import { vi } from 'vitest';
import type { App, Component, Ref } from 'vue';
import { createApp, h, nextTick, ref } from 'vue';
import type { TeamPresence } from '@/composables/useTeamPresence';
import { translate } from '@/lib/i18n';
import type { MessageSearchResult } from '@/types';
import type { Channel } from '@/types/channels';
import type { PersonRef } from '@/types/people';

/**
 * The harness the palette's suites share: the app state it reads through
 * `usePage()` and its composables, the model factories, and the mount/teardown
 * boilerplate that records what it emits. The design-system stand-ins it renders
 * through live beside this, in `QuickSwitcher.stubs`.
 */

/** The viewer and the route, as `usePage()` reports them. */
export const viewer = {
    url: '/t/acme/c/general',
    timezone: null as string | null,
};

/**
 * The team roster the people rows read. Mutable so a suite can put someone
 * away; set it before mounting, since the rows read the accessor once in setup.
 */
export const presence: TeamPresence = {
    presenceFor: () => 'active',
    isDndFor: () => false,
};

export function teamPresenceDouble(): Record<string, unknown> {
    return {
        useTeamPresence: () => presence,
        useTeamPresenceSubscription: () => {},
    };
}

/** Where a picked row sends the viewer. */
export const router = { visit: vi.fn() };

export function inertiaDouble(): Record<string, unknown> {
    return {
        router,
        usePage: () => ({
            url: viewer.url,
            props: {
                auth: { user: { timezone: viewer.timezone } },
                customEmojis: {},
                userGroups: [],
            },
        }),
    };
}

/**
 * The debounced message search, reduced to what it was asked and what it holds:
 * the URL builder it was handed, the terms it was sent, and the results a suite
 * writes back the way a response would.
 */
export const messageSearch = {
    results: ref<MessageSearchResult[]>([]),
    isSearching: ref(false),
    search: vi.fn(),
    reset: vi.fn(),
    buildUrl: null as ((term: string) => string) | null,
};

export function messageSearchDouble(): Record<string, unknown> {
    return {
        useMessageSearch: (buildUrl: (term: string) => string) => {
            messageSearch.buildUrl = buildUrl;

            return {
                results: messageSearch.results,
                isSearching: messageSearch.isSearching,
                search: messageSearch.search,
                reset: messageSearch.reset,
            };
        },
    };
}

/** The dock the hand-off to the Search panel has to reveal. */
export const dock = {
    open: ref(true),
    setOpen: vi.fn((value: boolean) => {
        dock.open.value = value;
    }),
    setOpenMobile: vi.fn(),
};

export function sidebarDouble(): Record<string, unknown> {
    return { useSidebar: () => dock };
}

/** Which side of the breakpoint the palette renders for. */
export const isMobile = ref(false);

export function isMobileDouble(): Record<string, unknown> {
    return { useIsMobile: () => isMobile };
}

export const openDirectMessage = vi.fn();

export function openDirectMessageDouble(): Record<string, unknown> {
    return { useOpenDirectMessage: () => ({ openDirectMessage }) };
}

/** The client-side write the Search hand-off lands on the current route. */
export const pinUrl = vi.fn();

export function pinUrlDouble(): Record<string, unknown> {
    return { pinUrl };
}

/** The Wayfinder routes the palette navigates to, reduced to their URLs. */
export function channelActionDouble(): Record<string, unknown> {
    return {
        show: (
            params: { team: string; channel: string },
            options?: { query?: Record<string, string> },
        ) => ({
            url: `/t/${params.team}/c/${params.channel}${
                options?.query === undefined
                    ? ''
                    : `?${new URLSearchParams(options.query).toString()}`
            }`,
        }),
    };
}

export function searchActionDouble(): Record<string, unknown> {
    return {
        suggest: (team: string, options: { query: unknown }) => ({
            url: `/t/${team}/search/suggest?${JSON.stringify(options.query)}`,
        }),
    };
}

/** Reset every double's state between tests, so a suite reads in any order. */
export function resetDoubles(): void {
    viewer.url = '/t/acme/c/general';
    viewer.timezone = null;
    presence.presenceFor = () => 'active';
    presence.isDndFor = () => false;
    router.visit.mockClear();
    messageSearch.results.value = [];
    messageSearch.isSearching.value = false;
    messageSearch.search.mockClear();
    messageSearch.reset.mockClear();
    dock.open.value = true;
    dock.setOpen.mockClear();
    dock.setOpenMobile.mockClear();
    isMobile.value = false;
    openDirectMessage.mockClear();
    pinUrl.mockClear();
}

export function channel(overrides: Partial<Channel> = {}): Channel {
    return {
        id: 'c1',
        name: 'general',
        slug: 'general',
        visibility: 'public',
        topic: null,
        description: null,
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

export function person(overrides: Partial<PersonRef> = {}): PersonRef {
    return { id: 'u1', name: 'Ada Lovelace', ...overrides };
}

export function searchResult(
    overrides: Partial<MessageSearchResult> = {},
): MessageSearchResult {
    return {
        message: {
            id: 'm1',
            body: 'the quokka danced at dawn',
            mentions: [],
            createdAt: '2026-01-02T15:04:05Z',
            user: { id: 'u9', name: 'Grace Hopper', avatar: null },
        },
        channelName: 'general',
        channelSlug: 'general',
        isDirectMessage: false,
        snippet: '',
        teamId: 't1',
        teamName: 'Acme',
        teamSlug: 'acme',
        ...overrides,
    } as unknown as MessageSearchResult;
}

const mounted: Array<{ app: App; host: HTMLElement }> = [];

/** Every event the palette emits, in the order it emitted them. */
export type Emitted = Array<[string, unknown]>;

/**
 * Mount the palette over its defaults, recording what it emits. Its open state
 * is a ref the harness owns, so a suite can watch the palette close itself —
 * which is half of what picking a row is supposed to do.
 */
export function mountSwitcher(
    Switcher: Component,
    props: Record<string, unknown> = {},
): { host: HTMLElement; emitted: Emitted; open: Ref<boolean> } {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const open = ref(true);
    const emitted: Emitted = [];
    const record =
        (event: string) =>
        (payload?: unknown): void => {
            emitted.push([event, payload]);
        };

    const app = createApp({
        render: () =>
            h(Switcher, {
                open: open.value,
                'onUpdate:open': (value: boolean) => {
                    open.value = value;
                },
                channels: [channel()],
                members: [person()],
                currentUserId: 'me',
                teamSlug: 'acme',
                onOpenReminders: record('openReminders'),
                ...props,
            }),
    });

    app.config.globalProperties.$t = translate;
    app.mount(host);
    mounted.push({ app, host });

    return { host, emitted, open };
}

export function unmountAll(): void {
    for (const { app, host } of mounted.splice(0)) {
        app.unmount();
        host.remove();
    }
}

/** The element carrying a `data-test` selector, or null when it is absent. */
export function find(host: HTMLElement, dataTest: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${dataTest}"]`);
}

/** Every element carrying a `data-test` selector, in document order. */
export function findAll(host: HTMLElement, dataTest: string): HTMLElement[] {
    return [...host.querySelectorAll<HTMLElement>(`[data-test="${dataTest}"]`)];
}

/** Type into the palette's filter field, as a viewer would. */
export async function type(host: HTMLElement, value: string): Promise<void> {
    const field = find(host, 'quick-switcher-input') as HTMLInputElement;
    field.value = value;
    field.dispatchEvent(new Event('input'));

    await flush();
}

/**
 * Settle the render. Twice over: a write reaches the palette through its own
 * `open` model, so the watcher it wakes only reaches the DOM on the tick after.
 */
export async function flush(): Promise<void> {
    await nextTick();
    await nextTick();
}
