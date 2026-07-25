<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse;

/**
 * The response the passkey login controller hands back once the WebAuthn
 * ceremony has verified the credential and signed the user in. The ceremony
 * itself belongs to laravel/passkeys; what the app owns is where it sends
 * someone afterwards, so that is the seam under test.
 */
function passkeyLoginResponse(User $user, bool $wantsJson = false): TestResponse
{
    $request = Request::create(route('passkey.login'), 'POST', server: $wantsJson ? ['HTTP_ACCEPT' => 'application/json'] : []);

    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn (): User => $user);

    return TestResponse::fromBaseResponse(app(PasskeyLoginResponse::class)->toResponse($request));
}

test('passkey login lands on the current team workspace', function (): void {
    // The vendor response reads `passkeys.redirect`, a static string Fortify
    // fills from `fortify.home` — the public marketing page.
    $user = User::factory()->create();

    passkeyLoginResponse($user)->assertRedirect(
        route('channels.index', ['team' => $user->currentTeam->slug]),
    );
});

test('passkey login answers the login screen with the workspace URL', function (): void {
    // The login screen drives the ceremony over JSON and navigates to whatever
    // `redirect` comes back, so this is the branch a real browser takes.
    $user = User::factory()->create();

    passkeyLoginResponse($user, wantsJson: true)
        ->assertOk()
        ->assertJson(['redirect' => route('channels.index', ['team' => $user->currentTeam->slug])]);
});

test('passkey login honours an intended URL the user can reach', function (): void {
    $user = User::factory()->create();

    $intended = route('profile.edit');
    session(['url.intended' => $intended]);

    passkeyLoginResponse($user)->assertRedirect($intended);
});

test('passkey login drops an intended URL the user cannot reach', function (): void {
    $user = User::factory()->create();

    session(['url.intended' => url('/t/does-not-exist')]);

    passkeyLoginResponse($user)->assertRedirect(
        route('channels.index', ['team' => $user->currentTeam->slug]),
    );
});
