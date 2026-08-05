// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { WORKSPACE_PROPS } from '@/lib/reloadProps';
import type { Team } from '@/types';

const visit = vi.fn();
const reload = vi.fn();

const page = { props: { currentTeam: { slug: 'acme' } as Team } };

vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: (...args: unknown[]) => visit(...args),
        reload: (...args: unknown[]) => reload(...args),
    },
    usePage: () => page,
}));

vi.mock('@/routes/teams', () => ({
    switchMethod: (slug: string) => ({ url: `/settings/teams/${slug}/switch` }),
}));

const { useTeamSwitch } = await import('@/composables/useTeamSwitch');

/** Drive the switch through to the visit that lands on the new workspace. */
function switchTo(slug: string, url = '/t/acme/c/general'): void {
    window.history.replaceState({}, '', url);

    useTeamSwitch().switchTeam({ slug } as Team);

    const [, options] = visit.mock.calls[0] as [
        unknown,
        { onFinish: () => void },
    ];
    options.onFinish();
}

/** The options the landing visit carried. */
function landingOptions(): { onSuccess?: () => void } {
    return visit.mock.calls[1]?.[1] as { onSuccess?: () => void };
}

describe('useTeamSwitch', () => {
    beforeEach(() => {
        visit.mockClear();
        reload.mockClear();
        page.props.currentTeam = { slug: 'acme' } as Team;
    });

    it('lands on the equivalent page under the new workspace', () => {
        switchTo('globex');

        expect(visit.mock.calls[1]?.[0]).toBe('/t/globex');
    });

    it('asks the new workspace for the props that describe it', () => {
        switchTo('globex');

        landingOptions().onSuccess?.();

        // The rosters are keyed by the team they belong to, which covers a
        // workspace the client has not seen. Coming *back* to one it has finds
        // that key already declared, and the client restores a once prop by
        // prop name — so without this the sidebar would show the workspace the
        // reader just left. A partial request is what bypasses the exclusion.
        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: WORKSPACE_PROPS }),
        );
    });

    it('does not ask until the landing visit has succeeded', () => {
        switchTo('globex');

        expect(reload).not.toHaveBeenCalled();
    });

    it('falls back to a plain reload away from the previous workspace', () => {
        switchTo('globex', '/settings/profile');

        // Off a workspace route the rosters are absent rather than empty, so
        // the client is holding no key for them and the way in ships them.
        expect(visit).toHaveBeenCalledTimes(1);
        expect(reload).toHaveBeenCalledWith();
    });
});
