<?php

declare(strict_types=1);

/**
 * The manifest as the browser receives it. It is rendered per request by
 * App\Http\Controllers\WebManifestController rather than emitted at build time,
 * so these read the route the browser reads instead of parsing a source file.
 *
 * @return array<string, mixed>
 */
function webManifest(): array
{
    return test()->get('/manifest.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->json();
}

test('the app shell links the web manifest and declares standalone capability', function (): void {
    $html = $this->get('/login')->assertOk()->getContent();

    expect($html)
        ->toContain('<link rel="manifest" href="/manifest.webmanifest">')
        ->toContain('<meta name="mobile-web-app-capable" content="yes">')
        ->toContain('<meta name="apple-mobile-web-app-capable" content="yes">')
        ->toContain('<meta name="apple-mobile-web-app-title" content="'.config('app.name').'">');
});

test('it describes a standalone app named after the instance', function (): void {
    config(['app.name' => 'Acme Chat']);

    expect(webManifest())
        ->name->toBe('Acme Chat')
        ->short_name->toBe('Acme Chat')
        ->id->toBe('/')
        ->start_url->toBe('/')
        ->scope->toBe('/')
        ->display->toBe('standalone')
        ->description->not->toBeEmpty()
        ->theme_color->toMatch('/^#[0-9a-f]{6}$/')
        ->background_color->toMatch('/^#[0-9a-f]{6}$/');
});

test('an instance renamed in .env installs under the new name with no rebuild', function (): void {
    config(['app.name' => 'First Name']);
    expect(webManifest())->name->toBe('First Name');

    config(['app.name' => 'Second Name']);
    expect(webManifest())->name->toBe('Second Name');
});

test('the pwa icon set ships opaque square icons at the installable sizes', function (): void {
    $icons = [
        'icon-192.png' => 192,
        'icon-512.png' => 512,
        'icon-maskable-512.png' => 512,
    ];

    foreach ($icons as $file => $size) {
        [$width, $height, $type] = getimagesize(resource_path('branding/icons/'.$file));

        expect($width)->toBe($size)
            ->and($height)->toBe($size)
            ->and($type)->toBe(IMAGETYPE_PNG);
    }
});

test('it declares the icon sizes browsers require to offer installation', function (): void {
    $icons = webManifest()['icons'];

    expect($icons)->toHaveCount(3)
        ->and(array_column($icons, 'sizes'))->toContain('192x192', '512x512')
        ->and(array_column($icons, 'type'))->each->toBe('image/png');
});

test('it ships a maskable icon so adaptive launchers do not letterbox the mark', function (): void {
    $icons = webManifest()['icons'];

    expect(array_filter($icons, fn (array $icon): bool => $icon['purpose'] === 'maskable'))
        ->toHaveCount(1);
});

test('every icon the manifest declares is one the operator can override', function (): void {
    foreach (webManifest()['icons'] as $icon) {
        expect($icon['src'])->toStartWith('/icons/');

        $this->get($icon['src'])->assertOk();
    }
});

test('the manifest declares a wide and a narrow screenshot for the richer install dialog', function (): void {
    $screenshots = webManifest()['screenshots'];

    expect($screenshots)->toHaveCount(2)
        ->and(array_column($screenshots, 'form_factor'))->toEqualCanonicalizing(['wide', 'narrow']);

    foreach ($screenshots as $screenshot) {
        expect($screenshot['src'])->toStartWith('/')
            ->and($screenshot['type'])->toBe('image/png')
            ->and($screenshot['label'])->not->toBeEmpty();
    }
});

test('every screenshot the manifest declares ships in public at its declared size', function (): void {
    foreach (webManifest()['screenshots'] as $screenshot) {
        $path = public_path(ltrim((string) $screenshot['src'], '/'));

        expect(file_exists($path))->toBeTrue();

        [$width, $height, $type] = getimagesize($path);

        expect($width.'x'.$height)->toBe($screenshot['sizes'])
            ->and($type)->toBe(IMAGETYPE_PNG);
    }
});

test('an unchanged manifest revalidates to a 304 rather than being sent again', function (): void {
    $etag = $this->get('/manifest.webmanifest')->assertOk()->headers->get('ETag');

    expect($etag)->not->toBeNull();

    $this->withHeaders(['If-None-Match' => $etag])
        ->get('/manifest.webmanifest')
        ->assertStatus(304);
});
