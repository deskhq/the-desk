import { beforeEach, describe, expect, it, vi } from 'vitest';

const { patch, pageProps } = vi.hoisted(() => ({
    patch: vi.fn(),
    pageProps: {
        value: { auth: { user: { timezone: null as string | null } } },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { patch },
    usePage: () => ({ props: pageProps.value }),
}));

import { backgroundVisit } from '@/lib/backgroundVisit';

/**
 * Import a fresh copy of the composable. Auto-detection is guarded by a
 * module-level "already fired" flag, so each test needs its own module instance
 * to exercise the first-load path.
 */
async function freshUseTimezone() {
    vi.resetModules();

    return (await import('@/composables/useTimezone')).useTimezone();
}

/** Options of the first recorded timezone patch. */
function firstOptions(): Record<string, unknown> {
    return patch.mock.calls[0][2] as Record<string, unknown>;
}

describe('useTimezone', () => {
    beforeEach(() => {
        patch.mockClear();
        pageProps.value = { auth: { user: { timezone: null } } };
        vi.spyOn(
            Intl.DateTimeFormat.prototype,
            'resolvedOptions',
        ).mockReturnValue({
            timeZone: 'Europe/Paris',
        } as Intl.ResolvedDateTimeFormatOptions);
    });

    it('persists a manual choice as an ordinary foreground write', async () => {
        const { setTimezone } = await freshUseTimezone();

        setTimezone('Europe/Paris');

        expect(patch.mock.calls[0][1]).toEqual({ timezone: 'Europe/Paris' });
        expect(firstOptions().async).toBeUndefined();
    });

    it('auto-detects in the background so it never interrupts the first navigation', async () => {
        const { syncDetectedTimezone } = await freshUseTimezone();

        syncDetectedTimezone();

        // It fires shortly after the first authenticated load, right when the
        // user is likely to be clicking into a channel (#586).
        expect(patch).toHaveBeenCalledOnce();
        expect(patch.mock.calls[0][1]).toEqual({ timezone: 'Europe/Paris' });
        expect(firstOptions()).toMatchObject(backgroundVisit);
    });

    it('does not auto-detect once a zone is already known', async () => {
        pageProps.value = { auth: { user: { timezone: 'America/New_York' } } };

        const { syncDetectedTimezone } = await freshUseTimezone();

        syncDetectedTimezone();

        expect(patch).not.toHaveBeenCalled();
    });
});
