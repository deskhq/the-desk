import { createInertiaApp } from '@inertiajs/vue3';
import { configureEcho } from '@laravel/echo-vue';
import { initializeTheme } from '@/composables/useAppearance';
import AuthLayout from '@/layouts/AuthLayout.vue';
import MainLayout from '@/layouts/MainLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { setMessages, translate } from '@/lib/i18n';
import type { Messages } from '@/lib/i18n';

configureEcho({
    broadcaster: 'reverb',
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    // Seed the translation catalog from the server-shared props before the first
    // render — on both the SSR pass and the client — so the initial paint is
    // already in the active locale (no flash of English on refresh). `translations`
    // is a once prop, so it rides the initial document only, not every visit.
    // Also expose the translation helper as a global `$t` for templates.
    withApp(app, { page }) {
        const props = page.props as {
            locale?: string;
            translations?: Messages;
        };

        setMessages(props.locale ?? 'en', props.translations ?? {});

        app.config.globalProperties.$t = translate;
    },
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
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

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
