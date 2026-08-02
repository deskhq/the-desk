<?php

use App\Actions\Channels\MarkThreadRead;
use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Teams\CreateTeam;
use App\Enums\MessageType;
use App\Enums\NotificationLevel;
use App\Enums\TeamRole;
use App\Enums\ThreadInboxFilter;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Poll;
use App\Models\PollVote;
use App\Models\Team;
use App\Models\ThreadRead;
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
 * The workspace URL that pins the Threads destination, which is what brings the
 * panel's inbox along with the shell route's props.
 */
function inboxUrl(Team $team, ?ThreadInboxFilter $filter = null): string
{
    $query = $filter instanceof ThreadInboxFilter
        ? ['nav' => 'threads', 'filter' => $filter->value]
        : ['nav' => 'threads'];

    return route('channels.show', [
        'team' => $team->slug,
        'channel' => Channel::GENERAL_SLUG,
    ]).'?'.http_build_query($query);
}

/**
 * Open the Threads panel as the given user and return the whole prop set, so a
 * test can read the inbox page, its unread tally, or the rail's dot flag.
 *
 * @return array<string, mixed>
 */
function inboxProps(User $viewer, Team $team, ?ThreadInboxFilter $filter = null): array
{
    $captured = [];

    test()->actingAs($viewer)
        ->get(inboxUrl($team, $filter))
        ->assertInertia(function (Assert $page) use (&$captured): void {
            $page->component('channels/Show');
            $captured = $page->toArray()['props'];
        });

    return $captured;
}

/**
 * Open the Threads panel as the given user and return the `threads.data` rows.
 *
 * @return array<int, array<string, mixed>>
 */
function inboxRows(User $viewer, Team $team, ?ThreadInboxFilter $filter = ThreadInboxFilter::All): array
{
    return inboxProps($viewer, $team, $filter)['threads']['data'];
}

test('the inbox lists a thread the user authored the root of', function (): void {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $root = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $rows = inboxRows($owner, $team);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['root']['id'])->toBe($root->id)
        ->and($rows[0]['channelName'])->toBe($general->name)
        ->and($rows[0]['isDirectMessage'])->toBeFalse()
        ->and($rows[0]['root']['threadUnread'])->toBeTrue();
});

test('the inbox lists a thread the user replied in', function (): void {
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

test('the inbox lists a thread the user was mentioned in', function (): void {
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

test('the inbox excludes threads the user does not follow', function (): void {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);
    $bob = inboxMember($team, $general);

    $root = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    // Bob never authored, replied, or was mentioned.
    expect(inboxRows($bob, $team))->toBeEmpty();
});

test('the inbox excludes roots with no replies and deleted roots', function (): void {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    // A root the owner started but nobody has replied to yet.
    Message::factory()->for($general)->for($owner)->create(['reply_count' => 0]);

    // A deleted root, even with replies, should not surface.
    $deleted = inboxRoot($general, $owner, attributes: ['deleted_at' => now()]);
    Message::factory()->for($general)->for($alice)->inThread($deleted)->create();

    expect(inboxRows($owner, $team))->toBeEmpty();
});

test('the inbox only lists threads in channels the user belongs to', function (): void {
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

test('the inbox orders threads by most recent reply first', function (): void {
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

test('a muted channel lists its threads without an unread dot', function (): void {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $general->channelMembers()->where('user_id', $owner->id)->update(['muted' => true]);

    $root = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    $rows = inboxRows($owner, $team);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['root']['threadUnread'])->toBeFalse()
        ->and($rows[0]['root']['threadUnreadReplyCount'])->toBe(0);
});

test('a mention inside a thread dots its card on a mentions-level channel', function (): void {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $general->channelMembers()->where('user_id', $owner->id)
        ->update(['notification_level' => NotificationLevel::Mentions]);

    $root = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create()
        ->mentionedUsers()->attach($owner->id);

    // The channel badges and the chime fires for this mention, so the thread it
    // landed in has to agree — the inbox is where the viewer goes to find it.
    $props = inboxProps($owner, $team, ThreadInboxFilter::Unread);

    expect($props['threads']['data'])->toHaveCount(1)
        ->and($props['threads']['data'][0]['root']['threadUnread'])->toBeTrue()
        ->and($props['threads']['data'][0]['root']['threadUnreadReplyCount'])->toBe(1)
        ->and($props['unreadThreadCount'])->toBe(1)
        ->and($props['hasUnreadThreads'])->toBeTrue();
});

test('opening and reading a thread clears its unread dot in the inbox', function (): void {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $root = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    expect(inboxRows($owner, $team)[0]['root']['threadUnread'])->toBeTrue();

    app(MarkThreadRead::class)->handle($root, $owner);

    expect(inboxRows($owner, $team)[0]['root']['threadUnread'])->toBeFalse();
});

test('the inbox is empty when the user follows no threads', function (): void {
    [$owner, $team] = inboxSetup();

    expect(inboxRows($owner, $team))->toBeEmpty();
});

test('the panel props stay off a workspace route that pins no destination', function (): void {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $root = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    // The inbox is a real query with a 30-row payload, so it rides along only
    // when the dock actually has the destination open.
    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->missing('threads')
            ->missing('unreadThreadCount'));
});

test('the unread filter is the default and hides threads with nothing new', function (): void {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $unread = inboxRoot($general, $owner, now());
    Message::factory()->for($general)->for($alice)->inThread($unread)->create();

    $read = inboxRoot($general, $owner, now()->subHour());
    Message::factory()->for($general)->for($alice)->inThread($read)->create();
    app(MarkThreadRead::class)->handle($read, $owner);

    $default = inboxRows($owner, $team, filter: null);

    expect($default)->toHaveCount(1)
        ->and($default[0]['root']['id'])->toBe($unread->id);

    $explicit = inboxRows($owner, $team, ThreadInboxFilter::Unread);

    expect($explicit)->toHaveCount(1)
        ->and($explicit[0]['root']['id'])->toBe($unread->id);

    // "All" is the pill that brings the caught-up threads back.
    expect(inboxRows($owner, $team, ThreadInboxFilter::All))->toHaveCount(2);
});

test('paging keeps the active filter', function (): void {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    // A page holds 30 cards, so 31 unread threads plus one the owner has read
    // prove the second page still asks the same question the first did.
    foreach (range(1, 31) as $minutesAgo) {
        $root = inboxRoot($general, $owner, now()->subMinutes($minutesAgo));
        Message::factory()->for($general)->for($alice)->inThread($root)->create();
    }

    $read = inboxRoot($general, $owner, now()->subHours(2));
    Message::factory()->for($general)->for($alice)->inThread($read)->create();
    app(MarkThreadRead::class)->handle($read, $owner);

    $firstPage = inboxProps($owner, $team)['threads'];

    expect($firstPage['data'])->toHaveCount(30)
        ->and($firstPage['next_cursor'])->not->toBeNull();

    $captured = [];

    $this->actingAs($owner)
        ->get(inboxUrl($team).'&'.http_build_query(['cursor' => $firstPage['next_cursor']]))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$captured): void {
            $captured = $page->toArray()['props']['threads'];
        });

    expect($captured['data'])->toHaveCount(1)
        ->and($captured['data'][0]['root']['id'])->not->toBe($read->id);
});

test('an unrecognised filter falls back to unread', function (): void {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $read = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($read)->create();
    app(MarkThreadRead::class)->handle($read, $owner);

    $url = route('channels.show', [
        'team' => $team->slug,
        'channel' => Channel::GENERAL_SLUG,
    ]).'?nav=threads&filter=everything';

    $this->actingAs($owner)
        ->get($url)
        ->assertInertia(fn (Assert $page): Assert => $page->where('threads.data', []));
});

test('the unread count tallies only unread followed threads', function (): void {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $first = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($first)->create();

    $second = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($second)->create();

    // A thread the owner is caught up on sits outside the tally.
    $read = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($read)->create();
    app(MarkThreadRead::class)->handle($read, $owner);

    expect(inboxProps($owner, $team)['unreadThreadCount'])->toBe(2);
});

test('the unread count ignores a muted channel', function (): void {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $general->channelMembers()->where('user_id', $owner->id)->update(['muted' => true]);

    $root = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    expect(inboxProps($owner, $team)['unreadThreadCount'])->toBe(0);
});

test('a thread reports how many replies are new to the viewer', function (): void {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $root = inboxRoot($general, $owner, attributes: ['reply_count' => 5]);
    $first = Message::factory()->for($general)->for($alice)->inThread($root)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create();
    // The viewer's own reply is never new to them.
    Message::factory()->for($general)->for($owner)->inThread($root)->create();
    // Neither is a deleted one.
    Message::factory()->for($general)->for($alice)->inThread($root)->create(['deleted_at' => now()]);

    expect(inboxRows($owner, $team)[0]['root'])
        ->threadUnreadReplyCount->toBe(3)
        ->threadReplyCount->toBe(5);

    // Reading part of the thread leaves only what landed after the pointer.
    ThreadRead::create([
        'thread_root_id' => $root->id,
        'user_id' => $owner->id,
        'last_read_reply_id' => $first->id,
    ]);

    expect(inboxRows($owner, $team)[0]['root']['threadUnreadReplyCount'])->toBe(2);

    app(MarkThreadRead::class)->handle($root, $owner);

    expect(inboxRows($owner, $team)[0]['root']['threadUnreadReplyCount'])->toBe(0);
});

test('a card keeps the viewer own selection on an anonymous poll root', function (): void {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $root = inboxRoot($general, $owner, attributes: ['type' => MessageType::Poll]);
    $poll = Poll::factory()->anonymous()->withOptions(['Yes', 'No'])->create([
        'message_id' => $root->id,
    ]);
    PollVote::factory()->for($poll->options->first(), 'option')->for($owner)->create();
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    // An anonymous poll hides its roster, so the only way the card can show the
    // viewer their own vote is the viewer travelling into the payload.
    expect(inboxRows($owner, $team)[0]['root']['poll']['options'][0]['votedByViewer'])
        ->toBeTrue();
});

test('a direct message thread names the viewer counterpart', function (): void {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $alice);

    $root = inboxRoot($dm, $owner);
    Message::factory()->for($dm)->for($alice)->inThread($root)->create();

    $rows = inboxRows($owner, $team);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['channelName'])->toBe($alice->name)
        ->and($rows[0]['isDirectMessage'])->toBeTrue()
        ->and($rows[0]['dmParticipant']['id'])->toBe($alice->id);
});

test('the legacy threads inbox route redirects onto the pinned destination', function (): void {
    [$owner, $team] = inboxSetup();

    $this->actingAs($owner)
        ->get(route('channels.threads.index', ['team' => $team->slug]))
        ->assertRedirect(route('channels.index', ['team' => $team->slug, 'nav' => 'threads']));
});

test('hasUnreadThreads flags an unread followed thread and clears when read', function (): void {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $root = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    expect(inboxProps($owner, $team)['hasUnreadThreads'])->toBeTrue();

    app(MarkThreadRead::class)->handle($root, $owner);

    expect(inboxProps($owner, $team)['hasUnreadThreads'])->toBeFalse();
});

test('hasUnreadThreads ignores threads the user does not follow', function (): void {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);
    $bob = inboxMember($team, $general);

    $root = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    expect(inboxProps($bob, $team)['hasUnreadThreads'])->toBeFalse();
});

test('hasUnreadThreads respects channel mute', function (): void {
    [$owner, $team, $general] = inboxSetup();
    $alice = inboxMember($team, $general);

    $general->channelMembers()->where('user_id', $owner->id)
        ->update(['notification_level' => NotificationLevel::Mentions]);

    $root = inboxRoot($general, $owner);
    Message::factory()->for($general)->for($alice)->inThread($root)->create();

    expect(inboxProps($owner, $team)['hasUnreadThreads'])->toBeFalse();
});
