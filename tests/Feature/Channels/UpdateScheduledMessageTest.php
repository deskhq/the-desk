<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\ScheduledMessageStatus;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\ScheduledMessage;
use App\Models\Team;
use App\Models\User;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function updateScheduledTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

test('the author can edit the body and send time of a pending scheduled message', function () {
    [$owner, $team, $general] = updateScheduledTeamWithGeneral();
    $scheduled = ScheduledMessage::factory()->for($general)->for($owner)->create([
        'body' => 'first draft',
        'send_at' => now()->addHour(),
    ]);
    $newTime = now()->addDay();

    $this->actingAs($owner)
        ->patch(route('channels.scheduled-messages.update', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'scheduledMessage' => $scheduled->id,
        ]), [
            'body' => 'revised draft',
            'send_at' => $newTime->toIso8601String(),
        ])
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]));

    $scheduled->refresh();

    expect($scheduled->body)->toBe('revised draft')
        ->and($scheduled->send_at->toIso8601String())->toBe($newTime->toIso8601String())
        ->and($scheduled->status)->toBe(ScheduledMessageStatus::Pending);
});

test('editing rejects a non-future send time', function () {
    [$owner, $team, $general] = updateScheduledTeamWithGeneral();
    $scheduled = ScheduledMessage::factory()->for($general)->for($owner)->create();

    $this->actingAs($owner)
        ->patch(route('channels.scheduled-messages.update', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'scheduledMessage' => $scheduled->id,
        ]), [
            'body' => 'revised',
            'send_at' => now()->subMinute()->toIso8601String(),
        ])
        ->assertInvalid(['send_at']);
});

test('editing rejects an empty body', function () {
    [$owner, $team, $general] = updateScheduledTeamWithGeneral();
    $scheduled = ScheduledMessage::factory()->for($general)->for($owner)->create();

    $this->actingAs($owner)
        ->patch(route('channels.scheduled-messages.update', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'scheduledMessage' => $scheduled->id,
        ]), [
            'body' => '   ',
            'send_at' => now()->addHour()->toIso8601String(),
        ])
        ->assertInvalid(['body']);
});

test('a non-author cannot edit a scheduled message', function () {
    [$owner, $team, $general] = updateScheduledTeamWithGeneral();
    $other = User::factory()->create();
    $team->memberships()->create(['user_id' => $other->id, 'role' => TeamRole::Member]);
    $scheduled = ScheduledMessage::factory()->for($general)->for($owner)->create(['body' => 'not yours']);

    $this->actingAs($other)
        ->patch(route('channels.scheduled-messages.update', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'scheduledMessage' => $scheduled->id,
        ]), [
            'body' => 'hijacked',
            'send_at' => now()->addHour()->toIso8601String(),
        ])
        ->assertForbidden();

    expect($scheduled->fresh()->body)->toBe('not yours');
});

test('an already-sent scheduled message cannot be edited', function () {
    [$owner, $team, $general] = updateScheduledTeamWithGeneral();
    $scheduled = ScheduledMessage::factory()->for($general)->for($owner)->sent()->create();

    $this->actingAs($owner)
        ->patch(route('channels.scheduled-messages.update', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'scheduledMessage' => $scheduled->id,
        ]), [
            'body' => 'too late to change',
            'send_at' => now()->addHour()->toIso8601String(),
        ])
        ->assertForbidden();
});

test('the author can cancel a pending scheduled message', function () {
    [$owner, $team, $general] = updateScheduledTeamWithGeneral();
    $scheduled = ScheduledMessage::factory()->for($general)->for($owner)->create();

    $this->actingAs($owner)
        ->delete(route('channels.scheduled-messages.destroy', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'scheduledMessage' => $scheduled->id,
        ]))
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]));

    $scheduled->refresh();

    expect($scheduled->status)->toBe(ScheduledMessageStatus::Cancelled)
        ->and($scheduled->cancelled_at)->not->toBeNull();
});

test('a non-author cannot cancel a scheduled message', function () {
    [$owner, $team, $general] = updateScheduledTeamWithGeneral();
    $other = User::factory()->create();
    $team->memberships()->create(['user_id' => $other->id, 'role' => TeamRole::Member]);
    $scheduled = ScheduledMessage::factory()->for($general)->for($owner)->create();

    $this->actingAs($other)
        ->delete(route('channels.scheduled-messages.destroy', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'scheduledMessage' => $scheduled->id,
        ]))
        ->assertForbidden();

    expect($scheduled->fresh()->status)->toBe(ScheduledMessageStatus::Pending);
});

test('a scheduled message id from another channel is not resolvable', function () {
    [$owner, $team, $general] = updateScheduledTeamWithGeneral();
    $other = Channel::factory()->for($team)->create();
    $other->channelMembers()->create(['user_id' => $owner->id]);
    $scheduled = ScheduledMessage::factory()->for($other)->for($owner)->create();

    $this->actingAs($owner)
        ->delete(route('channels.scheduled-messages.destroy', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'scheduledMessage' => $scheduled->id,
        ]))
        ->assertNotFound();
});
