<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The enforcement flag is a plain runtime config value: the verification routes
 * stay registered either way, so a request-time override is enough to re-gate
 * everyone without rebooting the app the way REGISTRATION_ENABLED must.
 */
function enableEmailVerification(bool $enabled): void
{
    config(['fortify.email_verification_enabled' => $enabled]);
}

test('hasVerifiedEmail treats every account as verified when the flag is off', function (): void {
    enableEmailVerification(false);

    $user = User::factory()->unverified()->create();

    expect($user->hasVerifiedEmail())->toBeTrue();
});

test('hasVerifiedEmail reflects email_verified_at when the flag is on', function (): void {
    enableEmailVerification(true);

    expect(User::factory()->unverified()->create()->hasVerifiedEmail())->toBeFalse();
    expect(User::factory()->create()->hasVerifiedEmail())->toBeTrue();
});

test('registration logs the user straight in with no verification email when the flag is off', function (): void {
    enableEmailVerification(false);
    Notification::fake();

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect();
    Notification::assertNothingSent();

    // A verified-gated route is reachable immediately.
    $this->get(route('appearance.edit'))->assertOk();
});

test('registration leaves the user unverified and emails them when the flag is on', function (): void {
    enableEmailVerification(true);
    Notification::fake();

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();

    $user = User::whereEmail('test@example.com')->firstOrFail();

    expect($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class);

    // The verified middleware bounces them to the verify screen.
    $this->get(route('appearance.edit'))->assertRedirect(route('verification.notice'));
});

test('the verified middleware is a no-op for unverified users when the flag is off', function (): void {
    enableEmailVerification(false);

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('appearance.edit'))->assertOk();
});

test('the verified middleware blocks unverified users when the flag is on', function (): void {
    enableEmailVerification(true);

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('appearance.edit'))
        ->assertRedirect(route('verification.notice'));
});

test('the shared emailVerificationEnabled prop reflects the flag', function (): void {
    enableEmailVerification(true);

    $this->get(route('login'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('auth/Login')
        ->where('emailVerificationEnabled', true),
    );

    enableEmailVerification(false);

    $this->get(route('login'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('auth/Login')
        ->where('emailVerificationEnabled', false),
    );
});
