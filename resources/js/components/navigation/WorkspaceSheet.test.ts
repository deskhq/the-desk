// @vitest-environment jsdom
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import type { Team } from '@/types';

/** Mutable stand-in for the shared workspace props. */
const props = vi.hoisted(() => ({
    currentTeam: null as Team | null,
    teams: [] as Team[],
    canInviteToCurrentTeam: false,
    canUpdateCurrentTeam: false,
    pendingInvitations: [] as unknown[],
}));

const switchTeam = vi.hoisted(() => vi.fn());

/** Drives the mocked breakpoint flag, which has to stay a real ref. */
const viewport = vi.hoisted(() => ({
    setMobile: null as null | ((mobile: boolean) => void),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props }),
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup:
            (linkProps, { slots }) =>
            () =>
                h('a', { href: linkProps.href }, slots.default?.()),
    }),
}));

vi.mock('@/composables/useTeamSwitch', () => ({
    useTeamSwitch: () => ({ switchTeam }),
}));

vi.mock('@/composables/useIsMobile', async () => {
    const { ref } = await import('vue');
    const isMobile = ref(false);

    viewport.setMobile = (mobile: boolean) => {
        isMobile.value = mobile;
    };

    return { useIsMobile: () => isMobile };
});

vi.mock('@/routes/teams', () => ({
    edit: (slug: string) => ({ url: `/settings/teams/${slug}` }),
}));

vi.mock('@lucide/vue', () => ({
    Check: { render: () => h('svg') },
    LogIn: { render: () => h('svg') },
    Plus: { render: () => h('svg') },
    Settings: { render: () => h('svg') },
    UserPlus: { render: () => h('svg') },
    Users: { render: () => h('svg') },
}));

vi.mock('@/components/CreateTeamModal.vue', () => ({
    default: defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h('div', slots.default?.()),
    }),
}));

/**
 * A stand-in for one menu/dialog primitive: it always renders its slot (so the
 * closed-surface machinery stays out of the assertions) and forwards a click as
 * the `select` the menu rows are wired to.
 */
function passthrough(tag = 'div') {
    return defineComponent({
        inheritAttrs: false,
        emits: ['select', 'update:open'],
        setup:
            (_, { slots, emit, attrs }) =>
            () =>
                h(
                    tag,
                    { ...attrs, onClick: () => emit('select', new Event('x')) },
                    slots.default?.(),
                ),
    });
}

vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenu: passthrough(),
    DropdownMenuTrigger: passthrough(),
    DropdownMenuContent: passthrough(),
    DropdownMenuItem: passthrough('button'),
    DropdownMenuLabel: passthrough(),
    DropdownMenuSeparator: passthrough('hr'),
}));

vi.mock('@/components/ui/dialog', () => ({
    Dialog: passthrough(),
    DialogTrigger: passthrough(),
    DialogContent: passthrough(),
    DialogTitle: passthrough(),
    DialogDescription: passthrough(),
}));

import WorkspaceSheet from './WorkspaceSheet.vue';

function team(overrides: Partial<Team> = {}): Team {
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

beforeEach(() => {
    props.currentTeam = team();
    props.teams = [team()];
    props.canInviteToCurrentTeam = true;
    props.canUpdateCurrentTeam = true;
    props.pendingInvitations = [];
    viewport.setMobile?.(false);
    switchTeam.mockClear();
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

function mountSheet() {
    const host = document.createElement('div');
    document.body.append(host);

    const invited = vi.fn();
    const joined = vi.fn();

    app = createApp({
        render: () =>
            h(
                WorkspaceSheet,
                { onInvite: invited, onJoin: joined },
                {
                    default: () =>
                        h('button', { 'data-test': 'workspace-switcher' }),
                },
            ),
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

    return { host, invited, joined };
}

function row(host: HTMLElement, name: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${name}"]`);
}

it('names the current workspace and its member count', () => {
    const { host } = mountSheet();

    const sheet = row(host, 'workspace-sheet');

    expect(sheet).not.toBeNull();
    expect(sheet!.textContent).toContain('Acme Corp');
    expect(sheet!.textContent).toContain('7 members');
});

it('keeps the trigger the caller supplied', () => {
    const { host } = mountSheet();

    expect(row(host, 'workspace-switcher')).not.toBeNull();
});

it('offers members, invite and settings to an admin', () => {
    const { host } = mountSheet();

    expect(row(host, 'workspace-members-link')).not.toBeNull();
    expect(row(host, 'invite-member-trigger')).not.toBeNull();
    expect(row(host, 'workspace-settings-link')).not.toBeNull();
});

it('leaves a plain member with the members row alone', () => {
    props.canInviteToCurrentTeam = false;
    props.canUpdateCurrentTeam = false;

    const { host } = mountSheet();

    expect(row(host, 'workspace-members-link')).not.toBeNull();
    expect(row(host, 'invite-member-trigger')).toBeNull();
    expect(row(host, 'workspace-settings-link')).toBeNull();
});

it('anchors the members row on the roster section of the settings page', () => {
    const { host } = mountSheet();

    expect(row(host, 'workspace-members-link')!.getAttribute('href')).toBe(
        '/settings/teams/acme#members',
    );
    expect(row(host, 'workspace-settings-link')!.getAttribute('href')).toBe(
        '/settings/teams/acme',
    );
});

it('hands the invite request to its host', () => {
    const { host, invited } = mountSheet();

    row(host, 'invite-member-trigger')!.click();

    expect(invited).toHaveBeenCalledTimes(1);
});

it('hides the join row until an invitation is pending', () => {
    const { host } = mountSheet();

    expect(row(host, 'join-workspace-trigger')).toBeNull();
});

it('carries the pending invitation count on the join row', () => {
    props.pendingInvitations = [{}, {}];

    const { host, joined } = mountSheet();

    expect(row(host, 'join-workspace-count')!.textContent).toContain('2');

    row(host, 'join-workspace-trigger')!.click();

    expect(joined).toHaveBeenCalledTimes(1);
});

it('marks the current workspace and never switches to it', () => {
    const { host } = mountSheet();

    const current = row(host, 'team-switcher-item')!;

    expect(current.getAttribute('aria-current')).toBe('true');

    current.click();

    expect(switchTeam).not.toHaveBeenCalled();
});

it('switches straight to another workspace', () => {
    props.teams = [
        team(),
        team({ id: 't-2', name: 'Nord', slug: 'nord', isCurrent: false }),
    ];

    const { host } = mountSheet();

    host.querySelectorAll<HTMLElement>(
        '[data-test="team-switcher-item"]',
    )[1].click();

    expect(switchTeam).toHaveBeenCalledWith(
        expect.objectContaining({ slug: 'nord' }),
    );
});

it('badges a workspace with its mention count, and dots a merely unread one', () => {
    props.teams = [
        team({ id: 't-2', name: 'Nord', isCurrent: false, mentionCount: 3 }),
        team({ id: 't-3', name: 'Sud', isCurrent: false, unreadCount: 5 }),
    ];

    const { host } = mountSheet();

    const badges = host.querySelectorAll(
        '[data-test="workspace-mention-badge"]',
    );

    expect(badges).toHaveLength(1);
    expect(badges[0].textContent).toContain('3');
    expect(
        host.querySelectorAll('[data-test="workspace-unread-dot"]'),
    ).toHaveLength(1);
});

it('shows no unread cue on a workspace with nothing waiting', () => {
    props.teams = [team({ isCurrent: false })];

    const { host } = mountSheet();

    expect(row(host, 'workspace-mention-badge')).toBeNull();
    expect(row(host, 'workspace-unread-dot')).toBeNull();
});

it('presents the same rows as a bottom sheet below the breakpoint', () => {
    viewport.setMobile?.(true);
    props.pendingInvitations = [{}];

    const { host } = mountSheet();

    expect(row(host, 'workspace-sheet')).not.toBeNull();
    expect(row(host, 'workspace-switcher')).not.toBeNull();
    expect(row(host, 'workspace-members-link')).not.toBeNull();
    expect(row(host, 'invite-member-trigger')).not.toBeNull();
    expect(row(host, 'workspace-settings-link')).not.toBeNull();
    expect(row(host, 'team-switcher-item')).not.toBeNull();
    expect(row(host, 'team-switcher-new-team')).not.toBeNull();
    expect(row(host, 'join-workspace-trigger')).not.toBeNull();
});

it('hands the invite request over from the bottom sheet too', () => {
    viewport.setMobile?.(true);

    const { host, invited } = mountSheet();

    row(host, 'invite-member-trigger')!.click();

    expect(invited).toHaveBeenCalledTimes(1);
});

it('switches workspace from the bottom sheet', () => {
    viewport.setMobile?.(true);
    props.teams = [
        team(),
        team({ id: 't-2', name: 'Nord', slug: 'nord', isCurrent: false }),
    ];

    const { host } = mountSheet();

    host.querySelectorAll<HTMLElement>(
        '[data-test="team-switcher-item"]',
    )[1].click();

    expect(switchTeam).toHaveBeenCalledWith(
        expect.objectContaining({ slug: 'nord' }),
    );
});
