// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import { translate } from '@/lib/i18n';
import type {
    RoleOption,
    Team,
    TeamInvitation,
    TeamPermissions,
} from '@/types';

/**
 * Covers the two sections at the bottom of the workspace settings page —
 * pending invitations and the danger zone — plus the dialogs the page mounts
 * for them. Both are permission-gated and demo-locked, and both survive the
 * split of `pages/teams/Edit.vue` unchanged, so the selectors and the requests
 * are pinned here.
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
    Check: { render: () => h('svg') },
    ChevronDown: { render: () => h('svg') },
    ChevronRight: { render: () => h('svg') },
    Crown: { render: () => h('svg') },
    Download: { render: () => h('svg') },
    Mail: { render: () => h('svg', { 'data-stub': 'Mail' }) },
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
    AvatarImage: passthrough('img'),
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
        membersCount: 1,
        unreadCount: 0,
        mentionCount: 0,
        ...overrides,
    };
}

function invitation(overrides: Partial<TeamInvitation> = {}): TeamInvitation {
    return {
        code: 'inv-1',
        email: 'grace@example.com',
        role: 'member',
        role_label: 'Member',
        created_at: '2024-03-04T10:30:00.000Z',
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

const availableRoles: RoleOption[] = [{ value: 'member', label: 'Member' }];

let app: App | null = null;

function mount(props: Record<string, unknown> = {}) {
    const host = document.createElement('div');
    document.body.append(host);

    app = createApp({
        render: () =>
            h(Edit, {
                team: team(),
                members: [],
                invitations: [invitation()],
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

describe('pending invitations', () => {
    it('counts them beside the heading and names each invitee', () => {
        const host = mount({
            invitations: [
                invitation(),
                invitation({ code: 'inv-2', email: 'alan@example.com' }),
            ],
        });

        expect(all(host, 'invitation-row')).toHaveLength(2);
        expect(host.textContent).toContain('Pending invitations');
        expect(find(host, 'invitation-row')?.textContent).toContain(
            'grace@example.com',
        );
    });

    it('reads out the role and how long ago it was sent', () => {
        const host = mount();

        expect(find(host, 'invitation-row')?.textContent).toContain(
            'Invited as Member · 2 hours ago',
        );
    });

    it('drops the whole section when nothing is pending', () => {
        const host = mount({ invitations: [] });

        expect(find(host, 'invitation-row')).toBeNull();
        expect(host.textContent).not.toContain('Pending invitations');
    });

    it('resends an invitation without losing the scroll position', () => {
        const host = mount();

        find(host, 'invitation-resend-button')?.click();

        expect(visit).toHaveBeenCalledWith(
            '/teams/acme/invitations/inv-1/resend',
            { preserveScroll: true },
        );
    });

    it('offers resend only to someone who may invite', () => {
        const host = mount({
            permissions: permissions({ canCreateInvitation: false }),
        });

        expect(find(host, 'invitation-resend-button')).toBeNull();
    });

    it('opens the cancellation dialog from the cross', async () => {
        const host = mount();

        expect(stub(host, 'CancelInvitationModal')?.dataset.open).toBe('false');

        find(host, 'invitation-cancel-button')?.click();
        await Promise.resolve();

        expect(stub(host, 'CancelInvitationModal')?.dataset.open).toBe('true');
    });

    it('names the icon-only cancel control for a screen reader', () => {
        const host = mount();

        expect(
            find(host, 'invitation-cancel-button')?.getAttribute('aria-label'),
        ).toBe('Cancel invitation');
    });

    it('offers cancellation only to someone who may cancel', () => {
        const host = mount({
            permissions: permissions({ canCancelInvitation: false }),
        });

        expect(find(host, 'invitation-cancel-button')).toBeNull();
    });
});

describe('the danger zone', () => {
    it('offers deletion to an owner of a real team', () => {
        const host = mount();

        expect(find(host, 'delete-team-button')).not.toBeNull();
        expect(host.textContent).toContain(
            'Permanently delete this team and all of its data. This cannot be undone.',
        );
    });

    it('withholds it from someone who may not delete the team', () => {
        const host = mount({
            permissions: permissions({ canDeleteTeam: false }),
        });

        expect(find(host, 'delete-team-button')).toBeNull();
        expect(stub(host, 'DeleteTeamModal')).toBeNull();
    });

    it('withholds it on a personal team', () => {
        const host = mount({ team: team({ isPersonal: true }) });

        expect(find(host, 'delete-team-button')).toBeNull();
        expect(stub(host, 'DeleteTeamModal')).toBeNull();
    });

    it('opens the deletion dialog from the button', async () => {
        const host = mount();

        find(host, 'delete-team-button')?.click();
        await Promise.resolve();

        expect(stub(host, 'DeleteTeamModal')?.dataset.open).toBe('true');
    });

    it('locks the button in the demo and says why', () => {
        pageProps.demoMode = true;

        const host = mount();

        expect(find(host, 'delete-team-button')?.hasAttribute('disabled')).toBe(
            true,
        );
        expect(find(host, 'demo-lock')).not.toBeNull();
        expect(host.textContent).toContain('Disabled in the demo');
    });
});

describe('the dialogs the page mounts', () => {
    it('leaves the invite dialog out for someone who may not invite', () => {
        const host = mount({
            permissions: permissions({ canCreateInvitation: false }),
        });

        expect(stub(host, 'InviteMemberModal')).toBeNull();
    });

    it('always mounts the member and invitation dialogs', () => {
        const host = mount();

        expect(stub(host, 'RemoveMemberModal')).not.toBeNull();
        expect(stub(host, 'TransferOwnershipModal')).not.toBeNull();
        expect(stub(host, 'CancelInvitationModal')).not.toBeNull();
    });
});
