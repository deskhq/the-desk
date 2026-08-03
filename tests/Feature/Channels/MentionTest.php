<?php

use App\Data\MessageData;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Build a mention token the composer inserts and the parser round-trips.
 */
function mentionToken(User $user): string
{
    return "@[{$user->name}]({$user->id})";
}

test('posting a message persists a mention row for a real team member', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $mentioned = teamMemberInChannel($general, ['name' => 'Ada Lovelace']);

    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => 'hey '.mentionToken($mentioned).' welcome',
            'client_uuid' => (string) Str::uuid7(),
        ])
        ->assertRedirect();

    $message = Message::firstOrFail();

    $this->assertDatabaseHas('mentions', [
        'message_id' => $message->id,
        'mentioned_user_id' => $mentioned->id,
    ]);
    expect($message->mentionedUsers)->toHaveCount(1);
});

test('parsing resolves only real team members, ignoring non-members and unknown ids', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general, ['name' => 'Real Member']);
    $stranger = User::factory()->create(['name' => 'Outsider']);
    $bogusId = (string) Str::uuid7();

    $body = mentionToken($member).' '.mentionToken($stranger)." @[Ghost]({$bogusId})";

    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => $body,
            'client_uuid' => (string) Str::uuid7(),
        ])
        ->assertRedirect();

    $message = Message::firstOrFail();

    expect($message->mentionedUsers->pluck('id')->all())->toBe([$member->id]);
    $this->assertDatabaseMissing('mentions', ['mentioned_user_id' => $stranger->id]);
});

test('a user mentioned twice in one message is persisted once', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $mentioned = teamMemberInChannel($general);

    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => mentionToken($mentioned).' and again '.mentionToken($mentioned),
            'client_uuid' => (string) Str::uuid7(),
        ])
        ->assertRedirect();

    expect(Message::firstOrFail()->mentionedUsers)->toHaveCount(1);
});

test('a mention token inside inline code does not notify', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $inCode = teamMemberInChannel($general, ['name' => 'Code Only']);
    $real = teamMemberInChannel($general, ['name' => 'Real Ping']);

    // Matches the client: a `@[Name](id)` token inside a `code` span renders
    // inert, so it must not create a mention. The one outside the span still does.
    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => 'ignore `'.mentionToken($inCode).'` but ping '.mentionToken($real),
            'client_uuid' => (string) Str::uuid7(),
        ])
        ->assertRedirect();

    $mentioned = Message::firstOrFail()->mentionedUsers;

    expect($mentioned)->toHaveCount(1)
        ->and($mentioned->first()->id)->toBe($real->id);
});

test('editing a message re-syncs mentions: adds new and removes stale', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $first = teamMemberInChannel($general, ['name' => 'First Person']);
    $second = teamMemberInChannel($general, ['name' => 'Second Person']);

    $message = Message::factory()->for($general)->for($owner)->create([
        'body' => 'ping '.mentionToken($first),
    ]);
    $message->mentionedUsers()->sync([$first->id]);

    $this->actingAs($owner)
        ->patch(route('channels.messages.update', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'message' => $message->id,
        ]), ['body' => 'ping '.mentionToken($second)])
        ->assertRedirect();

    expect($message->refresh()->mentionedUsers->pluck('id')->all())->toBe([$second->id]);
    $this->assertDatabaseMissing('mentions', ['mentioned_user_id' => $first->id]);
    $this->assertDatabaseHas('mentions', ['mentioned_user_id' => $second->id]);
});

test('the mention payload rides the MessageData on the channel page', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $mentioned = teamMemberInChannel($general, ['name' => 'Grace Hopper']);

    $message = Message::factory()->for($general)->for($owner)->create([
        'body' => 'hi '.mentionToken($mentioned),
    ]);
    $message->mentionedUsers()->sync([$mentioned->id]);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('messages.data.0.mentions', 1)
            ->where('messages.data.0.mentions.0.id', $mentioned->id)
            ->where('messages.data.0.mentions.0.name', 'Grace Hopper')
        );
});

test('the mention payload rides the broadcast MessageData', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $mentioned = teamMemberInChannel($general, ['name' => 'Alan Turing']);
    $clientUuid = (string) Str::uuid7();

    $this->actingAs($owner)->post(route('channels.messages.store', [
        'team' => $team->slug,
        'channel' => $general->slug,
    ]), [
        'body' => 'yo '.mentionToken($mentioned),
        'client_uuid' => $clientUuid,
    ]);

    $message = Message::where('client_uuid', $clientUuid)->firstOrFail();
    $payload = MessageData::fromMessage($message->load(['user', 'mentionedUsers']))->toArray();

    expect($payload['mentions'])->toBe([['id' => $mentioned->id, 'name' => 'Alan Turing', 'avatar' => $mentioned->avatar]]);
});

test('a deleted message blanks its mentions in the tombstone payload', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $mentioned = teamMemberInChannel($general);

    $message = Message::factory()->for($general)->for($owner)->create();
    $message->mentionedUsers()->sync([$mentioned->id]);
    $message->delete();

    $payload = MessageData::fromMessage($message->load('user'))->toArray();

    expect($payload['isDeleted'])->toBeTrue()
        ->and($payload['mentions'])->toBe([]);
});

test('the channel page exposes team members for the composer autocomplete', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    teamMemberInChannel($general, ['name' => 'Bea Member']);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('members', 2)
            ->where('members', fn ($members): bool => collect($members)->pluck('name')->contains('Bea Member')
                && collect($members)->pluck('name')->contains($owner->name))
        );
});
