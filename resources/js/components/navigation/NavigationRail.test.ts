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

vi.mock('@/components/navigation/WorkspaceSheet.vue', () => ({
    default: defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h(
                    'div',
                    { 'data-test': 'workspace-sheet-anchor' },
                    slots.default?.(),
                ),
    }),
}));

const switchTeam = vi.hoisted(() => vi.fn());

vi.mock('@/composables/useTeamSwitch', () => ({
    useTeamSwitch: () => ({ switchTeam }),
}));

vi.mock('@/components/ui/avatar', () => ({
    Avatar: defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h('span', slots.default?.()),
    }),
    AvatarImage: defineComponent({
        props: { src: { type: String, default: '' } },
        setup: (props) => () => h('img', { src: props.src }),
    }),
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

function makeTeam(overrides: Partial<Team> = {}): Team {
    return {
        id: 't-1',
        name: 'Acme Corp',
        slug: 'acme',
        isPersonal: false,
        membersCount: 7,
        isCurrent: true,
        unreadCount: 0,
        mentionCount: 0,
        ...overrides,
    };
}

let app: App | null = null;

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
    switchTeam.mockClear();
});

function mountRail(
    overrides: {
        active?: NavDestination;
        hasUnreadThreads?: boolean;
        teams?: Team[];
        avatar?: string;
    } = {},
) {
    const host = document.createElement('div');
    document.body.append(host);

    const select = vi.fn();

    app = createApp({
        render: () =>
            h(NavigationRail, {
                active: overrides.active ?? 'channels',
                user: { ...viewer, avatar: overrides.avatar },
                teams: overrides.teams ?? [makeTeam()],
                presence: 'active',
                isDnd: false,
                hasUnreadThreads: overrides.hasUnreadThreads ?? false,
                onSelect: select,
            }),
    });
    app.config.globalProperties.$t = (
        key: string,
        replacements?: Record<string, unknown>,
    ) =>
        replacements
            ? Object.entries(replacements).reduce(
                  (line, [token, value]) =>
                      line.replaceAll(`:${token}`, String(value)),
                  key,
              )
            : key;
    app.mount(host);

    return { host, select };
}

function tiles(host: HTMLElement): HTMLElement[] {
    return [
        ...host.querySelectorAll<HTMLElement>(
            '[data-test="rail-workspace-tile"]',
        ),
    ];
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

it('tiles every workspace above the destinations', () => {
    const { host } = mountRail({
        teams: [
            makeTeam(),
            makeTeam({ id: 't-2', name: 'Nord Bureau', isCurrent: false }),
        ],
    });

    expect(tiles(host)).toHaveLength(2);
    expect(tiles(host)[0].textContent).toContain('AC');
    expect(tiles(host)[1].textContent).toContain('NB');
});

it('drops the workspace tiles when the viewer is not in a workspace', () => {
    const { host } = mountRail({ teams: [] });

    expect(tiles(host)).toHaveLength(0);
});

it('opens the workspace sheet from the open workspace tile, and switches from the others', () => {
    const { host } = mountRail({
        teams: [
            makeTeam(),
            makeTeam({
                id: 't-2',
                name: 'Nord',
                slug: 'nord',
                isCurrent: false,
            }),
        ],
    });

    // The open tile is the sheet's anchor, so it is wrapped rather than wired
    // to a switch of its own.
    expect(
        tiles(host)[0].closest('[data-test="workspace-sheet-anchor"]'),
    ).not.toBeNull();
    expect(tiles(host)[0].getAttribute('data-current')).toBe('true');

    tiles(host)[0].click();

    expect(switchTeam).not.toHaveBeenCalled();

    tiles(host)[1].click();

    expect(switchTeam).toHaveBeenCalledWith(
        expect.objectContaining({ slug: 'nord' }),
    );
});

it('dots a workspace holding anything unread, and only that one', () => {
    const { host } = mountRail({
        teams: [
            makeTeam(),
            makeTeam({
                id: 't-2',
                name: 'Nord',
                isCurrent: false,
                unreadCount: 4,
            }),
            makeTeam({ id: 't-3', name: 'Sud', isCurrent: false }),
        ],
    });

    expect(
        host.querySelectorAll('[data-test="rail-workspace-unread-dot"]'),
    ).toHaveLength(1);
});

it('dots a workspace whose only news is a mention', () => {
    const { host } = mountRail({
        teams: [
            makeTeam({
                id: 't-2',
                name: 'Nord',
                isCurrent: false,
                mentionCount: 2,
            }),
        ],
    });

    expect(
        host.querySelectorAll('[data-test="rail-workspace-unread-dot"]'),
    ).toHaveLength(1);
});

it('keeps a way to create a workspace at the foot of the tiles', () => {
    const { host } = mountRail();

    expect(host.querySelector('[data-test="new-team-trigger"]')).not.toBeNull();
});

it('draws an uploaded avatar in place of the initials fallback', () => {
    const { host } = mountRail({ avatar: 'https://cdn.test/dana.png' });

    const image = glyph(host, 'you').querySelector('img');

    expect(image).not.toBeNull();
    expect(image!.getAttribute('src')).toBe('https://cdn.test/dana.png');
});

it('falls back to the viewer initials when there is no avatar', () => {
    const { host } = mountRail();

    const you = glyph(host, 'you');

    expect(you.querySelector('img')).toBeNull();
    expect(you.textContent).toContain('DU');
});
