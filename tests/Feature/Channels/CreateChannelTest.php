<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\ChannelCreationPolicy;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\User;

test('a team member can create a channel and is redirected to it', function (): void {
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

test('a plain team member can create a channel', function (): void {
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

test('a channel name must be unique within the team', function (): void {
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

test('the same channel name may exist in different teams', function (): void {
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

test('a channel named in a non-Latin script stays reachable', function (string $name): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)
        ->post(route('channels.store', ['team' => $team->slug]), [
            'name' => $name,
            'visibility' => 'public',
        ])
        ->assertSessionHasNoErrors();

    $channel = Channel::where('team_id', $team->id)->where('name', $name)->sole();

    expect($channel->slug)->not->toBe('');

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertOk();
})->with([
    'japanese' => ['日本語'],
    'korean' => ['한국어'],
    'hebrew' => ['עברית'],
    'punctuation' => ['<<<'],
    'emoji' => ['🎉🎉'],
]);

test('two channels named in a non-Latin script coexist in one team', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    foreach (['日本語', '中文'] as $name) {
        $this->actingAs($owner)
            ->post(route('channels.store', ['team' => $team->slug]), [
                'name' => $name,
                'visibility' => 'public',
            ])
            ->assertSessionHasNoErrors();
    }

    $slugs = Channel::where('team_id', $team->id)->whereIn('name', ['日本語', '中文'])->pluck('slug');

    expect($slugs)->toHaveCount(2)
        ->and($slugs->unique())->toHaveCount(2);
});

test('a repeated non-Latin channel name is still reported as already existing', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)
        ->post(route('channels.store', ['team' => $team->slug]), [
            'name' => '日本語',
            'visibility' => 'public',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->post(route('channels.store', ['team' => $team->slug]), [
            'name' => '日本語',
            'visibility' => 'public',
        ])
        ->assertSessionHasErrors('name');

    expect(Channel::where('team_id', $team->id)->where('name', '日本語')->count())->toBe(1);
});

test('creating a channel requires a valid visibility', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)
        ->post(route('channels.store', ['team' => $team->slug]), [
            'name' => 'Marketing',
            'visibility' => 'secret',
        ])
        ->assertSessionHasErrors('visibility');
});

test('creating a channel requires a name', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)
        ->post(route('channels.store', ['team' => $team->slug]), [
            'name' => '   ',
            'visibility' => 'public',
        ])
        ->assertSessionHasErrors('name');
});

test('a user who is not a team member cannot create a channel', function (): void {
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

test('a plain member is refused the visibility their workspace reserves for admins', function (string $reserved, string $stillOpen): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
    $team->update([$reserved.'_channel_creation_policy' => ChannelCreationPolicy::Admins]);

    $this->actingAs($member)
        ->post(route('channels.store', ['team' => $team->slug]), [
            'name' => 'Reserved',
            'visibility' => $reserved,
        ])
        ->assertForbidden();

    expect(Channel::where('team_id', $team->id)->where('slug', 'reserved')->exists())->toBeFalse();

    // The other visibility is untouched: the two policies are independent.
    $this->actingAs($member)
        ->post(route('channels.store', ['team' => $team->slug]), [
            'name' => 'Open',
            'visibility' => $stillOpen,
        ])
        ->assertSessionHasNoErrors();

    expect(Channel::where('team_id', $team->id)->where('slug', 'open')->exists())->toBeTrue();
})->with([
    'public reserved' => ['public', 'private'],
    'private reserved' => ['private', 'public'],
]);

test('an admin still creates the visibility the workspace reserves for admins', function (): void {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);
    $team->update(['public_channel_creation_policy' => ChannelCreationPolicy::Admins]);

    $this->actingAs($admin)
        ->post(route('channels.store', ['team' => $team->slug]), [
            'name' => 'Announcements',
            'visibility' => 'public',
        ])
        ->assertSessionHasNoErrors();

    expect(Channel::where('team_id', $team->id)->where('slug', 'announcements')->exists())->toBeTrue();
});
