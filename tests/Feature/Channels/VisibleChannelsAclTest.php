<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;

/**
 * A user in a team, joined to a mix of channels, alongside channels they cannot
 * see: one in the same team they never joined, and one in another team.
 *
 * @return array{user: User, team: Team, visible: array<int, string>, hidden: array<int, string>}
 */
function aclFixture(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    $joined = Channel::factory()->for($team)->create();
    $joined->channelMembers()->create(['user_id' => $user->id]);

    // An archived channel the user still belongs to stays in the ACL — "visible"
    // for authorization is membership, not sidebar presence.
    $archivedJoined = Channel::factory()->for($team)->create(['archived_at' => now()]);
    $archivedJoined->channelMembers()->create(['user_id' => $user->id]);

    // Same team, never joined — must not be visible.
    $notJoined = Channel::factory()->for($team)->create();

    // Another team the user also belongs to; its channels must not leak into
    // this team's ACL.
    $otherTeam = app(CreateTeam::class)->handle($user, 'Globex');
    $otherTeamChannel = Channel::factory()->for($otherTeam)->create();
    $otherTeamChannel->channelMembers()->create(['user_id' => $user->id]);

    return [
        'user' => $user,
        'team' => $team,
        'visible' => [$general->id, $joined->id, $archivedJoined->id],
        'hidden' => [$notJoined->id, $otherTeamChannel->id],
    ];
}

it('returns exactly the channels a user has joined in the team', function (): void {
    ['user' => $user, 'team' => $team, 'visible' => $visible, 'hidden' => $hidden] = aclFixture();

    $ids = $user->visibleChannelIds($team)->all();

    expect($ids)->toEqualCanonicalizing($visible)
        ->and($ids)->not->toContain(...$hidden);
});

it('excludes channels in other teams even when the user is a member', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $otherTeam = app(CreateTeam::class)->handle($user, 'Globex');
    $otherChannel = Channel::factory()->for($otherTeam)->create();
    $otherChannel->channelMembers()->create(['user_id' => $user->id]);

    expect($user->visibleChannelIds($team)->all())->not->toContain($otherChannel->id);
});

it('excludes channels in the team the user never joined', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $stranger = User::factory()->create();
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);
    $private = Channel::factory()->for($team)->create();
    $private->channelMembers()->create(['user_id' => $stranger->id]);

    expect($user->visibleChannelIds($team)->all())->not->toContain($private->id);
});
