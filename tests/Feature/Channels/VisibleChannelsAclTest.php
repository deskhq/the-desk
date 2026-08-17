<?php

declare(strict_types=1);

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * A user in a team, joined to a mix of channels, alongside the two channels the
 * readings disagree about: a public one in the same team they never joined
 * (readable, not a membership) and a private one they never joined (neither).
 *
 * @return array{user: User, team: Team, member: array<int, string>, publicNotJoined: Channel, privateNotJoined: Channel, otherTeamChannel: Channel}
 */
function aclFixture(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    $joined = Channel::factory()->private()->for($team)->create();
    $joined->channelMembers()->create(['user_id' => $user->id]);

    // An archived channel the user still belongs to stays in the membership
    // reading — it is membership, not sidebar presence.
    $archivedJoined = Channel::factory()->private()->for($team)->create(['archived_at' => now()]);
    $archivedJoined->channelMembers()->create(['user_id' => $user->id]);

    // Same team, public, never joined: readable, but not a membership.
    $publicNotJoined = Channel::factory()->for($team)->create();

    // Same team, private, never joined: neither reading.
    $privateNotJoined = Channel::factory()->private()->for($team)->create();

    // Another team the user also belongs to; its channels must not leak into
    // this team's readings.
    $otherTeam = app(CreateTeam::class)->handle($user, 'Globex');
    $otherTeamChannel = Channel::factory()->for($otherTeam)->create();
    $otherTeamChannel->channelMembers()->create(['user_id' => $user->id]);

    return [
        'user' => $user,
        'team' => $team,
        'member' => [$general->id, $joined->id, $archivedJoined->id],
        'publicNotJoined' => $publicNotJoined,
        'privateNotJoined' => $privateNotJoined,
        'otherTeamChannel' => $otherTeamChannel,
    ];
}

it('scopes the membership reading to exactly the channels a user has joined in the team', function (): void {
    [
        'user' => $user,
        'team' => $team,
        'member' => $member,
        'publicNotJoined' => $publicNotJoined,
        'privateNotJoined' => $privateNotJoined,
        'otherTeamChannel' => $otherTeamChannel,
    ] = aclFixture();

    $ids = $user->memberChannelIds($team)->all();

    expect($ids)->toEqualCanonicalizing($member)
        ->and($ids)->not->toContain($publicNotJoined->id, $privateNotJoined->id, $otherTeamChannel->id);
});

it('widens the readable reading to every public channel in the team, joined or not', function (): void {
    [
        'user' => $user,
        'team' => $team,
        'member' => $member,
        'publicNotJoined' => $publicNotJoined,
        'privateNotJoined' => $privateNotJoined,
        'otherTeamChannel' => $otherTeamChannel,
    ] = aclFixture();

    $ids = $user->readableChannelIds($team)->all();

    expect($ids)->toEqualCanonicalizing([...$member, $publicNotJoined->id])
        ->and($ids)->not->toContain($privateNotJoined->id, $otherTeamChannel->id);
});

it('reads nothing in a team the user does not belong to, however public its channels', function (): void {
    $stranger = User::factory()->create();
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $public = Channel::factory()->for($team)->create();

    expect($stranger->readableChannelIds($team)->all())->toBe([])
        ->and($stranger->memberChannelIds($team)->all())->toBe([])
        ->and(Gate::forUser($stranger)->allows('view', $public))->toBeFalse();
});

it('excludes channels in other teams from the membership reading even when the user is a member', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $otherTeam = app(CreateTeam::class)->handle($user, 'Globex');
    $otherChannel = Channel::factory()->for($otherTeam)->create();
    $otherChannel->channelMembers()->create(['user_id' => $user->id]);

    expect($user->memberChannelIds($team)->all())->not->toContain($otherChannel->id);
});

it('excludes channels in the team the user never joined from the membership reading', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $stranger = User::factory()->create();
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);
    $private = Channel::factory()->private()->for($team)->create();
    $private->channelMembers()->create(['user_id' => $stranger->id]);

    expect($user->memberChannelIds($team)->all())->not->toContain($private->id);
});

/**
 * The split is the whole point of the two names, so it is pinned end to end
 * rather than only on the model: a public channel the viewer never joined opens
 * as a page and is absent from every membership-scoped surface. `browse` exists
 * precisely so they discover it and *join*; widening search or forwarding to
 * never-joined channels is a product decision, not this refactor.
 */
it('lets a public channel the viewer never joined be readable while staying out of the membership surfaces', function (): void {
    ['user' => $user, 'team' => $team, 'publicNotJoined' => $channel] = aclFixture();

    // Readable: the channel page opens, and the policy the page and the API
    // both consult agrees.
    $this->actingAs($user)
        ->get(route('channels.show', ['team' => $team, 'channel' => $channel]))
        ->assertOk();

    expect(Gate::forUser($user)->allows('view', $channel))->toBeTrue();

    // Not a membership: forwarding refuses it as a destination, and the id set
    // the thread inbox and search filter on excludes it.
    $source = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();
    $message = Message::factory()->for($source)->for($user)->create();

    $this->actingAs($user)
        ->post(route('channels.messages.forward', ['team' => $team, 'channel' => $source, 'message' => $message]), [
            'client_uuid' => (string) Str::uuid(),
            'target_channel_id' => $channel->id,
        ])
        ->assertSessionHasErrors('target_channel_id');

    expect($user->memberChannelIds($team)->all())->not->toContain($channel->id);
});
