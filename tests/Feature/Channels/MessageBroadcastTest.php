<?php

use App\Actions\Teams\CreateTeam;
use App\Data\MessageData;
use App\Enums\TeamRole;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function broadcastTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Exercise channel authorization against a real broadcaster.
 *
 * The test suite defaults to the `null` broadcaster, which authorizes every
 * subscription without consulting routes/channels.php. Switching to a real
 * driver and reloading the channel definitions onto it lets the authorization
 * callback actually run.
 */
function useRealBroadcaster(): void
{
    config(['broadcasting.default' => 'reverb']);

    require base_path('routes/channels.php');
}

test('posting a message broadcasts MessageSent on the channel private channel with the MessageData payload', function () {
    Event::fake([MessageSent::class]);

    [$owner, $team, $general] = broadcastTeamWithGeneral();
    $clientUuid = (string) Str::uuid7();

    $this->actingAs($owner)->post(route('channels.messages.store', [
        'team' => $team->slug,
        'channel' => $general->slug,
    ]), [
        'body' => 'Hello realtime',
        'client_uuid' => $clientUuid,
    ]);

    $message = Message::where('client_uuid', $clientUuid)->with('user')->firstOrFail();

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($general, $message) {
        $target = $event->broadcastOn()[0];

        expect($target)->toBeInstanceOf(PrivateChannel::class)
            ->and($target->name)->toBe('private-channel.'.$general->id);

        return $event->broadcastWith() === MessageData::fromMessage($message)->toArray();
    });
});

test('a resent message with the same client uuid broadcasts only once', function () {
    Event::fake([MessageSent::class]);

    [$owner, $team, $general] = broadcastTeamWithGeneral();
    $clientUuid = (string) Str::uuid7();

    $payload = [
        'team' => $team->slug,
        'channel' => $general->slug,
    ];

    $this->actingAs($owner)->post(route('channels.messages.store', $payload), [
        'body' => 'first and only',
        'client_uuid' => $clientUuid,
    ]);

    $this->actingAs($owner)->post(route('channels.messages.store', $payload), [
        'body' => 'first and only',
        'client_uuid' => $clientUuid,
    ]);

    Event::assertDispatchedTimes(MessageSent::class, 1);
});

test('a channel member is authorized to subscribe to the channel', function () {
    useRealBroadcaster();

    [$owner, $team, $general] = broadcastTeamWithGeneral();

    $this->actingAs($owner)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-channel.'.$general->id,
            'socket_id' => '1234.5678',
        ])
        ->assertOk();
});

test('a non-member cannot subscribe to the channel', function () {
    useRealBroadcaster();

    [$owner, $team] = broadcastTeamWithGeneral();

    $stranger = User::factory()->create();
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);

    $private = Channel::factory()->for($team)->private()->create();
    $private->channelMembers()->create(['user_id' => $owner->id]);

    $this->actingAs($stranger)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-channel.'.$private->id,
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});

test('subscribing to an unknown channel is denied', function () {
    useRealBroadcaster();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-channel.'.Str::uuid7(),
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});
