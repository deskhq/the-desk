<?php

use App\Actions\Teams\CreateTeam;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function replyTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

test('a message can reply to another message in the same channel', function () {
    [$owner, $team, $general] = replyTeamWithGeneral();
    $parent = Message::factory()->for($general)->for($owner)->create(['body' => 'parent']);
    $clientUuid = (string) Str::uuid7();

    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => 'a reply',
            'client_uuid' => $clientUuid,
            'reply_to_id' => $parent->id,
        ])
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]));

    $this->assertDatabaseHas('messages', [
        'client_uuid' => $clientUuid,
        'body' => 'a reply',
        'reply_to_id' => $parent->id,
    ]);
});

test('a message without a reply target persists a null reply reference', function () {
    [$owner, $team, $general] = replyTeamWithGeneral();
    $clientUuid = (string) Str::uuid7();

    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => 'plain message',
            'client_uuid' => $clientUuid,
        ]);

    $this->assertDatabaseHas('messages', [
        'client_uuid' => $clientUuid,
        'reply_to_id' => null,
    ]);
});

test('the reply target must belong to the same channel', function () {
    [$owner, $team, $general] = replyTeamWithGeneral();
    $other = Channel::factory()->for($team)->create();
    $other->channelMembers()->create(['user_id' => $owner->id]);
    $foreign = Message::factory()->for($other)->for($owner)->create();

    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => 'cross-channel reply',
            'client_uuid' => (string) Str::uuid7(),
            'reply_to_id' => $foreign->id,
        ])
        ->assertInvalid(['reply_to_id']);

    expect(Message::where('channel_id', $general->id)->count())->toBe(0);
});

test('a reply cannot target a deleted message', function () {
    [$owner, $team, $general] = replyTeamWithGeneral();
    $parent = Message::factory()->for($general)->for($owner)->create();
    $parent->delete();

    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => 'reply to a ghost',
            'client_uuid' => (string) Str::uuid7(),
            'reply_to_id' => $parent->id,
        ])
        ->assertInvalid(['reply_to_id']);
});

test('a non-uuid reply target is rejected', function () {
    [$owner, $team, $general] = replyTeamWithGeneral();

    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => 'bad target',
            'client_uuid' => (string) Str::uuid7(),
            'reply_to_id' => 'not-a-uuid',
        ])
        ->assertInvalid(['reply_to_id']);
});

test('the channel page renders a reply as a compact quote of its parent', function () {
    [$owner, $team, $general] = replyTeamWithGeneral();
    $parent = Message::factory()->for($general)->for($owner)->create(['body' => 'the original']);
    Message::factory()->for($general)->for($owner)->replyTo($parent)->create(['body' => 'the answer']);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page) => $page
            // Newest-first: the reply is data.0, its parent data.1.
            ->where('messages.data.0.body', 'the answer')
            ->where('messages.data.0.replyTo.id', $parent->id)
            ->where('messages.data.0.replyTo.body', 'the original')
            ->where('messages.data.0.replyTo.authorName', $owner->name)
            ->where('messages.data.0.replyTo.isDeleted', false)
            ->where('messages.data.1.replyTo', null)
        );
});

test('a reply to a deleted parent renders a deleted quote stub with a blanked body', function () {
    [$owner, $team, $general] = replyTeamWithGeneral();
    $parent = Message::factory()->for($general)->for($owner)->create(['body' => 'secret original']);
    Message::factory()->for($general)->for($owner)->replyTo($parent)->create(['body' => 'still here']);
    $parent->delete();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('messages.data.0.body', 'still here')
            ->where('messages.data.0.replyTo.id', $parent->id)
            ->where('messages.data.0.replyTo.isDeleted', true)
            ->where('messages.data.0.replyTo.body', '')
        );
});

test('a deleted reply carries no quote of its own', function () {
    [$owner, $team, $general] = replyTeamWithGeneral();
    $parent = Message::factory()->for($general)->for($owner)->create(['body' => 'original']);
    $reply = Message::factory()->for($general)->for($owner)->replyTo($parent)->create(['body' => 'answer']);
    $reply->delete();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('messages.data.0.isDeleted', true)
            ->where('messages.data.0.replyTo', null)
        );
});

test('posting a reply broadcasts the parent quote in the MessageSent payload', function () {
    Event::fake([MessageSent::class]);

    [$owner, $team, $general] = replyTeamWithGeneral();
    $parent = Message::factory()->for($general)->for($owner)->create(['body' => 'parent body']);
    $clientUuid = (string) Str::uuid7();

    $this->actingAs($owner)->post(route('channels.messages.store', [
        'team' => $team->slug,
        'channel' => $general->slug,
    ]), [
        'body' => 'broadcast reply',
        'client_uuid' => $clientUuid,
        'reply_to_id' => $parent->id,
    ]);

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($parent) {
        $payload = $event->message->toArray();

        return $payload['replyTo']['id'] === $parent->id
            && $payload['replyTo']['body'] === 'parent body';
    });
});

test('the reply reference survives an idempotent resend', function () {
    [$owner, $team, $general] = replyTeamWithGeneral();
    $parent = Message::factory()->for($general)->for($owner)->create();
    $clientUuid = (string) Str::uuid7();

    $payload = [
        'team' => $team->slug,
        'channel' => $general->slug,
    ];

    $body = ['body' => 'once', 'client_uuid' => $clientUuid, 'reply_to_id' => $parent->id];

    $this->actingAs($owner)->post(route('channels.messages.store', $payload), $body);
    $this->actingAs($owner)->post(route('channels.messages.store', $payload), $body);

    expect(Message::where('client_uuid', $clientUuid)->count())->toBe(1)
        ->and(Message::where('client_uuid', $clientUuid)->first()->reply_to_id)->toBe($parent->id);
});
