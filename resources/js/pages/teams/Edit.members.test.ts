// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import { translate } from '@/lib/i18n';
import type { RoleOption, Team, TeamMember, TeamPermissions } from '@/types';

/**
 * Covers the member roster on the workspace settings page: the row itself, the
 * role dropdown, and the two owner-level affordances riding beside it. These
 * move together when `pages/teams/Edit.vue` is split, so what is pinned here is
 * the rendered markup — every selector, every permission guard deciding whether
 * a control renders at all, and the request each one sends.
 */
const pageProps = vi.hoisted(() => ({
    auth: { user: { id: 'me' } },
    integrationsEnabled: true,
    demoMode: false,
}));

const visit = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps }),
    router: { visit },
    Head: defineComponent({
        props: { title: { type: String, default: '' } },
        setup: (props) => () =>
            h('div', { 'data-stub': 'Head', 'data-title': props.title }),
    }),
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h('a', { href: props.href }, slots.default?.()),
    }),
    Form: defineComponent({
        setup:
            (_props, { slots }) =>
            () =>
                h('form', slots.default?.({ errors: {}, processing: false })),
    }),
}));

vi.mock('@lucide/vue', () => ({
    ChartColumn: { render: () => h('svg') },
    Check: { render: () => h('svg', { 'data-stub': 'Check' }) },
    ChevronDown: { render: () => h('svg') },
    ChevronRight: { render: () => h('svg') },
    Crown: { render: () => h('svg') },
    Download: { render: () => h('svg') },
    Mail: { render: () => h('svg') },
    Plug: { render: () => h('svg') },
    ScrollText: { render: () => h('svg') },
    Send: { render: () => h('svg') },
    ShieldCheck: { render: () => h('svg') },
    SmilePlus: { render: () => h('svg') },
    UserPlus: { render: () => h('svg') },
    Users: { render: () => h('svg') },
    X: { render: () => h('svg') },
}));

vi.mock('@/routes/teams', () => ({
    edit: (slug: string) => `/teams/${slug}/edit`,
    index: () => '/teams',
    update: { form: (slug: string) => ({ action: `/teams/${slug}` }) },
}));

vi.mock('@/routes/teams/analytics', () => ({ index: () => '/analytics' }));
vi.mock('@/routes/teams/audit', () => ({ index: () => '/audit' }));
vi.mock('@/routes/teams/audit-exports', () => ({ index: () => '/exports' }));
vi.mock('@/routes/teams/emojis', () => ({ index: () => '/emojis' }));
vi.mock('@/routes/teams/groups', () => ({ index: () => '/groups' }));
vi.mock('@/routes/teams/integrations', () => ({
    index: () => '/integrations',
}));
vi.mock('@/routes/teams/invitations', () => ({
    resend: ([slug, code]: [string, string]) =>
        `/teams/${slug}/invitations/${code}/resend`,
}));
vi.mock('@/routes/teams/members', () => ({
    show: ([slug, id]: [string, string]) => `/teams/${slug}/members/${id}`,
    update: ([slug, id]: [string, string]) => `/teams/${slug}/members/${id}`,
}));
vi.mock('@/routes/teams/security-log', () => ({ index: () => '/security' }));

vi.mock('@/lib/datetime', () => ({
    formatRelativeTime: () => '2 hours ago',
}));

/** Renders a child's default slot, so a stubbed wrapper stays transparent. */
function passthrough(tag = 'div') {
    return defineComponent({
        setup:
            (_props, { slots }) =>
            () =>
                h(tag, slots.default?.()),
    });
}

/** Stands in for a dialog, reporting whether the page asked it to open. */
function modalStub(name: string) {
    return defineComponent({
        name,
        props: { open: { type: Boolean, default: false } },
        setup: (props) => () =>
            h('div', {
                'data-stub': name,
                'data-open': String(props.open),
            }),
    });
}

vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenu: passthrough(),
    DropdownMenuTrigger: passthrough(),
    DropdownMenuContent: passthrough(),
    DropdownMenuItem: passthrough('button'),
}));

vi.mock('@/components/ui/tooltip', () => ({
    Tooltip: passthrough(),
    TooltipProvider: passthrough(),
    TooltipTrigger: passthrough(),
    TooltipContent: passthrough(),
}));

vi.mock('@/components/ui/avatar', () => ({
    Avatar: passthrough(),
    AvatarImage: defineComponent({
        props: { src: { type: String, default: '' } },
        setup: (props) => () =>
            h('img', { 'data-stub': 'AvatarImage', src: props.src }),
    }),
    AvatarFallback: passthrough('span'),
}));

vi.mock('@/components/UserStatusEmoji.vue', () => ({
    default: defineComponent({
        setup: () => () => h('span', { 'data-stub': 'UserStatusEmoji' }),
    }),
}));

vi.mock('@/components/InviteMemberModal.vue', () => ({
    default: modalStub('InviteMemberModal'),
}));
vi.mock('@/components/RemoveMemberModal.vue', () => ({
    default: modalStub('RemoveMemberModal'),
}));
vi.mock('@/components/TransferOwnershipModal.vue', () => ({
    default: modalStub('TransferOwnershipModal'),
}));
vi.mock('@/components/CancelInvitationModal.vue', () => ({
    default: modalStub('CancelInvitationModal'),
}));
vi.mock('@/components/DeleteTeamModal.vue', () => ({
    default: modalStub('DeleteTeamModal'),
}));

import Edit from './Edit.vue';

function team(overrides: Partial<Team> = {}): Team {
    return {
        id: 't-1',
        name: 'Acme Corp',
        slug: 'acme',
        isPersonal: false,
        role: 'owner',
        membersCount: 2,
        unreadCount: 0,
        mentionCount: 0,
        ...overrides,
    };
}

function member(overrides: Partial<TeamMember> = {}): TeamMember {
    return {
        id: 'm-1',
        name: 'Ada Lovelace',
        email: 'ada@example.com',
        avatar: null,
        role: 'member',
        role_label: 'Member',
        status: null,
        ...overrides,
    };
}

function permissions(
    overrides: Partial<TeamPermissions> = {},
): TeamPermissions {
    return {
        canUpdateTeam: true,
        canDeleteTeam: true,
        canAddMember: true,
        canUpdateMember: true,
        canRemoveMember: true,
        canCreateInvitation: true,
        canCancelInvitation: true,
        canTransferOwnership: true,
        canViewAudit: true,
        canViewSecurityLog: true,
        canViewAnalytics: true,
        canManageIntegrations: true,
        canManageUserGroups: true,
        ...overrides,
    };
}

const availableRoles: RoleOption[] = [
    { value: 'admin', label: 'Admin' },
    { value: 'member', label: 'Member' },
];

let app: App | null = null;

function mount(props: Record<string, unknown> = {}) {
    const host = document.createElement('div');
    document.body.append(host);

    app = createApp({
        render: () =>
            h(Edit, {
                team: team(),
                members: [member()],
                invitations: [],
                permissions: permissions(),
                availableRoles,
                ...props,
            }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);

    return host;
}

function find(host: HTMLElement, selector: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${selector}"]`);
}

function all(host: HTMLElement, selector: string): HTMLElement[] {
    return [...host.querySelectorAll<HTMLElement>(`[data-test="${selector}"]`)];
}

function stub(host: HTMLElement, name: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-stub="${name}"]`);
}

beforeEach(() => {
    pageProps.auth.user.id = 'me';
    pageProps.integrationsEnabled = true;
    pageProps.demoMode = false;
    visit.mockClear();
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

describe('the roster', () => {
    it('counts the members beside the heading', () => {
        const host = mount({
            members: [member(), member({ id: 'm-2', name: 'Grace Hopper' })],
        });

        expect(all(host, 'member-row')).toHaveLength(2);
        expect(host.textContent).toContain('Team members');
        expect(host.textContent).toContain('2');
    });

    it('links each member to their profile and marks the viewer', () => {
        const host = mount({
            members: [member({ id: 'me', name: 'Ada Lovelace' })],
        });

        const link = find(host, 'member-profile-link');

        expect(link?.getAttribute('href')).toBe('/teams/acme/members/me');
        expect(link?.textContent).toContain('(you)');
    });

    it('leaves the viewer marker off everybody else', () => {
        const host = mount();

        expect(find(host, 'member-profile-link')?.textContent).not.toContain(
            '(you)',
        );
    });

    it('shows the avatar image when the member has one', () => {
        const host = mount({
            members: [member({ avatar: '/avatars/ada.png' })],
        });

        expect(stub(host, 'AvatarImage')?.getAttribute('src')).toBe(
            '/avatars/ada.png',
        );
    });

    it('falls back to the initials when the member has no avatar', () => {
        const host = mount();

        expect(stub(host, 'AvatarImage')).toBeNull();
        expect(find(host, 'member-row')?.textContent).toContain('AL');
    });

    it('renders the email and the live status text', () => {
        const host = mount({
            members: [
                member({
                    status: {
                        emoji: '🌴',
                        text: 'On leave',
                    } as TeamMember['status'],
                }),
            ],
        });

        expect(find(host, 'member-row')?.textContent).toContain(
            'ada@example.com',
        );
        expect(find(host, 'member-status-text')?.textContent?.trim()).toBe(
            'On leave',
        );
    });

    it('leaves the status line out when the member has none', () => {
        const host = mount();

        expect(find(host, 'member-status-text')).toBeNull();
    });

    it('offers the invite button to someone who may invite', () => {
        expect(find(mount(), 'invite-member-button')).not.toBeNull();
    });

    it('withholds the invite button from everyone else', () => {
        const host = mount({
            permissions: permissions({ canCreateInvitation: false }),
        });

        expect(find(host, 'invite-member-button')).toBeNull();
    });

    it('opens the invite dialog from the button', async () => {
        const host = mount();

        expect(stub(host, 'InviteMemberModal')?.dataset.open).toBe('false');

        find(host, 'invite-member-button')?.click();
        await Promise.resolve();

        expect(stub(host, 'InviteMemberModal')?.dataset.open).toBe('true');
    });
});

describe('the role control', () => {
    it('badges the owner rather than offering them a role menu', () => {
        const host = mount({
            members: [member({ role: 'owner', role_label: 'Owner' })],
        });

        expect(find(host, 'member-role-trigger')).toBeNull();
        expect(find(host, 'member-row')?.textContent).toContain('Owner');
    });

    it('lists every assignable role with its description', () => {
        const host = mount();

        const options = all(host, 'member-role-option');

        expect(options).toHaveLength(2);
        expect(options[0].textContent).toContain('Admin');
        expect(options[0].textContent).toContain(
            'Can manage members, channels, and invitations',
        );
        expect(options[1].textContent).toContain(
            'Can read and post in channels they belong to',
        );
    });

    it('ticks the role the member already holds', () => {
        const host = mount();

        const options = all(host, 'member-role-option');

        expect(options[0].querySelector('[data-stub="Check"]')).toBeNull();
        expect(options[1].querySelector('[data-stub="Check"]')).not.toBeNull();
    });

    it('sends the new role and keeps the scroll position', () => {
        const host = mount();

        all(host, 'member-role-option')[0].click();

        expect(visit).toHaveBeenCalledWith('/teams/acme/members/m-1', {
            data: { role: 'admin' },
            preserveScroll: true,
        });
    });

    it('renders a plain label for someone who may not change roles', () => {
        const host = mount({
            permissions: permissions({ canUpdateMember: false }),
        });

        expect(find(host, 'member-role-trigger')).toBeNull();
        expect(find(host, 'member-row')?.textContent).toContain('Member');
    });
});

describe('the owner-level affordances', () => {
    it('offers transfer and removal on a non-owner row, each one named', () => {
        const host = mount();

        expect(
            find(host, 'member-transfer-ownership-button')?.getAttribute(
                'aria-label',
            ),
        ).toBe('Transfer ownership');
        expect(
            find(host, 'member-remove-button')?.getAttribute('aria-label'),
        ).toBe('Remove member');
    });

    it('offers neither on the owner row', () => {
        const host = mount({
            members: [member({ role: 'owner', role_label: 'Owner' })],
        });

        expect(find(host, 'member-transfer-ownership-button')).toBeNull();
        expect(find(host, 'member-remove-button')).toBeNull();
    });

    it('withholds each control from someone lacking the permission', () => {
        const host = mount({
            permissions: permissions({
                canTransferOwnership: false,
                canRemoveMember: false,
            }),
        });

        expect(find(host, 'member-transfer-ownership-button')).toBeNull();
        expect(find(host, 'member-remove-button')).toBeNull();
    });

    it('opens the transfer dialog from the crown', async () => {
        const host = mount();

        find(host, 'member-transfer-ownership-button')?.click();
        await Promise.resolve();

        expect(stub(host, 'TransferOwnershipModal')?.dataset.open).toBe('true');
    });

    it('opens the removal dialog from the cross', async () => {
        const host = mount();

        find(host, 'member-remove-button')?.click();
        await Promise.resolve();

        expect(stub(host, 'RemoveMemberModal')?.dataset.open).toBe('true');
    });

    it('locks both controls in the demo and says why', async () => {
        pageProps.demoMode = true;

        const host = mount();

        const transfer = find(host, 'member-transfer-ownership-button');
        const remove = find(host, 'member-remove-button');
        const opened = (name: string) => stub(host, name)?.dataset.open;

        // `aria-disabled` rather than the `disabled` attribute: a disabled
        // button leaves the tab order, so the reason would only reach a mouse.
        expect(transfer?.hasAttribute('disabled')).toBe(false);
        expect(transfer?.getAttribute('aria-disabled')).toBe('true');
        expect(remove?.hasAttribute('disabled')).toBe(false);
        expect(remove?.getAttribute('aria-disabled')).toBe('true');
        expect(host.textContent).toContain('Disabled in the demo');

        transfer?.click();
        remove?.click();
        await Promise.resolve();

        expect(opened('TransferOwnershipModal')).toBe('false');
        expect(opened('RemoveMemberModal')).toBe('false');
    });
});
