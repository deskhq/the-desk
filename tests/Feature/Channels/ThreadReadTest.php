<?php

use App\Actions\Channels\MarkChannelRead;
use App\Actions\Channels\MarkThreadRead;
use App\Actions\Teams\CreateTeam;
use App\Enums\NotificationLevel;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\ThreadRead;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function threadReadSetup(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Add a user to the team and #general, returning them.
 */
function threadReadMember(Team $team, Channel $channel): User
{
    $user = User::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);
    $channel->channelMembers()->firstOrCreate(['user_id' => $user->id]);

    return $user;
}

/**
 * Load the channel as the given user and return the root message's payload from
 * the main timeline (thread-only replies are excluded, so a lone root sits at 0).
 *
 * @return array<string, mixed>
 */
function rootPayload(User $viewer, Team $team, Channel $channel, Message $root): array
{
    $captured = [];

    test()->actingAs($viewer)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertInertia(function (Assert $page) use (&$captured, $root): void {
            $page->has('messages.data');
            $data = $page->toArray()['props']['messages']['data'];
            $captured = collect($data)->firstWhere('id', $root->id);
        });

    return $captured;
}

test('a followed thread with an unseen reply raises the root unread flag', function (): void {
    [$owner, $team, $general] = threadReadSetup();
    $alice = threadReadMember($team, $general);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $payload = rootPayload($owner, $team, $general, $root);

    expect($payload['threadFollowed'])->toBeTrue()
        ->and($payload['threadUnread'])->toBeTrue();
});

test('a non-participant who was never mentioned does not follow the thread', function (): void {
    [$owner, $team, $general] = threadReadSetup();
    $alice = threadReadMember($team, $general);
    $bob = threadReadMember($team, $general);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $payload = rootPayload($bob, $team, $general, $root);

    expect($payload['threadFollowed'])->toBeFalse()
        ->and($payload['threadUnread'])->toBeFalse();
});

test('replying in a thread makes the user a follower', function (): void {
    [$owner, $team, $general] = threadReadSetup();
    $alice = threadReadMember($team, $general);
    $bob = threadReadMember($team, $general);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($bob)->inThread($root)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $payload = rootPayload($bob, $team, $general, $root);

    expect($payload['threadFollowed'])->toBeTrue()
        ->and($payload['threadUnread'])->toBeTrue();
});

test('a mention inside a reply makes the user a follower', function (): void {
    [$owner, $team, $general] = threadReadSetup();
    $alice = threadReadMember($team, $general);
    $bob = threadReadMember($team, $general);

    $root = Message::factory()->for($general)->for($owner)->create();
    $reply = Message::factory()->for($general)->for($alice)->inThread($root)->create();
    $reply->mentionedUsers()->attach($bob->id);

    $payload = rootPayload($bob, $team, $general, $root);

    expect($payload['threadFollowed'])->toBeTrue()
        ->and($payload['threadUnread'])->toBeTrue();
});

test('a user\'s own replies never raise their unread flag', function (): void {
    [$owner, $team, $general] = threadReadSetup();

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($owner)->inThread($root)->create();

    $payload = rootPayload($owner, $team, $general, $root);

    expect($payload['threadFollowed'])->toBeTrue()
        ->and($payload['threadUnread'])->toBeFalse();
});

test('a soft-deleted reply does not count as unread', function (): void {
    [$owner, $team, $general] = threadReadSetup();
    $alice = threadReadMember($team, $general);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create(['deleted_at' => now()]);

    $payload = rootPayload($owner, $team, $general, $root);

    expect($payload['threadFollowed'])->toBeTrue()
        ->and($payload['threadUnread'])->toBeFalse();
});

test('marking the thread read clears the unread flag and persists the pointer', function (): void {
    [$owner, $team, $general] = threadReadSetup();
    $alice = threadReadMember($team, $general);

    $root = Message::factory()->for($general)->for($owner)->create();
    $reply = Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $this->actingAs($owner)
        ->post(route('channels.threads.read', ['team' => $team->slug, 'channel' => $general->slug, 'message' => $root->id]))
        ->assertRedirect();

    expect(ThreadRead::where('thread_root_id', $root->id)->where('user_id', $owner->id)->value('last_read_reply_id'))
        ->toBe($reply->id);

    $payload = rootPayload($owner, $team, $general, $root);

    expect($payload['threadUnread'])->toBeFalse();
});

test('a newer reply after the read pointer raises the flag again', function (): void {
    [$owner, $team, $general] = threadReadSetup();
    $alice = threadReadMember($team, $general);

    $root = Message::factory()->for($general)->for($owner)->create();
    $first = Message::factory()->for($general)->for($alice)->inThread($root)->create();
    ThreadRead::factory()->for($root, 'root')->for($owner)->upTo($first)->create();

    // Read up to the first reply: nothing unread yet.
    expect(rootPayload($owner, $team, $general, $root)['threadUnread'])->toBeFalse();

    // A later reply lands after the pointer.
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    expect(rootPayload($owner, $team, $general, $root)['threadUnread'])->toBeTrue();
});

test('thread read state is independent of the channel read pointer', function (): void {
    [$owner, $team, $general] = threadReadSetup();
    $alice = threadReadMember($team, $general);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    // Marking the whole channel read must not clear the thread's unread flag.
    app(MarkChannelRead::class)->handle($general, $owner);

    expect(rootPayload($owner, $team, $general, $root)['threadUnread'])->toBeTrue();

    // And marking the thread read must not move the channel's read pointer.
    $channelPointer = $general->channelMembers()->where('user_id', $owner->id)->value('last_read_message_id');

    app(MarkThreadRead::class)->handle($root, $owner);

    expect($general->channelMembers()->where('user_id', $owner->id)->value('last_read_message_id'))
        ->toBe($channelPointer);
});

test('a muted channel suppresses the thread unread flag', function (): void {
    [$owner, $team, $general] = threadReadSetup();
    $alice = threadReadMember($team, $general);

    $general->channelMembers()->where('user_id', $owner->id)->update(['muted' => true]);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $payload = rootPayload($owner, $team, $general, $root);

    expect($payload['threadFollowed'])->toBeTrue()
        ->and($payload['threadUnread'])->toBeFalse();
});

test('a notification level below all suppresses the thread unread flag', function (): void {
    [$owner, $team, $general] = threadReadSetup();
    $alice = threadReadMember($team, $general);

    $general->channelMembers()->where('user_id', $owner->id)
        ->update(['notification_level' => NotificationLevel::Mentions]);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    expect(rootPayload($owner, $team, $general, $root)['threadUnread'])->toBeFalse();
});

test('the thread panel root carries the viewer follow and unread state', function (): void {
    [$owner, $team, $general] = threadReadSetup();
    $alice = threadReadMember($team, $general);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug, 'thread' => $root->id]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('thread.root.threadFollowed', true)
            ->where('thread.root.threadUnread', true));
});

test('marking read is a no-op for a thread with no replies', function (): void {
    [$owner, $team, $general] = threadReadSetup();

    $root = Message::factory()->for($general)->for($owner)->create();

    app(MarkThreadRead::class)->handle($root, $owner);

    expect(ThreadRead::where('thread_root_id', $root->id)->exists())->toBeFalse();
});

test('marking a thread read requires permission to view the channel', function (): void {
    [$owner, $team, $general] = threadReadSetup();
    $root = Message::factory()->for($general)->for($owner)->create();

    $private = Channel::factory()->for($team)->create(['visibility' => 'private']);
    $outsider = threadReadMember($team, $general);
    $privateRoot = Message::factory()->for($private)->for($owner)->create();

    $this->actingAs($outsider)
        ->post(route('channels.threads.read', ['team' => $team->slug, 'channel' => $private->slug, 'message' => $privateRoot->id]))
        ->assertForbidden();
});
