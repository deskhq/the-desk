// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import { translate } from '@/lib/i18n';
import type { User } from '@/types';

/** Mutable stand-in for the shared Inertia props the sheet reads. */
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
                    { ...attrs, 'data-prefetch': props.prefetch || undefined },
                    slots.default?.(),
                ),
    }),
}));

vi.mock('vue-sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));

// The sheet rides the app's dialog primitive; its portal/focus behaviour is the
// primitive's own tested concern. The stub keeps the one contract the specs
// lean on — a Dialog renders its content only while `open` — so the presets
// sheet can be asserted closed and opened.
vi.mock('@/components/ui/dialog', () => {
    const pass = (name: string) =>
        defineComponent({
            name,
            setup:
                (_p, { slots }) =>
                () =>
                    h('div', slots.default?.()),
        });

    return {
        Dialog: defineComponent({
            name: 'DialogRootStub',
            props: { open: { type: Boolean, default: false } },
            emits: ['update:open'],
            setup:
                (props, { slots }) =>
                () =>
                    props.open ? h('div', slots.default?.()) : null,
        }),
        DialogContent: pass('DialogContent'),
        DialogTitle: pass('DialogTitle'),
        DialogDescription: pass('DialogDescription'),
    };
});

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

import UserMenuSheet from './UserMenuSheet.vue';

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

function mount(componentProps: Record<string, unknown> = {}): {
    host: HTMLElement;
    emitted: Record<string, unknown[][]>;
} {
    const emitted: Record<string, unknown[][]> = {};
    const capture =
        (name: string) =>
        (...args: unknown[]) => {
            (emitted[name] ??= []).push(args);
        };
    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp({
        render: () =>
            h(UserMenuSheet, {
                open: true,
                user: user(),
                'onUpdate:open': capture('update:open'),
                ...componentProps,
            }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);

    return { host, emitted };
}

function find(host: HTMLElement, selector: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${selector}"]`);
}

beforeEach(() => {
    page.props = {
        auth: { user: user() },
        currentTeam: { id: 't1', name: 'Acme Co' },
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

describe('UserMenuSheet masthead', () => {
    it('renders the identity block: name, presence word, email and team', () => {
        const { host } = mount();

        expect(host.textContent).toContain('Maya Chen');
        expect(host.textContent).toContain('maya@acme.co');
        expect(host.textContent).toContain('Acme Co');
        expect(find(host, 'user-menu-presence')).not.toBeNull();
        expect(find(host, 'user-menu-presence-label')!.textContent).toContain(
            'Active',
        );
    });

    it('renders nothing at all while closed', () => {
        const { host } = mount({ open: false });

        expect(host.textContent).toBe('');
    });
});

describe('UserMenuSheet status section', () => {
    it('shows the current status as a card with its expiry, and no plain set-status row', () => {
        const { host } = mount();

        const card = find(host, 'edit-status-menu-item');
        expect(card).not.toBeNull();
        expect(card!.textContent).toContain('Heads down');
        expect(host.textContent).toContain('3:00');
        expect(find(host, 'set-status-menu-item')).toBeNull();
    });

    it('degrades to the plain set-status row when no status is set', () => {
        page.props.auth = { user: user({ status: null }) };
        const { host } = mount();

        expect(find(host, 'set-status-menu-item')).not.toBeNull();
        expect(find(host, 'edit-status-menu-item')).toBeNull();
        expect(find(host, 'clear-status-menu-item')).toBeNull();
    });

    it('clears the status from the card without dismissing the sheet', () => {
        const { host, emitted } = mount();

        find(host, 'clear-status-menu-item')!.click();

        expect(router.delete).toHaveBeenCalledWith(
            '/settings/status',
            expect.anything(),
        );
        expect(emitted['update:open']).toBeUndefined();
    });

    it('trades the sheet for the status dialog when setting a status', () => {
        page.props.auth = { user: user({ status: null }) };
        const { host, emitted } = mount();

        find(host, 'set-status-menu-item')!.click();

        expect(openStatusDialog).toHaveBeenCalledOnce();
        expect(emitted['update:open']).toEqual([[false]]);
    });

    it('flips the away override in place from the presence row', () => {
        const { host, emitted } = mount();

        const row = find(host, 'toggle-presence-menu-item')!;
        expect(row.textContent).toContain('Set yourself away');

        row.click();

        expect(router.put).toHaveBeenCalledWith(
            '/settings/presence',
            { state: 'away' },
            expect.anything(),
        );
        expect(emitted['update:open']).toBeUndefined();
    });
});

describe('UserMenuSheet pause notifications', () => {
    it('opens the DND presets as a second sheet, not inline', () => {
        const { host } = mount();

        expect(find(host, 'pause-notifications-submenu')).toBeNull();

        find(host, 'pause-notifications-menu-item')!.click();

        return Promise.resolve().then(() => {
            expect(find(host, 'pause-notifications-submenu')).not.toBeNull();
            expect(find(host, 'pause-preset-thirty-minutes')).not.toBeNull();
            expect(find(host, 'pause-preset-custom')).not.toBeNull();
        });
    });

    it('applies a preset and returns to the menu sheet', async () => {
        const { host, emitted } = mount();

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
        expect(emitted['update:open']).toBeUndefined();
    });

    it('trades both sheets for the custom-pause dialog', async () => {
        const { host, emitted } = mount();

        find(host, 'pause-notifications-menu-item')!.click();
        await Promise.resolve();

        find(host, 'pause-preset-custom')!.click();

        expect(openDndPauseDialog).toHaveBeenCalledOnce();
        expect(emitted['update:open']).toEqual([[false]]);
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
        const { host, emitted } = mount();

        expect(find(host, 'dnd-paused-card')).not.toBeNull();

        find(host, 'dnd-resume-menu-item')!.click();

        expect(router.delete).toHaveBeenCalledWith(
            '/settings/dnd',
            expect.anything(),
        );
        expect(emitted['update:open']).toBeUndefined();
    });
});

describe('UserMenuSheet appearance', () => {
    it('renders the theme segmented control and applies a pick in place', () => {
        const { host, emitted } = mount();

        const control = find(host, 'menu-theme-switcher')!;
        const dark = control.querySelector<HTMLElement>(
            '[aria-checked="false"]',
        )!;

        dark.click();

        expect(updateAppearance).toHaveBeenCalled();
        expect(emitted['update:open']).toBeUndefined();
    });

    it('drops the sidebar switcher below md', () => {
        const { host } = mount();

        expect(find(host, 'menu-sidebar-switcher')).toBeNull();
    });
});

describe('UserMenuSheet navigation and footer', () => {
    it('drops the keyboard-shortcuts row: a phone has no hardware keyboard', () => {
        const { host } = mount();

        expect(find(host, 'keyboard-shortcuts-menu-item')).toBeNull();
    });

    it('keeps the settings link, with prefetch, and dismisses on follow', () => {
        const { host, emitted } = mount();

        const settings = find(host, 'settings-menu-item')!;
        expect(settings.getAttribute('data-prefetch')).not.toBeNull();

        settings.click();

        expect(emitted['update:open']).toEqual([[false]]);
    });

    it('replays the tour and dismisses', () => {
        const { host, emitted } = mount();

        find(host, 'replay-tour-menu-item')!.click();

        expect(replayTour).toHaveBeenCalledOnce();
        expect(emitted['update:open']).toEqual([[false]]);
    });

    it('logs out through the flush-all path', () => {
        const { host } = mount();

        const logout = find(host, 'logout-button')!;
        expect(logout.textContent).toContain('Log out');

        logout.click();

        expect(router.flushAll).toHaveBeenCalledOnce();
    });

    it('closes the version line with the running version', () => {
        const { host } = mount();

        expect(find(host, 'user-menu-version')!.textContent).toContain(
            'The Desk v2.4.1',
        );
    });
});
