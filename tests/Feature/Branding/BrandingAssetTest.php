<?php

use App\Support\Branding\BrandingAssets;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->brandingPath = sys_get_temp_dir().'/branding-'.bin2hex(random_bytes(8));

    config(['branding.path' => $this->brandingPath]);
});

afterEach(function (): void {
    File::deleteDirectory($this->brandingPath);
});

/**
 * Write an operator override into the branding directory under test.
 */
function overrideBrandingAsset(string $asset, string $contents = 'operator-asset'): string
{
    $path = test()->brandingPath.'/'.$asset;

    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);

    return $path;
}

/**
 * The file an asset route actually served, so a test can assert which of the
 * two candidates — override or shipped default — the route resolved to.
 */
function servedFile(string $url): string
{
    return test()->get($url)->assertOk()->baseResponse->getFile()->getPathname();
}

test('it serves the shipped default when the operator supplied no override', function (string $asset): void {
    expect(servedFile('/'.$asset))->toBe(resource_path('branding/'.$asset));
})->with(BrandingAssets::ASSETS);

test('it serves the operator override in place of the shipped default', function (string $asset): void {
    $override = overrideBrandingAsset($asset);

    expect(servedFile('/'.$asset))->toBe($override);
})->with(BrandingAssets::ASSETS);

test('a partial override leaves every other asset on the shipped default', function (): void {
    $override = overrideBrandingAsset('icons/icon-192.png');

    expect(servedFile('/icons/icon-192.png'))->toBe($override)
        ->and(servedFile('/icons/icon-512.png'))->toBe(resource_path('branding/icons/icon-512.png'));
});

test('it serves each asset as its own content type, revalidated rather than held', function (): void {
    $this->get('/favicon.svg')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('Cache-Control', 'no-cache, public')
        // An operator override is a file we did not author, so the browser is
        // told to trust the declared type rather than sniff its way to one.
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->get('/favicon.ico')->assertHeader('Content-Type', 'image/x-icon');
    $this->get('/og-image.png')->assertHeader('Content-Type', 'image/png');
});

test('an unchanged asset revalidates to a 304 rather than sending the bytes again', function (): void {
    $etag = $this->get('/favicon.ico')->assertOk()->headers->get('ETag');

    expect($etag)->not->toBeNull();

    $this->withHeaders(['If-None-Match' => $etag])
        ->get('/favicon.ico')
        ->assertStatus(304);
});

test('an asset name outside the branded set is not routed', function (string $url): void {
    $this->get($url)->assertNotFound();
})->with([
    '/icons/icon-999.png',
    '/icons/../.env',
    '/logo.svg',
]);

test('it serves the operator logo mark only once one has been supplied', function (): void {
    $this->get('/branding/logo')->assertNotFound();

    $override = overrideBrandingAsset('logo.png');

    expect(servedFile('/branding/logo'))->toBe($override);
});

test('it prefers a vector logo mark over a raster one', function (): void {
    overrideBrandingAsset('logo.png');
    $svg = overrideBrandingAsset('logo.svg');

    expect(servedFile('/branding/logo'))->toBe($svg);
});
