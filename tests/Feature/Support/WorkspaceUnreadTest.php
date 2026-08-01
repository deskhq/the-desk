<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\MessageType;
use App\Enums\NotificationLevel;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Support\WorkspaceUnread;

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
 * The viewer's raw per-channel unread count for one channel, resolved through
 * the correlated sub-query exactly as the sidebar query resolves it.
 */
function channelUnreadCount(User $viewer, Channel $channel): int
{
    return (int) $viewer->channels()
        ->whereKey($channel->id)
        ->select('channels.*')
        ->selectSub(WorkspaceUnread::forChannelsOf($viewer), 'unread_count')
        ->firstOrFail()
        ->getAttribute('unread_count');
}

test('the per-channel count holds other people\'s messages past the read pointer', function (): void {
    [$viewer, $mate, , $general] = unreadWorkspace();

    Message::factory()->for($general)->for($viewer)->create();
    $theirs = Message::factory()->for($general)->for($mate)->count(3)->create();

    expect(channelUnreadCount($viewer, $general))->toBe(3);

    $viewer->channels()->updateExistingPivot($general->id, ['last_read_message_id' => $theirs->first()->id]);

    expect(channelUnreadCount($viewer, $general))->toBe(2);
});

test('the per-channel count ignores system notices and deleted messages', function (): void {
    [$viewer, $mate, , $general] = unreadWorkspace();

    Message::factory()->for($general)->for($mate)->create(['type' => MessageType::systemValues()[0]]);
    $deleted = Message::factory()->for($general)->for($mate)->create();
    $deleted->delete();

    expect(channelUnreadCount($viewer, $general))->toBe(0);
});

test('the per-workspace tally sums a team\'s unread and mentions', function (): void {
    [$viewer, $mate, $team, $general] = unreadWorkspace();

    $messages = Message::factory()->for($general)->for($mate)->count(2)->create();
    $messages->last()->mentionedUsers()->attach($viewer);

    expect(WorkspaceUnread::forUser($viewer))->toBe([
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
    expect(WorkspaceUnread::forUser($viewer))->toBe([
        $team->id => ['unread' => 1, 'mention' => 0],
    ]);

    Message::factory()->for($general)->for($mate)->create([
        'thread_root_id' => $root->id,
        'sent_to_channel' => true,
    ]);

    expect(WorkspaceUnread::forUser($viewer))->toBe([
        $team->id => ['unread' => 2, 'mention' => 0],
    ]);
});

/**
 * The mention half of the same aggregate deliberately does not carry the
 * predicate — a mention anywhere, thread included, still badges — which is why
 * the rule is opt-in per call site rather than folded into the shared query.
 */
test('a mention inside a thread-only reply still tallies for the workspace', function (): void {
    [$viewer, $mate, $team, $general] = unreadWorkspace();

    $root = Message::factory()->for($general)->for($mate)->create();

    Message::factory()->for($general)->for($mate)->create([
        'thread_root_id' => $root->id,
        'sent_to_channel' => false,
    ])->mentionedUsers()->attach($viewer);

    expect(WorkspaceUnread::forUser($viewer))->toBe([
        $team->id => ['unread' => 1, 'mention' => 1],
    ]);
});

test('a muted channel is silent in the per-workspace tally but still counted per channel', function (): void {
    [$viewer, $mate, , $general] = unreadWorkspace();

    Message::factory()->for($general)->for($mate)->count(2)->create();
    $viewer->channels()->updateExistingPivot($general->id, ['muted' => true]);

    // The workspace dot goes quiet; the channel row still knows what it holds,
    // and ChannelData suppresses the badge from there.
    expect(WorkspaceUnread::forUser($viewer))->toBe([])
        ->and(channelUnreadCount($viewer, $general))->toBe(2);
});

test('the "nothing" notification level silences a workspace, "mentions" keeps only mentions', function (): void {
    [$viewer, $mate, $team, $general] = unreadWorkspace();

    $messages = Message::factory()->for($general)->for($mate)->count(2)->create();
    $messages->last()->mentionedUsers()->attach($viewer);

    $viewer->channels()->updateExistingPivot($general->id, ['notification_level' => NotificationLevel::Mentions]);

    expect(WorkspaceUnread::forUser($viewer))->toBe([
        $team->id => ['unread' => 0, 'mention' => 1],
    ]);

    $viewer->channels()->updateExistingPivot($general->id, ['notification_level' => NotificationLevel::Nothing]);

    expect(WorkspaceUnread::forUser($viewer))->toBe([]);
});
