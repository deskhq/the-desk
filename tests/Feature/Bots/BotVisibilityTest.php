<?php

declare(strict_types=1);

use App\Actions\Teams\CreateTeam;
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
    $owner = User::factory()->create(['name' => 'Zoe Owner']);
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $bot = User::factory()->bot($team)->create(['name' => 'Deploy Bot']);

    $general = $team->channels()->where('slug', Channel::GENERAL_SLUG)->firstOrFail();
    $general->channelMembers()->create(['user_id' => $bot->id]);

    return [$owner, $team, $bot];
}

test('a bot appears in the channel member roster, badged, after the humans', function (): void {
    [$owner, $team] = botInGeneral();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('members', 2)
            // Humans first (by name), the channel's bot appended and flagged so
            // the roster can render its badge and squared avatar.
            ->where('members.0.name', 'Zoe Owner')
            ->where('members.0.isBot', false)
            ->where('members.1.name', 'Deploy Bot')
            ->where('members.1.isBot', true)
        );
});

test('a bot is excluded from the channel join member count', function (): void {
    [$owner, $team] = botInGeneral();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        // Only the human owner is a seat; the bot member does not count.
        ->assertInertia(fn (Assert $page): Assert => $page->where('memberCount', 1));
});

test('a bot is excluded from the team roster shared to the sidebar and DM picker', function (): void {
    [$owner, $team, $bot] = botInGeneral();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('teamMembers', fn (Collection $members): bool => $members
                ->doesntContain(fn (array $member): bool => $member['id'] === $bot->id))
        );
});

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
