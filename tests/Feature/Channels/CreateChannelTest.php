<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\User;

test('a team member can create a channel and is redirected to it', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)
        ->post(route('channels.store', ['team' => $team->slug]), [
            'name' => '#Marketing',
            'visibility' => 'public',
            'topic' => 'Campaigns and launches',
        ])
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => 'marketing']));

    $channel = Channel::where('team_id', $team->id)->where('slug', 'marketing')->first();

    expect($channel)->not->toBeNull()
        ->and($channel->name)->toBe('Marketing')
        ->and($channel->visibility)->toBe(ChannelVisibility::Public)
        ->and($channel->topic)->toBe('Campaigns and launches')
        ->and($channel->created_by)->toBe($owner->id)
        ->and($channel->members()->whereKey($owner->id)->exists())->toBeTrue();
});

test('a plain team member can create a channel', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $this->actingAs($member)
        ->post(route('channels.store', ['team' => $team->slug]), [
            'name' => 'Random',
            'visibility' => 'private',
        ])
        ->assertRedirect();

    expect(Channel::where('team_id', $team->id)->where('slug', 'random')->exists())->toBeTrue();
});

test('a channel name must be unique within the team', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    Channel::factory()->for($team)->create(['name' => 'Marketing', 'slug' => 'marketing']);

    $this->actingAs($owner)
        ->post(route('channels.store', ['team' => $team->slug]), [
            'name' => '#Marketing',
            'visibility' => 'public',
        ])
        ->assertSessionHasErrors('name');

    expect(Channel::where('team_id', $team->id)->where('slug', 'marketing')->count())->toBe(1);
});

test('the same channel name may exist in different teams', function () {
    $owner = User::factory()->create();
    $teamA = app(CreateTeam::class)->handle($owner, 'Acme');
    $teamB = app(CreateTeam::class)->handle($owner, 'Globex');
    Channel::factory()->for($teamA)->create(['name' => 'Marketing', 'slug' => 'marketing']);

    $this->actingAs($owner)
        ->post(route('channels.store', ['team' => $teamB->slug]), [
            'name' => 'Marketing',
            'visibility' => 'public',
        ])
        ->assertSessionHasNoErrors();

    expect(Channel::where('team_id', $teamB->id)->where('slug', 'marketing')->exists())->toBeTrue();
});

test('creating a channel requires a valid visibility', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)
        ->post(route('channels.store', ['team' => $team->slug]), [
            'name' => 'Marketing',
            'visibility' => 'secret',
        ])
        ->assertSessionHasErrors('visibility');
});

test('creating a channel requires a name', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)
        ->post(route('channels.store', ['team' => $team->slug]), [
            'name' => '   ',
            'visibility' => 'public',
        ])
        ->assertSessionHasErrors('name');
});

test('a user who is not a team member cannot create a channel', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($outsider)
        ->post(route('channels.store', ['team' => $team->slug]), [
            'name' => 'Marketing',
            'visibility' => 'public',
        ])
        ->assertForbidden();

    expect(Channel::where('team_id', $team->id)->where('slug', 'marketing')->exists())->toBeFalse();
});
