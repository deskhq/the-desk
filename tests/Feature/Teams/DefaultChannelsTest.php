<?php

use App\Actions\Sso\ProvisionSsoUser;
use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\TeamInvitation;
use App\Models\User;

test('accepting an invitation joins the newcomer to every default channel', function (): void {
    $owner = User::factory()->create();
    $newcomer = User::factory()->create(['email' => 'newcomer@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $announcements = Channel::factory()->for($team)->create(['name' => 'Announcements', 'slug' => 'announcements', 'is_default' => true]);
    $watercooler = Channel::factory()->for($team)->create(['name' => 'Watercooler', 'slug' => 'watercooler', 'is_default' => false]);

    $invitation = TeamInvitation::factory()->for($team)->create([
        'email' => 'newcomer@example.com',
        'role' => TeamRole::Member,
    ]);

    $this->actingAs($newcomer)
        ->get(route('invitations.accept', ['invitation' => $invitation->code]))
        ->assertRedirect();

    expect($announcements->members()->whereKey($newcomer->id)->exists())->toBeTrue()
        ->and($watercooler->members()->whereKey($newcomer->id)->exists())->toBeFalse();
});

test('a joining member always lands in #general even with no default marked', function (): void {
    $owner = User::factory()->create();
    $newcomer = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = $team->channels()->where('slug', Channel::GENERAL_SLUG)->sole();

    $team->memberships()->create(['user_id' => $newcomer->id, 'role' => TeamRole::Member]);

    expect($general->members()->whereKey($newcomer->id)->exists())->toBeTrue();
});

test('a directory-provisioned user joins the default channels too', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    config(['sso.default_team_id' => $team->id]);

    $announcements = Channel::factory()->for($team)->create(['slug' => 'announcements', 'is_default' => true]);

    $user = app(ProvisionSsoUser::class)->handle('oidc', 'sub-123', 'jordan@example.com', 'Jordan Rivers');

    expect($announcements->members()->whereKey($user->id)->exists())->toBeTrue()
        ->and($team->channels()->where('slug', Channel::GENERAL_SLUG)->sole()->members()->whereKey($user->id)->exists())->toBeTrue();
});

test('marking a channel default does not sweep the existing members in', function (): void {
    $owner = User::factory()->create();
    $existing = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $existing->id, 'role' => TeamRole::Member]);

    $announcements = Channel::factory()->for($team)->create(['slug' => 'announcements', 'is_default' => false]);
    $announcements->update(['is_default' => true]);

    expect($announcements->members()->whereKey($existing->id)->exists())->toBeFalse();

    // Only someone arriving after the flag was set is placed in it.
    $newcomer = User::factory()->create();
    $team->memberships()->create(['user_id' => $newcomer->id, 'role' => TeamRole::Member]);

    expect($announcements->members()->whereKey($newcomer->id)->exists())->toBeTrue();
});

test('a private or archived channel is never joined as a default', function (): void {
    $owner = User::factory()->create();
    $newcomer = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $private = Channel::factory()->for($team)->private()->create(['slug' => 'secret', 'is_default' => true]);
    $archived = Channel::factory()->for($team)->create(['slug' => 'retired', 'is_default' => true, 'archived_at' => now()]);

    $team->memberships()->create(['user_id' => $newcomer->id, 'role' => TeamRole::Member]);

    expect($private->members()->whereKey($newcomer->id)->exists())->toBeFalse()
        ->and($archived->members()->whereKey($newcomer->id)->exists())->toBeFalse();
});

test('joining a default channel posts no notice into it', function (): void {
    $owner = User::factory()->create();
    $newcomer = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $announcements = Channel::factory()->for($team)->create(['slug' => 'announcements', 'is_default' => true]);

    $team->memberships()->create(['user_id' => $newcomer->id, 'role' => TeamRole::Member]);

    expect($announcements->messages()->count())->toBe(0);
});

test('a team admin marks a public channel as a default and takes it back', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->create(['slug' => 'announcements']);
    $channel->channelMembers()->create(['user_id' => $owner->id]);

    $this->actingAs($owner)
        ->patch(route('channels.update', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'is_default' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($channel->fresh()->is_default)->toBeTrue();

    $this->actingAs($owner)
        ->patch(route('channels.update', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'is_default' => false,
        ])
        ->assertSessionHasNoErrors();

    expect($channel->fresh()->is_default)->toBeFalse();
});

test('a plain member cannot mark a channel as a default', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $channel = Channel::factory()->for($team)->create(['slug' => 'announcements']);
    $channel->channelMembers()->create(['user_id' => $member->id]);

    $this->actingAs($member)
        ->patch(route('channels.update', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'is_default' => true,
        ])
        ->assertForbidden();

    expect($channel->fresh()->is_default)->toBeFalse();
});

test('a member may still edit the topic of a channel they cannot default', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $channel = Channel::factory()->for($team)->create(['slug' => 'announcements']);
    $channel->channelMembers()->create(['user_id' => $member->id]);

    $this->actingAs($member)
        ->patch(route('channels.update', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'topic' => 'Launches',
        ])
        ->assertSessionHasNoErrors();

    expect($channel->fresh()->topic)->toBe('Launches');
});

test('a private channel cannot be made a default', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->private()->create(['slug' => 'secret']);
    $channel->channelMembers()->create(['user_id' => $owner->id]);

    $this->actingAs($owner)
        ->patch(route('channels.update', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'is_default' => true,
        ])
        ->assertForbidden();

    expect($channel->fresh()->is_default)->toBeFalse();
});

test('the always-default #general has no flag to toggle', function (): void {
    $owner = User::factory()->create();
    $newcomer = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = $team->channels()->where('slug', Channel::GENERAL_SLUG)->sole();

    $this->actingAs($owner)
        ->patch(route('channels.update', ['team' => $team->slug, 'channel' => $general->slug]), [
            'is_default' => true,
        ])
        ->assertForbidden();

    // The flag stays unset and #general is a default all the same: it is one in
    // code, so there is nothing an admin could turn off.
    expect($general->fresh()->is_default)->toBeFalse();

    $team->memberships()->create(['user_id' => $newcomer->id, 'role' => TeamRole::Member]);

    expect($general->members()->whereKey($newcomer->id)->exists())->toBeTrue();
});

test('re-submitting the standing default flag is not treated as a change', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $channel = Channel::factory()->for($team)->create(['slug' => 'announcements', 'is_default' => true]);
    $channel->channelMembers()->create(['user_id' => $member->id]);

    // The details form posts every field it renders, so a member editing the
    // topic re-sends the flag unchanged; that must not read as an admin action.
    $this->actingAs($member)
        ->patch(route('channels.update', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'topic' => 'Launches',
            'is_default' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($channel->fresh()->topic)->toBe('Launches');
});
