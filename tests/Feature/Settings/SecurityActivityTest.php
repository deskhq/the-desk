<?php

use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;

test('signing in records a security event with request context', function (): void {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $event = SecurityEvent::query()
        ->where('user_id', $user->id)
        ->where('type', SecurityEventType::LoggedIn)
        ->sole();

    expect($event->ip_address)->toBe('127.0.0.1');
    expect($event->is_new_device)->toBeTrue();
});

test('a repeat sign in from the same device is not flagged as new', function (): void {
    $user = User::factory()->create();

    $credentials = ['email' => $user->email, 'password' => 'password'];

    $this->post(route('login.store'), $credentials);
    $this->post(route('logout'));
    $this->post(route('login.store'), $credentials);

    $logins = SecurityEvent::query()
        ->where('user_id', $user->id)
        ->where('type', SecurityEventType::LoggedIn);

    expect($logins->count())->toBe(2);
    expect((clone $logins)->where('is_new_device', true)->count())->toBe(1);
});

test('signing out records a security event', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('logout'));

    expect(SecurityEvent::query()
        ->where('user_id', $user->id)
        ->where('type', SecurityEventType::LoggedOut)
        ->exists())->toBeTrue();
});

test('signing out as a guest records nothing', function (): void {
    event(new Logout('web', null));

    expect(SecurityEvent::query()->count())->toBe(0);
});

test('changing the password records a security event', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertRedirect(route('security.edit'));

    expect(SecurityEvent::query()
        ->where('user_id', $user->id)
        ->where('type', SecurityEventType::PasswordChanged)
        ->exists())->toBeTrue();
});

test('resetting the password records a security event', function (): void {
    $user = User::factory()->create();

    event(new PasswordReset($user));

    expect(SecurityEvent::query()
        ->where('user_id', $user->id)
        ->where('type', SecurityEventType::PasswordReset)
        ->exists())->toBeTrue();
});

test('two factor changes are recorded', function (): void {
    $user = User::factory()->create();

    event(new TwoFactorAuthenticationEnabled($user));
    event(new TwoFactorAuthenticationConfirmed($user));
    event(new RecoveryCodesGenerated($user));
    event(new TwoFactorAuthenticationDisabled($user));

    $recorded = SecurityEvent::query()
        ->where('user_id', $user->id)
        ->pluck('type')
        ->all();

    expect($recorded)->toContain(
        SecurityEventType::TwoFactorEnabled,
        SecurityEventType::TwoFactorConfirmed,
        SecurityEventType::RecoveryCodesGenerated,
        SecurityEventType::TwoFactorDisabled,
    );
});

test('the security page lists recent activity newest first', function (): void {
    $user = User::factory()->create();

    SecurityEvent::factory()->for($user)->ofType(SecurityEventType::PasswordChanged)->create(['created_at' => now()->subMinute()]);
    SecurityEvent::factory()->for($user)->newDevice()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/Security')
            ->has('securityEvents', 2)
            ->has('securityEvents.0', fn (Assert $event): Assert => $event
                ->where('isNewDevice', true)
                ->where('label', SecurityEventType::LoggedIn->label())
                ->hasAll(['id', 'type', 'ipAddress', 'browser', 'platform', 'occurredAt']),
            ),
        );
});

test('the activity log is scoped to the authenticated user', function (): void {
    $user = User::factory()->create();
    SecurityEvent::factory()->for(User::factory())->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('securityEvents', []));
});
