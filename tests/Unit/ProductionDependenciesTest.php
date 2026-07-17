<?php

declare(strict_types=1);

/**
 * The production image installs with `composer install --no-dev`, so anything the
 * demo seeder leans on must live in `require`, not `require-dev`. Faker backs
 * `DemoSeeder` (`fake()` and every model factory), and `demo:seed` is a
 * production command (a public demo host reseeds on a schedule) — so faker has to
 * ship in production builds. See issue #464.
 */
test('faker ships as a production dependency so demo:seed runs under --no-dev', function (): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require'])->toHaveKey('fakerphp/faker')
        ->and($composer['require-dev'] ?? [])->not->toHaveKey('fakerphp/faker');
});
