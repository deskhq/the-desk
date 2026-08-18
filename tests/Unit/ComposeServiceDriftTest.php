<?php

declare(strict_types=1);

use Tests\Support\ProductionCompose;

/**
 * An operator's `docker-compose.prod.yml` is the file they downloaded on the day
 * they installed. `docker/upgrade.sh` bumps `APP_VERSION` and syncs `.env`; until
 * #1040 nothing looked at the stack itself, so a service added in a later release
 * simply never arrived — and a missing service does not error. The stack comes up
 * clean and quietly does less, which is how `queue-broadcasts` was absent for
 * five months.
 *
 * `upgrade.sh` now names the services it cannot find. This is what keeps that
 * list honest: a service that matters to an upgrading operator has to be in it,
 * and the check has to still be wired into the script that runs it.
 */
$upgradeScript = fn (): string => (string) file_get_contents(dirname(__DIR__, 2).'/docker/upgrade.sh');

test('the upgrade script checks the stack for missing services', function () use ($upgradeScript): void {
    expect(str_contains($upgradeScript(), 'docker-compose.prod.yml'))->toBeTrue()
        ->and(str_contains($upgradeScript(), 'has no'))->toBeTrue(
            'upgrade.sh no longer reports services missing from the operator\'s stack',
        );
});

/**
 * The services an operator loses a feature by not having.
 *
 * Deliberately not every service in the file: `pgsql` and `redis` are not
 * optional in any meaningful sense, and a stack without them fails loudly rather
 * than silently. These are the ones whose absence is *quiet*.
 */
test('every quietly-optional service is one the upgrade script warns about', function (string $service) use ($upgradeScript): void {
    expect(ProductionCompose::services())->toHaveKey($service);

    expect(str_contains($upgradeScript(), $service))->toBeTrue(
        "upgrade.sh does not warn about a missing [{$service}], so an operator loses the feature in silence",
    );
})->with(['unfurler', 'queue-broadcasts']);

/**
 * The warning points somewhere that tells them what to paste.
 */
test('the warning sends the operator to the upgrade documentation', function () use ($upgradeScript): void {
    expect(str_contains($upgradeScript(), 'self-hosting/upgrading'))->toBeTrue();
});
