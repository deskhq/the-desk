<?php

declare(strict_types=1);

namespace App\Support\Branding;

/**
 * The web app manifest that makes the instance installable.
 *
 * Built per request rather than emitted at build time, so the installed app's
 * identity follows `APP_NAME` and the operator's icon overrides with no rebuild
 * of the image. The name is the only operator-facing value here; the rest of
 * the copy is deliberately request-locale independent — an installed app keeps
 * whatever description it was installed with — so it stays out of the
 * translation catalogs.
 */
final class WebManifest
{
    /**
     * Brand ink — the plate the icon mark sits on, reused for the installed
     * app's title bar and splash screen so launching it reads as one surface.
     */
    private const string BRAND_INK = '#1d1a15';

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => '/',
            'name' => (string) config('app.name'),
            'short_name' => (string) config('app.name'),
            'description' => 'Open source, self-hosted team chat',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'theme_color' => self::BRAND_INK,
            'background_color' => self::BRAND_INK,
            'icons' => [
                [
                    'src' => '/icons/icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => '/icons/icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => '/icons/icon-maskable-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
            // Chromium only offers its richer install dialog — app name,
            // description and a preview carousel — when the manifest carries a
            // screenshot for the form factor it is installing on, so one of each
            // is the minimum that earns it. The set stays deliberately small:
            // every shot has to be re-taken on a redesign. Screenshots are not
            // operator-overridable (they picture the app, not the brand), so
            // they stay static files under public/.
            'screenshots' => [
                [
                    'src' => '/screenshots/channel-wide.png',
                    'sizes' => '1440x900',
                    'type' => 'image/png',
                    'form_factor' => 'wide',
                    'label' => 'A team channel open in a desktop window',
                ],
                [
                    'src' => '/screenshots/channel-narrow.png',
                    'sizes' => '780x1688',
                    'type' => 'image/png',
                    'form_factor' => 'narrow',
                    'label' => 'A team channel open on a phone',
                ],
            ],
        ];
    }
}
