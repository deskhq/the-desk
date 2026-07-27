<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Events\ReadStateAdvanced;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\ThreadRead;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function markAllSetup(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Add a user to the team and the given channel, returning them.
 */
function markAllMember(Team $team, Channel $channel): User
{
    $user = User::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);
    $channel->channelMembers()->firstOrCreate(['user_id' => $user->id]);

    return $user;
}

/**
 * A followed thread whose single reply is by someone else, so it reads as unread
 * for the root's author. Returns the root.
 */
function markAllThread(Channel $channel, User $author, User $replier): Message
{
    $root = Message::factory()->for($channel)->for($author)->create([
        'reply_count' => 1,
        'last_reply_at' => now(),
    ]);

    Message::factory()->for($channel)->for($replier)->inThread($root)->create();

    return $root;
}

/** Post "mark all read" as the given user. */
function markAllRead(User $viewer, Team $team): TestResponse
{
    return test()->actingAs($viewer)
        ->post(route('channels.threads.readAll', ['team' => $team->slug]));
}

/**
 * The Threads panel's props as the given viewer sees them, for asserting what
 * the bulk write left behind.
 *
 * @return array<string, mixed>
 */
function markAllPanelProps(User $viewer, Team $team): array
{
    $captured = [];

    $url = route('channels.show', [
        'team' => $team->slug,
        'channel' => Channel::GENERAL_SLUG,
    ]).'?nav=threads&filter=all';

    test()->actingAs($viewer)->get($url)
        ->assertInertia(function (Assert $page) use (&$captured): void {
            $captured = $page->toArray()['props'];
        });

    return $captured;
}

test('marking all read advances the pointer on every unread followed thread', function (): void {
    [$owner, $team, $general] = markAllSetup();
    $alice = markAllMember($team, $general);

    $first = markAllThread($general, $owner, $alice);
    $second = markAllThread($general, $owner, $alice);

    markAllRead($owner, $team)->assertRedirect();

    $props = markAllPanelProps($owner, $team);

    expect($props['hasUnreadThreads'])->toBeFalse()
        ->and($props['unreadThreadCount'])->toBe(0)
        ->and($props['threads']['data'])->toHaveCount(2)
        ->and(collect($props['threads']['data'])->pluck('root.threadUnread')->all())->toBe([false, false])
        ->and(collect($props['threads']['data'])->pluck('root.threadUnreadReplyCount')->all())->toBe([0, 0])
        // Every unread thread now carries the viewer's pointer, at the reply that
        // was the tail when they cleared the inbox.
        ->and(ThreadRead::where('user_id', $owner->id)->pluck('thread_root_id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all());
});

test('marking all read points past a deleted tail', function (): void {
    [$owner, $team, $general] = markAllSetup();
    $alice = markAllMember($team, $general);

    $root = markAllThread($general, $owner, $alice);
    // A reply deleted after it landed still moved the thread on, so the pointer
    // has to reach it — stopping at the last live reply would leave the thread
    // unread forever.
    $deleted = Message::factory()->for($general)->for($alice)->inThread($root)->create(['deleted_at' => now()]);

    markAllRead($owner, $team);

    expect(ThreadRead::where('user_id', $owner->id)->where('thread_root_id', $root->id)->value('last_read_reply_id'))
        ->toBe($deleted->id)
        ->and(markAllPanelProps($owner, $team)['hasUnreadThreads'])->toBeFalse();
});

test('marking all read leaves a thread in a channel the viewer cannot see alone', function (): void {
    [$owner, $team] = markAllSetup();

    // A private channel the owner is not a member of, holding a thread they were
    // mentioned in: the ACL that keeps it out of their inbox must also keep the
    // bulk write off it.
    $secret = Channel::factory()->for($team)->create(['visibility' => 'private']);
    $insider = markAllMember($team, $secret);
    $hidden = markAllThread($secret, $insider, $insider);
    $hidden->threadReplies()->first()->mentionedUsers()->attach($owner->id);

    markAllRead($owner, $team);

    expect(ThreadRead::where('user_id', $owner->id)->where('thread_root_id', $hidden->id)->exists())
        ->toBeFalse();
});

test('marking all read leaves other members read state untouched', function (): void {
    [$owner, $team, $general] = markAllSetup();
    $alice = markAllMember($team, $general);

    // Alice authored the root and both of them replied, so the thread is followed
    // by — and unread for — each of them.
    $root = markAllThread($general, $alice, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    markAllRead($owner, $team);

    expect(ThreadRead::where('user_id', $alice->id)->exists())->toBeFalse()
        ->and(markAllPanelProps($alice, $team)['hasUnreadThreads'])->toBeTrue()
        ->and(ThreadRead::where('user_id', $owner->id)->where('thread_root_id', $root->id)->exists())
        ->toBeTrue();
});

test('marking all read reaches the viewer other devices for each channel it touched', function (): void {
    Event::fake([ReadStateAdvanced::class]);

    [$owner, $team, $general] = markAllSetup();
    $alice = markAllMember($team, $general);
    $other = Channel::factory()->for($team)->create();
    $other->channelMembers()->createMany([['user_id' => $owner->id], ['user_id' => $alice->id]]);

    markAllThread($general, $owner, $alice);
    markAllThread($other, $owner, $alice);

    markAllRead($owner, $team);

    Event::assertDispatchedTimes(ReadStateAdvanced::class, 2);
    Event::assertDispatched(ReadStateAdvanced::class, fn (ReadStateAdvanced $event): bool => $event->userId === $owner->id
        && $event->channelId === $general->id);
    Event::assertDispatched(ReadStateAdvanced::class, fn (ReadStateAdvanced $event): bool => $event->userId === $owner->id
        && $event->channelId === $other->id);
});

test('marking an already clear inbox read writes nothing and signals nothing', function (): void {
    Event::fake([ReadStateAdvanced::class]);

    [$owner, $team, $general] = markAllSetup();
    $alice = markAllMember($team, $general);

    // Followed, but the only reply is the viewer's own, so nothing is unread.
    markAllThread($general, $alice, $owner);

    markAllRead($owner, $team);

    expect(ThreadRead::where('user_id', $owner->id)->exists())->toBeFalse();
    Event::assertNotDispatched(ReadStateAdvanced::class);
});

test('marking all read demands a member of the team', function (): void {
    [, $team] = markAllSetup();
    $outsider = User::factory()->create();

    markAllRead($outsider, $team)->assertForbidden();
});
