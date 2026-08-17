<?php

declare(strict_types=1);

use App\Actions\Sso\ProvisionSsoUser;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\SsoIdentity;
use App\Models\Team;
use App\Models\User;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * A Socialite user shaped like the generic OIDC provider's output: mapped
 * attributes plus the raw UserInfo claims. $emailVerified lands in the raw
 * `email_verified` claim in whatever form it is given (bool or the string
 * forms some IdPs emit); null omits the claim, as many conformant IdPs do.
 */
function fakeOidcUser(?string $email = 'ada@example.com', string $id = 'sub-1', ?string $name = 'Ada Byte', bool|string|null $emailVerified = null): SocialiteUser
{
    $claims = ['sub' => $id, 'name' => $name, 'email' => $email];

    if ($emailVerified !== null) {
        $claims['email_verified'] = $emailVerified;
    }

    return (new SocialiteUser)->setRaw($claims)->map([
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

test('the redirect route parks the "keep me signed in" choice for the callback', function (string $submitted, bool $parked): void {
    $mock = new MockHandler([oidcDiscoveryResponse()]);
    config(['sso.oidc.enabled' => true, 'services.oidc' => oidcServicesConfig($mock)]);
    Socialite::forgetDrivers();

    $this->get(route('sso.oidc.redirect', ['remember' => $submitted]))->assertSessionHas('sso.remember');

    // Asserted strictly: `assertSessionHas` compares loosely, so an absent key
    // would satisfy an expected `false` without the flag ever being parked.
    expect(session()->get('sso.remember'))->toBe($parked);
})->with(['on' => ['1', true], 'off' => ['0', false]]);

test('the redirect route parks the choice as off when the login screen sends nothing', function (): void {
    $mock = new MockHandler([oidcDiscoveryResponse()]);
    config(['sso.oidc.enabled' => true, 'services.oidc' => oidcServicesConfig($mock)]);
    Socialite::forgetDrivers();

    $this->get(route('sso.oidc.redirect'))->assertSessionHas('sso.remember');

    expect(session()->get('sso.remember'))->toBeFalse();
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

test('an explicitly unverified email is rejected and the existing account is not taken over', function (): void {
    config(['sso.oidc.enabled' => true, 'services.oidc.issuer' => 'https://idp.test']);
    User::factory()->create(['email' => 'ada@example.com']);
    Socialite::fake('oidc', fakeOidcUser(emailVerified: false));

    $response = $this->get(route('sso.oidc.callback'));

    $this->assertGuest();
    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status');
    $this->assertDatabaseCount('sso_identities', 0);
});

test('an explicitly unverified email never just-in-time provisions an account', function (): void {
    config(['sso.oidc.enabled' => true, 'services.oidc.issuer' => 'https://idp.test']);
    Team::factory()->create();
    Socialite::fake('oidc', fakeOidcUser(emailVerified: false));

    $response = $this->get(route('sso.oidc.callback'));

    $this->assertGuest();
    $response->assertRedirect(route('login'));
    expect(User::query()->count())->toBe(0);
});

test('a verified email links and signs in as before', function (bool|string $claim): void {
    config(['sso.oidc.enabled' => true, 'services.oidc.issuer' => 'https://idp.test']);
    $existing = User::factory()->create(['email' => 'ada@example.com']);
    Socialite::fake('oidc', fakeOidcUser(emailVerified: $claim));

    $this->get(route('sso.oidc.callback'));

    expect(auth()->id())->toBe($existing->id);
})->with(['boolean true' => true, 'string "true"' => 'true']);

test('a string "false" email_verified claim is coerced and rejected', function (): void {
    config(['sso.oidc.enabled' => true, 'services.oidc.issuer' => 'https://idp.test']);
    User::factory()->create(['email' => 'ada@example.com']);
    Socialite::fake('oidc', fakeOidcUser(emailVerified: 'false'));

    $response = $this->get(route('sso.oidc.callback'));

    $this->assertGuest();
    $response->assertRedirect(route('login'));
    $this->assertDatabaseCount('sso_identities', 0);
});

test('an absent email_verified claim is accepted by default', function (): void {
    config(['sso.oidc.enabled' => true, 'services.oidc.issuer' => 'https://idp.test']);
    $existing = User::factory()->create(['email' => 'ada@example.com']);
    Socialite::fake('oidc', fakeOidcUser());

    $this->get(route('sso.oidc.callback'));

    expect(auth()->id())->toBe($existing->id);
});

test('an absent email_verified claim is rejected when a verified email is required', function (): void {
    config(['sso.oidc.enabled' => true, 'services.oidc.issuer' => 'https://idp.test', 'sso.oidc.require_verified_email' => true]);
    Team::factory()->create();
    Socialite::fake('oidc', fakeOidcUser());

    $response = $this->get(route('sso.oidc.callback'));

    $this->assertGuest();
    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status');
    expect(User::query()->count())->toBe(0);
});

test('an explicitly verified email still signs in when a verified email is required', function (): void {
    config(['sso.oidc.enabled' => true, 'services.oidc.issuer' => 'https://idp.test', 'sso.oidc.require_verified_email' => true]);
    Team::factory()->create();
    Socialite::fake('oidc', fakeOidcUser(emailVerified: true));

    $this->get(route('sso.oidc.callback'));

    $this->assertAuthenticated();
});

test('a returning linked identity still signs in when the directory reports the email unverified', function (): void {
    config(['sso.oidc.enabled' => true, 'services.oidc.issuer' => 'https://idp.test']);
    $existing = User::factory()->create();
    SsoIdentity::factory()->provider('oidc:https://idp.test')->for($existing)->create(['provider_id' => 'sub-1']);
    Socialite::fake('oidc', fakeOidcUser(emailVerified: false));

    $this->get(route('sso.oidc.callback'));

    expect(auth()->id())->toBe($existing->id);
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

test('a successful callback lands on the current team workspace', function (): void {
    // Single sign-on has to arrive where a password login arrives; resolving its
    // own destination is how it ended up on the public marketing page instead.
    config(['sso.oidc.enabled' => true, 'services.oidc.issuer' => 'https://idp.test']);
    $existing = User::factory()->create(['email' => 'ada@example.com']);
    Socialite::fake('oidc', fakeOidcUser());

    $this->get(route('sso.oidc.callback'))->assertRedirect(
        route('channels.index', ['team' => $existing->currentTeam->slug]),
    );
});

test('a successful callback honours a reachable intended URL', function (): void {
    config(['sso.oidc.enabled' => true, 'services.oidc.issuer' => 'https://idp.test']);
    $existing = User::factory()->create(['email' => 'ada@example.com']);
    Socialite::fake('oidc', fakeOidcUser());

    // A channel inside the team, so honouring the intended URL is distinguishable
    // from simply falling back to the workspace.
    $channel = Channel::factory()->create(['team_id' => $existing->currentTeam->id, 'slug' => 'random']);

    $intended = route('channels.show', ['team' => $existing->currentTeam->slug, 'channel' => $channel->slug]);

    $this->withSession(['url.intended' => $intended])
        ->get(route('sso.oidc.callback'))
        ->assertRedirect($intended);
});

test('a successful callback drops an intended URL the user cannot reach', function (): void {
    config(['sso.oidc.enabled' => true, 'services.oidc.issuer' => 'https://idp.test']);
    $existing = User::factory()->create(['email' => 'ada@example.com']);
    Socialite::fake('oidc', fakeOidcUser());

    $this->withSession(['url.intended' => url('/t/does-not-exist')])
        ->get(route('sso.oidc.callback'))
        ->assertRedirect(route('channels.index', ['team' => $existing->currentTeam->slug]));
});

test('a callback with "keep me signed in" parked queues the long-lived recaller cookie', function (): void {
    config(['sso.oidc.enabled' => true, 'services.oidc.issuer' => 'https://idp.test']);
    User::factory()->create(['email' => 'ada@example.com']);
    Socialite::fake('oidc', fakeOidcUser());

    $response = $this->withSession(['sso.remember' => true])->get(route('sso.oidc.callback'));

    $this->assertAuthenticated();

    /** @var SessionGuard $guard */
    $guard = Auth::guard('web');
    $recaller = $response->getCookie($guard->getRecallerName(), decrypt: false);

    expect($recaller)->not->toBeNull()
        // The recaller is what outlives session expiry, so it has to be dated
        // well past any session lifetime — Laravel's own default is 400 days.
        ->and($recaller->getExpiresTime())->toBeGreaterThan(now()->addYear()->getTimestamp());
});

test('a callback without "keep me signed in" parked queues no recaller cookie', function (): void {
    config(['sso.oidc.enabled' => true, 'services.oidc.issuer' => 'https://idp.test']);
    User::factory()->create(['email' => 'ada@example.com']);
    Socialite::fake('oidc', fakeOidcUser());

    $response = $this->withSession(['sso.remember' => false])->get(route('sso.oidc.callback'));

    $this->assertAuthenticated();

    /** @var SessionGuard $guard */
    $guard = Auth::guard('web');

    expect($response->getCookie($guard->getRecallerName(), decrypt: false))->toBeNull();
});

test('the callback ignores a "keep me signed in" flag on its own query string', function (): void {
    // The flag is only ever read back from the session, so appending it to the
    // callback URL cannot buy an attacker a persistent sign-in from outside the
    // flow.
    config(['sso.oidc.enabled' => true, 'services.oidc.issuer' => 'https://idp.test']);
    User::factory()->create(['email' => 'ada@example.com']);
    Socialite::fake('oidc', fakeOidcUser());

    $response = $this->get(route('sso.oidc.callback', ['remember' => 1]));

    $this->assertAuthenticated();

    /** @var SessionGuard $guard */
    $guard = Auth::guard('web');

    expect($response->getCookie($guard->getRecallerName(), decrypt: false))->toBeNull();
});

test('a bounced callback still consumes the parked flag', function (): void {
    // Otherwise a failed attempt would leave it behind to quietly persist the
    // next sign-in, however that one was started.
    config(['sso.oidc.enabled' => true]);
    Socialite::fake('oidc', fakeOidcUser(email: null));

    $this->withSession(['sso.remember' => true])
        ->get(route('sso.oidc.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionMissing('sso.remember');
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
