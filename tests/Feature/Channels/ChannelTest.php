<?php

use App\Actions\Channels\CreateChannel;
use App\Actions\Teams\CreateTeam;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\TeamInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('creating a team auto-creates a #general channel', function () {
    $user = User::factory()->create();

    $team = app(CreateTeam::class)->handle($user, 'Acme');

    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->first();

    expect($general)->not->toBeNull()
        ->and($general->name)->toBe('general')
        ->and($general->visibility->value)->toBe('public')
        ->and($general->created_by)->toBe($user->id);
});

test('the team owner is auto-joined to #general on team creation', function () {
    $user = User::factory()->create();

    $team = app(CreateTeam::class)->handle($user, 'Acme');

    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    expect($general->members()->whereKey($user->id)->exists())->toBeTrue();
});

test('a new team member is auto-joined to #general when accepting an invitation', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser)->get(route('invitations.accept', $invitation));

    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    expect($general->members()->whereKey($invitedUser->id)->exists())->toBeTrue();
});

test('the channel page lists the current user\'s channels in the sidebar', function () {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    $this->actingAs($user)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channels/Show')
            ->has('channels', 1)
            ->where('channels.0.slug', 'general')
            ->where('channels.0.name', 'general')
            ->where('channels.0.isGeneral', true)
        );
});

test('the CreateChannel action creates a channel, strips a leading # and joins the creator', function () {
    $creator = User::factory()->create();
    $team = app(CreateTeam::class)->handle($creator, 'Acme');

    $channel = app(CreateChannel::class)->handle($team, '#Marketing', ChannelVisibility::Private, $creator);

    expect($channel->name)->toBe('Marketing')
        ->and($channel->slug)->toBe('marketing')
        ->and($channel->visibility)->toBe(ChannelVisibility::Private)
        ->and($channel->team_id)->toBe($team->id)
        ->and($channel->members()->whereKey($creator->id)->exists())->toBeTrue();
});

test('a bare team URL redirects to the #general channel', function () {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');

    $this->actingAs($user)
        ->get(route('channels.index', ['team' => $team->slug]))
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => 'general']));
});

test('a user who is not a team member cannot view the team\'s channels', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($outsider)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => 'general']))
        ->assertForbidden();
});
