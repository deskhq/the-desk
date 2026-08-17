<?php

declare(strict_types=1);

use App\Actions\Channels\DeleteChannel;
use App\Actions\Teams\CreateTeam;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditActivity;
use App\Models\Channel;
use App\Models\User;

test('a team admin deletes a channel, which disappears immediately and lands them on #general', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->create(['name' => 'Roadmap', 'slug' => 'roadmap']);
    $channel->members()->attach($owner->id);

    $this->actingAs($owner)
        ->delete(route('channels.destroy', ['team' => $team->slug, 'channel' => $channel->slug]), ['name' => 'Roadmap'])
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]));

    expect(Channel::query()->whereKey($channel->id)->exists())->toBeFalse()
        ->and(Channel::withTrashed()->whereKey($channel->id)->sole()->deleted_at)->not->toBeNull();
});

test('deleting an already-deleted channel keeps its original deletion date, so the window is never extended', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->create(['name' => 'Roadmap', 'slug' => 'roadmap']);

    $deleted = app(DeleteChannel::class)->handle($channel, null);

    $this->travel(1)->days();

    expect(app(DeleteChannel::class)->handle($channel, null)->deleted_at->equalTo($deleted->deleted_at))->toBeTrue();
});

test('deleting a channel records it in the workspace audit log', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->create(['name' => 'Roadmap', 'slug' => 'roadmap']);

    $this->actingAs($owner)
        ->delete(route('channels.destroy', ['team' => $team->slug, 'channel' => $channel->slug]), ['name' => 'Roadmap'])
        ->assertRedirect();

    $entry = AuditActivity::where('team_id', $team->id)
        ->where('event', AuditAction::ChannelDeleted->value)
        ->sole();

    expect($entry->properties->get('channel_name'))->toBe('Roadmap');
});

test('the typed channel name must match, so a mistyped confirmation deletes nothing', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->create(['name' => 'Roadmap', 'slug' => 'roadmap']);

    $this->actingAs($owner)
        ->from(route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->delete(route('channels.destroy', ['team' => $team->slug, 'channel' => $channel->slug]), ['name' => 'roadmap!'])
        ->assertSessionHasErrors('name');

    expect(Channel::query()->whereKey($channel->id)->exists())->toBeTrue();
});

test('a plain member cannot delete a channel, even one they created', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
    $channel = Channel::factory()->for($team)->create(['name' => 'Roadmap', 'slug' => 'roadmap', 'created_by' => $member->id]);

    $this->actingAs($member)
        ->delete(route('channels.destroy', ['team' => $team->slug, 'channel' => $channel->slug]), ['name' => 'Roadmap'])
        ->assertForbidden();

    expect(Channel::query()->whereKey($channel->id)->exists())->toBeTrue();
});

test('the #general channel cannot be deleted', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = $team->channels()->where('slug', Channel::GENERAL_SLUG)->sole();

    $this->actingAs($owner)
        ->delete(route('channels.destroy', ['team' => $team->slug, 'channel' => $general->slug]), ['name' => $general->name])
        ->assertForbidden();

    expect(Channel::query()->whereKey($general->id)->exists())->toBeTrue();
});
