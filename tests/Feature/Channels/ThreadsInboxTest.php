<?php

use App\Actions\Channels\MarkThreadRead;
use App\Actions\Teams\CreateTeam;
use App\Enums\NotificationLevel;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function inboxSetup(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Add a user to the team and the given channel, returning them.
 */
function inboxMember(Team $team, Channel $channel): User
{
    $user = User::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);
    $channel->channelMembers()->firstOrCreate(['user_id' => $user->id]);

    return $user;
}

/**
 * Create a thread root carrying the denormalized reply aggregates the inbox
 * filters and orders on, as PostMessage would maintain them in production.
 */
function inboxRoot(Channel $channel, User $author, ?CarbonInterface $lastReplyAt = null, array $attributes = []): Message
{
    return Message::factory()->for($channel)->for($author)->create(array_merge([
        'reply_count' => 1,
        'last_reply_at' => $lastReplyAt ?? now(),
    ], $attributes));
}

/**
 * Load the Threads inbox as the given user and return the `threads.data` rows.
 *
 * @return array<int, array<string, mixed>>
 */
function inboxRows(User $viewer, Team $team): array
{
    $captured = [];

    test()->actingAs($viewer)
        ->get(route('channels.threads.index', ['team' => $team->slug]))
        ->assertInertia(function (Assert $page) use (&$captured) {
            $page->component('channels/Threads');
            $captured = $page->toArray()['props']['threads']['data'];
        });

    return $captured;
}

test('the inbox lists a thread the user authored the root of', function () {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $root = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $rows = inboxRows($owner, $team);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['root']['id'])->toBe($root->id)
        ->and($rows[0]['channelName'])->toBe($general->name)
        ->and($rows[0]['root']['threadUnread'])->toBeTrue();
});

test('the inbox lists a thread the user replied in', function () {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $root = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    // Alice replied, so she follows; a reply by someone else would be unread,
    // but here she is caught up (only her own reply exists).
    $rows = inboxRows($alice, $team);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['root']['id'])->toBe($root->id)
        ->and($rows[0]['root']['threadUnread'])->toBeFalse();
});

test('the inbox lists a thread the user was mentioned in', function () {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);
    $bob = inboxMember($team, $general);

    $root = inboxRoot($general, $owner);
    $reply = Message::factory()->for($general)->for($alice)->inThread($root)->create();
    $reply->mentionedUsers()->attach($bob->id);

    $rows = inboxRows($bob, $team);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['root']['id'])->toBe($root->id)
        ->and($rows[0]['root']['threadUnread'])->toBeTrue();
});

test('the inbox excludes threads the user does not follow', function () {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);
    $bob = inboxMember($team, $general);

    $root = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    // Bob never authored, replied, or was mentioned.
    expect(inboxRows($bob, $team))->toBeEmpty();
});

test('the inbox excludes roots with no replies and deleted roots', function () {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    // A root the owner started but nobody has replied to yet.
    Message::factory()->for($general)->for($owner)->create(['reply_count' => 0]);

    // A deleted root, even with replies, should not surface.
    $deleted = inboxRoot($general, $owner, attributes: ['deleted_at' => now()]);
    Message::factory()->for($general)->for($alice)->inThread($deleted)->create();

    expect(inboxRows($owner, $team))->toBeEmpty();
});

test('the inbox only lists threads in channels the user belongs to', function () {
    [$owner, $team, $general] = inboxSetup();

    // A private channel the owner is NOT a member of, with a thread they were
    // even mentioned in — it must not leak into their inbox.
    $secret = Channel::factory()->for($team)->create(['visibility' => 'private']);
    $insider = inboxMember($team, $secret);
    $root = inboxRoot($secret, $insider);
    $reply = Message::factory()->for($secret)->for($insider)->inThread($root)->create();
    $reply->mentionedUsers()->attach($owner->id);

    expect(inboxRows($owner, $team))->toBeEmpty();
});

test('the inbox orders threads by most recent reply first', function () {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $older = inboxRoot($general, $owner, now()->subHour());
    Message::factory()->for($general)->for($alice)->inThread($older)->create();

    $newer = inboxRoot($general, $owner, now());
    Message::factory()->for($general)->for($alice)->inThread($newer)->create();

    $rows = inboxRows($owner, $team);

    expect($rows[0]['root']['id'])->toBe($newer->id)
        ->and($rows[1]['root']['id'])->toBe($older->id);
});

test('a muted channel lists its threads without an unread dot', function () {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $general->channelMembers()->where('user_id', $owner->id)->update(['muted' => true]);

    $root = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $rows = inboxRows($owner, $team);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['root']['threadUnread'])->toBeFalse();
});

test('opening and reading a thread clears its unread dot in the inbox', function () {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $root = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    expect(inboxRows($owner, $team)[0]['root']['threadUnread'])->toBeTrue();

    app(MarkThreadRead::class)->handle($root, $owner);

    expect(inboxRows($owner, $team)[0]['root']['threadUnread'])->toBeFalse();
});

test('the inbox is empty when the user follows no threads', function () {
    [$owner, $team, $general] = inboxSetup();

    expect(inboxRows($owner, $team))->toBeEmpty();
});

test('hasUnreadThreads flags an unread followed thread and clears when read', function () {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $root = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $this->actingAs($owner)
        ->get(route('channels.threads.index', ['team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('hasUnreadThreads', true));

    app(MarkThreadRead::class)->handle($root, $owner);

    $this->actingAs($owner)
        ->get(route('channels.threads.index', ['team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('hasUnreadThreads', false));
});

test('hasUnreadThreads ignores threads the user does not follow', function () {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);
    $bob = inboxMember($team, $general);

    $root = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $this->actingAs($bob)
        ->get(route('channels.threads.index', ['team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('hasUnreadThreads', false));
});

test('hasUnreadThreads respects channel mute', function () {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $general->channelMembers()->where('user_id', $owner->id)
        ->update(['notification_level' => NotificationLevel::Mentions]);

    $root = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $this->actingAs($owner)
        ->get(route('channels.threads.index', ['team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('hasUnreadThreads', false));
});
