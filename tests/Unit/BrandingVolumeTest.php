<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Whitelabeling has to survive an upgrade, so an operator's brand assets are
 * bind-mounted from the host rather than baked into the image or copied into a
 * named volume they would then have to seed. Every app-role service mounts the
 * same directory: the web app resolves the assets, and the workers render mail
 * and notifications that reference them. See issue #970.
 */
$compose = fn (): array => Yaml::parseFile(dirname(__DIR__, 2).'/docker-compose.prod.yml');

$appServices = ['app', 'queue', 'queue-broadcasts', 'reverb', 'scheduler'];

test('every app-role service mounts the operator branding directory read-only', function (string $service) use ($compose): void {
    expect($compose()['services'][$service]['volumes'])
        ->toContain('./branding:/app/storage/branding:ro');
})->with($appServices);

test('the branding mount lands where the app resolves overrides from', function (): void {
    // The image installs the application at /app, so the mount target has to
    // be storage_path('branding') as config/branding.php spells it, or an
    // operator's files land somewhere the app never looks.
    expect((string) file_get_contents(dirname(__DIR__, 2).'/config/branding.php'))
        ->toContain("storage_path('branding')");
});

test('the shipped defaults every override falls back to are in the build context', function (): void {
    $root = dirname(__DIR__, 2);

    foreach (explode("\n", (string) file_get_contents($root.'/.dockerignore')) as $line) {
        expect(trim($line))->not->toStartWith('resources/branding');
    }

    expect(is_dir($root.'/resources/branding'))->toBeTrue();
});

test('the attribution toggle is documented in the production env template', function (): void {
    expect((string) file_get_contents(dirname(__DIR__, 2).'/.env.prod.example'))
        ->toContain('BRANDING_ATTRIBUTION=');
});
