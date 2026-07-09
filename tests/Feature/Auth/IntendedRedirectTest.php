<?php

use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Log the user in and return the resulting redirect response, seeding the given
 * URL as the guest-stored "intended" target when provided.
 */
function loginWithIntended(User $user, ?string $intended = null): TestResponse
{
    $session = $intended === null ? [] : ['url.intended' => $intended];

    return test()->withSession($session)->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);
}

test('login with no intended URL lands on the current team general channel', function () {
    $user = User::factory()->create();

    loginWithIntended($user)->assertRedirect(
        route('channels.index', ['team' => $user->currentTeam->slug]),
    );
});

test('login honours an intended URL the user can view', function () {
    $user = User::factory()->create();
    $channel = Channel::factory()->create([
        'team_id' => $user->currentTeam->id,
        'slug' => 'random',
    ]);

    $intended = route('channels.show', ['team' => $user->currentTeam->slug, 'channel' => $channel->slug]);

    loginWithIntended($user, $intended)->assertRedirect($intended);
});

test('login honours an intended URL pointing at a team the user belongs to', function () {
    $user = User::factory()->create();

    $intended = route('channels.index', ['team' => $user->currentTeam->slug]);

    loginWithIntended($user, $intended)->assertRedirect($intended);
});

test('login honours a non-workspace intended URL as-is', function () {
    $user = User::factory()->create();

    $intended = route('profile.edit');

    loginWithIntended($user, $intended)->assertRedirect($intended);
});

test('login falls back when the intended channel no longer exists in the team', function () {
    $user = User::factory()->create();

    $intended = route('channels.show', ['team' => $user->currentTeam->slug, 'channel' => 'random']);

    loginWithIntended($user, $intended)->assertRedirect(
        route('channels.index', ['team' => $user->currentTeam->slug]),
    );
});

test('login falls back when the intended team does not exist', function () {
    $user = User::factory()->create();

    loginWithIntended($user, url('/t/does-not-exist'))->assertRedirect(
        route('channels.index', ['team' => $user->currentTeam->slug]),
    );
});

test('login falls back when the intended team is one the user does not belong to', function () {
    $user = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherOwner = User::factory()->create();
    $otherTeam->members()->attach($otherOwner, ['role' => TeamRole::Owner->value]);

    $intended = route('channels.index', ['team' => $otherTeam->slug]);

    loginWithIntended($user, $intended)->assertRedirect(
        route('channels.index', ['team' => $user->currentTeam->slug]),
    );
});

test('login falls back when the intended URL has no path', function () {
    $user = User::factory()->create();

    loginWithIntended($user, 'http://localhost')->assertRedirect(
        route('channels.index', ['team' => $user->currentTeam->slug]),
    );
});
