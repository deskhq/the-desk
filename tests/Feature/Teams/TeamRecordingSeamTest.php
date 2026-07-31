<?php

declare(strict_types=1);

use App\Actions\Teams\AcceptTeamInvitation;
use App\Actions\Teams\CreateTeam;
use App\Actions\Teams\CreateTeamInvitation;
use App\Actions\Teams\RemoveTeamMember;
use App\Actions\Teams\ResendTeamInvitation;
use App\Actions\Teams\RevokeTeamInvitation;
use App\Actions\Teams\TransferTeamOwnership;
use App\Actions\Teams\UpdateTeam;
use App\Actions\Teams\UpdateTeamMemberRole;
use App\Enums\AuditAction;
use App\Enums\ChannelCreationPolicy;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\AuditActivity;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * The workspace-administration facts, recorded by the Actions that own the
 * mutation rather than by the controllers that used to. Every rule is reached
 * by calling the Action, not by driving HTTP.
 */
beforeEach(function (): void {
    Notification::fake();

    $this->owner = User::factory()->create();
    $this->team = app(CreateTeam::class)->handle($this->owner, 'Acme');
    $this->member = User::factory()->create();
    $this->team->memberships()->create(['user_id' => $this->member->id, 'role' => TeamRole::Member]);
});

/**
 * Fetch the single audit entry of an action recorded against the test's team.
 */
function teamSeamEntry(AuditAction $action): AuditActivity
{
    return AuditActivity::query()
        ->where('team_id', test()->team->id)
        ->where('event', $action->value)
        ->sole();
}

/**
 * Count the audit entries of an action recorded against the test's team.
 */
function teamSeamEntries(AuditAction $action): int
{
    return AuditActivity::query()
        ->where('team_id', test()->team->id)
        ->where('event', $action->value)
        ->count();
}

it('records a workspace rename with the name it replaced', function (): void {
    app(UpdateTeam::class)->handle($this->team, $this->owner, ['name' => 'Acme Corp']);

    $entry = teamSeamEntry(AuditAction::TeamRenamed);

    expect($entry->causer_id)->toBe($this->owner->id);
    expect($entry->properties['old_name'])->toBe('Acme');
    expect($entry->properties['new_name'])->toBe('Acme Corp');
});

it('records nothing when an update moves no attribute', function (): void {
    app(UpdateTeam::class)->handle($this->team, $this->owner, ['name' => 'Acme']);

    expect(AuditActivity::query()->where('team_id', $this->team->id)->count())->toBe(0);
});

it('records a channel creation policy change per visibility', function (): void {
    app(UpdateTeam::class)->handle($this->team, $this->owner, [
        'public_channel_creation_policy' => ChannelCreationPolicy::Admins,
    ]);

    $entry = teamSeamEntry(AuditAction::ChannelCreationPolicyChanged);

    expect($entry->properties['visibility'])->toBe(ChannelVisibility::Public->label());
    expect($entry->properties['new_policy'])->toBe(ChannelCreationPolicy::Admins->label());
});

it('records a member role change with the roles it moved between', function (): void {
    app(UpdateTeamMemberRole::class)->handle($this->team, $this->member, TeamRole::Admin, $this->owner);

    $entry = teamSeamEntry(AuditAction::MemberRoleChanged);

    expect($entry->causer_id)->toBe($this->owner->id);
    expect($entry->subject_id)->toBe($this->member->id);
    expect($entry->properties['old_role'])->toBe(TeamRole::Member->label());
    expect($entry->properties['new_role'])->toBe(TeamRole::Admin->label());
});

it('records nothing when a member keeps the role they had', function (): void {
    app(UpdateTeamMemberRole::class)->handle($this->team, $this->member, TeamRole::Member, $this->owner);

    expect(teamSeamEntries(AuditAction::MemberRoleChanged))->toBe(0);
});

it('records a member being removed from the workspace', function (): void {
    app(RemoveTeamMember::class)->handle($this->team, $this->member, $this->owner);

    $entry = teamSeamEntry(AuditAction::MemberRemoved);

    expect($entry->causer_id)->toBe($this->owner->id);
    expect($entry->properties['member_name'])->toBe($this->member->name);
    expect($this->team->memberships()->where('user_id', $this->member->id)->exists())->toBeFalse();
});

it('records an ownership transfer', function (): void {
    app(TransferTeamOwnership::class)->handle($this->team, $this->owner, $this->member);

    $entry = teamSeamEntry(AuditAction::OwnershipTransferred);

    expect($entry->causer_id)->toBe($this->owner->id);
    expect($entry->properties['new_owner_name'])->toBe($this->member->name);
});

it('records an invitation being created', function (): void {
    $invitation = app(CreateTeamInvitation::class)->handle($this->team, $this->owner, 'new@example.test', TeamRole::Admin);

    $entry = teamSeamEntry(AuditAction::InvitationCreated);

    expect($entry->causer_id)->toBe($this->owner->id);
    expect($entry->subject_id)->toBe($invitation->id);
    expect($entry->properties['email'])->toBe('new@example.test');
    expect($entry->properties['role'])->toBe(TeamRole::Admin->label());
});

it('records an invitation being revoked while it still exists', function (): void {
    $invitation = app(CreateTeamInvitation::class)->handle($this->team, $this->owner, 'new@example.test', TeamRole::Member);

    app(RevokeTeamInvitation::class)->handle($invitation, $this->owner);

    expect(teamSeamEntry(AuditAction::InvitationRevoked)->properties['email'])->toBe('new@example.test');
    expect(TeamInvitation::query()->whereKey($invitation->id)->exists())->toBeFalse();
});

it('records an invitation being resent and refreshes its expiry', function (): void {
    $invitation = app(CreateTeamInvitation::class)->handle($this->team, $this->owner, 'new@example.test', TeamRole::Member);
    $invitation->update(['expires_at' => now()->subDay()]);

    app(ResendTeamInvitation::class)->handle($invitation, $this->owner);

    expect(teamSeamEntry(AuditAction::InvitationResent)->properties['email'])->toBe('new@example.test');
    expect($invitation->fresh()->expires_at->isFuture())->toBeTrue();
});

it('records an invitation being accepted by the person who accepted it', function (): void {
    $invitation = app(CreateTeamInvitation::class)->handle($this->team, $this->owner, 'new@example.test', TeamRole::Member);
    $newcomer = User::factory()->create(['email' => 'new@example.test']);

    app(AcceptTeamInvitation::class)->handle($invitation, $newcomer);

    $entry = teamSeamEntry(AuditAction::InvitationAccepted);

    expect($entry->causer_id)->toBe($newcomer->id);
    expect($newcomer->fresh()->belongsToTeam($this->team))->toBeTrue();
});
