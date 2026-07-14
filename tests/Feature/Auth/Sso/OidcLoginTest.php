<?php

use App\Actions\Sso\ProvisionSsoUser;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use GuzzleHttp\Handler\MockHandler;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

function fakeOidcUser(?string $email = 'ada@example.com', string $id = 'sub-1', ?string $name = 'Ada Byte'): SocialiteUser
{
    return (new SocialiteUser)->map([
        'id' => $id,
        'name' => $name,
        'email' => $email,
    ]);
}

test('the redirect route sends the user to the identity provider authorize endpoint', function (): void {
    $mock = new MockHandler([oidcDiscoveryResponse()]);
    config(['sso.oidc.enabled' => true, 'services.oidc' => oidcServicesConfig($mock)]);
    Socialite::forgetDrivers();

    $this->get(route('sso.oidc.redirect'))
        ->assertRedirectContains('https://idp.test/authorize')
        ->assertRedirectContains('client_id=client-id');
});

test('the redirect route is hidden when oidc is not configured', function (): void {
    config(['sso.oidc.enabled' => false]);

    $this->get(route('sso.oidc.redirect'))->assertNotFound();
});

test('the callback route is hidden when oidc is not configured', function (): void {
    config(['sso.oidc.enabled' => false]);

    $this->get(route('sso.oidc.callback'))->assertNotFound();
});

test('a first-time callback just-in-time provisions and logs in the user', function (): void {
    config(['sso.oidc.enabled' => true, 'services.oidc.issuer' => 'https://idp.test']);
    $team = Team::factory()->create();
    Socialite::fake('oidc', fakeOidcUser());

    $response = $this->get(route('sso.oidc.callback'));

    $this->assertAuthenticated();
    $response->assertRedirect();

    $user = auth()->user();
    expect($user->email)->toBe('ada@example.com')
        ->and($user->teamRole($team))->toBe(TeamRole::Member);

    $this->assertDatabaseHas('sso_identities', [
        'provider' => 'oidc:https://idp.test',
        'provider_id' => 'sub-1',
        'user_id' => $user->id,
    ]);
});

test('a callback for an existing email links that account rather than duplicating it', function (): void {
    config(['sso.oidc.enabled' => true, 'services.oidc.issuer' => 'https://idp.test']);
    $existing = User::factory()->create(['email' => 'ada@example.com']);
    Socialite::fake('oidc', fakeOidcUser());

    $this->get(route('sso.oidc.callback'));

    expect(auth()->id())->toBe($existing->id)
        ->and(User::query()->where('email', 'ada@example.com')->count())->toBe(1);

    $this->assertDatabaseHas('sso_identities', [
        'provider' => 'oidc:https://idp.test',
        'provider_id' => 'sub-1',
        'user_id' => $existing->id,
    ]);
});

test('identities are namespaced by issuer so the same subject at two issuers never collides', function (): void {
    config(['sso.oidc.enabled' => true, 'services.oidc.issuer' => 'https://idp-a.test']);
    Socialite::fake('oidc', fakeOidcUser(email: 'alice@example.com', id: 'shared-sub'));
    $this->get(route('sso.oidc.callback'));
    $alice = auth()->user();

    // The trailing slash is normalised away, so it can never mint a second
    // identity for what is really the same configured issuer.
    config(['services.oidc.issuer' => 'https://idp-b.test/']);
    Socialite::fake('oidc', fakeOidcUser(email: 'bob@example.com', id: 'shared-sub'));
    $this->get(route('sso.oidc.callback'));
    $bob = auth()->user();

    expect($bob->id)->not->toBe($alice->id)
        ->and(User::query()->count())->toBe(2);

    $this->assertDatabaseHas('sso_identities', [
        'provider' => 'oidc:https://idp-a.test',
        'provider_id' => 'shared-sub',
        'user_id' => $alice->id,
    ]);
    $this->assertDatabaseHas('sso_identities', [
        'provider' => 'oidc:https://idp-b.test',
        'provider_id' => 'shared-sub',
        'user_id' => $bob->id,
    ]);
});

test('a callback with no email address fails gracefully back to login', function (): void {
    config(['sso.oidc.enabled' => true]);
    Socialite::fake('oidc', fakeOidcUser(email: null));

    $response = $this->get(route('sso.oidc.callback'));

    $this->assertGuest();
    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status');
});

test('a callback with no stable subject fails gracefully back to login', function (): void {
    config(['sso.oidc.enabled' => true]);
    Socialite::fake('oidc', fakeOidcUser(id: ''));

    $response = $this->get(route('sso.oidc.callback'));

    $this->assertGuest();
    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status');
    expect(User::query()->count())->toBe(0);
});

test('a callback that errors or is denied fails gracefully back to login', function (): void {
    config(['sso.oidc.enabled' => true]);

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andThrow(new InvalidStateException);
    Socialite::shouldReceive('driver')->with('oidc')->andReturn($provider);

    $response = $this->get(route('sso.oidc.callback'));

    $this->assertGuest();
    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status');
});

test('a provisioning failure fails gracefully back to login', function (): void {
    config(['sso.oidc.enabled' => true]);
    Socialite::fake('oidc', fakeOidcUser());

    $this->mock(ProvisionSsoUser::class)
        ->shouldReceive('handle')
        ->once()
        ->andThrow(new RuntimeException('provisioning blew up'));

    $response = $this->get(route('sso.oidc.callback'));

    $this->assertGuest();
    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status');
});
