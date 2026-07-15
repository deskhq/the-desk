<?php

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditActivity;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Fetch the single audit entry of the given action for a team.
 */
function invitationAuditEntry(Team $team, AuditAction $action): AuditActivity
{
    return AuditActivity::query()
        ->where('team_id', $team->id)
        ->where('event', $action->value)
        ->sole();
}

/**
 * Create an owner and their team.
 *
 * @return array{0: User, 1: Team}
 */
function ownedTeam(): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    return [$owner, $team];
}

test('creating an invitation records an audit entry', function (): void {
    Notification::fake();
    [$owner, $team] = ownedTeam();

    $this->actingAs($owner)
        ->post(route('teams.invitations.store', $team), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Member->value,
        ])
        ->assertRedirect();

    $entry = invitationAuditEntry($team, AuditAction::InvitationCreated);

    expect($entry->causer_id)->toBe($owner->id);
    expect($entry->properties['email'])->toBe('invited@example.com');
    expect($entry->properties['role'])->toBe(TeamRole::Member->label());
});

test('resending an invitation records an audit entry', function (): void {
    Notification::fake();
    [$owner, $team] = ownedTeam();
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->post(route('teams.invitations.resend', [$team, $invitation]))
        ->assertRedirect();

    $entry = invitationAuditEntry($team, AuditAction::InvitationResent);

    expect($entry->causer_id)->toBe($owner->id);
    expect($entry->subject_id)->toBe($invitation->id);
    expect($entry->properties['email'])->toBe('invited@example.com');
});

test('a rate-limited resend records no audit entry', function (): void {
    Notification::fake();
    [$owner, $team] = ownedTeam();
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($owner)->post(route('teams.invitations.resend', [$team, $invitation]));
    $this->actingAs($owner)->post(route('teams.invitations.resend', [$team, $invitation]));

    expect(AuditActivity::query()->where('event', AuditAction::InvitationResent->value)->count())->toBe(1);
});

test('cancelling an invitation records an audit entry', function (): void {
    [$owner, $team] = ownedTeam();
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->delete(route('teams.invitations.destroy', [$team, $invitation]))
        ->assertRedirect();

    $entry = invitationAuditEntry($team, AuditAction::InvitationRevoked);

    expect($entry->causer_id)->toBe($owner->id);
    expect($entry->properties['email'])->toBe('invited@example.com');
});

test('accepting an invitation records an audit entry caused by the invitee', function (): void {
    [$owner, $team] = ownedTeam();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser)
        ->get(route('invitations.accept', $invitation))
        ->assertRedirect();

    $entry = invitationAuditEntry($team, AuditAction::InvitationAccepted);

    expect($entry->causer_id)->toBe($invitedUser->id);
    expect($entry->subject_id)->toBe($invitation->id);
    expect($entry->properties['email'])->toBe('invited@example.com');
});
