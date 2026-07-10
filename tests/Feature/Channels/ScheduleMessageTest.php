<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\ScheduledMessageStatus;
use App\Enums\TeamRole;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\ScheduledMessage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function scheduleTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

test('a member can schedule a message for a future time', function () {
    Event::fake([MessageSent::class]);

    [$owner, $team, $general] = scheduleTeamWithGeneral();
    $clientUuid = (string) Str::uuid7();
    $sendAt = now()->addHour();

    $this->actingAs($owner)
        ->post(route('channels.scheduled-messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => 'send me later',
            'client_uuid' => $clientUuid,
            'send_at' => $sendAt->toIso8601String(),
        ])
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]));

    $scheduled = ScheduledMessage::firstOrFail();

    expect($scheduled->body)->toBe('send me later')
        ->and($scheduled->client_uuid)->toBe($clientUuid)
        ->and($scheduled->user_id)->toBe($owner->id)
        ->and($scheduled->channel_id)->toBe($general->id)
        ->and($scheduled->status)->toBe(ScheduledMessageStatus::Pending)
        ->and($scheduled->send_at->toIso8601String())->toBe($sendAt->toIso8601String());

    // Scheduling posts nothing yet: no message row and no broadcast.
    expect(Message::count())->toBe(0);
    Event::assertNotDispatched(MessageSent::class);
});

test('scheduling clears the author composer draft for the channel', function () {
    [$owner, $team, $general] = scheduleTeamWithGeneral();
    $owner->channels()->updateExistingPivot($general->id, ['draft' => 'half-typed']);

    $this->actingAs($owner)
        ->post(route('channels.scheduled-messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => 'send me later',
            'client_uuid' => (string) Str::uuid7(),
            'send_at' => now()->addHour()->toIso8601String(),
        ]);

    expect($owner->channels()->where('channels.id', $general->id)->first()->pivot->draft)->toBeNull();
});

test('a scheduled message can quote a live message in the same channel', function () {
    [$owner, $team, $general] = scheduleTeamWithGeneral();
    $parent = Message::factory()->for($general)->for($owner)->create();

    $this->actingAs($owner)
        ->post(route('channels.scheduled-messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => 'a scheduled reply',
            'client_uuid' => (string) Str::uuid7(),
            'send_at' => now()->addHour()->toIso8601String(),
            'reply_to_id' => $parent->id,
        ]);

    expect(ScheduledMessage::firstOrFail()->reply_to_id)->toBe($parent->id);
});

test('a non-future send time is rejected', function () {
    [$owner, $team, $general] = scheduleTeamWithGeneral();

    $this->actingAs($owner)
        ->post(route('channels.scheduled-messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => 'too late',
            'client_uuid' => (string) Str::uuid7(),
            'send_at' => now()->subMinute()->toIso8601String(),
        ])
        ->assertInvalid(['send_at']);

    expect(ScheduledMessage::count())->toBe(0);
});

test('a missing send time is rejected', function () {
    [$owner, $team, $general] = scheduleTeamWithGeneral();

    $this->actingAs($owner)
        ->post(route('channels.scheduled-messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => 'when though',
            'client_uuid' => (string) Str::uuid7(),
        ])
        ->assertInvalid(['send_at']);
});

test('an empty body is rejected', function () {
    [$owner, $team, $general] = scheduleTeamWithGeneral();

    $this->actingAs($owner)
        ->post(route('channels.scheduled-messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => '   ',
            'client_uuid' => (string) Str::uuid7(),
            'send_at' => now()->addHour()->toIso8601String(),
        ])
        ->assertInvalid(['body']);
});

test('the reply target must belong to the same channel', function () {
    [$owner, $team, $general] = scheduleTeamWithGeneral();
    $other = Channel::factory()->for($team)->create();
    $other->channelMembers()->create(['user_id' => $owner->id]);
    $foreign = Message::factory()->for($other)->for($owner)->create();

    $this->actingAs($owner)
        ->post(route('channels.scheduled-messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => 'cross-channel reply',
            'client_uuid' => (string) Str::uuid7(),
            'send_at' => now()->addHour()->toIso8601String(),
            'reply_to_id' => $foreign->id,
        ])
        ->assertInvalid(['reply_to_id']);
});

test('the reply target cannot be a deleted message', function () {
    [$owner, $team, $general] = scheduleTeamWithGeneral();
    $parent = Message::factory()->for($general)->for($owner)->create();
    $parent->delete();

    $this->actingAs($owner)
        ->post(route('channels.scheduled-messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => 'reply to a ghost',
            'client_uuid' => (string) Str::uuid7(),
            'send_at' => now()->addHour()->toIso8601String(),
            'reply_to_id' => $parent->id,
        ])
        ->assertInvalid(['reply_to_id']);
});

test('a non-member cannot schedule a message', function () {
    [$owner, $team] = scheduleTeamWithGeneral();
    // A private channel the team member is not a member of: the postMessage gate
    // rejects scheduling just as it would an immediate send.
    $private = Channel::factory()->for($team)->private()->create();
    $private->channelMembers()->create(['user_id' => $owner->id]);

    $stranger = User::factory()->create();
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);

    $this->actingAs($stranger)
        ->post(route('channels.scheduled-messages.store', ['team' => $team->slug, 'channel' => $private->slug]), [
            'body' => 'let me in',
            'client_uuid' => (string) Str::uuid7(),
            'send_at' => now()->addHour()->toIso8601String(),
        ])
        ->assertForbidden();

    expect(ScheduledMessage::count())->toBe(0);
});

test('a message cannot be scheduled to an archived channel', function () {
    [$owner, $team, $general] = scheduleTeamWithGeneral();
    $channel = Channel::factory()->for($team)->archived()->create();
    $channel->channelMembers()->create(['user_id' => $owner->id]);

    $this->actingAs($owner)
        ->post(route('channels.scheduled-messages.store', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'body' => 'to the archive',
            'client_uuid' => (string) Str::uuid7(),
            'send_at' => now()->addHour()->toIso8601String(),
        ])
        ->assertForbidden();
});
