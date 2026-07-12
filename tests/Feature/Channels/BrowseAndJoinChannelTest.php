<?php

use App\Actions\Channels\JoinChannel;
use App\Actions\Teams\CreateTeam;
use App\Models\Channel;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('browsing lists joinable public channels only', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');

    $joinable = Channel::factory()->for($team)->create(['name' => 'Marketing', 'slug' => 'marketing']);
    Channel::factory()->for($team)->private()->create(['name' => 'Secret', 'slug' => 'secret']);
    Channel::factory()->for($team)->archived()->create(['name' => 'Old', 'slug' => 'old']);
    $alreadyJoined = Channel::factory()->for($team)->create(['name' => 'Design', 'slug' => 'design']);
    app(JoinChannel::class)->handle($alreadyJoined, $user);

    $this->actingAs($user)
        ->get(route('channels.browse', ['team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('channels/Browse')
            ->has('joinableChannels', 1)
            ->where('joinableChannels.0.slug', 'marketing')
        );
});

test('browsing does not leak channels from other teams', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $otherTeam = app(CreateTeam::class)->handle(User::factory()->create(), 'Globex');
    Channel::factory()->for($otherTeam)->create(['name' => 'Marketing', 'slug' => 'marketing']);

    $this->actingAs($user)
        ->get(route('channels.browse', ['team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->has('joinableChannels', 0));
});

test('a team member can join a public channel', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $channel = Channel::factory()->for($team)->create(['name' => 'Marketing', 'slug' => 'marketing']);

    $this->actingAs($user)
        ->post(route('channels.join', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => 'marketing']));

    expect($channel->members()->whereKey($user->id)->exists())->toBeTrue();
});

test('joining a channel is idempotent', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $channel = Channel::factory()->for($team)->create(['slug' => 'marketing']);
    app(JoinChannel::class)->handle($channel, $user);

    $this->actingAs($user)
        ->post(route('channels.join', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertRedirect();

    expect($channel->channelMembers()->where('user_id', $user->id)->count())->toBe(1);
});

test('a private channel cannot be joined by browsing', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $channel = Channel::factory()->for($team)->private()->create(['slug' => 'secret']);

    $this->actingAs($user)
        ->post(route('channels.join', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertForbidden();

    expect($channel->members()->whereKey($user->id)->exists())->toBeFalse();
});

test('an archived channel cannot be joined', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $channel = Channel::factory()->for($team)->archived()->create(['slug' => 'old']);

    $this->actingAs($user)
        ->post(route('channels.join', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertForbidden();
});

test('a non-team-member cannot join a channel', function (): void {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->create(['slug' => 'marketing']);

    $this->actingAs($outsider)
        ->post(route('channels.join', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertForbidden();
});
