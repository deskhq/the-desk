<?php

declare(strict_types=1);

use App\Actions\Teams\CreateTeam;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Events\AuditableActionOccurred;
use App\Exceptions\AuditLogImmutableException;
use App\Models\AuditActivity;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a team (with its #general channel) owned by a fresh user.
 *
 * @return array{0: User, 1: Team}
 */
function auditTeam(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    return [$owner, $team];
}

/**
 * Attach a member to a team with the given role.
 */
function auditMember(Team $team, TeamRole $role = TeamRole::Member): User
{
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => $role->value]);

    return $member;
}

/**
 * Fetch the single audit entry of the given action for a team.
 */
function auditEntry(Team $team, AuditAction $action): AuditActivity
{
    return AuditActivity::query()
        ->where('team_id', $team->id)
        ->where('event', $action->value)
        ->sole();
}

test('dispatching an auditable action records it against the team', function (): void {
    [$owner, $team] = auditTeam();
    $channel = Channel::factory()->for($team)->create();

    event(new AuditableActionOccurred($team, $owner, AuditAction::ChannelArchived, $channel, [
        'channel_name' => $channel->name,
    ]));

    $entry = auditEntry($team, AuditAction::ChannelArchived);

    expect($entry->causer_id)->toBe($owner->id);
    expect($entry->subject_id)->toBe($channel->id);
    expect($entry->properties['channel_name'])->toBe($channel->name);
});

test('an auditable action with no human causer is recorded without an actor', function (): void {
    [, $team] = auditTeam();

    event(new AuditableActionOccurred($team, null, AuditAction::WebhookSubscriptionAutoDisabled));

    expect(auditEntry($team, AuditAction::WebhookSubscriptionAutoDisabled)->causer_id)->toBeNull();
});

test('an audit entry keeps the target it was recorded against', function (): void {
    [$owner, $team] = auditTeam();
    $member = auditMember($team);

    event(new AuditableActionOccurred($team, $owner, AuditAction::MemberRemoved, $member, [
        'member_name' => $member->name,
    ]));

    $entry = auditEntry($team, AuditAction::MemberRemoved);

    expect($entry->subject_id)->toBe($member->id);
    expect($entry->description)->toBe(AuditAction::MemberRemoved->label());
});

test('an audit entry belongs to its team', function (): void {
    [$owner, $team] = auditTeam();
    $entry = AuditActivity::factory()->forTeam($team)->causedBy($owner)->create();

    expect($entry->team->id)->toBe($team->id);
});

test('an audit entry cannot be updated', function (): void {
    $entry = AuditActivity::factory()->create();

    expect(fn () => $entry->update(['description' => 'tampered']))
        ->toThrow(AuditLogImmutableException::class);

    expect($entry->fresh()->description)->not->toBe('tampered');
});

test('an audit entry cannot be deleted', function (): void {
    $entry = AuditActivity::factory()->create();

    expect(fn () => $entry->delete())
        ->toThrow(AuditLogImmutableException::class);

    expect(AuditActivity::query()->whereKey($entry->id)->exists())->toBeTrue();
});

test('an admin can view the audit log', function (): void {
    [$owner, $team] = auditTeam();
    $admin = auditMember($team, TeamRole::Admin);
    AuditActivity::factory()->forTeam($team)->causedBy($owner)->ofAction(AuditAction::ChannelCreated)->create();

    $this->actingAs($admin)
        ->get(route('teams.audit.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('teams/Audit')
            ->has('entries.data', 1)
            ->where('entries.data.0.action', AuditAction::ChannelCreated->value)
        );
});

test('a plain member cannot view the audit log', function (): void {
    [$owner, $team] = auditTeam();
    $member = auditMember($team);

    $this->actingAs($member)
        ->get(route('teams.audit.index', $team))
        ->assertForbidden();
});

test('a non member cannot view the audit log', function (): void {
    [$owner, $team] = auditTeam();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('teams.audit.index', $team))
        ->assertForbidden();
});

test('the audit log is not available for a personal team', function (): void {
    $user = User::factory()->create();
    $personal = $user->personalTeam();

    $this->actingAs($user)
        ->get(route('teams.audit.index', $personal))
        ->assertForbidden();
});

test('the audit log only shows the current teams entries', function (): void {
    [$owner, $team] = auditTeam();
    $otherTeam = Team::factory()->create();

    AuditActivity::factory()->forTeam($team)->causedBy($owner)->ofAction(AuditAction::ChannelCreated)->create();
    AuditActivity::factory()->forTeam($otherTeam)->ofAction(AuditAction::TeamRenamed)->create();

    $this->actingAs($owner)
        ->get(route('teams.audit.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.action', AuditAction::ChannelCreated->value)
        );
});

test('the audit log can be filtered by action', function (): void {
    [$owner, $team] = auditTeam();

    AuditActivity::factory()->forTeam($team)->causedBy($owner)->ofAction(AuditAction::ChannelCreated)->create();
    AuditActivity::factory()->forTeam($team)->causedBy($owner)->ofAction(AuditAction::MemberRemoved)->create();

    $this->actingAs($owner)
        ->get(route('teams.audit.index', ['team' => $team, 'action' => AuditAction::ChannelCreated->value]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.action', AuditAction::ChannelCreated->value)
        );
});

test('the audit log can be filtered by actor', function (): void {
    [$owner, $team] = auditTeam();
    $admin = auditMember($team, TeamRole::Admin);

    AuditActivity::factory()->forTeam($team)->causedBy($owner)->ofAction(AuditAction::ChannelCreated)->create();
    AuditActivity::factory()->forTeam($team)->causedBy($admin)->ofAction(AuditAction::MemberRemoved)->create();

    $this->actingAs($owner)
        ->get(route('teams.audit.index', ['team' => $team, 'actor' => $admin->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.action', AuditAction::MemberRemoved->value)
        );
});
