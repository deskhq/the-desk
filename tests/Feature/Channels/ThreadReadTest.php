<?php

use App\Actions\Channels\MarkChannelRead;
use App\Actions\Channels\MarkThreadRead;
use App\Enums\NotificationLevel;
use App\Data\MessageData;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\ThreadRead;
use App\Models\User;
use App\Support\ChannelTimelineWindow;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The root message's payload on the main timeline, as the viewer sees it.
 *
 * Read off {@see ChannelTimelineWindow}, which is what builds the `messages`
 * prop, rather than by rendering `channels/Show` to pluck that prop back out
 * (#1117). Thread-only replies are excluded from the main timeline, so a lone
 * root sits there alone. The HTTP half — that a render still ships the prop —
 * is stated by the last test in this file.
 *
 * @return array<string, mixed>
 */
function rootPayload(User $viewer, Channel $channel, Message $root): array
{
    $payload = collect(new ChannelTimelineWindow($channel, $viewer)->messages()->items())
        ->firstWhere('id', $root->id);

    expect($payload)->toBeInstanceOf(MessageData::class);

    /** @var MessageData $payload */
    return $payload->toArray();
}

test('a followed thread with an unseen reply raises the root unread flag', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $alice = teamMemberInChannel($general);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $payload = rootPayload($owner, $general, $root);

    expect($payload['threadFollowed'])->toBeTrue()
        ->and($payload['threadUnread'])->toBeTrue()
        ->and($payload['threadUnreadReplyCount'])->toBe(1);
});

test('a non-participant who was never mentioned does not follow the thread', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $alice = teamMemberInChannel($general);
    $bob = teamMemberInChannel($general);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $payload = rootPayload($bob, $general, $root);

    expect($payload['threadFollowed'])->toBeFalse()
        ->and($payload['threadUnread'])->toBeFalse()
        // A thread nobody follows has nothing "new" to report, whatever the
        // replies say.
        ->and($payload['threadUnreadReplyCount'])->toBe(0);
});

test('replying in a thread makes the user a follower', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $alice = teamMemberInChannel($general);
    $bob = teamMemberInChannel($general);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($bob)->inThread($root)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $payload = rootPayload($bob, $general, $root);

    expect($payload['threadFollowed'])->toBeTrue()
        ->and($payload['threadUnread'])->toBeTrue();
});

test('a mention inside a reply makes the user a follower', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $alice = teamMemberInChannel($general);
    $bob = teamMemberInChannel($general);

    $root = Message::factory()->for($general)->for($owner)->create();
    $reply = Message::factory()->for($general)->for($alice)->inThread($root)->create();
    $reply->mentionedUsers()->attach($bob->id);

    $payload = rootPayload($bob, $general, $root);

    expect($payload['threadFollowed'])->toBeTrue()
        ->and($payload['threadUnread'])->toBeTrue();
});

test('a user\'s own replies never raise their unread flag', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($owner)->inThread($root)->create();

    $payload = rootPayload($owner, $general, $root);

    expect($payload['threadFollowed'])->toBeTrue()
        ->and($payload['threadUnread'])->toBeFalse();
});

test('a soft-deleted reply does not count as unread', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $alice = teamMemberInChannel($general);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create(['deleted_at' => now()]);

    $payload = rootPayload($owner, $general, $root);

    expect($payload['threadFollowed'])->toBeTrue()
        ->and($payload['threadUnread'])->toBeFalse();
});

test('marking the thread read clears the unread flag and persists the pointer', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $alice = teamMemberInChannel($general);

    $root = Message::factory()->for($general)->for($owner)->create();
    $reply = Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $this->actingAs($owner)
        ->post(route('channels.threads.read', ['team' => $team->slug, 'channel' => $general->slug, 'message' => $root->id]))
        ->assertRedirect();

    expect(ThreadRead::where('thread_root_id', $root->id)->where('user_id', $owner->id)->value('last_read_reply_id'))
        ->toBe($reply->id);

    $payload = rootPayload($owner, $general, $root);

    expect($payload['threadUnread'])->toBeFalse();
});

test('a newer reply after the read pointer raises the flag again', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $alice = teamMemberInChannel($general);

    $root = Message::factory()->for($general)->for($owner)->create();
    $first = Message::factory()->for($general)->for($alice)->inThread($root)->create();
    ThreadRead::factory()->for($root, 'root')->for($owner)->upTo($first)->create();

    // Read up to the first reply: nothing unread yet.
    expect(rootPayload($owner, $general, $root)['threadUnread'])->toBeFalse();

    // A later reply lands after the pointer.
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    expect(rootPayload($owner, $general, $root)['threadUnread'])->toBeTrue();
});

test('thread read state is independent of the channel read pointer', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $alice = teamMemberInChannel($general);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    // Marking the whole channel read must not clear the thread's unread flag.
    app(MarkChannelRead::class)->handle($general, $owner);

    expect(rootPayload($owner, $general, $root)['threadUnread'])->toBeTrue();

    // And marking the thread read must not move the channel's read pointer.
    $channelPointer = $general->channelMembers()->where('user_id', $owner->id)->value('last_read_message_id');

    app(MarkThreadRead::class)->handle($root, $owner);

    expect($general->channelMembers()->where('user_id', $owner->id)->value('last_read_message_id'))
        ->toBe($channelPointer);
});

test('a muted channel suppresses the thread unread flag', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $alice = teamMemberInChannel($general);

    $general->channelMembers()->where('user_id', $owner->id)->update(['muted' => true]);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $payload = rootPayload($owner, $general, $root);

    expect($payload['threadFollowed'])->toBeTrue()
        ->and($payload['threadUnread'])->toBeFalse();
});

test('a notification level below all suppresses the thread unread flag', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $alice = teamMemberInChannel($general);

    $general->channelMembers()->where('user_id', $owner->id)
        ->update(['notification_level' => NotificationLevel::Mentions]);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    expect(rootPayload($owner, $general, $root)['threadUnread'])->toBeFalse();
});

test('a mention inside a thread raises its dot on a mentions-level channel', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $alice = teamMemberInChannel($general);

    $general->channelMembers()->where('user_id', $owner->id)
        ->update(['notification_level' => NotificationLevel::Mentions]);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create()
        ->mentionedUsers()->attach($owner->id);

    // The "mentions" level silences ordinary traffic, never a mention — the one
    // thread the viewer was explicitly pulled into is the last one to go quiet.
    $payload = rootPayload($owner, $general, $root);

    expect($payload['threadUnread'])->toBeTrue()
        ->and($payload['threadUnreadReplyCount'])->toBe(1);
});

test('the reply count on a mentions-level channel counts only the mentions', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $alice = teamMemberInChannel($general);

    $general->channelMembers()->where('user_id', $owner->id)
        ->update(['notification_level' => NotificationLevel::Mentions]);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create()
        ->mentionedUsers()->attach($owner->id);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    // At this level the ordinary reply alerts nobody, so counting it would
    // promise a dot's worth of news the channel itself refuses to badge.
    expect(rootPayload($owner, $general, $root)['threadUnreadReplyCount'])->toBe(1);
});

test('muting a channel silences a mention inside a thread', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $alice = teamMemberInChannel($general);

    $general->channelMembers()->where('user_id', $owner->id)->update(['muted' => true]);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create()
        ->mentionedUsers()->attach($owner->id);

    expect(rootPayload($owner, $general, $root)['threadUnread'])->toBeFalse();
});

test('the nothing level silences a mention inside a thread', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $alice = teamMemberInChannel($general);

    $general->channelMembers()->where('user_id', $owner->id)
        ->update(['notification_level' => NotificationLevel::Nothing]);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create()
        ->mentionedUsers()->attach($owner->id);

    expect(rootPayload($owner, $general, $root)['threadUnread'])->toBeFalse();
});

test('the thread panel root carries the viewer follow and unread state', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $alice = teamMemberInChannel($general);

    $root = Message::factory()->for($general)->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug, 'thread' => $root->id]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('thread.root.threadFollowed', true)
            ->where('thread.root.threadUnread', true));
});

test('marking read is a no-op for a thread with no replies', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $root = Message::factory()->for($general)->for($owner)->create();

    app(MarkThreadRead::class)->handle($root, $owner);

    expect(ThreadRead::where('thread_root_id', $root->id)->exists())->toBeFalse();
});

test('marking a thread read requires permission to view the channel', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $root = Message::factory()->for($general)->for($owner)->create();

    $private = Channel::factory()->for($team)->create(['visibility' => 'private']);
    $outsider = teamMemberInChannel($general);
    $privateRoot = Message::factory()->for($private)->for($owner)->create();

    $this->actingAs($outsider)
        ->post(route('channels.threads.read', ['team' => $team->slug, 'channel' => $private->slug, 'message' => $privateRoot->id]))
        ->assertForbidden();
});
