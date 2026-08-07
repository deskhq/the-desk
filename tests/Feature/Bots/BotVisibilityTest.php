<?php

declare(strict_types=1);

use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Add a bot to a team's #general channel and return [$owner, $team, $bot].
 *
 * @return array{0: User, 1: Team, 2: User}
 */
function botInGeneral(): array
{
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $owner->update(['name' => 'Zoe Owner']);

    $bot = User::factory()->bot($team)->create(['name' => 'Deploy Bot']);
    channelMembership($general, $bot);

    return [$owner, $team, $bot];
}

test('a bot is shipped to the channel roster, badged', function (): void {
    [$owner, $team] = botInGeneral();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        // A bot is a channel member and not a team member, so it is the one part
        // of the roster the visit ships rather than the client composing it from
        // the workspace roster (#1254). That it lands after the humans, badged
        // and squared, is the composition's claim — see
        // `resources/js/lib/channelRoster.test.ts`.
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('botMembers', 1)
            ->where('botMembers.0.name', 'Deploy Bot')
            ->where('botMembers.0.isBot', true)
        );
});

test('a bot is excluded from the channel join member count', function (): void {
    [$owner, $team] = botInGeneral();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        // Only the human owner is a seat; the bot member does not count.
        ->assertInertia(fn (Assert $page): Assert => $page->where('memberCount', 1));
});

// A bot's exclusion from the roster shared to the sidebar and DM picker is
// stated on that roster itself, in tests/Integration/Support/WorkspaceShellTest.php.

test('a bot is excluded from the team settings member list and seat count', function (): void {
    [$owner, $team, $bot] = botInGeneral();

    expect($team->members()->count())->toBe(1);

    $this->actingAs($owner)
        ->get(route('teams.edit', ['team' => $team->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('members', fn (Collection $members): bool => $members
                ->doesntContain(fn (array $member): bool => $member['id'] === $bot->id))
        );
});
