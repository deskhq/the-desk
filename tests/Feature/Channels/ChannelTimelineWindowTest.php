<?php

use App\Actions\Teams\CreateTeam;
use App\Data\MessageData;
use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Support\ChannelTimelineWindow;
use Illuminate\Support\Collection;

/**
 * A user in a fresh team with its auto-created #general channel. The window
 * read-model is exercised directly against these — no HTTP round-trip.
 *
 * @return array{user: User, team: Team, general: Channel}
 */
function windowFixture(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return ['user' => $user, 'team' => $team, 'general' => $general];
}

/**
 * Create $count top-level messages in the channel, bodies "message 1".."message N",
 * in ascending (ordered-uuid) id order.
 *
 * @return Collection<int, Message>
 */
function windowMessages(Channel $channel, User $author, int $count): Collection
{
    return collect(range(1, $count))->map(
        fn (int $i) => Message::factory()->for($channel)->for($author)->create(['body' => "message {$i}"])
    );
}

/**
 * The bodies of a window's main timeline page, newest first.
 *
 * @return array<int, string>
 */
function bodiesOf(ChannelTimelineWindow $window): array
{
    return collect($window->messages()->items())
        ->map(fn (MessageData $message): string => $message->body)
        ->all();
}

it('renders system notices in the timeline but never as the unread boundary', function (): void {
    ['user' => $user, 'general' => $general] = windowFixture();
    $messages = windowMessages($general, $user, 3);
    $notice = Message::factory()->for($general)->for($user)->memberLeft()->create();

    // Read through message 3; the only thing after it is the ambient notice, so
    // the boundary never opens and the window stays at newest — yet the notice
    // still loads into the page so the timeline can render it.
    $window = new ChannelTimelineWindow(
        channel: $general,
        viewer: $user,
        lastReadMessageId: $messages[2]->id,
    );

    expect($window->ceilingId())->toBeNull();

    $types = collect($window->messages()->items())
        ->map(fn (MessageData $message): MessageType => $message->type);

    expect($types)->toContain(MessageType::MemberLeft)
        ->and($window->messages()->items()[0]->id)->toBe($notice->id);
});

it('anchors the unread boundary on a first-unread poll', function (): void {
    ['user' => $user, 'general' => $general] = windowFixture();
    $read = windowMessages($general, $user, 30);
    Message::factory()->for($general)->for($user)->poll()->create();
    $unread = windowMessages($general, $user, 70);

    $window = new ChannelTimelineWindow(
        channel: $general,
        viewer: $user,
        lastReadMessageId: $read[29]->id,
    );

    // The poll is the first unread message, so it opens the boundary and the
    // window caps 39 rows past it — one row earlier than it would had the
    // boundary skipped the poll and landed on the message after it.
    expect($window->ceilingId())->toBe($unread[39]->id);
});

it('opens at newest with no ceiling on a never-read channel', function (): void {
    ['user' => $user, 'general' => $general] = windowFixture();
    windowMessages($general, $user, 60);

    // Null read pointer: nothing to anchor, so the default newest window holds.
    $window = new ChannelTimelineWindow(channel: $general, viewer: $user);

    expect($window->ceilingId())->toBeNull()
        ->and($window->jumpToMessageId())->toBeNull();

    $bodies = bodiesOf($window);
    expect($bodies)->toHaveCount(50)
        ->and($bodies[0])->toBe('message 60')
        ->and($bodies[49])->toBe('message 11');
});

it('opens at newest with no ceiling on a fully-read channel', function (): void {
    ['user' => $user, 'general' => $general] = windowFixture();
    $messages = windowMessages($general, $user, 60);

    // Read pointer at the newest message: nothing is unread.
    $window = new ChannelTimelineWindow(
        channel: $general,
        viewer: $user,
        lastReadMessageId: $messages[59]->id,
    );

    expect($window->ceilingId())->toBeNull();
    expect(bodiesOf($window))->toHaveCount(50);
});

it('keeps the newest window when unread fits within a page', function (): void {
    ['user' => $user, 'general' => $general] = windowFixture();
    $messages = windowMessages($general, $user, 60);

    // Read through message 20: 40 unread fit inside one 50-row page.
    $window = new ChannelTimelineWindow(
        channel: $general,
        viewer: $user,
        lastReadMessageId: $messages[19]->id,
    );

    expect($window->ceilingId())->toBeNull();

    $bodies = bodiesOf($window);
    expect($bodies[0])->toBe('message 60')
        ->and($bodies[49])->toBe('message 11');
});

it('anchors the window around the boundary when unread exceeds a page', function (): void {
    ['user' => $user, 'general' => $general] = windowFixture();
    $messages = windowMessages($general, $user, 100);

    // Read through message 30: 70 unread exceed one page, so the window caps
    // 39 messages past the boundary (message 71) and holds messages 22..71.
    $window = new ChannelTimelineWindow(
        channel: $general,
        viewer: $user,
        lastReadMessageId: $messages[29]->id,
    );

    expect($window->ceilingId())->toBe($messages[70]->id);

    $bodies = bodiesOf($window);
    expect($bodies)->toHaveCount(50)
        ->and($bodies[0])->toBe('message 71')
        ->and($bodies[49])->toBe('message 22');
});

it('windows a jump target with newer context below it', function (): void {
    ['user' => $user, 'general' => $general] = windowFixture();
    $messages = windowMessages($general, $user, 30);
    $target = $messages[4]; // message 5

    $window = new ChannelTimelineWindow(
        channel: $general,
        viewer: $user,
        requestedJumpId: $target->id,
    );

    expect($window->jumpToMessageId())->toBe($target->id)
        // 15 messages newer than the target cap the window at message 20.
        ->and($window->ceilingId())->toBe($messages[19]->id);

    $bodies = bodiesOf($window);
    expect($bodies)->toHaveCount(20)
        ->and($bodies[0])->toBe('message 20');
});

it('leaves a jump near the newest message uncapped', function (): void {
    ['user' => $user, 'general' => $general] = windowFixture();
    $messages = windowMessages($general, $user, 5);
    $target = $messages[4]; // the newest

    $window = new ChannelTimelineWindow(
        channel: $general,
        viewer: $user,
        requestedJumpId: $target->id,
    );

    // Fewer than JUMP_CONTEXT newer messages exist, so no ceiling is needed.
    expect($window->jumpToMessageId())->toBe($target->id)
        ->and($window->ceilingId())->toBeNull();
    expect(bodiesOf($window))->toHaveCount(5);
});

it('ignores a jump target from another channel', function (): void {
    ['user' => $user, 'team' => $team, 'general' => $general] = windowFixture();
    windowMessages($general, $user, 3);
    $other = Channel::factory()->for($team)->create(['created_by' => $user->id]);
    $foreign = Message::factory()->for($other)->for($user)->create();

    $window = new ChannelTimelineWindow(
        channel: $general,
        viewer: $user,
        requestedJumpId: $foreign->id,
    );

    expect($window->jumpToMessageId())->toBeNull()
        ->and($window->ceilingId())->toBeNull();
});

it('ignores an absent or non-string jump target', function (): void {
    ['user' => $user, 'general' => $general] = windowFixture();
    windowMessages($general, $user, 3);

    expect((new ChannelTimelineWindow(channel: $general, viewer: $user))->jumpToMessageId())->toBeNull();
    // A tampered array query value never resolves to a target.
    expect((new ChannelTimelineWindow(channel: $general, viewer: $user, requestedJumpId: ['x']))->jumpToMessageId())
        ->toBeNull();
    expect((new ChannelTimelineWindow(channel: $general, viewer: $user, requestedJumpId: ''))->jumpToMessageId())
        ->toBeNull();
});

it('includes thread replies sent to the channel and excludes those that are not', function (): void {
    ['user' => $user, 'general' => $general] = windowFixture();
    $root = Message::factory()->for($general)->for($user)->create(['body' => 'root']);
    Message::factory()->for($user)->inThread($root)->sentToChannel()->create(['body' => 'echoed reply']);
    Message::factory()->for($user)->inThread($root)->create(['body' => 'thread-only reply']);

    $window = new ChannelTimelineWindow(channel: $general, viewer: $user);

    $bodies = bodiesOf($window);
    expect($bodies)->toContain('root')
        ->and($bodies)->toContain('echoed reply')
        ->and($bodies)->not->toContain('thread-only reply');
});

it('resolves the open thread root and its replies', function (): void {
    ['user' => $user, 'general' => $general] = windowFixture();
    $root = Message::factory()->for($general)->for($user)->create(['body' => 'root']);
    Message::factory()->for($user)->inThread($root)->create(['body' => 'reply one']);

    $window = new ChannelTimelineWindow(
        channel: $general,
        viewer: $user,
        requestedThreadRootId: $root->id,
    );

    $thread = $window->thread();
    expect($thread)->not->toBeNull()
        ->and($thread['root'])->toBeInstanceOf(MessageData::class)
        ->and($thread['root']->id)->toBe($root->id);

    $replyBodies = collect($window->threadReplies()->items())
        ->map(fn (MessageData $message): string => $message->body)
        ->all();
    expect($replyBodies)->toBe(['reply one']);
});

it('returns no thread and an empty replies page without a thread param', function (): void {
    ['user' => $user, 'general' => $general] = windowFixture();
    windowMessages($general, $user, 2);

    $window = new ChannelTimelineWindow(channel: $general, viewer: $user);

    expect($window->thread())->toBeNull()
        ->and($window->threadReplies()->items())->toBe([]);
});

it('returns no thread for a non-string or foreign thread param', function (): void {
    ['user' => $user, 'team' => $team, 'general' => $general] = windowFixture();
    $other = Channel::factory()->for($team)->create(['created_by' => $user->id]);
    $foreignRoot = Message::factory()->for($other)->for($user)->create();

    // A tampered array query value resolves to no thread.
    expect((new ChannelTimelineWindow(channel: $general, viewer: $user, requestedThreadRootId: ['x']))->thread())
        ->toBeNull();
    // A root in another channel is not this channel's thread.
    expect((new ChannelTimelineWindow(channel: $general, viewer: $user, requestedThreadRootId: $foreignRoot->id))->thread())
        ->toBeNull();
});
