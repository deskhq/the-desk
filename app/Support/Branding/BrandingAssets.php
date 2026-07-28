<?php

declare(strict_types=1);

namespace App\Support\Branding;

/**
 * Resolves the instance's brand assets, preferring the operator's override.
 *
 * Each asset is served from its canonical URL (`/favicon.ico`, `/og-image.png`,
 * `/icons/icon-192.png`, …) by a route rather than as a static file, which is
 * what makes overriding it possible at all: a file sitting in `public/` is
 * served by the web server before the framework ever sees the request. The
 * shipped defaults therefore live in `resources/branding` and an override is
 * the same filename inside the operator's branding directory.
 */
class BrandingAssets
{
    /**
     * The overridable assets, named by the path they are served at and by the
     * filename an operator drops into the branding directory to replace one.
     *
     * @var list<string>
     */
    public const ASSETS = [
        'favicon.ico',
        'favicon.svg',
        'apple-touch-icon.png',
        'og-image.png',
        'icons/icon-192.png',
        'icons/icon-512.png',
        'icons/icon-maskable-512.png',
    ];

    /**
     * The logo mark filenames, in the order they are tried. The mark has no
     * shipped file: its default is the inline SVG component `AppLogoIcon.vue`,
     * whose lower planes ride on `currentColor` so the mark adapts to the
     * surface it sits on. An override cannot do that, so it is only rendered
     * when the operator has actually supplied one.
     *
     * @var list<string>
     */
    public const LOGO_CANDIDATES = ['logo.svg', 'logo.png'];

    /**
     * Content types for the extensions the branding directory accepts, so an
     * override is served as itself rather than as whatever the shipped default
     * happened to be.
     *
     * @var array<string, string>
     */
    private const array MIME_TYPES = [
        'ico' => 'image/x-icon',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
    ];

    /**
     * The absolute path an asset resolves to: the operator's override when one
     * exists, the shipped default otherwise.
     */
    public function path(string $asset): string
    {
        return $this->overridePath($asset) ?? resource_path('branding/'.$asset);
    }

    /**
     * The absolute path of the operator's override for an asset, or null when
     * they have not supplied one.
     */
    public function overridePath(string $asset): ?string
    {
        $path = rtrim((string) config('branding.path'), '/').'/'.$asset;

        return is_file($path) ? $path : null;
    }

    /**
     * The absolute path of the operator's logo mark, or null when the instance
     * still uses the shipped inline mark.
     */
    public function logoPath(): ?string
    {
        foreach (self::LOGO_CANDIDATES as $candidate) {
            $path = $this->overridePath($candidate);

            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    /**
     * The content type to serve a resolved asset path with.
     */
    public function mimeType(string $path): string
    {
        return self::MIME_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }
}
