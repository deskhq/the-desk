<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\AuditAction;
use App\Enums\ChannelCreationPolicy;
use App\Enums\TeamRole;
use App\Models\AuditActivity;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a workspace starts with both kinds of channel open to every member', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    expect($team->public_channel_creation_policy)->toBe(ChannelCreationPolicy::Members)
        ->and($team->private_channel_creation_policy)->toBe(ChannelCreationPolicy::Members);
});

test('an admin saves the channel-creation policies', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)
        ->patch(route('teams.update', ['team' => $team->slug]), [
            'public_channel_creation_policy' => ChannelCreationPolicy::Admins->value,
            'private_channel_creation_policy' => ChannelCreationPolicy::Members->value,
        ])
        ->assertSessionHasNoErrors();

    $team->refresh();

    expect($team->public_channel_creation_policy)->toBe(ChannelCreationPolicy::Admins)
        ->and($team->private_channel_creation_policy)->toBe(ChannelCreationPolicy::Members)
        ->and($team->name)->toBe('Acme');
});

test('renaming the workspace on its own leaves the policies alone', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->update(['public_channel_creation_policy' => ChannelCreationPolicy::Admins]);

    $this->actingAs($owner)
        ->patch(route('teams.update', ['team' => $team->slug]), ['name' => 'Acme Corp'])
        ->assertSessionHasNoErrors();

    $team->refresh();

    expect($team->name)->toBe('Acme Corp')
        ->and($team->public_channel_creation_policy)->toBe(ChannelCreationPolicy::Admins);
});

test('an unrecognized channel-creation policy is rejected', function (string $field): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)
        ->patch(route('teams.update', ['team' => $team->slug]), [$field => 'nobody'])
        ->assertSessionHasErrors($field);

    expect($team->fresh()->creationPolicyFor(App\Enums\ChannelVisibility::Public))
        ->toBe(ChannelCreationPolicy::Members);
})->with(['public_channel_creation_policy', 'private_channel_creation_policy']);

test('a plain member cannot change the channel-creation policies', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $this->actingAs($member)
        ->patch(route('teams.update', ['team' => $team->slug]), [
            'public_channel_creation_policy' => ChannelCreationPolicy::Admins->value,
        ])
        ->assertForbidden();

    expect($team->fresh()->public_channel_creation_policy)->toBe(ChannelCreationPolicy::Members);
});

test('changing a channel-creation policy is recorded in the audit log', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)
        ->patch(route('teams.update', ['team' => $team->slug]), [
            'public_channel_creation_policy' => ChannelCreationPolicy::Admins->value,
        ]);

    $entry = AuditActivity::query()
        ->where('event', AuditAction::ChannelCreationPolicyChanged->value)
        ->sole();

    expect($entry->properties['visibility'])->toBe('public')
        ->and($entry->properties['old_policy'])->toBe(ChannelCreationPolicy::Members->value)
        ->and($entry->properties['new_policy'])->toBe(ChannelCreationPolicy::Admins->value);
});

test('re-saving the standing policy records nothing', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)
        ->patch(route('teams.update', ['team' => $team->slug]), [
            'public_channel_creation_policy' => ChannelCreationPolicy::Members->value,
            'private_channel_creation_policy' => ChannelCreationPolicy::Members->value,
        ]);

    expect(AuditActivity::query()->where('event', AuditAction::ChannelCreationPolicyChanged->value)->count())->toBe(0);
});

test('the workspace admin page carries the policies and their options', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->update(['private_channel_creation_policy' => ChannelCreationPolicy::Admins]);

    $this->actingAs($owner)
        ->get(route('teams.edit', ['team' => $team->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('team.publicChannelCreationPolicy', ChannelCreationPolicy::Members->value)
            ->where('team.privateChannelCreationPolicy', ChannelCreationPolicy::Admins->value)
            ->where('channelCreationPolicies.1.value', ChannelCreationPolicy::Admins->value)
            ->etc());
});
