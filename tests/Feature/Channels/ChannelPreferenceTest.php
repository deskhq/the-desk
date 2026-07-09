<?php

use App\Actions\Channels\JoinChannel;
use App\Actions\Teams\CreateTeam;
use App\Enums\ChannelVisibility;
use App\Enums\NotificationLevel;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function prefTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Add a user to the team and the given channel, returning them.
 */
function prefMember(Team $team, Channel $channel, ?string $name = null): User
{
    $user = User::factory()->create($name ? ['name' => $name] : []);
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);
    $channel->channelMembers()->firstOrCreate(['user_id' => $user->id]);

    return $user;
}

/**
 * Post a message to the channel, optionally mentioning a member.
 */
function prefPost(Channel $channel, User $author, ?User $mention = null): Message
{
    $body = $mention ? "hey @[{$mention->name}]({$mention->id})" : fake()->sentence();

    $message = Message::factory()->for($channel)->for($author)->create(['body' => $body]);

    if ($mention) {
        $message->mentionedUsers()->attach($mention->id);
    }

    return $message;
}

/**
 * Resolve the sidebar `channels` prop entry for the channel as the acting user.
 *
 * @return array<string, mixed>
 */
function prefSidebarEntry(User $user, Team $team, Channel $channel): array
{
    $response = test()->actingAs($user)->get(route('channels.show', [
        'team' => $team->slug,
        'channel' => $channel->slug,
    ]))->assertOk();

    $channels = $response->viewData('page')['props']['channels'];

    return collect($channels)->firstWhere('slug', $channel->slug);
}

/**
 * Update the preferences endpoint as the given user.
 */
function updatePreferences(User $user, Team $team, Channel $channel, bool $muted, string $level): TestResponse
{
    return test()->actingAs($user)->patch(route('channels.preferences.update', [
        'team' => $team->slug,
        'channel' => $channel->slug,
    ]), ['muted' => $muted, 'notification_level' => $level]);
}

test('joining a channel applies the default notification preferences', function () {
    [$owner, $team] = prefTeamWithGeneral();
    $channel = Channel::factory()->for($team)->create([
        'visibility' => ChannelVisibility::Public,
        'created_by' => $owner->id,
    ]);
    $user = User::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    app(JoinChannel::class)->handle($channel, $user);

    $this->assertDatabaseHas('channel_members', [
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'muted' => false,
        'notification_level' => NotificationLevel::All->value,
    ]);
});

test('a member can update their notification preferences', function () {
    [, $team, $general] = prefTeamWithGeneral();
    $member = prefMember($team, $general);

    updatePreferences($member, $team, $general, muted: true, level: 'mentions')
        ->assertRedirect();

    $this->assertDatabaseHas('channel_members', [
        'channel_id' => $general->id,
        'user_id' => $member->id,
        'muted' => true,
        'notification_level' => 'mentions',
    ]);
});

test('a non-member cannot update preferences for a channel', function () {
    [$owner, $team] = prefTeamWithGeneral();
    $private = Channel::factory()->for($team)->create([
        'visibility' => ChannelVisibility::Private,
        'created_by' => $owner->id,
    ]);
    $stranger = User::factory()->create();
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);

    updatePreferences($stranger, $team, $private, muted: true, level: 'nothing')
        ->assertForbidden();

    $this->assertDatabaseMissing('channel_members', [
        'channel_id' => $private->id,
        'user_id' => $stranger->id,
    ]);
});

test('the notification level must be one of the allowed values', function () {
    [, $team, $general] = prefTeamWithGeneral();
    $member = prefMember($team, $general);

    updatePreferences($member, $team, $general, muted: false, level: 'sometimes')
        ->assertSessionHasErrors('notification_level');
});

test('the muted flag must be a boolean', function () {
    [, $team, $general] = prefTeamWithGeneral();
    $member = prefMember($team, $general);

    test()->actingAs($member)->patch(route('channels.preferences.update', [
        'team' => $team->slug,
        'channel' => $general->slug,
    ]), ['muted' => 'maybe', 'notification_level' => 'all'])
        ->assertSessionHasErrors('muted');
});

test('the channel view exposes the members own preferences and the level options', function () {
    [, $team, $general] = prefTeamWithGeneral();
    $member = prefMember($team, $general);
    $member->channels()->updateExistingPivot($general->id, [
        'muted' => true,
        'notification_level' => 'mentions',
    ]);

    $response = $this->actingAs($member)->get(route('channels.show', [
        'team' => $team->slug,
        'channel' => $general->slug,
    ]))->assertOk();

    $props = $response->viewData('page')['props'];

    expect($props['channel'])
        ->toMatchArray(['muted' => true, 'notificationLevel' => 'mentions'])
        ->and($props['canManagePreferences'])->toBeTrue()
        ->and($props['notificationLevels'])->toBe([
            ['value' => 'all', 'label' => 'All messages'],
            ['value' => 'mentions', 'label' => 'Mentions only'],
            ['value' => 'nothing', 'label' => 'Nothing'],
        ]);
});

test('a non-member viewing a public channel cannot manage preferences and sees defaults', function () {
    [$owner, $team] = prefTeamWithGeneral();
    $public = Channel::factory()->for($team)->create([
        'visibility' => ChannelVisibility::Public,
        'created_by' => $owner->id,
    ]);
    $outsider = User::factory()->create();
    $team->memberships()->create(['user_id' => $outsider->id, 'role' => TeamRole::Member]);

    $response = $this->actingAs($outsider)->get(route('channels.show', [
        'team' => $team->slug,
        'channel' => $public->slug,
    ]))->assertOk();

    $props = $response->viewData('page')['props'];

    expect($props['channel'])
        ->toMatchArray(['muted' => false, 'notificationLevel' => 'all'])
        ->and($props['canManagePreferences'])->toBeFalse();
});

test('the notification level and mute suppress the sidebar badges', function (bool $muted, string $level, int $expectedUnread, int $expectedMention) {
    [$owner, $team, $general] = prefTeamWithGeneral();
    $member = prefMember($team, $general, 'Ada Lovelace');

    // Two unread messages, one of which is a direct @mention of the member.
    prefPost($general, $owner);
    prefPost($general, $owner, $member);

    $member->channels()->updateExistingPivot($general->id, [
        'muted' => $muted,
        'notification_level' => $level,
    ]);

    expect(prefSidebarEntry($member, $team, $general))
        ->toMatchArray(['unreadCount' => $expectedUnread, 'mentionCount' => $expectedMention]);
})->with([
    '"all" shows the unread dot and the mention badge' => [false, 'all', 2, 1],
    '"mentions" keeps the mention badge but silences the unread dot' => [false, 'mentions', 0, 1],
    '"nothing" suppresses every badge' => [false, 'nothing', 0, 0],
    'muted suppresses every badge' => [true, 'all', 0, 0],
    'muted overrides an otherwise-alerting mentions level' => [true, 'mentions', 0, 0],
]);
