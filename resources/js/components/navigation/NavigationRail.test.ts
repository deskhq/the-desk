// @vitest-environment jsdom
import { afterEach, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import type { NavDestination } from '@/composables/useNavPanel';
import type { Team, User } from '@/types';

vi.mock('@lucide/vue', () => ({
    AlarmClock: { render: () => h('svg') },
    Hash: { render: () => h('svg') },
    MessagesSquare: { render: () => h('svg') },
    Plus: { render: () => h('svg') },
    Search: { render: () => h('svg') },
}));

vi.mock('@/components/CreateTeamModal.vue', () => ({
    default: defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h('div', slots.default?.()),
    }),
}));

vi.mock('@/components/ui/avatar', () => ({
    Avatar: defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h('span', slots.default?.()),
    }),
    AvatarImage: defineComponent({ setup: () => () => h('img') }),
    AvatarFallback: defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h('span', slots.default?.()),
    }),
}));

import NavigationRail from './NavigationRail.vue';

const viewer: User = {
    id: 'u-1',
    name: 'Dana Ubuntu',
    email: 'dana@example.test',
    avatar: null,
} as unknown as User;

const team: Team = { id: 't-1', name: 'Acme Corp', slug: 'acme' } as Team;

let app: App | null = null;

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

function mountRail(
    overrides: {
        active?: NavDestination;
        hasUnreadThreads?: boolean;
        currentTeam?: Team | null;
    } = {},
) {
    const host = document.createElement('div');
    document.body.append(host);

    const select = vi.fn();

    app = createApp({
        render: () =>
            h(NavigationRail, {
                active: overrides.active ?? 'channels',
                user: viewer,
                currentTeam:
                    overrides.currentTeam === undefined
                        ? team
                        : overrides.currentTeam,
                presence: 'active',
                isDnd: false,
                hasUnreadThreads: overrides.hasUnreadThreads ?? false,
                onSelect: select,
            }),
    });
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(host);

    return { host, select };
}

function glyph(host: HTMLElement, destination: NavDestination): HTMLElement {
    const button = host.querySelector<HTMLElement>(
        `[data-test="rail-destination-${destination}"]`,
    );

    expect(button).not.toBeNull();

    return button!;
}

it('is a named navigation landmark', () => {
    const { host } = mountRail();

    const rail = host.querySelector('nav[data-test="navigation-rail"]');

    expect(rail).not.toBeNull();
    expect(rail!.getAttribute('aria-label')).toBe('Destinations');
});

it('names every destination for assistive technology', () => {
    const { host } = mountRail();

    for (const [destination, label] of [
        ['channels', 'Channels'],
        ['threads', 'Threads'],
        ['reminders', 'Reminders'],
        ['search', 'Search'],
        ['you', 'You'],
    ] as const) {
        expect(glyph(host, destination).textContent).toContain(label);
    }
});

it('marks only the open destination with aria-current', () => {
    const { host } = mountRail({ active: 'reminders' });

    expect(glyph(host, 'reminders').getAttribute('aria-current')).toBe('true');
    expect(glyph(host, 'channels').getAttribute('aria-current')).toBeNull();
});

it('asks for a destination when its glyph is activated', () => {
    const { host, select } = mountRail();

    glyph(host, 'search').click();

    expect(select).toHaveBeenCalledWith('search');
});

it('opens the You destination from the viewer avatar', () => {
    const { host, select } = mountRail();

    glyph(host, 'you').click();

    expect(select).toHaveBeenCalledWith('you');
});

it('flags unread threads on the threads glyph only while there are any', () => {
    const unread = mountRail({ hasUnreadThreads: true });

    expect(
        unread.host.querySelector('[data-test="rail-threads-unread-dot"]'),
    ).not.toBeNull();

    app?.unmount();
    app = null;
    document.body.innerHTML = '';

    const read = mountRail();

    expect(
        read.host.querySelector('[data-test="rail-threads-unread-dot"]'),
    ).toBeNull();
});

it('tiles the current workspace above the destinations', () => {
    const { host } = mountRail();

    const tile = host.querySelector('[data-test="rail-workspace-tile"]');

    expect(tile).not.toBeNull();
    expect(tile!.textContent).toContain('AC');
});

it('drops the workspace tile when the viewer is not in a workspace', () => {
    const { host } = mountRail({ currentTeam: null });

    expect(host.querySelector('[data-test="rail-workspace-tile"]')).toBeNull();
});
