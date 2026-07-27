// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import { translate } from '@/lib/i18n';
import type { User } from '@/types';

/** Mutable stand-in for the shared Inertia props the panel reads. */
const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
}));

const router = vi.hoisted(() => ({
    put: vi.fn(),
    delete: vi.fn(),
    flushAll: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    router,
    Link: defineComponent({
        name: 'InertiaLinkStub',
        props: {
            href: { type: [String, Object], default: '' },
            as: { type: String, default: 'a' },
            prefetch: { type: Boolean, default: false },
        },
        setup:
            (props, { slots, attrs }) =>
            () =>
                h(
                    props.as === 'button' ? 'button' : 'a',
                    {
                        ...attrs,
                        'data-prefetch': props.prefetch || undefined,
                        'data-href':
                            typeof props.href === 'string'
                                ? props.href
                                : ((props.href as { url?: string }).url ?? ''),
                    },
                    slots.default?.(),
                ),
    }),
}));

vi.mock('@/composables/useToast', () => {
    const toast = {
        error: vi.fn(),
        success: vi.fn(),
        warning: vi.fn(),
        progress: vi.fn(),
    };

    return { useToast: () => toast };
});

// The workspace sheet is a surface of its own with its own specs; here it is
// only the wrapper the "Switch workspace" row triggers, so the stub keeps that
// row reachable and records what it is anchored on.
vi.mock('@/components/navigation/WorkspaceSheet.vue', () => ({
    default: defineComponent({
        name: 'WorkspaceSheetStub',
        setup:
            (_props, { slots }) =>
            () =>
                h(
                    'div',
                    { 'data-test': 'workspace-sheet-anchor' },
                    slots.default?.(),
                ),
    }),
}));

const updateAppearance = vi.hoisted(() => vi.fn());

vi.mock('@/composables/useAppearance', async () => {
    const { ref } = await import('vue');

    return {
        useAppearance: () => ({ appearance: ref('light'), updateAppearance }),
    };
});

const openStatusDialog = vi.hoisted(() => vi.fn());

vi.mock('@/composables/useUserStatusDialog', () => ({
    useUserStatusDialog: () => ({ open: openStatusDialog }),
}));

const openDndPauseDialog = vi.hoisted(() => vi.fn());

vi.mock('@/composables/useDndPauseDialog', () => ({
    useDndPauseDialog: () => ({ open: openDndPauseDialog }),
}));

const replayTour = vi.hoisted(() => vi.fn());

vi.mock('@/composables/useOnboardingTour', () => ({
    useOnboardingTour: () => ({ open: replayTour }),
}));

vi.mock('@/composables/useUpdateStatus', async () => {
    const { ref } = await import('vue');

    return {
        useUpdateStatus: () => ({
            status: ref({ current: '2.4.1', latest: null, notesUrl: null }),
            isBehind: ref(false),
        }),
    };
});

import UserPanel from './UserPanel.vue';

function user(overrides: Partial<User> = {}): User {
    return {
        id: 1,
        name: 'Maya Chen',
        email: 'maya@acme.co',
        pronouns: null,
        title: null,
        phone: null,
        timezone: 'UTC',
        status: {
            emoji: '🎧',
            text: 'Heads down',
            expiresAt: '2026-01-01T15:00:00.000Z',
        },
        presence: 'active',
        dnd: {
            until: null,
            scheduleEnabled: false,
            startsAt: null,
            endsAt: null,
            scheduleSnoozedUntil: null,
        },
        locale: 'en',
        time_format: 'auto',
        email_verified_at: null,
        created_at: '',
        updated_at: '',
        chime_sound: 'chime',
        share_read_receipts: true,
        sidebar_position: 'left',
        onboarding_completed_at: null,
        ...overrides,
    } as User;
}

let app: App | null = null;

function mount(): HTMLElement {
    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp({ render: () => h(UserPanel) });
    app.config.globalProperties.$t = translate;
    app.mount(host);

    return host;
}

function find(host: HTMLElement, selector: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${selector}"]`);
}

beforeEach(() => {
    page.props = {
        auth: { user: user() },
        currentTeam: { id: 't1', name: 'Acme Co' },
        teams: [{ id: 't1' }, { id: 't2' }],
        customEmojis: {},
        name: 'The Desk',
    };
});

afterEach(() => {
    vi.clearAllMocks();
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

describe('UserPanel header', () => {
    it('renders the identity block: the name, and the workspace · presence line', () => {
        const host = mount();

        expect(host.textContent).toContain('Maya Chen');
        expect(find(host, 'user-menu-presence')).not.toBeNull();
        expect(find(host, 'user-menu-presence-label')!.textContent).toContain(
            'Acme Co · Active',
        );
    });

    it('names the pause rather than the presence while in do not disturb', () => {
        page.props.auth = {
            user: user({
                dnd: {
                    until: '2099-01-01T15:00:00.000Z',
                    scheduleEnabled: false,
                    startsAt: null,
                    endsAt: null,
                    scheduleSnoozedUntil: null,
                },
            }),
        };
        const host = mount();

        expect(find(host, 'user-menu-presence-label')!.textContent).toContain(
            'Notifications paused',
        );
    });
});

describe('UserPanel status section', () => {
    it('shows the current status as a card with its expiry, and no plain set-status row', () => {
        const host = mount();

        const card = find(host, 'edit-status-menu-item');
        expect(card).not.toBeNull();
        expect(card!.textContent).toContain('Heads down');
        expect(host.textContent).toContain('3:00');
        expect(find(host, 'set-status-menu-item')).toBeNull();
    });

    it('degrades to the plain set-status row when no status is set', () => {
        page.props.auth = { user: user({ status: null }) };
        const host = mount();

        expect(find(host, 'set-status-menu-item')).not.toBeNull();
        expect(find(host, 'edit-status-menu-item')).toBeNull();
        expect(find(host, 'clear-status-menu-item')).toBeNull();
    });

    it('clears the status from the card in place', () => {
        const host = mount();

        find(host, 'clear-status-menu-item')!.click();

        expect(router.delete).toHaveBeenCalledWith(
            '/settings/status',
            expect.anything(),
        );
    });

    it('opens the status dialog from the plain row', () => {
        page.props.auth = { user: user({ status: null }) };
        const host = mount();

        find(host, 'set-status-menu-item')!.click();

        expect(openStatusDialog).toHaveBeenCalledOnce();
    });

    it('flips the away override in place from the presence row', () => {
        const host = mount();

        const row = find(host, 'toggle-presence-menu-item')!;
        expect(row.textContent).toContain('Set yourself away');

        row.click();

        expect(router.put).toHaveBeenCalledWith(
            '/settings/presence',
            { state: 'away' },
            expect.anything(),
        );
    });
});

describe('UserPanel pause notifications', () => {
    it('discloses the DND presets under the row rather than in a layer of its own', async () => {
        const host = mount();

        const row = find(host, 'pause-notifications-menu-item')!;
        expect(find(host, 'pause-notifications-submenu')).toBeNull();
        expect(row.getAttribute('aria-expanded')).toBe('false');

        row.click();
        await Promise.resolve();

        expect(find(host, 'pause-notifications-submenu')).not.toBeNull();
        expect(row.getAttribute('aria-expanded')).toBe('true');
        expect(find(host, 'pause-preset-thirty-minutes')).not.toBeNull();
        expect(find(host, 'pause-preset-custom')).not.toBeNull();
        expect(find(host, 'quiet-hours-menu-item')).not.toBeNull();
    });

    it('applies a preset and folds the disclosure back up', async () => {
        const host = mount();

        find(host, 'pause-notifications-menu-item')!.click();
        await Promise.resolve();

        find(host, 'pause-preset-thirty-minutes')!.click();
        await Promise.resolve();

        expect(router.put).toHaveBeenCalledWith(
            '/settings/dnd',
            { until: expect.any(String) },
            expect.anything(),
        );
        expect(find(host, 'pause-notifications-submenu')).toBeNull();
    });

    it('trades the disclosure for the custom-pause dialog', async () => {
        const host = mount();

        find(host, 'pause-notifications-menu-item')!.click();
        await Promise.resolve();

        find(host, 'pause-preset-custom')!.click();
        await Promise.resolve();

        expect(openDndPauseDialog).toHaveBeenCalledOnce();
        expect(find(host, 'pause-notifications-submenu')).toBeNull();
    });

    it('shows the paused card with a resume pill while a manual pause runs', () => {
        page.props.auth = {
            user: user({
                dnd: {
                    until: '2099-01-01T15:00:00.000Z',
                    scheduleEnabled: false,
                    startsAt: null,
                    endsAt: null,
                    scheduleSnoozedUntil: null,
                },
            }),
        };
        const host = mount();

        expect(find(host, 'dnd-paused-card')).not.toBeNull();

        find(host, 'dnd-resume-menu-item')!.click();

        expect(router.delete).toHaveBeenCalledWith(
            '/settings/dnd',
            expect.anything(),
        );
    });
});

describe('UserPanel appearance', () => {
    it('renders the theme segmented control and applies a pick in place', () => {
        const host = mount();

        const control = find(host, 'menu-theme-switcher')!;
        const dark = control.querySelector<HTMLElement>(
            '[aria-checked="false"]',
        )!;

        dark.click();

        expect(updateAppearance).toHaveBeenCalled();
    });

    it('drops the sidebar switcher: below md the dock is a drawer, not a pane', () => {
        const host = mount();

        expect(find(host, 'menu-sidebar-switcher')).toBeNull();
    });
});

describe('UserPanel account rows', () => {
    it('drops the keyboard-shortcuts row: a phone has no hardware keyboard', () => {
        const host = mount();

        expect(find(host, 'keyboard-shortcuts-menu-item')).toBeNull();
    });

    it('keeps the settings link, with prefetch, on the full-screen index', () => {
        const host = mount();

        const settings = find(host, 'settings-menu-item')!;

        expect(settings.getAttribute('data-prefetch')).not.toBeNull();
        // Below `md` Settings opens on its full-screen index (#816), never
        // straight on the profile pane.
        expect(settings.getAttribute('data-href')).toBe('/settings');
    });

    it('anchors "Switch workspace" on the workspace sheet, tallying the workspaces', () => {
        const host = mount();

        const row = find(host, 'switch-workspace-menu-item')!;

        expect(
            row.closest('[data-test="workspace-sheet-anchor"]'),
        ).not.toBeNull();
        expect(find(host, 'switch-workspace-count')!.textContent).toContain(
            '2',
        );
    });

    it('deep-links "Security & devices" into the account security settings', () => {
        const host = mount();

        expect(
            find(host, 'security-menu-item')!.getAttribute('data-href'),
        ).toBe('/settings/security');
    });

    it('replays the tour', () => {
        const host = mount();

        find(host, 'replay-tour-menu-item')!.click();

        expect(replayTour).toHaveBeenCalledOnce();
    });
});

describe('UserPanel footer', () => {
    it('logs out through the flush-all path', () => {
        const host = mount();

        const logout = find(host, 'logout-button')!;
        expect(logout.textContent).toContain('Log out');

        logout.click();

        expect(router.flushAll).toHaveBeenCalledOnce();
    });

    it('closes with the version line and the running version', () => {
        const host = mount();

        expect(find(host, 'user-menu-version')!.textContent).toContain(
            'The Desk v2.4.1',
        );
    });
});
