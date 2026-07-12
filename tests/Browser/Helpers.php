<?php

declare(strict_types=1);

use App\Actions\Channels\JoinChannel;
use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel;
use Pest\Browser\Api\AwaitableWebpage;
use Tests\Browser\Support\ForgetGuardsPerRequest;

/**
 * Point broadcasting at a real Reverb server for the duration of a browser test.
 *
 * The browser plugin serves the app in-process (see
 * Pest\Browser\Drivers\LaravelHttpServer), so this runtime config override
 * reaches every request the browser makes. phpunit.xml forces
 * BROADCAST_CONNECTION=null for the headless suites; here we flip it to `reverb`
 * so `ShouldBroadcast` events actually reach the second client over WebSockets.
 *
 * The browser-facing (`public_*`) host/port/scheme are derived from the same
 * server-facing REVERB_* connection the PHP process publishes to. Browser and
 * server are co-located (same Sail container locally, same runner in CI), so a
 * single host reaches Reverb from both — `reverb:8080` under Sail, `127.0.0.1:8080`
 * in CI — with no separate public endpoint to configure.
 */
function useReverbForBrowserTests(): void
{
    $options = config('broadcasting.connections.reverb.options');

    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.public_host' => $options['host'] ?? '127.0.0.1',
        'broadcasting.connections.reverb.public_port' => $options['port'] ?? 8080,
        'broadcasting.connections.reverb.public_scheme' => $options['scheme'] ?? 'http',
    ]);

    // The app boots with BROADCAST_CONNECTION=null (phpunit.xml), so channels.php
    // registered its authorizations on the null broadcaster. Re-require it now that
    // `reverb` is the default connection, so every private/presence subscription's
    // authorization callback attaches to the broadcaster the browser actually uses —
    // otherwise `/broadcasting/auth` denies every channel before any callback runs.
    require base_path('routes/channels.php');

    // Force each request to re-resolve auth from its own session, so two browser
    // contexts can act as two users despite the shared long-lived test server.
    // Registered as global middleware (deduped) on the *singleton* kernel the
    // in-process server resolves (via the contract), so it runs ahead of the
    // session guard's first resolution on every request.
    $kernel = app(HttpKernelContract::class);

    if ($kernel instanceof Kernel && ! $kernel->hasMiddleware(ForgetGuardsPerRequest::class)) {
        $kernel->prependMiddleware(ForgetGuardsPerRequest::class);
    }
}

/**
 * A team, two members, and the team's #general channel that both belong to.
 *
 * Both users are real channel members so each is authorized to subscribe to the
 * channel's realtime broadcasts (see routes/channels.php). #general is the
 * channel both land on straight after login, so tests never need a full-page
 * `navigate()` (which drops the browser session under the in-process test
 * server) to reach a shared room.
 *
 * @return array{owner: User, member: User, team: Team, channel: Channel}
 */
function browserTeamWithChannel(): array
{
    $owner = User::factory()->create(['name' => 'Alice Owner']);
    $member = User::factory()->create(['name' => 'Bob Member']);

    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $channel = Channel::where('team_id', $team->id)
        ->where('slug', Channel::GENERAL_SLUG)
        ->firstOrFail();

    // The creator auto-joins #general; the second member must be joined explicitly
    // so they are authorized to subscribe to its realtime channel.
    app(JoinChannel::class)->handle($channel, $member);

    // Every factory user gets their own personal team as their current team, so
    // point the second member at the shared team — otherwise they land on their
    // personal #general after login instead of the room the owner is in.
    $member->update(['current_team_id' => $team->id]);

    return ['owner' => $owner, 'member' => $member, 'team' => $team, 'channel' => $channel];
}

/**
 * The in-app URL for a channel's message timeline.
 */
function browserChannelUrl(Team $team, Channel $channel): string
{
    return "/t/{$team->slug}/c/{$channel->slug}";
}

/**
 * Sign a user in through the real login form, returning the page so the caller
 * can continue driving it. Each `visit()` gets its own browser context (isolated
 * cookie jar), so two calls yield two independently authenticated clients.
 */
function signInThroughBrowser(User $user, string $password = 'password'): AwaitableWebpage
{
    return visit('/login')
        ->type('#email', $user->email)
        ->type('#password', $password)
        ->click('@login-button')
        // Wait for the post-login redirect to settle before the caller navigates,
        // otherwise a follow-up visit can race the session cookie and bounce back
        // to /login. assertPathIsNot retries within the browser timeout.
        ->assertPathIsNot('/login');
}
