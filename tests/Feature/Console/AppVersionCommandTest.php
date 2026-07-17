<?php

use Illuminate\Support\Facades\Artisan;

it('prints the running version and nothing else', function (): void {
    config()->set('app.version', '9.9.9');

    $exitCode = Artisan::call('app:version');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toBe("9.9.9\n");
});

it('prints the version from the VERSION file, stripped of its release-please annotation', function (): void {
    Artisan::call('app:version');

    expect(trim(Artisan::output()))
        ->toBe(trim((string) preg_replace('/#.*/', '', (string) file_get_contents(base_path('VERSION')))))
        ->toMatch('/^\d+\.\d+\.\d+$/');
});
