<?php

declare(strict_types=1);

use App\Actions\Fortify\ResetUserPassword;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

test('a bot cannot authenticate through the login screen', function (): void {
    $team = Team::factory()->create();
    $bot = User::factory()->bot($team)->create();

    $this->post(route('login.store'), [
        'email' => $bot->email,
        'password' => 'password',
    ]);

    // A bot carries no login password, so the credential check can never pass.
    $this->assertGuest();
});

test('a bot cannot gain a password through the reset flow', function (): void {
    $team = Team::factory()->create();
    $bot = User::factory()->bot($team)->create();

    expect(fn (): mixed => app(ResetUserPassword::class)->reset($bot, [
        'password' => 'new-password-1234',
        'password_confirmation' => 'new-password-1234',
    ]))->toThrow(ValidationException::class);

    expect($bot->refresh()->password)->toBeNull();
});
