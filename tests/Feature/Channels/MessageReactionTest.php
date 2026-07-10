<?php

use App\Actions\Teams\CreateTeam;
use App\Data\MessageData;
use App\Enums\TeamRole;
use App\Events\MessageReactionChanged;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use App\Support\AccountDeleter;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function reactionTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * POST the toggle-reaction endpoint for the given actor.
 */
function toggleReaction($actor, $team, $channel, $message, string $emoji)
{
    return test()->actingAs($actor)->post(route('channels.messages.reactions.store', [
        'team' => $team->slug,
        'channel' => $channel->slug,
        'message' => $message->id,
    ]), ['emoji' => $emoji]);
}

test('reacting adds the reaction, and reacting again with the same emoji removes it', function () {
    [$owner, $team, $general] = reactionTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();

    toggleReaction($owner, $team, $general, $message, '👍')->assertRedirect();

    expect(MessageReaction::where('message_id', $message->id)->where('user_id', $owner->id)->where('emoji', '👍')->exists())->toBeTrue();

    toggleReaction($owner, $team, $general, $message, '👍')->assertRedirect();

    expect(MessageReaction::where('message_id', $message->id)->exists())->toBeFalse();
});

test('a user holds at most one row per distinct emoji and can react with several emoji', function () {
    [$owner, $team, $general] = reactionTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();

    toggleReaction($owner, $team, $general, $message, '👍');
    toggleReaction($owner, $team, $general, $message, '🎉');

    expect(MessageReaction::where('message_id', $message->id)->where('user_id', $owner->id)->count())->toBe(2);

    // Toggling one emoji off leaves the other untouched — no duplicate rows.
    toggleReaction($owner, $team, $general, $message, '👍');

    $remaining = MessageReaction::where('message_id', $message->id)->where('user_id', $owner->id)->get();

    expect($remaining)->toHaveCount(1)
        ->and($remaining->first()->emoji)->toBe('🎉');
});

test('a non-member of a private channel cannot react', function () {
    [$owner, $team] = reactionTeamWithGeneral();

    $stranger = User::factory()->create();
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);

    $private = Channel::factory()->for($team)->private()->create();
    $private->channelMembers()->create(['user_id' => $owner->id]);
    $message = Message::factory()->for($private)->for($owner)->create();

    toggleReaction($stranger, $team, $private, $message, '👍')->assertForbidden();

    expect(MessageReaction::where('message_id', $message->id)->exists())->toBeFalse();
});

test('reactions cannot be added in an archived channel', function () {
    [$owner, $team, $general] = reactionTeamWithGeneral();
    $channel = Channel::factory()->for($team)->create(['archived_at' => now()]);
    $channel->channelMembers()->create(['user_id' => $owner->id]);
    $message = Message::factory()->for($channel)->for($owner)->create();

    toggleReaction($owner, $team, $channel, $message, '👍')->assertForbidden();

    expect(MessageReaction::where('message_id', $message->id)->exists())->toBeFalse();
});

test('the emoji is required', function () {
    [$owner, $team, $general] = reactionTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();

    $this->actingAs($owner)->post(route('channels.messages.reactions.store', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'message' => $message->id,
    ]), [])->assertInvalid(['emoji']);
});

test('a message aggregates its reactions per emoji with reactor sets', function () {
    [$owner, $team, $general] = reactionTeamWithGeneral();
    $alice = User::factory()->create(['name' => 'Alice']);
    $team->memberships()->create(['user_id' => $alice->id, 'role' => TeamRole::Member]);

    $message = Message::factory()->for($general)->for($owner)->create();

    MessageReaction::factory()->for($message)->for($owner)->emoji('👍')->create();
    MessageReaction::factory()->for($message)->for($alice)->emoji('👍')->create();
    MessageReaction::factory()->for($message)->for($alice)->emoji('🎉')->create();

    $message->load('reactions.user');
    $reactions = MessageData::fromMessage($message)->reactions;

    expect($reactions)->toHaveCount(2);

    $thumbs = collect($reactions)->firstWhere('emoji', '👍');
    $party = collect($reactions)->firstWhere('emoji', '🎉');

    expect($thumbs->count)->toBe(2)
        ->and(collect($thumbs->reactors)->pluck('name')->all())->toEqualCanonicalizing(['Alice', $owner->name])
        ->and($party->count)->toBe(1)
        ->and($party->reactors[0]->name)->toBe('Alice');
});

test('toggling a reaction broadcasts MessageReactionChanged on the channel with the fresh summary', function () {
    Event::fake([MessageReactionChanged::class]);

    [$owner, $team, $general] = reactionTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();

    // Add.
    toggleReaction($owner, $team, $general, $message, '👍');

    Event::assertDispatched(MessageReactionChanged::class, function (MessageReactionChanged $event) use ($general, $message) {
        $target = $event->broadcastOn()[0];
        $payload = $event->broadcastWith();

        expect($target)->toBeInstanceOf(PrivateChannel::class)
            ->and($target->name)->toBe('private-channel.'.$general->id);

        return $payload['messageId'] === $message->id
            && count($payload['reactions']) === 1
            && $payload['reactions'][0]['emoji'] === '👍'
            && $payload['reactions'][0]['count'] === 1;
    });

    // Remove — still broadcasts, now with an empty summary.
    toggleReaction($owner, $team, $general, $message, '👍');

    Event::assertDispatchedTimes(MessageReactionChanged::class, 2);
    Event::assertDispatched(MessageReactionChanged::class, fn (MessageReactionChanged $event) => $event->broadcastWith()['reactions'] === []);
});

test('deleting a message removes its reactions and emits none in the tombstone payload', function () {
    [$owner, $team, $general] = reactionTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();
    MessageReaction::factory()->for($message)->for($owner)->emoji('👍')->create();

    $this->actingAs($owner)->delete(route('channels.messages.destroy', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'message' => $message->id,
    ]))->assertRedirect();

    expect(MessageReaction::where('message_id', $message->id)->exists())->toBeFalse();

    $message->refresh()->loadMissing('user');
    expect(MessageData::fromMessage($message)->reactions)->toBe([]);
});

test('deleting a reacting user drops their reactions via the cascade', function () {
    [$owner, $team, $general] = reactionTeamWithGeneral();
    $alice = User::factory()->create();
    $team->memberships()->create(['user_id' => $alice->id, 'role' => TeamRole::Member]);

    $message = Message::factory()->for($general)->for($owner)->create();
    MessageReaction::factory()->for($message)->for($alice)->emoji('👍')->create();

    app(AccountDeleter::class)->delete($alice);

    expect(MessageReaction::where('message_id', $message->id)->exists())->toBeFalse();
});
