<?php

test('the app shell links the web manifest and declares standalone capability', function (): void {
    $html = $this->get('/login')->assertOk()->getContent();

    expect($html)
        ->toContain('<link rel="manifest" href="/build/manifest.webmanifest">')
        ->toContain('<meta name="mobile-web-app-capable" content="yes">')
        ->toContain('<meta name="apple-mobile-web-app-capable" content="yes">')
        ->toContain('<meta name="apple-mobile-web-app-title" content="'.config('app.name').'">');
});

test('the pwa icon set ships opaque square icons at the installable sizes', function (): void {
    $icons = [
        'icon-192.png' => 192,
        'icon-512.png' => 512,
        'icon-maskable-512.png' => 512,
    ];

    foreach ($icons as $file => $size) {
        [$width, $height, $type] = getimagesize(public_path('icons/'.$file));

        expect($width)->toBe($size)
            ->and($height)->toBe($size)
            ->and($type)->toBe(IMAGETYPE_PNG);
    }
});

test('every icon the manifest declares is shipped in public', function (): void {
    $manifest = file_get_contents(base_path('resources/pwa/manifest.ts'));

    preg_match_all("/src: '([^']+)'/", $manifest, $matches);

    expect($matches[1])->toHaveCount(3);

    foreach ($matches[1] as $src) {
        expect(file_exists(public_path(ltrim($src, '/'))))->toBeTrue();
    }
});
