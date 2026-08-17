<?php

declare(strict_types=1);

use App\Actions\Teams\CreateTeam;
use App\Data\UnreadDigestData;
use App\Enums\MessageType;
use App\Enums\NotificationLevel;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Support\WorkspaceUnread;
use Illuminate\Support\Facades\DB;

/**
 * A viewer, a workspace mate, their team, and its #general channel — both in it.
 * The read-model is driven straight against these, no HTTP round-trip.
 *
 * @return array{0: User, 1: User, 2: Team, 3: Channel}
 */
function unreadWorkspace(): array
{
    $viewer = User::factory()->create();
    $team = app(CreateTeam::class)->handle($viewer, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', Channel::GENERAL_SLUG)->firstOrFail();

    $mate = User::factory()->create();
    // The membership auto-joins them to #general.
    $team->memberships()->create(['user_id' => $mate->id, 'role' => TeamRole::Member]);

    return [$viewer, $mate, $team, $general];
}

/**
 * The viewer's digest as plain arrays, detailed for the given workspace.
 *
 * @return array{channels: array<string, array{unread: int, mention: int}>, teams: array<string, array{unread: int, mention: int}>, threads: bool}
 */
function digestArray(User $viewer, ?Team $team = null): array
{
    /** @var array{channels: array<string, array{unread: int, mention: int}>, teams: array<string, array{unread: int, mention: int}>, threads: bool} $digest */
    $digest = WorkspaceUnread::digest($viewer, $team)->toArray();

    return $digest;
}

test('the per-channel reading holds other people\'s messages past the read pointer', function (): void {
    [$viewer, $mate, $team, $general] = unreadWorkspace();

    Message::factory()->for($general)->for($viewer)->create();
    $theirs = Message::factory()->for($general)->for($mate)->count(3)->create();

    expect(digestArray($viewer, $team)['channels'][$general->id])->toBe(['unread' => 3, 'mention' => 0]);

    $viewer->channels()->updateExistingPivot($general->id, ['last_read_message_id' => $theirs->first()->id]);

    expect(digestArray($viewer, $team)['channels'][$general->id])->toBe(['unread' => 2, 'mention' => 0]);
});

test('the per-channel reading ignores system notices and deleted messages', function (): void {
    [$viewer, $mate, $team, $general] = unreadWorkspace();

    Message::factory()->for($general)->for($mate)->create(['type' => MessageType::systemValues()[0]]);
    $deleted = Message::factory()->for($general)->for($mate)->create();
    $deleted->delete();

    // Nothing waiting means no entry at all: the map is sparse, and the client
    // reads a missing channel as zero.
    expect(digestArray($viewer, $team)['channels'])->toBe([]);
});

test('the per-workspace tally sums a team\'s unread and mentions', function (): void {
    [$viewer, $mate, $team, $general] = unreadWorkspace();

    $messages = Message::factory()->for($general)->for($mate)->count(2)->create();
    $messages->last()->mentionedUsers()->attach($viewer);

    expect(digestArray($viewer)['teams'])->toBe([
        $team->id => ['unread' => 2, 'mention' => 1],
    ]);
});

/**
 * The workspace tally is where the channel-traffic rule used to be spelled as
 * raw SQL inside the conditional aggregate, and it had no test at all: the dot
 * on the rail could have counted thread-only replies for as long as anyone cared
 * to look, and would still have agreed with nothing. It now shares
 * {@see Message::channelTrafficSql()} with the sidebar, and this is what says so.
 */
test('the per-workspace tally counts a thread reply only when it was also sent to the channel', function (): void {
    [$viewer, $mate, $team, $general] = unreadWorkspace();

    $root = Message::factory()->for($general)->for($mate)->create();

    Message::factory()->for($general)->for($mate)->create([
        'thread_root_id' => $root->id,
        'sent_to_channel' => false,
    ]);

    // The root alone; the thread-only reply lives in the thread view.
    expect(digestArray($viewer)['teams'])->toBe([
        $team->id => ['unread' => 1, 'mention' => 0],
    ]);

    Message::factory()->for($general)->for($mate)->create([
        'thread_root_id' => $root->id,
        'sent_to_channel' => true,
    ]);

    expect(digestArray($viewer)['teams'])->toBe([
        $team->id => ['unread' => 2, 'mention' => 0],
    ]);
});

/**
 * The mention half of the same aggregate deliberately does not carry the
 * predicate — a mention anywhere, thread included, still badges — which is why
 * the rule is applied to one half of the conditional aggregate and not the other.
 */
test('a mention inside a thread-only reply still tallies for the workspace', function (): void {
    [$viewer, $mate, $team, $general] = unreadWorkspace();

    $root = Message::factory()->for($general)->for($mate)->create();

    Message::factory()->for($general)->for($mate)->create([
        'thread_root_id' => $root->id,
        'sent_to_channel' => false,
    ])->mentionedUsers()->attach($viewer);

    expect(digestArray($viewer)['teams'])->toBe([
        $team->id => ['unread' => 1, 'mention' => 1],
    ]);
});

/**
 * The two readings used to disagree here on purpose: the workspace tally applied
 * muting in SQL while the channel row shipped its raw count for the DTO to
 * suppress. One query now answers both, so suppression happens once — which is
 * what makes "the dot and the rows inside it cannot drift" a property rather
 * than a habit.
 */
test('a muted channel is silent in both readings', function (): void {
    [$viewer, $mate, $team, $general] = unreadWorkspace();

    Message::factory()->for($general)->for($mate)->count(2)->create();
    $viewer->channels()->updateExistingPivot($general->id, ['muted' => true]);

    expect(digestArray($viewer, $team))
        ->toMatchArray(['teams' => [], 'channels' => []]);
});

test('the "nothing" notification level silences a channel, "mentions" keeps only mentions', function (): void {
    [$viewer, $mate, $team, $general] = unreadWorkspace();

    $messages = Message::factory()->for($general)->for($mate)->count(2)->create();
    $messages->last()->mentionedUsers()->attach($viewer);

    $viewer->channels()->updateExistingPivot($general->id, ['notification_level' => NotificationLevel::Mentions]);

    expect(digestArray($viewer, $team))->toMatchArray([
        'teams' => [$team->id => ['unread' => 0, 'mention' => 1]],
        'channels' => [$general->id => ['unread' => 0, 'mention' => 1]],
    ]);

    $viewer->channels()->updateExistingPivot($general->id, ['notification_level' => NotificationLevel::Nothing]);

    expect(digestArray($viewer, $team))->toMatchArray(['teams' => [], 'channels' => []]);
});

/**
 * The digest is the only thing left on a navigation that still touches
 * per-channel state, so its cost is the epic's first suspect if the budget is
 * missed. It is an indexed aggregate plus the threads probe, and that is the
 * whole of it: a workspace ten times the size costs the same number of round
 * trips, which is the property a byte figure alone would not catch.
 */
test('the digest costs the same number of queries however many channels the viewer is in', function (): void {
    [$viewer, $mate, $team, $general] = unreadWorkspace();

    Message::factory()->for($general)->for($mate)->create();

    $small = countQueries(fn (): UnreadDigestData => WorkspaceUnread::digest($viewer, $team));

    foreach (range(1, 20) as $index) {
        $channel = Channel::factory()->for($team)->create(['name' => "Room {$index}", 'slug' => "room-{$index}"]);
        $channel->members()->attach([$viewer->id, $mate->id]);
        Message::factory()->for($channel)->for($mate)->create();
    }

    $large = countQueries(fn (): UnreadDigestData => WorkspaceUnread::digest($viewer, $team));

    expect(digestArray($viewer, $team)['channels'])->toHaveCount(21)
        ->and($large)->toBe($small)
        ->and($small)->toBe(3);
});

/**
 * Run the callback with the query log on and hand back how many statements it
 * cost.
 */
function countQueries(Closure $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $work();

        return count(DB::getRawQueryLog());
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}
