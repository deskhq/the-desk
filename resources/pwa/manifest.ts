import type { ManifestOptions } from 'vite-plugin-pwa';

/**
 * Brand ink — the plate the icon mark sits on, reused for the installed app's
 * title bar and splash screen so launching it reads as one surface.
 */
const BRAND_INK = '#1d1a15';

/**
 * The web app manifest that makes the instance installable.
 *
 * Emitted at build time to `/manifest.webmanifest`, so the name is baked in
 * from the build's `APP_NAME` rather than the request-time locale — the copy
 * here is deliberately name-only and stays out of the translation catalogs.
 */
export function pwaManifest(appName: string): Partial<ManifestOptions> {
    return {
        id: '/',
        name: appName,
        short_name: appName,
        description: 'Open source, self-hosted team chat',
        start_url: '/',
        scope: '/',
        display: 'standalone',
        theme_color: BRAND_INK,
        background_color: BRAND_INK,
        icons: [
            {
                src: '/icons/icon-192.png',
                sizes: '192x192',
                type: 'image/png',
                purpose: 'any',
            },
            {
                src: '/icons/icon-512.png',
                sizes: '512x512',
                type: 'image/png',
                purpose: 'any',
            },
            {
                src: '/icons/icon-maskable-512.png',
                sizes: '512x512',
                type: 'image/png',
                purpose: 'maskable',
            },
        ],
    };
}
