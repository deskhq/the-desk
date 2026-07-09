<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function threadTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Add a user to the team and #general, returning them.
 */
function threadMember(Team $team, Channel $channel, ?string $name = null): User
{
    $user = User::factory()->create($name ? ['name' => $name] : []);
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);
    $channel->channelMembers()->firstOrCreate(['user_id' => $user->id]);

    return $user;
}

/**
 * Post a thread reply through the HTTP endpoint as the given user.
 */
function postThreadReply(Team $team, Channel $channel, User $author, Message $root, array $overrides = []): TestResponse
{
    return test()->actingAs($author)->post(
        route('channels.messages.store', ['team' => $team->slug, 'channel' => $channel->slug]),
        array_merge([
            'body' => 'a thread reply',
            'client_uuid' => (string) Str::uuid7(),
            'thread_root_id' => $root->id,
        ], $overrides),
    );
}

test('a reply persists against its thread root and bumps the root aggregates', function () {
    [$owner, $team, $general] = threadTeamWithGeneral();
    $root = Message::factory()->for($general)->for($owner)->create(['body' => 'root']);
    $clientUuid = (string) Str::uuid7();

    postThreadReply($team, $general, $owner, $root, ['body' => 'the reply', 'client_uuid' => $clientUuid])
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]));

    $this->assertDatabaseHas('messages', [
        'client_uuid' => $clientUuid,
        'body' => 'the reply',
        'thread_root_id' => $root->id,
        'sent_to_channel' => false,
    ]);

    $root->refresh();
    expect($root->reply_count)->toBe(1)
        ->and($root->last_reply_at)->not->toBeNull();
});

test('a second reply increments the root reply count', function () {
    [$owner, $team, $general] = threadTeamWithGeneral();
    $root = Message::factory()->for($general)->for($owner)->create();

    postThreadReply($team, $general, $owner, $root);
    postThreadReply($team, $general, $owner, $root);

    expect($root->refresh()->reply_count)->toBe(2);
});

test('a reply cannot target another reply (threads are one level deep)', function () {
    [$owner, $team, $general] = threadTeamWithGeneral();
    $root = Message::factory()->for($general)->for($owner)->create();
    $reply = Message::factory()->for($owner)->inThread($root)->create();

    postThreadReply($team, $general, $owner, $reply, ['thread_root_id' => $reply->id])
        ->assertInvalid(['thread_root_id']);
});

test('the thread root must belong to the same channel', function () {
    [$owner, $team, $general] = threadTeamWithGeneral();
    $other = Channel::factory()->for($team)->create();
    $other->channelMembers()->create(['user_id' => $owner->id]);
    $foreign = Message::factory()->for($other)->for($owner)->create();

    postThreadReply($team, $general, $owner, $foreign, ['thread_root_id' => $foreign->id])
        ->assertInvalid(['thread_root_id']);
});

test('a reply cannot start on a deleted root', function () {
    [$owner, $team, $general] = threadTeamWithGeneral();
    $root = Message::factory()->for($general)->for($owner)->create();
    $root->delete();

    postThreadReply($team, $general, $owner, $root, ['thread_root_id' => $root->id])
        ->assertInvalid(['thread_root_id']);
});

test('a non-uuid thread root is rejected', function () {
    [$owner, $team, $general] = threadTeamWithGeneral();
    $root = Message::factory()->for($general)->for($owner)->create();

    postThreadReply($team, $general, $owner, $root, ['thread_root_id' => 'not-a-uuid'])
        ->assertInvalid(['thread_root_id']);
});

test('sent_to_channel is ignored without a thread root', function () {
    [$owner, $team, $general] = threadTeamWithGeneral();
    $clientUuid = (string) Str::uuid7();

    $this->actingAs($owner)->post(
        route('channels.messages.store', ['team' => $team->slug, 'channel' => $general->slug]),
        ['body' => 'plain', 'client_uuid' => $clientUuid, 'sent_to_channel' => true],
    );

    $this->assertDatabaseHas('messages', [
        'client_uuid' => $clientUuid,
        'thread_root_id' => null,
        'sent_to_channel' => false,
    ]);
});

test('the main timeline hides thread-only replies but keeps sent-to-channel replies', function () {
    [$owner, $team, $general] = threadTeamWithGeneral();
    $root = Message::factory()->for($general)->for($owner)->create(['body' => 'root']);
    Message::factory()->for($owner)->inThread($root)->create(['body' => 'thread only']);
    Message::factory()->for($owner)->inThread($root)->sentToChannel()->create(['body' => 'also in channel']);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('messages.data', 2)
            // Newest-first: the sent-to-channel reply, then the root.
            ->where('messages.data.0.body', 'also in channel')
            ->where('messages.data.0.sentToChannel', true)
            ->where('messages.data.1.body', 'root')
        );
});

test('the thread prop returns the root and its replies oldest-first, tombstones included', function () {
    [$owner, $team, $general] = threadTeamWithGeneral();
    $root = Message::factory()->for($general)->for($owner)->create(['body' => 'root']);
    Message::factory()->for($owner)->inThread($root)->create(['body' => 'first reply']);
    $second = Message::factory()->for($owner)->inThread($root)->create(['body' => 'second reply']);
    $second->delete();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug, 'thread' => $root->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('thread.root.id', $root->id)
            ->has('thread.replies', 2)
            ->where('thread.replies.0.body', 'first reply')
            ->where('thread.replies.1.isDeleted', true)
            ->where('thread.replies.1.body', '')
        );
});

test('the thread prop is null for a normal visit', function () {
    [$owner, $team, $general] = threadTeamWithGeneral();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('thread', null));
});

test('the thread prop is null when the param is blank or points elsewhere', function () {
    [$owner, $team, $general] = threadTeamWithGeneral();
    $reply = Message::factory()->for($owner)->inThread(
        Message::factory()->for($general)->for($owner)->create(),
    )->create();

    // Blank param.
    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug, 'thread' => '']))
        ->assertInertia(fn (Assert $page) => $page->where('thread', null));

    // A reply id is not a root, so it resolves to no thread.
    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug, 'thread' => $reply->id]))
        ->assertInertia(fn (Assert $page) => $page->where('thread', null));
});

test('a root exposes its distinct thread participants and survives deletion', function () {
    [$owner, $team, $general] = threadTeamWithGeneral();
    $ada = threadMember($team, $general, 'Ada');
    $grace = threadMember($team, $general, 'Grace');

    $root = Message::factory()->for($general)->for($owner)->create([
        'reply_count' => 3,
        'last_reply_at' => now(),
    ]);
    // Ada replies twice, Grace once: two distinct participants.
    Message::factory()->for($ada)->inThread($root)->count(2)->create();
    Message::factory()->for($grace)->inThread($root)->create();
    $root->delete();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('messages.data', 1)
            ->where('messages.data.0.isDeleted', true)
            ->where('messages.data.0.body', '')
            ->where('messages.data.0.threadReplyCount', 3)
            ->has('messages.data.0.threadParticipants', 2)
        );
});

test('posting a reply broadcasts the reply and the updated root aggregate', function () {
    Event::fake([MessageSent::class, MessageUpdated::class]);

    [$owner, $team, $general] = threadTeamWithGeneral();
    $root = Message::factory()->for($general)->for($owner)->create();
    $clientUuid = (string) Str::uuid7();

    postThreadReply($team, $general, $owner, $root, ['body' => 'broadcast reply', 'client_uuid' => $clientUuid]);

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($root, $clientUuid) {
        $payload = $event->message->toArray();

        return $payload['clientUuid'] === $clientUuid
            && $payload['threadRootId'] === $root->id;
    });

    Event::assertDispatched(MessageUpdated::class, function (MessageUpdated $event) use ($root) {
        $payload = $event->message->toArray();

        return $payload['id'] === $root->id && $payload['threadReplyCount'] === 1;
    });
});

test('a thread-only reply does not raise the channel unread badge', function () {
    [$owner, $team, $general] = threadTeamWithGeneral();
    $member = threadMember($team, $general, 'Reader');
    $root = Message::factory()->for($general)->for($owner)->create();

    // A plain timeline message and a thread-only reply, both by someone else.
    Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($owner)->inThread($root)->create();

    $entry = threadSidebarEntry($member, $team, $general);

    // The root + the plain message count; the thread-only reply does not.
    expect($entry['unreadCount'])->toBe(2);
});

test('a sent-to-channel reply raises the channel unread badge', function () {
    [$owner, $team, $general] = threadTeamWithGeneral();
    $member = threadMember($team, $general, 'Reader');
    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($owner)->inThread($root)->sentToChannel()->create();

    // The root + the sent-to-channel reply both count.
    expect(threadSidebarEntry($member, $team, $general)['unreadCount'])->toBe(2);
});

test('a mention inside a thread still badges the channel', function () {
    [$owner, $team, $general] = threadTeamWithGeneral();
    $member = threadMember($team, $general, 'Reader');
    $root = Message::factory()->for($general)->for($owner)->create();

    $reply = Message::factory()->for($owner)->inThread($root)->create([
        'body' => "hey @[{$member->name}]({$member->id})",
    ]);
    $reply->mentionedUsers()->attach($member->id);

    $entry = threadSidebarEntry($member, $team, $general);

    // The thread reply is not in the plain unread count, but its mention badges.
    expect($entry['unreadCount'])->toBe(1)
        ->and($entry['mentionCount'])->toBe(1);
});

/**
 * Resolve the sidebar `channels` prop entry for the given channel as the user.
 *
 * @return array{unreadCount: int, mentionCount: int}
 */
function threadSidebarEntry(User $user, Team $team, Channel $channel): array
{
    $response = test()->actingAs($user)->get(route('channels.show', [
        'team' => $team->slug,
        'channel' => $channel->slug,
    ]))->assertOk();

    $entry = collect($response->viewData('page')['props']['channels'])
        ->firstWhere('slug', $channel->slug);

    return ['unreadCount' => $entry['unreadCount'], 'mentionCount' => $entry['mentionCount']];
}
