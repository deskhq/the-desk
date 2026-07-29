<?php

declare(strict_types=1);

use Tests\Support\ProductionCompose;

/**
 * `docker-compose.build.yml` exists so an operator can run the stack from their
 * own source. It does that by restoring a local `build:` on top of every
 * service that would otherwise pull the published image — so a service it
 * misses keeps the registry reference and is pulled instead of built.
 *
 * That drift is close to invisible: the pull quietly succeeds whenever GHCR
 * happens to hold the tag in `APP_VERSION`, and only fails on an unreleased
 * commit — a release-please merge, a fork, a private patch. Which is exactly
 * the case the overlay is for. `queue-broadcasts` was missing for five months
 * this way (#1040), so the coverage is asserted here rather than left to the
 * calendar.
 */
$overlaid = function (): array {
    $overlay = ProductionCompose::buildOverlay()['services'];
    $merged = [];

    foreach (ProductionCompose::services() as $name => $service) {
        $merged[$name] = array_replace($service, $overlay[$name] ?? []);
    }

    return $merged;
};

test('the app-role services are derived from the stack rather than assumed', function (): void {
    // Every guard below drives its cases off this set, so a production `image:`
    // the derivation stops recognising would empty them all rather than fail.
    expect(ProductionCompose::appRoleServices())->toContain('app')
        ->and(ProductionCompose::appRoleServices())->toContain('queue-broadcasts');
});

test('the build overlay restores a local build for every service on the shared app image', function (string $service): void {
    $overlaid = ProductionCompose::buildOverlay()['services'][$service] ?? null;

    expect($overlaid)->not->toBeNull()
        ->and($overlaid['build']['context'])->toBe('.')
        ->and($overlaid['image'])->toBe('the-desk:latest');
})->with(fn (): array => ProductionCompose::appRoleServices());

test('no service is pulled from the registry once the overlay is layered on', function (string $service) use ($overlaid): void {
    expect(ProductionCompose::imageOf($overlaid()[$service]))
        ->not->toContain(ProductionCompose::APP_IMAGE);
})->with(fn (): array => array_keys(ProductionCompose::services()));

test('the overlay only names services the production stack declares', function (): void {
    expect(array_keys(ProductionCompose::buildOverlay()['services']))
        ->each->toBeIn(array_keys(ProductionCompose::services()));
});

test('the overlay leaves the infrastructure services alone', function () use ($overlaid): void {
    foreach (['pgsql', 'meilisearch', 'redis'] as $service) {
        expect($overlaid()[$service])->toEqual(ProductionCompose::services()[$service]);
    }
});
