import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                bunny('Newsreader', {
                    weights: [400, 500, 600],
                    styles: ['normal', 'italic'],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
        // The worker is emitted into `public/` rather than alongside the hashed
        // bundles in `public/build/`: a worker can only control the paths below
        // its own, so it has to be served from the web root to cover the whole
        // app. `injectionPoint: undefined` opts out of Workbox precaching
        // entirely — we own the worker's contents (see
        // `resources/pwa/service-worker.ts`).
        //
        // `manifest: false` because the web app manifest is no longer a build
        // artifact: `/manifest.webmanifest` is a Laravel route rendered per
        // request (App\Http\Controllers\WebManifestController), so the installed
        // app's name and icons follow `.env` and the operator's branding
        // overrides instead of whatever `APP_NAME` happened to be set to on the
        // machine that built the image.
        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources/pwa',
            filename: 'service-worker.ts',
            injectManifest: { injectionPoint: undefined },
            injectRegister: null,
            registerType: 'autoUpdate',
            outDir: 'public',
            buildBase: '/',
            manifest: false,
        }),
    ],
});
