<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

test('production enforces a strong default password policy', function () {
    $this->app->detectEnvironment(fn () => 'production');

    try {
        (new AppServiceProvider($this->app))->boot();

        $validator = Validator::make(
            ['password' => 'weak'],
            ['password' => Password::defaults()],
        );

        expect($validator->fails())->toBeTrue()
            ->and(Password::defaults())->not->toBeNull();
    } finally {
        // Restore non-destructive defaults so the test database can be torn down.
        DB::prohibitDestructiveCommands(false);
        Password::defaults(fn () => null);
    }
});
