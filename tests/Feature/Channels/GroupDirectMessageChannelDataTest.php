<?php

use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Teams\CreateTeam;
use App\Data\ChannelData;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

/**
 * Add a plain member to the team and return them.
 */
function groupDataTeamMember(Team $team, string $name): User
{
    $user = User::factory()->create(['name' => $name]);
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    return $user;
}

test('a group direct channel serializes its participants viewer-relatively', function (): void {
    $owner = User::factory()->create(['name' => 'Owner']);
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $ana = groupDataTeamMember($team, 'Ana Pires');
    $tomas = groupDataTeamMember($team, 'Tomas K');

    $dm = app(OpenDirectMessage::class)->openForUsers($team, $owner, collect([$ana, $tomas]));

    $this->actingAs($owner);
    $view = ChannelData::fromChannel($dm->fresh());

    expect($view->isDirect)->toBeTrue()
        ->and($view->isGroupDirect)->toBeTrue()
        ->and($view->dmUserId)->toBeNull()
        ->and($view->dmParticipants)->toHaveCount(2)
        ->and(collect($view->dmParticipants)->pluck('name')->all())->toBe(['Ana Pires', 'Tomas K'])
        ->and($view->name)->toBe('Ana Pires, Tomas K');

    // The viewer never sees themselves in the participant list.
    $this->actingAs($ana);
    $anaView = ChannelData::fromChannel($dm->fresh());

    expect(collect($anaView->dmParticipants)->pluck('name')->all())->toBe(['Owner', 'Tomas K']);
});

test('a standard channel exposes no group participant data', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = $team->channels()->where('slug', 'general')->firstOrFail();

    $this->actingAs($owner);
    $view = ChannelData::fromChannel($channel);

    expect($view->isDirect)->toBeFalse()
        ->and($view->isGroupDirect)->toBeFalse()
        ->and($view->dmParticipants)->toBeNull();
});
