<?php

use App\Actions\Channels\MarkChannelRead;
use App\Actions\Teams\CreateTeam;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function unreadTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Add a user to the team and the given channel, returning them.
 */
function unreadChannelMember(Team $team, Channel $channel, ?string $name = null): User
{
    $user = User::factory()->create($name ? ['name' => $name] : []);
    // Joining the team auto-adds the user to #general via the membership observer,
    // so create the channel pivot idempotently for any other channel.
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);
    $channel->channelMembers()->firstOrCreate(['user_id' => $user->id]);

    return $user;
}

/**
 * Post a message to the channel authored by the given user.
 */
function unreadPost(Channel $channel, User $author, ?User $mention = null): Message
{
    $body = $mention instanceof User ? "hey @[{$mention->name}]({$mention->id})" : fake()->sentence();

    $message = Message::factory()->for($channel)->for($author)->create(['body' => $body]);

    if ($mention instanceof User) {
        $message->mentionedUsers()->attach($mention->id);
    }

    return $message;
}

/**
 * Mark the channel read for the user, pointing the pivot at the given message.
 */
function markReadUpTo(User $user, Channel $channel, ?Message $message): void
{
    $user->channels()->updateExistingPivot($channel->id, ['last_read_message_id' => $message?->id]);
}

/**
 * Resolve the sidebar `channels` prop entry for the given channel as the acting user.
 *
 * @return array{unreadCount: int, mentionCount: int}
 */
function sidebarChannel(User $user, Team $team, Channel $channel): array
{
    $response = test()->actingAs($user)->get(route('channels.show', [
        'team' => $team->slug,
        'channel' => $channel->slug,
    ]))->assertOk();

    $channels = $response->viewData('page')['props']['channels'];

    $entry = collect($channels)->firstWhere('slug', $channel->slug);

    return ['unreadCount' => $entry['unreadCount'], 'mentionCount' => $entry['mentionCount']];
}

test('unread count reflects only the messages posted after last_read', function (): void {
    [$owner, $team, $general] = unreadTeamWithGeneral();
    $member = unreadChannelMember($team, $general, 'Ada Lovelace');

    $messages = collect(range(1, 5))->map(fn (): Message => unreadPost($general, $owner));
    markReadUpTo($member, $general, $messages[2]);

    expect(sidebarChannel($member, $team, $general))
        ->toMatchArray(['unreadCount' => 2, 'mentionCount' => 0]);
});

test('a null last_read means every message in the channel is unread', function (): void {
    [$owner, $team, $general] = unreadTeamWithGeneral();
    $member = unreadChannelMember($team, $general);

    collect(range(1, 3))->each(fn (): Message => unreadPost($general, $owner));

    expect(sidebarChannel($member, $team, $general)['unreadCount'])->toBe(3);
});

test('a member does not see their own messages as unread', function (): void {
    [$owner, $team, $general] = unreadTeamWithGeneral();
    $member = unreadChannelMember($team, $general);

    unreadPost($general, $owner);
    unreadPost($general, $member);
    unreadPost($general, $member);

    expect(sidebarChannel($member, $team, $general)['unreadCount'])->toBe(1);
});

test('soft-deleted messages are excluded from the unread count', function (): void {
    [$owner, $team, $general] = unreadTeamWithGeneral();
    $member = unreadChannelMember($team, $general);

    unreadPost($general, $owner);
    $deleted = unreadPost($general, $owner);
    $deleted->delete();

    expect(sidebarChannel($member, $team, $general)['unreadCount'])->toBe(1);
});

test('system notices are ambient and never advance the unread or mention badge', function (): void {
    [$owner, $team, $general] = unreadTeamWithGeneral();
    $member = unreadChannelMember($team, $general, 'Ada Lovelace');

    $read = unreadPost($general, $owner);
    markReadUpTo($member, $general, $read);

    // Peers joining and leaving after the read pointer must not badge the channel.
    Message::factory()->for($general)->for($owner)->memberJoined()->create();
    Message::factory()->for($general)->for($owner)->memberLeft()->create();

    expect(sidebarChannel($member, $team, $general))
        ->toMatchArray(['unreadCount' => 0, 'mentionCount' => 0]);
});

test('the mention count only counts unread messages that mention the user', function (): void {
    [$owner, $team, $general] = unreadTeamWithGeneral();
    $member = unreadChannelMember($team, $general, 'Grace Hopper');

    $readMention = unreadPost($general, $owner, $member);
    markReadUpTo($member, $general, $readMention);

    unreadPost($general, $owner);
    unreadPost($general, $owner, $member);
    unreadPost($general, $owner, $member);

    expect(sidebarChannel($member, $team, $general))
        ->toMatchArray(['unreadCount' => 3, 'mentionCount' => 2]);
});

test('a mention of another member does not inflate the users mention count', function (): void {
    [$owner, $team, $general] = unreadTeamWithGeneral();
    $member = unreadChannelMember($team, $general);
    $other = unreadChannelMember($team, $general, 'Someone Else');

    unreadPost($general, $owner, $other);

    expect(sidebarChannel($member, $team, $general)['mentionCount'])->toBe(0);
});

test('MarkChannelRead advances last_read to the latest message and clears the badge', function (): void {
    [$owner, $team, $general] = unreadTeamWithGeneral();
    $member = unreadChannelMember($team, $general, 'Alan Turing');

    unreadPost($general, $owner, $member);
    $latest = unreadPost($general, $owner);

    app(MarkChannelRead::class)->handle($general, $member);

    $this->assertDatabaseHas('channel_members', [
        'channel_id' => $general->id,
        'user_id' => $member->id,
        'last_read_message_id' => $latest->id,
    ]);

    expect(sidebarChannel($member, $team, $general))
        ->toMatchArray(['unreadCount' => 0, 'mentionCount' => 0]);
});

test('MarkChannelRead leaves the pointer untouched when the channel has no messages', function (): void {
    [, $team, $general] = unreadTeamWithGeneral();
    $member = unreadChannelMember($team, $general);

    app(MarkChannelRead::class)->handle($general, $member);

    $this->assertDatabaseHas('channel_members', [
        'channel_id' => $general->id,
        'user_id' => $member->id,
        'last_read_message_id' => null,
    ]);
});

test('MarkChannelRead is a no-op for a user who is not a channel member', function (): void {
    [$owner, $team, $general] = unreadTeamWithGeneral();
    $private = Channel::factory()->for($team)->create([
        'visibility' => ChannelVisibility::Private,
        'created_by' => $owner->id,
    ]);
    // A plain team member is auto-joined to #general but not to the private channel.
    $outsider = unreadChannelMember($team, $general);

    Message::factory()->for($private)->for($owner)->create();

    app(MarkChannelRead::class)->handle($private, $outsider);

    $this->assertDatabaseMissing('channel_members', [
        'channel_id' => $private->id,
        'user_id' => $outsider->id,
    ]);
});

test('hitting the read endpoint advances the pointer and clears the badges', function (): void {
    [$owner, $team, $general] = unreadTeamWithGeneral();
    $member = unreadChannelMember($team, $general, 'Katherine Johnson');

    unreadPost($general, $owner, $member);
    $latest = unreadPost($general, $owner);

    // The badges are showing before the channel is marked read.
    expect(sidebarChannel($member, $team, $general))
        ->toMatchArray(['unreadCount' => 2, 'mentionCount' => 1]);

    $this->actingAs($member)
        ->post(route('channels.read', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertRedirect();

    $this->assertDatabaseHas('channel_members', [
        'channel_id' => $general->id,
        'user_id' => $member->id,
        'last_read_message_id' => $latest->id,
    ]);

    expect(sidebarChannel($member, $team, $general))
        ->toMatchArray(['unreadCount' => 0, 'mentionCount' => 0]);
});

test('the read endpoint is forbidden on a private channel the user cannot view', function (): void {
    [$owner, $team] = unreadTeamWithGeneral();
    $private = Channel::factory()->for($team)->create([
        'visibility' => ChannelVisibility::Private,
        'created_by' => $owner->id,
    ]);

    $stranger = User::factory()->create();
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);

    $this->actingAs($stranger)
        ->post(route('channels.read', ['team' => $team->slug, 'channel' => $private->slug]))
        ->assertForbidden();
});
