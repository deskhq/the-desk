<?php

use App\Actions\Channels\JoinChannel;
use App\Actions\Teams\CreateTeam;
use App\Enums\AuditAction;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Exceptions\AuditLogImmutableException;
use App\Models\AuditActivity;
use App\Models\Channel;
use App\Models\Message;
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

test('renaming a team records an audit entry with old and new names', function () {
    [$owner, $team] = auditTeam();

    $this->actingAs($owner)
        ->patch(route('teams.update', $team), ['name' => 'Acme Corp'])
        ->assertRedirect();

    $entry = auditEntry($team, AuditAction::TeamRenamed);

    expect($entry->causer_id)->toBe($owner->id);
    expect($entry->properties['old_name'])->toBe('Acme');
    expect($entry->properties['new_name'])->toBe('Acme Corp');
});

test('renaming a team to the same name records nothing', function () {
    [$owner, $team] = auditTeam();

    $this->actingAs($owner)
        ->patch(route('teams.update', $team), ['name' => 'Acme'])
        ->assertRedirect();

    expect(AuditActivity::query()->where('team_id', $team->id)->count())->toBe(0);
});

test('changing a member role records an audit entry with old and new roles', function () {
    [$owner, $team] = auditTeam();
    $member = auditMember($team);

    $this->actingAs($owner)
        ->patch(route('teams.members.update', [$team, $member]), ['role' => TeamRole::Admin->value])
        ->assertRedirect();

    $entry = auditEntry($team, AuditAction::MemberRoleChanged);

    expect($entry->causer_id)->toBe($owner->id);
    expect($entry->subject_id)->toBe($member->id);
    expect($entry->properties['old_role'])->toBe(TeamRole::Member->label());
    expect($entry->properties['new_role'])->toBe(TeamRole::Admin->label());
});

test('changing a member role to the same role records nothing', function () {
    [$owner, $team] = auditTeam();
    $member = auditMember($team);

    $this->actingAs($owner)
        ->patch(route('teams.members.update', [$team, $member]), ['role' => TeamRole::Member->value])
        ->assertRedirect();

    expect(AuditActivity::query()->where('team_id', $team->id)->count())->toBe(0);
});

test('removing a member records an audit entry', function () {
    [$owner, $team] = auditTeam();
    $member = auditMember($team);

    $this->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $member]))
        ->assertRedirect();

    $entry = auditEntry($team, AuditAction::MemberRemoved);

    expect($entry->causer_id)->toBe($owner->id);
    expect($entry->properties['member_name'])->toBe($member->name);
});

test('transferring ownership records an audit entry', function () {
    [$owner, $team] = auditTeam();
    $member = auditMember($team, TeamRole::Admin);

    $this->actingAs($owner)
        ->post(route('teams.members.transfer-ownership', [$team, $member]), ['password' => 'password'])
        ->assertRedirect();

    $entry = auditEntry($team, AuditAction::OwnershipTransferred);

    expect($entry->causer_id)->toBe($owner->id);
    expect($entry->properties['new_owner_name'])->toBe($member->name);
});

test('creating a channel records an audit entry', function () {
    [$owner, $team] = auditTeam();

    $this->actingAs($owner)
        ->post(route('channels.store', $team), [
            'name' => 'marketing',
            'visibility' => ChannelVisibility::Public->value,
        ])
        ->assertRedirect();

    $entry = auditEntry($team, AuditAction::ChannelCreated);

    expect($entry->causer_id)->toBe($owner->id);
    expect($entry->properties['channel_name'])->toBe('marketing');
});

test('archiving a channel records an audit entry', function () {
    [$owner, $team] = auditTeam();
    $channel = Channel::factory()->for($team)->create(['created_by' => $owner->id]);

    $this->actingAs($owner)
        ->post(route('channels.archive', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertRedirect();

    $entry = auditEntry($team, AuditAction::ChannelArchived);

    expect($entry->causer_id)->toBe($owner->id);
    expect($entry->properties['channel_name'])->toBe($channel->name);
});

test('adding a channel member records an audit entry', function () {
    [$owner, $team] = auditTeam();
    $member = auditMember($team);
    $channel = Channel::factory()->for($team)->private()->create(['slug' => 'secret']);
    app(JoinChannel::class)->handle($channel, $owner);

    $this->actingAs($owner)
        ->post(route('channels.members.store', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'user_id' => $member->id,
        ])
        ->assertRedirect();

    $entry = auditEntry($team, AuditAction::ChannelMemberAdded);

    expect($entry->causer_id)->toBe($owner->id);
    expect($entry->properties['channel_name'])->toBe($channel->name);
    expect($entry->properties['member_name'])->toBe($member->name);
});

test('removing a channel member records an audit entry', function () {
    [$owner, $team] = auditTeam();
    $member = auditMember($team);
    $channel = Channel::factory()->for($team)->private()->create(['slug' => 'secret']);
    app(JoinChannel::class)->handle($channel, $owner);
    app(JoinChannel::class)->handle($channel, $member);

    $this->actingAs($owner)
        ->delete(route('channels.members.destroy', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'user_id' => $member->id,
        ])
        ->assertRedirect();

    $entry = auditEntry($team, AuditAction::ChannelMemberRemoved);

    expect($entry->causer_id)->toBe($owner->id);
    expect($entry->properties['member_name'])->toBe($member->name);
});

test('a moderator deleting another members message records an audit entry', function () {
    [$owner, $team] = auditTeam();
    $member = auditMember($team);
    $general = $team->channels()->where('slug', Channel::GENERAL_SLUG)->firstOrFail();
    $message = Message::factory()->for($general)->for($member)->create();

    $this->actingAs($owner)
        ->delete(route('channels.messages.destroy', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'message' => $message->id,
        ]))
        ->assertRedirect();

    $entry = auditEntry($team, AuditAction::MessageDeleted);

    expect($entry->causer_id)->toBe($owner->id);
    expect($entry->properties['author_name'])->toBe($member->name);
});

test('a member deleting their own message records nothing', function () {
    [$owner, $team] = auditTeam();
    $member = auditMember($team);
    $general = $team->channels()->where('slug', Channel::GENERAL_SLUG)->firstOrFail();
    $message = Message::factory()->for($general)->for($member)->create();

    $this->actingAs($member)
        ->delete(route('channels.messages.destroy', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'message' => $message->id,
        ]))
        ->assertRedirect();

    expect(AuditActivity::query()->where('event', AuditAction::MessageDeleted->value)->count())->toBe(0);
});

test('an audit entry belongs to its team', function () {
    [$owner, $team] = auditTeam();
    $entry = AuditActivity::factory()->forTeam($team)->causedBy($owner)->create();

    expect($entry->team->id)->toBe($team->id);
});

test('an audit entry cannot be updated', function () {
    $entry = AuditActivity::factory()->create();

    expect(fn () => $entry->update(['description' => 'tampered']))
        ->toThrow(AuditLogImmutableException::class);

    expect($entry->fresh()->description)->not->toBe('tampered');
});

test('an audit entry cannot be deleted', function () {
    $entry = AuditActivity::factory()->create();

    expect(fn () => $entry->delete())
        ->toThrow(AuditLogImmutableException::class);

    expect(AuditActivity::query()->whereKey($entry->id)->exists())->toBeTrue();
});

test('an admin can view the audit log', function () {
    [$owner, $team] = auditTeam();
    $admin = auditMember($team, TeamRole::Admin);
    AuditActivity::factory()->forTeam($team)->causedBy($owner)->ofAction(AuditAction::ChannelCreated)->create();

    $this->actingAs($admin)
        ->get(route('teams.audit.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/Audit')
            ->has('entries.data', 1)
            ->where('entries.data.0.action', AuditAction::ChannelCreated->value)
        );
});

test('a plain member cannot view the audit log', function () {
    [$owner, $team] = auditTeam();
    $member = auditMember($team);

    $this->actingAs($member)
        ->get(route('teams.audit.index', $team))
        ->assertForbidden();
});

test('a non member cannot view the audit log', function () {
    [$owner, $team] = auditTeam();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('teams.audit.index', $team))
        ->assertForbidden();
});

test('the audit log is not available for a personal team', function () {
    $user = User::factory()->create();
    $personal = $user->personalTeam();

    $this->actingAs($user)
        ->get(route('teams.audit.index', $personal))
        ->assertForbidden();
});

test('the audit log only shows the current teams entries', function () {
    [$owner, $team] = auditTeam();
    $otherTeam = Team::factory()->create();

    AuditActivity::factory()->forTeam($team)->causedBy($owner)->ofAction(AuditAction::ChannelCreated)->create();
    AuditActivity::factory()->forTeam($otherTeam)->ofAction(AuditAction::TeamRenamed)->create();

    $this->actingAs($owner)
        ->get(route('teams.audit.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.action', AuditAction::ChannelCreated->value)
        );
});

test('the audit log can be filtered by action', function () {
    [$owner, $team] = auditTeam();

    AuditActivity::factory()->forTeam($team)->causedBy($owner)->ofAction(AuditAction::ChannelCreated)->create();
    AuditActivity::factory()->forTeam($team)->causedBy($owner)->ofAction(AuditAction::MemberRemoved)->create();

    $this->actingAs($owner)
        ->get(route('teams.audit.index', ['team' => $team, 'action' => AuditAction::ChannelCreated->value]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.action', AuditAction::ChannelCreated->value)
        );
});

test('the audit log can be filtered by actor', function () {
    [$owner, $team] = auditTeam();
    $admin = auditMember($team, TeamRole::Admin);

    AuditActivity::factory()->forTeam($team)->causedBy($owner)->ofAction(AuditAction::ChannelCreated)->create();
    AuditActivity::factory()->forTeam($team)->causedBy($admin)->ofAction(AuditAction::MemberRemoved)->create();

    $this->actingAs($owner)
        ->get(route('teams.audit.index', ['team' => $team, 'actor' => $admin->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.action', AuditAction::MemberRemoved->value)
        );
});
