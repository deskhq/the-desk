<?php

declare(strict_types=1);

use App\Actions\Sso\ProvisionSsoUser;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

test('accepting an invitation joins the newcomer to every default channel', function (): void {
    ['team' => $team] = teamWithChannel();
    $newcomer = User::factory()->create(['email' => 'newcomer@example.com']);

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
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $newcomer = User::factory()->create();

    $team->memberships()->create(['user_id' => $newcomer->id, 'role' => TeamRole::Member]);

    expect($general->members()->whereKey($newcomer->id)->exists())->toBeTrue();
});

test('a directory-provisioned user joins the default channels too', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    config(['sso.default_team_id' => $team->id]);

    $announcements = Channel::factory()->for($team)->create(['slug' => 'announcements', 'is_default' => true]);

    $user = app(ProvisionSsoUser::class)->handle('oidc', 'sub-123', 'jordan@example.com', 'Jordan Rivers');

    expect($announcements->members()->whereKey($user->id)->exists())->toBeTrue()
        ->and($general->members()->whereKey($user->id)->exists())->toBeTrue();
});

test('marking a channel default does not sweep the existing members in', function (): void {
    ['team' => $team] = teamWithChannel();
    $existing = User::factory()->create();
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
    ['team' => $team] = teamWithChannel();
    $newcomer = User::factory()->create();

    $private = Channel::factory()->for($team)->private()->create(['slug' => 'secret', 'is_default' => true]);
    $archived = Channel::factory()->for($team)->create(['slug' => 'retired', 'is_default' => true, 'archived_at' => now()]);

    $team->memberships()->create(['user_id' => $newcomer->id, 'role' => TeamRole::Member]);

    expect($private->members()->whereKey($newcomer->id)->exists())->toBeFalse()
        ->and($archived->members()->whereKey($newcomer->id)->exists())->toBeFalse();
});

test('joining a default channel posts no notice into it', function (): void {
    ['team' => $team] = teamWithChannel();
    $newcomer = User::factory()->create();
    $announcements = Channel::factory()->for($team)->create(['slug' => 'announcements', 'is_default' => true]);

    $team->memberships()->create(['user_id' => $newcomer->id, 'role' => TeamRole::Member]);

    expect($announcements->messages()->count())->toBe(0);
});

test('a team admin marks a public channel as a default and takes it back', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $admin = teamMemberInChannel($general, role: TeamRole::Admin);

    // An Admin, not the Owner, and not a member of the channel: deciding where
    // newcomers land is workspace administration, not channel membership.
    $channel = Channel::factory()->for($team)->create(['slug' => 'announcements']);

    $this->actingAs($admin)
        ->patch(route('channels.update', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'is_default' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($channel->fresh()->is_default)->toBeTrue();

    $this->actingAs($admin)
        ->patch(route('channels.update', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'is_default' => false,
        ])
        ->assertSessionHasNoErrors();

    expect($channel->fresh()->is_default)->toBeFalse();
});

test('a plain member cannot mark a channel as a default', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    $channel = Channel::factory()->for($team)->create(['slug' => 'announcements']);
    channelMembership($channel, $member);

    $this->actingAs($member)
        ->patch(route('channels.update', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'is_default' => true,
        ])
        ->assertForbidden();

    expect($channel->fresh()->is_default)->toBeFalse();
});

test('a member may still edit the topic of a channel they cannot default', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    $channel = Channel::factory()->for($team)->create(['slug' => 'announcements']);
    channelMembership($channel, $member);

    $this->actingAs($member)
        ->patch(route('channels.update', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'topic' => 'Launches',
        ])
        ->assertSessionHasNoErrors();

    expect($channel->fresh()->topic)->toBe('Launches');
});

test('a private channel cannot be made a default', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $channel = Channel::factory()->for($team)->private()->create(['slug' => 'secret']);
    channelMembership($channel, $owner);

    $this->actingAs($owner)
        ->patch(route('channels.update', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'is_default' => true,
        ])
        ->assertForbidden();

    expect($channel->fresh()->is_default)->toBeFalse();
});

test('the always-default #general has no flag to toggle', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $newcomer = User::factory()->create();

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
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    $channel = Channel::factory()->for($team)->create(['slug' => 'announcements', 'is_default' => true]);
    channelMembership($channel, $member);

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

test('the workspace admin page lists the public channels an admin may default', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();

    Channel::factory()->for($team)->create(['name' => 'Announcements', 'slug' => 'announcements', 'is_default' => true]);
    Channel::factory()->for($team)->create(['name' => 'Watercooler', 'slug' => 'watercooler']);
    Channel::factory()->for($team)->private()->create(['name' => 'Secret', 'slug' => 'secret']);
    Channel::factory()->for($team)->create(['name' => 'Retired', 'slug' => 'retired', 'archived_at' => now()]);

    $this->actingAs($owner)
        ->get(route('teams.edit', ['team' => $team->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            // #general leads, then the rest by name; private and archived
            // channels can never be defaults, so they are absent entirely.
            ->where('defaultChannels', fn (Collection $channels): bool => $channels->pluck('slug')->all() === [
                Channel::GENERAL_SLUG, 'announcements', 'watercooler',
            ])
            ->where('defaultChannels.0.isGeneral', true)
            ->where('defaultChannels.1.isDefault', true)
            ->where('defaultChannels.2.isDefault', false)
            ->etc());
});

test('a plain member is sent no list of default channels to manage', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    $this->actingAs($member)
        ->get(route('teams.edit', ['team' => $team->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('defaultChannels', [])
            ->etc());
});

test('an archived channel cannot be made a default', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $channel = Channel::factory()->for($team)->create(['slug' => 'retired', 'archived_at' => now()]);

    $this->actingAs($owner)
        ->patch(route('channels.update', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'is_default' => true,
        ])
        ->assertForbidden();

    expect($channel->fresh()->is_default)->toBeFalse();
});

test('flipping the default flag alongside a detail edit still needs channel membership', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $admin = teamMemberInChannel($general, role: TeamRole::Admin);

    $channel = Channel::factory()->for($team)->create(['slug' => 'announcements']);

    // The default flag alone is workspace administration; a topic is the
    // channel's own business, so editing it from outside is still refused.
    $this->actingAs($admin)
        ->patch(route('channels.update', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'is_default' => true,
            'topic' => 'Launches',
        ])
        ->assertForbidden();

    expect($channel->fresh()->is_default)->toBeFalse()
        ->and($channel->fresh()->topic)->toBeNull();
});
