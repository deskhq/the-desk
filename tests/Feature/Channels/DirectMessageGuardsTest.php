<?php

use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Teams\CreateTeam;
use App\Enums\ChannelType;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;

/**
 * Open a direct message between the owner and a fresh team member.
 *
 * @return array{0: Channel, 1: User, 2: User}
 */
function openDmWithNewMember(Team $team, User $owner): array
{
    $other = User::factory()->create();
    $team->memberships()->create(['user_id' => $other->id, 'role' => TeamRole::Member]);

    return [app(OpenDirectMessage::class)->handle($team, $owner, $other), $owner, $other];
}

test('a channel created through the store endpoint is always a standard channel', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)->post(route('channels.store', ['team' => $team->slug]), [
        'name' => 'Marketing',
        'visibility' => 'public',
    ]);

    $channel = Channel::where('team_id', $team->id)->where('slug', 'marketing')->firstOrFail();

    expect($channel->type)->toBe(ChannelType::Standard)
        ->and($channel->isDirect())->toBeFalse();
});

test('a direct message cannot be archived', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    [$dm] = openDmWithNewMember($team, $owner);

    $this->actingAs($owner)
        ->post(route('channels.archive', ['team' => $team->slug, 'channel' => $dm->slug]))
        ->assertForbidden();

    expect($dm->fresh()->isArchived())->toBeFalse();
});

test('direct messages are excluded from the browse directory', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    [$dm] = openDmWithNewMember($team, $owner);

    $this->actingAs($owner)
        ->get(route('channels.browse', ['team' => $team->slug]))
        ->assertInertia(fn ($page) => $page
            ->component('channels/Browse')
            ->where('joinableChannels', fn ($channels) => collect($channels)->doesntContain('id', $dm->id))
        );
});
