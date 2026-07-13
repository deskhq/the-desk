<?php

use App\Actions\Teams\CreateTeam;
use App\Data\MessageData;
use App\Enums\MessageType;
use App\Enums\TeamRole;
use App\Events\MessagePinned;
use App\Http\Requests\Channels\PinMessageRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessagePin;
use App\Models\User;
use App\Support\AccountDeleter;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function pinTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * POST the pin endpoint for the given actor.
 */
function pinMessage($actor, $team, $channel, $message)
{
    return test()->actingAs($actor)->post(route('channels.messages.pin.store', [
        'team' => $team->slug,
        'channel' => $channel->slug,
        'message' => $message->id,
    ]));
}

/**
 * DELETE the pin endpoint for the given actor.
 */
function unpinMessage($actor, $team, $channel, $message)
{
    return test()->actingAs($actor)->delete(route('channels.messages.pin.destroy', [
        'team' => $team->slug,
        'channel' => $channel->slug,
        'message' => $message->id,
    ]));
}

test('pinning a message adds a pin row attributed to the pinner', function (): void {
    [$owner, $team, $general] = pinTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();

    pinMessage($owner, $team, $general, $message)->assertRedirect();

    $pin = MessagePin::where('message_id', $message->id)->first();

    expect($pin)->not->toBeNull()
        ->and($pin->channel_id)->toBe($general->id)
        ->and($pin->pinned_by)->toBe($owner->id);
});

test('pinning again is an idempotent no-op that leaves one pin row', function (): void {
    [$owner, $team, $general] = pinTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();

    pinMessage($owner, $team, $general, $message)->assertRedirect();
    pinMessage($owner, $team, $general, $message)->assertRedirect();

    expect(MessagePin::where('message_id', $message->id)->count())->toBe(1);
});

test('unpinning removes the pin, and unpinning again is a no-op', function (): void {
    [$owner, $team, $general] = pinTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();
    MessagePin::factory()->for($message)->for($general)->for($owner, 'pinnedBy')->create();

    unpinMessage($owner, $team, $general, $message)->assertRedirect();

    expect(MessagePin::where('message_id', $message->id)->exists())->toBeFalse();

    // Double-unpin is a no-op, not an error.
    unpinMessage($owner, $team, $general, $message)->assertRedirect();
});

test('any member can unpin a message another member pinned', function (): void {
    [$owner, $team, $general] = pinTeamWithGeneral();
    $alice = User::factory()->create();
    $team->memberships()->create(['user_id' => $alice->id, 'role' => TeamRole::Member]);

    $message = Message::factory()->for($general)->for($owner)->create();
    MessagePin::factory()->for($message)->for($general)->for($owner, 'pinnedBy')->create();

    // Alice, who did not pin it, may still unpin — a shared toggle.
    unpinMessage($alice, $team, $general, $message)->assertRedirect();

    expect(MessagePin::where('message_id', $message->id)->exists())->toBeFalse();
});

test('a non-member of a private channel cannot pin', function (): void {
    [$owner, $team] = pinTeamWithGeneral();

    $stranger = User::factory()->create();
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);

    $private = Channel::factory()->for($team)->private()->create();
    $private->channelMembers()->create(['user_id' => $owner->id]);
    $message = Message::factory()->for($private)->for($owner)->create();

    pinMessage($stranger, $team, $private, $message)->assertForbidden();

    expect(MessagePin::where('message_id', $message->id)->exists())->toBeFalse();
});

test('messages cannot be pinned or unpinned in an archived channel', function (): void {
    [$owner, $team, $general] = pinTeamWithGeneral();
    $channel = Channel::factory()->for($team)->create(['archived_at' => now()]);
    $channel->channelMembers()->create(['user_id' => $owner->id]);
    $message = Message::factory()->for($channel)->for($owner)->create();
    $pin = MessagePin::factory()->for($message)->for($channel)->for($owner, 'pinnedBy')->create();

    pinMessage($owner, $team, $channel, $message)->assertForbidden();
    unpinMessage($owner, $team, $channel, $message)->assertForbidden();

    // The existing pin is untouched — archived channels stay read-only for pins.
    expect(MessagePin::whereKey($pin->id)->exists())->toBeTrue();
});

test('a system notice cannot be pinned', function (): void {
    [$owner, $team, $general] = pinTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create(['type' => MessageType::MemberJoined]);

    pinMessage($owner, $team, $general, $message)->assertForbidden();

    expect(MessagePin::where('message_id', $message->id)->exists())->toBeFalse();
});

test('thread replies and direct messages can be pinned', function (): void {
    [$owner, $team, $general] = pinTeamWithGeneral();
    $root = Message::factory()->for($general)->for($owner)->create();
    $reply = Message::factory()->for($general)->for($owner)->create(['thread_root_id' => $root->id]);

    pinMessage($owner, $team, $general, $reply)->assertRedirect();

    expect(MessagePin::where('message_id', $reply->id)->exists())->toBeTrue();

    // A direct message channel the owner belongs to accepts pins too.
    $dm = Channel::factory()->for($team)->direct()->create();
    $dm->channelMembers()->create(['user_id' => $owner->id]);
    $dmMessage = Message::factory()->for($dm)->for($owner)->create();

    pinMessage($owner, $team, $dm, $dmMessage)->assertRedirect();

    expect(MessagePin::where('message_id', $dmMessage->id)->exists())->toBeTrue();
});

test('pinning is rejected once the channel is at its pin cap', function (): void {
    [$owner, $team, $general] = pinTeamWithGeneral();

    // Fill the channel to the cap.
    $filler = Message::factory()->count(PinMessageRequest::MAX_PINS)->for($general)->for($owner)->create();
    foreach ($filler as $message) {
        MessagePin::factory()->for($message)->for($general)->for($owner, 'pinnedBy')->create();
    }

    $message = Message::factory()->for($general)->for($owner)->create();

    pinMessage($owner, $team, $general, $message)
        ->assertInvalid(['message' => 'This channel has reached its limit of 100 pinned messages.']);

    expect(MessagePin::where('message_id', $message->id)->exists())->toBeFalse()
        ->and(MessagePin::where('channel_id', $general->id)->count())->toBe(PinMessageRequest::MAX_PINS);
});

test('re-pinning an already-pinned message at the cap is still an idempotent no-op', function (): void {
    [$owner, $team, $general] = pinTeamWithGeneral();

    $messages = Message::factory()->count(PinMessageRequest::MAX_PINS)->for($general)->for($owner)->create();
    foreach ($messages as $message) {
        MessagePin::factory()->for($message)->for($general)->for($owner, 'pinnedBy')->create();
    }

    // The channel is at the cap, but re-pinning one already pinned does not error.
    pinMessage($owner, $team, $general, $messages->first())->assertRedirect();

    expect(MessagePin::where('channel_id', $general->id)->count())->toBe(PinMessageRequest::MAX_PINS);
});

test('MessageData carries the pin, and a tombstone carries none', function (): void {
    [$owner, $team, $general] = pinTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();
    MessagePin::factory()->for($message)->for($general)->for($owner, 'pinnedBy')->create();

    $message->loadMessageDataRelations();
    $data = MessageData::fromMessage($message);

    expect($data->pin)->not->toBeNull()
        ->and($data->pin->pinnedBy->id)->toBe($owner->id)
        ->and($data->pin->pinnedBy->name)->toBe($owner->name);

    // A soft-deleted message never surfaces a pin.
    $message->delete();
    $message->refresh()->loadMessageDataRelations();

    expect(MessageData::fromMessage($message)->pin)->toBeNull();
});

test('pinning broadcasts MessagePinned on the channel with the pinner and count', function (): void {
    Event::fake([MessagePinned::class]);

    [$owner, $team, $general] = pinTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();

    pinMessage($owner, $team, $general, $message);

    Event::assertDispatched(MessagePinned::class, function (MessagePinned $event) use ($general, $message, $owner): bool {
        $target = $event->broadcastOn()[0];
        $payload = $event->broadcastWith();

        expect($target)->toBeInstanceOf(PrivateChannel::class)
            ->and($target->name)->toBe('private-channel.'.$general->id);

        return $payload['messageId'] === $message->id
            && $payload['pinned'] === true
            && $payload['pinnedBy']['id'] === $owner->id
            && $payload['pinCount'] === 1;
    });

    // Re-pinning is a no-op and broadcasts nothing further.
    pinMessage($owner, $team, $general, $message);

    Event::assertDispatchedTimes(MessagePinned::class, 1);
});

test('unpinning broadcasts MessagePinned with pinned false and no pinner', function (): void {
    Event::fake([MessagePinned::class]);

    [$owner, $team, $general] = pinTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();
    MessagePin::factory()->for($message)->for($general)->for($owner, 'pinnedBy')->create();

    unpinMessage($owner, $team, $general, $message);

    Event::assertDispatched(MessagePinned::class, function (MessagePinned $event) use ($message): bool {
        $payload = $event->broadcastWith();

        return $payload['messageId'] === $message->id
            && $payload['pinned'] === false
            && $payload['pinnedBy'] === null
            && $payload['pinCount'] === 0;
    });

    // Double-unpin is a no-op and broadcasts nothing further.
    unpinMessage($owner, $team, $general, $message);

    Event::assertDispatchedTimes(MessagePinned::class, 1);
});

test('soft-deleting a pinned message removes the pin and broadcasts an unpin', function (): void {
    Event::fake([MessagePinned::class]);

    [$owner, $team, $general] = pinTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();
    MessagePin::factory()->for($message)->for($general)->for($owner, 'pinnedBy')->create();

    $this->actingAs($owner)->delete(route('channels.messages.destroy', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'message' => $message->id,
    ]))->assertRedirect();

    expect(MessagePin::where('message_id', $message->id)->exists())->toBeFalse();

    Event::assertDispatched(MessagePinned::class, fn (MessagePinned $event): bool => $event->messageId === $message->id && $event->pinned === false);
});

test('the channel page carries the pin count and pins most-recently-pinned first', function (): void {
    [$owner, $team, $general] = pinTeamWithGeneral();
    $older = Message::factory()->for($general)->for($owner)->create();
    $newer = Message::factory()->for($general)->for($owner)->create();

    MessagePin::factory()->for($older)->for($general)->for($owner, 'pinnedBy')->create(['created_at' => now()->subMinute()]);
    MessagePin::factory()->for($newer)->for($general)->for($owner, 'pinnedBy')->create(['created_at' => now()]);

    $this->actingAs($owner)->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pinCount', 2)
            ->has('pins', 2)
            ->where('pins.0.id', $newer->id)
            ->where('pins.1.id', $older->id)
        );
});

test('deleting a user reassigns the pins they created to the tombstone', function (): void {
    [$owner, $team, $general] = pinTeamWithGeneral();
    $alice = User::factory()->create();
    $team->memberships()->create(['user_id' => $alice->id, 'role' => TeamRole::Member]);

    $message = Message::factory()->for($general)->for($owner)->create();
    MessagePin::factory()->for($message)->for($general)->for($alice, 'pinnedBy')->create();

    app(AccountDeleter::class)->delete($alice);

    $pin = MessagePin::where('message_id', $message->id)->first();

    expect($pin)->not->toBeNull()
        ->and($pin->pinned_by)->toBe(User::tombstone()->id);
});

test('force-deleting a message cascades its pin away', function (): void {
    [$owner, $team, $general] = pinTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();
    MessagePin::factory()->for($message)->for($general)->for($owner, 'pinnedBy')->create();

    $message->forceDelete();

    expect(MessagePin::where('message_id', $message->id)->exists())->toBeFalse();
});
