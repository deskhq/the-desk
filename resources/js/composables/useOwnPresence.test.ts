import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const page = reactive({
    props: {
        auth: {
            user: {
                presence: undefined as string | undefined,
                dnd: null as Record<string, unknown> | null,
                timezone: null as string | null,
            },
        },
    },
});

vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

import { useOwnPresence } from '@/composables/useOwnPresence';

/** A do-not-disturb pause that has not lapsed yet. */
function activePause(): Record<string, unknown> {
    return {
        until: new Date(Date.now() + 60_000).toISOString(),
        scheduleEnabled: false,
        startsAt: null,
        endsAt: null,
        scheduleSnoozedUntil: null,
    };
}

describe('useOwnPresence', () => {
    it('reads the viewer through as active when the prop carries no presence', () => {
        page.props.auth.user.presence = undefined;

        expect(useOwnPresence().presence.value).toBe('active');
    });

    it('keeps the presence the shared prop carries', () => {
        page.props.auth.user.presence = 'away';

        expect(useOwnPresence().presence.value).toBe('away');
    });

    it('is not in do-not-disturb without a configuration', () => {
        page.props.auth.user.dnd = null;
        page.props.auth.user.timezone = null;

        expect(useOwnPresence().isDnd.value).toBe(false);
    });

    it('evaluates a running pause locally rather than waiting for the server', () => {
        page.props.auth.user.dnd = activePause();
        page.props.auth.user.timezone = 'Europe/Paris';

        expect(useOwnPresence().isDnd.value).toBe(true);
    });

    it('follows the shared prop as it changes', () => {
        page.props.auth.user.presence = 'active';
        page.props.auth.user.dnd = null;

        const { presence, isDnd } = useOwnPresence();

        expect(isDnd.value).toBe(false);

        page.props.auth.user.presence = 'away';
        page.props.auth.user.dnd = activePause();

        expect(presence.value).toBe('away');
        expect(isDnd.value).toBe(true);
    });
});
