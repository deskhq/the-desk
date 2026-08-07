<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\ChannelType;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| A channel visit serialises the roster once (#1254)
|--------------------------------------------------------------------------
|
| The team's people ride the shell's `teamMembers`; the page adds only what that
| roster cannot hold — the channel's own bots, which are not team members — and
| a direct message adds nothing at all, its participants already riding the
| `channel` prop. The composition back into one roster is the client's, stated
| in `resources/js/lib/channelRoster.test.ts`.
|
*/

/**
 * A team whose #general has a bot member, plus a second human.
 *
 * @return array{User, Team, Channel, User}
 */
function teamWithBotInGeneral(): array
{
    $owner = User::factory()->create(['name' => 'Zoe Owner']);
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::query()->where('team_id', $team->id)->where('slug', Channel::GENERAL_SLUG)->firstOrFail();

    $member = User::factory()->create(['name' => 'Amy Member']);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $bot = User::factory()->bot($team)->create(['name' => 'Deploy Bot']);
    channelMembership($general, $bot);

    return [$owner, $team, $general, $bot];
}

test('a standard channel adds only the members the shared roster cannot hold', function (): void {
    [$owner, $team, $general, $bot] = teamWithBotInGeneral();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('botMembers', 1)
            ->where('botMembers.0.id', $bot->id)
            ->where('botMembers.0.name', 'Deploy Bot')
            ->where('botMembers.0.isBot', true)
        );
});

test('no member is serialised twice in a channel visit', function (): void {
    [$owner, $team, $general] = teamWithBotInGeneral();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(function (Assert $page): void {
            $props = $page->toArray()['props'];

            $shared = collect($props['teamMembers'])->pluck('id');
            $delta = collect($props['botMembers'])->pluck('id');

            expect($shared)->not->toBeEmpty()
                ->and($delta)->not->toBeEmpty()
                ->and($delta->intersect($shared))->toBeEmpty();
        });
});

test('a channel with no bots adds nothing to the shared roster', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    teamMemberInChannel($general, ['name' => 'Amy Member']);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page->where('botMembers', []));
});

test('a direct message adds no roster, its participants riding the channel prop', function (): void {
    $owner = User::factory()->create(['name' => 'Zoe Owner']);
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = User::factory()->create(['name' => 'Amy Member']);
    $team->memberships()->create(['user_id' => $other->id, 'role' => TeamRole::Member]);

    $this->actingAs($owner)->post(route('channels.dm.store', ['team' => $team->slug]), ['user_id' => $other->id]);
    $dm = Channel::where('team_id', $team->id)->where('type', ChannelType::Direct)->firstOrFail();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $dm->slug]))
        // The counterpart the client composes the conversation's roster from is
        // already on the `channel` prop — see MentionMembersPropTest.
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('botMembers', [])
            ->has('channel.dmParticipants', 1)
        );
});
