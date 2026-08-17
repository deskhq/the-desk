<?php

declare(strict_types=1);

use App\Enums\PostRegistrationPrompt;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Register a brand-new account through the real Fortify endpoint and return it,
 * so each test observes the session the registering request actually left behind.
 */
function registerAccount(): User
{
    test()->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    return User::where('email', 'test@example.com')->sole();
}

test('registering queues the passkey prompt and confirms the password for the session', function (): void {
    config(['fortify.passkeys_enabled' => true]);

    registerAccount();

    $this->assertAuthenticated();

    expect(session(PostRegistrationPrompt::SESSION_KEY))
        ->toBe(PostRegistrationPrompt::Passkey->value);
    // Passkey enrolment sits behind RequirePassword, and Fortify only ever
    // stamps this in ConfirmablePasswordController — without it the prompt would
    // bounce the user to re-type the password they chose seconds earlier.
    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

test('the shared prop offers the prompt while passkeys are available', function (): void {
    config(['fortify.passkeys_enabled' => true]);

    $user = registerAccount();

    $this->get(workspaceUrl($user->currentTeam))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('postRegistrationPrompt', PostRegistrationPrompt::Passkey->value));
});

test('the shared props name the device the request came from', function (): void {
    config(['fortify.passkeys_enabled' => true]);

    $user = registerAccount();

    // The prompt prefills its name field with this, so the passkey the user keeps
    // is named after the device they enrolled it on.
    $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/140.0.0.0')
        ->get(workspaceUrl($user->currentTeam))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('currentDevice.browser', 'Chrome')
            ->where('currentDevice.platform', 'macOS'));
});

test('the shared prop withholds the prompt when passkeys are switched off', function (): void {
    config(['fortify.passkeys_enabled' => true]);

    $user = registerAccount();

    config(['fortify.passkeys_enabled' => false]);

    $this->get(workspaceUrl($user->currentTeam))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('postRegistrationPrompt', null));
});

test('the shared prop withholds the prompt under enforced single sign-on', function (): void {
    config(['fortify.passkeys_enabled' => true]);

    $user = registerAccount();

    config(['sso.enforced' => true]);

    $this->get(workspaceUrl($user->currentTeam))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('postRegistrationPrompt', null));
});

test('the shared prop ignores a session value that is not a known prompt', function (): void {
    config(['fortify.passkeys_enabled' => true]);

    $user = User::factory()->create();

    // A key left behind by an older release, or a tampered-with session: neither an
    // unknown name nor a non-string value may reach the frontend as a prompt.
    foreach (['no-such-prompt', ['passkey'], 42] as $queued) {
        $this->actingAs($user)
            ->withSession([PostRegistrationPrompt::SESSION_KEY => $queued])
            ->get(workspaceUrl($user->currentTeam))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('postRegistrationPrompt', null));
    }
});

test('a user who simply signs in is never prompted', function (): void {
    config(['fortify.passkeys_enabled' => true]);

    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->get(workspaceUrl($user->currentTeam))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('postRegistrationPrompt', null));
});

test('dismissing the prompt clears the queued key and is idempotent', function (): void {
    config(['fortify.passkeys_enabled' => true]);

    registerAccount();

    $this->delete(route('post-registration-prompt.destroy'))->assertRedirect();

    expect(session()->has(PostRegistrationPrompt::SESSION_KEY))->toBeFalse();

    // Clearing an already-cleared key is a no-op, so a double dismissal (two
    // tabs, a retried request) is harmless.
    $this->delete(route('post-registration-prompt.destroy'))->assertRedirect();

    expect(session()->has(PostRegistrationPrompt::SESSION_KEY))->toBeFalse();
});

test('dismissing the prompt requires an authenticated user', function (): void {
    $this->delete(route('post-registration-prompt.destroy'))
        ->assertRedirect(route('login'));
});
