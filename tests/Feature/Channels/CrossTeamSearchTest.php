<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a team owned by a fresh user with its #general channel.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function crossTeamWithGeneral(string $name): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, $name);
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Add an existing user to a team and its #general, returning the channel.
 */
function addToTeam(User $user, Team $team): Channel
{
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();
    $team->memberships()->firstOrCreate(['user_id' => $user->id], ['role' => TeamRole::Member]);
    $general->channelMembers()->firstOrCreate(['user_id' => $user->id]);

    return $general;
}

/**
 * @param  array<string, string>  $params
 */
function crossTeamSearch(User $user, Team $team, array $params): TestResponse
{
    return test()->actingAs($user)->get(route('search', ['team' => $team->slug, ...$params]));
}

test('visibleChannelIdsAcrossTeams unions the channels of every team the user is in', function (): void {
    [$member, $teamA, $generalA] = crossTeamWithGeneral('Acme');
    [, $teamB] = crossTeamWithGeneral('Beta');
    $generalB = addToTeam($member, $teamB);

    $ids = $member->visibleChannelIdsAcrossTeams()->all();

    // The union spans both teams, unlike the team-scoped ACL which never leaves A.
    expect($ids)->toContain($generalA->id, $generalB->id)
        ->and($member->visibleChannelIds($teamA)->all())->not->toContain($generalB->id);
});

test('scope=all searches the union of the user teams', function (): void {
    [$member, $teamA, $generalA] = crossTeamWithGeneral('Acme');
    [$ownerB, $teamB] = crossTeamWithGeneral('Beta');
    $generalB = addToTeam($member, $teamB);
    Message::factory()->for($generalA)->for($member)->create(['body' => 'zephyr in acme']);
    Message::factory()->for($generalB)->for($ownerB)->create(['body' => 'zephyr in beta']);

    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'all'])
        ->assertInertia(fn (Assert $page): Assert => $page->has('results', 2));
});

test('scope=team stays within the current team', function (): void {
    [$member, $teamA, $generalA] = crossTeamWithGeneral('Acme');
    [$ownerB, $teamB] = crossTeamWithGeneral('Beta');
    $generalB = addToTeam($member, $teamB);
    Message::factory()->for($generalA)->for($member)->create(['body' => 'zephyr in acme']);
    Message::factory()->for($generalB)->for($ownerB)->create(['body' => 'zephyr in beta']);

    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'team'])
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('results', 1)
            ->where('results.0.message.body', 'zephyr in acme')
        );
});

test('scope defaults to the current team when omitted', function (): void {
    [$member, $teamA, $generalA] = crossTeamWithGeneral('Acme');
    [$ownerB, $teamB] = crossTeamWithGeneral('Beta');
    $generalB = addToTeam($member, $teamB);
    Message::factory()->for($generalA)->for($member)->create(['body' => 'zephyr in acme']);
    Message::factory()->for($generalB)->for($ownerB)->create(['body' => 'zephyr in beta']);

    crossTeamSearch($member, $teamA, ['q' => 'zephyr'])
        ->assertInertia(fn (Assert $page): Assert => $page->has('results', 1));
});

test('scope=all never surfaces a team the user does not belong to', function (): void {
    [$member, $teamA] = crossTeamWithGeneral('Acme');
    [$ownerB, $teamB, $generalB] = crossTeamWithGeneral('Beta');
    // The member is NOT in team B.
    Message::factory()->for($generalB)->for($ownerB)->create(['body' => 'zephyr in beta']);

    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'all'])
        ->assertInertia(fn (Assert $page): Assert => $page->has('results', 0));
});

test('scope=all never leaks a private channel the user is not in', function (): void {
    [$member, $teamA] = crossTeamWithGeneral('Acme');
    [$ownerB, $teamB] = crossTeamWithGeneral('Beta');
    addToTeam($member, $teamB);
    $privateB = Channel::factory()->for($teamB)->private()->create(['created_by' => $ownerB->id]);
    Message::factory()->for($privateB)->for($ownerB)->create(['body' => 'secret zephyr']);

    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'all'])
        ->assertInertia(fn (Assert $page): Assert => $page->has('results', 0));
});

test('a cross-team result carries its own team for tagging and jump links', function (): void {
    [$member, $teamA] = crossTeamWithGeneral('Acme');
    [$ownerB, $teamB] = crossTeamWithGeneral('Beta');
    $generalB = addToTeam($member, $teamB);
    Message::factory()->for($generalB)->for($ownerB)->create(['body' => 'zephyr in beta']);

    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'all'])
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('results', 1)
            ->where('results.0.teamSlug', $teamB->slug)
            ->where('results.0.teamName', $teamB->name)
            ->where('results.0.teamId', $teamB->id)
        );
});

test('the scope facet echoes back as url state', function (): void {
    [$member, $teamA] = crossTeamWithGeneral('Acme');

    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'all'])
        ->assertInertia(fn (Assert $page): Assert => $page->where('filters.scope', 'all'));
});

test('an invalid scope is rejected', function (): void {
    [$member, $teamA] = crossTeamWithGeneral('Acme');

    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'sideways'])
        ->assertSessionHasErrors('scope');
});
