<?php

use App\Enums\ChannelCreationPolicy;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The workspace permission props (#1250)
|--------------------------------------------------------------------------
|
| Six shared props gate an affordance on what the viewer may do in the
| workspace they are in. They are answered by one derivation per request
| rather than by six, and this file pins both halves of that: the flags each
| role is handed — the behavioural contract, which the collapse leaves
| untouched — and the number of times the derivation runs, which is the point
| of it.
|
| The seam is the Inertia visit, like the rest of the shell-contract work:
| what a navigation ships is an HTTP fact. `WorkspacePermissionsTest` states
| the derivation itself, without a round-trip.
|
*/

test('a workspace visit reports the six permission props for the viewer role', function (
    string $role,
    bool $administers,
): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $viewer = $role === TeamRole::Owner->value
        ? $owner
        : teamMemberInChannel($general, [], TeamRole::from($role));

    visitWorkspaceAs($viewer, $team)
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('canInviteToCurrentTeam', $administers)
            ->where('canUpdateCurrentTeam', $administers)
            ->where('canViewCurrentTeamAudit', $administers)
            ->where('canViewCurrentTeamSecurityLog', $administers)
            ->where('canManageCurrentTeamIntegrations', $administers)
            // Both visibilities are self-service by default, whatever the role.
            ->where('creatableChannelVisibilities', [
                ChannelVisibility::Public->value,
                ChannelVisibility::Private->value,
            ])
        );
})->with([
    'owner' => [TeamRole::Owner->value, true],
    'admin' => [TeamRole::Admin->value, true],
    'member' => [TeamRole::Member->value, false],
]);

test('the creatable visibilities still follow the workspace creation policy', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $team->update(['private_channel_creation_policy' => ChannelCreationPolicy::Admins]);

    $member = teamMemberInChannel($general, [], TeamRole::Member);

    visitWorkspaceAs($member, $team)
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('creatableChannelVisibilities', [ChannelVisibility::Public->value])
        );

    visitWorkspaceAs($owner, $team)
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('creatableChannelVisibilities', [
                ChannelVisibility::Public->value,
                ChannelVisibility::Private->value,
            ])
        );
});

test('off a workspace route the six props fall back to what a page without a shell shows', function (): void {
    $user = User::factory()->create();

    // A personal workspace is the viewer's current team on every settings page,
    // so the five flags describe *it*: its owner may rename and invite to it,
    // while the evidence logs and the integrations surface are withheld from a
    // personal workspace outright. With no shell there is no channel-creation
    // affordance to describe either.
    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('canInviteToCurrentTeam', true)
            ->where('canUpdateCurrentTeam', true)
            ->where('canViewCurrentTeamAudit', false)
            ->where('canViewCurrentTeamSecurityLog', false)
            ->where('canManageCurrentTeamIntegrations', false)
            ->where('creatableChannelVisibilities', [])
        );
});

test('a guest page reports no workspace permissions at all', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('canInviteToCurrentTeam', false)
            ->where('canUpdateCurrentTeam', false)
            ->where('canViewCurrentTeamAudit', false)
            ->where('canViewCurrentTeamSecurityLog', false)
            ->where('canManageCurrentTeamIntegrations', false)
            ->where('creatableChannelVisibilities', [])
        );
});

test('a workspace visit derives the viewer permissions once for all six props', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    /** @var array<int, string> $abilities */
    $abilities = [];

    Gate::after(function (?User $user, string $ability) use (&$abilities): null {
        $abilities[] = $ability;

        return null;
    });

    visitWorkspaceAs($viewer, $team)->assertOk();

    // `viewAudit` stands in for the fifteen abilities one TeamPermissions
    // projection answers: before the collapse each of the five flag props asked
    // for a projection of its own, so this counted five.
    expect(array_count_values($abilities)['viewAudit'] ?? 0)->toBe(1);
});
