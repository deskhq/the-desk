// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import { translate } from '@/lib/i18n';
import type { RoleOption, Team, TeamPermissions } from '@/types';

/**
 * Covers the page around the roster: its header, the team-name form, and the
 * grid of admin destinations with the emoji-only fallback that replaces it for
 * a plain member. Every card is permission-gated, and the whole grid collapses
 * to one link when none of the gates open — the behaviour most at risk when
 * `pages/teams/Edit.vue` is split into sections.
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
        props: { action: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h(
                    'form',
                    { action: props.action },
                    slots.default?.({ errors: {}, processing: false }),
                ),
    }),
}));

vi.mock('@lucide/vue', () => ({
    ChartColumn: { render: () => h('svg') },
    Check: { render: () => h('svg') },
    ChevronDown: { render: () => h('svg') },
    ChevronRight: { render: () => h('svg') },
    Crown: { render: () => h('svg', { 'data-stub': 'Crown' }) },
    Download: { render: () => h('svg') },
    Mail: { render: () => h('svg') },
    Plug: { render: () => h('svg') },
    ScrollText: { render: () => h('svg') },
    Send: { render: () => h('svg') },
    Trash2: { render: () => h('svg') },
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

vi.mock('@/routes/teams/analytics', () => ({
    index: (slug: string) => `/teams/${slug}/analytics`,
}));
vi.mock('@/routes/teams/audit', () => ({
    index: (slug: string) => `/teams/${slug}/audit`,
}));
vi.mock('@/routes/teams/audit-exports', () => ({
    index: (slug: string) => `/teams/${slug}/audit-exports`,
}));
vi.mock('@/routes/teams/emojis', () => ({
    index: (slug: string) => `/teams/${slug}/emojis`,
}));
vi.mock('@/routes/teams/groups', () => ({
    index: (slug: string) => `/teams/${slug}/groups`,
}));
vi.mock('@/routes/teams/integrations', () => ({
    index: (slug: string) => `/teams/${slug}/integrations`,
}));
vi.mock('@/routes/teams/invitations', () => ({
    resend: ([slug, code]: [string, string]) =>
        `/teams/${slug}/invitations/${code}/resend`,
}));
vi.mock('@/routes/teams/members', () => ({
    show: ([slug, id]: [string, string]) => `/teams/${slug}/members/${id}`,
    update: ([slug, id]: [string, string]) => `/teams/${slug}/members/${id}`,
}));
vi.mock('@/routes/teams/security-log', () => ({
    index: (slug: string) => `/teams/${slug}/security-log`,
}));

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

import {
    channelCreationSettings,
    defaultChannelCandidates,
    teamPermissions,
} from './Edit.doubles';
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

/** A plain member: none of the admin destinations are open to them. */
function memberPermissions(): TeamPermissions {
    return teamPermissions({
        canUpdateTeam: false,
        canDeleteTeam: false,
        canViewAudit: false,
        canViewSecurityLog: false,
        canViewAnalytics: false,
        canViewDeletedChannels: false,
        canManageIntegrations: false,
        canManageUserGroups: false,
    });
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
                invitations: [],
                permissions: teamPermissions(),
                availableRoles,
                channelCreation: channelCreationSettings(),
                defaultChannels: defaultChannelCandidates(),
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

describe('the page header', () => {
    it('trails back to the team list and names the team', () => {
        const host = mount();

        expect(host.querySelector('nav a')?.getAttribute('href')).toBe(
            '/teams',
        );
        expect(host.querySelector('h1')?.textContent?.trim()).toBe('Acme Corp');
    });

    it('badges the owner of the team', () => {
        const host = mount();

        expect(host.textContent).toContain('You own this team');
    });

    it('leaves the badge off for everyone else', () => {
        const host = mount({ team: team({ role: 'admin' }) });

        expect(host.textContent).not.toContain('You own this team');
    });

    it('titles the document for editing when the viewer may edit', () => {
        expect(stub(mount(), 'Head')?.dataset.title).toBe('Edit Acme Corp');
    });

    it('titles it for viewing when they may not', () => {
        const host = mount({ permissions: memberPermissions() });

        expect(stub(host, 'Head')?.dataset.title).toBe('View Acme Corp');
    });
});

describe('the team name form', () => {
    it('posts to the team update route with the current name', () => {
        const host = mount();

        expect(
            (find(host, 'team-name-input') as HTMLInputElement | null)?.value,
        ).toBe('Acme Corp');
        expect(host.querySelector('form')?.getAttribute('action')).toBe(
            '/teams/acme',
        );
        expect(find(host, 'team-save-button')).not.toBeNull();
    });

    it('labels the input for assistive tech without repeating the heading', () => {
        const host = mount();

        const label = host.querySelector('label');

        expect(label?.getAttribute('for')).toBe('name');
        expect(label?.className).toContain('sr-only');
    });

    it('locks the save button in the demo', () => {
        pageProps.demoMode = true;

        const host = mount();

        expect(find(host, 'team-save-button')?.hasAttribute('disabled')).toBe(
            true,
        );
    });

    it('is absent for someone who may not rename the team', () => {
        const host = mount({ permissions: memberPermissions() });

        expect(find(host, 'team-name-input')).toBeNull();
        expect(find(host, 'team-save-button')).toBeNull();
    });
});

describe('the admin destinations', () => {
    it('links every card an owner can reach', () => {
        const host = mount();

        expect(find(host, 'manage-emoji-link')?.getAttribute('href')).toBe(
            '/teams/acme/emojis',
        );
        expect(find(host, 'manage-groups-link')?.getAttribute('href')).toBe(
            '/teams/acme/groups',
        );
        expect(
            find(host, 'manage-integrations-link')?.getAttribute('href'),
        ).toBe('/teams/acme/integrations');
        expect(find(host, 'view-analytics-link')?.getAttribute('href')).toBe(
            '/teams/acme/analytics',
        );
        expect(find(host, 'view-audit-log-link')?.getAttribute('href')).toBe(
            '/teams/acme/audit',
        );
        expect(find(host, 'view-security-log-link')?.getAttribute('href')).toBe(
            '/teams/acme/security-log',
        );
        expect(
            find(host, 'view-audit-exports-link')?.getAttribute('href'),
        ).toBe('/teams/acme/audit-exports');
    });

    it('withholds each card behind its own permission', () => {
        const host = mount({
            permissions: teamPermissions({
                canManageUserGroups: false,
                canViewAnalytics: false,
                canViewAudit: false,
                canViewSecurityLog: false,
            }),
        });

        expect(find(host, 'manage-groups-link')).toBeNull();
        expect(find(host, 'view-analytics-link')).toBeNull();
        expect(find(host, 'view-audit-log-link')).toBeNull();
        expect(find(host, 'view-security-log-link')).toBeNull();
        expect(find(host, 'view-audit-exports-link')).toBeNull();
    });

    it('keeps the exports card while either log is readable', () => {
        const host = mount({
            permissions: teamPermissions({ canViewAudit: false }),
        });

        expect(find(host, 'view-audit-log-link')).toBeNull();
        expect(find(host, 'view-audit-exports-link')).not.toBeNull();
    });

    it('hides integrations while the platform toggle is off', () => {
        pageProps.integrationsEnabled = false;

        const host = mount();

        expect(find(host, 'manage-integrations-link')).toBeNull();
    });

    it('hides integrations from someone who may not manage them', () => {
        const host = mount({
            permissions: teamPermissions({ canManageIntegrations: false }),
        });

        expect(find(host, 'manage-integrations-link')).toBeNull();
    });

    it('falls back to the emoji card alone for a plain member', () => {
        const host = mount({ permissions: memberPermissions() });

        expect(find(host, 'manage-emoji-link')?.getAttribute('href')).toBe(
            '/teams/acme/emojis',
        );
        expect(find(host, 'manage-groups-link')).toBeNull();
        expect(find(host, 'view-analytics-link')).toBeNull();
        expect(find(host, 'view-audit-exports-link')).toBeNull();
    });
});
