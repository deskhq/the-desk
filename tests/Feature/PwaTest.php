<?php

use Illuminate\Support\Str;

/**
 * The entries of one array in the manifest source — `icons` or `screenshots` —
 * each flattened to its string members, so a test can assert per key instead of
 * over every `src` the file happens to declare.
 *
 * @return list<array<string, string>>
 */
function pwaManifestEntries(string $key): array
{
    $source = file_get_contents(base_path('resources/pwa/manifest.ts'));

    expect($source)->toContain($key.': [');

    $block = Str::before(Str::after($source, $key.': ['), '],');

    preg_match_all('/\{(.*?)\}/s', $block, $objects);

    return array_map(function (string $object): array {
        preg_match_all("/(\w+): '([^']*)'/", $object, $pairs, PREG_SET_ORDER);

        return array_column($pairs, 2, 1);
    }, $objects[1]);
}

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
    $icons = pwaManifestEntries('icons');

    expect($icons)->toHaveCount(3);

    foreach ($icons as $icon) {
        expect(file_exists(public_path(ltrim($icon['src'], '/'))))->toBeTrue();
    }
});

test('the manifest declares a wide and a narrow screenshot for the richer install dialog', function (): void {
    $screenshots = pwaManifestEntries('screenshots');

    expect($screenshots)->toHaveCount(2)
        ->and(array_column($screenshots, 'form_factor'))->toEqualCanonicalizing(['wide', 'narrow']);

    foreach ($screenshots as $screenshot) {
        expect($screenshot['src'])->toStartWith('/')
            ->and($screenshot['type'])->toBe('image/png')
            ->and($screenshot['label'])->not->toBeEmpty();
    }
});

test('every screenshot the manifest declares ships in public at its declared size', function (): void {
    foreach (pwaManifestEntries('screenshots') as $screenshot) {
        $path = public_path(ltrim($screenshot['src'], '/'));

        expect(file_exists($path))->toBeTrue();

        [$width, $height, $type] = getimagesize($path);

        expect($width.'x'.$height)->toBe($screenshot['sizes'])
            ->and($type)->toBe(IMAGETYPE_PNG);
    }
});
