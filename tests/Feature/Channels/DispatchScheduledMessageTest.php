<?php

use App\Actions\Channels\DispatchDueScheduledMessages;
use App\Data\MessageData;
use App\Enums\ScheduledMessageStatus;
use App\Enums\TeamRole;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\ScheduledMessage;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/** Run the per-minute dispatch scan. */
function dispatchDue(): void
{
    app(DispatchDueScheduledMessages::class)->handle();
}

test('a due scheduled message is delivered through the normal send path and broadcasts', function (): void {
    Event::fake([MessageSent::class]);

    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $scheduled = ScheduledMessage::factory()->for($general)->for($owner)->create([
        'body' => 'from the past',
        'send_at' => now()->subMinute(),
    ]);

    dispatchDue();

    $message = Message::where('client_uuid', $scheduled->client_uuid)->firstOrFail();

    expect($message->body)->toBe('from the past')
        ->and($message->user_id)->toBe($owner->id)
        ->and($message->channel_id)->toBe($general->id)
        ->and($scheduled->fresh()->status)->toBe(ScheduledMessageStatus::Sent)
        ->and($scheduled->fresh()->sent_at)->not->toBeNull();

    Event::assertDispatched(MessageSent::class, fn (MessageSent $event): bool => $event->broadcastOn()[0]->name === 'private-channel.'.$general->id
        && $event->broadcastWith() === MessageData::fromMessage($message->fresh()->load('user'))->toArray());
});

test('a scheduled message not yet due is left pending', function (): void {
    Event::fake([MessageSent::class]);

    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $scheduled = ScheduledMessage::factory()->for($general)->for($owner)->create([
        'send_at' => now()->addHour(),
    ]);

    dispatchDue();

    expect($scheduled->fresh()->status)->toBe(ScheduledMessageStatus::Pending)
        ->and(Message::count())->toBe(0);
    Event::assertNotDispatched(MessageSent::class);
});

test('a cancelled scheduled message is never delivered', function (): void {
    Event::fake([MessageSent::class]);

    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    ScheduledMessage::factory()->for($general)->for($owner)->cancelled()->create([
        'send_at' => now()->subMinute(),
    ]);

    dispatchDue();

    expect(Message::count())->toBe(0);
    Event::assertNotDispatched(MessageSent::class);
});

test('a due message is delivered at most once across overlapping runs', function (): void {
    Event::fake([MessageSent::class]);

    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    ScheduledMessage::factory()->for($general)->for($owner)->create([
        'send_at' => now()->subMinute(),
    ]);

    dispatchDue();
    dispatchDue();

    expect(Message::count())->toBe(1);
    Event::assertDispatchedTimes(MessageSent::class, 1);
});

test('a due message preserves the inline reply quote when its target is still live', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $parent = Message::factory()->for($general)->for($owner)->create();
    $scheduled = ScheduledMessage::factory()->for($general)->for($owner)->replyTo($parent)->create([
        'send_at' => now()->subMinute(),
    ]);

    dispatchDue();

    $message = Message::where('client_uuid', $scheduled->client_uuid)->firstOrFail();

    expect($message->reply_to_id)->toBe($parent->id)
        ->and($scheduled->fresh()->status)->toBe(ScheduledMessageStatus::Sent);
});

test('a due message drops a since-deleted reply target but still sends', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $parent = Message::factory()->for($general)->for($owner)->create();
    $scheduled = ScheduledMessage::factory()->for($general)->for($owner)->replyTo($parent)->create([
        'body' => 'still worth sending',
        'send_at' => now()->subMinute(),
    ]);
    $parent->delete();

    dispatchDue();

    $message = Message::where('client_uuid', $scheduled->client_uuid)->firstOrFail();

    expect($message->reply_to_id)->toBeNull()
        ->and($message->body)->toBe('still worth sending')
        ->and($scheduled->fresh()->status)->toBe(ScheduledMessageStatus::Sent);
});

test('a due message for an archived channel fails instead of delivering', function (): void {
    Event::fake([MessageSent::class]);

    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $channel = Channel::factory()->for($team)->create();
    $channel->channelMembers()->create(['user_id' => $owner->id]);
    $scheduled = ScheduledMessage::factory()->for($channel)->for($owner)->create([
        'send_at' => now()->subMinute(),
    ]);
    $channel->update(['archived_at' => now()]);

    dispatchDue();

    expect(Message::count())->toBe(0)
        ->and($scheduled->fresh()->status)->toBe(ScheduledMessageStatus::Failed)
        ->and($scheduled->fresh()->failed_at)->not->toBeNull()
        ->and($scheduled->fresh()->failure_reason)->not->toBeNull();
    Event::assertNotDispatched(MessageSent::class);
});

test('a due message fails when its author is no longer a channel member', function (): void {
    Event::fake([MessageSent::class]);

    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $author = User::factory()->create();
    $team->memberships()->create(['user_id' => $author->id, 'role' => TeamRole::Member]);
    $general->channelMembers()->firstOrCreate(['user_id' => $author->id]);
    $scheduled = ScheduledMessage::factory()->for($general)->for($author)->create([
        'send_at' => now()->subMinute(),
    ]);
    $general->channelMembers()->where('user_id', $author->id)->delete();

    dispatchDue();

    expect(Message::count())->toBe(0)
        ->and($scheduled->fresh()->status)->toBe(ScheduledMessageStatus::Failed);
    Event::assertNotDispatched(MessageSent::class);
});

test('delivering a scheduled message leaves the author current draft untouched', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $owner->channels()->updateExistingPivot($general->id, ['draft' => 'a fresh draft']);
    ScheduledMessage::factory()->for($general)->for($owner)->create([
        'send_at' => now()->subMinute(),
    ]);

    dispatchDue();

    expect($owner->channels()->where('channels.id', $general->id)->first()->pivot->draft)
        ->toBe('a fresh draft');
});

test('the send time is honored as a UTC instant regardless of when the scan runs', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $sendAt = now()->addMinutes(30);
    ScheduledMessage::factory()->for($general)->for($owner)->create(['send_at' => $sendAt]);

    // A scan a minute before the instant leaves it pending.
    $this->travelTo($sendAt->copy()->subMinute());
    dispatchDue();
    expect(Message::count())->toBe(0);

    // A scan once the instant has arrived delivers it.
    $this->travelTo($sendAt->copy()->addSecond());
    dispatchDue();
    expect(Message::count())->toBe(1);
});
