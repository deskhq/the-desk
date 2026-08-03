import type { ComputedRef, Ref } from 'vue';
import { computed, ref } from 'vue';
import { writeClientCookie } from '@/lib/cookies';
import type { Appearance, ResolvedAppearance } from '@/types';

export type { Appearance, ResolvedAppearance };

export type UseAppearanceReturn = {
    appearance: Ref<Appearance>;
    resolvedAppearance: ComputedRef<ResolvedAppearance>;
    updateAppearance: (value: Appearance) => void;
};

export function updateTheme(value: Appearance): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (value === 'system') {
        const mediaQueryList = window.matchMedia(
            '(prefers-color-scheme: dark)',
        );
        const systemTheme = mediaQueryList.matches ? 'dark' : 'light';

        document.documentElement.classList.toggle(
            'dark',
            systemTheme === 'dark',
        );
    } else {
        document.documentElement.classList.toggle('dark', value === 'dark');
    }
}

const setCookie = (name: string, value: string, days = 365) => {
    writeClientCookie(name, value, days * 24 * 60 * 60);
};

const mediaQuery = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

const getStoredAppearance = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return localStorage.getItem('appearance') as Appearance | null;
};

const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const handleSystemThemeChange = () => {
    const currentAppearance = getStoredAppearance();

    updateTheme(currentAppearance || 'system');
};

const appearance = ref<Appearance>('system');

/**
 * Adopt the stored preference at boot: paint it, hold it in {@link appearance}
 * for the surfaces that show which one is picked, and follow the system theme
 * from here on. Called once by `app.ts`.
 *
 * The rehydration used to sit behind an `onMounted` inside
 * {@link useAppearance}, which re-read storage for every component that called
 * the composable and left the shared ref unreachable from module scope — where
 * the command registry's theme verbs live. One place, once, at boot.
 */
export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const savedAppearance = getStoredAppearance();

    if (savedAppearance) {
        appearance.value = savedAppearance;
    }

    updateTheme(savedAppearance || 'system');

    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance(): UseAppearanceReturn {
    const resolvedAppearance = computed<ResolvedAppearance>(() => {
        if (appearance.value === 'system') {
            return prefersDark() ? 'dark' : 'light';
        }

        return appearance.value;
    });

    function updateAppearance(value: Appearance) {
        appearance.value = value;

        // Store in localStorage for client-side persistence...
        localStorage.setItem('appearance', value);

        // Store in cookie for SSR...
        setCookie('appearance', value);

        updateTheme(value);
    }

    return {
        appearance,
        resolvedAppearance,
        updateAppearance,
    };
}
