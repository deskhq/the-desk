import { vi } from 'vitest';
import type { App, Component } from 'vue';
import { createApp, h, ref } from 'vue';
import { passthrough } from '@/components/ChannelMasthead.stubs';
import type { TeamPresence } from '@/composables/useTeamPresence';
import { translate } from '@/lib/i18n';
import type { RenderedPresence } from '@/lib/presence';
import type { Channel, DmParticipant, RosterMember } from '@/types';

/**
 * The harness the masthead's suites share: the app state it reads through
 * `usePage()` and its composables, the model factories, and the mount/teardown
 * boilerplate that records what it emits. The design-system stand-ins it
 * renders through live beside this, in `ChannelMasthead.stubs`.
 */

/** The viewer, as `usePage()` reports them. Mutable so a suite can move them. */
export const viewer = {
    avatar: null as string | null,
    presence: 'active' as RenderedPresence,
};

/** The pieces of `@inertiajs/vue3` the masthead reads. */
export function inertiaDouble(): Record<string, unknown> {
    return {
        usePage: () => ({
            url: '/t/acme/c/general',
            props: { auth: { user: viewer } },
        }),
    };
}

/**
 * The team roster the masthead's dots read. Mutable so a suite can put someone
 * away or on do-not-disturb; set it before mounting, since the components read
 * the accessor once in setup.
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

/** The dock's own open state, which the search glyph expands before pinning. */
export const dock = {
    open: ref(true),
    setOpen: vi.fn((value: boolean) => {
        dock.open.value = value;
    }),
};

export function sidebarDouble(): Record<string, unknown> {
    return {
        SidebarTrigger: passthrough('button'),
        useSidebar: () => ({ open: dock.open, setOpen: dock.setOpen }),
    };
}

/** Where the search glyph sends the viewer, on each side of the breakpoint. */
export const navigation = {
    isMobile: ref(false),
    openDestination: vi.fn(),
    openQuickSwitcher: vi.fn(),
};

export function isMobileDouble(): Record<string, unknown> {
    return { useIsMobile: () => navigation.isMobile };
}

export function navPanelDouble(): Record<string, unknown> {
    return {
        useNavPanel: () => ({ openDestination: navigation.openDestination }),
    };
}

/**
 * The dialog registry, with the switcher's `open` spied on: below the breakpoint
 * the masthead's search glyph is the jump-to overlay's entry point.
 */
export function dialogDouble(): Record<string, unknown> {
    return {
        useDialog: (name: string) => ({
            isOpen: ref(false),
            open: name === 'switcher' ? navigation.openQuickSwitcher : vi.fn(),
            close: vi.fn(),
            toggle: vi.fn(),
        }),
    };
}

/** Reset every double's state between tests, so a suite reads in any order. */
export function resetDoubles(): void {
    viewer.avatar = null;
    viewer.presence = 'active';
    presence.presenceFor = () => 'active';
    presence.isDndFor = () => false;
    dock.open.value = true;
    dock.setOpen.mockClear();
    navigation.isMobile.value = false;
    navigation.openDestination.mockClear();
    navigation.openQuickSwitcher.mockClear();
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

export function member(overrides: Partial<RosterMember> = {}): RosterMember {
    return {
        id: 'u1',
        name: 'Ada Lovelace',
        avatar: null,
        isBot: false,
        status: null,
        presence: 'active',
        isDnd: false,
        ...overrides,
    };
}

/** The other side of a DM, as the channel payload carries them. */
export function participant(
    overrides: Partial<DmParticipant> = {},
): DmParticipant {
    return {
        id: 'peer',
        name: 'Ada Lovelace',
        avatar: null,
        isBot: false,
        status: null,
        presence: 'active',
        isDnd: false,
        ...overrides,
    };
}

/** Stands in for the notification indicator's Lucide glyph. */
export const notificationIcon = passthrough('svg');

const mounted: Array<{ app: App; host: HTMLElement }> = [];

/** Every event the masthead emits, in the order it emitted them. */
export type Emitted = Array<[string, unknown]>;

/**
 * Mount the masthead over its defaults, recording what it emits. The prop bag
 * is spread rather than made reactive: each case renders one variant of a
 * header that only ever re-renders from above.
 */
export function mountMasthead(
    Masthead: Component,
    props: Record<string, unknown> = {},
): { host: HTMLElement; emitted: Emitted } {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const emitted: Emitted = [];
    const record =
        (event: string) =>
        (payload?: unknown): void => {
            emitted.push([event, payload]);
        };

    const app = createApp({
        render: () =>
            h(Masthead, {
                channel: channel(),
                members: [],
                title: 'general',
                canManagePreferences: false,
                canArchive: false,
                canDelete: false,
                canLeave: false,
                canAddPeople: false,
                notificationLevels: [],
                starred: false,
                muted: false,
                pinCount: 0,
                notificationLevel: 'all',
                notificationStatus: null,
                onOpenDetails: record('openDetails'),
                onToggleStar: record('toggleStar'),
                onNotificationLevelChange: record('notificationLevelChange'),
                onMuteChange: record('muteChange'),
                onArchive: record('archive'),
                onDelete: record('delete'),
                onLeave: record('leave'),
                onAddPeople: record('addPeople'),
                onOpenPins: record('openPins'),
                ...props,
            }),
    });

    app.config.globalProperties.$t = translate;
    app.mount(host);
    mounted.push({ app, host });

    return { host, emitted };
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

export function click(host: HTMLElement, selector: string): void {
    host.querySelector<HTMLElement>(selector)?.click();
}
