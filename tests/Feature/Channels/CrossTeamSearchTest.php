<?php

use App\Enums\NavDestination;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

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

test('memberChannelIdsAcrossTeams unions the channels of every team the user is in but never one they are not in', function (): void {
    ['owner' => $member, 'team' => $teamA, 'channel' => $generalA] = teamWithChannel('Acme');
    ['owner' => $ownerB, 'team' => $teamB, 'channel' => $generalB] = teamWithChannel('Beta');
    joinTeamAndChannel($generalB, $member);
    // A private channel in team B the member is NOT a member of.
    $privateB = Channel::factory()->for($teamB)->private()->create(['created_by' => $ownerB->id]);

    $ids = $member->memberChannelIdsAcrossTeams()->all();

    // The union spans both teams, excludes the private channel the member is not
    // in, and is strictly wider than the team-scoped ACL, which never leaves A.
    expect($ids)->toContain($generalA->id, $generalB->id)
        ->not->toContain($privateB->id)
        ->and($member->memberChannelIds($teamA)->all())->not->toContain($generalB->id);
});

test('scope=all searches the union of the user teams', function (): void {
    ['owner' => $member, 'team' => $teamA, 'channel' => $generalA] = teamWithChannel('Acme');
    ['owner' => $ownerB, 'team' => $teamB, 'channel' => $generalB] = teamWithChannel('Beta');
    joinTeamAndChannel($generalB, $member);
    Message::factory()->for($generalA)->for($member)->create(['body' => 'zephyr in acme']);
    Message::factory()->for($generalB)->for($ownerB)->create(['body' => 'zephyr in beta']);

    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'all'])
        ->assertInertia(fn (Assert $page): Assert => $page->has('searchResults', 2));
});

test('scope=team stays within the current team', function (): void {
    ['owner' => $member, 'team' => $teamA, 'channel' => $generalA] = teamWithChannel('Acme');
    ['owner' => $ownerB, 'team' => $teamB, 'channel' => $generalB] = teamWithChannel('Beta');
    joinTeamAndChannel($generalB, $member);
    Message::factory()->for($generalA)->for($member)->create(['body' => 'zephyr in acme']);
    Message::factory()->for($generalB)->for($ownerB)->create(['body' => 'zephyr in beta']);

    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'team'])
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('searchResults', 1)
            ->where('searchResults.0.message.body', 'zephyr in acme')
        );
});

test('scope defaults to the current team when omitted', function (): void {
    ['owner' => $member, 'team' => $teamA, 'channel' => $generalA] = teamWithChannel('Acme');
    ['owner' => $ownerB, 'team' => $teamB, 'channel' => $generalB] = teamWithChannel('Beta');
    joinTeamAndChannel($generalB, $member);
    Message::factory()->for($generalA)->for($member)->create(['body' => 'zephyr in acme']);
    Message::factory()->for($generalB)->for($ownerB)->create(['body' => 'zephyr in beta']);

    crossTeamSearch($member, $teamA, ['q' => 'zephyr'])
        ->assertInertia(fn (Assert $page): Assert => $page->has('searchResults', 1));
});

test('scope=all never surfaces a team the user does not belong to', function (): void {
    ['owner' => $member, 'team' => $teamA] = teamWithChannel('Acme');
    ['owner' => $ownerB, 'channel' => $generalB] = teamWithChannel('Beta');
    // The member is NOT in team B.
    Message::factory()->for($generalB)->for($ownerB)->create(['body' => 'zephyr in beta']);

    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'all'])
        ->assertInertia(fn (Assert $page): Assert => $page->has('searchResults', 0));
});

test('scope=all never leaks a private channel the user is not in', function (): void {
    ['owner' => $member, 'team' => $teamA] = teamWithChannel('Acme');
    ['owner' => $ownerB, 'team' => $teamB, 'channel' => $generalB] = teamWithChannel('Beta');
    joinTeamAndChannel($generalB, $member);
    $privateB = Channel::factory()->for($teamB)->private()->create(['created_by' => $ownerB->id]);
    Message::factory()->for($privateB)->for($ownerB)->create(['body' => 'secret zephyr']);

    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'all'])
        ->assertInertia(fn (Assert $page): Assert => $page->has('searchResults', 0));
});

test('a cross-team result carries its own team for tagging and jump links', function (): void {
    ['owner' => $member, 'team' => $teamA] = teamWithChannel('Acme');
    ['owner' => $ownerB, 'team' => $teamB, 'channel' => $generalB] = teamWithChannel('Beta');
    joinTeamAndChannel($generalB, $member);
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
    ['owner' => $member, 'team' => $teamA, 'channel' => $generalA] = teamWithChannel('Acme');
    ['owner' => $ownerB, 'team' => $teamB, 'channel' => $generalB] = teamWithChannel('Beta');
    joinTeamAndChannel($generalB, $member);
    Message::factory()->for($generalA)->for($member)->create(['body' => 'zephyr in acme']);
    Message::factory()->for($generalB)->for($ownerB)->create(['body' => 'zephyr in beta']);

    // The panel's props are resolved off the URL in the shell's shared props, so
    // a bad scope narrows rather than rejecting the whole workspace.
    crossTeamSearch($member, $teamA, ['q' => 'zephyr', 'scope' => 'sideways'])
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->has('searchResults', 1));
});

test('the panel exposes the cross-team channel union for the global-mode facet', function (): void {
    ['owner' => $member, 'team' => $teamA, 'channel' => $generalA] = teamWithChannel('Acme');
    ['team' => $teamB, 'channel' => $generalB] = teamWithChannel('Beta');
    joinTeamAndChannel($generalB, $member);

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
