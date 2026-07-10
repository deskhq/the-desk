<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\ScheduledMessage;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function scheduledListTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

test('the channel page lists the viewer own pending scheduled messages, soonest first', function () {
    [$owner, $team, $general] = scheduledListTeamWithGeneral();

    $later = ScheduledMessage::factory()->for($general)->for($owner)->create([
        'body' => 'the later one',
        'send_at' => now()->addHours(3),
    ]);
    $sooner = ScheduledMessage::factory()->for($general)->for($owner)->create([
        'body' => 'the sooner one',
        'send_at' => now()->addHour(),
    ]);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('scheduledMessages', 2)
            ->where('scheduledMessages.0.id', $sooner->id)
            ->where('scheduledMessages.0.body', 'the sooner one')
            ->where('scheduledMessages.1.id', $later->id)
        );
});

test('the list excludes other members scheduled messages', function () {
    [$owner, $team, $general] = scheduledListTeamWithGeneral();

    $other = User::factory()->create();
    $team->memberships()->create(['user_id' => $other->id, 'role' => TeamRole::Member]);

    ScheduledMessage::factory()->for($general)->for($owner)->create(['body' => 'mine']);
    ScheduledMessage::factory()->for($general)->for($other)->create(['body' => 'theirs']);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('scheduledMessages', 1)
            ->where('scheduledMessages.0.body', 'mine')
        );
});

test('the list excludes sent and cancelled scheduled messages', function () {
    [$owner, $team, $general] = scheduledListTeamWithGeneral();

    ScheduledMessage::factory()->for($general)->for($owner)->create(['body' => 'still pending']);
    ScheduledMessage::factory()->for($general)->for($owner)->sent()->create(['body' => 'already sent']);
    ScheduledMessage::factory()->for($general)->for($owner)->cancelled()->create(['body' => 'was cancelled']);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('scheduledMessages', 1)
            ->where('scheduledMessages.0.body', 'still pending')
        );
});

test('the list is scoped to the current channel', function () {
    [$owner, $team, $general] = scheduledListTeamWithGeneral();
    $other = Channel::factory()->for($team)->create();
    $other->channelMembers()->create(['user_id' => $owner->id]);

    ScheduledMessage::factory()->for($general)->for($owner)->create(['body' => 'in general']);
    ScheduledMessage::factory()->for($other)->for($owner)->create(['body' => 'in other']);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('scheduledMessages', 1)
            ->where('scheduledMessages.0.body', 'in general')
        );
});

test('a scheduled message carries its inline reply quote', function () {
    [$owner, $team, $general] = scheduledListTeamWithGeneral();
    $parent = Message::factory()->for($general)->for($owner)->create(['body' => 'the original']);
    ScheduledMessage::factory()->for($general)->for($owner)->replyTo($parent)->create(['body' => 'scheduled answer']);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scheduledMessages.0.replyTo.id', $parent->id)
            ->where('scheduledMessages.0.replyTo.body', 'the original')
            ->where('scheduledMessages.0.replyTo.authorName', $owner->name)
        );
});
