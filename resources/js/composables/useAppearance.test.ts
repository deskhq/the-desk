// @vitest-environment jsdom
import { beforeEach, describe, expect, it } from 'vitest';
import { initializeTheme, useAppearance } from '@/composables/useAppearance';

/**
 * Exactly the surface the module-scope lift moved, and no more: `initializeTheme`
 * now owns the rehydration that used to sit behind `useAppearance`'s `onMounted`,
 * which a command's `run` cannot reach from outside a component instance.
 *
 * A browser test is structurally blind to this regressing. `initializeTheme`
 * already painted the right theme at boot without the ref, so dropping or
 * misordering the rehydration leaves the shell the right colour while
 * `appearance.value` sits at 'system' and storage says 'dark' — and the symptom
 * is the wrong radio selected on /settings/appearance, not a wrong-coloured page.
 */
/** jsdom has no `matchMedia`; the "system" theme resolves through it. */
function systemPrefers(scheme: 'dark' | 'light'): void {
    window.matchMedia = ((query: string) => ({
        matches: scheme === 'dark',
        media: query,
        addEventListener: () => {},
        removeEventListener: () => {},
    })) as unknown as typeof window.matchMedia;
}

describe('useAppearance', () => {
    beforeEach(() => {
        systemPrefers('light');
        localStorage.clear();
        document.documentElement.classList.remove('dark');
        useAppearance().appearance.value = 'system';
    });

    describe('initializeTheme', () => {
        it('rehydrates the stored appearance into the shared ref', () => {
            localStorage.setItem('appearance', 'dark');

            initializeTheme();

            expect(useAppearance().appearance.value).toBe('dark');
        });

        it('paints the stored theme', () => {
            localStorage.setItem('appearance', 'dark');

            initializeTheme();

            expect(document.documentElement.classList.contains('dark')).toBe(
                true,
            );
        });

        it('leaves the ref on system when nothing is stored', () => {
            initializeTheme();

            expect(useAppearance().appearance.value).toBe('system');
        });
    });

    describe('updateAppearance', () => {
        it('records the choice in storage, the cookie and the class', () => {
            useAppearance().updateAppearance('dark');

            expect(localStorage.getItem('appearance')).toBe('dark');
            expect(document.cookie).toContain('appearance=dark');
            expect(document.documentElement.classList.contains('dark')).toBe(
                true,
            );
        });

        it('takes the class back off for the light theme', () => {
            useAppearance().updateAppearance('dark');
            useAppearance().updateAppearance('light');

            expect(useAppearance().appearance.value).toBe('light');
            expect(document.documentElement.classList.contains('dark')).toBe(
                false,
            );
        });
    });
});
