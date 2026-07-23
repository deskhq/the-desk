import { createInertiaApp } from '@inertiajs/vue3';
import { configureEcho } from '@laravel/echo-vue';
import { initializeTheme } from '@/composables/useAppearance';
import AuthLayout from '@/layouts/AuthLayout.vue';
import MainLayout from '@/layouts/MainLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { setTimeFormat } from '@/lib/clock';
import { reverbEchoConfig } from '@/lib/echo';
import type { ReverbRuntimeConfig } from '@/lib/echo';
import { initializeFlashToast } from '@/lib/flashToast';
import { setMessages, translate } from '@/lib/i18n';
import type { Messages } from '@/lib/i18n';
import { initializeOverlayInert } from '@/lib/overlayInert';
import type { TimeFormat } from '@/types';

/**
 * Seeded from the server-shared props at boot (see `withApp`), so both Echo and
 * the document title use the operator's runtime settings rather than values
 * baked into the bundle at build time.
 */
let appName = 'Laravel';

// createInertiaApp returns a bootstrap Promise we intentionally don't await.
void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    // Seed the translation catalog from the server-shared props before the first
    // render — on both the SSR pass and the client — so the initial paint is
    // already in the active locale (no flash of English on refresh). `translations`
    // is a once prop, so it rides the initial document only, not every visit.
    // The clock-style preference is seeded the same way, so the first paint's
    // times of day are already on the viewer's chosen clock.
    // Also expose the translation helper as a global `$t` for templates.
    withApp(app, { page }) {
        const props = page.props as {
            name?: string;
            reverb?: ReverbRuntimeConfig;
            locale?: string;
            translations?: Messages;
            auth?: { user?: { time_format?: TimeFormat } | null };
        };

        appName = props.name ?? 'Laravel';

        // Configure Echo once, at boot, from the runtime Reverb config — before
        // any component's `useEcho` runs. Guard on `reverb` so a page without the
        // shared prop (e.g. a bare error response) doesn't throw.
        if (props.reverb) {
            configureEcho(reverbEchoConfig(props.reverb));
        }

        setMessages(props.locale ?? 'en', props.translations ?? {});

        setTimeFormat(props.auth?.user?.time_format ?? 'auto');

        app.config.globalProperties.$t = translate;
    },
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
            case name === 'Error':
                return null;
            case name.startsWith('channels/'):
                return MainLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
            case name.startsWith('teams/'):
                return [MainLayout, SettingsLayout];
            default:
                return MainLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

initializeTheme();

initializeFlashToast();

initializeOverlayInert();
