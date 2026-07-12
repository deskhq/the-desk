<?php

use App\Data\WorkspaceAnalyticsData;
use App\Enums\AnalyticsRange;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Support\WorkspaceAnalytics;
use Illuminate\Support\Facades\Cache;

/**
 * Freeze the clock so day/month bucketing is deterministic.
 */
beforeEach(function (): void {
    $this->travelTo('2026-07-15 12:00:00');
});

/**
 * A team owned by a fresh user, with the given number of extra members.
 *
 * @return array{0: Team, 1: User}
 */
function analyticsTeam(int $members = 0): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    for ($i = 0; $i < $members; $i++) {
        $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Member->value]);
    }

    return [$team, $owner];
}

/**
 * A standard channel in the team. With no name, returns the team's #general
 * channel (auto-created when the first member joins); otherwise creates a new
 * named channel.
 */
function analyticsChannel(Team $team, ?string $name = null): Channel
{
    if ($name === null) {
        return $team->channels()->where('slug', Channel::GENERAL_SLUG)->firstOrFail();
    }

    return Channel::factory()->for($team)->create(['name' => $name]);
}

/**
 * Post a message to a channel from a user at a given time.
 */
function postMessage(Channel $channel, User $user, string $at, array $attributes = []): Message
{
    return Message::factory()->for($channel)->for($user)->create([
        'created_at' => $at,
        ...$attributes,
    ]);
}

/**
 * Compute the snapshot for a team and range through the service.
 */
function analyticsFor(Team $team, AnalyticsRange $range = AnalyticsRange::Month): WorkspaceAnalyticsData
{
    return app(WorkspaceAnalytics::class)->for($team, $range);
}

test('active members counts distinct authors in the window against total members', function (): void {
    [$team, $owner] = analyticsTeam(members: 3);
    $channel = analyticsChannel($team);
    $members = $team->members()->where('team_members.role', TeamRole::Member->value)->get();

    // Two distinct members post inside the 30-day window.
    postMessage($channel, $members[0], '2026-07-10 09:00:00');
    postMessage($channel, $members[0], '2026-07-11 09:00:00');
    postMessage($channel, $members[1], '2026-07-12 09:00:00');
    // One posts outside the window (65 days ago) — not active.
    postMessage($channel, $members[2], '2026-05-11 09:00:00');

    $stat = analyticsFor($team)->activeMembers;

    expect($stat->value)->toBe(2)
        ->and($stat->total)->toBe(4);
});

test('active members delta compares against the previous window', function (): void {
    [$team, $owner] = analyticsTeam(members: 2);
    $channel = analyticsChannel($team);
    $members = $team->members()->where('team_members.role', TeamRole::Member->value)->get();

    // Current 30d window: 2 active authors.
    postMessage($channel, $members[0], '2026-07-01 09:00:00');
    postMessage($channel, $members[1], '2026-07-02 09:00:00');
    // Previous 30d window: 1 active author.
    postMessage($channel, $members[0], '2026-06-01 09:00:00');

    expect(analyticsFor($team)->activeMembers->delta)->toBe(1);
});

test('messages per day averages volume over the window and reports percent change', function (): void {
    [$team, $owner] = analyticsTeam();
    $channel = analyticsChannel($team);

    // 30 messages this window -> 1/day.
    for ($i = 0; $i < 30; $i++) {
        postMessage($channel, $owner, '2026-07-10 09:00:00');
    }
    // 15 messages in the previous window -> +100% change in total volume.
    for ($i = 0; $i < 15; $i++) {
        postMessage($channel, $owner, '2026-06-10 09:00:00');
    }

    $stat = analyticsFor($team)->messagesPerDay;

    expect($stat->value)->toBe(1)
        ->and($stat->deltaPercent)->toBe(100);
});

test('messages per day percent change is null without a previous baseline', function (): void {
    [$team, $owner] = analyticsTeam();
    $channel = analyticsChannel($team);
    postMessage($channel, $owner, '2026-07-10 09:00:00');

    expect(analyticsFor($team)->messagesPerDay->deltaPercent)->toBeNull();
});

test('messages sent totals the window and counts thread replies separately', function (): void {
    [$team, $owner] = analyticsTeam();
    $channel = analyticsChannel($team);

    $root = postMessage($channel, $owner, '2026-07-05 09:00:00');
    postMessage($channel, $owner, '2026-07-06 09:00:00', ['thread_root_id' => $root->id]);
    postMessage($channel, $owner, '2026-07-07 09:00:00', ['thread_root_id' => $root->id]);

    $stat = analyticsFor($team)->messagesSent;

    expect($stat->value)->toBe(3)
        ->and($stat->secondary)->toBe(2);
});

test('active channels counts channels with activity against live channel count', function (): void {
    [$team, $owner] = analyticsTeam();
    $general = analyticsChannel($team);
    $random = analyticsChannel($team, 'random');
    analyticsChannel($team, 'quiet'); // no messages
    Channel::factory()->for($team)->archived()->create(['name' => 'archived']);

    postMessage($general, $owner, '2026-07-10 09:00:00');
    postMessage($random, $owner, '2026-07-11 09:00:00');

    $stat = analyticsFor($team)->activeChannels;

    // 2 active; total live standard channels = #general, random, quiet = 3.
    expect($stat->value)->toBe(2)
        ->and($stat->total)->toBe(3);
});

test('the messages-by-day series is zero-filled for the whole window', function (): void {
    [$team, $owner] = analyticsTeam();
    $channel = analyticsChannel($team);

    postMessage($channel, $owner, '2026-07-14 09:00:00');
    postMessage($channel, $owner, '2026-07-14 10:00:00');

    $series = analyticsFor($team, AnalyticsRange::Week)->messagesByDay;

    expect($series)->toHaveCount(7)
        ->and($series[0]->date)->toBe('2026-07-09')
        ->and($series[6]->date)->toBe('2026-07-15')
        ->and($series[5]->count)->toBe(2)
        ->and($series[6]->count)->toBe(0);
});

test('top channels ranks the busiest channels by message count', function (): void {
    [$team, $owner] = analyticsTeam();
    $design = analyticsChannel($team, 'design');
    $general = analyticsChannel($team);

    postMessage($design, $owner, '2026-07-10 09:00:00');
    postMessage($design, $owner, '2026-07-10 09:01:00');
    postMessage($design, $owner, '2026-07-10 09:02:00');
    postMessage($general, $owner, '2026-07-11 09:00:00');

    $top = analyticsFor($team)->topChannels;

    expect($top)->toHaveCount(2)
        ->and($top[0]->name)->toBe('design')
        ->and($top[0]->count)->toBe(3)
        ->and($top[1]->name)->toBe('general')
        ->and($top[1]->count)->toBe(1);
});

test('member growth accumulates the member total over six months', function (): void {
    [$team, $owner] = analyticsTeam();
    $channel = analyticsChannel($team);

    // Owner joined in Feb; two more members join in July.
    $team->memberships()->where('user_id', $owner->id)->update(['created_at' => '2026-02-10 09:00:00']);
    $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Member->value]);
    $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Member->value]);
    $team->memberships()->where('user_id', '!=', $owner->id)->update(['created_at' => '2026-07-01 09:00:00']);

    $growth = analyticsFor($team)->memberGrowth;

    expect($growth)->toHaveCount(6)
        ->and($growth[0]->month)->toBe('2026-02-01')
        ->and($growth[0]->total)->toBe(1)
        ->and($growth[5]->month)->toBe('2026-07-01')
        ->and($growth[5]->total)->toBe(3);
});

test('top contributors ranks the most active members', function (): void {
    [$team, $owner] = analyticsTeam(members: 1);
    $channel = analyticsChannel($team);
    $member = $team->members()->where('team_members.role', TeamRole::Member->value)->first();

    postMessage($channel, $owner, '2026-07-10 09:00:00');
    postMessage($channel, $owner, '2026-07-10 09:01:00');
    postMessage($channel, $member, '2026-07-11 09:00:00');

    $top = analyticsFor($team)->topContributors;

    expect($top)->toHaveCount(2)
        ->and($top[0]->id)->toBe($owner->id)
        ->and($top[0]->count)->toBe(2)
        ->and($top[1]->id)->toBe($member->id);
});

test('analytics ignores direct messages, other teams, and soft-deleted messages', function (): void {
    [$team, $owner] = analyticsTeam();
    $channel = analyticsChannel($team);

    // A live message in scope.
    postMessage($channel, $owner, '2026-07-10 09:00:00');

    // A DM message — excluded.
    $dm = Channel::factory()->for($team)->direct()->create();
    postMessage($dm, $owner, '2026-07-10 09:00:00');

    // A soft-deleted message — excluded.
    postMessage($channel, $owner, '2026-07-10 09:00:00', ['deleted_at' => '2026-07-10 10:00:00']);

    // Another team's message — excluded.
    [$otherTeam, $otherOwner] = analyticsTeam();
    postMessage(analyticsChannel($otherTeam), $otherOwner, '2026-07-10 09:00:00');

    expect(analyticsFor($team)->messagesSent->value)->toBe(1);
});

test('snapshots are cached per team and range', function (): void {
    [$team, $owner] = analyticsTeam();
    $channel = analyticsChannel($team);
    postMessage($channel, $owner, '2026-07-10 09:00:00');

    expect(analyticsFor($team)->messagesSent->value)->toBe(1);

    // A new message after the first read is not reflected until the cache clears.
    postMessage($channel, $owner, '2026-07-11 09:00:00');
    expect(analyticsFor($team)->messagesSent->value)->toBe(1);

    app(WorkspaceAnalytics::class)->forget($team);
    expect(analyticsFor($team)->messagesSent->value)->toBe(2);
});

test('caching is scoped to the requested range', function (): void {
    [$team, $owner] = analyticsTeam();
    $channel = analyticsChannel($team);
    Cache::spy();

    app(WorkspaceAnalytics::class)->for($team, AnalyticsRange::Week);

    Cache::shouldHaveReceived('remember')
        ->withArgs(fn (string $key): bool => str_contains($key, ':analytics:7d'))
        ->once();
});
