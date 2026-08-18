<?php

declare(strict_types=1);

use Tests\Support\ProductionCompose;

/**
 * `app`, `queue`, `queue-broadcasts`, `reverb`, and `scheduler` all mount the
 * same named volume (`storage-app`). On a fresh volume the daemon seeds it from
 * the image (copy-up); containers created at the same instant race inside
 * dockerd and one dies with `mkdir /var/lib/docker/volumes/..._data/private:
 * file exists`. Making the workers wait for `app` means exactly one container
 * triggers the copy-up. See issue #609.
 */
test('every app-role service mounts the shared storage-app volume', function (string $service): void {
    expect(ProductionCompose::services()[$service]['volumes'])
        ->toContain('storage-app:/app/storage/app');
})->with(fn (): array => ProductionCompose::laravelServices());

test('the shared storage-app volume is initialised by app alone', function (string $service): void {
    $dependsOn = ProductionCompose::services()[$service]['depends_on'];

    expect($dependsOn)->toHaveKey('app')
        ->and($dependsOn['app']['condition'])->toBe('service_started');
})->with(fn (): array => ProductionCompose::laravelWorkers());

test('waiting on app does not drop the infrastructure dependencies', function (string $service): void {
    $dependsOn = ProductionCompose::services()[$service]['depends_on'];

    foreach (['pgsql', 'redis', 'meilisearch'] as $dependency) {
        expect($dependsOn[$dependency]['condition'])->toBe('service_healthy');
    }
})->with(fn (): array => ProductionCompose::laravelServices());

test('app does not depend on itself', function (): void {
    expect(ProductionCompose::services()['app']['depends_on'])->not->toHaveKey('app');
});

/**
 * The unfurler runs the shared image and nothing else about the application.
 *
 * That is the whole reason `sharedImageServices()` and `laravelServices()` are
 * two sets rather than one. It parses bytes fetched from wherever a member
 * pointed it, so it gets no `.env` (which carries APP_KEY, the database password
 * and every other secret), no application storage, and no branding. Every one of
 * those would arrive at once if someone "simplified" it onto the `x-app` anchor,
 * and every other guard in this file would keep passing while it happened. See
 * ADR-0016.
 */
test('the unfurler carries no application state', function (): void {
    $unfurler = ProductionCompose::services()['unfurler'];

    expect($unfurler)->not->toHaveKey('env_file')
        ->and($unfurler['entrypoint'])->toBe(['/usr/local/bin/unfurler']);

    foreach ((array) ($unfurler['volumes'] ?? []) as $mount) {
        expect($mount)
            ->not->toContain('storage-app')
            ->not->toContain('branding')
            ->not->toContain('.env');
    }
});

test('the unfurler is not reachable from outside the compose network', function (): void {
    // The second of the two controls, and the free one: the shared secret stops
    // another container on this network using it as an open fetcher, and this
    // stops anything off-box reaching it at all.
    expect(ProductionCompose::services()['unfurler'])->not->toHaveKey('ports');
});
