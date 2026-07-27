<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\NavDestination;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
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
 * Open the Search destination on the team's #general with the given facets.
 *
 * @param  array<string, string>  $params
 */
function crossTeamSearch(User $user, Team $team, array $params): TestResponse
{
    return test()->actingAs($user)->get(route('channels.show', [
        'team' => $team->slug,
        'channel' => Channel::GENERAL_SLUG,
        ...$params,
        // Last, so a facet a caller passes can never steer the destination.
        'nav' => NavDestination::Search->value,
    ]));
}

test('visibleChannelIdsAcrossTeams unions the channels of every team the user is in but never one they are not in', function (): void {
    [$member, $teamA, $generalA] = crossTeamWithGeneral('Acme');
    [$ownerB, $teamB] = crossTeamWithGeneral('Beta');
    $generalB = addToTeam($member, $teamB);
    // A private channel in team B the member is NOT a member of.
    $privateB = Channel::factory()->for($teamB)->private()->create(['created_by' => $ownerB->id]);

    $ids = $member->visibleChannelIdsAcrossTeams()->all();

    // The union spans both teams, excludes the private channel the member is not
    // in, and is strictly wider than the team-scoped ACL, which never leaves A.
    expect($ids)->toContain($generalA->id, $generalB->id)
        ->not->toContain($privateB->id)
        ->and($member->visibleChannelIds($teamA)->all())->not->toContain($generalB->id);
});

test('scope=all searches the union of the user teams', function (): void {
    [$member, $teamA, $generalA] = crossTeamWithGeneral('Acme');
    [$ownerB, $teamB] = crossTeamWithGeneral('Beta');
    $generalB = addToTeam($member, $teamB);
    Message::factory()->for($generalA)->for($member)->create(['body' => 'zephyr in acme']);
    Message::factory()->for($generalB)->for($ownerB)->create(['body' => 'zephyr in beta']);

    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'all'])
        ->assertInertia(fn (Assert $page): Assert => $page->has('searchResults', 2));
});

test('scope=team stays within the current team', function (): void {
    [$member, $teamA, $generalA] = crossTeamWithGeneral('Acme');
    [$ownerB, $teamB] = crossTeamWithGeneral('Beta');
    $generalB = addToTeam($member, $teamB);
    Message::factory()->for($generalA)->for($member)->create(['body' => 'zephyr in acme']);
    Message::factory()->for($generalB)->for($ownerB)->create(['body' => 'zephyr in beta']);

    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'team'])
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('searchResults', 1)
            ->where('searchResults.0.message.body', 'zephyr in acme')
        );
});

test('scope defaults to the current team when omitted', function (): void {
    [$member, $teamA, $generalA] = crossTeamWithGeneral('Acme');
    [$ownerB, $teamB] = crossTeamWithGeneral('Beta');
    $generalB = addToTeam($member, $teamB);
    Message::factory()->for($generalA)->for($member)->create(['body' => 'zephyr in acme']);
    Message::factory()->for($generalB)->for($ownerB)->create(['body' => 'zephyr in beta']);

    crossTeamSearch($member, $teamA, ['q' => 'zephyr'])
        ->assertInertia(fn (Assert $page): Assert => $page->has('searchResults', 1));
});

test('scope=all never surfaces a team the user does not belong to', function (): void {
    [$member, $teamA] = crossTeamWithGeneral('Acme');
    [$ownerB, $teamB, $generalB] = crossTeamWithGeneral('Beta');
    // The member is NOT in team B.
    Message::factory()->for($generalB)->for($ownerB)->create(['body' => 'zephyr in beta']);

    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'all'])
        ->assertInertia(fn (Assert $page): Assert => $page->has('searchResults', 0));
});

test('scope=all never leaks a private channel the user is not in', function (): void {
    [$member, $teamA] = crossTeamWithGeneral('Acme');
    [$ownerB, $teamB] = crossTeamWithGeneral('Beta');
    addToTeam($member, $teamB);
    $privateB = Channel::factory()->for($teamB)->private()->create(['created_by' => $ownerB->id]);
    Message::factory()->for($privateB)->for($ownerB)->create(['body' => 'secret zephyr']);

    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'all'])
        ->assertInertia(fn (Assert $page): Assert => $page->has('searchResults', 0));
});

test('a cross-team result carries its own team for tagging and jump links', function (): void {
    [$member, $teamA] = crossTeamWithGeneral('Acme');
    [$ownerB, $teamB] = crossTeamWithGeneral('Beta');
    $generalB = addToTeam($member, $teamB);
    Message::factory()->for($generalB)->for($ownerB)->create(['body' => 'zephyr in beta']);

    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'all'])
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('searchResults', 1)
            ->where('searchResults.0.teamSlug', $teamB->slug)
            ->where('searchResults.0.teamName', $teamB->name)
            ->where('searchResults.0.teamId', $teamB->id)
        );
});

test('an unknown scope falls back to the current team', function (): void {
    [$member, $teamA, $generalA] = crossTeamWithGeneral('Acme');
    [$ownerB, $teamB] = crossTeamWithGeneral('Beta');
    $generalB = addToTeam($member, $teamB);
    Message::factory()->for($generalA)->for($member)->create(['body' => 'zephyr in acme']);
    Message::factory()->for($generalB)->for($ownerB)->create(['body' => 'zephyr in beta']);

    // The panel's props are resolved off the URL in the shell's shared props, so
    // a bad scope narrows rather than rejecting the whole workspace.
    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'sideways'])
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->has('searchResults', 1));
});

test('the panel exposes the cross-team channel union for the global-mode facet', function (): void {
    [$member, $teamA, $generalA] = crossTeamWithGeneral('Acme');
    [, $teamB] = crossTeamWithGeneral('Beta');
    $generalB = addToTeam($member, $teamB);

    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'all'])
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('searchWorkspaceChannels')
            ->where(
                'searchWorkspaceChannels',
                fn (Collection $channels): bool => $channels
                    ->pluck('id')
                    ->contains($generalA->id)
                    && $channels->pluck('id')->contains($generalB->id)
                    && $channels->contains(
                        fn (array $channel): bool => $channel['teamSlug'] === $teamB->slug,
                    )
            )
        );
});
