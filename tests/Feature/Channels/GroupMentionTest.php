<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Models\UserGroup;
use App\Support\MessagePlainText;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function groupMentionTeam(): array
{
    $owner = User::factory()->create(['name' => 'Owner Person']);
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Add a user to the team as a plain member and return them.
 */
function groupTeamMember(Team $team, ?string $name = null): User
{
    $user = User::factory()->create($name ? ['name' => $name] : []);
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    return $user;
}

/**
 * Create a group in the team with the given members already attached.
 *
 * @param  array<int, User>  $members
 */
function userGroupWith(Team $team, string $slug, array $members = []): UserGroup
{
    $group = UserGroup::factory()->for($team)->slug($slug)->create();
    $group->members()->sync(collect($members)->pluck('id')->all());

    return $group;
}

/**
 * Build the group-mention token the composer inserts, which carries a `group:`
 * type prefix so the parser can tell it from a plain user mention.
 */
function groupToken(UserGroup $group): string
{
    return "@[{$group->slug}](group:{$group->id})";
}

/**
 * Post a message to the channel as the given user and return the stored row.
 */
function postGroupMessage(User $actor, Team $team, Channel $channel, string $body): Message
{
    $clientUuid = (string) Str::uuid7();

    test()->actingAs($actor)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'body' => $body,
            'client_uuid' => $clientUuid,
        ])
        ->assertRedirect();

    return Message::where('client_uuid', $clientUuid)->firstOrFail();
}

test('a group mention fans out to a mention row for every member', function (): void {
    [$owner, $team, $general] = groupMentionTeam();
    $ada = groupTeamMember($team, 'Ada Lovelace');
    $grace = groupTeamMember($team, 'Grace Hopper');
    $group = userGroupWith($team, 'dev-team', [$ada, $grace]);

    $message = postGroupMessage($owner, $team, $general, 'heads up '.groupToken($group));

    expect($message->mentionedUsers->pluck('id')->sort()->values()->all())
        ->toBe(collect([$ada->id, $grace->id])->sort()->values()->all());
});

test('the poster is excluded from a group they mention', function (): void {
    [$owner, $team, $general] = groupMentionTeam();
    $ada = groupTeamMember($team, 'Ada Lovelace');
    $group = userGroupWith($team, 'dev-team', [$owner, $ada]);

    $message = postGroupMessage($owner, $team, $general, 'ping '.groupToken($group));

    expect($message->mentionedUsers->pluck('id')->all())->toBe([$ada->id]);
});

test('a member mentioned both individually and via a group gets one row', function (): void {
    [$owner, $team, $general] = groupMentionTeam();
    $ada = groupTeamMember($team, 'Ada Lovelace');
    $group = userGroupWith($team, 'dev-team', [$ada]);

    $message = postGroupMessage(
        $owner,
        $team,
        $general,
        "@[{$ada->name}]({$ada->id}) and ".groupToken($group),
    );

    expect($message->mentionedUsers)->toHaveCount(1)
        ->and($message->mentionedUsers->first()->id)->toBe($ada->id);
});

test('a group member who has left the team is not notified', function (): void {
    [$owner, $team, $general] = groupMentionTeam();
    $ada = groupTeamMember($team, 'Ada Lovelace');
    $departed = groupTeamMember($team, 'Departed Person');
    $group = userGroupWith($team, 'dev-team', [$ada, $departed]);

    $team->memberships()->where('user_id', $departed->id)->delete();

    $message = postGroupMessage($owner, $team, $general, 'ping '.groupToken($group));

    expect($message->mentionedUsers->pluck('id')->all())->toBe([$ada->id]);
});

test('an empty group mention notifies nobody', function (): void {
    [$owner, $team, $general] = groupMentionTeam();
    $group = userGroupWith($team, 'dev-team');

    $message = postGroupMessage($owner, $team, $general, 'anyone? '.groupToken($group));

    expect($message->mentionedUsers)->toHaveCount(0);
});

test('an unknown group id and a group from another team are both dropped', function (): void {
    [$owner, $team, $general] = groupMentionTeam();
    $outsider = User::factory()->create();
    $otherTeam = app(CreateTeam::class)->handle($outsider, 'Globex');
    $foreign = userGroupWith($otherTeam, 'dev-team', [$outsider]);
    $bogusId = (string) Str::uuid7();

    $message = postGroupMessage(
        $owner,
        $team,
        $general,
        groupToken($foreign)." and @[ghosts](group:{$bogusId})",
    );

    expect($message->mentionedUsers)->toHaveCount(0);
});

test('a group token inside inline code does not notify', function (): void {
    [$owner, $team, $general] = groupMentionTeam();
    $ada = groupTeamMember($team, 'Ada Lovelace');
    $group = userGroupWith($team, 'dev-team', [$ada]);

    $message = postGroupMessage($owner, $team, $general, 'type `'.groupToken($group).'` to ping');

    expect($message->mentionedUsers)->toHaveCount(0);
});

test('editing a message re-expands its group mentions', function (): void {
    [$owner, $team, $general] = groupMentionTeam();
    $ada = groupTeamMember($team, 'Ada Lovelace');
    $grace = groupTeamMember($team, 'Grace Hopper');
    $devs = userGroupWith($team, 'dev-team', [$ada]);
    $ops = userGroupWith($team, 'ops-team', [$grace]);

    $message = postGroupMessage($owner, $team, $general, 'ping '.groupToken($devs));
    expect($message->mentionedUsers->pluck('id')->all())->toBe([$ada->id]);

    $this->actingAs($owner)
        ->patch(route('channels.messages.update', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'message' => $message->id,
        ]), ['body' => 'ping '.groupToken($ops)])
        ->assertRedirect();

    expect($message->refresh()->mentionedUsers->pluck('id')->all())->toBe([$grace->id]);
});

test('membership is snapshotted at post time, not resolved on read', function (): void {
    [$owner, $team, $general] = groupMentionTeam();
    $ada = groupTeamMember($team, 'Ada Lovelace');
    $latecomer = groupTeamMember($team, 'Late Comer');
    $group = userGroupWith($team, 'dev-team', [$ada]);

    $message = postGroupMessage($owner, $team, $general, 'ping '.groupToken($group));

    $group->members()->attach($latecomer->id);

    expect($message->refresh()->mentionedUsers->pluck('id')->all())->toBe([$ada->id]);
});

test('a group mention counts toward the sidebar mention badge like a direct one', function (): void {
    [$owner, $team, $general] = groupMentionTeam();
    $ada = groupTeamMember($team, 'Ada Lovelace');
    $group = userGroupWith($team, 'dev-team', [$ada]);

    postGroupMessage($owner, $team, $general, 'ping '.groupToken($group));

    $response = $this->actingAs($ada)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertOk();

    $entry = collect($response->viewData('page')['props']['channels'])->firstWhere('slug', $general->slug);

    expect($entry['mentionCount'])->toBe(1);
});

test('a group mention makes the thread show up in the mentioned member inbox', function (): void {
    [$owner, $team, $general] = groupMentionTeam();
    $ada = groupTeamMember($team, 'Ada Lovelace');
    $group = userGroupWith($team, 'dev-team', [$ada]);

    $root = postGroupMessage($owner, $team, $general, 'thoughts?');

    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => 'asking '.groupToken($group),
            'client_uuid' => (string) Str::uuid7(),
            'thread_root_id' => $root->id,
        ])
        ->assertRedirect();

    // Following a thread is derived from the mention rows, so a fanned-out group
    // mention pulls each member into the thread exactly as naming them would.
    $panelUrl = route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]).'?nav=threads';

    $this->actingAs($ada)
        ->get($panelUrl)
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('threads.data', 1)
            ->where('threads.data.0.root.id', $root->id)
        );
});

test('the workspace groups ride along on every in-workspace request', function (): void {
    [$owner, $team, $general] = groupMentionTeam();
    $ada = groupTeamMember($team, 'Ada Lovelace');
    userGroupWith($team, 'dev-team', [$ada]);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('userGroups', 1)
            ->where('userGroups.0.slug', 'dev-team')
            ->where('userGroups.0.membersCount', 1)
            // The roster stays off the shared payload: resolving a pill and
            // listing the menu only need the handle and the count.
            ->where('userGroups.0.members', [])
        );
});

test('the shared groups payload is empty off the workspace', function (): void {
    [$owner, $team] = groupMentionTeam();
    userGroupWith($team, 'dev-team');

    $this->actingAs($owner)
        ->get(route('teams.edit', $team))
        ->assertInertia(fn (Assert $page): Assert => $page->where('userGroups', []));
});

test('a group mention is unwrapped to its handle for search indexing', function (): void {
    [$owner, $team, $general] = groupMentionTeam();
    $ada = groupTeamMember($team, 'Ada Lovelace');
    $group = userGroupWith($team, 'dev-team', [$ada]);

    $message = postGroupMessage($owner, $team, $general, 'ship it '.groupToken($group));

    expect(MessagePlainText::from($message->body))->toBe('ship it @dev-team');
});
