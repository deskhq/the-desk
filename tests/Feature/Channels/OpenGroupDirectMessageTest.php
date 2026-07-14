<?php

use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Teams\CreateTeam;
use App\Enums\ChannelType;
use App\Enums\ChannelVisibility;
use App\Enums\NotificationLevel;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Add a user to the team as a plain member and return them.
 */
function groupDmTeamMember(Team $team): User
{
    $user = User::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    return $user;
}

/**
 * A team owner plus the given number of extra plain members.
 *
 * @return array{0: User, 1: Team, 2: Collection<int, User>}
 */
function teamWithMembers(int $extra): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $others = collect(range(1, $extra))->map(fn (): User => groupDmTeamMember($team));

    return [$owner, $team, $others];
}

test('opening a group direct message creates a group_direct channel with every participant', function (): void {
    [$owner, $team, $others] = teamWithMembers(2);

    $channel = app(OpenDirectMessage::class)->openForUsers($team, $owner, $others);

    $ids = $others->push($owner)->pluck('id')->sort()->values();

    expect($channel->type)->toBe(ChannelType::GroupDirect)
        ->and($channel->visibility)->toBe(ChannelVisibility::Private)
        ->and($channel->name)->toBeNull()
        ->and($channel->slug)->toStartWith('dm-')
        ->and($channel->dm_key)->toBe('g:'.hash('sha256', $ids->implode(':')))
        ->and($channel->members()->count())->toBe(3)
        ->and($channel->channelMembers()->where('user_id', $owner->id)->value('notification_level'))
        ->toBe(NotificationLevel::All);
});

test('opening a group direct message with the same member set reuses the channel', function (): void {
    [$owner, $team, $others] = teamWithMembers(2);

    $first = app(OpenDirectMessage::class)->openForUsers($team, $owner, $others);
    // A different initiator, same member set, in any order.
    $second = app(OpenDirectMessage::class)->openForUsers($team, $others->first(), collect([$owner, $others->last()]));

    expect($second->id)->toBe($first->id)
        ->and(Channel::where('type', ChannelType::GroupDirect)->count())->toBe(1);
});

test('a two-person set collapses to a 1:1 direct message, not a group', function (): void {
    [$owner, $team, $others] = teamWithMembers(1);

    $channel = app(OpenDirectMessage::class)->openForUsers($team, $owner, $others);

    expect($channel->type)->toBe(ChannelType::Direct)
        ->and($channel->dm_key)->toBe(collect([$owner->id, $others->first()->id])->sort()->implode(':'));
});

test('opening a group direct message with an existing set re-adds a member who left', function (): void {
    [$owner, $team, $others] = teamWithMembers(2);
    $left = $others->last();

    $channel = app(OpenDirectMessage::class)->openForUsers($team, $owner, $others);
    $channel->channelMembers()->where('user_id', $left->id)->delete();
    expect($channel->members()->whereKey($left->id)->exists())->toBeFalse();

    app(OpenDirectMessage::class)->openForUsers($team, $owner, $others);

    expect($channel->fresh()->members()->whereKey($left->id)->exists())->toBeTrue()
        ->and($channel->channelMembers()->where('user_id', $left->id)->value('notification_level'))
        ->toBe(NotificationLevel::All);
});

test('opening a group direct message un-hides it for the initiator', function (): void {
    [$owner, $team, $others] = teamWithMembers(2);

    $channel = app(OpenDirectMessage::class)->openForUsers($team, $owner, $others);
    $channel->channelMembers()->where('user_id', $owner->id)->update(['hidden_at' => now()]);

    app(OpenDirectMessage::class)->openForUsers($team, $owner, $others);

    expect($channel->fresh()->channelMembers()->where('user_id', $owner->id)->value('hidden_at'))->toBeNull();
});
