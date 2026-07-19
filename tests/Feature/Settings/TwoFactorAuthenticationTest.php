<?php

use App\Enums\SecurityEventType;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Features;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;

/**
 * Build a user who has already enrolled in and confirmed two-factor auth,
 * with a real secret so a live TOTP code can be generated against it.
 */
function enrolledTwoFactorUser(): User
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();

    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(
            Collection::times(8, fn (): string => RecoveryCode::generate())->all(),
        )),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

test('the two factor feature is registered regardless of the toggle', function (): void {
    // Registered unconditionally so Wayfinder always emits the 2FA route
    // modules and the frontend build stays stable when the toggle is off.
    expect(Features::enabled(Features::twoFactorAuthentication()))->toBeTrue();
    expect(Route::has('two-factor.login'))->toBeTrue();
});

test('the security page offers two factor management when the toggle is on', function (): void {
    config(['fortify.two_factor_enabled' => true, 'sso.enforced' => false]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/Security')
            ->where('canManageTwoFactor', true)
            ->where('twoFactorEnabled', false)
            ->where('requiresConfirmation', true),
        );
});

test('the security page hides two factor management when the toggle is off', function (): void {
    config(['fortify.two_factor_enabled' => false]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/Security')
            ->where('canManageTwoFactor', false)
            ->missing('twoFactorEnabled')
            ->missing('requiresConfirmation'),
        );
});

test('the security page hides two factor management under SSO enforcement', function (): void {
    // Under AUTH_SSO_ONLY the identity provider owns MFA and users have no
    // usable local password, so app-native TOTP is moot.
    config(['fortify.two_factor_enabled' => true, 'sso.enforced' => true]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('canManageTwoFactor', false)
            ->missing('twoFactorEnabled'),
        );
});

test('the security page exposes setup material while enrolment is pending', function (): void {
    config(['fortify.two_factor_enabled' => true]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'));

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('twoFactorEnabled', false)
            ->where('twoFactor.pendingConfirmation', true)
            ->has('twoFactor.qrSvg')
            ->has('twoFactor.secretKey')
            ->has('twoFactor.recoveryCodes', 8),
        );
});

test('the security page hides the secret once two factor is confirmed', function (): void {
    config(['fortify.two_factor_enabled' => true]);

    $user = enrolledTwoFactorUser();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('twoFactorEnabled', true)
            ->where('twoFactor.pendingConfirmation', false)
            ->where('twoFactor.qrSvg', null)
            ->where('twoFactor.secretKey', null)
            ->has('twoFactor.recoveryCodes', 8),
        );
});

test('the security page omits two factor state when not enrolled', function (): void {
    config(['fortify.two_factor_enabled' => true]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('twoFactorEnabled', false)
            ->where('twoFactor', null),
        );
});

test('enabling and confirming two factor records the security events', function (): void {
    config(['fortify.two_factor_enabled' => true]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'))
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->two_factor_secret)->not->toBeNull();

    $code = app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.confirm'), ['code' => $code])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->two_factor_confirmed_at)->not->toBeNull();

    $types = $user->securityEvents()->pluck('type');

    expect($types)
        ->toContain(SecurityEventType::TwoFactorEnabled)
        ->toContain(SecurityEventType::TwoFactorConfirmed);
});

test('regenerating recovery codes records the security event', function (): void {
    config(['fortify.two_factor_enabled' => true]);

    $user = enrolledTwoFactorUser();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.recovery-codes'))
        ->assertSessionHasNoErrors();

    expect($user->securityEvents()->pluck('type'))
        ->toContain(SecurityEventType::RecoveryCodesGenerated);
});

test('disabling two factor records the security event', function (): void {
    config(['fortify.two_factor_enabled' => true]);

    $user = enrolledTwoFactorUser();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'))
        ->assertSessionHasNoErrors();

    expect($user->refresh()->two_factor_secret)->toBeNull();
    expect($user->securityEvents()->pluck('type'))
        ->toContain(SecurityEventType::TwoFactorDisabled);
});

test('a user with two factor enabled is challenged at login', function (): void {
    config(['fortify.two_factor_enabled' => true]);

    $user = enrolledTwoFactorUser();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->get(route('two-factor.login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('auth/TwoFactorChallenge'));
});

test('a valid TOTP code completes the two factor challenge', function (): void {
    config(['fortify.two_factor_enabled' => true]);

    $user = enrolledTwoFactorUser();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $code = app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));

    $this->post(route('two-factor.login.store'), ['code' => $code])
        ->assertRedirect();

    expect(auth()->check())->toBeTrue();
});

test('the two factor challenge submit is rate limited', function (): void {
    // An attacker holding the password lands on the 2FA-pending session; without
    // a throttle the 6-digit TOTP space is brute-forceable within its validity
    // window, defeating the second factor.
    config(['fortify.two_factor_enabled' => true]);

    $user = enrolledTwoFactorUser();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('two-factor.login.store'), ['code' => '000000'])
            ->assertRedirect();
    }

    $this->post(route('two-factor.login.store'), ['code' => '000000'])
        ->assertTooManyRequests();
});

test('the two factor challenge throttle falls back to the IP without a pending login', function (): void {
    // A direct hit with no 2FA-pending session has no login id to key on; the
    // limiter must still resolve a key rather than fail open.
    config(['fortify.two_factor_enabled' => true]);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('two-factor.login.store'), ['code' => '000000'])
            ->assertRedirect();
    }

    $this->post(route('two-factor.login.store'), ['code' => '000000'])
        ->assertTooManyRequests();
});
