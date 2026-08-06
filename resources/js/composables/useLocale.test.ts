import { describe, expect, it, vi } from 'vitest';

const patch = vi.hoisted(() => vi.fn());
const reload = vi.hoisted(() => vi.fn());
const setMessages = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({
    router: { patch, reload },
    usePage: () => ({ props: { auth: { user: { locale: 'en' } } } }),
}));

vi.mock('@/lib/i18n', () => ({
    fetchCatalog: vi.fn().mockResolvedValue({ Channels: 'Canaux' }),
    i18n: { locale: 'en' },
    setMessages,
}));

const { useLocale } = await import('@/composables/useLocale');

describe('useLocale', () => {
    it('swaps the catalog before persisting, so the UI re-renders at once', async () => {
        await useLocale().updateLocale('fr');

        expect(setMessages).toHaveBeenCalledWith('fr', { Channels: 'Canaux' });
        expect(patch).toHaveBeenCalledWith(
            expect.any(String),
            { locale: 'fr' },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('asks back for the props the server renders in the reader own language', async () => {
        await useLocale().updateLocale('fr');

        // Those props are cached per locale, so a language the reader has
        // already used this session is one the client believes it holds; only a
        // partial reload naming them escapes the exclusion (#1251).
        patch.mock.calls.at(-1)?.[2]?.onSuccess();

        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({
                async: true,
                preserveUrl: true,
                only: [
                    'locale',
                    'translations',
                    'slashCommands',
                    'sidebarPositions',
                    'invitableRoles',
                    'teams',
                    'currentTeam',
                ],
            }),
        );
    });
});
