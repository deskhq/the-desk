// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h, reactive } from 'vue';

const { reload } = vi.hoisted(() => ({ reload: vi.fn() }));

const page = reactive<{ component: string; props: Record<string, unknown> }>({
    component: 'channels/Show',
    props: {},
});

vi.mock('@inertiajs/vue3', () => ({
    router: { reload },
    usePage: () => page,
}));

const { syncDetectedTimezone } = vi.hoisted(() => ({
    syncDetectedTimezone: vi.fn(),
}));

vi.mock('@/composables/useTimezone', () => ({
    useTimezone: () => ({ syncDetectedTimezone }),
}));

const { startTour, shouldAutoStartTour } = vi.hoisted(() => ({
    startTour: vi.fn(),
    shouldAutoStartTour: vi.fn(() => true),
}));

vi.mock('@/composables/useOnboardingTour', () => ({
    useOnboardingTour: () => ({ start: startTour }),
    shouldAutoStartTour,
}));

import { useShellStartup } from '@/composables/useShellStartup';

let app: App | null = null;

/** Whatever the composable returns, mounted on a bare component. */
function boot(): ReturnType<typeof useShellStartup> {
    let startup!: ReturnType<typeof useShellStartup>;

    app = createApp({
        setup: () => {
            startup = useShellStartup();

            return () => h('div');
        },
    });
    app.mount(document.createElement('div'));

    return startup;
}

describe('useShellStartup', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        shouldAutoStartTour.mockReturnValue(true);
        page.component = 'channels/Show';
        page.props = { auth: { user: { id: 'u1' } } };
    });

    afterEach(() => {
        app?.unmount();
        app = null;
    });

    it('pulls the lazily shared invitations without interrupting the viewer', () => {
        boot();

        expect(reload).toHaveBeenCalledOnce();
        expect(reload.mock.calls[0][0]).toEqual(
            expect.objectContaining({
                async: true,
                preserveUrl: true,
                only: ['pendingInvitations'],
            }),
        );
    });

    it('persists the browser’s timezone on the first authenticated paint', () => {
        boot();

        expect(syncDetectedTimezone).toHaveBeenCalledOnce();
    });

    describe('the first-run tour', () => {
        it('starts for a user who has never completed it', () => {
            boot();

            expect(startTour).toHaveBeenCalledOnce();
        });

        it('waits behind a pending post-registration prompt', () => {
            page.props = {
                auth: { user: { id: 'u1' } },
                postRegistrationPrompt: 'passkey',
            };

            boot();

            expect(shouldAutoStartTour).toHaveBeenCalledWith(
                expect.anything(),
                true,
            );
        });

        it('stays out of the way while the viewer is deep in settings', () => {
            // The tour anchors live on the channel workspace, so there is
            // nothing for it to point at here; replaying stays in the user menu.
            page.component = 'settings/Profile';

            boot();

            expect(startTour).not.toHaveBeenCalled();
        });

        it('can be started again once the prompt in front of it is answered', () => {
            page.component = 'settings/Profile';

            const { startTourIfEligible } = boot();

            page.component = 'channels/Show';
            startTourIfEligible(false);

            expect(startTour).toHaveBeenCalledOnce();
        });
    });

    describe('the settings section', () => {
        it.each([
            ['settings/Profile', true],
            ['teams/Edit', true],
            ['channels/Show', false],
        ])('reads %s as %s', (component, expected) => {
            page.component = component;

            expect(boot().isSettingsSection.value).toBe(expected);
        });
    });
});
